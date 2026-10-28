<?php

namespace Tests\Feature\Members;

use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Ledger\Application\LedgerService;
use App\Modules\Ledger\Infrastructure\Models\LedgerEntry;
use App\Modules\Ledger\Infrastructure\Models\LedgerTransaction;
use App\Modules\Notifications\Infrastructure\Models\InAppNotification;
use App\Modules\Payments\Infrastructure\Models\Payment;
use App\Modules\Tontine\Infrastructure\Models\EarlyWithdrawal;
use App\Modules\Tontine\Infrastructure\Models\EarlyWithdrawalPayoutAttempt;
use App\Modules\Tontine\Infrastructure\Models\Membership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\BuildsKafoFixtures;
use Tests\TestCase;

class EarlyWithdrawalTest extends TestCase
{
    use RefreshDatabase, BuildsKafoFixtures;

    private Membership $membership;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();

        $this->owner = $this->member();
        $this->owner->roles()->attach(Role::where('name', 'member')->value('id'));
        $this->membership = $this->membership($this->owner);
        $this->creditSavings($this->membership, 20000);
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    private function requestAsStaff(int $amount, ?string $key = null)
    {
        return $this->postJson('/api/admin/early-withdrawals', [
            'membership_id'   => $this->membership->id,
            'amount_minor'    => $amount,
            'reason'          => 'Frais de scolarité',
            'payout_channel'  => 'cash',
            'idempotency_key' => $key ?? (string) Str::uuid(),
        ]);
    }

    private function approved(int $amount = 8000): string
    {
        $this->actingAsStaff('manager');
        $id = $this->requestAsStaff($amount)->assertCreated()->json('data.id');
        $this->postJson("/api/admin/early-withdrawals/{$id}/approve")->assertOk();

        return $id;
    }

    private function initiate(string $id, User $admin, ?string $key = null)
    {
        return $this->postJson("/api/admin/early-withdrawals/{$id}/payout/initiate", [
            'channel' => 'mtn', 'phone' => '+2290197000099', 'idempotency_key' => $key ?? (string) Str::uuid(),
        ], $this->stepUpHeader($admin));
    }

    private function asAdmin(): User
    {
        $admin = $this->staff('admin');
        Sanctum::actingAs($admin);

        return $admin;
    }

    private function withdrawalEntries(): int
    {
        return LedgerTransaction::where('description', 'like', 'Retrait anticipé%')->count();
    }

    // ── Demande ────────────────────────────────────────────────────────────

    public function test_member_submits_a_valid_request_with_payout_method_and_sees_breakdown(): void
    {
        Payment::create([
            'reference' => 'PAY-W-1', 'idempotency_key' => (string) Str::uuid(), 'membership_id' => $this->membership->id,
            'channel' => 'cash', 'status' => 'confirmed', 'amount_minor' => 20000, 'confirmed_at' => now(),
        ]);
        Sanctum::actingAs($this->owner);

        $this->getJson("/api/member/memberships/{$this->membership->id}/withdrawable")
            ->assertOk()
            ->assertJsonPath('data.paid_minor', 20000)
            ->assertJsonPath('data.savings_minor', 20000)
            ->assertJsonPath('data.withdrawn_minor', 0)
            ->assertJsonPath('data.fees_minor', 0)
            ->assertJsonPath('data.debts.deducted', false)
            ->assertJsonPath('data.available_minor', 20000);

        $this->postJson('/api/member/early-withdrawals', [
            'membership_id' => $this->membership->id, 'amount_minor' => 4000, 'payout_channel' => 'mtn',
            'idempotency_key' => (string) Str::uuid(),
        ])->assertStatus(422)->assertJsonValidationErrors(['payout_phone'], 'error.errors');

        $this->postJson('/api/member/early-withdrawals', [
            'membership_id' => $this->membership->id, 'amount_minor' => 4000, 'payout_channel' => 'mtn',
            'payout_phone' => '+2290197000077', 'idempotency_key' => (string) Str::uuid(),
        ])->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.payout_channel', 'mtn');

        $this->assertSame(0, $this->withdrawalEntries(), 'une demande ne décaisse jamais');
        $this->assertTrue(InAppNotification::where('user_id', $this->owner->id)->where('title', 'Demande de retrait reçue')->exists());
    }

