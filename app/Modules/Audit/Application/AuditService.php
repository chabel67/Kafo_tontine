<?php

namespace App\Modules\Audit\Application;

use App\Modules\Audit\Infrastructure\Models\AuditLog;
use Illuminate\Http\Request;

class AuditService
{
    public function log(
        string  $action,
        ?string $entityType  = null,
        ?string $entityId    = null,
        array   $oldValues   = [],
        array   $newValues   = [],
        ?string $userId      = null,
        ?string $userName    = null,
        ?string $ipAddress   = null,
        ?string $userAgent   = null,
    ): AuditLog {
        return AuditLog::create([
            'user_id'     => $userId,
            'user_name'   => $userName,
            'action'      => $action,
            'entity_type' => $entityType,
            'entity_id'   => $entityId,
            'old_values'  => empty($oldValues) ? null : $oldValues,
            'new_values'  => empty($newValues) ? null : $newValues,
            'ip_address'  => $ipAddress,
            'user_agent'  => $userAgent ? substr($userAgent, 0, 512) : null,
        ]);
    }

    /**
     * Historique paginé d'un ensemble d'entités (vue par entité, EF-AUDIT-03).
     *
     * @param  array<string, string[]>  $entities  ['user' => [id], 'membership' => [id, …]]
     */
    public function forEntities(array $entities, int $perPage = 15): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        return AuditLog::query()
            ->where(function ($q) use ($entities) {
                foreach ($entities as $type => $ids) {
                    if (empty($ids)) continue;
                    $q->orWhere(fn ($w) => $w->where('entity_type', $type)->whereIn('entity_id', $ids));
                }
            })
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }

    public function logFromRequest(
        Request $request,
        string  $action,
        ?string $entityType = null,
        ?string $entityId   = null,
        array   $oldValues  = [],
        array   $newValues  = [],
    ): AuditLog {
        $user = $request->user();

        return $this->log(
            action:     $action,
            entityType: $entityType,
            entityId:   $entityId,
            oldValues:  $oldValues,
            newValues:  $newValues,
            userId:     $user?->id,
            userName:   $user?->full_name,
            ipAddress:  $request->ip(),
            userAgent:  $request->userAgent(),
        );
    }
}
