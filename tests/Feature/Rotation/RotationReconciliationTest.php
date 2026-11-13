<?php

namespace Tests\Feature\Rotation;

use App\Modules\Ledger\Application\LedgerService;
use App\Modules\Ledger\Domain\Enums\AccountType;
use App\Modules\Tontine\Application\RotationReconciliationService;
use App\Modules\Tontine\Infrastructure\Models\Installment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\BuildsKafoFixtures;
use Tests\Support\BuildsRotationFixtures;
use Tests\TestCase;

/** Lot 6 — rapprochement de la caisse commune, journal, libellés, diagnostic. */
class RotationReconciliationTest extends TestCase
{
    use RefreshDatabase, BuildsKafoFixtures, BuildsRotationFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    /** Cotisations (avec complément et pénalité), puis cagnotte du tour 1 versée. */
    private function scenario(): void
    {
        $this->startedRotation('contribution_surcharge', 50);
        Installment::where('membership_id', $this->rotationMembers[0]->id)->where('sequence_number', 1)
            ->update(['penalty_minor' => 200, 'penalty_reason' => 'Retard constaté au marché.']);

        $this->payRotationCash($this->rotationMembers[0], 2300)->assertCreated();  // 200 + 2 × 1 050
        $this->payRotationCash($this->rotationMembers[1], 2100)->assertCreated();
        $this->payRotationCash($this->rotationMembers[2], 3000)->assertCreated();  // 900 d'avance, restent en caisse

        $manager = $this->staff('manager');
        $admin   = $this->staff('admin');
        Sanctum::actingAs($manager);
        $this->postJson("/api/admin/rotation-rounds/{$this->round(1)->id}/approve")->assertOk();
        Sanctum::actingAs($admin);
        $this->postJson("/api/admin/rotation-rounds/{$this->round(1)->id}/payout/initiate",
            ['channel' => 'cash', 'idempotency_key' => (string) Str::uuid()], $this->stepUpHeader($admin))->assertOk();
        $this->postJson("/api/admin/rotation-rounds/{$this->round(1)->id}/payout/confirm")->assertOk();
    }

    public function test_pool_reconciles_with_operations(): void
    {
        $this->scenario();
        $this->actingAsStaff('admin');

        $r = $this->getJson("/api/admin/campaigns/{$this->rotation->id}/rotation/reconciliation")->assertOk()->json('data');

        $this->assertTrue($r['balanced'], implode(' | ', $r['anomalies']));
        $this->assertSame(0, $r['difference_minor']);
        $this->assertSame(7400, $r['detail']['payments_minor']);
        $this->assertSame(200, $r['detail']['penalties_minor']);
        $this->assertSame(350, $r['detail']['fees_minor'], '7 échéances touchées × 50 : le complément passe avant la cotisation (D-R10 → D-R5 → caisse)');
        $this->assertSame(6000, $r['detail']['rounds_paid_out_minor']);
        $this->assertSame(850, $r['expected_minor'], '7 400 − 200 − 350 − 6 000');
        $this->assertLedgerBalanced();
    }

    public function test_a_stray_ledger_entry_is_detected(): void
    {
        $this->scenario();
        $ledger = app(LedgerService::class);
        $ledger->openAccount('MISC_REVENUE', AccountType::MiscRevenue);
        $ledger->post(
            legs: [
                ['account' => "ROTATING_POOL:{$this->rotation->id}", 'type' => 'debit', 'amount' => 100],
                ['account' => 'MISC_REVENUE', 'type' => 'credit', 'amount' => 100],
            ],
            reference: $ledger->nextReference(),
            description: 'Écriture parasite (test)',
        );

        $r = app(RotationReconciliationService::class)->reconcile($this->rotation);
        $this->assertFalse($r['balanced']);
        $this->assertSame(-100, $r['difference_minor']);
        $this->assertStringContainsString('Écart de caisse', $r['anomalies'][0]);
    }

    public function test_journal_and_account_labels_name_the_rotation_operations(): void
    {
        $this->scenario();
        $this->actingAsStaff('admin');

        $types = collect($this->getJson('/api/admin/ledger/journal')->assertOk()->json('data'))->pluck('operation_type');
        $this->assertContains('rotation_contribution', $types);
        $this->assertContains('rotation_payout', $types);

        $accounts = collect($this->getJson('/api/admin/ledger/accounts')->assertOk()->json('data'));
        $pool     = $accounts->firstWhere('key', "ROTATING_POOL:{$this->rotation->id}");
        $this->assertSame("Caisse tontine — {$this->rotation->name}", $pool['account_label']);
    }

    public function test_diagnostic_reports_the_rotating_pools(): void
    {
        $this->scenario();

        $this->artisan('kafo:diagnose-ledger')
            ->expectsOutputToContain("{$this->rotation->name} : comptable 850, attendu 850, écart 0")
            ->assertSuccessful();
    }
}
