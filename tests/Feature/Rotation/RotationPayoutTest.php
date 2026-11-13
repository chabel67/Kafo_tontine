<?php

namespace Tests\Feature\Rotation;

use App\Modules\Audit\Infrastructure\Models\AuditLog;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Ledger\Infrastructure\Models\LedgerTransaction;
use App\Modules\Notifications\Infrastructure\Models\InAppNotification;
use App\Modules\Tontine\Infrastructure\Models\Installment;
use App\Modules\Tontine\Infrastructure\Models\RotationPayoutAttempt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\BuildsKafoFixtures;
use Tests\Support\BuildsRotationFixtures;
use Tests\TestCase;

/**
 * Lot 5 — distributions : validation (D-R3/D-R4, motifs), versement en deux temps sous step-up,
 * versant ≠ validateur (D-R9), caisse jamais dépassée, idempotence, échec et reprise, écriture unique.
 */
class RotationPayoutTest extends TestCase
{
    use RefreshDatabase, BuildsKafoFixtures, BuildsRotationFixtures;

    private User $manager;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        $this->manager = $this->staff('manager');
        $this->admin   = $this->staff('admin');
    }

    private function payWindowForAll(): void
    {
        foreach ($this->rotationMembers as $m) {
            $this->payRotationCash($m, 2000)->assertCreated();
        }
    }

    private function approve(array $reasons = []): \Illuminate\Testing\TestResponse
    {
        Sanctum::actingAs($this->manager);

        return $this->postJson("/api/admin/rotation-rounds/{$this->round(1)->id}/approve", $reasons);
    }

    private function initiate(?string $key = null, ?User $as = null): \Illuminate\Testing\TestResponse
    {
        $as ??= $this->admin;
        Sanctum::actingAs($as);

        return $this->postJson("/api/admin/rotation-rounds/{$this->round(1)->id}/payout/initiate", [
            'channel' => 'cash', 'idempotency_key' => $key ?? (string) Str::uuid(),
        ], $this->stepUpHeader($as));
    }

    private function confirm(): \Illuminate\Testing\TestResponse
    {
        Sanctum::actingAs($this->admin);

        return $this->postJson("/api/admin/rotation-rounds/{$this->round(1)->id}/payout/confirm", ['external_reference' => 'REMISE-001']);
    }

    private function poolKey(): string
    {
        return "ROTATING_POOL:{$this->rotation->id}";
    }

    public function test_full_distribution_with_a_single_ledger_entry(): void
    {
        $this->startedRotation();
        $this->payWindowForAll();
        Sanctum::actingAs($this->manager);
        $this->getJson("/api/admin/rotation-rounds/{$this->round(1)->id}/payout")->assertOk()
            ->assertJsonPath('data.status', 'ready')
            ->assertJsonPath('data.net_minor', 6000)
            ->assertJsonPath('data.overrides_required', []);

        $this->approve()->assertOk()->assertJsonPath('data.status', 'approved');

        // Validateur ≠ versant (D-R9).
        $this->initiate(null, $this->manager)->assertStatus(403);   // pas la permission de verser
        $before = LedgerTransaction::count();
        $this->initiate()->assertOk()->assertJsonPath('data.status', 'processing');
        $this->assertSame($before, LedgerTransaction::count(), 'aucune écriture au lancement');

        $this->confirm()->assertOk()->assertJsonPath('data.status', 'completed');
        $this->assertSame(0, $this->ledgerBalance($this->poolKey()));
        $this->assertSame($before + 1, LedgerTransaction::count());
        $round = $this->round(1);
        $this->assertSame(6000, $round->distributed_minor);
        $this->assertStringStartsWith('RND-', $round->reference);
        $this->assertTrue(InAppNotification::where('user_id', $this->rotationMembers[0]->user_id)->where('title', 'Cagnotte versée')->exists());

        // Le bénéficiaire reste tenu de ses 4 cotisations restantes.
        $this->assertSame(4, Installment::where('membership_id', $this->rotationMembers[0]->id)->where('status', '!=', 'paid')->count());
        $this->assertLedgerBalanced();
    }

    public function test_approver_cannot_disburse_their_own_approval(): void
    {
        $this->startedRotation();
        $this->payWindowForAll();
        Sanctum::actingAs($this->admin);
        $this->postJson("/api/admin/rotation-rounds/{$this->round(1)->id}/approve")->assertOk();

        $this->initiate()->assertStatus(422)->assertJsonPath('error.code', 'maker_checker_violation');
    }

    public function test_unpaid_contributions_need_a_reason_and_never_exceed_the_pool(): void
    {
        $this->startedRotation();
        // Membre 3 n'a rien payé : la caisse (4 000) ne couvre pas la cagnotte (6 000).
        $this->payRotationCash($this->rotationMembers[0], 2000)->assertCreated();
        $this->payRotationCash($this->rotationMembers[1], 2000)->assertCreated();
        $this->approve(['unpaid_reason' => 'Membre 3 malade, il paiera samedi.'])
            ->assertStatus(422)->assertJsonPath('error.code', 'rotation_round_blocked');

        // Des paiements d'avance des autres membres remplissent la caisse : le personnel peut décider.
        $this->payRotationCash($this->rotationMembers[1], 2000)->assertCreated();
        $this->approve()->assertStatus(422)->assertJsonValidationErrors(['unpaid_reason'], 'error.errors');
        $this->approve(['unpaid_reason' => 'Membre 3 malade, il paiera samedi.'])->assertOk();

        $this->assertSame('Membre 3 malade, il paiera samedi.', $this->round(1)->override_reasons['unpaid']['reason']);
        $this->assertSame(2000, AuditLog::where('action', 'rotation.round.approve')->sole()->new_values['unpaid_override']['missing_minor']);
    }

    public function test_beneficiary_in_arrears_needs_a_reason(): void
    {
        $this->startedRotation();
        $this->payWindowForAll();
        Installment::where('membership_id', $this->rotationMembers[0]->id)->where('sequence_number', 3)
            ->update(['due_date' => now()->subDay()->toDateString()]);

        $this->approve()->assertStatus(422)->assertJsonValidationErrors(['beneficiary_arrears_reason'], 'error.errors');
        $this->approve(['beneficiary_arrears_reason' => 'Accord du groupe en réunion du 12.'])->assertOk();
    }

    public function test_round_cannot_be_approved_before_its_date(): void
    {
        $this->startedRotation();
        $this->payWindowForAll();
        Sanctum::actingAs($this->manager);

        $this->postJson("/api/admin/rotation-rounds/{$this->round(2)->id}/approve")->assertStatus(422);
        $this->getJson("/api/admin/rotation-rounds/{$this->round(2)->id}/payout")->assertOk()
            ->assertJsonPath('data.status', 'collecting');
    }

    public function test_failure_then_retry_and_idempotent_initiation(): void
    {
        $this->startedRotation();
        $this->payWindowForAll();
        $this->approve()->assertOk();

        $key = (string) Str::uuid();
        $this->initiate($key)->assertOk();
        $this->initiate($key)->assertOk();   // rejeu : même tentative
        $this->assertSame(1, RotationPayoutAttempt::count());
        $this->initiate()->assertStatus(422)->assertJsonPath('error.code', 'invalid_round_transition');

        Sanctum::actingAs($this->admin);
        $this->postJson("/api/admin/rotation-rounds/{$this->round(1)->id}/payout/fail", ['reason' => 'Numéro MoMo injoignable.'])
            ->assertOk()->assertJsonPath('data.status', 'failed');
        $this->assertSame(2000 * 3, $this->ledgerBalance($this->poolKey()), 'un échec n\'écrit rien');

        $this->initiate()->assertOk();
        $this->confirm()->assertOk();
        $this->confirm()->assertStatus(422)->assertJsonPath('error.code', 'invalid_round_transition');

        $this->assertSame(1, LedgerTransaction::where('description', 'like', 'Cagnotte tontine%')->count(), 'jamais de double versement');
        $this->assertSame(2, RotationPayoutAttempt::count());
    }

    public function test_payout_deduction_goes_to_fee_income(): void
    {
        $this->startedRotation('payout_deduction', 500);
        $this->payWindowForAll();
        $this->approve()->assertOk();
        $this->initiate()->assertOk()->assertJsonPath('data.attempts.0.amount_minor', 5500);
        $this->confirm()->assertOk();

        $this->assertSame(500, $this->ledgerBalance('FEE_INCOME'));
        $this->assertSame(0, $this->ledgerBalance($this->poolKey()));
        $this->assertSame(-5500, $this->ledgerBalance('CASH_BOX') - 6000, 'caisse physique : +6 000 encaissés, −5 500 versés');
        $this->assertLedgerBalanced();
    }

    public function test_initiation_requires_step_up(): void
    {
        $this->startedRotation();
        $this->payWindowForAll();
        $this->approve()->assertOk();
        Sanctum::actingAs($this->admin);

        $this->postJson("/api/admin/rotation-rounds/{$this->round(1)->id}/payout/initiate", [
            'channel' => 'cash', 'idempotency_key' => (string) Str::uuid(),
        ])->assertStatus(403)->assertJsonPath('error.code', 'step_up_required');
    }
}
