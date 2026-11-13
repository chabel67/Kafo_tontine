<?php

namespace Tests\Feature\Rotation;

use App\Modules\Tontine\Application\InstallmentGeneratorService;
use App\Modules\Tontine\Infrastructure\Models\Campaign;
use App\Modules\Tontine\Infrastructure\Models\CampaignScheduleDate;
use App\Modules\Tontine\Infrastructure\Models\Installment;
use App\Modules\Tontine\Infrastructure\Models\MarketCalendar;
use App\Modules\Tontine\Infrastructure\Models\RotationRound;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsKafoFixtures;
use Tests\TestCase;

/**
 * Lot 2 — planification des tontines rotatives : fréquences, tours, cagnotte fixe (D-R1),
 * date de tour = dernière échéance (D-R2), frais (D-R5), confirmation figée.
 */
class RotationScheduleTest extends TestCase
{
    use RefreshDatabase, BuildsKafoFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        $this->actingAsStaff('super_admin');
    }

    private function create(array $overrides = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/admin/campaigns', [
            'name' => 'Tontine marché', 'campaign_type' => 'rotating', 'contribution_mode' => 'fixed',
            'contribution_amount_minor' => 1000, 'max_members' => 3, 'installments_per_round' => 7,
            'first_installment_date' => '2027-03-01',
            'contribution_schedule' => ['type' => 'every_n_days', 'n' => 1],
            ...$overrides,
        ]);
    }

    private function preview(string $id): array
    {
        return $this->getJson("/api/admin/campaigns/{$id}/rotation/schedule")->assertOk()->json('data');
    }

    private function roundDates(array $preview): array
    {
        return array_column($preview['rounds'], 'scheduled_date');
    }

    // ── Création ───────────────────────────────────────────────────────────

    public function test_rotating_campaign_derives_installments_and_never_allows_withdrawals(): void
    {
        $this->create()->assertCreated()
            ->assertJsonPath('data.campaign_type', 'rotating')
            ->assertJsonPath('data.duration_installments', 21)
            ->assertJsonPath('data.allow_early_withdrawals', false)
            ->assertJsonPath('data.rotation_fee_mode', 'none');
    }

    public function test_rotating_campaign_requires_fixed_contribution_and_valid_rule(): void
    {
        $this->create(['contribution_mode' => 'custom', 'contribution_amount_minor' => null, 'min_contribution_minor' => 500])
            ->assertStatus(422);
        $this->create(['contribution_schedule' => ['type' => 'weekly', 'weekday' => 'funday']])
            ->assertStatus(422)->assertJsonValidationErrors(['contribution_schedule.weekday'], 'error.errors');
        $this->create(['contribution_schedule' => ['type' => 'custom_dates', 'dates' => ['2027-03-01', '2027-03-01']]])
            ->assertStatus(422)->assertJsonValidationErrors(['contribution_schedule.dates'], 'error.errors');
        $this->create(['contribution_schedule' => ['type' => 'custom_dates', 'dates' => ['2027-02-30']]])
            ->assertStatus(422);
    }

    // ── Fréquences ────────────────────────────────────────────────────────

    public function test_daily_contributions_with_weekly_rounds(): void
    {
        $p = $this->preview($this->create()->json('data.id'));

        $this->assertTrue($p['valid']);
        $this->assertSame(21, $p['installments_count']);
        $this->assertSame(21000, $p['pot_minor'], '3 membres × 7 jours × 1 000');
        $this->assertSame(['2027-03-07', '2027-03-14', '2027-03-21'], $this->roundDates($p));
        $this->assertSame([1, 8, 15], array_column($p['rounds'], 'first_sequence'));
    }

    public function test_daily_contributions_across_a_leap_day(): void
    {
        $p = $this->preview($this->create([
            'max_members' => 2, 'installments_per_round' => 1, 'first_installment_date' => '2028-02-28',
        ])->json('data.id'));

        $this->assertSame(['2028-02-28', '2028-02-29'], array_column($p['dates'], 'due_date'));
    }

    public function test_weekly_every_two_weeks_on_saturday(): void
    {
        $p = $this->preview($this->create([
            'installments_per_round' => 1, 'first_installment_date' => '2027-03-01',   // lundi
            'contribution_schedule' => ['type' => 'weekly', 'weekday' => 'saturday', 'every_weeks' => 2],
        ])->json('data.id'));

        $this->assertSame(['2027-03-06', '2027-03-20', '2027-04-03'], array_column($p['dates'], 'due_date'));
    }

    public function test_weekly_contributions_with_four_week_rounds(): void
    {
        $p = $this->preview($this->create([
            'max_members' => 2, 'installments_per_round' => 4, 'first_installment_date' => '2027-03-01',
            'contribution_schedule' => ['type' => 'weekly', 'weekday' => 'monday'],
        ])->json('data.id'));

        $this->assertSame(['2027-03-22', '2027-04-19'], $this->roundDates($p));
        $this->assertSame(8000, $p['pot_minor']);
    }

    public function test_specific_weekdays(): void
    {
        $p = $this->preview($this->create([
            'max_members' => 2, 'installments_per_round' => 2, 'first_installment_date' => '2027-03-01',
            'contribution_schedule' => ['type' => 'named_weekdays', 'weekdays' => ['monday', 'wednesday', 'friday']],
        ])->json('data.id'));

        $this->assertSame(['2027-03-01', '2027-03-03', '2027-03-05', '2027-03-08'], array_column($p['dates'], 'due_date'));
    }

    public function test_monthly_on_the_31st(): void
    {
        $p = $this->preview($this->create([
            'max_members' => 2, 'installments_per_round' => 2, 'first_installment_date' => '2027-01-01',
            'contribution_schedule' => ['type' => 'monthly', 'day_of_month' => 31],
        ])->json('data.id'));

        $this->assertSame(['2027-01-31', '2027-02-28', '2027-03-31', '2027-04-30'], array_column($p['dates'], 'due_date'));
        $this->assertSame(['2027-02-28', '2027-04-30'], $this->roundDates($p));
    }

    public function test_market_days_reuse_an_existing_calendar(): void
    {
        $calendar = MarketCalendar::create(['name' => 'Dantokpa', 'cadence_type' => 'every_n_days', 'cadence_value' => ['n' => 5]]);
        $p = $this->preview($this->create([
            'max_members' => 2, 'installments_per_round' => 1, 'first_installment_date' => '2027-03-01',
            'contribution_schedule' => ['type' => 'market_calendar', 'market_calendar_id' => $calendar->id],
        ])->json('data.id'));

        $this->assertSame(['2027-03-01', '2027-03-06'], array_column($p['dates'], 'due_date'));
    }

    public function test_custom_dates_are_sorted_and_must_match_the_round_count(): void
    {
        $wrong = $this->preview($this->create([
            'max_members' => 2, 'installments_per_round' => 1, 'first_installment_date' => null,
            'contribution_schedule' => ['type' => 'custom_dates', 'dates' => ['2027-05-10']],
        ])->json('data.id'));
        $this->assertFalse($wrong['valid']);
        $this->assertStringContainsString('exactement 2 dates', $wrong['issues'][0]);

        $ok = $this->preview($this->create([
            'max_members' => 2, 'installments_per_round' => 1, 'first_installment_date' => null,
            'contribution_schedule' => ['type' => 'custom_dates', 'dates' => ['2027-06-01', '2027-05-10']],
        ])->json('data.id'));
        $this->assertTrue($ok['valid']);
        $this->assertSame(['2027-05-10', '2027-06-01'], $this->roundDates($ok));
    }

    // ── Frais (D-R5) ──────────────────────────────────────────────────────

    public function test_payout_deduction_and_contribution_surcharge(): void
    {
        $deduction = $this->preview($this->create(['rotation_fee_mode' => 'payout_deduction', 'rotation_fee_minor' => 500])->json('data.id'));
        $this->assertSame(20500, $deduction['rounds'][0]['net_minor']);
        $this->assertSame(1000, $deduction['amount_due_per_installment_minor']);

        $surcharge = $this->preview($this->create(['rotation_fee_mode' => 'contribution_surcharge', 'rotation_fee_minor' => 50])->json('data.id'));
        $this->assertSame(1050, $surcharge['amount_due_per_installment_minor']);
        $this->assertSame(21000, $surcharge['pot_minor'], 'le complément ne gonfle pas la cagnotte');
    }

    // ── Confirmation figée ────────────────────────────────────────────────

    public function test_confirmation_freezes_dates_and_rounds_and_gates_opening(): void
    {
        $id = $this->create()->json('data.id');

        $this->patchJson("/api/admin/campaigns/{$id}/status", ['status' => 'open'])
            ->assertStatus(422)->assertJsonPath('error.code', 'rotation_schedule_not_confirmed');

        $this->postJson("/api/admin/campaigns/{$id}/rotation/schedule/confirm")->assertOk();
        $this->assertSame(21, CampaignScheduleDate::where('campaign_id', $id)->count());
        $this->assertSame(3, RotationRound::where('campaign_id', $id)->count());

        // Reconfirmer en brouillon remplace sans doublon.
        $this->postJson("/api/admin/campaigns/{$id}/rotation/schedule/confirm")->assertOk();
        $this->assertSame(3, RotationRound::where('campaign_id', $id)->count());

        // Changer un paramètre en brouillon annule la confirmation.
        $this->patchJson("/api/admin/campaigns/{$id}", ['installments_per_round' => 2])->assertOk();
        $this->assertNull(Campaign::find($id)->schedule_confirmed_at);
        $this->assertSame(0, RotationRound::where('campaign_id', $id)->count());

        $this->postJson("/api/admin/campaigns/{$id}/rotation/schedule/confirm")->assertOk();
        $this->patchJson("/api/admin/campaigns/{$id}/status", ['status' => 'open'])->assertOk();

        // Ouverte : calendrier figé.
        $this->postJson("/api/admin/campaigns/{$id}/rotation/schedule/confirm")
            ->assertStatus(422)->assertJsonPath('error.code', 'rotation_schedule_locked');
    }

    public function test_installments_follow_the_confirmed_dates_and_isolate_the_surcharge(): void
    {
        $id = $this->create(['rotation_fee_mode' => 'contribution_surcharge', 'rotation_fee_minor' => 50])->json('data.id');
        $this->postJson("/api/admin/campaigns/{$id}/rotation/schedule/confirm")->assertOk();

        $campaign   = Campaign::find($id);
        $membership = $this->membership($this->member(), $campaign, ['committed_amount_minor' => 1000]);
        app(InstallmentGeneratorService::class)->generate($membership->load('campaign'));

        $installments = Installment::where('membership_id', $membership->id)->orderBy('sequence_number')->get();
        $this->assertCount(21, $installments);
        $this->assertSame('2027-03-01', $installments->first()->due_date->toDateString());
        $this->assertSame('2027-03-21', $installments->last()->due_date->toDateString());
        $this->assertSame(1050, $installments->first()->amount_minor);
        $this->assertSame(50, $installments->first()->fee_minor);
    }
}
