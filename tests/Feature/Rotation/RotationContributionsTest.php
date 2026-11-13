<?php

namespace Tests\Feature\Rotation;

use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Ledger\Application\LedgerService;
use App\Modules\Ledger\Infrastructure\Models\LedgerAccount;
use App\Modules\Tontine\Application\InstallmentGeneratorService;
use App\Modules\Tontine\Application\RotationScheduleService;
use App\Modules\Tontine\Application\RotationTrackingService;
use App\Modules\Tontine\Infrastructure\Models\Campaign;
use App\Modules\Tontine\Infrastructure\Models\Installment;
use App\Modules\Tontine\Infrastructure\Models\Membership;
use App\Modules\Tontine\Infrastructure\Models\RotationRound;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\BuildsKafoFixtures;
use Tests\TestCase;

/**
 * Lot 4 — cotisations rotatives : caisse commune (jamais d'épargne), complément (D-R5),
 * pénalités (D-R10), couverture et statut des tours (D-R3/D-R4), obligations après distribution,
 * blocage des opérations d'épargne.
 */
class RotationContributionsTest extends TestCase
{
    use RefreshDatabase, BuildsKafoFixtures;

    private Campaign $campaign;
    /** @var Membership[] */
    private array $members = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    /** Tontine démarrée : 3 membres, 2 cotisations par tour, une cotisation par jour depuis $daysAgo jours. */
    private function startedRotation(string $feeMode = 'none', int $fee = 0, int $daysAgo = 10): void
    {
        $campaign = $this->campaign([
            'status' => 'draft', 'campaign_type' => 'rotating', 'max_members' => 3, 'installments_per_round' => 2,
            'contribution_amount_minor' => 1000, 'duration_installments' => 6, 'grace_period_days' => 0,
            'first_installment_date' => now()->subDays($daysAgo)->toDateString(),
            'contribution_schedule' => ['type' => 'every_n_days', 'n' => 1], 'allow_early_withdrawals' => false,
            'rotation_fee_mode' => $feeMode, 'rotation_fee_minor' => $fee,
        ]);
        app(RotationScheduleService::class)->confirm($campaign);
        $campaign->update(['status' => 'open']);

        foreach (range(1, 3) as $i) {
            $m = $this->membership($this->member(['full_name' => "Awa Membre{$i} Dossou"]), $campaign->fresh(), ['committed_amount_minor' => 1000]);
            app(InstallmentGeneratorService::class)->generate($m->load('campaign'));
            $this->members[] = $m;
        }
        foreach (RotationRound::where('campaign_id', $campaign->id)->orderBy('round_number')->get() as $i => $round) {
            $round->update(['beneficiary_membership_id' => $this->members[$i]->id]);
        }
        $campaign->update(['status' => 'active', 'started_at' => now(), 'activated_at' => now()]);
        $this->campaign = $campaign->fresh();
    }

    private function payCash(Membership $m, int $amount, bool $force = false): \Illuminate\Testing\TestResponse
    {
        $this->actingAsStaff('treasurer');

        return $this->postJson('/api/admin/payments/cash', [
            'membership_id' => $m->id, 'amount_minor' => $amount, 'force' => $force, 'confirm_atypical' => true,
        ]);
    }

    private function balance(string $key): int
    {
        return app(LedgerService::class)->balances([$key])[$key] ?? 0;
    }

    // ── Caisse commune ────────────────────────────────────────────────────

    public function test_contribution_goes_to_the_common_pool_never_to_savings(): void
    {
        $this->startedRotation();
        $this->payCash($this->members[0], 1000)->assertCreated();

        $this->assertSame(1000, $this->balance("ROTATING_POOL:{$this->campaign->id}"));
        $this->assertFalse(LedgerAccount::where('key', "MEMBER_SAVINGS:{$this->members[0]->id}")->exists());
        $this->assertSame('paid', Installment::where('membership_id', $this->members[0]->id)->where('sequence_number', 1)->value('status')->value);
        $this->assertLedgerBalanced();
    }

    public function test_surcharge_goes_to_fee_income(): void
    {
        $this->startedRotation('contribution_surcharge', 50);
        $this->payCash($this->members[0], 2100)->assertCreated();   // 2 échéances × 1 050

        $this->assertSame(2000, $this->balance("ROTATING_POOL:{$this->campaign->id}"));
        $this->assertSame(100, $this->balance('FEE_INCOME'));
        $this->assertLedgerBalanced();
    }

