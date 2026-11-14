<?php

namespace Tests\Feature\Reporting;

use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Ledger\Application\LedgerService;
use App\Modules\Ledger\Domain\Enums\AccountType;
use App\Modules\Lending\Infrastructure\Models\Loan;
use App\Modules\Lending\Infrastructure\Models\LoanRequest;
use App\Modules\Payments\Infrastructure\Models\Payment;
use App\Modules\Tontine\Infrastructure\Models\Membership;
use App\Shared\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\BuildsKafoFixtures;
use Tests\TestCase;

/**
 * Lot A — champs additifs pour les fronts : work-queue structurée, épargne
 * disponible du dashboard membre, libellés de comptes ledger, indicateurs
 * financiers de campagne, stats du jour en fuseau métier.
 */
class FrontReadModelsTest extends TestCase
{
    use RefreshDatabase, BuildsKafoFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    private function confirmedCash(Membership $membership, int $amount, ?Carbon $at = null): Payment
    {
        return Payment::create([
            'reference'       => 'PAY-TEST-' . Str::random(6),
            'idempotency_key' => (string) Str::uuid(),
            'membership_id'   => $membership->id,
            'channel'         => 'cash',
            'status'          => 'confirmed',
            'amount_minor'    => $amount,
            'confirmed_at'    => $at ?? now(),
        ]);
    }

    // ── 6a) work-queue ──────────────────────────────────────────────────────

    public function test_work_queue_items_expose_structured_fields(): void
    {
        $this->actingAsStaff('manager');
        $campaign = $this->campaign(['name' => 'Marché Dantokpa']);
        $alice    = $this->member(['full_name' => 'Alice Houngbo']);
        $pending  = $this->membership($this->member(['full_name' => 'Bruno Pending']), $campaign, ['status' => 'pending_l1', 'activated_at' => null]);
        $active   = $this->membership($alice, $campaign);

        LoanRequest::create([
            'membership_id' => $active->id,
            'campaign_id'   => $campaign->id,
            'amount_minor'  => 120000,
            'status'        => 'pending',
            'product_type'  => 'advance',
        ]);

        $items = collect($this->getJson('/api/admin/stats/work-queue')->assertOk()->json('data'));

        $loan = $items->firstWhere('type', 'loan_request');
        $this->assertSame(120000, $loan['amount_minor']);
        $this->assertSame('Marché Dantokpa', $loan['campaign_name']);
        $this->assertSame('pending', $loan['status_code']);
        $this->assertSame('Alice Houngbo', $loan['member_name']);
        $this->assertSame('120' . Money::NBSP . '000' . Money::NBSP . 'FCFA — Marché Dantokpa', $loan['sub'], 'sub au format Kafo');
        $this->assertArrayHasKey('status', $loan, 'libellé historique conservé');

        $membership = $items->firstWhere('id', $pending->id);
        $this->assertNull($membership['amount_minor']);
        $this->assertSame('pending_l1', $membership['status_code']);
        $this->assertSame('Bruno Pending', $membership['member_name']);
        $this->assertSame('Marché Dantokpa', $membership['campaign_name']);
    }

    // ── 6b) dashboard membre ────────────────────────────────────────────────

    public function test_member_dashboard_exposes_ledger_savings_next_to_paid_total(): void
    {
        $member = $this->member();
        $member->roles()->attach(Role::where('name', 'member')->value('id'));
        $m1 = $this->membership($member);
        $m2 = $this->membership($member);

        // Paiements confirmés (total payé) ≠ solde ledger (épargne réellement disponible).
        $this->confirmedCash($m1, 5000);
        $this->creditSavings($m1, 30000);
        $this->creditSavings($m2, 20000);

        Sanctum::actingAs($member);
        $data = $this->getJson('/api/member/dashboard')->assertOk()->json('data');

        $this->assertSame(5000, $data['total_savings_minor'], 'champ historique inchangé');
        $this->assertSame(5000, $data['total_paid_minor']);
        $this->assertSame(50000, $data['available_savings_minor']);

        $byId = collect($data['campaigns'])->keyBy('id');
        $this->assertSame(30000, $byId[$m1->id]['available_savings_minor']);
        $this->assertSame(20000, $byId[$m2->id]['available_savings_minor']);

        // Même source que le calcul des retraits (EarlyWithdrawalService::breakdown).
        $withdrawable = $this->getJson("/api/member/memberships/{$m1->id}/withdrawable")->assertOk()->json('data');
        $this->assertSame($byId[$m1->id]['available_savings_minor'], $withdrawable['savings_minor']);

        $memberships = collect($this->getJson('/api/member/memberships')->assertOk()->json('data'))->keyBy('id');
        $this->assertSame(30000, $memberships[$m1->id]['available_savings_minor']);
        $this->assertSame(5000, $memberships[$m1->id]['savings_minor'], 'champ historique inchangé');
    }

    // ── 6c) libellés de comptes ledger ──────────────────────────────────────

