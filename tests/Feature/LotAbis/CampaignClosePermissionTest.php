<?php

namespace Tests\Feature\LotAbis;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsKafoFixtures;
use Tests\TestCase;

/**
 * Décision produit du 2026-10-29 : la clôture de campagne (POST /close, step-up) est ouverte
 * à l'admin et au trésorier (`campaign.close`). Ouverture et activation restent réservées au
 * super-admin (`campaign.transition`, R-CAMP-01).
 */
class CampaignClosePermissionTest extends TestCase
{
    use RefreshDatabase, BuildsKafoFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    public function test_admin_and_treasurer_can_close_a_campaign_with_step_up(): void
    {
        foreach (['admin', 'treasurer'] as $role) {
            $actor    = $this->actingAsStaff($role);
            $campaign = $this->campaign(['status' => 'active']);

            $this->getJson("/api/admin/campaigns/{$campaign->id}/close/preview")->assertOk();

            $this->postJson("/api/admin/campaigns/{$campaign->id}/close", ['reason' => 'Fin du cycle de cotisation annuel.'])
                ->assertStatus(403)->assertJsonPath('error.code', 'step_up_required');

            $this->postJson("/api/admin/campaigns/{$campaign->id}/close", ['reason' => 'Fin du cycle de cotisation annuel.'], $this->stepUpHeader($actor))
                ->assertOk();

            $this->assertSame('closed', $campaign->fresh()->status->value, $role);
        }
    }

    public function test_other_transitions_stay_reserved_to_super_admin(): void
    {
        foreach (['admin', 'treasurer'] as $role) {
            $this->actingAsStaff($role);
            $campaign = $this->campaign(['status' => 'draft']);

            $this->patchJson("/api/admin/campaigns/{$campaign->id}/status", ['status' => 'open', 'reason' => 'Ouverture prévue ce jour.'])
                ->assertForbidden();
            $this->assertSame('draft', $campaign->fresh()->status->value, $role);
        }
    }

    public function test_manager_still_cannot_close(): void
    {
        $actor    = $this->actingAsStaff('manager');
        $campaign = $this->campaign(['status' => 'active']);

        $this->postJson("/api/admin/campaigns/{$campaign->id}/close", ['reason' => 'Fin du cycle de cotisation annuel.'], $this->stepUpHeader($actor))
            ->assertForbidden();
        $this->assertSame('active', $campaign->fresh()->status->value);
    }

    public function test_treasurer_reads_campaigns_through_campaign_view(): void
    {
        $this->actingAsStaff('treasurer');
        $this->getJson('/api/admin/campaigns')->assertOk();
    }
}
