<?php

namespace Tests\Feature\Payments;

use App\Modules\Ledger\Application\LedgerService;
use App\Modules\Ledger\Domain\Enums\AccountType;
use App\Modules\Payments\Infrastructure\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Support\BuildsKafoFixtures;
use Tests\TestCase;

/**
 * DA-20 — agrégateurs : FedaPay (transaction côté serveur, webhook HMAC), trésorerie
 * PSP_FLOAT:{AGRÉGATEUR}, transfert des anciens floats par réseau.
 */
class PspAggregatorsTest extends TestCase
{
    use RefreshDatabase, BuildsKafoFixtures;

    private const FED = 'https://sandbox-api.fedapay.com/v1';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        $this->configurePsps();
    }

    private function initiateFedapay(int $amount = 5000): array
    {
        $member     = $this->member();
        $membership = $this->membership($member);
        Sanctum::actingAs($member);

        return $this->postJson('/api/member/payments/initiate', [
            'membership_id' => $membership->id, 'channel' => 'fedapay', 'amount_minor' => $amount,
            'idempotency_key' => 'fed-key-0001',
        ])->assertCreated()->json('data');
    }

    private function signedWebhook(array $payload, ?string $secret = 'wh_sandbox_test', ?int $ts = null)
    {
        $body = json_encode($payload);
        $ts ??= time();
        $sig = hash_hmac('sha256', $ts . '.' . $body, (string) $secret);

        return $this->call('POST', '/api/webhooks/fedapay', [], [], [], [
            'CONTENT_TYPE'             => 'application/json',
            'HTTP_X_FEDAPAY_SIGNATURE' => "t={$ts},s={$sig}",
        ], $body);
    }

    private function fakeFedapay(string $status = 'approved', int $amount = 5000): void
    {
        Http::fake([
            self::FED . '/transactions/987/token' => Http::response(['token' => 'tok', 'url' => 'https://process.fedapay.com/tok']),
            self::FED . '/transactions/987'       => Http::response(['v1/transaction' => [
                'id' => 987, 'status' => $status, 'amount' => $amount, 'mode' => 'mtn_open',
            ]]),
            self::FED . '/transactions'           => Http::response(['v1/transaction' => ['id' => 987, 'status' => 'pending']], 201),
        ]);
    }

    public function test_fedapay_checkout_creates_server_side_transaction_once(): void
    {
        $this->fakeFedapay();
        $data = $this->initiateFedapay();

        $this->assertSame('redirect', $data['checkout']['mode']);
        $this->assertSame('https://process.fedapay.com/tok', $data['checkout']['checkout_url']);
        $this->assertSame('987', $data['checkout']['transaction_id']);

        Http::assertSent(fn ($r) => $r->url() === self::FED . '/transactions'
            && $r['amount'] === 5000 && $r['currency'] === ['iso' => 'XOF']
            && $r['custom_metadata']['payment_id'] === $data['id']
            && str_contains($r['callback_url'], 'payment=' . $data['id'])
            && $r->hasHeader('Authorization', 'Bearer sk_fed'));

        // Rejouer la même clé ne crée pas une seconde transaction FedaPay.
        $this->postJson('/api/member/payments/initiate', [
            'membership_id' => Payment::find($data['id'])->membership_id, 'channel' => 'fedapay',
            'amount_minor' => 5000, 'idempotency_key' => 'fed-key-0001',
        ])->assertOk()->assertJsonPath('data.checkout.checkout_url', 'https://process.fedapay.com/tok');
        Http::assertSentCount(2);
    }

    public function test_fedapay_webhook_confirms_and_books_aggregator_treasury(): void
    {
        $this->fakeFedapay();
        $data = $this->initiateFedapay();

        $event = ['name' => 'transaction.approved', 'entity' => [
            'id' => 987, 'status' => 'approved', 'amount' => 5000, 'mode' => 'mtn_open',
            'custom_metadata' => ['payment_id' => $data['id']],
        ]];
        $this->signedWebhook($event)->assertOk()->assertJsonPath('data.status', 'confirmed');
        // Rejeu : idempotent, une seule écriture.
        $this->signedWebhook($event)->assertOk()->assertJsonPath('data.status', 'confirmed');

        $payment = Payment::find($data['id']);
        $this->assertSame('confirmed', $payment->status->value);
        $this->assertSame('987', $payment->psp_reference);
        $this->assertSame('mtn', $payment->network);
        $this->assertSame(5000, app(LedgerService::class)->balance('PSP_FLOAT:FEDAPAY'));
    }

    public function test_fedapay_webhook_rejects_bad_signature_expired_and_amount_mismatch(): void
    {
        $this->fakeFedapay(amount: 4000);
        $data  = $this->initiateFedapay();
        $event = ['name' => 'transaction.approved', 'entity' => [
            'id' => 987, 'status' => 'approved', 'custom_metadata' => ['payment_id' => $data['id']],
        ]];

        $this->signedWebhook($event, secret: 'wrong')->assertStatus(401);
        $this->signedWebhook($event, ts: time() - 3600)->assertStatus(401);
        $this->postJson('/api/webhooks/fedapay', $event)->assertStatus(401);

        $this->signedWebhook($event)->assertStatus(409)->assertJsonPath('error.code', 'psp_amount_mismatch');
        $this->assertSame('pending', Payment::find($data['id'])->status->value);
    }

    public function test_fedapay_pending_event_is_acknowledged_without_effect_and_declined_fails(): void
    {
        $this->fakeFedapay(status: 'declined');
        $data = $this->initiateFedapay();

        $this->signedWebhook(['name' => 'transaction.created', 'entity' => ['id' => 987, 'status' => 'pending']])
            ->assertOk()->assertJsonPath('data.acknowledged', true);

        // Sans payment_id : retrouvé par la transaction mémorisée au checkout.
        $this->signedWebhook(['name' => 'transaction.declined', 'entity' => ['id' => 987, 'status' => 'declined']])
            ->assertOk()->assertJsonPath('data.status', 'failed');
        $this->assertSame('failed', Payment::find($data['id'])->status->value);
    }

    public function test_kkiapay_webhook_still_works_through_generic_handler(): void
    {
        $member     = $this->member();
        $membership = $this->membership($member);
        Sanctum::actingAs($member);
        $id = $this->postJson('/api/member/payments/initiate', [
            'membership_id' => $membership->id, 'channel' => 'kkiapay', 'amount_minor' => 3000,
        ])->assertCreated()->json('data.id');

        Http::fake(['*/api/v1/transactions/status' => Http::response([
            'transactionId' => 'KK-1', 'status' => 'SUCCESS', 'amount' => 3000, 'paymentMethod' => 'MOOV',
        ])]);

        $this->postJson('/api/webhooks/kkiapay', [
            'transactionId' => 'KK-1', 'isPaymentSucces' => true, 'stateData' => ['payment_id' => $id],
        ], ['x-kkiapay-secret' => 'nope'])->assertStatus(401);

        $this->postJson('/api/webhooks/kkiapay', [
            'transactionId' => 'KK-1', 'isPaymentSucces' => true, 'stateData' => json_encode(['payment_id' => $id]),
        ], ['x-kkiapay-secret' => 'kkiapay-whsec'])->assertOk()->assertJsonPath('data.status', 'confirmed');

        $this->assertSame('moov', Payment::find($id)->network);
        $this->assertSame(3000, app(LedgerService::class)->balance('PSP_FLOAT:KKIAPAY'));
    }

    public function test_migrate_psp_treasury_moves_legacy_float_balances_once(): void
    {
        $ledger = app(LedgerService::class);
        $ledger->openAccount('MOMO_FLOAT:MTN', AccountType::MomoFloat);
        $ledger->openAccount('MOMO_FLOAT:MOOV', AccountType::MomoFloat);
        $ledger->openAccount('MISC_REVENUE', AccountType::MiscRevenue);
        $ledger->post([
            ['account' => 'MOMO_FLOAT:MTN', 'type' => 'debit', 'amount' => 7000],
            ['account' => 'MISC_REVENUE', 'type' => 'credit', 'amount' => 7000],
        ], $ledger->nextReference(), 'seed');
        $ledger->post([
            ['account' => 'MISC_REVENUE', 'type' => 'debit', 'amount' => 2000],
            ['account' => 'MOMO_FLOAT:MOOV', 'type' => 'credit', 'amount' => 2000],
        ], $ledger->nextReference(), 'seed');

        $this->artisan('kafo:migrate-psp-treasury')->assertSuccessful();
        $this->assertSame(7000, $ledger->balance('MOMO_FLOAT:MTN'), 'aperçu : aucune écriture');

        $this->artisan('kafo:migrate-psp-treasury', ['--execute' => true])->assertSuccessful();
        $this->assertSame(0, $ledger->balance('MOMO_FLOAT:MTN'));
        $this->assertSame(0, $ledger->balance('MOMO_FLOAT:MOOV'));
        $this->assertSame(5000, $ledger->balance('PSP_FLOAT:KKIAPAY'));

        $count = \App\Modules\Ledger\Infrastructure\Models\LedgerTransaction::count();
        $this->artisan('kafo:migrate-psp-treasury', ['--execute' => true])->assertSuccessful();
        $this->assertSame($count, \App\Modules\Ledger\Infrastructure\Models\LedgerTransaction::count(), 'second passage sans effet');
    }
}
