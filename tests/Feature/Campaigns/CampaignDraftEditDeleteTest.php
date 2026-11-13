<?php

namespace Tests\Feature\Campaigns;

use App\Modules\Audit\Infrastructure\Models\AuditLog;
use App\Modules\Tontine\Infrastructure\Models\Campaign;
use App\Modules\Tontine\Infrastructure\Models\CampaignScheduleDate;
use App\Modules\Tontine\Infrastructure\Models\RotationRound;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsKafoFixtures;
use Tests\TestCase;

/** Brouillon : modification complète (R-CAMP-02, R-CAMP-06/07) et suppression définitive auditée. */
class CampaignDraftEditDeleteTest extends TestCase
{
    use RefreshDatabase, BuildsKafoFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    private function draft(array $attributes = []): Campaign
    {
        return $this->campaign(['status' => 'draft', 'first_installment_date' => now()->addDays(5)->toDateString(), ...$attributes]);
    }

    // ── Modification ───────────────────────────────────────────────────────

    public function test_draft_switches_from_fixed_to_custom_and_back(): void
    {
        $this->actingAsStaff('admin');
        $c = $this->draft();

        $this->patchJson("/api/admin/campaigns/{$c->id}", [
            'contribution_mode' => 'custom', 'min_contribution_minor' => 1000, 'max_contribution_minor' => 9000,
            'min_trust_score' => 50,
        ])->assertOk()
            ->assertJsonPath('data.contribution_mode', 'custom')
            ->assertJsonPath('data.contribution_amount_minor', null)
            ->assertJsonPath('data.min_contribution_minor', 1000);
        $this->assertSame(50, $c->fresh()->min_trust_score);

        $this->patchJson("/api/admin/campaigns/{$c->id}", ['contribution_mode' => 'fixed'])
            ->assertStatus(422)->assertJsonValidationErrors(['contribution_amount_minor'], 'error.errors');
        $this->patchJson("/api/admin/campaigns/{$c->id}", ['contribution_mode' => 'fixed', 'contribution_amount_minor' => 3000])
            ->assertOk()
            ->assertJsonPath('data.contribution_amount_minor', 3000)
            ->assertJsonPath('data.min_contribution_minor', null);
    }

    public function test_custom_bounds_must_be_ordered(): void
    {
        $this->actingAsStaff('admin');
        $c = $this->draft(['contribution_mode' => 'custom', 'contribution_amount_minor' => null]);

        $this->patchJson("/api/admin/campaigns/{$c->id}", ['min_contribution_minor' => 5000, 'max_contribution_minor' => 5000])
            ->assertStatus(422)->assertJsonValidationErrors(['max_contribution_minor'], 'error.errors');
    }

    public function test_rotating_draft_stays_fixed(): void
    {
        $this->actingAsStaff('admin');
        $c = $this->draft(['campaign_type' => 'rotating', 'max_members' => 3, 'installments_per_round' => 1,
            'contribution_schedule' => ['type' => 'every_n_days', 'n' => 1], 'duration_installments' => 3]);

        $this->patchJson("/api/admin/campaigns/{$c->id}", ['contribution_mode' => 'custom'])
            ->assertStatus(422)->assertJsonValidationErrors(['contribution_mode'], 'error.errors');
    }

    public function test_open_campaign_keeps_hard_rules_locked_but_accepts_unchanged_values(): void
    {
        $this->actingAsStaff('admin');
        $c = $this->campaign(['status' => 'open']);

        $this->patchJson("/api/admin/campaigns/{$c->id}", ['contribution_mode' => 'fixed', 'grace_period_days' => 5])->assertOk();
        $this->patchJson("/api/admin/campaigns/{$c->id}", ['contribution_mode' => 'custom'])
            ->assertStatus(422)->assertJsonPath('error.code', 'campaign_rule_locked');
    }

    // ── Suppression ────────────────────────────────────────────────────────

    public function test_draft_is_deleted_with_a_reason_and_audited(): void
    {
        $this->actingAsStaff('super_admin');
        $c = $this->draft();

        $this->deleteJson("/api/admin/campaigns/{$c->id}", ['reason' => 'court'])
            ->assertStatus(422)->assertJsonValidationErrors(['reason'], 'error.errors');

        $this->deleteJson("/api/admin/campaigns/{$c->id}", ['reason' => 'Créée en double par erreur.'])
            ->assertOk()->assertJsonPath('data.deleted', true);

        $this->assertNull(Campaign::find($c->id));
        $log = AuditLog::where('action', 'campaign.delete')->sole();
        $this->assertSame($c->name, $log->old_values['name']);
        $this->assertSame('Créée en double par erreur.', $log->new_values['reason']);
        $this->getJson("/api/admin/campaigns/{$c->id}")->assertNotFound();
    }

    public function test_rotating_draft_with_confirmed_schedule_is_deleted(): void
    {
        $this->actingAsStaff('super_admin');
        $id = $this->postJson('/api/admin/campaigns', [
            'name' => 'Tontine test', 'campaign_type' => 'rotating', 'contribution_mode' => 'fixed',
            'contribution_amount_minor' => 1000, 'max_members' => 3, 'installments_per_round' => 1,
            'first_installment_date' => now()->addDays(3)->toDateString(),
            'contribution_schedule' => ['type' => 'every_n_days', 'n' => 1],
        ])->assertCreated()->json('data.id');
        $this->postJson("/api/admin/campaigns/{$id}/rotation/schedule/confirm")->assertOk();

        $this->deleteJson("/api/admin/campaigns/{$id}", ['reason' => 'Paramètres à revoir entièrement.'])->assertOk();

        $this->assertSame(0, RotationRound::where('campaign_id', $id)->count());
        $this->assertSame(0, CampaignScheduleDate::where('campaign_id', $id)->count());
    }

    public function test_only_drafts_can_be_deleted(): void
    {
        $this->actingAsStaff('super_admin');
        $open = $this->campaign(['status' => 'open']);

        $this->deleteJson("/api/admin/campaigns/{$open->id}", ['reason' => 'Je veux la supprimer.'])
            ->assertStatus(422)->assertJsonPath('error.code', 'campaign_not_deletable');
        $this->assertNotNull(Campaign::find($open->id));
    }

    public function test_deletion_requires_campaign_create(): void
    {
        $this->actingAsStaff('treasurer');
        $c = $this->draft();

        $this->deleteJson("/api/admin/campaigns/{$c->id}", ['reason' => 'Créée en double par erreur.'])->assertForbidden();
        $this->assertNotNull(Campaign::find($c->id));
    }
}
