<?php

namespace App\Modules\Payments\Infrastructure\Psp;

use App\Modules\Payments\Domain\Contracts\PspDriver;
use App\Modules\Payments\Domain\Dto\CheckoutContext;
use App\Modules\Payments\Domain\Dto\PspVerification;
use App\Modules\Payments\Domain\Dto\PspWebhookEvent;
use App\Modules\Payments\Domain\Enums\PaymentChannel;
use App\Modules\Payments\Domain\Exceptions\InvalidWebhookSignatureException;
use App\Modules\Payments\Infrastructure\Models\Payment;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * FedaPay = agrégateur Mobile Money (Bénin : MTN `mtn_open`, Moov `moov`, Celtiis `sbin`…).
 *
 * Contrairement à KKiaPay, la transaction est créée CÔTÉ SERVEUR :
 *  - prepareCheckout() : POST /v1/transactions puis POST /v1/transactions/{id}/token →
 *                        URL de la page de paiement FedaPay (mode `redirect`). La transaction
 *                        est mémorisée sur le paiement (`metadata.psp_checkout`) : rappeler
 *                        l'initiation ne crée pas une seconde transaction.
 *  - verify()          : GET /v1/transactions/{id} (statut + montant).
 *  - parseWebhook()    : header `X-FEDAPAY-SIGNATURE` = « t={timestamp},s={hex} »,
 *                        HMAC-SHA256 de « {timestamp}.{corps brut} » avec le secret du
 *                        webhook, tolérance 300 s (comme le SDK PHP officiel).
 *
 * Doc : https://docs.fedapay.com (API v1). Auth : `Authorization: Bearer {secret_key}`.
 */
class FedapayDriver implements PspDriver
{
    private const REQUIRED_KEYS  = ['secret_key', 'webhook_secret'];
    private const SUCCESS        = ['approved', 'transferred'];
    private const FAILURE        = ['declined', 'canceled', 'cancelled', 'refunded', 'expired'];
    private const TOLERANCE_SECS = 300;

    public function __construct(private readonly array $config) {}

    public function channel(): PaymentChannel
    {
        return PaymentChannel::Fedapay;
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
        return array_values($this->config['networks'] ?? ['mtn', 'moov', 'celtiis']);
    }

    public function isSandbox(): bool
    {
        return (bool) ($this->config['sandbox'] ?? true);
    }

    public function prepareCheckout(Payment $payment): CheckoutContext
    {
        $this->assertConfigured();

        $stored = (array) (($payment->metadata ?? [])['psp_checkout'] ?? []);
        if (($stored['provider'] ?? null) === 'fedapay' && ! empty($stored['url']) && ! empty($stored['transaction_id'])) {
            return $this->context($payment, (string) $stored['transaction_id'], (string) $stored['url']);
        }

        $membership = $payment->membership()->with('user')->first();
        $user       = $membership?->user;

        $body = array_filter([
            'description'     => "Cotisation Kafo — {$payment->reference}",
            'amount'          => $payment->amount_minor,
            'currency'        => ['iso' => 'XOF'],
            'callback_url'    => $this->returnUrl($payment),
            'custom_metadata' => [
                'payment_id'      => $payment->id,
                'reference'       => $payment->reference,
                'idempotency_key' => $payment->idempotency_key,
            ],
            'customer'        => $this->customer($user?->full_name, $payment->phone ?? $user?->phone),
        ], fn ($v) => $v !== null);

        try {
            $created = $this->unwrap($this->http()->post('/transactions', $body)->throw()->json());
            $id      = (string) ($created['id'] ?? '');
            if ($id === '') {
                throw new RuntimeException('Réponse FedaPay sans identifiant de transaction.');
            }
            $token = $this->unwrap($this->http()->post("/transactions/{$id}/token")->throw()->json());
            $url   = (string) ($token['url'] ?? '');
            if ($url === '') {
                throw new RuntimeException('Réponse FedaPay sans lien de paiement.');
            }
        } catch (RequestException $e) {
            throw new RuntimeException("Création de la transaction FedaPay échouée pour {$payment->reference}: " . $e->getMessage(), previous: $e);
        }

        $payment->update(['metadata' => array_merge((array) $payment->metadata, [
            'psp_checkout' => ['provider' => 'fedapay', 'transaction_id' => $id, 'url' => $url],
        ])]);

        return $this->context($payment, $id, $url);
    }

    public function verify(string $transactionId): PspVerification
    {
        $this->assertConfigured();

        try {
            $t = $this->unwrap($this->http()->get('/transactions/' . rawurlencode($transactionId))->throw()->json());
        } catch (RequestException $e) {
            throw new RuntimeException("Verify FedaPay échoué pour {$transactionId}: " . $e->getMessage(), previous: $e);
        }

        $status    = strtolower((string) ($t['status'] ?? ''));
        $isSuccess = in_array($status, self::SUCCESS, true);

        return new PspVerification(
            transactionId:  (string) ($t['id'] ?? $transactionId),
            isSuccess:      $isSuccess,
            amountMinor:    (int) ($t['amount'] ?? 0),
            currency:       'XOF',
            method:         isset($t['mode']) ? (string) $t['mode'] : null,
            performedAt:    $t['approved_at'] ?? $t['updated_at'] ?? null,
            failureCode:    $isSuccess ? null : ($t['last_error_code'] ?? ($status ?: null)),
            failureMessage: null,
            raw:            $t,
        );
    }

