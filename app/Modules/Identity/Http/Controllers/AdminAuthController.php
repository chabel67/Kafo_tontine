<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Audit\Application\AuditService;
use App\Modules\Identity\Application\AuthService;
use App\Modules\Identity\Application\OtpService;
use App\Modules\Identity\Domain\Enums\OtpPurpose;
use App\Modules\Identity\Http\Requests\LoginRequest;
use App\Modules\Identity\Http\Requests\OtpRequestRequest;
use App\Modules\Identity\Http\Requests\StepUpRequest;
use App\Modules\Identity\Http\Resources\UserResource;
use App\Shared\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Hash;

class AdminAuthController extends Controller
{
    public function __construct(
        private readonly OtpService    $otpService,
        private readonly AuthService   $authService,
        private readonly AuditService  $audit,
    ) {}

    public function requestOtp(OtpRequestRequest $request): JsonResponse
    {
        $purpose = OtpPurpose::tryFrom($request->input('purpose', 'login')) ?? OtpPurpose::Login;

        $this->otpService->request(
            $request->input('phone'),
            $purpose,
            $request->ip(),
        );

        return ApiResponse::success([
            'expires_in' => (int) config('kafo.otp_ttl_seconds', 300),
        ]);
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

        $this->audit->logFromRequest($request, 'auth.login_success', 'user', $result['user']->id);

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
