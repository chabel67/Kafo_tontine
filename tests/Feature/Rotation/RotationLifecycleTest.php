<?php

namespace Tests\Feature\Rotation;

use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Notifications\Infrastructure\Models\InAppNotification;
use App\Modules\Tontine\Application\RotationReconciliationService;
use App\Modules\Tontine\Infrastructure\Models\Installment;
use App\Modules\Tontine\Infrastructure\Models\Membership;
use App\Modules\Tontine\Infrastructure\Models\RotationRound;
use App\Modules\Tontine\Jobs\RotationRemindersJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\BuildsKafoFixtures;
use Tests\Support\BuildsRotationFixtures;
use Tests\TestCase;

/**
 * Lot 8 — départs (D-R7), remplaçant, sorties de caisse en deux temps, reliquat (D-R11),
 * clôture financière, rappels.
 */
class RotationLifecycleTest extends TestCase
{
    use RefreshDatabase, BuildsKafoFixtures, BuildsRotationFixtures;

    private User $manager;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        $this->manager = $this->staff('manager');
        $this->admin   = $this->staff('admin');
    }

    private function as(User $u): void
    {
        Sanctum::actingAs($u);
    }

    private function distribute(int $roundNumber): void
    {
        $this->as($this->manager);
        $this->postJson("/api/admin/rotation-rounds/{$this->round($roundNumber)->id}/approve")->assertOk();
        $this->as($this->admin);
        $this->postJson("/api/admin/rotation-rounds/{$this->round($roundNumber)->id}/payout/initiate",
            ['channel' => 'cash', 'idempotency_key' => (string) Str::uuid()], $this->stepUpHeader($this->admin))->assertOk();
        $this->postJson("/api/admin/rotation-rounds/{$this->round($roundNumber)->id}/payout/confirm")->assertOk();
    }

    // ── Départs ───────────────────────────────────────────────────────────

    public function test_a_served_member_cannot_leave(): void
    {
        $this->startedRotation();
        foreach ($this->rotationMembers as $m) {
            $this->payRotationCash($m, 2000)->assertCreated();
        }
        $this->distribute(1);

        $this->as($this->manager);
        $this->postJson("/api/admin/memberships/{$this->rotationMembers[0]->id}/rotation/departure",
            ['refund_minor' => 0, 'reason' => 'Déménagement à Parakou.'])
            ->assertStatus(422)->assertJsonPath('error.code', 'departure_already_served');
    }

    public function test_departure_refund_is_bounded_two_step_and_maker_checked(): void
    {
        $this->startedRotation();
        $leaver = $this->rotationMembers[2];   // tour 3, pas encore servi
        $this->payRotationCash($leaver, 2000)->assertCreated();
        $this->as($this->manager);

        $this->postJson("/api/admin/memberships/{$leaver->id}/rotation/departure", ['refund_minor' => 2500, 'reason' => 'Déménagement à Parakou.'])
            ->assertStatus(422)->assertJsonPath('error.code', 'departure_refund_out_of_bounds');

        $this->as($this->admin);
        $id = $this->postJson("/api/admin/memberships/{$leaver->id}/rotation/departure", ['refund_minor' => 1800, 'reason' => 'Déménagement à Parakou.'])
            ->assertCreated()->json('data.id');

        $this->assertSame('suspended', $leaver->fresh()->status->value);
        $this->assertSame('vacant', $this->round(3)->status->value);
        $this->assertSame(4, Installment::where('membership_id', $leaver->id)->where('status', 'waived')->count());

        // Celui qui a décidé ne verse pas.
        $this->postJson("/api/admin/rotation-departures/{$id}/refund/initiate", ['channel' => 'cash', 'idempotency_key' => (string) Str::uuid()],
            $this->stepUpHeader($this->admin))->assertStatus(422)->assertJsonPath('error.code', 'maker_checker_violation');

        $other = $this->staff('admin');
        $this->as($other);
        $attempt = $this->postJson("/api/admin/rotation-departures/{$id}/refund/initiate", ['channel' => 'cash', 'idempotency_key' => (string) Str::uuid()],
            $this->stepUpHeader($other))->assertOk()->json('data.id');
        $this->assertSame(2000, $this->ledgerBalance("ROTATING_POOL:{$this->rotation->id}"), 'aucune écriture au lancement');

        $this->postJson("/api/admin/rotation-cash-outs/{$attempt}/confirm")->assertOk()->assertJsonPath('data.status', 'succeeded');
        $this->assertSame(200, $this->ledgerBalance("ROTATING_POOL:{$this->rotation->id}"));
        $this->assertTrue(app(RotationReconciliationService::class)->reconcile($this->rotation)['balanced']);
        $this->assertLedgerBalanced();
    }

    public function test_replacement_takes_the_round_and_remaining_installments(): void
    {
        $this->startedRotation();
        $leaver = $this->rotationMembers[2];
        $this->payRotationCash($leaver, 2000)->assertCreated();   // séq. 1-2 payées
        $this->as($this->manager);
        $id = $this->postJson("/api/admin/memberships/{$leaver->id}/rotation/departure", ['refund_minor' => 0, 'reason' => 'Raisons familiales graves.'])
            ->assertCreated()->json('data.id');

        $newcomer = $this->member(['full_name' => 'Kossi Remplaçant']);
        $mid = $this->postJson("/api/admin/rotation-departures/{$id}/replacement", ['user_id' => $newcomer->id])->assertCreated()->json('data.membership_id');

        $this->assertSame($mid, $this->round(3)->beneficiary_membership_id);
        $this->assertNotSame('vacant', $this->round(3)->status->value);
        $this->assertSame([3, 4, 5, 6], Installment::where('membership_id', $mid)->orderBy('sequence_number')->pluck('sequence_number')->all());
        $this->assertTrue(InAppNotification::where('user_id', $newcomer->id)->where('type', 'rotation.round_assigned')->exists());

        $this->postJson("/api/admin/rotation-departures/{$id}/replacement", ['user_id' => $this->member()->id])
            ->assertStatus(409)->assertJsonPath('error.code', 'replacement_exists');
    }

    // ── Clôture et reliquat (D-R11) ───────────────────────────────────────

    public function test_closure_requires_all_rounds_and_an_empty_pool(): void
    {
        $this->startedRotation(daysAgo: 6);   // les 6 échéances sont échues : 3 tours distribuables
        foreach ($this->rotationMembers as $m) {
            $this->payRotationCash($m, 6000)->assertCreated();
        }
        $this->payRotationCash($this->rotationMembers[0], 900)->assertCreated();   // trop-perçu → reliquat
        $this->as($this->admin);

        $this->getJson("/api/admin/campaigns/{$this->rotation->id}/rotation/closure")->assertOk()
            ->assertJsonPath('data.closable', false)->assertJsonPath('data.rounds_pending', 3);
        $this->postJson("/api/admin/campaigns/{$this->rotation->id}/rotation/close", ['reason' => 'Fin du cycle de la tontine.'], $this->stepUpHeader($this->admin))
            ->assertStatus(422)->assertJsonPath('error.code', 'rotation_not_closable');

        foreach ([1, 2, 3] as $t) {
            $this->distribute($t);
        }
        $super = $this->staff('super_admin');
        $this->as($super);
        $preview = $this->getJson("/api/admin/campaigns/{$this->rotation->id}/rotation/closure")->assertOk()->json('data');
        $this->assertSame(900, $preview['pool_balance_minor']);
        $this->assertFalse($preview['closable']);

        // Reliquat : 600 versés à un membre (deux temps), 300 passés en produit.
        $mid = $this->rotationMembers[0]->id;
        $attempt = $this->postJson("/api/admin/campaigns/{$this->rotation->id}/rotation/surplus/payouts/initiate", [
            'membership_id' => $mid, 'amount_minor' => 600, 'reason' => 'Rendu du trop-perçu de Awa.', 'channel' => 'cash',
            'idempotency_key' => (string) Str::uuid(),
        ], $this->stepUpHeader($super))->assertOk()->json('data.id');
        $this->postJson("/api/admin/rotation-cash-outs/{$attempt}/confirm")->assertOk();
        $this->postJson("/api/admin/campaigns/{$this->rotation->id}/rotation/surplus/revenue",
            ['amount_minor' => 300, 'reason' => 'Solde décidé en assemblée.'], $this->stepUpHeader($super))->assertOk();

        $this->postJson("/api/admin/campaigns/{$this->rotation->id}/rotation/close", ['reason' => 'Fin du cycle de la tontine.'], $this->stepUpHeader($super))
            ->assertOk()->assertJsonPath('data.status', 'closed');

        $this->assertSame(0, $this->ledgerBalance("ROTATING_POOL:{$this->rotation->id}"));
        $this->assertSame(300, $this->ledgerBalance('MISC_REVENUE'));
        $this->assertSame(0, Membership::where('campaign_id', $this->rotation->id)->where('status', '!=', 'closed')->count());
        $this->assertTrue(app(RotationReconciliationService::class)->reconcile($this->rotation->fresh())['balanced']);
        $this->assertLedgerBalanced();
    }

    public function test_unpaid_obligations_block_nothing_but_are_listed_and_never_erased(): void
    {
        $this->startedRotation(daysAgo: 6);
        foreach ($this->rotationMembers as $m) {
            $this->payRotationCash($m, 6000)->assertCreated();
        }
        foreach ([1, 2, 3] as $t) {
            $this->distribute($t);
        }
        // Une pénalité impayée reste une obligation après la fin des tours.
        Installment::where('membership_id', $this->rotationMembers[1]->id)->where('sequence_number', 6)
            ->update(['status' => 'late', 'penalty_minor' => 500]);

        $super = $this->staff('super_admin');
        $this->as($super);
        $preview = $this->getJson("/api/admin/campaigns/{$this->rotation->id}/rotation/closure")->assertOk()->json('data');
        $this->assertSame(500, $preview['remaining_obligations'][0]['remaining_minor']);

        $this->postJson("/api/admin/campaigns/{$this->rotation->id}/rotation/close", ['reason' => 'Fin du cycle de la tontine.'], $this->stepUpHeader($super))->assertOk();
        $this->assertSame('late', Installment::where('membership_id', $this->rotationMembers[1]->id)->where('sequence_number', 6)->value('status')->value);
    }

    // ── Rappels ──────────────────────────────────────────────────────────

    public function test_daily_reminders(): void
    {
        $this->startedRotation(daysAgo: 0);   // tour 1 : échéances aujourd'hui et demain → cotisation demain, tour J+1
        RotationRound::where('campaign_id', $this->rotation->id)->where('round_number', 2)
            ->update(['scheduled_date' => now()->addDays(2)->toDateString()]);

        app()->call([new RotationRemindersJob(), 'handle']);

        $this->assertSame(3, InAppNotification::where('type', 'rotation.contribution_reminder')->count());
        $this->assertTrue(InAppNotification::where('type', 'rotation.round_upcoming')
            ->where('user_id', $this->rotationMembers[1]->user_id)->exists());
    }
}
