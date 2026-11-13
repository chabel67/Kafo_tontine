<?php

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Domain\Enums\OtpPurpose;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\Exceptions\AccountNotActivatedException;
use App\Modules\Identity\Domain\Exceptions\PinAlreadySetException;
use App\Modules\Identity\Domain\Exceptions\UnauthorizedException;
use App\Modules\Identity\Infrastructure\Models\AccessLog;
use App\Modules\Identity\Infrastructure\Models\AuthSession;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Shared\PhoneMask;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AuthService
{
    private const STAFF_ROLES = ['treasurer', 'manager', 'admin', 'super_admin'];

    /** Nom des jetons Sanctum staff — seul ce nom est soumis à l'expiration d'inactivité (R-AUTH-01). */
    public const STAFF_TOKEN_NAME  = 'backoffice';
    public const MEMBER_TOKEN_NAME = 'member';

    public function __construct(
        private readonly OtpService $otpService,
        private readonly PinService $pinService,
    ) {}

    public function loginStaff(
        string $phone,
        string $otp,
        string $pin,
        ?string $ip,
        ?string $userAgent,
    ): array {
        $user = User::where('phone', $phone)
            ->whereHas('roles', fn ($q) => $q->whereIn('name', self::STAFF_ROLES))
            ->with('roles')
            ->first();

        // Anti-énumération : l'OTP est vérifié AVANT toute réponse dépendant du
        // compte. Sans possession du téléphone, compte inconnu, désactivé ou sans
        // PIN donnent la même erreur OTP (aucun OTP n'est émis pour un numéro
        // non staff, cf. AdminAuthController::requestOtp).
        $this->otpService->verify($phone, $otp, OtpPurpose::Login);

        if (! $user || $user->status !== UserStatus::Active) {
            AccessLog::record($user?->id, 'login_failed_user_not_found', $ip, $userAgent, ['phone' => PhoneMask::mask($phone)]);
            throw new UnauthorizedException();
        }

        // Premier login : pas de PIN encore posé. L'OTP login est consommé (anti-rejeu) ;
        // le client bascule sur POST /admin/auth/pin/set, qui exige un OTP distinct
        // purpose=signup (l'OTP login n'y serait de toute façon pas accepté).
        if (! $user->pin_hash) {
            AccessLog::record($user->id, 'login_requires_pin_setup', $ip, $userAgent);
            return ['requires_pin_setup' => true];
        }

        // Then verify PIN (with progressive lockout)
        $this->pinService->verify($user, $pin);

        // Revoke all previous sessions
        AuthSession::where('user_id', $user->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);

        $token     = $user->createToken(self::STAFF_TOKEN_NAME)->plainTextToken;
        $tokenHash = hash('sha256', $token);

        AuthSession::create([
            'user_id'          => $user->id,
            'token_hash'       => $tokenHash,
            'ip_address'       => $ip,
            'user_agent'       => $userAgent,
            'expires_at'       => now()->addMinutes(config('sanctum.expiration', 1440)),
            'last_activity_at' => now(),
        ]);

        AccessLog::record($user->id, 'login_success', $ip, $userAgent);

        return ['token' => $token, 'user' => $user];
    }

    public function loginMember(
        string $phone,
        string $otp,
        string $pin,
        ?string $ip,
        ?string $userAgent,
    ): array {
        $user = User::where('phone', $phone)->first();

        // Anti-énumération (comme loginStaff) : l'OTP est vérifié AVANT toute réponse
        // dépendant du compte. Sans possession du téléphone, compte inconnu, en attente,
        // suspendu ou archivé donnent la même erreur OTP (otp_expired / otp_invalid).
        $this->otpService->verify($phone, $otp, OtpPurpose::Login);

        // R-MBR-03 : compte pas encore activé par le staff — message explicite, renvoyé
        // seulement maintenant que l'OTP est vérifié.
        if ($user && $user->status === UserStatus::Pending) {
            AccessLog::record($user->id, 'login_failed_not_activated', $ip, $userAgent);
            throw new AccountNotActivatedException();
        }

        if (! $user || $user->status !== UserStatus::Active) {
            AccessLog::record($user?->id, 'login_failed_user_not_found', $ip, $userAgent, ['phone' => PhoneMask::mask($phone)]);
            throw new UnauthorizedException();
        }

        $this->pinService->verify($user, $pin);

        AuthSession::where('user_id', $user->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);

        $token     = $user->createToken(self::MEMBER_TOKEN_NAME)->plainTextToken;
        $tokenHash = hash('sha256', $token);

        AuthSession::create([
            'user_id'          => $user->id,
            'token_hash'       => $tokenHash,
            'ip_address'       => $ip,
            'user_agent'       => $userAgent,
            'expires_at'       => now()->addMinutes(config('sanctum.expiration', 1440)),
            'last_activity_at' => now(),
        ]);

        AccessLog::record($user->id, 'login_success', $ip, $userAgent);

        return ['token' => $token, 'user' => $user];
    }

    public function setupPin(
        string $phone,
        string $otp,
        string $pin,
        ?string $ip,
        ?string $userAgent,
    ): array {
        $this->pinService->assertStrong($pin);

        $user = User::where('phone', $phone)->first();

        // OTP d'abord : sans possession du téléphone, rien n'est révélé sur le compte.
        $this->otpService->verify($phone, $otp, OtpPurpose::Signup);

        if ($user && $user->status === UserStatus::Pending) {
            AccessLog::record($user->id, 'pin_setup_failed_not_activated', $ip, $userAgent);
            throw new AccountNotActivatedException();
        }

        if (! $user || $user->status !== UserStatus::Active) {
            AccessLog::record($user?->id, 'pin_setup_failed', $ip, $userAgent, ['phone' => PhoneMask::mask($phone)]);
            throw new UnauthorizedException();
        }

        // Un OTP seul ne doit jamais suffire à remplacer un PIN existant :
        // le changement de PIN passe par POST /member/profile/pin (ancien PIN requis).
        if ($user->pin_hash) {
            AccessLog::record($user->id, 'pin_setup_refused_already_set', $ip, $userAgent);
            throw new PinAlreadySetException();
        }

        $this->pinService->set($user, $pin);

        AuthSession::where('user_id', $user->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);

        $token     = $user->createToken(self::MEMBER_TOKEN_NAME)->plainTextToken;
        $tokenHash = hash('sha256', $token);

        AuthSession::create([
            'user_id'          => $user->id,
            'token_hash'       => $tokenHash,
            'ip_address'       => $ip,
            'user_agent'       => $userAgent,
            'expires_at'       => now()->addMinutes(config('sanctum.expiration', 1440)),
            'last_activity_at' => now(),
        ]);

        AccessLog::record($user->id, 'pin_set', $ip);

        return ['token' => $token, 'user' => $user->fresh()];
    }

    public function setupStaffPin(
        string $phone,
        string $otp,
        string $pin,
        ?string $ip,
        ?string $userAgent,
    ): array {
        $this->pinService->assertStrong($pin);

        $user = User::where('phone', $phone)
            ->whereHas('roles', fn ($q) => $q->whereIn('name', self::STAFF_ROLES))
            ->first();

        // OTP d'abord : sans lui, aucune information sur le compte n'est révélée.
        $this->otpService->verify($phone, $otp, OtpPurpose::Signup);

        if (! $user || $user->status !== UserStatus::Active) {
            AccessLog::record($user?->id, 'pin_setup_failed', $ip, $userAgent, ['phone' => PhoneMask::mask($phone)]);
            throw new UnauthorizedException();
        }

        // Un PIN existant ne se remplace jamais avec un simple OTP.
        if ($user->pin_hash) {
            AccessLog::record($user->id, 'pin_setup_refused_already_set', $ip, $userAgent);
            throw new PinAlreadySetException();
        }

        $this->pinService->set($user, $pin);

        AuthSession::where('user_id', $user->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);

        $token     = $user->createToken(self::STAFF_TOKEN_NAME)->plainTextToken;
        $tokenHash = hash('sha256', $token);

        AuthSession::create([
            'user_id'          => $user->id,
            'token_hash'       => $tokenHash,
            'ip_address'       => $ip,
            'user_agent'       => $userAgent,
            'expires_at'       => now()->addMinutes(config('sanctum.expiration', 1440)),
            'last_activity_at' => now(),
        ]);

        AccessLog::record($user->id, 'staff_pin_set', $ip);

        return ['token' => $token, 'user' => $user->fresh()->load('roles')];
    }

    public function stepUp(User $user, string $otp, ?string $ip): string
    {
        $this->otpService->verify($user->phone, $otp, OtpPurpose::StepUp);

        $stepUpToken = Str::random(64);
        Cache::put("step_up:{$stepUpToken}", $user->id, 300); // 5 minutes, usage unique (RequireStepUpToken)
        Cache::put("step_up_exp:{$stepUpToken}", now()->addSeconds(300)->timestamp, 300);

        AccessLog::record($user->id, 'step_up_success', $ip);

        return $stepUpToken;
    }

    public function logout(string $tokenHash, string $userId, ?string $ip): void
    {
        AuthSession::where('token_hash', $tokenHash)->update(['revoked_at' => now()]);

        AccessLog::record($userId, 'logout', $ip);
    }
}
