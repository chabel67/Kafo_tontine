<?php

namespace App\Modules\Payments\Http\Controllers;

use App\Modules\Payments\Domain\Enums\PaymentChannel;
use App\Modules\Payments\Domain\Enums\PaymentStatus;
use App\Modules\Payments\Infrastructure\Models\Payment;
use App\Modules\Payments\Infrastructure\Psp\PspRegistry;
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
    public function __construct(private readonly PspRegistry $psps) {}

    /**
     * GET /member/payment-methods — agrégateurs avec lesquels le membre peut cotiser
     * (configurés et activés), avec les réseaux couverts (DA-20).
     */
    public function methods(): JsonResponse
    {
        return ApiResponse::success(array_map(
            fn (PaymentChannel $c) => \Illuminate\Support\Arr::only($this->psps->describe($c), ['channel', 'label', 'networks', 'mode']),
            $this->psps->available(),
        ));
    }

    public function initiate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'membership_id'   => ['required', 'uuid'],
            'channel'         => ['required', new Enum(PaymentChannel::class)],
            'amount_minor'    => ['required', 'integer', 'min:1'],
            'phone'           => ['nullable', 'string', 'max:20'],
            'idempotency_key' => ['nullable', 'string', 'max:100'],
        ]);

        // DA-20 : on cotise en ligne avec un agrégateur configuré et activé. Les espèces
        // se remettent au trésorier.
        $channel = PaymentChannel::from($data['channel']);
        if (! $this->psps->isAvailable($channel)) {
            $supported = array_map(fn (PaymentChannel $c) => $c->value, $this->psps->available());
            return ApiResponse::error(
                'channel_not_supported',
                $channel === PaymentChannel::Cash
                    ? 'Le paiement en espèces se fait auprès de votre trésorier, pas depuis l\'application.'
                    : "{$channel->label()} n'est pas disponible pour cotiser en ligne pour le moment.",
                422,
                ['details' => ['channel' => $channel->value, 'supported_channels' => $supported]],
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
            return $this->respondWithCheckout($existing, created: false);
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

        return $this->respondWithCheckout($payment, created: true);
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
            'network'      => $p->network,
        ]);

        return ApiResponse::success($items, meta: [
            'total'        => $payments->total(),
            'per_page'     => $payments->perPage(),
            'current_page' => $payments->currentPage(),
            'last_page'    => $payments->lastPage(),
        ]);
    }

    /**
     * L'agrégateur peut être injoignable (FedaPay crée la transaction côté serveur) :
     * le paiement reste en attente, et rejouer la même clé d'idempotence réessaie.
     */
    private function respondWithCheckout(Payment $p, bool $created): JsonResponse
    {
        try {
            $body = $this->formatWithCheckout($p);
        } catch (\RuntimeException $e) {
            \Illuminate\Support\Facades\Log::error('Checkout agrégateur impossible', [
                'payment_id' => $p->id, 'channel' => $p->channel->value, 'error' => $e->getMessage(),
            ]);
            return ApiResponse::error('psp_checkout_failed',
                "{$p->channel->label()} ne répond pas pour le moment. Réessayez dans quelques instants.", 502,
                ['details' => ['payment_id' => $p->id]]);
        }

        return $created ? ApiResponse::created($body) : ApiResponse::success($body);
    }

    private function formatWithCheckout(Payment $p): array
    {
        $out = $this->formatPayment($p);

        if ($p->channel->isPsp() && $p->status === PaymentStatus::Pending && $this->psps->has($p->channel)) {
            $out['checkout'] = $this->psps->driver($p->channel)->prepareCheckout($p)->toArray();
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
            'network'      => $p->network,
            'created_at'   => $p->created_at->toIso8601String(),
        ];
    }
}
