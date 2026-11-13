<?php

namespace Tests\Feature\Rotation;

use App\Modules\Tontine\Domain\Enums\CampaignType;
use App\Modules\Tontine\Domain\Enums\RotationRoundStatus as S;
use App\Modules\Tontine\Infrastructure\Models\CampaignScheduleDate;
use App\Modules\Tontine\Infrastructure\Models\RotationPayoutAttempt;
use App\Modules\Tontine\Infrastructure\Models\RotationRound;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\BuildsKafoFixtures;
use Tests\TestCase;

/** Lot 1 — modèle de données des campagnes rotatives : défauts, contraintes, machine d'état. */
class RotationModelTest extends TestCase
{
    use RefreshDatabase, BuildsKafoFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    private function round(array $attrs): RotationRound
    {
        return RotationRound::create([
            'round_number' => 1, 'first_sequence' => 1, 'last_sequence' => 2,
            'scheduled_date' => now()->toDateString(), 'expected_minor' => 10000, ...$attrs,
        ]);
    }

    public function test_existing_and_new_campaigns_default_to_savings(): void
    {
        $campaign = $this->campaign()->fresh();
        $this->assertSame(CampaignType::Savings, $campaign->campaign_type);
        $this->assertFalse($campaign->isRotating());

        $this->actingAsStaff('admin');
        $this->getJson("/api/admin/campaigns/{$campaign->id}")->assertOk()
            ->assertJsonPath('data.campaign_type', 'savings')
            ->assertJsonPath('data.rotation_fee_mode', 'none');
    }

    public function test_campaign_type_is_constrained(): void
    {
        $this->expectException(QueryException::class);
        \DB::table('tontine_campaigns')->where('id', $this->campaign()->id)->update(['campaign_type' => 'lottery']);
    }

    public function test_a_member_cannot_benefit_twice_in_the_same_campaign(): void
    {
        $campaign = $this->campaign(['campaign_type' => 'rotating']);
        $m = $this->membership($this->member(), $campaign);
        $this->round(['campaign_id' => $campaign->id, 'beneficiary_membership_id' => $m->id]);

        $this->expectException(QueryException::class);
        $this->round(['campaign_id' => $campaign->id, 'round_number' => 2, 'first_sequence' => 3, 'last_sequence' => 4, 'beneficiary_membership_id' => $m->id]);
    }

    public function test_round_numbers_and_schedule_dates_are_unique_per_campaign(): void
    {
        $campaign = $this->campaign(['campaign_type' => 'rotating']);
        $this->round(['campaign_id' => $campaign->id]);
        CampaignScheduleDate::create(['campaign_id' => $campaign->id, 'sequence' => 1, 'due_date' => '2027-01-01']);

        try {
            $this->round(['campaign_id' => $campaign->id]);
            $this->fail('numéro de tour en double accepté');
        } catch (QueryException) {}

        $this->expectException(QueryException::class);
        CampaignScheduleDate::create(['campaign_id' => $campaign->id, 'sequence' => 2, 'due_date' => '2027-01-01']);
    }

    public function test_only_one_payout_attempt_in_progress_per_round(): void
    {
        $campaign = $this->campaign(['campaign_type' => 'rotating']);
        $m     = $this->membership($this->member(), $campaign);
        $round = $this->round(['campaign_id' => $campaign->id, 'beneficiary_membership_id' => $m->id]);
        $staff = $this->staff('admin');
        $attempt = fn (int $no) => RotationPayoutAttempt::create([
            'kind' => 'round', 'campaign_id' => $campaign->id, 'membership_id' => $m->id, 'rotation_round_id' => $round->id,
            'attempt_no' => $no, 'idempotency_key' => (string) Str::uuid(), 'amount_minor' => 10000, 'channel' => 'cash',
            'initiated_by' => $staff->id, 'initiated_at' => now(),
        ]);
        $attempt(1);

        $this->expectException(QueryException::class);
        $attempt(2);
    }

    public function test_round_state_machine(): void
    {
        $this->assertTrue(S::Scheduled->canTransitionTo(S::Collecting));
        $this->assertTrue(S::Blocked->canTransitionTo(S::Approved), 'D-R3 : le personnel peut valider malgré les impayés');
        $this->assertTrue(S::Failed->canTransitionTo(S::Processing));
        $this->assertFalse(S::Completed->canTransitionTo(S::Processing), 'un tour versé est définitif');
        $this->assertFalse(S::Scheduled->canTransitionTo(S::Processing));
        $this->assertTrue(S::Completed->isLocked());
        $this->assertFalse(S::Ready->isLocked());
    }
}
