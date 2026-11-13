<?php

namespace App\Modules\Payments\Http\Controllers;

use App\Modules\Payments\Domain\Contracts\PspDriver;
use App\Modules\Payments\Domain\Enums\PaymentChannel;
use App\Modules\Payments\Domain\Enums\PaymentStatus;
use App\Modules\Payments\Infrastructure\Models\Payment;
use App\Modules\Tontine\Domain\Enums\MembershipStatus;
use App\Modules\Tontine\Infrastructure\Models\Membership;
use App\Shared\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Enum;

class MemberPaymentController extends Controller
{
    public function __construct(private readonly PspDriver $psp) {}

    public function initiate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'membership_id'   => ['required', 'uuid'],
            'channel'         => ['required', new Enum(PaymentChannel::class)],
            'amount_minor'    => ['required', 'integer', 'min:1'],
            'phone'           => ['nullable', 'string', 'max:20'],
            'idempotency_key' => ['nullable', 'string', 'max:100'],
        ]);

        // DA-13 : seul un canal MoMo routé par KKiaPay (MTN, Moov) s'initie depuis l'app.
        // Les espèces se remettent au trésorier ; Orange et Wave ne sont pas routés.
        $channel = PaymentChannel::from($data['channel']);
        if (! $channel->isMomo() || ! $channel->isRoutedByPsp()) {
            return ApiResponse::error(
                'channel_not_supported',
                $channel === PaymentChannel::Cash
                    ? 'Le paiement en espèces se fait auprès de votre trésorier, pas depuis l\'application.'
                    : "{$channel->label()} n'est pas disponible pour cotiser en ligne. Choisissez MTN MoMo ou Moov Money.",
                422,
                ['details' => ['channel' => $channel->value, 'supported_channels' => ['mtn', 'moov']]],
            );
        }

        $membership = Membership::where('id', $data['membership_id'])
            ->where('user_id', $request->user()->id)
            ->where('status', MembershipStatus::Active)
            ->firstOrFail();

        // Plus de cotisation sur une campagne clôturée (règlement de clôture en cours).
        if ($membership->campaign()->value('status') === 'closed') {
            throw \App\Modules\Tontine\Domain\Exceptions\TontineRuleException::campaignNotActive();
        }

        $idempotencyKey = $data['idempotency_key'] ?? Str::uuid()->toString();

        $existing = Payment::where('idempotency_key', $idempotencyKey)->first();
        if ($existing) {
            return ApiResponse::success($this->formatWithCheckout($existing));
        }

        $reference = \App\Shared\Reference::next('PAY', \App\Shared\Reference::PAY);

        $payment = Payment::create([
            'reference'       => $reference,
            'idempotency_key' => $idempotencyKey,
            'membership_id'   => $membership->id,
            'channel'         => $data['channel'],
            'status'          => PaymentStatus::Pending,
            'amount_minor'    => $data['amount_minor'],
            'phone'           => $data['phone'] ?? $request->user()->phone,
            'created_by'      => $request->user()->id,
        ]);

        return ApiResponse::created($this->formatWithCheckout($payment));
    }

    public function transactions(Request $request): JsonResponse
    {
        $membershipIds = Membership::where('user_id', $request->user()->id)
            ->pluck('id');

        $payments = Payment::whereIn('membership_id', $membershipIds)
            ->with('membership.campaign')
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        $items = collect($payments->items())->map(fn (Payment $p) => [
            'id'           => $p->id,
            'reference'    => $p->reference,
            'date'         => $p->created_at->toDateString(),
            // R-FEE-02 : un frais d'adhésion n'est pas une cotisation (jamais d'épargne).
            'label'        => ($p->purpose === 'membership_fee' ? 'Frais d\'adhésion — ' : 'Cotisation — ')
                . ($p->membership->campaign->name ?? '—'),
            'purpose'      => $p->purpose ?? 'contribution',
            'amount_minor' => $p->amount_minor,
            'type'         => 'debit',
            'status'       => $p->status->value,
            'channel'      => $p->channel->value,
        ]);

        return ApiResponse::success($items, meta: [
            'total'        => $payments->total(),
            'per_page'     => $payments->perPage(),
            'current_page' => $payments->currentPage(),
            'last_page'    => $payments->lastPage(),
        ]);
    }

    private function formatWithCheckout(Payment $p): array
    {
        $out = $this->formatPayment($p);

        if ($p->channel->isMomo() && $p->status === PaymentStatus::Pending) {
            $out['checkout'] = $this->psp->prepareCheckout($p)->toArray();
        }

        return $out;
    }

    private function formatPayment(Payment $p): array
    {
        return [
            'id'           => $p->id,
            'reference'    => $p->reference,
            'status'       => $p->status->value,
            'amount_minor' => $p->amount_minor,
            'channel'      => $p->channel->value,
            'created_at'   => $p->created_at->toIso8601String(),
        ];
    }
}
