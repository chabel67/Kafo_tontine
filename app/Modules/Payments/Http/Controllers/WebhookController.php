<?php

namespace App\Modules\Payments\Http\Controllers;

use App\Modules\Payments\Application\PaymentService;
use App\Modules\Payments\Domain\Dto\PspWebhookEvent;
use App\Modules\Payments\Domain\Enums\MobileNetwork;
use App\Modules\Payments\Domain\Enums\PaymentChannel;
use App\Modules\Payments\Domain\Exceptions\InvalidWebhookSignatureException;
use App\Modules\Payments\Infrastructure\Psp\PspRegistry;
use App\Modules\Payments\Infrastructure\Models\Payment;
use App\Shared\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
    public function __construct(
        private readonly PaymentService $service,
        private readonly PspRegistry    $psps,
    ) {}

    /**
     * POST /webhooks/psp/{channel}
     *
     * Handler générique historique (simulation dev + backfill legacy).
     * En prod : handlers dédiés /webhooks/kkiapay et /webhooks/fedapay.
     */
    public function handle(Request $request, string $channel): JsonResponse
    {
        $data = $request->validate([
            'idempotency_key' => ['required', 'string'],
            'psp_reference'   => ['required', 'string'],
            'status'          => ['required', 'string', 'in:confirmed,failed'],
        ]);

        Log::info("Webhook PSP [{$channel}]", $data);

        if ($data['status'] !== 'confirmed') {
            return ApiResponse::success(['acknowledged' => true]);
        }

        // DA-20 : le compte de trésorerie suit le moyen choisi à l'initiation, pas l'URL.
        $pending = Payment::where('idempotency_key', $data['idempotency_key'])->firstOrFail();
        $payment = $this->service->confirmFromWebhook(
            $data['idempotency_key'],
            $data['psp_reference'],
            $pending->channel->value,
        );

        return ApiResponse::success(['reference' => $payment->reference, 'status' => 'confirmed']);
    }

    /** POST /webhooks/kkiapay */
    public function handleKkiapay(Request $request): JsonResponse
    {
        return $this->handleProvider($request, PaymentChannel::Kkiapay);
    }

    /** POST /webhooks/fedapay */
    public function handleFedapay(Request $request): JsonResponse
    {
        return $this->handleProvider($request, PaymentChannel::Fedapay);
    }

    /**
     * Webhook d'un agrégateur (DA-20) — même enchaînement pour tous :
     *   1. signature vérifiée par le driver (KKiaPay : secret partagé ; FedaPay : HMAC) ;
     *   2. paiement retrouvé (id interne transmis au checkout, sinon clé d'idempotence,
     *      sinon transaction mémorisée au checkout) ;
     *   3. défense en profondeur : `verify()` server-to-server (R-PAY-01) ;
     *      divergence d'état ou de montant → 409 sans écriture ;
     *   4. confirmation (écriture DR PSP_FLOAT:{AGRÉGATEUR}) ou échec — idempotents.
     */
    private function handleProvider(Request $request, PaymentChannel $provider): JsonResponse
    {
        $driver = $this->psps->driver($provider);
        $tag    = $provider->label();

        try {
            $event = $driver->parseWebhook($request);
        } catch (InvalidWebhookSignatureException $e) {
            Log::warning("Webhook {$tag} : signature invalide", [
                'ip'         => $request->ip(),
                'user_agent' => $request->userAgent(),
                'reason'     => $e->getMessage(),
            ]);
            return ApiResponse::error('invalid_signature', 'Signature webhook invalide.', 401);
        }

        // Événement sans issue (transaction créée, en attente…) : rien à faire.
        if ($event->claimedSuccess === null || $event->transactionId === '') {
            return ApiResponse::success(['acknowledged' => true, 'event' => $event->eventName]);
        }

        $payment = $this->findPayment($provider, $event);
        if (! $payment) {
            Log::error("Webhook {$tag} : Payment introuvable", [
                'transactionId' => $event->transactionId,
                'payment_id'    => $event->paymentId,
            ]);
            return ApiResponse::error('payment_not_found', 'Paiement introuvable pour cette transaction.', 404);
        }

        if ($payment->channel !== $provider) {
            Log::alert("Webhook {$tag} : paiement initié avec un autre moyen", [
                'payment_id' => $payment->id,
                'channel'    => $payment->channel?->value,
            ]);
            return ApiResponse::error('psp_channel_mismatch', 'Ce paiement n\'a pas été initié avec cet agrégateur.', 409);
        }

        try {
            $verification = $driver->verify($event->transactionId);
        } catch (\Throwable $e) {
            Log::error("Webhook {$tag} : verify() a échoué", [
                'payment_id'    => $payment->id,
                'transactionId' => $event->transactionId,
                'error'         => $e->getMessage(),
            ]);
            // Par prudence : mieux vaut un nouvel essai de l'agrégateur qu'une confirmation à tort.
            return ApiResponse::error('psp_verify_failed', 'Impossible de vérifier la transaction.', 502);
        }

        if ($verification->isSuccess !== $event->claimedSuccess) {
            Log::alert("Webhook {$tag} : divergence webhook/verify", [
                'payment_id'      => $payment->id,
                'transactionId'   => $event->transactionId,
                'webhook_success' => $event->claimedSuccess,
                'verify_success'  => $verification->isSuccess,
            ]);
            return ApiResponse::error('psp_state_mismatch', 'État PSP incohérent.', 409);
        }

        if ($verification->isSuccess && $verification->amountMinor !== $payment->amount_minor) {
            Log::alert("Webhook {$tag} : montant incohérent", [
                'payment_id'     => $payment->id,
                'expected_minor' => $payment->amount_minor,
                'verify_minor'   => $verification->amountMinor,
            ]);
            return ApiResponse::error('psp_amount_mismatch', 'Montant PSP incohérent.', 409);
        }

        if (! $verification->isSuccess) {
            $failed = $this->service->markFailedFromWebhook(
                $payment->idempotency_key,
                $event->transactionId,
                $verification->failureCode ?? $event->failureCode,
                $verification->failureMessage ?? $event->failureMessage,
            );

            return ApiResponse::success(['reference' => $failed->reference, 'status' => 'failed']);
        }

        $method    = $verification->method ?? $event->method;
        $confirmed = $this->service->confirmFromWebhook(
            $payment->idempotency_key,
            $event->transactionId,
            $provider->value,
            [
                'psp'          => $provider->value,
                'psp_method'   => $method,
                'performed_at' => $verification->performedAt,
            ],
            MobileNetwork::fromPspMethod($method)?->value,
        );

        return ApiResponse::success(['reference' => $confirmed->reference, 'status' => 'confirmed']);
    }

    private function findPayment(PaymentChannel $provider, PspWebhookEvent $event): ?Payment
    {
        if ($event->paymentId && \Illuminate\Support\Str::isUuid($event->paymentId)
            && ($p = Payment::find($event->paymentId))) {
            return $p;
        }
        if ($event->idempotencyKey && ($p = Payment::where('idempotency_key', $event->idempotencyKey)->first())) {
            return $p;
        }

        // Transaction créée côté serveur (FedaPay) et mémorisée au checkout.
        return Payment::where('channel', $provider->value)
            ->where('metadata->psp_checkout->transaction_id', $event->transactionId)
            ->first();
    }
}
