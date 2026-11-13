<?php

namespace Tests\Feature\LotAbis;

use App\Modules\Audit\Application\AuditService;
use App\Modules\Identity\Infrastructure\Models\OtpCode;
use App\Modules\Lending\Infrastructure\Models\LoanRequest;
use App\Modules\Payments\Infrastructure\Models\Payment;
use App\Modules\Tontine\Domain\Enums\InstallmentStatus;
use App\Modules\Tontine\Infrastructure\Models\EarlyWithdrawal;
use App\Modules\Tontine\Infrastructure\Models\Installment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\BuildsKafoFixtures;
use Tests\TestCase;

/**
 * Lot A-bis — lectures additives : décompte des échéances hors annulées (7), libellés
 * d'audit (8), indicateurs de campagne (9), compteurs par statut (11).
 */
class ReadModelsLotAbisTest extends TestCase
{
    use RefreshDatabase, BuildsKafoFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    // ── 7. Échéances annulées ───────────────────────────────────────────────

    public function test_member_counts_exclude_waived_installments_additively(): void
    {
        $member     = $this->member();
        $membership = $this->membership($member);
        $this->installments($membership, ['paid', 'paid', 'late', 'pending']);
        Installment::where('membership_id', $membership->id)->where('sequence_number', 3)
            ->update(['status' => InstallmentStatus::Waived->value]);

        Sanctum::actingAs($member);

        $campaign = $this->getJson('/api/member/dashboard')->assertOk()->json('data.campaigns.0');
        $this->assertSame(2, $campaign['paid']);
        $this->assertSame(4, $campaign['total'], 'champ historique inchangé (lu par Flutter)');
        $this->assertSame(3, $campaign['total_effective']);
        $this->assertSame(1, $campaign['waived']);

        $row = $this->getJson('/api/member/memberships')->assertOk()->json('data.0');
        $this->assertSame(4, $row['installments_total']);
        $this->assertSame(3, $row['installments_total_effective']);
        $this->assertSame(1, $row['installments_waived']);
        $this->assertSame(2, $row['installments_paid']);
    }

    // ── 8. Audit ─────────────────────────────────────────────────────────────

    public function test_audit_logs_expose_entity_labels_from_each_module(): void
    {
        $member     = $this->member(['full_name' => 'Awa Dossou']);
        $campaign   = $this->campaign(['name' => 'Marché Dantokpa']);
        $membership = $this->membership($member, $campaign);
        $payment    = Payment::create([
            'reference' => 'PAY-2026-000777', 'idempotency_key' => (string) Str::uuid(), 'membership_id' => $membership->id,
            'channel' => 'cash', 'status' => 'confirmed', 'amount_minor' => 5000,
        ]);
        $request = LoanRequest::create([
            'membership_id' => $membership->id, 'amount_minor' => 50000, 'status' => 'pending',
            'product_type' => 'advance', 'campaign_id' => $campaign->id,
        ]);
        $withdrawal = EarlyWithdrawal::create([
            'reference' => 'WDR-2026-000003', 'idempotency_key' => (string) Str::uuid(), 'membership_id' => $membership->id,
            'user_id' => $member->id, 'amount_minor' => 3000, 'payout_channel' => 'cash', 'status' => 'pending',
            'requested_via' => 'member', 'requested_by' => $member->id,
        ]);

        $audit = app(AuditService::class);
        $audit->log('member.update', 'user', $member->id);
        $audit->log('campaign.status_changed', 'tontine_campaign', $campaign->id);
        $audit->log('membership.suspend', 'membership', $membership->id);
        $audit->log('payment.cancel', 'payment', $payment->id);
        $audit->log('loan_request.reject', 'loan_request', $request->id);
        $audit->log('early_withdrawal.approve', 'early_withdrawal', $withdrawal->id);
        $audit->log('settings.update');
        $audit->log('payment.cancel', 'payment', (string) Str::uuid()); // entité disparue

        $this->actingAsStaff('admin');
        $rows = collect($this->getJson('/api/admin/audit-logs')->assertOk()->json('data'));
        $label = fn (string $type, ?string $id) => $rows->first(fn ($r) => $r['entity_type'] === $type && $r['entity_id'] === $id)['entity_label'];

        $this->assertSame('Awa Dossou', $label('user', $member->id));
        $this->assertSame('Marché Dantokpa', $label('tontine_campaign', $campaign->id));
        $this->assertSame('Adhésion — Awa Dossou · Marché Dantokpa', $label('membership', $membership->id));
        $this->assertSame('PAY-2026-000777 — Awa Dossou', $label('payment', $payment->id));
        $this->assertStringStartsWith("Demande d'avance de 50", $label('loan_request', $request->id));
        $this->assertStringEndsWith('— Awa Dossou', $label('loan_request', $request->id));
        $this->assertSame('Retrait WDR-2026-000003 — Awa Dossou', $label('early_withdrawal', $withdrawal->id));
        $this->assertNull($rows->firstWhere('action', 'settings.update')['entity_label']);
        $this->assertNull($rows->first(fn ($r) => $r['entity_type'] === 'payment' && $r['entity_id'] !== $payment->id)['entity_label']);

        // Détail : même libellé
        $id = $rows->firstWhere('entity_type', 'membership')['id'];
        $this->getJson("/api/admin/audit-logs/{$id}")->assertOk()
            ->assertJsonPath('data.entity_label', 'Adhésion — Awa Dossou · Marché Dantokpa');
    }

