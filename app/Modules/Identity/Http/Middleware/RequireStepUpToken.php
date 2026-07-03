<?php

namespace App\Modules\Identity\Http\Middleware;

use App\Shared\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class RequireStepUpToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $token  = $request->header('X-Step-Up-Token');
        $userId = $token ? Cache::get("step_up:{$token}") : null;

        if (! $userId || $userId !== $request->user()?->id) {
            return ApiResponse::error('step_up_required', 'A valid step-up OTP is required for this action.', 403);
        }

        return $next($request);
    }
}
