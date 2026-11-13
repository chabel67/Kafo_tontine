<?php

namespace App\Modules\Identity\Application;

use App\Modules\Audit\Domain\Contracts\AuditEntityLabelResolver;
use App\Modules\Identity\Infrastructure\Models\User;

/** Libellé des entités d'audit `user` : nom complet du membre ou de l'agent. */
class UserAuditLabelResolver implements AuditEntityLabelResolver
{
    public function labels(string $entityType, array $ids): array
    {
        return User::whereIn('id', $ids)->pluck('full_name', 'id')
            ->map(fn ($name) => $name ?: 'Utilisateur sans nom')
            ->all();
    }
}
