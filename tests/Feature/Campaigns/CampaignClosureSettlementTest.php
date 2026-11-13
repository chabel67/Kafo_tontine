<?php

namespace Tests\Feature\Campaigns;

use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Ledger\Application\LedgerService;
use App\Modules\Ledger\Infrastructure\Models\LedgerTransaction;
use App\Modules\Lending\Infrastructure\Models\LoanRepayment;
use App\Modules\Lending\Infrastructure\Models\LoanRequest;
use App\Modules\Notifications\Infrastructure\Models\InAppNotification;
use App\Modules\Tontine\Application\CampaignClosureService;
use App\Modules\Tontine\Application\EarlyWithdrawalService;
use App\Modules\Tontine\Domain\Exceptions\CampaignAlreadyClosedException;
use App\Modules\Tontine\Infrastructure\Models\Campaign;
use App\Modules\Tontine\Infrastructure\Models\CampaignPayout;
use App\Modules\Tontine\Infrastructure\Models\EarlyWithdrawal;
use App\Modules\Tontine\Infrastructure\Models\Membership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\BuildsKafoFixtures;
use Tests\TestCase;

/**
 * Clôture (temps 1) et règlement individuel (temps 2) — R-CAMP-08 à R-CAMP-16, R-LOAN-15/16.
 */
class CampaignClosureSettlementTest extends TestCase
{
    use RefreshDatabase, BuildsKafoFixtures;

    private Campaign $campaign;
    private User $owner;
    private Membership $membership;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();

        $this->campaign   = $this->campaign();
        $this->owner      = $this->member();
        $this->membership = $this->membership($this->owner, $this->campaign);
        $this->admin      = $this->staff('admin');
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    private function close(): \Illuminate\Testing\TestResponse
    {
        Sanctum::actingAs($this->admin);

        return $this->postJson("/api/admin/campaigns/{$this->campaign->id}/close",
            ['reason' => 'Fin du cycle de cotisation annuel.'], $this->stepUpHeader($this->admin));
    }

    private function payout(?Membership $membership = null): CampaignPayout
    {
        return CampaignPayout::where('membership_id', ($membership ?? $this->membership)->id)->firstOrFail();
    }

    private function settle(CampaignPayout $payout, array $extra = []): \Illuminate\Testing\TestResponse
    {
        Sanctum::actingAs($this->admin);

        return $this->postJson("/api/admin/campaign-payouts/{$payout->id}/settle", [
            'channel' => 'cash', 'idempotency_key' => (string) Str::uuid(), ...$extra,
        ], $this->stepUpHeader($this->admin));
    }

    private function withdrawal(string $status, int $amount): EarlyWithdrawal
    {
        return EarlyWithdrawal::create([
            'reference' => 'WDR-T-' . Str::random(6), 'idempotency_key' => (string) Str::uuid(),
            'membership_id' => $this->membership->id, 'user_id' => $this->owner->id,
            'amount_minor' => $amount, 'payout_channel' => 'cash', 'status' => $status,
            'requested_via' => 'staff', 'requested_by' => $this->admin->id,
        ]);
    }

    // ── Temps 1 : clôture ──────────────────────────────────────────────────

    public function test_closure_creates_payouts_for_active_memberships_only(): void
    {
        $this->creditSavings($this->membership, 30000);
        $suspended = $this->membership($this->member(), $this->campaign, ['status' => 'suspended']);
        $this->creditSavings($suspended, 10000);

        $this->close()->assertOk()->assertJsonPath('data.payouts_count', 1);

        $this->assertSame('closed', $this->campaign->fresh()->status->value);
        $this->assertSame(30000, $this->payout()->net_amount_minor);
        $this->assertFalse(CampaignPayout::where('membership_id', $suspended->id)->exists());
    }

    public function test_concurrent_closure_is_refused(): void
    {
        $this->close()->assertOk();

        $this->expectException(CampaignAlreadyClosedException::class);
        app(CampaignClosureService::class)->close($this->campaign->fresh(), $this->admin, 'second');
    }

