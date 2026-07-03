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