    public function test_request_above_available_is_rejected(): void
    {
        $this->actingAsStaff('manager');

        $this->requestAsStaff(25000)->assertStatus(422)->assertJsonPath('error.code', 'withdrawal_exceeds_available');
    }

    public function test_successive_requests_cannot_exceed_available_funds(): void
    {
        $this->actingAsStaff('manager');

        $this->requestAsStaff(15000)->assertCreated();
        $this->getJson("/api/admin/memberships/{$this->membership->id}/withdrawable")
            ->assertJsonPath('data.committed_minor', 15000)
            ->assertJsonPath('data.available_minor', 5000);
        $this->requestAsStaff(6000)->assertStatus(422);
        $this->requestAsStaff(5000)->assertCreated();
        $this->requestAsStaff(1)->assertStatus(422);
    }

    public function test_request_is_idempotent(): void
    {
        $this->actingAsStaff('manager');
        $key = (string) Str::uuid();

        $first  = $this->requestAsStaff(1000, $key)->assertCreated()->json('data.id');
        $second = $this->requestAsStaff(1000, $key)->json('data.id');

        $this->assertSame($first, $second);
        $this->assertSame(1, EarlyWithdrawal::count());
    }

    public function test_member_cannot_withdraw_from_someone_elses_membership(): void
    {
        Sanctum::actingAs($this->member());

        $this->getJson("/api/member/memberships/{$this->membership->id}/withdrawable")->assertNotFound();
        $this->postJson('/api/member/early-withdrawals', [
            'membership_id' => $this->membership->id, 'amount_minor' => 1000, 'payout_channel' => 'cash',
            'idempotency_key' => (string) Str::uuid(),
        ])->assertNotFound();
        $this->assertSame(0, EarlyWithdrawal::count());
    }

    public function test_only_active_memberships_can_request(): void
    {
        $this->actingAsStaff('manager');
        $this->membership->update(['status' => 'suspended']);

        $this->requestAsStaff(1000)->assertStatus(422)->assertJsonPath('error.code', 'membership_not_active');
    }

    // ── Instruction ────────────────────────────────────────────────────────

    public function test_treasurer_reviews_requests_info_and_member_answers(): void
    {
        $treasurer = $this->actingAsStaff('treasurer');
        $id = EarlyWithdrawal::create([
            'reference' => 'WDR-T-1', 'idempotency_key' => (string) Str::uuid(), 'membership_id' => $this->membership->id,
            'user_id' => $this->owner->id, 'amount_minor' => 3000, 'payout_channel' => 'cash', 'status' => 'pending',
            'requested_via' => 'member', 'requested_by' => $this->owner->id,
        ])->id;

        $this->postJson("/api/admin/early-withdrawals/{$id}/review")
            ->assertOk()->assertJsonPath('data.status', 'under_review')->assertJsonPath('data.assigned_to.id', $treasurer->id);

        $this->postJson("/api/admin/early-withdrawals/{$id}/request-info", ['reason' => 'Merci de préciser l\'usage des fonds.'])
            ->assertOk()->assertJsonPath('data.status', 'info_requested');
        $this->assertTrue(InAppNotification::where('user_id', $this->owner->id)->where('title', 'Informations complémentaires requises')->exists());

        // Pas d'approbation tant que l'information n'est pas fournie
        $this->postJson("/api/admin/early-withdrawals/{$id}/approve")->assertStatus(422);

        Sanctum::actingAs($this->owner);
        $this->postJson("/api/member/early-withdrawals/{$id}/respond", ['response' => 'Pour les frais d\'hôpital de ma mère.'])
            ->assertOk()->assertJsonPath('data.status', 'under_review');

        Sanctum::actingAs($treasurer);
        $this->postJson("/api/admin/early-withdrawals/{$id}/approve")->assertOk()->assertJsonPath('data.status', 'approved');

        $detail = $this->getJson("/api/admin/early-withdrawals/{$id}")
            ->assertOk()
            ->assertJsonPath('data.member_response', 'Pour les frais d\'hôpital de ma mère.');
        $this->assertEqualsCanonicalizing(
            ['early_withdrawal.review', 'early_withdrawal.request_info', 'early_withdrawal.respond', 'early_withdrawal.approve'],
            array_column($detail->json('data.decisions'), 'action'),
            'chaque décision est tracée',
        );
    }

