<?php

namespace App\Modules\Notifications\Application;

use App\Modules\Notifications\Infrastructure\Models\InAppNotification;

class NotificationService
{
    /**
     * Creates a notification for a specific user (or broadcast to all if user_id is null).
     */
    public function notify(
        ?string $userId,
        string $type,
        string $title,
        string $body,
        ?string $actionUrl = null,
    ): InAppNotification {
        return InAppNotification::create([
            'user_id'    => $userId,
            'type'       => $type,
            'title'      => $title,
            'body'       => $body,
            'action_url' => $actionUrl,
        ]);
    }

    /**
     * Broadcast a notification to all staff (user_id = null → visible to all).
     */
    public function broadcast(string $type, string $title, string $body, ?string $actionUrl = null): InAppNotification
    {
        return $this->notify(null, $type, $title, $body, $actionUrl);
    }

    public function markRead(string $notificationId, string $userId): void
    {
        InAppNotification::where('id', $notificationId)
            ->where(fn ($q) => $q->where('user_id', $userId)->orWhereNull('user_id'))
            ->update(['read_at' => now()]);
    }

    public function markAllRead(string $userId): void
    {
        InAppNotification::where(fn ($q) => $q->where('user_id', $userId)->orWhereNull('user_id'))
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    public function unreadCount(string $userId): int
    {
        return InAppNotification::where(fn ($q) => $q->where('user_id', $userId)->orWhereNull('user_id'))
            ->whereNull('read_at')
            ->count();
    }
}
