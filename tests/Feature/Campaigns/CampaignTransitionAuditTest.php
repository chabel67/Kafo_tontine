<?php

namespace Tests\Feature\Campaigns;

use App\Modules\Audit\Infrastructure\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsKafoFixtures;
use Tests\TestCase;

/**
 * Lot A — R-CAMP-04 / R-AUTH-05 : toute transition de campagne est auditée avec
 * son motif ; motif ≥ 10 caractères obligatoire pour open → active.
 * Lot A-bis : la clôture ne passe plus par PATCH /status mais par POST /close (step-up).
 */
class CampaignTransitionAuditTest extends TestCase
{
    use RefreshDatabase, BuildsKafoFixtures;

    private \App\Modules\Identity\Infrastructure\Models\User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        $this->actor = $this->actingAsStaff('super_admin');
    }

    public function test_draft_to_open_is_audited_with_optional_reason(): void
    {
        $campaign = $this->campaign(['status' => 'draft']);

        $this->patchJson("/api/admin/campaigns/{$campaign->id}/status", ['status' => 'open'])
            ->assertOk()->assertJsonPath('data.status', 'open');

        $audit = AuditLog::where('action', 'campaign.status_changed')->where('entity_id', $campaign->id)->firstOrFail();
        $this->assertSame('draft', $audit->old_values['status']);
        $this->assertSame('open', $audit->new_values['status']);
        $this->assertNull($audit->new_values['reason']);
    }

    public function test_open_to_active_requires_a_reason_and_records_it(): void
    {
        $campaign = $this->campaign(['status' => 'open']);

        $this->patchJson("/api/admin/campaigns/{$campaign->id}/status", ['status' => 'active'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_error')
            ->assertJsonValidationErrors(['reason'], 'error.errors');

        $this->patchJson("/api/admin/campaigns/{$campaign->id}/status", ['status' => 'active', 'reason' => 'court'])
            ->assertStatus(422);

        $this->assertSame('open', $campaign->fresh()->status->value, 'aucune transition sans motif');
        $this->assertSame(0, AuditLog::where('action', 'campaign.status_changed')->count());

        $reason = 'Toutes les adhésions prévues sont validées.';
        $this->patchJson("/api/admin/campaigns/{$campaign->id}/status", ['status' => 'active', 'reason' => $reason])
            ->assertOk()->assertJsonPath('data.status', 'active');

        $audit = AuditLog::where('action', 'campaign.status_changed')->where('entity_id', $campaign->id)->firstOrFail();
        $this->assertSame('open', $audit->old_values['status']);
        $this->assertSame('active', $audit->new_values['status']);
        $this->assertSame($reason, $audit->new_values['reason']);
    }

    /**
     * Lot A-bis (R-CAMP-08, R-AUTH-03) : la clôture via PATCH contournait le step-up.
     * Elle est refusée ; POST /close (step-up + motif) reste la seule porte d'entrée.
     */
    public function test_patch_to_closed_is_refused_and_redirects_to_close_endpoint(): void
    {
        $campaign = $this->campaign(['status' => 'active']);

        $this->patchJson("/api/admin/campaigns/{$campaign->id}/status", ['status' => 'closed', 'reason' => 'Fin du cycle de cotisation annuel.'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'campaign_close_endpoint_required')
            ->assertJsonPath('error.details.method', 'POST');

        $this->assertSame('active', $campaign->fresh()->status->value);
        $this->assertSame(0, AuditLog::where('entity_id', $campaign->id)->count());
    }

    public function test_close_endpoint_requires_step_up_and_a_reason_and_is_audited(): void
    {
        $actor    = $this->actor;
        $campaign = $this->campaign(['status' => 'active']);

        $this->postJson("/api/admin/campaigns/{$campaign->id}/close", ['reason' => 'Fin du cycle de cotisation annuel.'])
            ->assertStatus(403)->assertJsonPath('error.code', 'step_up_required');

        $this->postJson("/api/admin/campaigns/{$campaign->id}/close", ['reason' => 'court'], $this->stepUpHeader($actor))
            ->assertStatus(422);

        $this->postJson("/api/admin/campaigns/{$campaign->id}/close", ['reason' => 'Fin du cycle de cotisation annuel.'], $this->stepUpHeader($actor))
            ->assertOk()->assertJsonPath('data.campaign_id', $campaign->id);

        $this->assertSame('closed', $campaign->fresh()->status->value);
        $this->assertDatabaseHas('audit_logs', ['action' => 'campaign.close', 'entity_id' => $campaign->id]);
    }

    public function test_invalid_transition_message_is_french_and_code_unchanged(): void
    {
        $campaign = $this->campaign(['status' => 'draft']);

        $this->patchJson("/api/admin/campaigns/{$campaign->id}/status", ['status' => 'active', 'reason' => 'Démarrage anticipé demandé.'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'invalid_status_transition')
            ->assertJsonPath('error.message', "Transition de campagne impossible : 'draft' → 'active'.");
    }
}
