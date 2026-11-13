<?php

namespace Tests\Feature\Payments;

use App\Modules\Payments\Infrastructure\Models\InstallmentPayment;
use App\Modules\Tontine\Infrastructure\Models\Installment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsKafoFixtures;
use Tests\TestCase;

/** R-SCHED-05 : un paiement partiel antérieur est déduit lors des affectations suivantes. */
class PartialInstallmentAllocationTest extends TestCase
{
    use RefreshDatabase, BuildsKafoFixtures;

    public function test_previous_partial_payment_is_deducted_and_remaining_is_exposed(): void
    {
        $this->seedRoles();
        $membership = $this->membership($this->member());
        $this->installments($membership, ['pending', 'pending', 'pending']);
        $this->actingAsStaff('treasurer');
        $c       = $membership->committed_amount_minor;
        $partial = intdiv($c, 5) * 2;               // 40 % de la cotisation
        $cash    = fn (int $amount, array $extra = []) => $this->postJson('/api/admin/payments/cash', [
            'membership_id' => $membership->id, 'amount_minor' => $amount, 'force' => true, ...$extra,
        ]);

        // 1) Partiel : atypique, confirmé explicitement
        $cash($partial)->assertStatus(422)->assertJsonPath('error.code', 'atypical_amount');
        $cash($partial, ['confirm_atypical' => true])->assertCreated();

        $rows = $this->getJson("/api/admin/memberships/{$membership->id}/installments")->assertOk()->json('data');
        $this->assertSame([$partial, $c - $partial], [$rows[0]['paid_minor'], $rows[0]['remaining_minor']]);
        $this->assertSame('pending', $rows[0]['status']);

        // 2) Une cotisation entière : l'échéance 1 ne reçoit que son reste, le surplus va à l'échéance 2
        $id = $cash($c)->assertCreated()->json('data.id');
        $alloc = InstallmentPayment::where('payment_id', $id)->orderBy('amount_minor', 'desc')->pluck('amount_minor')->all();
        $this->assertSame([$c - $partial, $partial], $alloc);

        $byNumber = Installment::where('membership_id', $membership->id)->orderBy('sequence_number')->get();
        $this->assertSame('paid', $byNumber[0]->status->value);
        $this->assertSame('pending', $byNumber[1]->status->value);
        $this->assertSame($c, (int) InstallmentPayment::where('installment_id', $byNumber[0]->id)->sum('amount_minor'));

        // 3) Solder exactement le reste dû de l'échéance 2 n'est pas atypique (R-PAY-05)
        $cash($c - $partial)->assertCreated();
        $this->assertSame('paid', $byNumber[1]->fresh()->status->value);
        $this->assertSame('pending', $byNumber[2]->fresh()->status->value);

        $rows = $this->getJson("/api/admin/memberships/{$membership->id}/installments")->json('data');
        $this->assertSame([0, 0, $c], array_column($rows, 'remaining_minor'));
    }
}
