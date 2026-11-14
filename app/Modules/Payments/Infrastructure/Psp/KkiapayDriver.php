<?php

namespace App\Modules\Payments\Infrastructure\Psp;

use App\Modules\Payments\Domain\Contracts\PspDriver;
use App\Modules\Payments\Domain\Dto\CheckoutContext;
use App\Modules\Payments\Domain\Dto\PspVerification;
use App\Modules\Payments\Domain\Dto\PspWebhookEvent;
use App\Modules\Payments\Domain\Enums\PaymentChannel;
use App\Modules\Payments\Domain\Exceptions\InvalidWebhookSignatureException;
use App\Modules\Payments\Infrastructure\Models\Payment;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * KKiaPay = agrégateur Mobile Money (réseaux couverts : `services.kkiapay.networks`).
 *
 * Flow :
 *  - prepareCheckout()  : retourne les paramètres du widget côté client (le SDK
 *                         KKiaPay JS/Flutter gère push-USSD et choix opérateur).
 *  - verify()           : appelle l'endpoint /api/v1/transactions/status pour
 *                         confirmer server-to-server.
 *
 * Endpoint verify assumé (à confirmer via dashboard KKiaPay > Développeurs) :
 *   POST {api_url}/api/v1/transactions/status
 *   Headers : x-api-key: {public_key}, x-private-key: {private_key}, x-secret-key: {secret}
 *   Body    : {"transactionId": "..."}
 */
class KkiapayDriver implements PspDriver
{
    public const PROVIDER = 'kkiapay';

    private const REQUIRED_KEYS = ['public_key', 'private_key', 'secret', 'webhook_secret'];

    public function __construct(private readonly array $config) {}

    public function channel(): PaymentChannel
    {
        return PaymentChannel::Kkiapay;
    }

    public function isConfigured(): bool
    {
        foreach (self::REQUIRED_KEYS as $k) {
            if (empty($this->config[$k])) {
                return false;
            }
        }

        return true;
    }

    public function networks(): array
    {
        return array_values($this->config['networks'] ?? ['mtn', 'moov']);
    }

    public function isSandbox(): bool
    {
        return (bool) ($this->config['sandbox'] ?? true);
    }

    /**
     * Sécurité (doc KKiaPay) : header `x-kkiapay-secret` comparé en temps constant au
     * secret du tableau de bord — pas de HMAC. Retry KKiaPay : 5 tentatives si non-2xx.
     *
     * Payload : { transactionId, isPaymentSucces, amount, method,
     *             stateData: { payment_id, reference, idempotency_key }, failureCode?, failureMessage? }
     */
    public function parseWebhook(Request $request): PspWebhookEvent
    {
        $expected = (string) ($this->config['webhook_secret'] ?? '');
        $received = (string) $request->header('x-kkiapay-secret', '');
        if ($expected === '' || ! hash_equals($expected, $received)) {
            throw new InvalidWebhookSignatureException('Signature webhook KKiaPay invalide.');
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

        // `stateData` transporte notre payload injecté au checkout (chaîne JSON ou tableau).
        $state = $data['stateData'] ?? null;
        if (is_string($state)) {
            $decoded = json_decode($state, true);
            $state   = is_array($decoded) ? $decoded : [];
        }
        $state = is_array($state) ? $state : [];

        return new PspWebhookEvent(
            transactionId:  $data['transactionId'],
            claimedSuccess: (bool) $data['isPaymentSucces'],
            paymentId:      $state['payment_id'] ?? null,
            idempotencyKey: $state['idempotency_key'] ?? null,
            method:         $data['method'] ?? null,
            failureCode:    $data['failureCode'] ?? null,
            failureMessage: $data['failureMessage'] ?? null,
        );
    }

    public function prepareCheckout(Payment $payment): CheckoutContext
    {
        $this->assertConfigured(['public_key']);

        $membership = $payment->membership()->with('user')->first();
        $user       = $membership?->user;

        return new CheckoutContext(
            provider:    self::PROVIDER,
            publicKey:   $this->config['public_key'],
            amountMinor: $payment->amount_minor,
            currency:    'XOF',
            sandbox:     (bool) ($this->config['sandbox'] ?? true),
            data: [
                'payment_id'      => $payment->id,
                'reference'       => $payment->reference,
                'idempotency_key' => $payment->idempotency_key,
            ],
            phone:    $payment->phone ?? $user?->phone,
            fullName: $user?->full_name,
            reason:   "Cotisation Kafo — {$payment->reference}",
            restrictPaymentMethods: ['momo'],
            mode: 'widget',
        );
    }

    public function verify(string $transactionId): PspVerification
    {
        $this->assertConfigured(['public_key', 'private_key', 'secret']);

        $baseUrl = ($this->config['sandbox'] ?? true)
            ? ($this->config['sandbox_api_url'] ?? 'https://api-sandbox.kkiapay.me')
            : ($this->config['api_url'] ?? 'https://api.kkiapay.me');

        try {
            $response = Http::baseUrl($baseUrl)
                ->timeout((int) ($this->config['timeout'] ?? 15))
                ->withHeaders([
                    'x-api-key'     => $this->config['public_key'],
                    'x-private-key' => $this->config['private_key'],
                    'x-secret-key'  => $this->config['secret'],
                    'Accept'        => 'application/json',
                ])
                ->post('/api/v1/transactions/status', [
                    'transactionId' => $transactionId,
                ])
                ->throw()
                ->json();
        } catch (RequestException $e) {
            throw new RuntimeException(
                "Verify KKiaPay échoué pour {$transactionId}: " . $e->getMessage(),
                previous: $e,
            );
        }

        $status    = strtoupper((string) ($response['status'] ?? ''));
        $isSuccess = in_array($status, ['SUCCESS', 'CONFIRMED', 'COMPLETED'], true);

        return new PspVerification(
            transactionId:  $response['transactionId'] ?? $transactionId,
            isSuccess:      $isSuccess,
            amountMinor:    (int) ($response['amount'] ?? 0),
            currency:       $response['currency'] ?? 'XOF',
            method:         $response['paymentMethod'] ?? $response['type'] ?? null,
            performedAt:    $response['performedAt'] ?? null,
            failureCode:    $isSuccess ? null : ($response['failureCode'] ?? $status ?: null),
            failureMessage: $isSuccess ? null : ($response['failureMessage'] ?? null),
            raw:            is_array($response) ? $response : [],
        );
    }

    private function assertConfigured(array $keys): void
    {
        foreach ($keys as $k) {
            if (empty($this->config[$k])) {
                throw new RuntimeException("KKiaPay config manquante: {$k}");
            }
        }
    }
}