    public function test_rejection_requires_a_reason_and_notifies_member(): void
    {
        $this->actingAsStaff('manager');
        $id = $this->requestAsStaff(1000)->json('data.id');

        $this->postJson("/api/admin/early-withdrawals/{$id}/reject", ['reason' => 'court'])->assertStatus(422);
        $this->postJson("/api/admin/early-withdrawals/{$id}/reject", ['reason' => 'Épargne nécessaire pour la clôture'])
            ->assertOk()->assertJsonPath('data.status', 'rejected');

        $this->assertTrue(InAppNotification::where('user_id', $this->owner->id)
            ->where('body', 'like', '%Épargne nécessaire pour la clôture%')->exists());
        $this->getJson("/api/admin/memberships/{$this->membership->id}/withdrawable")->assertJsonPath('data.available_minor', 20000);
    }

    public function test_double_approval_is_refused(): void
    {
        $id = $this->approved();

        $this->postJson("/api/admin/early-withdrawals/{$id}/approve")
            ->assertStatus(422)->assertJsonPath('error.code', 'invalid_withdrawal_transition');
    }

    // ── Versement ──────────────────────────────────────────────────────────

    public function test_approval_alone_does_not_touch_the_ledger(): void
    {
        $entries = LedgerEntry::count();
        $this->approved();

        $this->assertSame($entries, LedgerEntry::count());
        $this->assertSame(20000, app(LedgerService::class)->balance("MEMBER_SAVINGS:{$this->membership->id}"));
    }

    public function test_successful_payout_posts_exactly_one_balanced_entry(): void
    {
        $id    = $this->approved(8000);
        $admin = $this->asAdmin();

        $this->initiate($id, $admin)->assertOk()->assertJsonPath('data.status', 'payout_processing');
        $this->assertSame(0, $this->withdrawalEntries(), 'pas d\'écriture tant que le versement n\'est pas confirmé');

        $this->postJson("/api/admin/early-withdrawals/{$id}/payout/confirm", ['external_reference' => 'MTN-TX-778899'])
            ->assertOk()->assertJsonPath('data.status', 'disbursed')
            ->assertJsonPath('data.attempts.0.status', 'succeeded');

        $w       = EarlyWithdrawal::findOrFail($id);
        $entries = LedgerEntry::where('transaction_id', $w->ledger_transaction_id)->get();
        $this->assertSame(1, $this->withdrawalEntries());
        $this->assertSame(8000, (int) $entries->where('entry_type', 'debit')->sum('amount_minor'));
        $this->assertSame(8000, (int) $entries->where('entry_type', 'credit')->sum('amount_minor'));
        $this->assertSame("MEMBER_SAVINGS:{$this->membership->id}", $entries->firstWhere('entry_type', 'debit')->account_key);
        $this->assertSame('MOMO_FLOAT:mtn', $entries->firstWhere('entry_type', 'credit')->account_key);
        $this->assertSame(12000, app(LedgerService::class)->balance("MEMBER_SAVINGS:{$this->membership->id}"));
        $this->assertSame('active', $this->membership->fresh()->status->value, 'l\'adhésion continue');
        $this->assertTrue(InAppNotification::where('user_id', $this->owner->id)->where('title', 'Versement confirmé')->exists());
    }