    public function test_staff_login_is_audited_with_its_author(): void
    {
        $staff = $this->staff('treasurer');
        $this->postJson('/api/admin/auth/otp/request', ['phone' => $staff->phone])->assertOk();
        $code = $this->knownOtp($staff->phone, 'login');
        $this->postJson('/api/admin/auth/login', ['phone' => $staff->phone, 'otp' => $code, 'pin' => '123456'])->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'auth.login_success', 'user_id' => $staff->id, 'user_name' => $staff->full_name,
        ]);

        // Entrée antérieure sans auteur : nom repris à la lecture (l'auteur est l'entité).
        app(AuditService::class)->log('auth.login_success', 'user', $staff->id);
        $this->actingAsStaff('admin');
        $rows = collect($this->getJson('/api/admin/audit-logs?action=auth.login_success')->json('data'));
        $this->assertCount(2, $rows);
        $this->assertTrue($rows->every(fn ($r) => $r['user_name'] === $staff->full_name));
        $this->assertSame(0, OtpCode::whereNull('consumed_at')->where('phone', $staff->phone)->count());
    }

    // ── 9. Indicateurs de campagne ──────────────────────────────────────────

    public function test_campaign_financials_expose_expected_amount_and_late_counts(): void
    {
        $campaign = $this->campaign();
        $a = $this->membership($this->member(), $campaign);  // 5000 par échéance
        $b = $this->membership($this->member(), $campaign);
        // a : 3 échues (paid, late, waived) + 1 à venir ; b : 2 échues pending (grâce dépassée) + 1 à venir
        $this->installments($a, ['paid', 'late', 'paid', 'pending']);
        Installment::where('membership_id', $a->id)->where('sequence_number', 3)->update(['status' => 'waived']);
        $this->installments($b, ['pending', 'pending', 'pending']);
        Installment::where('membership_id', $b->id)->whereIn('sequence_number', [1, 2])->update([
            'due_date' => now()->subDays(10)->toDateString(), 'grace_period_ends_date' => now()->subDays(7)->toDateString(),
        ]);

        $this->actingAsStaff('manager');
        $f = $this->getJson("/api/admin/campaigns/{$campaign->id}")->assertOk()->json('data.financials');

        // Échues hors annulée : a(paid, late) + b(2 pending) = 4 × 5000
        $this->assertSame(20000, $f['expected_minor']);
        $this->assertSame(1, $f['late_installments_count']);
        $this->assertSame(2, $f['overdue_unmarked_installments_count']);
        $this->assertArrayHasKey('total_collected_minor', $f, 'champs existants conservés');
    }

    // ── 11. Compteurs par statut ────────────────────────────────────────────

    public function test_loan_request_counts_by_status(): void
    {
        $membership = $this->membership($this->member(['full_name' => 'Koffi Agbo']));
        $other      = $this->membership($this->member(['full_name' => 'Rita Houngbo']));
        foreach (['pending', 'pending', 'approved', 'rejected'] as $status) {
            LoanRequest::create(['membership_id' => $membership->id, 'amount_minor' => 10000, 'status' => $status, 'product_type' => 'advance']);
        }
        LoanRequest::create(['membership_id' => $other->id, 'amount_minor' => 10000, 'status' => 'pending', 'product_type' => 'advance']);

        $this->actingAsStaff('treasurer'); // loan.view
        $data = $this->getJson('/api/admin/loan-requests/counts')->assertOk()->json('data');
        $this->assertSame(3, $data['by_status']['pending']);
        $this->assertSame(1, $data['by_status']['approved']);
        $this->assertSame(0, $data['by_status']['countersigned']);
        $this->assertSame(5, $data['total']);

        $this->assertSame(2, $this->getJson('/api/admin/loan-requests/counts?search=Koffi')->json('data.by_status.pending'));
        // Cohérent avec la liste
        $this->assertSame(3, $this->getJson('/api/admin/loan-requests?status=pending')->json('meta.total'));

        Sanctum::actingAs($this->member());
        $this->getJson('/api/admin/loan-requests/counts')->assertForbidden();
    }

    public function test_early_withdrawal_counts_by_status(): void
    {
        $owner      = $this->member();
        $membership = $this->membership($owner);
        foreach (['pending', 'under_review', 'info_requested', 'approved', 'payout_failed', 'rejected'] as $i => $status) {
            EarlyWithdrawal::create([
                'reference' => "WDR-T-{$i}", 'idempotency_key' => (string) Str::uuid(), 'membership_id' => $membership->id,
                'user_id' => $owner->id, 'amount_minor' => 1000, 'payout_channel' => 'cash', 'status' => $status,
                'requested_via' => 'member', 'requested_by' => $owner->id,
            ]);
        }

        $this->actingAsStaff('treasurer'); // withdrawal.view
        $data = $this->getJson('/api/admin/early-withdrawals/counts')->assertOk()->json('data');
        $this->assertSame(3, $data['awaiting']);
        $this->assertSame(1, $data['by_status']['approved']);
        $this->assertSame(1, $data['by_status']['payout_failed']);
        $this->assertSame(0, $data['by_status']['payout_processing']);
        $this->assertSame(6, $data['total']);
        $this->assertSame($data['awaiting'], $this->getJson('/api/admin/early-withdrawals?status=awaiting')->json('meta.total'));

        $this->assertSame(0, $this->getJson('/api/admin/early-withdrawals/counts?from=2000-01-01&to=2000-01-02')->json('data.total'));
        $this->getJson('/api/admin/early-withdrawals/counts?campaign_id=pas-un-uuid')->assertStatus(422);
    }
}
