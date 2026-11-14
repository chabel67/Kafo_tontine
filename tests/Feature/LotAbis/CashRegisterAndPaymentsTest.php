<?php

namespace Tests\Feature\LotAbis;

use App\Modules\Payments\Infrastructure\Models\Payment;
use App\Modules\Tontine\Infrastructure\Models\Membership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\BuildsKafoFixtures;
use Tests\TestCase;

/**
 * Lot A-bis — point 4 (R-PAY-06, caisse clôturée) et point 10 (filtres de date des
 * paiements, caisse du trésorier courant).
 */
class CashRegisterAndPaymentsTest extends TestCase
{
    use RefreshDatabase, BuildsKafoFixtures;

    private Membership $membership;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        $this->membership = $this->membership($this->member());
    }

    private function cash(int $amount = 5000, array $extra = [])
    {
        return $this->postJson('/api/admin/payments/cash', [
            'membership_id' => $this->membership->id,
            'amount_minor'  => $amount,
            ...$extra,
        ]);
    }

    public function test_cash_is_refused_after_own_register_closure_for_the_business_day(): void
    {
        $this->travelTo(Carbon::parse('2026-10-28 10:00:00', 'UTC'));
        $treasurer = $this->actingAsStaff('treasurer');

        $this->cash()->assertCreated();
        $this->postJson('/api/admin/cash/close', ['physical_amount_minor' => 5000])->assertOk();

        $this->cash(5000, ['force' => true])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'cash_register_closed')
            ->assertJsonPath('error.message', "Votre caisse est clôturée pour la journée du 28/10/2026 : aucun nouvel encaissement en espèces n'est possible aujourd'hui.");
        $this->assertSame(1, Payment::where('created_by', $treasurer->id)->count());

        // Un autre trésorier n'est pas concerné par cette clôture.
        $this->actingAsStaff('treasurer');
        $this->cash(5000, ['force' => true])->assertCreated();
    }

    public function test_closure_applies_to_the_local_business_day_only(): void
    {
        // 28/10 22:00 UTC = 28/10 23:00 à Cotonou → clôture du 28.
        $this->travelTo(Carbon::parse('2026-10-28 22:00:00', 'UTC'));
        $this->actingAsStaff('treasurer');
        $this->postJson('/api/admin/cash/close', ['physical_amount_minor' => 0])->assertOk();
        $this->cash()->assertStatus(409);

        // 28/10 23:30 UTC = 29/10 00:30 à Cotonou : nouveau jour métier, caisse ouverte.
        $this->travelTo(Carbon::parse('2026-10-28 23:30:00', 'UTC'));
        $this->cash()->assertCreated();
    }

    public function test_cash_summary_exposes_own_register_and_closure(): void
    {
        $this->travelTo(Carbon::parse('2026-10-28 10:00:00', 'UTC'));
        $this->actingAsStaff('treasurer');
        $this->cash(5000)->assertCreated();

        $other = $this->actingAsStaff('treasurer');
        $this->membership = $this->membership($this->member());
        $this->cash(10000)->assertCreated();

        $data = $this->getJson('/api/admin/cash/summary')->assertOk()->json('data');
        $this->assertSame('2026-10-28', $data['date']);
        $this->assertSame(['count' => 2, 'total' => 15000], $data['confirmed'], 'toutes caisses : inchangé');
        $this->assertSame(['count' => 1, 'total' => 10000], $data['mine']['confirmed']);
        $this->assertFalse($data['mine']['closed']);
        $this->assertNull($data['mine']['closure']);

        $this->postJson('/api/admin/cash/close', ['physical_amount_minor' => 9000])->assertOk();
        $mine = $this->getJson('/api/admin/cash/summary')->json('data.mine');
        $this->assertTrue($mine['closed']);
        // Écart calculé par la clôture existante (théorique = toutes caisses du jour, inchangé).
        $this->assertSame(9000, $mine['closure']['physical_amount_minor']);
        $this->assertSame(9000 - 15000, $mine['closure']['discrepancy_minor']);
        $this->assertNotNull($mine['closure']['closed_at']);
        $this->assertSame($other->id, \App\Modules\Audit\Infrastructure\Models\AuditLog::where('action', 'cash.close_day')->value('user_id'));
    }

    public function test_payments_list_filters_by_business_day_and_own_entries(): void
    {
        $this->actingAsStaff('treasurer');
        // 27/10 23:30 UTC = 28/10 00:30 à Cotonou
        $this->travelTo(Carbon::parse('2026-10-27 23:30:00', 'UTC'));
        $this->cash()->assertCreated();
        $this->travelTo(Carbon::parse('2026-10-27 12:00:00', 'UTC'));
        $this->cash(5000, ['force' => true])->assertCreated();
        $this->travelBack();

        // Paiement saisi par quelqu'un d'autre, le 28/10 local.
        $this->travelTo(Carbon::parse('2026-10-28 09:00:00', 'UTC'));
        Payment::create([
            'reference' => 'PAY-T-' . Str::random(6), 'idempotency_key' => (string) Str::uuid(),
            'membership_id' => $this->membership->id, 'channel' => 'kkiapay', 'status' => 'pending',
            'amount_minor' => 5000,
        ]);
        $this->travelBack();

        $this->getJson('/api/admin/payments?from=2026-10-28&to=2026-10-28')->assertOk()->assertJsonPath('meta.total', 2);
        $this->getJson('/api/admin/payments?from=2026-10-28&to=2026-10-28&channel=cash')->assertJsonPath('meta.total', 1);
        $this->getJson('/api/admin/payments?from=2026-10-28&to=2026-10-28&mine=1')->assertJsonPath('meta.total', 1);
        $this->getJson('/api/admin/payments?to=2026-10-27')->assertJsonPath('meta.total', 1);
        $this->getJson('/api/admin/payments')->assertJsonPath('meta.total', 3);

        $this->getJson('/api/admin/payments?from=28-10-2026')->assertStatus(422)->assertJsonPath('error.code', 'validation_error');
        $this->getJson('/api/admin/payments?from=2026-10-28&to=2026-10-27')->assertStatus(422);
    }
}
