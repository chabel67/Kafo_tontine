<?php

namespace Tests\Feature\Documents;

use App\Modules\Audit\Infrastructure\Models\AuditLog;
use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Ledger\Infrastructure\Models\LedgerTransaction;
use App\Modules\Lending\Application\LoanAttestationService;
use App\Modules\Lending\Infrastructure\Models\Loan;
use App\Modules\Tontine\Application\WithdrawalAttestationService;
use App\Modules\Tontine\Infrastructure\Models\EarlyWithdrawal;
use App\Modules\Tontine\Infrastructure\Models\Membership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\BuildsKafoFixtures;
use Tests\TestCase;

/** R-DOC-01 : attestations PDF de prêt décaissé et de retrait anticipé versé. */
class AttestationTest extends TestCase
{
    use RefreshDatabase, BuildsKafoFixtures;

    private User $owner;
    private Membership $membership;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        $this->owner = $this->member(['full_name' => 'Awa Dossou', 'phone' => '+2290197001234']);
        $this->owner->roles()->attach(Role::where('name', 'member')->value('id'));
        $this->membership = $this->membership($this->owner);
        $this->creditSavings($this->membership, 50000);
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    private function disbursedWithdrawal(int $amount = 8000): EarlyWithdrawal
    {
        $this->actingAsStaff('manager');
        $id = $this->postJson('/api/admin/early-withdrawals', [
            'membership_id' => $this->membership->id, 'amount_minor' => $amount, 'reason' => 'Frais de scolarité',
            'payout_channel' => 'cash', 'idempotency_key' => (string) Str::uuid(),
        ])->assertCreated()->json('data.id');
        $this->postJson("/api/admin/early-withdrawals/{$id}/approve")->assertOk();

        $admin = $this->staff('admin');
        Sanctum::actingAs($admin);
        $this->postJson("/api/admin/early-withdrawals/{$id}/payout/initiate", [
            'channel' => 'kkiapay', 'network' => 'mtn', 'phone' => '+2290197000099', 'idempotency_key' => (string) Str::uuid(),
        ], $this->stepUpHeader($admin))->assertOk();
        $this->postJson("/api/admin/early-withdrawals/{$id}/payout/confirm", ['external_reference' => 'MTN-TX-778899'])->assertOk();

        return EarlyWithdrawal::findOrFail($id);
    }

