<?php

namespace Tests\Feature\LotAbis;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\BuildsKafoFixtures;
use Tests\TestCase;

/**
 * Lot A-bis — point 3 (B-13) : routes Tontine, Payments, Notifications et Reporting
 * taguées `permission:*` selon la matrice RolesSeeder (inchangée).
 */
class AdminRoutePermissionsTest extends TestCase
{
    use RefreshDatabase, BuildsKafoFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    /** Lectures quotidiennes du trésorier : aucune ne doit être refusée. */
    public function test_treasurer_keeps_daily_screens(): void
    {
        $membership = $this->membership($this->member());
        $this->actingAsStaff('treasurer');

        foreach ([
            '/api/admin/stats/dashboard',
            '/api/admin/stats/work-queue',
            '/api/admin/stats/recent-payments',
            '/api/admin/stats/trends',
            '/api/admin/stats/members-by-campaign',
            '/api/admin/stats/collections-by-campaign',
            '/api/admin/stats/reconciliation',
            '/api/admin/payments',
            '/api/admin/members',
            '/api/admin/memberships',
            "/api/admin/memberships/{$membership->id}",
            "/api/admin/memberships/{$membership->id}/installments",
            '/api/admin/campaigns',
            "/api/admin/campaigns/{$membership->campaign_id}",
            '/api/admin/campaign-payouts',
            '/api/admin/cash/summary',
            '/api/admin/notifications',
            '/api/admin/notifications/count',
        ] as $uri) {
            $this->getJson($uri)->assertOk();
        }

        // Encaissement cash et clôture de caisse
        $this->postJson('/api/admin/payments/cash', ['membership_id' => $membership->id, 'amount_minor' => 5000])->assertCreated();
        $this->postJson('/api/admin/cash/close', ['physical_amount_minor' => 5000])->assertOk();
    }

    public function test_treasurer_is_refused_management_actions(): void
    {
        $campaign   = $this->campaign(['status' => 'draft']);
        $membership = $this->membership($this->member(), $this->campaign());
        $this->actingAsStaff('treasurer');

        $this->postJson('/api/admin/campaigns', [])->assertForbidden();
        $this->patchJson("/api/admin/campaigns/{$campaign->id}", ['name' => 'X'])->assertForbidden();
        $this->patchJson("/api/admin/campaigns/{$campaign->id}/status", ['status' => 'open', 'reason' => 'Ouverture prévue ce jour.'])->assertForbidden();
        $this->postJson("/api/admin/memberships/{$membership->id}/validate-l2")->assertForbidden();
        $this->postJson("/api/admin/memberships/{$membership->id}/suspend", ['reason' => 'Motif suffisamment long.'])->assertForbidden();
        $this->postJson('/api/admin/market-calendars', [])->assertForbidden();
        // Depuis le 2026-10-29, le trésorier a `campaign.view` : lecture des calendriers permise.
        $this->getJson('/api/admin/market-calendars')->assertOk();
        // Depuis le 2026-10-29, le trésorier a `reporting.view` : exports CSV permis, audit toujours refusé.
        foreach (['ledger', 'members', 'loans', 'journal'] as $export) {
            $this->get("/api/admin/exports/{$export}")->assertOk();
        }
        $this->getJson('/api/admin/exports/audit')->assertForbidden();
        $this->assertSame('draft', $campaign->fresh()->status->value);
    }

    public function test_member_token_is_refused_on_staff_routes(): void
    {
        $member = $this->member();
        $member->roles()->attach(\App\Modules\Identity\Infrastructure\Models\Role::where('name', 'member')->value('id'));
        Sanctum::actingAs($member);

        foreach (['/api/admin/campaigns', '/api/admin/payments', '/api/admin/memberships', '/api/admin/notifications', '/api/admin/stats/dashboard'] as $uri) {
            $this->getJson($uri)->assertForbidden()->assertJsonPath('error.code', 'unauthorized');
        }
    }

    public function test_admin_has_management_and_exports_but_not_super_admin_transitions(): void
    {
        $campaign = $this->campaign(['status' => 'draft']);
        $this->actingAsStaff('admin');

        $this->getJson('/api/admin/market-calendars')->assertOk();
        $this->patchJson("/api/admin/campaigns/{$campaign->id}", ['name' => 'Campagne renommée'])->assertOk();
        $this->getJson('/api/admin/exports/members')->assertOk();
        $this->getJson('/api/admin/stats/dashboard')->assertOk();

        // R-CAMP-01 : transitions réservées au super-admin (`campaign.transition`).
        $this->patchJson("/api/admin/campaigns/{$campaign->id}/status", ['status' => 'open', 'reason' => 'Ouverture prévue ce jour.'])->assertForbidden();
    }

    public function test_manager_passes_the_validate_l2_permission_and_reads_campaigns(): void
    {
        $membership = $this->membership($this->member(), null, ['status' => 'pending_l2']);
        $this->actingAsStaff('manager');

        // Au-delà de la permission, le résultat dépend des règles métier : seul le 403 est exclu.
        $this->assertNotSame(403, $this->postJson("/api/admin/memberships/{$membership->id}/validate-l2")->status());
        $this->getJson('/api/admin/campaigns')->assertOk();
        $this->getJson('/api/admin/market-calendars')->assertOk();
    }
}
