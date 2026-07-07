<?php

namespace App\Modules\Payments\Http\Controllers;

use App\Modules\Payments\Application\PaymentService;
use App\Modules\Payments\Domain\Contracts\PspDriver;
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
        private readonly PspDriver      $psp,
    ) {}

    /**
     * POST /webhooks/psp/{channel}
     *
     * Handler générique historique (simulation dev + backfill legacy).
     * En prod : préférer le handler dédié /webhooks/kkiapay.
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

        $payment = $this->service->confirmFromWebhook(
            $data['idempotency_key'],
            $data['psp_reference'],
            $channel,
        );

        return ApiResponse::success(['reference' => $payment->reference, 'status' => 'confirmed']);
    }

    /**
     * POST /webhooks/kkiapay
     *
     * Sécurité (aligné doc KKiaPay) :
     *   - Header `x-kkiapay-secret` comparé en temps constant au secret configuré
     *     dans le dashboard KKiaPay (pas de HMAC — secret partagé).
     *   - Défense en profondeur : verify server-to-server via KKiaPay.
     *
     * Retry KKiaPay : 5 tentatives à 500ms si non-2xx.
     *
     * Payload attendu :
     *   {
     *     transactionId, isPaymentSucces, amount, method,
     *     stateData: { payment_id, reference, idempotency_key },
     *     failureCode?, failureMessage?
     *   }
     */
    public function handleKkiapay(Request $request): JsonResponse
    {
        $expected = (string) config('services.kkiapay.webhook_secret', '');
        $received = (string) $request->header('x-kkiapay-secret', '');

        if ($expected === '' || ! hash_equals($expected, $received)) {
            Log::warning('Webhook KKiaPay : signature invalide', [
                'ip'         => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);
            return ApiResponse::error('invalid_signature', 'Signature webhook invalide.', 401);
        }

        $data = $request->validate([
            'transactionId'   => ['required', 'string'],
            'isPaymentSucces' => ['required', 'boolean'],
            'amount'          => ['nullable', 'integer'],
            'method'          => ['nullable', 'string'],
            'stateData'       => ['nullable'],
            'failureCode'     => ['nullable', 'string'],
            'failureMessage'  => ['nullable', 'string'],
        ]);

        // `stateData` transporte notre payload custom injecté au checkout.
        // Peut arriver comme string JSON ou tableau selon la version PSP.
        $stateData = $data['stateData'] ?? null;
        if (is_string($stateData)) {
            $decoded   = json_decode($stateData, true);
            $stateData = is_array($decoded) ? $decoded : [];
        }
        $stateData = is_array($stateData) ? $stateData : [];

        $paymentId     = $stateData['payment_id']      ?? null;
        $idempotencyKey = $stateData['idempotency_key'] ?? null;

        $payment = $paymentId
            ? Payment::find($paymentId)
            : ($idempotencyKey ? Payment::where('idempotency_key', $idempotencyKey)->first() : null);

        if (! $payment) {
            Log::error('Webhook KKiaPay : Payment introuvable', [
                'transactionId' => $data['transactionId'],
                'stateData'     => $stateData,
            ]);
            return ApiResponse::error('payment_not_found', 'Paiement introuvable pour cette transaction.', 404);
        }

        // Défense en profondeur : verify server-to-server (R-PAY-01).
        try {
            $verification = $this->psp->verify($data['transactionId']);
        } catch (\Throwable $e) {
            Log::error('Webhook KKiaPay : verify() a échoué', [
                'payment_id'    => $payment->id,
                'transactionId' => $data['transactionId'],
                'error'         => $e->getMessage(),
            ]);
            // On refuse par prudence : mieux vaut retry KKiaPay que confirmer à tort.
            return ApiResponse::error('psp_verify_failed', 'Impossible de vérifier la transaction.', 502);
        }

        if ($verification->isSuccess !== (bool) $data['isPaymentSucces']) {
            Log::alert('Webhook KKiaPay : divergence webhook/verify', [
                'payment_id'      => $payment->id,
                'transactionId'   => $data['transactionId'],
                'webhook_success' => $data['isPaymentSucces'],
                'verify_success'  => $verification->isSuccess,
            ]);
            return ApiResponse::error('psp_state_mismatch', 'État PSP incohérent.', 409);
        }

        if ($verification->isSuccess && $verification->amountMinor !== $payment->amount_minor) {
            Log::alert('Webhook KKiaPay : montant incohérent', [
                'payment_id'      => $payment->id,
                'expected_minor'  => $payment->amount_minor,
                'verify_minor'    => $verification->amountMinor,
            ]);
            return ApiResponse::error('psp_amount_mismatch', 'Montant PSP incohérent.', 409);
        }

        if (! $verification->isSuccess) {
            $failed = $this->service->markFailedFromWebhook(
                $payment->idempotency_key,
                $data['transactionId'],
                $verification->failureCode ?? ($data['failureCode'] ?? null),
                $verification->failureMessage ?? ($data['failureMessage'] ?? null),
            );

            return ApiResponse::success(['reference' => $failed->reference, 'status' => 'failed']);
        }

        $confirmed = $this->service->confirmFromWebhook(
            $payment->idempotency_key,
            $data['transactionId'],
            $payment->channel->value,
            [
                'psp'         => 'kkiapay',
                'psp_method'  => $verification->method ?? $data['method'] ?? null,
                'performed_at' => $verification->performedAt,
            ],
        );

        return ApiResponse::success(['reference' => $confirmed->reference, 'status' => 'confirmed']);
    }
}
