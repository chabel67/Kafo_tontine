<?php

namespace Tests\Feature\Campaigns;

use App\Modules\Audit\Infrastructure\Models\AuditLog;
use App\Modules\Ledger\Application\LedgerService;
use App\Modules\Payments\Infrastructure\Models\InstallmentPayment;
use App\Modules\Tontine\Infrastructure\Models\EarlyWithdrawal;
use App\Modules\Tontine\Infrastructure\Models\Membership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\BuildsKafoFixtures;
use Tests\TestCase;

/**
 * Configuration de campagne : retraits anticipés (R-WDR-08), frais d'adhésion (R-FEE-01 → 03),
 * modes de cotisation et paramètres contractuels figés (R-CAMP-02, R-MEMB-08).
 */
class CampaignConfigurationTest extends TestCase
{
    use RefreshDatabase, BuildsKafoFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    private function createPayload(array $extra = []): array
    {
        return [
            'name' => 'Campagne marché', 'contribution_mode' => 'fixed', 'contribution_amount_minor' => 5000,
            'duration_installments' => 6, 'first_installment_date' => now()->addDays(3)->toDateString(),
            ...$extra,
        ];
    }

    private function requestWithdrawal(Membership $m, int $amount = 1000)
    {
        Sanctum::actingAs($m->user);

        return $this->postJson('/api/member/early-withdrawals', [
            'membership_id' => $m->id, 'amount_minor' => $amount, 'payout_channel' => 'cash',
            'idempotency_key' => (string) Str::uuid(),
        ]);
    }

    // ── Création ───────────────────────────────────────────────────────────

    public function test_defaults_allow_withdrawals_and_no_membership_fee(): void
    {
        $this->actingAsStaff('super_admin');

        $this->postJson('/api/admin/campaigns', $this->createPayload())->assertCreated()
            ->assertJsonPath('data.allow_early_withdrawals', true)
            ->assertJsonPath('data.membership_fee_minor', 0);
    }

    public function test_campaign_can_disable_withdrawals_and_set_a_membership_fee(): void
    {
        $this->actingAsStaff('super_admin');

        $this->postJson('/api/admin/campaigns', $this->createPayload([
            'allow_early_withdrawals' => false, 'membership_fee_minor' => 2000,
        ]))->assertCreated()
            ->assertJsonPath('data.allow_early_withdrawals', false)
            ->assertJsonPath('data.membership_fee_minor', 2000);
    }

    // ── Retraits anticipés autorisés / interdits ──────────────────────────

    public function test_withdrawal_is_refused_server_side_when_campaign_disallows_it(): void
    {
        $m = $this->membership($this->member(), $this->campaign(['allow_early_withdrawals' => false]));
        $this->creditSavings($m, 10000);

        $this->requestWithdrawal($m)->assertStatus(422)->assertJsonPath('error.code', 'early_withdrawals_disabled');
        $this->assertSame(0, EarlyWithdrawal::count());
    }

    public function test_withdrawal_policy_change_is_reasoned_audited_and_not_retroactive(): void
    {
        $m = $this->membership($this->member());
        $this->creditSavings($m, 10000);
        $existing = $this->requestWithdrawal($m, 3000)->assertCreated()->json('data.id');

        $this->actingAsStaff('admin');
        $this->patchJson("/api/admin/campaigns/{$m->campaign_id}/withdrawal-policy", ['allow_early_withdrawals' => false])
            ->assertStatus(422);
        $this->patchJson("/api/admin/campaigns/{$m->campaign_id}/withdrawal-policy", [
            'allow_early_withdrawals' => false, 'reason' => 'Trésorerie tendue ce trimestre.',
        ])->assertOk()->assertJsonPath('data.allow_early_withdrawals', false);
        $this->assertTrue(AuditLog::where('action', 'campaign.withdrawal_policy.update')->exists());

        // Nouvelles demandes bloquées…
        $this->requestWithdrawal($m)->assertStatus(422)->assertJsonPath('error.code', 'early_withdrawals_disabled');
        // … la demande existante poursuit son cycle.
        $this->actingAsStaff('manager');
        $this->postJson("/api/admin/early-withdrawals/{$existing}/approve")->assertOk()->assertJsonPath('data.status', 'approved');
    }

    // ── Règles dures / douces (R-CAMP-02) ─────────────────────────────────

    public function test_hard_rules_are_refused_explicitly_once_campaign_is_open(): void
    {
        $campaign = $this->campaign(['status' => 'active']);
        $this->actingAsStaff('admin');

        $this->patchJson("/api/admin/campaigns/{$campaign->id}", ['contribution_amount_minor' => 9000])
            ->assertStatus(422)->assertJsonPath('error.code', 'campaign_rule_locked');
        $this->patchJson("/api/admin/campaigns/{$campaign->id}", ['membership_fee_minor' => 1000])
            ->assertStatus(422)->assertJsonPath('error.code', 'campaign_rule_locked');
        $this->patchJson("/api/admin/campaigns/{$campaign->id}", ['description' => 'Nouvelle description', 'grace_period_days' => 5])
            ->assertOk()->assertJsonPath('data.grace_period_days', 5);
    }

