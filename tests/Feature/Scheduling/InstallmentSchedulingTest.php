<?php

namespace Tests\Feature\Scheduling;

use App\Modules\Notifications\Infrastructure\Models\InAppNotification;
use App\Modules\Tontine\Infrastructure\Models\Installment;
use App\Modules\Tontine\Infrastructure\Models\MarketCalendar;
use App\Modules\Tontine\Jobs\MarkLateInstallmentsJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsKafoFixtures;
use Tests\TestCase;

/**
 * Lot 0 des campagnes rotatives (profite aussi à l'épargne) :
 * calcul mensuel en fin de mois et détection quotidienne des retards (B-03).
 */
class InstallmentSchedulingTest extends TestCase
{
    use RefreshDatabase, BuildsKafoFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    private function previewDates(int $dayOfMonth, string $first, int $count): array
    {
        $calendar = MarketCalendar::create([
            'name' => "Mensuel {$dayOfMonth}", 'cadence_type' => 'monthly', 'cadence_value' => ['day_of_month' => $dayOfMonth],
        ]);
        $campaign = $this->campaign([
            'status' => 'draft', 'market_calendar_id' => $calendar->id,
            'first_installment_date' => $first, 'duration_installments' => $count,
        ]);
        $this->actingAsStaff('admin');

        return array_column(
            $this->getJson("/api/admin/campaigns/{$campaign->id}/preview")->assertOk()->json('data.installments'),
            'due_date',
        );
    }

    public function test_monthly_day_31_falls_back_to_the_last_day_of_shorter_months(): void
    {
        $this->assertSame(
            ['2027-01-31', '2027-02-28', '2027-03-31', '2027-04-30'],
            $this->previewDates(31, '2027-01-15', 4),
        );
    }

    public function test_monthly_day_30_in_a_leap_year_february(): void
    {
        $this->assertSame(['2028-02-29', '2028-03-30'], $this->previewDates(30, '2028-02-01', 2));
    }

    public function test_monthly_first_date_is_never_before_the_campaign_start(): void
    {
        $this->assertSame(['2027-02-05', '2027-03-05'], $this->previewDates(5, '2027-01-20', 2));
    }

    public function test_late_installments_are_marked_once_and_the_member_is_notified(): void
    {
        $owner  = $this->member();
        $active = $this->membership($owner);
        $this->installments($active, ['pending', 'paid', 'pending']);
        // Échéance 1 : grâce dépassée ; échéance 3 : encore dans sa grâce.
        Installment::where('membership_id', $active->id)->where('sequence_number', 1)
            ->update(['grace_period_ends_date' => now()->subDays(2)->toDateString()]);
        Installment::where('membership_id', $active->id)->where('sequence_number', 3)
            ->update(['due_date' => now()->subDay()->toDateString(), 'grace_period_ends_date' => now()->addDays(2)->toDateString()]);

        $closed = $this->membership($this->member(), null, ['status' => 'closed']);
        $this->installments($closed, ['pending']);
        Installment::where('membership_id', $closed->id)->update(['grace_period_ends_date' => now()->subDays(5)->toDateString()]);

        app()->call([new MarkLateInstallmentsJob(), 'handle']);

        $statuses = Installment::where('membership_id', $active->id)->orderBy('sequence_number')->pluck('status')->map->value->all();
        $this->assertSame(['late', 'paid', 'pending'], $statuses);
        $this->assertNotNull(Installment::where('membership_id', $active->id)->where('sequence_number', 1)->value('late_marked_at'));
        $this->assertSame('pending', Installment::where('membership_id', $closed->id)->value('status')->value, 'adhésion clôturée non suivie');
        $this->assertSame(1, InAppNotification::where('user_id', $owner->id)->where('type', 'installment.late')->count());

        // Idempotent : rien de nouveau au second passage.
        app()->call([new MarkLateInstallmentsJob(), 'handle']);
        $this->assertSame(1, InAppNotification::where('user_id', $owner->id)->where('type', 'installment.late')->count());
    }
}