    public function test_failed_payout_writes_nothing_and_can_be_retried_once_paid(): void
    {
        $id    = $this->approved(8000);
        $admin = $this->asAdmin();

        $this->initiate($id, $admin)->assertOk();
        $this->postJson("/api/admin/early-withdrawals/{$id}/payout/fail", ['reason' => 'Numéro MoMo injoignable'])
            ->assertOk()->assertJsonPath('data.status', 'payout_failed');
        $this->assertSame(0, $this->withdrawalEntries());
        $this->getJson("/api/admin/memberships/{$this->membership->id}/withdrawable")
            ->assertJsonPath('data.available_minor', 12000, 'les fonds restent réservés après un échec');

        // Relance : nouvelle tentative, puis confirmation
        $this->initiate($id, $admin)->assertOk()->assertJsonPath('data.status', 'payout_processing');
        $this->postJson("/api/admin/early-withdrawals/{$id}/payout/confirm")->assertOk();

        $this->assertSame(2, EarlyWithdrawalPayoutAttempt::where('early_withdrawal_id', $id)->count());
        $this->assertSame(['failed', 'succeeded'], EarlyWithdrawalPayoutAttempt::where('early_withdrawal_id', $id)->orderBy('attempt_no')->pluck('status')->all());
        $this->assertSame(1, $this->withdrawalEntries(), 'un seul décaissement comptable malgré la relance');
    }

    public function test_double_disbursement_is_impossible(): void
    {
        $id    = $this->approved(8000);
        $admin = $this->asAdmin();
        $key   = (string) Str::uuid();

        $this->initiate($id, $admin, $key)->assertOk();
        // Rejeu de la même requête : même tentative, pas de doublon
        $this->initiate($id, $admin, $key)->assertOk();
        // Nouvelle tentative pendant qu'une est en cours : refusée
        $this->initiate($id, $admin)->assertStatus(422)->assertJsonPath('error.code', 'invalid_withdrawal_transition');
        $this->assertSame(1, EarlyWithdrawalPayoutAttempt::where('early_withdrawal_id', $id)->count());

        $this->postJson("/api/admin/early-withdrawals/{$id}/payout/confirm")->assertOk();
        $this->postJson("/api/admin/early-withdrawals/{$id}/payout/confirm")
            ->assertStatus(422)->assertJsonPath('error.code', 'invalid_withdrawal_transition');

        $this->assertSame(1, $this->withdrawalEntries());
        $this->assertSame(12000, app(LedgerService::class)->balance("MEMBER_SAVINGS:{$this->membership->id}"));
    }

    public function test_payout_requires_step_up_and_a_different_person(): void
    {
        $admin = $this->actingAsStaff('admin');
        $id    = $this->requestAsStaff(3000)->json('data.id');
        $this->postJson("/api/admin/early-withdrawals/{$id}/approve")->assertOk();

        $this->postJson("/api/admin/early-withdrawals/{$id}/payout/initiate", [
            'channel' => 'cash', 'idempotency_key' => (string) Str::uuid(),
        ])->assertForbidden()->assertJsonPath('error.code', 'step_up_required');

        $this->postJson("/api/admin/early-withdrawals/{$id}/payout/initiate", [
            'channel' => 'cash', 'idempotency_key' => (string) Str::uuid(),
        ], $this->stepUpHeader($admin))->assertStatus(422)->assertJsonPath('error.code', 'maker_checker_violation');
    }

    public function test_balance_is_rechecked_before_paying(): void
    {
        $id = $this->approved(15000);

        // Le solde baisse entre l'approbation et le versement
        $ledger = app(LedgerService::class);
        $ledger->post([
            ['account' => "MEMBER_SAVINGS:{$this->membership->id}", 'type' => 'debit',  'amount' => 10000],
            ['account' => 'CASH_BOX',                                'type' => 'credit', 'amount' => 10000],
        ], $ledger->nextReference(), 'Sortie test');

        $admin = $this->asAdmin();
        $this->initiate($id, $admin)->assertStatus(422)->assertJsonPath('error.code', 'withdrawal_exceeds_available');
        $this->assertSame('approved', EarlyWithdrawal::find($id)->status->value);
    }

