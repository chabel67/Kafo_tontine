<?php

namespace Tests\Feature\Campaigns;

use App\Modules\Payments\Infrastructure\Models\Payment;
use App\Modules\Tontine\Infrastructure\Models\Installment;
use App\Modules\Tontine\Infrastructure\Models\Membership;
use App\Shared\BusinessTime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\BuildsKafoFixtures;
use Tests\TestCase;

/** EF-CAMP-05 — suivi des cotisations : qui a payé, qui n'a pas encore payé. */
class ContributionTrackingTest extends TestCase
{
    use RefreshDatabase, BuildsKafoFixtures;

    private string $past;
    private string $today;
    private string $future;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        $this->today  = BusinessTime::todayDate();
        $this->past   = BusinessTime::today()->subDays(10)->toDateString();
        $this->future = BusinessTime::today()->addDays(10)->toDateString();
    }

    /** Trois échéances (passé, aujourd'hui, futur) par adhésion, statuts donnés pour les deux premières. */
    private function schedule(Membership $m, string $first, string $second): array
    {
        $out = [];
        foreach ([[$this->past, $first], [$this->today, $second], [$this->future, 'pending']] as $n => [$date, $status]) {
            $out[] = Installment::create([
                'membership_id' => $m->id, 'campaign_id' => $m->campaign_id, 'sequence_number' => $n + 1,
                'due_date' => $date, 'grace_period_ends_date' => $date, 'amount_minor' => 5000,
                'status' => $status, 'paid_at' => $status === 'paid' ? now() : null,
                'late_marked_at' => $status === 'late' ? now() : null,
                'waived_at' => $status === 'waived' ? now() : null,
            ]);
        }

        return $out;
    }

    private function allocate(Installment $i, int $amount): void
    {
        $p = Payment::create([
            'reference' => 'PAY-T-' . Str::random(6), 'idempotency_key' => (string) Str::uuid(),
            'membership_id' => $i->membership_id, 'channel' => 'cash', 'status' => 'confirmed',
            'amount_minor' => $amount, 'confirmed_at' => now(),
        ]);
        DB::table('installment_payments')->insert(['payment_id' => $p->id, 'installment_id' => $i->id, 'amount_minor' => $amount]);
    }

    private function fixture(): array
    {
        $campaign = $this->campaign(['name' => 'Épargne du marché']);
        $paid     = $this->membership($this->member(['full_name' => 'Awa Paye']), $campaign);
        $late     = $this->membership($this->member(['full_name' => 'Bio Retard']), $campaign);
        $partial  = $this->membership($this->member(['full_name' => 'Cica Partiel']), $campaign);
        $waived   = $this->membership($this->member(['full_name' => 'Dodo Annule']), $campaign);
        $pending  = $this->membership($this->member(['full_name' => 'Ella Attente']), $campaign, ['status' => 'pending_l1']);

        $this->schedule($paid, 'paid', 'paid');
        $this->schedule($late, 'late', 'pending');
        [, $p2] = $this->schedule($partial, 'paid', 'pending');
        $this->allocate($p2, 2000);
        $this->schedule($waived, 'paid', 'waived');
        $this->schedule($pending, 'pending', 'pending');

        return compact('campaign', 'paid', 'late', 'partial', 'waived');
    }

    public function test_due_dates_summary_and_default_date(): void
    {
        ['campaign' => $c] = $this->fixture();
        $this->actingAsStaff('treasurer');

        $r = $this->getJson("/api/admin/campaigns/{$c->id}/contributions/due-dates")->assertOk();
        $r->assertJsonPath('data.default_due_date', $this->today)
            ->assertJsonCount(3, 'data.dates');

        $today = collect($r->json('data.dates'))->firstWhere('due_date', $this->today);
        // Adhésion en attente de validation exclue : 4 membres suivis.
        $this->assertSame(4, $today['members_count']);
        $this->assertSame(['paid' => 1, 'partial' => 1, 'due' => 1, 'late' => 0, 'upcoming' => 0, 'waived' => 1], $today['counts']);
        $this->assertSame(15000, $today['expected_minor'], 'annulée exclue de l\'attendu');
        $this->assertSame(2000, $today['collected_minor'], 'payé = affectations réelles');
        $this->assertSame(1, $today['paid_count']);
        $this->assertSame(2, $today['unpaid_count']);
    }

    public function test_by_due_date_rows_statuses_order_and_filters(): void
    {
        ['campaign' => $c] = $this->fixture();
        $this->actingAsStaff('treasurer');

        $rows = $this->getJson("/api/admin/campaigns/{$c->id}/contributions?due_date={$this->today}")->assertOk()
            ->assertJsonPath('data.totals.remaining_minor', 8000)
            ->json('data.rows');

        $this->assertSame(['Bio Retard', 'Cica Partiel', 'Awa Paye', 'Dodo Annule'], array_column($rows, 'member_name'), 'non-payés d\'abord');
        $byName = collect($rows)->keyBy('member_name');
        $this->assertSame('due', $byName['Bio Retard']['status']);
        $this->assertSame(1, $byName['Bio Retard']['late_count']);
        $this->assertSame('partial', $byName['Cica Partiel']['status']);
        $this->assertSame(3000, $byName['Cica Partiel']['remaining_minor']);
        $this->assertNotNull($byName['Cica Partiel']['last_payment_at']);
        $this->assertSame('waived', $byName['Dodo Annule']['status']);

        $this->getJson("/api/admin/campaigns/{$c->id}/contributions?due_date={$this->today}&status=unpaid")
            ->assertJsonCount(2, 'data.rows');
        $this->getJson("/api/admin/campaigns/{$c->id}/contributions?due_date={$this->past}&status=late")
            ->assertJsonCount(1, 'data.rows')->assertJsonPath('data.rows.0.member_name', 'Bio Retard');
        $this->getJson("/api/admin/campaigns/{$c->id}/contributions?due_date={$this->future}")
            ->assertJsonPath('data.totals.counts.upcoming', 4);
        $this->getJson("/api/admin/campaigns/{$c->id}/contributions?due_date={$this->today}&search=cica")
            ->assertJsonCount(1, 'data.rows');
        // Sans date : la date par défaut (aujourd'hui).
        $this->getJson("/api/admin/campaigns/{$c->id}/contributions")->assertJsonPath('data.due_date', $this->today);
    }

    public function test_grid_is_paginated_with_cells_and_member_summary(): void
    {
        ['campaign' => $c] = $this->fixture();
        $this->actingAsStaff('manager');

        $r = $this->getJson("/api/admin/campaigns/{$c->id}/contributions/grid?per_page=2")->assertOk()
            ->assertJsonPath('meta.total', 4)->assertJsonPath('meta.last_page', 2)
            ->assertJsonCount(3, 'data.columns')->assertJsonCount(2, 'data.rows');

        $awa = $r->json('data.rows.0');
        $this->assertSame('Awa Paye', $awa['member_name']);
        $this->assertSame(2, $awa['paid_count']);
        $this->assertSame(2, $awa['due_count']);
        $this->assertSame(2, $awa['paid_due_count']);
        $this->assertSame(0, $awa['paid_ahead_count']);
        $this->assertSame(0, $awa['outstanding_minor']);
        $this->assertSame('upcoming', $awa['cells']['3']['status']);

        $bio = $r->json('data.rows.1');
        $this->assertSame('late', $bio['cells']['1']['status']);
        $this->assertSame(10000, $bio['outstanding_minor']);
    }

    public function test_export_csv_for_a_date_and_overview(): void
    {
        ['campaign' => $c] = $this->fixture();
        $this->actingAsStaff('treasurer');

        $csv = $this->get("/api/admin/campaigns/{$c->id}/contributions/export?due_date={$this->today}")->assertOk()->streamedContent();
        $this->assertStringContainsString('Membre;Téléphone', $csv);
        $this->assertStringContainsString('Cica Partiel', $csv);
        $this->assertStringContainsString('Partiel', $csv);

        $all = $this->get("/api/admin/campaigns/{$c->id}/contributions/export")->assertOk()->streamedContent();
        $this->assertStringContainsString('Reste dû à ce jour', $all);
        $this->assertSame(5, substr_count(trim($all), "\n") + 1, 'en-tête + 4 membres');
    }

    public function test_member_cannot_read_tracking(): void
    {
        ['campaign' => $c] = $this->fixture();
        Sanctum::actingAs($this->member());

        $this->getJson("/api/admin/campaigns/{$c->id}/contributions/due-dates")->assertForbidden();
    }

    public function test_rotating_campaign_dates_carry_round_number(): void
    {
        $c = $this->campaign(['campaign_type' => 'rotating']);
        $m = $this->membership($this->member(), $c);
        $this->schedule($m, 'paid', 'pending');
        DB::table('rotation_rounds')->insert([
            ['id' => (string) Str::uuid(), 'campaign_id' => $c->id, 'round_number' => 1, 'first_sequence' => 1, 'last_sequence' => 1,
             'scheduled_date' => $this->past, 'status' => 'completed', 'expected_minor' => 5000, 'created_at' => now(), 'updated_at' => now()],
            ['id' => (string) Str::uuid(), 'campaign_id' => $c->id, 'round_number' => 2, 'first_sequence' => 2, 'last_sequence' => 2,
             'scheduled_date' => $this->today, 'status' => 'collecting', 'expected_minor' => 5000, 'created_at' => now(), 'updated_at' => now()],
        ]);
        $this->actingAsStaff('treasurer');

        $dates = collect($this->getJson("/api/admin/campaigns/{$c->id}/contributions/due-dates")->assertOk()->json('data.dates'));
        $this->assertSame(1, $dates->firstWhere('due_date', $this->past)['round_number']);
        $this->getJson("/api/admin/campaigns/{$c->id}/contributions?due_date={$this->today}")
            ->assertJsonPath('data.round_number', 2);
    }
}
