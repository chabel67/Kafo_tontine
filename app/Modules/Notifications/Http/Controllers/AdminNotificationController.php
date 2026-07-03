<?php

namespace App\Modules\Notifications\Http\Controllers;

use App\Modules\Notifications\Application\NotificationService;
use App\Modules\Notifications\Infrastructure\Models\InAppNotification;
use App\Shared\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class AdminNotificationController extends Controller
{
    public function __construct(private readonly NotificationService $service) {}

    public function index(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        $notifications = InAppNotification::where(
            fn ($q) => $q->where('user_id', $userId)->orWhereNull('user_id')
        )
            ->orderByRaw('read_at IS NOT NULL ASC')
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->map(fn ($n) => [
                'id'         => $n->id,
                'type'       => $n->type,
                'title'      => $n->title,
                'body'       => $n->body,
                'action_url' => $n->action_url,
                'read'       => ! is_null($n->read_at),
                'created_at' => $n->created_at?->toIso8601String(),
            ]);

        return ApiResponse::success($notifications);
    }

    public function count(Request $request): JsonResponse
    {
        $count = $this->service->unreadCount($request->user()->id);
        return ApiResponse::success(['unread' => $count]);
    }

    public function markRead(Request $request, string $id): JsonResponse
    {
        $this->service->markRead($id, $request->user()->id);
        return ApiResponse::success(['marked' => true]);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $this->service->markAllRead($request->user()->id);
        return ApiResponse::success(['marked' => true]);
    }
}