    public function test_unpaid_withdrawals_are_rejected_silently_and_return_to_the_payout(): void
    {
        $this->creditSavings($this->membership, 50000);
        $pending  = $this->withdrawal('pending', 5000);
        $approved = $this->withdrawal('approved', 7000);
        $failed   = $this->withdrawal('payout_failed', 3000);
        $done     = $this->withdrawal('disbursed', 1000);
        $notificationsBefore = InAppNotification::where('user_id', $this->owner->id)->count();

        $this->close()->assertOk()->assertJsonPath('data.rejected_withdrawals_count', 3);

        foreach ([$pending, $approved, $failed] as $w) {
            $this->assertSame('rejected', $w->fresh()->status->value);
            $this->assertSame(EarlyWithdrawalService::CLOSURE_REASON, $w->fresh()->rejected_reason);
        }
        $this->assertSame('disbursed', $done->fresh()->status->value);
        $this->assertSame($notificationsBefore, InAppNotification::where('user_id', $this->owner->id)->count(), 'D2 : aucune notification');
        // Les montants rejetés restent dans l'épargne : tout va au règlement.
        $this->assertSame(50000, $this->payout()->net_amount_minor);
    }

    public function test_withdrawal_in_payout_freezes_settlement_until_confirmed(): void
    {
        $this->creditSavings($this->membership, 40000);
        Sanctum::actingAs($this->staff('manager'));
        $id = $this->postJson('/api/admin/early-withdrawals', [
            'membership_id' => $this->membership->id, 'amount_minor' => 10000, 'payout_channel' => 'cash',
            'idempotency_key' => (string) Str::uuid(),
        ])->assertCreated()->json('data.id');
        $this->postJson("/api/admin/early-withdrawals/{$id}/approve")->assertOk();
        Sanctum::actingAs($this->admin);
        $this->postJson("/api/admin/early-withdrawals/{$id}/payout/initiate", [
            'channel' => 'cash', 'idempotency_key' => (string) Str::uuid(),
        ], $this->stepUpHeader($this->admin))->assertOk();

        $this->close()->assertOk()->assertJsonPath('data.rejected_withdrawals_count', 0);
        $payout = $this->payout();
        $this->assertSame(30000, $payout->net_amount_minor, 'prévisionnel : retrait en cours déduit');

        $this->settle($payout)->assertStatus(409)->assertJsonPath('error.code', 'withdrawal_payout_in_progress');

        // L'argent a été remis : la confirmation reste possible après la clôture.
        Sanctum::actingAs($this->admin);
        $this->postJson("/api/admin/early-withdrawals/{$id}/payout/confirm", [])->assertOk();

        $this->settle($payout)->assertOk()->assertJsonPath('data.net_amount_minor', 30000);
        $this->assertSame(0, $this->savingsBalance($this->membership));
        $this->assertLedgerBalanced();
    }

    public function test_failed_payout_after_closure_is_rejected_and_amount_goes_to_settlement(): void
    {
        $this->creditSavings($this->membership, 20000);
        $w = $this->withdrawal('approved', 8000);
        $w->update(['decided_by' => $this->staff('manager')->id]);
        app(EarlyWithdrawalService::class)->initiatePayout($w, 'cash', null, (string) Str::uuid(), $this->admin);

        $this->close()->assertOk();
        app(EarlyWithdrawalService::class)->failPayout($w->fresh(), 'Membre absent au rendez-vous.', $this->admin);

        $this->assertSame('rejected', $w->fresh()->status->value);
        $this->settle($this->payout())->assertOk()->assertJsonPath('data.net_amount_minor', 20000);
    }

    public function test_no_new_withdrawal_or_advance_after_closure(): void
    {
        $this->creditSavings($this->membership, 20000);
        $this->close()->assertOk();

        Sanctum::actingAs($this->owner);
        $this->postJson('/api/member/early-withdrawals', [
            'membership_id' => $this->membership->id, 'amount_minor' => 1000, 'payout_channel' => 'cash',
            'idempotency_key' => (string) Str::uuid(),
        ])->assertStatus(422)->assertJsonPath('error.code', 'campaign_not_active');

        $this->postJson('/api/member/loan-requests', [
            'membership_id' => $this->membership->id, 'amount_minor' => 1000,
        ])->assertStatus(422)->assertJsonPath('error.code', 'campaign_not_active');
    }

