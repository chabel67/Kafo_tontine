<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Audit\Application\AuditService;
use App\Modules\Identity\Application\AuthService;
use App\Modules\Identity\Application\OtpService;
use App\Modules\Identity\Application\PinService;
use App\Modules\Identity\Domain\Exceptions\PinInvalidException;
use App\Modules\Identity\Domain\Exceptions\PinLockedException;
use App\Modules\Identity\Domain\Enums\OtpPurpose;
use App\Modules\Identity\Http\Requests\LoginRequest;
use App\Modules\Identity\Http\Requests\OtpRequestRequest;
use App\Modules\Identity\Http\Requests\StepUpRequest;
use App\Modules\Identity\Http\Resources\UserResource;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Shared\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

class AdminAuthController extends Controller
{
    private const STAFF_ROLES = ['treasurer', 'manager', 'admin', 'super_admin'];

    public function __construct(
        private readonly OtpService    $otpService,
        private readonly AuthService   $authService,
        private readonly AuditService  $audit,
        private readonly PinService    $pinService,
    ) {}

    public function requestOtp(OtpRequestRequest $request): JsonResponse
    {
        $purpose = OtpPurpose::tryFrom($request->input('purpose', 'login')) ?? OtpPurpose::Login;
        $phone   = $request->input('phone');
        $ttl     = (int) config('kafo.otp_ttl_seconds', 300);

        // Ne révèle pas l'existence des comptes : on répond 200 même si le
        // téléphone n'appartient à aucun staff, mais on n'envoie pas d'OTP.
        $isStaff = User::where('phone', $phone)
            ->whereHas('roles', fn ($q) => $q->whereIn('name', self::STAFF_ROLES))
            ->exists();

        if (! $isStaff) {
            return ApiResponse::success(['expires_in' => $ttl]);
        }

        $this->otpService->request($phone, $purpose, $request->ip());

        return ApiResponse::success(['expires_in' => $ttl]);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $result = $this->authService->loginStaff(
            phone:     $request->input('phone'),
            otp:       $request->input('otp'),
            pin:       $request->input('pin'),
            ip:        $request->ip(),
            userAgent: $request->userAgent(),
        );

        if (! empty($result['requires_pin_setup'])) {
            return ApiResponse::success(['requires_pin_setup' => true]);
        }

        $this->audit->logFromRequest($request, 'auth.login_success', 'user', $result['user']->id, actor: $result['user']);

        return ApiResponse::success([
            'token' => $result['token'],
            'user'  => new UserResource($result['user']),
        ]);
    }

    public function setupPin(Request $request): JsonResponse
    {
        $request->validate([
            'phone'            => ['required', 'string'],
            'otp'              => ['required', 'string', 'size:6'],
            'pin'              => ['required', 'string', 'size:6'],
            'pin_confirmation' => ['required', 'string', 'same:pin'],
        ]);

        $result = $this->authService->setupStaffPin(
            phone:     $request->input('phone'),
            otp:       $request->input('otp'),
            pin:       $request->input('pin'),
            ip:        $request->ip(),
            userAgent: $request->userAgent(),
        );

        $this->audit->logFromRequest($request, 'auth.staff_pin_set', 'user', $result['user']->id, actor: $result['user']);

        return ApiResponse::success([
            'token' => $result['token'],
            'user'  => new UserResource($result['user']),
        ]);
    }

    public function stepUp(StepUpRequest $request): JsonResponse
    {
        $stepUpToken = $this->authService->stepUp(
            user: $request->user(),
            otp:  $request->input('otp'),
            ip:   $request->ip(),
        );

        return ApiResponse::success(['step_up_token' => $stepUpToken]);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user()->load('roles');

        return ApiResponse::success(new UserResource($user));
    }

    /**
     * Profil staff : changement de son propre PIN (PIN actuel exigé).
     * Les autres sessions de l'agent sont fermées, la session courante est conservée.
     */
    public function changePin(Request $request): JsonResponse
    {
        $request->validate([
            'current_pin'          => ['required', 'string', 'size:6'],
            'new_pin'              => ['required', 'string', 'size:6', 'different:current_pin'],
            'new_pin_confirmation' => ['required', 'string', 'same:new_pin'],
        ], [
            'new_pin_confirmation.same' => 'Les deux PIN ne correspondent pas.',
            'new_pin.different'         => 'Le nouveau PIN doit être différent du PIN actuel.',
            'current_pin.size'          => 'Le PIN actuel doit contenir 6 chiffres.',
            'new_pin.size'              => 'Le nouveau PIN doit contenir 6 chiffres.',
        ]);

        $user = $request->user();

        try {
            $this->pinService->verify($user, $request->input('current_pin'));
        } catch (PinInvalidException) {
            return ApiResponse::error('pin_invalid', 'PIN actuel incorrect.', 422);
        } catch (PinLockedException $e) {
            return ApiResponse::error('pin_locked', "Compte temporairement bloqué. Réessayez dans {$e->lockSeconds} secondes.", 423);
        }

        try {
            $this->pinService->set($user, $request->input('new_pin'));
        } catch (\InvalidArgumentException $e) {
            return ApiResponse::error('pin_weak', $e->getMessage(), 422);
        }

        $currentTokenId = $user->currentAccessToken()?->id;
        $revoked = $user->tokens()->when($currentTokenId, fn ($q) => $q->where('id', '!=', $currentTokenId))->delete();

        $this->audit->logFromRequest($request, 'auth.staff_pin_change', 'user', $user->id, newValues: ['other_sessions_revoked' => $revoked]);

        return ApiResponse::success(['message' => 'PIN modifié.', 'other_sessions_revoked' => $revoked]);
    }

    /** Profil staff : photo (même stockage que la photo membre). */
    public function uploadPhoto(Request $request): JsonResponse
    {
        $request->validate([
            'photo' => ['required', 'image', 'mimes:jpeg,jpg,png,webp', 'max:5120'],
        ]);

        $user = $request->user();
        $this->deleteStoredPhoto($user);

        $path     = $request->file('photo')->store("avatars/{$user->id}", 'public');
        $metadata = $user->metadata ?? [];
        $metadata['avatar_url']  = Storage::disk('public')->url($path);
        $metadata['avatar_path'] = $path;
        $user->update(['metadata' => $metadata]);

        $this->audit->logFromRequest($request, 'auth.staff_photo_update', 'user', $user->id);

        return ApiResponse::success(new UserResource($user->fresh()->load('roles')));
    }

    public function deletePhoto(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->deleteStoredPhoto($user);

        $metadata = $user->metadata ?? [];
        unset($metadata['avatar_url'], $metadata['avatar_path']);
        $user->update(['metadata' => $metadata]);

        $this->audit->logFromRequest($request, 'auth.staff_photo_delete', 'user', $user->id);

        return ApiResponse::success(new UserResource($user->fresh()->load('roles')));
    }

    private function deleteStoredPhoto(User $user): void
    {
        $oldPath = $user->metadata['avatar_path'] ?? null;
        if ($oldPath) {
            Storage::disk('public')->delete($oldPath);
        }
    }

    public function logout(Request $request): JsonResponse
    {
        $token     = $request->bearerToken();
        $tokenHash = hash('sha256', $token ?? '');

        $this->audit->logFromRequest($request, 'auth.logout', 'user', $request->user()->id);

        $request->user()->currentAccessToken()->delete();

        $this->authService->logout(
            tokenHash: $tokenHash,
            userId:    $request->user()->id,
            ip:        $request->ip(),
        );

        return ApiResponse::noContent();
    }
}
