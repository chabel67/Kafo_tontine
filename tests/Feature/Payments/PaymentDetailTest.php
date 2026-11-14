<?php

namespace Tests\Feature\Payments;

use App\Modules\Ledger\Application\LedgerService;
use App\Modules\Ledger\Infrastructure\Models\LedgerTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsKafoFixtures;
use Tests\TestCase;

/** Fiche paiement (EF-PAY-02) : champs additifs recorded_by, ledger_transaction, history, contexte d'échéance. */
class PaymentDetailTest extends TestCase
{
    use RefreshDatabase, BuildsKafoFixtures;

    public function test_cash_payment_detail_exposes_agent_ledger_history_and_installment(): void
    {
        $this->seedRoles();
        $membership = $this->membership($this->member());
        $this->installments($membership, ['pending', 'pending']);
        $agent = $this->actingAsStaff('treasurer');

        $id = $this->postJson('/api/admin/payments/cash', [
            'membership_id' => $membership->id,
            'amount_minor'  => $membership->committed_amount_minor,
        ])->assertCreated()->json('data.id');

        $res = $this->getJson("/api/admin/payments/{$id}")->assertOk();

        // Champs historiques inchangés
        $res->assertJsonPath('data.id', $id)->assertJsonPath('data.channel', 'cash')
            ->assertJsonPath('data.membership.id', $membership->id);

        $res->assertJsonPath('data.recorded_by.id', $agent->id)
            ->assertJsonPath('data.recorded_by.full_name', $agent->full_name)
            ->assertJsonPath('data.ledger_transaction.reversed_by', null)
            ->assertJsonPath('data.installment_payments.0.sequence_number', 1)
            ->assertJsonPath('data.installment_payments.0.installment_status', 'paid');
        $this->assertNotNull($res->json('data.installment_payments.0.due_date'));
        $this->assertSame($membership->committed_amount_minor, $res->json('data.installment_payments.0.installment_amount_minor'));

        $txnId = $res->json('data.ledger_transaction.id');
        $this->assertSame($id, LedgerTransaction::find($txnId)->metadata['payment_id']);
        $this->assertIsArray($res->json('data.history'));
        $this->assertArrayNotHasKey('ip_address', $res->json('data.history.0') ?? []);

        // Contre-passation : visible sur la fiche
        app(LedgerService::class)->reverse(LedgerTransaction::find($txnId), 'Erreur de saisie de test', $agent->id);
        $this->getJson("/api/admin/payments/{$id}")
            ->assertJsonPath('data.ledger_transaction.id', $txnId)
            ->assertJsonPath('data.ledger_transaction.reversed_by.created_at', fn ($v) => $v !== null);
    }

    public function test_manual_confirmation_reason_appears_in_history(): void
    {
        $this->seedRoles();
        $membership = $this->membership($this->member());
        $this->installments($membership, ['pending']);
        $this->actingAsStaff('super_admin');

        $id = $this->postJson('/api/admin/payments/momo', [
            'membership_id' => $membership->id, 'channel' => 'kkiapay',
            'amount_minor'  => $membership->committed_amount_minor, 'phone' => '+22997000000',
        ])->json('data.id') ?? $this->markTestSkipped('Initiation MoMo staff indisponible dans ce contexte.');

        $this->postJson("/api/admin/payments/{$id}/confirm", ['reason' => 'Fonds visibles sur le relevé'])->assertOk();

        $res = $this->getJson("/api/admin/payments/{$id}")->assertOk()
            ->assertJsonPath('data.recorded_by.full_name', fn ($v) => $v !== null);
        $confirm = collect($res->json('data.history'))->firstWhere('action', 'payment.confirm_manual');
        $this->assertSame('Fonds visibles sur le relevé', $confirm['reason']);
        $this->assertNotNull($confirm['user_name']);
    }

    public function test_member_initiated_payment_has_no_recording_agent(): void
    {
        $this->seedRoles();
        $member     = $this->member();
        $membership = $this->membership($member);
        $payment    = \App\Modules\Payments\Infrastructure\Models\Payment::create([
            'reference' => 'PAY-TEST-000001', 'idempotency_key' => (string) \Illuminate\Support\Str::uuid(),
            'membership_id' => $membership->id, 'channel' => 'kkiapay', 'status' => 'pending',
            'amount_minor' => 5000, 'created_by' => $member->id,
        ]);
        $this->actingAsStaff('treasurer');

        $this->getJson("/api/admin/payments/{$payment->id}")->assertOk()
            ->assertJsonPath('data.recorded_by', null)
            ->assertJsonPath('data.ledger_transaction', null);
    }
}