    // ── Permissions, notifications, statistiques ───────────────────────────

    public function test_role_permissions_are_enforced(): void
    {
        $this->actingAsStaff('treasurer');
        $this->requestAsStaff(1000)->assertForbidden();                  // pas withdrawal.request

        $id = $this->approved(1000);
        $this->actingAsStaff('treasurer');
        $this->postJson("/api/admin/early-withdrawals/{$id}/payout/confirm")->assertForbidden();

        $this->actingAsStaff('manager');
        $this->postJson("/api/admin/early-withdrawals/{$id}/payout/fail", ['reason' => 'Tentative non autorisée'])->assertForbidden();

        Sanctum::actingAs($this->owner);
        $this->getJson('/api/admin/early-withdrawals')->assertForbidden();
    }

    public function test_member_notifications_are_private(): void
    {
        $this->actingAsStaff('manager');
        $this->requestAsStaff(1000)->assertCreated();

        Sanctum::actingAs($this->owner);
        $this->getJson('/api/member/notifications/count')->assertOk()->assertJsonPath('data.unread', 1);
        $notificationId = $this->getJson('/api/member/notifications')->json('data.0.id');

        $intruder = $this->member();
        Sanctum::actingAs($intruder);
        $this->getJson('/api/member/notifications')->assertOk()->assertJsonCount(0, 'data');
        $this->postJson("/api/member/notifications/{$notificationId}/read")->assertOk();
        $this->assertNull(InAppNotification::find($notificationId)->read_at, 'un autre membre ne peut pas la marquer lue');

        Sanctum::actingAs($this->owner);
        $this->postJson('/api/member/notifications/read-all')->assertOk();
        $this->getJson('/api/member/notifications/count')->assertJsonPath('data.unread', 0);
    }

    public function test_statistics_are_computed_from_real_data(): void
    {
        $paid  = $this->approved(5000);
        $admin = $this->asAdmin();
        $this->initiate($paid, $admin)->assertOk();
        $this->postJson("/api/admin/early-withdrawals/{$paid}/payout/confirm")->assertOk();

        $this->actingAsStaff('manager');
        $rejected = $this->requestAsStaff(2000)->json('data.id');
        $this->postJson("/api/admin/early-withdrawals/{$rejected}/reject", ['reason' => 'Motif de refus détaillé'])->assertOk();
        $this->requestAsStaff(3000)->assertCreated();

        $this->getJson('/api/admin/early-withdrawals/stats')
            ->assertOk()
            ->assertJsonPath('data.requested.amount_minor', 10000)
            ->assertJsonPath('data.requested.count', 3)
            ->assertJsonPath('data.disbursed.amount_minor', 5000)
            ->assertJsonPath('data.approved.amount_minor', 5000)
            ->assertJsonPath('data.rejected.amount_minor', 2000)
            ->assertJsonPath('data.pending.amount_minor', 3000)
            ->assertJsonPath('data.failed.count', 0);
    }

    public function test_list_filters_by_status_campaign_and_period(): void
    {
        $this->actingAsStaff('manager');
        $this->requestAsStaff(1000)->assertCreated();
        $otherMembership = $this->membership($this->member());
        $this->creditSavings($otherMembership, 5000);
        $this->postJson('/api/admin/early-withdrawals', [
            'membership_id' => $otherMembership->id, 'amount_minor' => 2000, 'payout_channel' => 'cash',
            'idempotency_key' => (string) Str::uuid(),
        ])->assertCreated();

        $this->getJson('/api/admin/early-withdrawals?status=awaiting')->assertJsonPath('meta.total', 2);
        $this->getJson("/api/admin/early-withdrawals?campaign_id={$this->membership->campaign_id}")
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.available_minor', 20000);
        $this->getJson('/api/admin/early-withdrawals?from=' . now()->addDay()->toDateString())->assertJsonPath('meta.total', 0);
    }
}