    // ── Pénalités (D-R10) ─────────────────────────────────────────────────

    public function test_penalty_is_decided_case_by_case_paid_first_and_locked_once_paid(): void
    {
        $this->startedRotation();
        $installment = Installment::where('membership_id', $this->members[0]->id)->where('sequence_number', 1)->first();
        $future      = Installment::where('membership_id', $this->members[0]->id)->orderByDesc('sequence_number')->first();
        $future->update(['due_date' => now()->addDays(3)->toDateString()]);
        $this->actingAsStaff('manager');

        $this->postJson("/api/admin/installments/{$future->id}/penalty", ['amount_minor' => 200, 'reason' => 'Retard répété ce mois.'])
            ->assertStatus(422)->assertJsonPath('error.code', 'penalty_not_due');
        $this->postJson("/api/admin/installments/{$installment->id}/penalty", ['amount_minor' => 200, 'reason' => 'court'])
            ->assertStatus(422);

        $this->postJson("/api/admin/installments/{$installment->id}/penalty", ['amount_minor' => 300, 'reason' => 'Retard répété ce mois.'])->assertOk();
        $this->postJson("/api/admin/installments/{$installment->id}/penalty/cancel", ['reason' => 'Erreur de saisie corrigée.'])->assertOk()
            ->assertJsonPath('data.penalty_minor', 0);
        $this->postJson("/api/admin/installments/{$installment->id}/penalty", ['amount_minor' => 200, 'reason' => 'Retard répété ce mois.'])->assertOk();

        // Paiement partiel : la pénalité est réglée en premier.
        $this->payCash($this->members[0], 150)->assertCreated();
        $this->assertSame(150, $this->balance('PENALTY_INCOME'));
        $this->assertSame(0, $this->balance("ROTATING_POOL:{$this->campaign->id}"));

        $this->actingAsStaff('manager');
        $this->postJson("/api/admin/installments/{$installment->id}/penalty/cancel", ['reason' => 'Geste commercial accordé.'])
            ->assertStatus(422)->assertJsonPath('error.code', 'penalty_locked');

        $this->payCash($this->members[0], 1050, true)->assertCreated();
        $this->assertSame(200, $this->balance('PENALTY_INCOME'));
        $this->assertSame(1000, $this->balance("ROTATING_POOL:{$this->campaign->id}"));
        $this->assertSame('paid', $installment->fresh()->status->value);
        $this->assertLedgerBalanced();
    }

    // ── Couverture et statut des tours (D-R3 / D-R4) ──────────────────────

    public function test_round_is_ready_only_when_its_window_is_fully_paid(): void
    {
        $this->startedRotation(daysAgo: 2);   // tour 1 échu hier, échéance 3 aujourd'hui (pas encore due)
        $round1 = RotationRound::where('campaign_id', $this->campaign->id)->where('round_number', 1)->first();
        $tracking = app(RotationTrackingService::class);

        $this->payCash($this->members[0], 2000)->assertCreated();
        $this->payCash($this->members[1], 2000)->assertCreated();
        $tracking->refreshStatuses($this->campaign);
        $this->assertSame('blocked', $round1->fresh()->status->value);

        $coverage = $tracking->coverage($round1->fresh());
        $this->assertSame(6000, $coverage['pot_minor']);
        $this->assertSame(4000, $coverage['collected_minor']);
        $this->assertSame(2000, $coverage['missing_minor']);
        $this->assertSame(2, $coverage['members_up_to_date']);
        $this->assertCount(2, $coverage['unpaid']);

        $this->payCash($this->members[2], 2000)->assertCreated();
        $tracking->refreshStatuses($this->campaign);
        $this->assertSame('ready', $round1->fresh()->status->value);
        $this->assertSame(6000, $tracking->poolBalance($this->campaign->id));
    }

