<?php

namespace Tests\Feature\Lending;

use App\Modules\Audit\Infrastructure\Models\AuditLog;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Ledger\Application\LedgerService;
use App\Modules\Lending\Application\LendingService;
use App\Modules\Lending\Infrastructure\Models\Loan;
use App\Modules\Lending\Infrastructure\Models\LoanRepayment;
use App\Modules\Payments\Application\PaymentService;
use App\Modules\Payments\Infrastructure\Models\Payment;
use App\Modules\Ledger\Infrastructure\Models\LedgerTransaction;
use App\Modules\Tontine\Infrastructure\Models\Membership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\BuildsKafoFixtures;
use Tests\TestCase;

/**
 * R-SAV-01 (une même épargne ne sert jamais deux fois), avances (D1 : dépassement signalé,
 * jamais bloquant), prêts communautaires indépendants (R-LOAN-16), concurrence et idempotence.
 */
class SavingsReservationAndLoansTest extends TestCase
{
    use RefreshDatabase, BuildsKafoFixtures;

    private User $owner;
    private Membership $membership;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        $this->owner      = $this->member();
        $this->membership = $this->membership($this->owner);
        $this->creditSavings($this->membership, 100000);
    }

    private function withdrawable(): int
    {
        Sanctum::actingAs($this->owner);

        return $this->getJson("/api/member/memberships/{$this->membership->id}/withdrawable")->assertOk()->json('data.available_minor');
    }

    private function requestWithdrawal(int $amount)
    {
        Sanctum::actingAs($this->owner);

        return $this->postJson('/api/member/early-withdrawals', [
            'membership_id' => $this->membership->id, 'amount_minor' => $amount, 'payout_channel' => 'cash',
            'idempotency_key' => (string) Str::uuid(),
        ]);
    }

    // ── Réservations croisées ─────────────────────────────────────────────

    public function test_example_from_the_specification_withdrawal_and_advance_reserve_savings(): void
    {
        $this->requestWithdrawal(30000)->assertCreated();
        $this->advance($this->membership, 20000);

        $this->assertSame(50000, $this->withdrawable());
        $this->requestWithdrawal(50001)->assertStatus(422)->assertJsonPath('error.code', 'withdrawal_exceeds_available');
        $this->requestWithdrawal(50000)->assertCreated();
        $this->assertSame(0, $this->withdrawable());
    }

    public function test_pending_advance_request_reserves_savings_and_releases_when_rejected(): void
    {
        $request = $this->advanceRequest($this->membership, 40000);
        $this->assertSame(60000, $this->withdrawable());

        app(LendingService::class)->reject($request, $this->staff('manager')->id, 'Demande non justifiée.');
        $this->assertSame(100000, $this->withdrawable());
    }

    public function test_advance_snapshot_reflects_withdrawals_in_progress(): void
    {
        $this->requestWithdrawal(70000)->assertCreated();
        $request = $this->advanceRequest($this->membership, 50000);

        $this->assertSame(30000, $request->eligibility_snapshot['available_minor']);
        $this->assertSame(70000, $request->eligibility_snapshot['committed_withdrawals_minor']);
        $this->assertSame(20000, $request->eligibility_snapshot['exceeds_available_minor']);
    }

    public function test_approval_rechecks_the_available_amount(): void
    {
        $id = $this->requestWithdrawal(60000)->assertCreated()->json('data.id');
        $this->advance($this->membership, 50000);   // accordée par le staff malgré la réservation (D1)

        $this->actingAsStaff('manager');
        $this->postJson("/api/admin/early-withdrawals/{$id}/approve")
            ->assertStatus(422)->assertJsonPath('error.code', 'withdrawal_exceeds_available');
    }

    public function test_respond_is_only_possible_after_an_information_request(): void
    {
        $id = $this->requestWithdrawal(1000)->assertCreated()->json('data.id');
        Sanctum::actingAs($this->owner);

        $this->postJson("/api/member/early-withdrawals/{$id}/respond", ['response' => 'Voici les justificatifs.'])
            ->assertStatus(422)->assertJsonPath('error.code', 'invalid_withdrawal_transition');
    }

    // ── Avances : décision du staff (D1) ──────────────────────────────────

    public function test_advance_above_available_is_accepted_flagged_and_audited(): void
    {
        $this->requestWithdrawal(90000)->assertCreated();
        $request = $this->advanceRequest($this->membership, 30000);

        $admin = $this->actingAsStaff('admin');
        $this->postJson("/api/admin/loan-requests/{$request->id}/approve")->assertOk()
            ->assertJsonPath('data.eligibility_snapshot.exceeds_available_minor', 20000);

        $audit = AuditLog::where('action', 'loan_request.approve')->sole();
        $this->assertSame(20000, $audit->new_values['exceeds_available_minor']);

        $this->postJson("/api/admin/loan-requests/{$request->id}/disburse", ['channel' => 'cash'], $this->stepUpHeader($admin))
            ->assertCreated();
    }

    public function test_double_disbursement_is_impossible(): void
    {
        $request = $this->advanceRequest($this->membership, 10000);
        $admin = $this->actingAsStaff('admin');
        $this->postJson("/api/admin/loan-requests/{$request->id}/approve")->assertOk();

        $this->postJson("/api/admin/loan-requests/{$request->id}/disburse", ['channel' => 'cash'], $this->stepUpHeader($admin))->assertCreated();
        $this->postJson("/api/admin/loan-requests/{$request->id}/disburse", ['channel' => 'cash'], $this->stepUpHeader($admin))
            ->assertStatus(422)->assertJsonPath('error.code', 'invalid_loan_request_transition');

        $this->assertSame(1, Loan::count());
    }

    public function test_advance_never_debits_savings_and_is_distinct_from_a_community_loan(): void
    {
        $advance = $this->advance($this->membership, 10000);
        $loan    = $this->standardLoan($this->owner, 50000);

        $this->assertSame(100000, $this->savingsBalance($this->membership));
        $this->assertSame('advance', $advance->product_type->value);
        $this->assertSame($this->membership->id, $advance->membership_id);
        $this->assertSame('standard', $loan->product_type->value);
        $this->assertNull($loan->membership_id, 'R-LOAN-16 : rattaché au membre, pas à une adhésion');
        $this->assertSame($this->owner->id, $loan->user_id);
        $this->assertSame(90000, $this->withdrawable(), 'seule l\'avance réserve l\'épargne');
    }

    // ── Prêts communautaires ──────────────────────────────────────────────

    public function test_interest_is_paid_first_and_loan_is_repaid_only_when_total_due_is_zero(): void
    {
        $loan = $this->standardLoan($this->owner, 100000, 1200, 2);   // 12 %/an sur ~2 mois
        $due  = $loan->fresh()->totalDueMinor();
        $interest = $due - 100000;
        $this->assertGreaterThan(0, $interest);

        $admin = $this->actingAsStaff('admin');
        $this->postJson("/api/admin/loans/{$loan->id}/repay", ['amount_minor' => $due + 1, 'channel' => 'cash'])
            ->assertStatus(422)->assertJsonPath('error.code', 'repayment_exceeds_due');

        $this->postJson("/api/admin/loans/{$loan->id}/repay", ['amount_minor' => 100000, 'channel' => 'cash'])->assertCreated()
            ->assertJsonPath('data.loan_status', 'active')
            ->assertJsonPath('data.total_due_minor', $interest);

        $this->postJson("/api/admin/loans/{$loan->id}/repay", ['amount_minor' => $interest, 'channel' => 'cash'])->assertCreated()
            ->assertJsonPath('data.loan_status', 'repaid');

        $this->assertSame($interest, app(LedgerService::class)->balance('REVENUE_INTEREST'));
        $this->assertSame(0, app(LedgerService::class)->balance("LOAN_RECEIVABLE:{$loan->id}"));
        $this->assertLedgerBalanced();
    }

    public function test_repayment_is_idempotent(): void
    {
        $loan = $this->standardLoan($this->owner, 20000);
        $this->actingAsStaff('admin');
        $key = (string) Str::uuid();

        $this->postJson("/api/admin/loans/{$loan->id}/repay", ['amount_minor' => 5000, 'channel' => 'cash', 'idempotency_key' => $key])->assertCreated();
        $this->postJson("/api/admin/loans/{$loan->id}/repay", ['amount_minor' => 5000, 'channel' => 'cash', 'idempotency_key' => $key])->assertCreated();

        $this->assertSame(1, LoanRepayment::count());
        $this->assertSame(15000, $loan->fresh()->outstanding_minor);
    }

    public function test_standard_loan_created_for_a_member_without_membership(): void
    {
        $user = $this->member();
        $admin = $this->actingAsStaff('admin');

        $this->postJson('/api/admin/loan-requests', [
            'product_type' => 'standard', 'user_id' => $user->id, 'amount_minor' => 40000,
            'periodicity' => 'monthly', 'installments_count' => 4, 'first_due_date' => now()->addMonth()->toDateString(),
        ])->assertCreated()
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonPath('data.campaign_id', null);
    }

    public function test_write_off_of_a_community_loan_without_membership(): void
    {
        $loan = $this->standardLoan($this->owner, 20000);
        $admin = $this->actingAsStaff('super_admin');

        $this->postJson("/api/admin/loans/{$loan->id}/write-off", ['reason' => 'Membre parti sans laisser d\'adresse, recouvrement impossible.'], $this->stepUpHeader($admin))
            ->assertOk();
        $this->assertSame('written_off', $loan->fresh()->status->value);
        $this->assertSame('active', $this->membership->fresh()->status->value, 'aucune adhésion suspendue');
    }

    // ── Webhook ────────────────────────────────────────────────────────────

    public function test_duplicate_webhook_confirms_and_posts_only_once(): void
    {
        $payment = Payment::create([
            'reference' => 'PAY-WH-1', 'idempotency_key' => (string) Str::uuid(), 'membership_id' => $this->membership->id,
            'channel' => 'mtn', 'status' => 'pending', 'amount_minor' => 5000,
        ]);
        $service = app(PaymentService::class);
        $before  = LedgerTransaction::count();

        $service->confirmFromWebhook($payment->idempotency_key, 'PSP-1', 'mtn');
        $service->confirmFromWebhook($payment->idempotency_key, 'PSP-1', 'mtn');

        $this->assertSame($before + 1, LedgerTransaction::count());
        $this->assertSame(105000, $this->savingsBalance($this->membership));
        $this->assertSame(5000, app(LedgerService::class)->balance('MOMO_FLOAT:MTN'));
    }
}