    public function test_ledger_accounts_and_journal_expose_human_labels(): void
    {
        $campaign   = $this->campaign(['name' => 'Tontine Zogbo']);
        $membership = $this->membership($this->member(['full_name' => 'Chantal Agossou']), $campaign);
        $this->creditSavings($membership, 10000);
        app(LedgerService::class)->openAccount('MOMO_FLOAT:MTN', AccountType::MomoFloat);
        app(LedgerService::class)->openAccount('PSP_FLOAT:KKIAPAY', AccountType::PspFloat);

        $this->actingAsStaff('admin');
        $accounts = collect($this->getJson('/api/admin/ledger/accounts')->assertOk()->json('data'))->keyBy('key');

        $this->assertSame('Caisse espèces', $accounts['CASH_BOX']['account_label']);
        $this->assertSame('MoMo MTN (ancien)', $accounts['MOMO_FLOAT:MTN']['account_label']);
        $this->assertSame('Solde KKiaPay', $accounts['PSP_FLOAT:KKIAPAY']['account_label']);
        $this->assertSame('Épargne — Chantal Agossou · Tontine Zogbo', $accounts["MEMBER_SAVINGS:{$membership->id}"]['account_label']);

        $entries = $this->getJson('/api/admin/ledger/accounts/' . urlencode("MEMBER_SAVINGS:{$membership->id}") . '/entries')->assertOk();
        $entries->assertJsonPath('meta.account.account_label', 'Épargne — Chantal Agossou · Tontine Zogbo');
        $entries->assertJsonPath('data.0.account_label', 'Épargne — Chantal Agossou · Tontine Zogbo');

        $journal = $this->getJson('/api/admin/ledger/journal')->assertOk()->json('data');
        $this->assertSame('Caisse espèces', $journal[0]['account_label']);

        $txnId = $journal[0]['id'];
        $labels = collect($this->getJson("/api/admin/ledger/transactions/{$txnId}")->assertOk()->json('data.entries'))->pluck('account_label');
        $this->assertContains('Caisse espèces', $labels);
        $this->assertContains('Épargne — Chantal Agossou · Tontine Zogbo', $labels);
    }

    public function test_journal_default_period_follows_the_business_day(): void
    {
        $membership = $this->membership($this->member());
        // 28/10 23:30 UTC = 29/10 00:30 à Cotonou
        $this->travelTo(Carbon::parse('2026-10-28 23:30:00', 'UTC'));
        $this->creditSavings($membership, 5000);

        $this->actingAsStaff('admin');
        $this->getJson('/api/admin/ledger/journal?from=2026-10-29&to=2026-10-29')
            ->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/admin/ledger/journal?from=2026-10-28&to=2026-10-28')
            ->assertOk()->assertJsonPath('meta.total', 0);
    }

    // ── 6d) indicateurs financiers de campagne ──────────────────────────────

    public function test_campaign_detail_exposes_financial_indicators(): void
    {
        $campaign = $this->campaign();
        $m1 = $this->membership($this->member(), $campaign);
        $m2 = $this->membership($this->member(), $campaign);
        $this->confirmedCash($m1, 5000);
        $this->confirmedCash($m2, 10000);
        $this->creditSavings($m1, 8000);

        $request = LoanRequest::create([
            'membership_id' => $m2->id,
            'campaign_id'   => $campaign->id,
            'amount_minor'  => 4000,
            'status'        => 'disbursed',
            'product_type'  => 'advance',
        ]);
        Loan::create([
            'reference'         => 'LOAN-TEST-0001',
            'loan_request_id'   => $request->id,
            'membership_id'     => $m2->id,
            'campaign_id'       => $campaign->id,
            'product_type'      => 'advance',
            'principal_minor'   => 4000,
            'outstanding_minor' => 3000,
            'status'            => 'active',
            'disbursed_channel' => 'cash',
            'disbursed_at'      => now(),
        ]);

        $this->actingAsStaff('manager');
        $this->getJson("/api/admin/campaigns/{$campaign->id}")
            ->assertOk()
            ->assertJsonPath('data.financials.total_collected_minor', 15000)
            ->assertJsonPath('data.financials.confirmed_payments_count', 2)
            ->assertJsonPath('data.financials.total_savings_minor', 8000)
            ->assertJsonPath('data.financials.outstanding_advances_minor', 3000)
            ->assertJsonPath('data.financials.active_loans_count', 1);

        // Les listes ne portent pas ce bloc (coût).
        $this->getJson('/api/admin/campaigns')->assertOk()->assertJsonMissingPath('data.0.financials');
    }

    // ── Stats du jour en fuseau métier ──────────────────────────────────────

    public function test_dashboard_today_uses_the_local_business_day(): void
    {
        $membership = $this->membership($this->member());
        // 28/10 22:30 UTC = 28/10 23:30 local (veille) ; 28/10 23:15 UTC = 29/10 00:15 local (jour)
        $this->confirmedCash($membership, 1000, Carbon::parse('2026-10-28 22:30:00', 'UTC'));
        $this->confirmedCash($membership, 5000, Carbon::parse('2026-10-28 23:15:00', 'UTC'));

        $this->travelTo(Carbon::parse('2026-10-28 23:40:00', 'UTC')); // 29/10 00:40 local
        $this->actingAsStaff('treasurer');

        $this->getJson('/api/admin/stats/dashboard')
            ->assertOk()
            ->assertJsonPath('data.encaissements_jour.total', 5000)
            ->assertJsonPath('data.encaissements_jour.count', 1);
    }
}
