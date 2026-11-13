<?php

namespace App\Modules\Tontine\Http\Controllers;

use App\Modules\Tontine\Application\RotationTrackingService;
use App\Modules\Tontine\Domain\Exceptions\TontineRuleException;
use App\Modules\Tontine\Infrastructure\Models\Membership;
use App\Shared\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/** Tontine rotative — espace membre : sa situation et le calendrier des tours (D-R8). */
class MemberRotationController extends Controller
{
    public function __construct(private readonly RotationTrackingService $tracking) {}

    public function show(Request $request, Membership $membership): JsonResponse
    {
        abort_unless($membership->user_id === $request->user()->id, 404);
        if (! $membership->campaign->isRotating()) {
            throw TontineRuleException::notRotating();
        }

        return ApiResponse::success($this->tracking->memberView($membership));
    }
}
