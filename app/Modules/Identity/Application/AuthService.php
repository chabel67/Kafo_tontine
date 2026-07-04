<?php

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Domain\Enums\OtpPurpose;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\Exceptions\UnauthorizedException;
use App\Modules\Identity\Infrastructure\Models\AccessLog;
use App\Modules\Identity\Infrastructure\Models\AuthSession;
use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AuthService
{
    private const STAFF_ROLES = ['treasurer', 'manager', 'admin', 'super_admin'];

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

        if (! $user || $user->status !== UserStatus::Active) {
            AccessLog::record(null, 'login_failed_user_not_found', $ip, $userAgent, ['phone' => $phone]);
            throw new UnauthorizedException();
        }

        // Premier login : pas de PIN encore posé. On signale sans consommer l'OTP —
        // le client bascule sur POST /admin/auth/pin/set avec un nouvel OTP purpose=signup.
        if (! $user->pin_hash) {
            AccessLog::record($user->id, 'login_requires_pin_setup', $ip, $userAgent);
            return ['requires_pin_setup' => true];
        }

        // Verify OTP first (cheaper check)
        $this->otpService->verify($phone, $otp, OtpPurpose::Login);

        // Then verify PIN (with progressive lockout)
        $this->pinService->verify($user, $pin);

        // Revoke all previous sessions
        AuthSession::where('user_id', $user->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);

        $token     = $user->createToken('backoffice')->plainTextToken;
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

        if (! $user || $user->status !== UserStatus::Active) {
            AccessLog::record(null, 'login_failed_user_not_found', $ip, $userAgent, ['phone' => $phone]);
            throw new UnauthorizedException();
        }

        $this->otpService->verify($phone, $otp, OtpPurpose::Login);
        $this->pinService->verify($user, $pin);

        AuthSession::where('user_id', $user->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);

        $token     = $user->createToken('member')->plainTextToken;
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
        $user = User::where('phone', $phone)->first();

        if (! $user || $user->status !== UserStatus::Active) {
            AccessLog::record(null, 'pin_setup_failed', $ip, $userAgent, ['phone' => $phone]);
            throw new UnauthorizedException();
        }

        $this->otpService->verify($phone, $otp, OtpPurpose::Signup);
        $this->pinService->set($user, $pin);

        AuthSession::where('user_id', $user->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);

        $token     = $user->createToken('member')->plainTextToken;
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
        $user = User::where('phone', $phone)
            ->whereHas('roles', fn ($q) => $q->whereIn('name', self::STAFF_ROLES))
            ->first();

        if (! $user || $user->status !== UserStatus::Active) {
            AccessLog::record(null, 'pin_setup_failed', $ip, $userAgent, ['phone' => $phone]);
            throw new UnauthorizedException();
        }

        $this->otpService->verify($phone, $otp, OtpPurpose::Signup);
        $this->pinService->set($user, $pin);

        AuthSession::where('user_id', $user->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);

        $token     = $user->createToken('backoffice')->plainTextToken;
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
        Cache::put("step_up:{$stepUpToken}", $user->id, 300); // 5 minutes

        AccessLog::record($user->id, 'step_up_success', $ip);

        return $stepUpToken;
    }

    public function logout(string $tokenHash, string $userId, ?string $ip): void
    {
        AuthSession::where('token_hash', $tokenHash)->update(['revoked_at' => now()]);

        AccessLog::record($userId, 'logout', $ip);
    }
}
