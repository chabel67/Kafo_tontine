<?php

namespace Tests\Feature\Payments;

use App\Modules\Audit\Infrastructure\Models\AuditLog;
use App\Modules\Payments\Infrastructure\Models\Payment;
use App\Modules\Tontine\Infrastructure\Models\Membership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Support\BuildsKafoFixtures;
use Tests\TestCase;

/**
 * Lot A — encaissement cash :
 *  - R-PAY-08 raisonné sur le jour local métier (Africa/Porto-Novo, UTC+1) ;
 *  - R-PAY-05 montant atypique (`atypical_amount`, `confirm_atypical`) ;
 *  - motif audité sur confirmation / annulation manuelles.
 */
class CashPaymentRulesTest extends TestCase
{
    use RefreshDatabase, BuildsKafoFixtures;

    private Membership $membership;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        $this->membership = $this->membership($this->member()); // committed = 5000
        $this->actingAsStaff('treasurer');
    }

    private function cash(int $amount, array $extra = [])
    {
        return $this->postJson('/api/admin/payments/cash', [
            'membership_id' => $this->membership->id,
            'amount_minor'  => $amount,
            ...$extra,
        ]);
    }

    // ── Fuseau métier (R-PAY-08) ────────────────────────────────────────────

    public function test_business_timezone_is_a_valid_iana_zone(): void
    {
        $this->assertSame('Africa/Porto-Novo', config('kafo.business_timezone'));
        $this->assertSame('UTC', config('app.timezone'), 'le stockage reste en UTC');
        $this->assertSame('2026-10-28', Carbon::parse('2026-10-27 23:30:00', 'UTC')->setTimezone('Africa/Porto-Novo')->toDateString());
    }

    public function test_cash_at_2330_utc_belongs_to_the_next_local_day(): void
    {
        // 27/10 20:00 UTC = 27/10 21:00 à Cotonou
        $this->travelTo(Carbon::parse('2026-10-27 20:00:00', 'UTC'));
        $this->cash(5000)->assertCreated();

        // 27/10 23:30 UTC = 28/10 00:30 à Cotonou : nouveau jour local → pas de doublon
        $this->travelTo(Carbon::parse('2026-10-27 23:30:00', 'UTC'));
        $this->cash(5000)->assertCreated();

        // 28/10 10:00 UTC : même jour local que l'encaissement de 23:30 UTC → doublon
        $this->travelTo(Carbon::parse('2026-10-28 10:00:00', 'UTC'));
        $this->cash(5000)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'duplicate_cash_payment_today');
    }

    public function test_duplicate_detection_still_works_within_the_same_local_day(): void
    {
        // 28/10 23:10 local (22:10 UTC) puis 28/10 23:50 local (22:50 UTC)
        $this->travelTo(Carbon::parse('2026-10-28 22:10:00', 'UTC'));
        $this->cash(5000)->assertCreated();

        $this->travelTo(Carbon::parse('2026-10-28 22:50:00', 'UTC'));
        $this->cash(5000)->assertStatus(409)
            ->assertJsonPath('error.details.existing_payment.amount_minor', 5000);

        $this->cash(5000, ['force' => true])->assertCreated();
        $this->assertDatabaseHas('audit_logs', ['action' => 'payment.cash.duplicate_bypass']);
    }

    // ── Montant atypique (R-PAY-05) ─────────────────────────────────────────

    public function test_multiples_of_the_committed_amount_are_accepted(): void
    {
        $this->cash(15000)->assertCreated();
        $this->assertSame(0, AuditLog::where('action', 'payment.cash.atypical_confirmed')->count());
    }

    public function test_atypical_amount_requires_explicit_confirmation(): void
    {
        $this->cash(7000)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'atypical_amount')
            ->assertJsonPath('error.details.expected_amount_minor', 5000)
            ->assertJsonPath('error.details.amount_minor', 7000)
            ->assertJsonPath('error.details.lower_multiple_minor', 5000)
            ->assertJsonPath('error.details.upper_multiple_minor', 10000);

        $this->assertSame(0, Payment::count(), 'aucun paiement sans confirmation');

        $id = $this->cash(7000, ['confirm_atypical' => true])->assertCreated()->json('data.id');

        $audit = AuditLog::where('action', 'payment.cash.atypical_confirmed')->firstOrFail();
        $this->assertSame($id, $audit->entity_id);
        $this->assertSame(5000, $audit->new_values['expected_amount_minor']);
        $this->assertSame(7000, $audit->new_values['amount_minor']);
    }

    public function test_amount_below_the_commitment_is_atypical(): void
    {
        $this->cash(2500)->assertStatus(422)
            ->assertJsonPath('error.code', 'atypical_amount')
            ->assertJsonPath('error.details.lower_multiple_minor', null);
    }

    public function test_force_and_confirm_atypical_are_independent(): void
    {
        $this->cash(5000)->assertCreated();

        // Doublon ET atypique : `force` seul ne suffit pas (le contrôle atypique passe en premier).
        $this->cash(7000, ['force' => true])->assertStatus(422)->assertJsonPath('error.code', 'atypical_amount');
        // `confirm_atypical` seul ne contourne pas le doublon.
        $this->cash(7000, ['confirm_atypical' => true])->assertStatus(409)->assertJsonPath('error.code', 'duplicate_cash_payment_today');
        // Les deux confirmations → accepté, deux audits.
        $this->cash(7000, ['force' => true, 'confirm_atypical' => true])->assertCreated();

        $this->assertDatabaseHas('audit_logs', ['action' => 'payment.cash.duplicate_bypass']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'payment.cash.atypical_confirmed']);
    }

    // ── Confirmation / annulation manuelles ─────────────────────────────────

    private function pendingMomo(): Payment
    {
        return Payment::create([
            'reference'       => 'PAY-TEST-' . Str::random(6),
            'idempotency_key' => (string) Str::uuid(),
            'membership_id'   => $this->membership->id,
            'channel'         => 'kkiapay',
            'status'          => 'pending',
            'amount_minor'    => 5000,
            'phone'           => '+2290197000077',
        ]);
    }

    public function test_manual_confirm_and_cancel_audit_the_optional_reason(): void
    {
        $toConfirm = $this->pendingMomo();
        $this->postJson("/api/admin/payments/{$toConfirm->id}/confirm", ['reason' => 'Reçu opérateur vérifié'])
            ->assertOk()->assertJsonPath('data.status', 'confirmed');

        $audit = AuditLog::where('action', 'payment.confirm_manual')->where('entity_id', $toConfirm->id)->firstOrFail();
        $this->assertSame('pending', $audit->old_values['status']);
        $this->assertSame('Reçu opérateur vérifié', $audit->new_values['reason']);

        // B-13 (lot A-bis) : l'annulation exige `payment.cancel` (gestionnaire, admin) — pas le trésorier.
        $toCancel = $this->pendingMomo();
        $this->postJson("/api/admin/payments/{$toCancel->id}/cancel")->assertForbidden();
        $this->actingAsStaff('manager');
        $this->postJson("/api/admin/payments/{$toCancel->id}/cancel")->assertOk();
        $audit = AuditLog::where('action', 'payment.cancel')->where('entity_id', $toCancel->id)->firstOrFail();
        $this->assertNull($audit->new_values['reason']);

        $this->postJson("/api/admin/payments/{$toCancel->id}/cancel", ['reason' => str_repeat('x', 501)])
            ->assertStatus(422)->assertJsonPath('error.code', 'validation_error');
    }
}