    public function test_only_advance_requests_are_rejected_community_loans_are_untouched(): void
    {
        $this->creditSavings($this->membership, 100000);
        $advanceRequest = $this->advanceRequest($this->membership, 20000);
        $loan = $this->standardLoan($this->owner, 60000);
        $standardRequest = LoanRequest::create([
            'user_id' => $this->owner->id, 'product_type' => 'standard', 'amount_minor' => 30000,
            'status' => 'pending', 'campaign_id' => $this->campaign->id, 'periodicity' => 'monthly',
            'installments_count' => 2, 'first_due_date' => now()->addMonth()->toDateString(),
        ]);

        $this->close()->assertOk()->assertJsonPath('data.cancelled_advances_count', 1);

        $this->assertSame('rejected', $advanceRequest->fresh()->status->value);
        $this->assertSame('pending', $standardRequest->fresh()->status->value, 'R-LOAN-16 : demande de prêt communautaire intacte');
        $this->assertSame('active', $loan->fresh()->status->value);
        // Un prêt actif ne bloque pas la clôture (R-CAMP-03 supprimée) et n'est jamais déduit.
        $this->assertSame(100000, $this->payout()->net_amount_minor);
    }

    // ── Temps 2 : règlement ────────────────────────────────────────────────

    public function test_preview_matches_settlement_and_advance_is_compensated(): void
    {
        $this->creditSavings($this->membership, 150000);
        $this->advance($this->membership, 20000);

        Sanctum::actingAs($this->admin);
        $preview = $this->getJson("/api/admin/campaigns/{$this->campaign->id}/close/preview")->assertOk()->json('data.payouts.0');
        $this->assertSame(130000, $preview['net_amount_minor']);
        $this->assertSame(20000, $preview['advance_offset_minor']);

        $this->close()->assertOk();
        $this->settle($this->payout())->assertOk()
            ->assertJsonPath('data.net_amount_minor', $preview['net_amount_minor'])
            ->assertJsonPath('data.advance_offset_minor', 20000);

        $this->assertSame(0, $this->savingsBalance($this->membership));
        $this->assertSame('closed', $this->membership->fresh()->status->value);
        $this->assertLedgerBalanced();
    }

    public function test_shortfall_goes_to_expense_loss(): void
    {
        $this->creditSavings($this->membership, 10000);
        $advance = $this->advance($this->membership, 25000);   // D1 : dépassement accepté par le staff

        $this->close()->assertOk();
        $this->settle($this->payout())->assertOk()->assertJsonPath('data.net_amount_minor', 0);

        $this->assertSame(15000, app(LedgerService::class)->balance('EXPENSE_LOSS'), 'charge positive (solde débiteur)');
        $this->assertSame(0, app(LedgerService::class)->balance("LOAN_RECEIVABLE:{$advance->id}"));
        $this->assertSame('closed_by_campaign', $advance->fresh()->status->value);
        $this->assertLedgerBalanced();
    }

    public function test_double_settlement_is_impossible_and_replay_is_idempotent(): void
    {
        $this->creditSavings($this->membership, 30000);
        $this->close()->assertOk();
        $payout = $this->payout();
        $key = (string) Str::uuid();

        $this->settle($payout, ['idempotency_key' => $key])->assertOk();
        $this->settle($payout, ['idempotency_key' => $key])->assertOk()->assertJsonPath('data.status', 'settled');
        $this->settle($payout)->assertStatus(409)->assertJsonPath('error.code', 'payout_not_pending');

        $this->assertSame(1, LedgerTransaction::where('description', 'like', 'Payout de clôture%')->count());
        $this->assertSame(0, $this->savingsBalance($this->membership));
    }

    public function test_failed_settlement_rolls_back_and_can_be_retried(): void
    {
        $this->creditSavings($this->membership, 30000);
        $loan = $this->standardLoan($this->owner, 20000);
        $this->close()->assertOk();
        $payout = $this->payout();

        // Échec : remboursement supérieur à la dette → rien n'est écrit, payout toujours en attente.
        $this->settle($payout, ['loan_repayment' => ['loan_id' => $loan->id, 'amount_minor' => 25000]])
            ->assertStatus(422)->assertJsonPath('error.code', 'repayment_exceeds_due');
        $this->assertSame('pending', $payout->fresh()->status->value);
        $this->assertSame(30000, $this->savingsBalance($this->membership));
        $this->assertSame(0, LoanRepayment::count());

        $this->settle($payout)->assertOk();
    }

    // ── Remboursement volontaire d'un prêt communautaire (R-CAMP-14) ──────

