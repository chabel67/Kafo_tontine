<?php

namespace App\Modules\Notifications\Http\Controllers;

use App\Modules\Notifications\Infrastructure\Models\InAppNotification;
use App\Shared\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Notifications in-app de l'app membre.
 *
 * Strictement limitées aux notifications adressées au membre (user_id = membre) :
 * les diffusions staff (user_id NULL) ne sont ni lues ni modifiables ici — contrairement
 * à NotificationService::markRead/markAllRead, qui les incluent pour le personnel.
 */
class MemberNotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $notifications = $this->own($request)
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
        return ApiResponse::success(['unread' => $this->own($request)->whereNull('read_at')->count()]);
    }

    public function markRead(Request $request, string $id): JsonResponse
    {
        $this->own($request)->where('id', $id)->update(['read_at' => now()]);

        return ApiResponse::success(['marked' => true]);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $this->own($request)->whereNull('read_at')->update(['read_at' => now()]);

        return ApiResponse::success(['marked' => true]);
    }

    private function own(Request $request)
    {
        return InAppNotification::where('user_id', $request->user()->id);
    }
}
