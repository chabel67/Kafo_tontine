<?php

namespace App\Modules\Identity\Http\Middleware;

use App\Shared\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * R-AUTH-03 : action sensible sous code de confirmation (step-up).
 *
 * Jeton à USAGE UNIQUE (2026-11-13) : il est retiré du cache à l'entrée (deux requêtes
 * simultanées ne peuvent pas l'utiliser toutes les deux). Si l'action échoue (réponse ≥ 400 :
 * validation, règle métier…), il est restitué pour sa durée restante afin que l'utilisateur
 * puisse corriger et réessayer sans nouveau code. Une action réussie le consomme.
 */
class RequireStepUpToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $token  = $request->header('X-Step-Up-Token');
        $userId = $token ? Cache::pull("step_up:{$token}") : null;

        if (! $userId || $userId !== $request->user()?->id) {
            if ($userId) {
                $this->restore($token, $userId);   // jeton d'un autre utilisateur : on ne le détruit pas
            }
            return ApiResponse::error('step_up_required', 'Une vérification OTP renforcée (step-up) est requise pour cette action.', 403);
        }

        $response = $next($request);

        if ($response->getStatusCode() >= 400) {
            $this->restore($token, $userId);
        } else {
            Cache::forget("step_up_exp:{$token}");
        }

        return $response;
    }

    private function restore(string $token, string $userId): void
    {
        $expiresAt = (int) Cache::get("step_up_exp:{$token}", now()->addSeconds(300)->timestamp);
        $remaining = $expiresAt - now()->timestamp;
        if ($remaining > 0) {
            Cache::put("step_up:{$token}", $userId, $remaining);
        }
    }
}