    public function test_voluntary_repayment_example_from_the_specification(): void
    {
        $this->creditSavings($this->membership, 150000);
        $this->advance($this->membership, 20000);
        $loan = $this->standardLoan($this->owner, 200000);
        $this->close()->assertOk();
        $payout = $this->payout();

        Sanctum::actingAs($this->admin);
        $this->getJson("/api/admin/campaign-payouts/{$payout->id}")->assertOk()
            ->assertJsonPath('data.settlement.live.net_amount_minor', 130000)
            ->assertJsonPath('data.settlement.standard_loans.0.id', $loan->id)
            ->assertJsonPath('data.settlement.standard_loans.0.total_due_minor', 200000);

        $this->settle($payout, ['loan_repayment' => ['loan_id' => $loan->id, 'amount_minor' => 50000]])->assertOk()
            ->assertJsonPath('data.net_amount_minor', 130000)
            ->assertJsonPath('data.loan_repayment_minor', 50000)
            ->assertJsonPath('data.paid_out_minor', 80000);

        $this->assertSame(150000, $loan->fresh()->outstanding_minor, 'le membre reste débiteur du solde');
        $this->assertSame('active', $loan->fresh()->status->value, 'jamais soldé automatiquement');
        $this->assertSame(150000, app(LedgerService::class)->balance("LOAN_RECEIVABLE:{$loan->id}"));
        $repayment = LoanRepayment::where('loan_id', $loan->id)->sole();
        $this->assertSame('campaign_payout', $repayment->channel);
        $this->assertSame($payout->id, $repayment->campaign_payout_id);
        $this->assertSame(0, $this->savingsBalance($this->membership));
        $this->assertLedgerBalanced();
    }

    public function test_repayment_equal_to_debt_settles_the_loan(): void
    {
        $this->creditSavings($this->membership, 50000);
        $loan = $this->standardLoan($this->owner, 30000);
        $this->close()->assertOk();

        $this->settle($this->payout(), ['loan_repayment' => ['loan_id' => $loan->id, 'amount_minor' => 30000]])
            ->assertOk()->assertJsonPath('data.paid_out_minor', 20000);

        $this->assertSame('repaid', $loan->fresh()->status->value);
        $this->assertSame(0, $loan->fresh()->outstanding_minor);
    }

    public function test_repayment_above_net_is_refused(): void
    {
        $this->creditSavings($this->membership, 10000);
        $loan = $this->standardLoan($this->owner, 50000);
        $this->close()->assertOk();

        $this->settle($this->payout(), ['loan_repayment' => ['loan_id' => $loan->id, 'amount_minor' => 15000]])
            ->assertStatus(422)->assertJsonPath('error.code', 'invalid_loan_repayment');
    }

    public function test_repayment_of_someone_elses_loan_is_refused(): void
    {
        $this->creditSavings($this->membership, 50000);
        $otherLoan = $this->standardLoan($this->member(), 20000);
        $this->close()->assertOk();

        $this->settle($this->payout(), ['loan_repayment' => ['loan_id' => $otherLoan->id, 'amount_minor' => 5000]])
            ->assertStatus(422);
        $this->assertSame(20000, $otherLoan->fresh()->outstanding_minor);
    }

    public function test_several_loans_only_the_selected_one_is_repaid(): void
    {
        $this->creditSavings($this->membership, 60000);
        $first  = $this->standardLoan($this->owner, 40000);
        $second = $this->standardLoan($this->owner, 25000);
        $this->close()->assertOk();

        $this->settle($this->payout(), ['loan_repayment' => ['loan_id' => $second->id, 'amount_minor' => 10000]])->assertOk();

        $this->assertSame(40000, $first->fresh()->outstanding_minor);
        $this->assertSame(15000, $second->fresh()->outstanding_minor);
    }

    public function test_without_repayment_the_loan_is_unchanged_and_still_repayable_after_closure(): void
    {
        $this->creditSavings($this->membership, 40000);
        $loan = $this->standardLoan($this->owner, 30000);
        $this->close()->assertOk();
        $this->settle($this->payout())->assertOk()->assertJsonPath('data.paid_out_minor', 40000);

        $this->assertSame(30000, $loan->fresh()->outstanding_minor);

        Sanctum::actingAs($this->admin);
        $this->postJson("/api/admin/loans/{$loan->id}/repay", ['amount_minor' => 10000, 'channel' => 'cash'])
            ->assertCreated()->assertJsonPath('data.outstanding_minor', 20000);
        $this->assertLedgerBalanced();
    }
}