    // ── Modes de cotisation et contrat figé ───────────────────────────────

    public function test_fixed_and_custom_modes_set_the_contractual_amount(): void
    {
        $this->actingAsStaff('admin');
        $fixed  = $this->campaign(['status' => 'open', 'membership_fee_minor' => 2000]);
        $custom = $this->campaign([
            'status' => 'open', 'contribution_mode' => 'custom', 'contribution_amount_minor' => null,
            'min_contribution_minor' => 1000, 'max_contribution_minor' => 10000,
        ]);
        $user = $this->member();

        $this->postJson("/api/admin/memberships/campaigns/{$fixed->id}/enroll", ['user_id' => $user->id])
            ->assertCreated()->assertJsonPath('data.committed_amount_minor', 5000)
            ->assertJsonPath('data.membership_fee.due_minor', 2000);
        $this->postJson("/api/admin/memberships/campaigns/{$custom->id}/enroll", ['user_id' => $user->id, 'committed_amount' => 20000])
            ->assertStatus(422);
        $this->postJson("/api/admin/memberships/campaigns/{$custom->id}/enroll", ['user_id' => $user->id, 'committed_amount' => 7000])
            ->assertCreated()->assertJsonPath('data.committed_amount_minor', 7000);

        // Une modification ultérieure de la campagne ne touche pas le contrat existant.
        $fixed->update(['contribution_amount_minor' => 8000, 'membership_fee_minor' => 9000]);
        $m = Membership::where('campaign_id', $fixed->id)->sole();
        $this->assertSame(5000, $m->committed_amount_minor);
        $this->assertSame(2000, $m->membership_fee_minor);
    }

    // ── Frais d'adhésion (D3 : bloquants, encaissés en espèces) ──────────

    public function test_membership_fee_blocks_activation_until_paid_in_cash_and_never_becomes_savings(): void
    {
        $campaign = $this->campaign(['status' => 'open', 'membership_fee_minor' => 2000]);
        $this->actingAsStaff('admin');
        $mid = $this->postJson("/api/admin/memberships/campaigns/{$campaign->id}/enroll", ['user_id' => $this->member()->id])
            ->assertCreated()->json('data.id');

        $this->actingAsStaff('manager');
        $this->postJson("/api/admin/memberships/{$mid}/validate-l1")
            ->assertStatus(422)->assertJsonPath('error.code', 'membership_fee_unpaid');

        $this->actingAsStaff('treasurer');
        $this->postJson('/api/admin/payments/cash', ['membership_id' => $mid, 'amount_minor' => 1500, 'purpose' => 'membership_fee'])
            ->assertCreated()->assertJsonPath('data.purpose', 'membership_fee');
        $this->postJson('/api/admin/payments/cash', ['membership_id' => $mid, 'amount_minor' => 1000, 'purpose' => 'membership_fee'])
            ->assertStatus(422)->assertJsonPath('error.code', 'membership_fee_exceeds_due');

        $this->actingAsStaff('manager');
        $this->postJson("/api/admin/memberships/{$mid}/validate-l1")->assertStatus(422);

        $this->actingAsStaff('treasurer');
        $this->postJson('/api/admin/payments/cash', ['membership_id' => $mid, 'amount_minor' => 500, 'purpose' => 'membership_fee'])
            ->assertCreated();

        $this->actingAsStaff('manager');
        $this->postJson("/api/admin/memberships/{$mid}/validate-l1")->assertOk()
            ->assertJsonPath('data.membership_fee.remaining_minor', 0);

        $ledger = app(LedgerService::class);
        $this->assertSame(2000, $ledger->balance('FEE_INCOME'));
        $this->assertSame(0, $ledger->balance("MEMBER_SAVINGS:{$mid}"), 'les frais ne sont jamais de l\'épargne');
        $this->assertSame(0, InstallmentPayment::count(), 'les frais ne règlent aucune échéance');
        $this->assertLedgerBalanced();
    }

    public function test_campaign_without_fee_activates_directly(): void
    {
        $campaign = $this->campaign(['status' => 'open']);
        $this->actingAsStaff('admin');
        $mid = $this->postJson("/api/admin/memberships/campaigns/{$campaign->id}/enroll", ['user_id' => $this->member()->id])
            ->json('data.id');

        $this->actingAsStaff('manager');
        $this->postJson("/api/admin/memberships/{$mid}/validate-l1")->assertOk()->assertJsonPath('data.status', 'active');
    }
}
