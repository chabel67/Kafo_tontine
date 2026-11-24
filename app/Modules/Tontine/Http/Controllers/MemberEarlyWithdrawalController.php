<?php

namespace App\Modules\Tontine\Http\Controllers;

use App\Modules\Audit\Application\AuditService;
use App\Modules\Notifications\Application\NotificationService;
use App\Modules\Tontine\Application\EarlyWithdrawalService;
use App\Modules\Tontine\Application\WithdrawalAttestationService;
use App\Modules\Tontine\Http\Requests\CreateEarlyWithdrawalRequest;
use App\Modules\Tontine\Http\Requests\RespondEarlyWithdrawalRequest;
use App\Modules\Tontine\Http\Resources\EarlyWithdrawalResource;
use App\Modules\Tontine\Infrastructure\Models\EarlyWithdrawal;
use App\Modules\Tontine\Infrastructure\Models\Membership;
use App\Shared\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

/**
 * Retraits anticipés — app membre. Un membre ne voit et n'agit que sur ses propres
 * adhésions et demandes (404 sinon, pour ne rien révéler).
 */
class MemberEarlyWithdrawalController extends Controller
{
    public function __construct(
        private readonly EarlyWithdrawalService $withdrawals,
        private readonly AuditService           $audit,
        private readonly NotificationService    $notifications,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $items = EarlyWithdrawal::with('membership.campaign')
            ->where('user_id', $request->user()->id)
            ->orderByDesc('created_at')
            ->get();

        return ApiResponse::success(EarlyWithdrawalResource::collection($items));
    }

    public function withdrawable(Request $request, Membership $membership): JsonResponse
    {
        $this->assertOwner($request, $membership->user_id);

        return ApiResponse::success($this->withdrawals->breakdown($membership));
    }

    public function store(CreateEarlyWithdrawalRequest $request): JsonResponse
    {
        $data       = $request->validated();
        $membership = Membership::findOrFail($data['membership_id']);
        $this->assertOwner($request, $membership->user_id);

        $withdrawal = $this->withdrawals->request(
            $membership, (int) $data['amount_minor'], $data['reason'] ?? null,
            $data['payout_channel'], $data['payout_phone'] ?? null,
            $request->user(), 'member', $data['idempotency_key'],
            $data['payout_network'] ?? null,
        );

        if ($withdrawal->wasRecentlyCreated) {
            $this->audit->logFromRequest($request, 'early_withdrawal.request', 'early_withdrawal', $withdrawal->id, [],
                ['amount_minor' => $withdrawal->amount_minor, 'membership_id' => $membership->id, 'via' => 'member']);

            $this->notifications->broadcast(
                'early_withdrawal.requested',
                'Nouvelle demande de retrait anticipé',
                sprintf('%s demande un retrait de %s FCFA (%s).',
                    $request->user()->full_name ?? $request->user()->phone,
                    number_format($withdrawal->amount_minor, 0, ',', ' '),
                    $withdrawal->reference),
                "/withdrawals/{$withdrawal->id}",
            );
        }

        return ApiResponse::created(new EarlyWithdrawalResource($withdrawal->load('membership.campaign')));
    }

    public function cancel(Request $request, EarlyWithdrawal $withdrawal): JsonResponse
    {
        $this->assertOwner($request, $withdrawal->user_id);

        // Le membre annule tant que l'analyse n'a pas commencé (ou sur demande d'informations).
        if (! in_array($withdrawal->status->value, ['pending', 'info_requested'], true)) {
            throw new \App\Modules\Tontine\Domain\Exceptions\InvalidWithdrawalTransitionException($withdrawal->status->value, 'cancelled');
        }

        $old       = $withdrawal->status->value;
        $cancelled = $this->withdrawals->cancel($withdrawal);

        $this->audit->logFromRequest($request, 'early_withdrawal.cancel', 'early_withdrawal', $withdrawal->id,
            ['status' => $old], ['status' => 'cancelled']);

        return ApiResponse::success(new EarlyWithdrawalResource($cancelled->load('membership.campaign')));
    }

    /** Réponse du membre à une demande d'informations complémentaires. */
    public function respond(RespondEarlyWithdrawalRequest $request, EarlyWithdrawal $withdrawal): JsonResponse
    {
        $this->assertOwner($request, $withdrawal->user_id);

        $updated = $this->withdrawals->respond($withdrawal, $request->validated('response'));

        $this->audit->logFromRequest($request, 'early_withdrawal.respond', 'early_withdrawal', $withdrawal->id,
            ['status' => 'info_requested'], ['status' => $updated->status->value]);

        return ApiResponse::success(new EarlyWithdrawalResource($updated->load('membership.campaign')));
    }

    /** GET /member/early-withdrawals/{withdrawal}/attestation — PDF de SES retraits versés (404 sinon). */
    public function attestation(Request $request, EarlyWithdrawal $withdrawal, WithdrawalAttestationService $attestations): Response|JsonResponse
    {
        $this->assertOwner($request, $withdrawal->user_id);

        if (! WithdrawalAttestationService::isAvailable($withdrawal)) {
            return ApiResponse::error('attestation_unavailable', "L'attestation est disponible une fois le versement confirmé.", 422);
        }
        $this->audit->logFromRequest($request, 'early_withdrawal.attestation.download', 'early_withdrawal', $withdrawal->id, [], ['reference' => $withdrawal->reference]);

        return $attestations->download($withdrawal);
    }

    private function assertOwner(Request $request, string $ownerId): void
    {
        if ($ownerId !== $request->user()->id) {
            abort(404);
        }
    }
}
