<?php

namespace App\Modules\Identity\Http\Middleware;

use App\Modules\Identity\Domain\Exceptions\UnauthorizedException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware d'autorisation basé sur les permissions du modèle User.
 *
 * Usage : `->middleware('permission:loan.disburse')`
 *
 * Plusieurs permissions séparées par une virgule sont évaluées en OU (l'user
 * doit avoir AU MOINS UNE) : `permission:loan.approve,loan.countersign`.
 *
 * Le rôle super_admin (permission wildcard `*`) est court-circuité par
 * `User::hasPermission()` — pas besoin de le lister explicitement.
 *
 * Ce middleware n'applique PAS le scope campagne (role_user.campaign_id) — à
 * traiter dans un futur chantier via des Policies au niveau resource.
 */
class EnsurePermission
{
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $request->user();

        if (! $user) {
            throw new UnauthorizedException();
        }

        foreach ($permissions as $permission) {
            if ($user->hasPermission($permission)) {
                return $next($request);
            }
        }

        throw new UnauthorizedException($permissions[0] ?? '');
    }
}