    public function test_beneficiary_in_arrears_blocks_the_round(): void
    {
        $this->startedRotation(daysAgo: 2);
        $round1 = RotationRound::where('campaign_id', $this->campaign->id)->where('round_number', 1)->first();
        foreach ($this->members as $m) {
            $this->payCash($m, 2000)->assertCreated();
        }
        // Le bénéficiaire du tour 1 a une échéance ultérieure déjà échue et impayée.
        Installment::where('membership_id', $this->members[0]->id)->where('sequence_number', 3)
            ->update(['due_date' => now()->subDay()->toDateString()]);

        $tracking = app(RotationTrackingService::class);
        $tracking->refreshStatuses($this->campaign);
        $this->assertSame('blocked', $round1->fresh()->status->value);
        $this->assertSame(1, $tracking->coverage($round1->fresh())['beneficiary_arrears']['count']);
    }

    public function test_future_round_is_scheduled_or_collecting(): void
    {
        $this->startedRotation(daysAgo: 0);   // tour 1 : dates aujourd'hui et demain
        $tracking = app(RotationTrackingService::class);
        $tracking->refreshStatuses($this->campaign);

        $statuses = RotationRound::where('campaign_id', $this->campaign->id)->orderBy('round_number')->pluck('status')->map->value->all();
        $this->assertSame(['collecting', 'scheduled', 'scheduled'], $statuses);
    }

    // ── Suivi membre, confidentialité (D-R8) ─────────────────────────────

    public function test_member_view_masks_other_names_and_keeps_obligations_after_distribution(): void
    {
        $this->startedRotation();
        RotationRound::where('campaign_id', $this->campaign->id)->where('round_number', 1)
            ->update(['status' => 'completed', 'distributed_minor' => 6000]);
        $owner = User::find($this->members[0]->user_id);
        Sanctum::actingAs($owner);

        $data = $this->getJson("/api/member/memberships/{$this->members[0]->id}/rotation")->assertOk()->json('data');

        $this->assertTrue($data['me']['round']['received']);
        $this->assertSame(6000, $data['me']['round']['received_minor']);
        $this->assertSame(6000, $data['me']['remaining_minor'], 'ses 6 échéances restent dues après sa distribution');
        $this->assertSame('Awa D.', $data['rounds'][1]['beneficiary']);
        $this->assertTrue($data['rounds'][0]['is_me']);
        $this->assertStringNotContainsString('+229', json_encode($data['rounds']));

        // Un autre membre ne voit pas cette adhésion.
        Sanctum::actingAs(User::find($this->members[1]->user_id));
        $this->getJson("/api/member/memberships/{$this->members[0]->id}/rotation")->assertNotFound();
    }

    public function test_admin_overview(): void
    {
        $this->startedRotation();
        $this->payCash($this->members[0], 2000)->assertCreated();
        $this->actingAsStaff('manager');

        $data = $this->getJson("/api/admin/campaigns/{$this->campaign->id}/rotation/overview")->assertOk()->json('data');
        $this->assertSame(3, $data['rounds_count']);
        $this->assertSame(6000, $data['pot_minor']);
        $this->assertSame(2000, $data['pool_balance_minor']);
        $this->assertSame(16000, $data['remaining_to_collect_minor']);
        $this->assertCount(3, $data['members']);
    }

    // ── Pas d'opérations d'épargne ────────────────────────────────────────

    public function test_savings_operations_are_refused_on_a_rotating_campaign(): void
    {
        $this->startedRotation();
        $this->payCash($this->members[0], 2000)->assertCreated();
        $owner = User::find($this->members[0]->user_id);
        Sanctum::actingAs($owner);

        $this->getJson("/api/member/memberships/{$this->members[0]->id}/withdrawable")->assertOk()
            ->assertJsonPath('data.available_minor', 0)->assertJsonPath('data.not_applicable', true);
        $this->postJson('/api/member/early-withdrawals', [
            'membership_id' => $this->members[0]->id, 'amount_minor' => 500, 'payout_channel' => 'cash',
            'idempotency_key' => (string) Str::uuid(),
        ])->assertStatus(422)->assertJsonPath('error.code', 'not_for_rotating_campaign');
        $this->postJson('/api/member/loan-requests', ['membership_id' => $this->members[0]->id, 'amount_minor' => 500])
            ->assertStatus(422)->assertJsonPath('error.code', 'not_for_rotating_campaign');

        $admin = $this->actingAsStaff('admin');
        $this->postJson("/api/admin/campaigns/{$this->campaign->id}/close", ['reason' => 'Fin du cycle de la tontine.'], $this->stepUpHeader($admin))
            ->assertStatus(422)->assertJsonPath('error.code', 'rotation_closure_required');
    }
}
