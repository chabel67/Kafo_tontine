<?php

namespace App\Modules\Identity\Http\Middleware;

use App\Modules\Identity\Application\AuthService;
use App\Modules\Identity\Infrastructure\Models\AccessLog;
use App\Modules\Identity\Infrastructure\Models\AuthSession;
use App\Shared\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpFoundation\Response;

/**
 * Expiration des sessions back-office après inactivité (R-AUTH-01, DA-10).
 *
 * Ne concerne QUE les jetons staff (nom `backoffice`, posé par AuthService) :
 * les jetons membres (`member`, app mobile / frontoffice) ne sont jamais expirés ici.
 *
 * Doit s'exécuter AVANT `auth:sanctum` : le guard Sanctum écrase `last_used_at`
 * dès qu'il authentifie la requête. Le middleware est donc posé au niveau du
 * groupe de routes des modules (ModuleServiceProvider) et relit lui-même le jeton.
 * Il n'agit que sur les routes protégées par `auth:sanctum` (les routes publiques
 * — login, OTP — restent accessibles même si le client envoie un vieux jeton).
 *
 * Au-delà de `kafo.staff_idle_minutes` sans requête authentifiée, le jeton est
 * supprimé, la session `auth_sessions` révoquée, et la réponse est 401
 * `session_expired`.
 */
class ExpireIdleStaffToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $bearer = $request->bearerToken();

        if (! $bearer || ! $this->routeRequiresSanctum($request)) {
            return $next($request);
        }

        $model = Sanctum::$personalAccessTokenModel;
        $token = $model::findToken($bearer);

        if (! $token || $token->name !== AuthService::STAFF_TOKEN_NAME) {
            return $next($request);
        }

        $idleMinutes = max(1, (int) config('kafo.staff_idle_minutes', 30));
        $lastSeen    = $token->last_used_at ?? $token->created_at;

        if ($lastSeen && $lastSeen->lt(now()->subMinutes($idleMinutes))) {
            $userId = (string) $token->tokenable_id;

            $token->delete();
            AuthSession::where('token_hash', hash('sha256', $bearer))
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now()]);
            AccessLog::record($userId, 'session_expired_idle', $request->ip(), $request->userAgent(), [
                'idle_minutes' => $idleMinutes,
            ]);

            return ApiResponse::error(
                'session_expired',
                "Votre session a expiré après {$idleMinutes} minutes d'inactivité. Veuillez vous reconnecter.",
                401,
            );
        }

        return $next($request);
    }

    private function routeRequiresSanctum(Request $request): bool
    {
        $route = $request->route();

        if (! $route) {
            return false;
        }

        foreach ($route->gatherMiddleware() as $middleware) {
            if (is_string($middleware) && str_starts_with($middleware, 'auth:') && str_contains($middleware, 'sanctum')) {
                return true;
            }
        }

        return false;
    }
}
