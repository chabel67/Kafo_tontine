<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Application\AuthService;
use App\Modules\Identity\Application\OtpService;
use App\Modules\Identity\Domain\Enums\OtpPurpose;
use App\Modules\Identity\Http\Requests\LoginRequest;
use App\Modules\Identity\Http\Requests\OtpRequestRequest;
use App\Modules\Identity\Http\Resources\UserResource;
use App\Shared\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class MemberAuthController extends Controller
{
    public function __construct(
        private readonly OtpService  $otpService,
        private readonly AuthService $authService,
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
        $result = $this->authService->loginMember(
            phone:     $request->input('phone'),
            otp:       $request->input('otp'),
            pin:       $request->input('pin'),
            ip:        $request->ip(),
            userAgent: $request->userAgent(),
        );

        return ApiResponse::success([
            'token' => $result['token'],
            'user'  => new UserResource($result['user']),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return ApiResponse::success(new UserResource($request->user()));
    }

    public function setPin(Request $request): JsonResponse
    {
        $request->validate([
            'phone'            => ['required', 'string'],
            'otp'              => ['required', 'string', 'size:6'],
            'pin'              => ['required', 'string', 'size:6'],
            'pin_confirmation' => ['required', 'string', 'same:pin'],
        ]);

        $result = $this->authService->setupPin(
            phone:     $request->input('phone'),
            otp:       $request->input('otp'),
            pin:       $request->input('pin'),
            ip:        $request->ip(),
            userAgent: $request->userAgent(),
        );

        return ApiResponse::success([
            'token' => $result['token'],
            'user'  => new UserResource($result['user']),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $token     = $request->bearerToken();
        $tokenHash = hash('sha256', $token ?? '');

        $request->user()->currentAccessToken()->delete();

        $this->authService->logout(
            tokenHash: $tokenHash,
            userId:    $request->user()->id,
            ip:        $request->ip(),
        );

        return ApiResponse::noContent();
    }
}