    private function assertPdf($response, string $reference): void
    {
        $response->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Content-Disposition', "attachment; filename=\"attestation-{$reference}.pdf\"");
        $this->assertStringStartsWith('%PDF', $response->getContent());
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    // ── Prêts ──────────────────────────────────────────────────────────────

    public function test_staff_downloads_the_attestation_of_a_disbursed_advance(): void
    {
        $loan = $this->advance($this->membership, 10000);
        $this->actingAsStaff('treasurer');

        $this->getJson("/api/admin/loans/{$loan->id}")->assertJsonPath('data.attestation_available', true);
        $this->assertPdf($this->get("/api/admin/loans/{$loan->id}/attestation"), $loan->reference);

        $this->assertTrue(AuditLog::where('action', 'loan.attestation.download')->where('entity_id', $loan->id)->exists());
    }

    public function test_advance_attestation_content(): void
    {
        $loan = $this->advance($this->membership, 10000);
        $html = app(LoanAttestationService::class)->html($loan);

        $this->assertStringContainsString("Attestation d'octroi de prêt", html_entity_decode($html, ENT_QUOTES));
        $this->assertStringContainsString($loan->reference, $html);
        $this->assertStringContainsString('Awa Dossou', $html);
        $this->assertStringContainsString("10\u{00A0}000\u{00A0}FCFA", $html);
        $this->assertStringContainsString('Avance sur épargne', $html);
        $this->assertStringContainsString('règlement de clôture', html_entity_decode($html, ENT_QUOTES));
        $this->assertStringNotContainsString('Échéancier de remboursement', $html);
        // Numéro masqué : jamais en clair sur un document qui circule.
        $this->assertStringNotContainsString('+2290197001234', $html);
        $this->assertStringContainsString('1234', $html);
        // Transaction de décaissement (le prêt ne stocke pas son identifiant).
        $txn = LedgerTransaction::whereRaw("metadata ->> 'loan_id' = ?", [$loan->id])->value('reference');
        $this->assertStringContainsString($txn, $html);
        $this->assertStringContainsString('Le bénéficiaire', $html);
    }

    public function test_standard_loan_attestation_includes_schedule_and_organization(): void
    {
        DB::table('system_settings')->where('key', 'org_name')->update(['value' => 'Tontine Solidaire']);
        DB::table('system_settings')->where('key', 'org_address')->update(['value' => 'Cotonou, Akpakpa']);

        $loan = $this->standardLoan($this->owner, 30000, 500, 3);
        $html = html_entity_decode(app(LoanAttestationService::class)->html($loan), ENT_QUOTES);

        $this->assertStringContainsString('Prêt communautaire', $html);
        $this->assertStringContainsString('Échéancier de remboursement', $html);
        $this->assertSame(3, substr_count($html, '<td>' . '1</td>') + substr_count($html, '<td>2</td>') + substr_count($html, '<td>3</td>'));
        $this->assertStringContainsString('Tontine Solidaire', $html);
        $this->assertStringContainsString('Cotonou, Akpakpa', $html);
        $this->assertStringContainsString('Pour Tontine Solidaire', $html);
        $this->assertStringContainsString('5 %', $html);
    }

    public function test_member_downloads_only_their_own_loan_attestation(): void
    {
        $loan = $this->advance($this->membership, 10000);

        Sanctum::actingAs($this->owner);
        $this->getJson('/api/member/loans')->assertJsonPath('data.0.attestation_available', true);
        $this->assertPdf($this->get("/api/member/loans/{$loan->id}/attestation"), $loan->reference);

        Sanctum::actingAs($this->member());
        $this->get("/api/member/loans/{$loan->id}/attestation")->assertNotFound();
    }

    public function test_attestation_is_refused_before_disbursement(): void
    {
        $loan = $this->advance($this->membership, 10000);
        Loan::whereKey($loan->id)->update(['disbursed_at' => null]);
        $this->actingAsStaff('treasurer');

        $this->getJson("/api/admin/loans/{$loan->id}/attestation")
            ->assertStatus(422)->assertJsonPath('error.code', 'attestation_unavailable');
    }

    public function test_member_cannot_use_the_staff_route(): void
    {
        $loan = $this->advance($this->membership, 10000);
        Sanctum::actingAs($this->owner);

        $this->getJson("/api/admin/loans/{$loan->id}/attestation")->assertForbidden();
    }

    // ── Retraits anticipés ─────────────────────────────────────────────────

    public function test_withdrawal_attestation_after_confirmed_payout(): void
    {
        $w = $this->disbursedWithdrawal(8000);
        $this->actingAsStaff('treasurer');

        $this->getJson("/api/admin/early-withdrawals/{$w->id}")->assertJsonPath('data.attestation_available', true);
        $this->assertPdf($this->get("/api/admin/early-withdrawals/{$w->id}/attestation"), $w->reference);
        $this->assertTrue(AuditLog::where('action', 'early_withdrawal.attestation.download')->where('entity_id', $w->id)->exists());

        $html = html_entity_decode(app(WithdrawalAttestationService::class)->html($w->fresh()), ENT_QUOTES);
        $this->assertStringContainsString('Attestation de retrait anticipé', $html);
        $this->assertStringContainsString("8\u{00A0}000\u{00A0}FCFA", $html);
        $this->assertStringContainsString('KKiaPay · MTN MoMo', $html);
        $this->assertStringContainsString('MTN-TX-778899', $html);
        $this->assertStringContainsString(LedgerTransaction::whereKey($w->fresh()->ledger_transaction_id)->value('reference'), $html);
        $this->assertStringNotContainsString('+2290197000099', $html);
    }

    public function test_withdrawal_attestation_is_refused_until_paid_and_private_to_owner(): void
    {
        $this->actingAsStaff('manager');
        $id = $this->postJson('/api/admin/early-withdrawals', [
            'membership_id' => $this->membership->id, 'amount_minor' => 5000, 'reason' => 'Frais de santé',
            'payout_channel' => 'cash', 'idempotency_key' => (string) Str::uuid(),
        ])->assertCreated()->json('data.id');

        $this->getJson("/api/admin/early-withdrawals/{$id}")->assertJsonPath('data.attestation_available', false);
        $this->getJson("/api/admin/early-withdrawals/{$id}/attestation")
            ->assertStatus(422)->assertJsonPath('error.code', 'attestation_unavailable');

        $w = $this->disbursedWithdrawal(6000);
        Sanctum::actingAs($this->owner);
        $this->assertPdf($this->get("/api/member/early-withdrawals/{$w->id}/attestation"), $w->reference);

        Sanctum::actingAs($this->member());
        $this->get("/api/member/early-withdrawals/{$w->id}/attestation")->assertNotFound();
    }

    // ── Paramètres Organisation ────────────────────────────────────────────

    public function test_organization_settings_are_editable_and_text_can_be_cleared(): void
    {
        $admin = $this->staff('super_admin');
        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/settings')->assertJsonPath('data.organization.0.group', 'organization');

        $this->patchJson('/api/admin/settings', ['settings' => [
            ['key' => 'org_name', 'value' => 'Kafo Bénin'],
            ['key' => 'org_address', 'value' => ''],
        ]], $this->stepUpHeader($admin))->assertOk();
        $this->assertSame('Kafo Bénin', DB::table('system_settings')->where('key', 'org_name')->value('value'));
        $this->assertSame('', DB::table('system_settings')->where('key', 'org_address')->value('value'));

        // Un paramètre numérique reste obligatoire.
        $this->patchJson('/api/admin/settings', ['settings' => [
            ['key' => 'min_trust_score', 'value' => ''],
        ]], $this->stepUpHeader($admin))->assertStatus(422);
    }
}
