<?php

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Domain\Exceptions\UnauthorizedException;
use App\Modules\Identity\Infrastructure\Models\User;

class AuthorizationService
{
    public function has(User $user, string $permission, ?string $campaignId = null): bool
    {
        return $user->hasPermission($permission, $campaignId);
    }

    public function assert(User $user, string $permission, ?string $campaignId = null): void
    {
        if (! $this->has($user, $permission, $campaignId)) {
            throw new UnauthorizedException($permission);
        }
    }
}
