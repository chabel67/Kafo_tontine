<?php

namespace Tests\Feature\Rotation;

use App\Modules\Audit\Infrastructure\Models\AuditLog;
use App\Modules\Notifications\Infrastructure\Models\InAppNotification;
use App\Modules\Tontine\Application\RotationScheduleService;
use App\Modules\Tontine\Infrastructure\Models\Campaign;
use App\Modules\Tontine\Infrastructure\Models\Membership;
use App\Modules\Tontine\Infrastructure\Models\RotationRound;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsKafoFixtures;
use Tests\TestCase;

/** Lot 3 — groupe fermé, attribution des tours (manuelle / tirage, D-R6), démarrage. */
class RotationAssignmentTest extends TestCase
{
    use RefreshDatabase, BuildsKafoFixtures;

    private Campaign $campaign;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();

        $campaign = $this->campaign([
            'status' => 'draft', 'campaign_type' => 'rotating', 'max_members' => 3, 'installments_per_round' => 2,
            'contribution_amount_minor' => 1000, 'duration_installments' => 6, 'first_installment_date' => '2027-03-01',
            'contribution_schedule' => ['type' => 'every_n_days', 'n' => 7], 'allow_early_withdrawals' => false,
        ]);
        app(RotationScheduleService::class)->confirm($campaign);
        $campaign->update(['status' => 'open']);
        $this->campaign = $campaign->fresh();

        $this->actingAsStaff('admin');
    }

    private function members(int $n, string $status = 'active'): array
    {
        return collect(range(1, $n))
            ->map(fn () => $this->membership($this->member(), $this->campaign, ['status' => $status, 'committed_amount_minor' => 1000])->id)
            ->all();
    }

    private function url(string $suffix): string
    {
        return "/api/admin/campaigns/{$this->campaign->id}/rotation/{$suffix}";
    }

    public function test_assignment_requires_a_complete_group_and_a_valid_order(): void
    {
        $ids = $this->members(2);
        $this->postJson($this->url('assign'), ['method' => 'manual', 'order' => $ids])
            ->assertStatus(422)->assertJsonPath('error.code', 'rotation_group_incomplete');

        $ids[] = $this->members(1)[0];
        $this->postJson($this->url('assign'), ['method' => 'manual', 'order' => [$ids[0], $ids[0], $ids[1]]])
            ->assertStatus(422)->assertJsonPath('error.code', 'rotation_invalid_order');

        $this->postJson($this->url('assign'), ['method' => 'manual', 'order' => [$ids[2], $ids[0], $ids[1]]])->assertOk();
        $this->assertSame([$ids[2], $ids[0], $ids[1]],
            RotationRound::where('campaign_id', $this->campaign->id)->orderBy('round_number')->pluck('beneficiary_membership_id')->all());
        $this->assertTrue(AuditLog::where('action', 'campaign.rotation.rounds_assigned')->exists());
    }

    public function test_draw_is_reproducible_traced_and_checked(): void
    {
        $this->members(3);
        $proposal = $this->postJson($this->url('draw'))->assertOk()->json('data');
        $order    = array_column($proposal['order'], 'membership_id');
        $this->assertCount(3, $order);

        // Ordre falsifié avec la graine du tirage : refusé.
        $this->postJson($this->url('assign'), ['method' => 'draw', 'seed' => $proposal['seed'], 'order' => array_reverse($order)])
            ->assertStatus(422)->assertJsonPath('error.code', 'rotation_draw_mismatch');

        $this->postJson($this->url('assign'), ['method' => 'draw', 'seed' => $proposal['seed'], 'order' => $order])->assertOk();
        $audit = AuditLog::where('action', 'campaign.rotation.rounds_assigned')->sole();
        $this->assertSame($proposal['seed'], $audit->new_values['seed']);
        $this->assertSame('draw', $audit->new_values['method']);
    }

    public function test_start_checks_the_group_and_freezes_the_order(): void
    {
        $ids = $this->members(3);
        $this->actingAsStaff('super_admin');   // démarrer = transition de statut (R-CAMP-01)

        $this->postJson($this->url('start'))->assertStatus(422)->assertJsonPath('error.code', 'rotation_rounds_unassigned');

        $this->postJson($this->url('assign'), ['method' => 'manual', 'order' => $ids])->assertOk();
        $this->postJson($this->url('start'))->assertOk()->assertJsonPath('data.status', 'active');

        $this->assertNotNull($this->campaign->fresh()->started_at);
        $this->assertSame('collecting', RotationRound::where('campaign_id', $this->campaign->id)->where('round_number', 1)->value('status')->value);
        $this->assertSame(3, InAppNotification::where('type', 'rotation.round_assigned')->count());

        // Ordre figé, groupe fermé.
        $this->postJson($this->url('assign'), ['method' => 'manual', 'order' => array_reverse($ids)])
            ->assertStatus(422)->assertJsonPath('error.code', 'rotation_order_locked');
        $this->postJson("/api/admin/memberships/campaigns/{$this->campaign->id}/enroll", ['user_id' => $this->member()->id])
            ->assertStatus(422)->assertJsonPath('error.code', 'rotation_group_closed');
    }

    public function test_pending_memberships_block_the_start(): void
    {
        $ids = $this->members(3);
        $this->postJson($this->url('assign'), ['method' => 'manual', 'order' => $ids])->assertOk();
        Membership::find($ids[0])->update(['status' => 'pending_l2']);
        $this->actingAsStaff('super_admin');

        $this->postJson($this->url('start'))->assertStatus(422)->assertJsonPath('error.code', 'rotation_group_incomplete');
    }

    public function test_status_transition_to_active_goes_through_the_start_checks(): void
    {
        $this->members(3);
        $this->actingAsStaff('super_admin');

        $this->patchJson("/api/admin/campaigns/{$this->campaign->id}/status", ['status' => 'active', 'reason' => 'Démarrage de la tontine.'])
            ->assertStatus(422)->assertJsonPath('error.code', 'rotation_rounds_unassigned');
        $this->assertSame('open', $this->campaign->fresh()->status->value);
    }

    public function test_enrollment_is_open_before_the_start(): void
    {
        $this->postJson("/api/admin/memberships/campaigns/{$this->campaign->id}/enroll", ['user_id' => $this->member()->id])
            ->assertCreated()->assertJsonPath('data.committed_amount_minor', 1000);
    }
}