    public function parseWebhook(Request $request): PspWebhookEvent
    {
        $this->assertSignature($request);

        $payload = json_decode($request->getContent(), true);
        if (! is_array($payload)) {
            throw new InvalidWebhookSignatureException('Corps du webhook FedaPay illisible.');
        }

        // Événement FedaPay : { name: "transaction.approved", entity: { …transaction… } }.
        $name   = (string) ($payload['name'] ?? $payload['type'] ?? '');
        $entity = $payload['entity'] ?? $payload['data'] ?? $payload['object'] ?? null;
        $entity = is_array($entity) ? $this->unwrap($entity) : [];
        $id     = (string) ($entity['id'] ?? '');

        $status = strtolower((string) ($entity['status'] ?? str_replace('transaction.', '', $name)));
        $claimed = match (true) {
            in_array($status, self::SUCCESS, true) => true,
            in_array($status, self::FAILURE, true) => false,
            default                                => null,
        };

        $meta = (array) ($entity['custom_metadata'] ?? []);

        return new PspWebhookEvent(
            transactionId:  $id,
            claimedSuccess: $claimed,
            paymentId:      isset($meta['payment_id']) ? (string) $meta['payment_id'] : null,
            idempotencyKey: isset($meta['idempotency_key']) ? (string) $meta['idempotency_key'] : null,
            method:         isset($entity['mode']) ? (string) $entity['mode'] : null,
            failureCode:    $claimed === false ? ($entity['last_error_code'] ?? $status) : null,
            eventName:      $name ?: null,
        );
    }

    /** Signature « t=…,s=… » : HMAC-SHA256("{t}.{corps brut}"), comparée en temps constant. */
    private function assertSignature(Request $request): void
    {
        $secret = (string) ($this->config['webhook_secret'] ?? '');
        $header = (string) $request->header('X-FEDAPAY-SIGNATURE', '');
        if ($secret === '' || $header === '') {
            throw new InvalidWebhookSignatureException('Signature webhook FedaPay absente.');
        }

        $timestamp  = null;
        $signatures = [];
        foreach (explode(',', $header) as $item) {
            [$k, $v] = array_pad(explode('=', trim($item), 2), 2, '');
            if ($k === 't') {
                $timestamp = $v;
            } elseif ($k === 's') {
                $signatures[] = $v;
            }
        }

        if (! ctype_digit((string) $timestamp) || $signatures === []) {
            throw new InvalidWebhookSignatureException('Signature webhook FedaPay mal formée.');
        }
        if (abs(time() - (int) $timestamp) > (int) ($this->config['webhook_tolerance'] ?? self::TOLERANCE_SECS)) {
            throw new InvalidWebhookSignatureException('Signature webhook FedaPay expirée.');
        }

        $expected = hash_hmac('sha256', $timestamp . '.' . $request->getContent(), $secret);
        foreach ($signatures as $sig) {
            if (hash_equals($expected, $sig)) {
                return;
            }
        }

        throw new InvalidWebhookSignatureException('Signature webhook FedaPay invalide.');
    }

    private function context(Payment $payment, string $transactionId, string $url): CheckoutContext
    {
        return new CheckoutContext(
            provider:      'fedapay',
            publicKey:     (string) ($this->config['public_key'] ?? ''),
            amountMinor:   $payment->amount_minor,
            currency:      'XOF',
            sandbox:       $this->isSandbox(),
            data: [
                'payment_id'      => $payment->id,
                'reference'       => $payment->reference,
                'idempotency_key' => $payment->idempotency_key,
            ],
            phone:         $payment->phone,
            callbackUrl:   $this->returnUrl($payment),
            reason:        "Cotisation Kafo — {$payment->reference}",
            mode:          'redirect',
            checkoutUrl:   $url,
            transactionId: $transactionId,
        );
    }

    /** Page de retour après paiement (app membre) ; le statut réel vient du webhook. */
    private function returnUrl(Payment $payment): ?string
    {
        $base = $this->config['return_url'] ?? null;
        if (! $base) {
            return null;
        }

        return $base . (str_contains($base, '?') ? '&' : '?') . 'payment=' . urlencode($payment->id);
    }

    private function customer(?string $fullName, ?string $phone): ?array
    {
        if (! $fullName && ! $phone) {
            return null;
        }
        $parts = preg_split('/\s+/', trim((string) $fullName), 2) ?: [];
        $digits = preg_replace('/\D/', '', (string) $phone);
        // FedaPay attend le numéro national, sans indicatif (+229).
        $national = $digits && str_starts_with($digits, '229') ? substr($digits, 3) : $digits;

        return array_filter([
            'firstname'    => $parts[0] ?? null,
            'lastname'     => $parts[1] ?? ($parts[0] ?? null),
            'phone_number' => $national ? ['number' => $national, 'country' => 'bj'] : null,
        ]);
    }

    /** Les réponses FedaPay peuvent être enveloppées (« v1/transaction » : {…}). */
    private function unwrap(mixed $json): array
    {
        if (! is_array($json)) {
            return [];
        }
        if (isset($json['id']) || isset($json['url']) || isset($json['token'])) {
            return $json;
        }
        foreach ($json as $key => $value) {
            if (is_array($value) && str_starts_with((string) $key, 'v1/')) {
                return $value;
            }
        }

        return $json;
    }

    private function http(): PendingRequest
    {
        $base = $this->isSandbox()
            ? ($this->config['sandbox_api_url'] ?? 'https://sandbox-api.fedapay.com/v1')
            : ($this->config['api_url'] ?? 'https://api.fedapay.com/v1');

        return Http::baseUrl(rtrim($base, '/'))
            ->timeout((int) ($this->config['timeout'] ?? 15))
            ->withToken((string) $this->config['secret_key'])
            ->acceptJson()
            ->asJson();
    }

    private function assertConfigured(): void
    {
        if (empty($this->config['secret_key'])) {
            throw new RuntimeException('FedaPay config manquante: secret_key');
        }
    }
}
