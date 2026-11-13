<?php

namespace Tests\Feature\Renewal;

use App\Modules\Audit\Infrastructure\Models\AuditLog;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Ledger\Infrastructure\Models\LedgerTransaction;
use App\Modules\Notifications\Infrastructure\Models\InAppNotification;
use App\Modules\Tontine\Infrastructure\Models\Campaign;
use App\Modules\Tontine\Infrastructure\Models\CampaignPayout;
use App\Modules\Tontine\Infrastructure\Models\CampaignRenewal;
use App\Modules\Tontine\Infrastructure\Models\Installment;
use App\Modules\Tontine\Infrastructure\Models\Membership;
use App\Modules\Tontine\Infrastructure\Models\RotationRound;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\BuildsKafoFixtures;
use Tests\Support\BuildsRotationFixtures;
use Tests\TestCase;

/** Reconduction des campagnes (R1–R4) : épargne et rotatif, historique intact, idempotence, garde de démarrage. */
class CampaignRenewalTest extends TestCase
{
    use RefreshDatabase, BuildsKafoFixtures, BuildsRotationFixtures;

    private Campaign $source;
    /** @var Membership[] */
    private array $old = [];
    private User $super;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        $this->super = $this->staff('super_admin');
    }

    /** Campagne d'épargne clôturée et réglée : 3 membres, le 3ᵉ doit encore une échéance. */
    private function closedSavings(): void
    {
        $this->source = $this->campaign(['status' => 'closed', 'closed_at' => now()->subDay(), 'name' => 'Épargne Dantokpa']);
        foreach (range(1, 3) as $i) {
            $this->old[] = $this->membership($this->member(), $this->source, ['status' => 'closed']);
        }
        $this->installments($this->old[2], ['late']);
    }

    private function payload(array $extra = []): array
    {
        return [
            'first_installment_date' => now()->addDays(10)->toDateString(),
            'members' => [
                ['membership_id' => $this->old[0]->id],
                ['membership_id' => $this->old[1]->id],
            ],
            ...$extra,
        ];
    }

    private function renew(array $payload, ?string $key = null): \Illuminate\Testing\TestResponse
    {
        Sanctum::actingAs($this->super);

        return $this->postJson("/api/admin/campaigns/{$this->source->id}/renewal", [...$payload, 'idempotency_key' => $key ?? (string) Str::uuid()]);
    }

    // ── Épargne ──────────────────────────────────────────────────────────

    public function test_savings_renewal_creates_a_linked_open_cycle_with_pending_memberships_and_touches_no_history(): void
    {
        $this->closedSavings();
        $newcomer = $this->member(['full_name' => 'Nouvelle Adhérente']);
        $txBefore = LedgerTransaction::count();
        $oldStatuses = Membership::whereIn('id', collect($this->old)->pluck('id'))->pluck('status', 'id')->map->value->all();

        Sanctum::actingAs($this->super);
        $preview = $this->postJson("/api/admin/campaigns/{$this->source->id}/renewal/preview",
            $this->payload(['members' => [...$this->payload()['members'], ['user_id' => $newcomer->id]]]))->assertOk()->json('data');
        $this->assertTrue($preview['valid'], implode(' | ', $preview['issues']));
        $this->assertSame('Épargne Dantokpa — Cycle 2', $preview['target']['name']);
        $this->assertCount(2, $preview['members']['kept']);
        $this->assertCount(1, $preview['members']['added']);
        $this->assertSame($this->old[2]->id, $preview['members']['removed'][0]['membership_id']);

        $id = $this->renew($this->payload(['members' => [...$this->payload()['members'], ['user_id' => $newcomer->id]]]))
            ->assertCreated()
            ->assertJsonPath('data.cycle_number', 2)
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.previous_campaign_id', $this->source->id)
            ->assertJsonPath('data.series_id', $this->source->series_id)
            ->json('data.id');

        $this->assertSame(3, Membership::where('campaign_id', $id)->where('status', 'pending_l1')->count());
        $this->assertSame(0, Installment::where('campaign_id', $id)->count(), 'aucune échéance avant validation (R2)');
        $this->assertSame($txBefore, LedgerTransaction::count(), 'aucune écriture créée par une reconduction');
        $this->assertSame($oldStatuses, Membership::whereIn('id', collect($this->old)->pluck('id'))->pluck('status', 'id')->map->value->all());
        $this->assertSame('late', Installment::where('membership_id', $this->old[2]->id)->value('status')->value, 'la dette reste dans l\'ancien cycle');
        $this->assertSame(3, InAppNotification::where('type', 'campaign.renewal_proposed')->count());
        $this->assertTrue(AuditLog::where('action', 'campaign.renewed')->exists());
        $this->assertSame($this->source->id, Campaign::find($this->source->id)->nextCycle->previous_campaign_id);
    }

    public function test_changed_amount_dates_and_duration_apply_only_to_the_new_cycle(): void
    {
        $this->closedSavings();
        $id = $this->renew($this->payload(['contribution_amount_minor' => 7500, 'duration_installments' => 6]))->assertCreated()->json('data.id');

        $new = Campaign::find($id);
        $this->assertSame(7500, $new->contribution_amount_minor);
        $this->assertSame(6, $new->duration_installments);
        $this->assertSame(5000, $this->source->fresh()->contribution_amount_minor);

        $m = Membership::where('campaign_id', $id)->first();
        $this->assertSame(7500, $m->committed_amount_minor);
        $this->actingAsStaff('manager');
        $this->postJson("/api/admin/memberships/{$m->id}/validate-l1")->assertOk();
        $this->assertSame(6, Installment::where('membership_id', $m->id)->where('amount_minor', 7500)->count());

        $this->assertSame(7500, CampaignRenewal::sole()->config_changes['contribution_amount_minor']['after']);
    }

    public function test_debtor_needs_a_reason_and_debt_is_never_transferred(): void
    {
        $this->closedSavings();
        $members = [...$this->payload()['members'], ['membership_id' => $this->old[2]->id]];

        Sanctum::actingAs($this->super);
        $this->getJson("/api/admin/campaigns/{$this->source->id}/renewal/candidates")->assertOk()
            ->assertJsonPath('data.candidates.2.selected_by_default', false)
            ->assertJsonPath('data.candidates.2.debt_minor', 5000);

        $this->renew($this->payload(['members' => $members]))->assertStatus(422)->assertJsonPath('error.code', 'renewal_invalid');

        $members[2]['debt_override_reason'] = 'Engagement écrit de régulariser avant fin du mois.';
        $id = $this->renew($this->payload(['members' => $members]))->assertCreated()->json('data.id');
        $this->assertSame(3, Membership::where('campaign_id', $id)->count());
        $this->assertSame(1, Installment::where('membership_id', $this->old[2]->id)->where('status', 'late')->count());
    }

    public function test_only_a_closed_campaign_can_be_renewed_and_only_once(): void
    {
        $this->closedSavings();
        $this->source->update(['status' => 'active']);
        $this->renew($this->payload())->assertStatus(422)->assertJsonPath('error.code', 'renewal_invalid');

        $this->source->update(['status' => 'closed']);
        $key = (string) Str::uuid();
        $first  = $this->renew($this->payload(), $key)->assertCreated()->json('data.id');
        $replay = $this->renew($this->payload(), $key)->assertCreated()->json('data.id');
        $this->assertSame($first, $replay, 'double clic : même cycle');
        $this->assertSame(1, CampaignRenewal::count());

        $this->renew($this->payload())->assertStatus(409)->assertJsonPath('error.code', 'campaign_already_renewed');
    }

    public function test_admin_can_prepare_but_only_super_admin_confirms(): void
    {
        $this->closedSavings();
        $this->actingAsStaff('admin');

        $this->postJson("/api/admin/campaigns/{$this->source->id}/renewal/preview", $this->payload())->assertOk();
        $this->postJson("/api/admin/campaigns/{$this->source->id}/renewal", [...$this->payload(), 'idempotency_key' => (string) Str::uuid()])
            ->assertStatus(403);
    }

    public function test_new_cycle_starts_only_once_the_previous_is_settled(): void
    {
        $this->closedSavings();
        $payout = CampaignPayout::create([
            'reference' => 'PAYOUT-T-1', 'campaign_id' => $this->source->id, 'membership_id' => $this->old[0]->id,
            'savings_minor' => 10000, 'advance_offset_minor' => 0, 'net_amount_minor' => 10000, 'status' => 'pending',
        ]);
        $id = $this->renew($this->payload())->assertCreated()->json('data.id');

        Sanctum::actingAs($this->super);
        $this->patchJson("/api/admin/campaigns/{$id}/status", ['status' => 'active', 'reason' => 'Démarrage du cycle 2.'])
            ->assertStatus(422)->assertJsonPath('error.code', 'previous_cycle_not_settled');

        $payout->update(['status' => 'settled']);
        $this->patchJson("/api/admin/campaigns/{$id}/status", ['status' => 'active', 'reason' => 'Démarrage du cycle 2.'])->assertOk();
    }

    // ── Rotatif ──────────────────────────────────────────────────────────

    public function test_rotating_renewal_recomputes_rounds_and_only_proposes_the_previous_order(): void
    {
        $source = $this->startedRotation();
        $source->update(['status' => 'closed', 'closed_at' => now()]);
        $this->source = $source->fresh();
        $newcomer = $this->member();

        Sanctum::actingAs($this->super);
        $payload = [
            'first_installment_date' => now()->addDays(5)->toDateString(),
            'installments_per_round' => 3,
            'contribution_amount_minor' => 2000,
            'members' => [
                ['membership_id' => $this->rotationMembers[1]->id],
                ['membership_id' => $this->rotationMembers[2]->id],
                ['user_id' => $newcomer->id],
                ['membership_id' => $this->rotationMembers[0]->id],
            ],
        ];
        // Le membre 1 a des échéances impayées dans le cycle 1 : motif requis.
        $payload['members'][3]['debt_override_reason'] = 'Accord du groupe en assemblée générale.';
        foreach ([1, 2] as $i) {
            $payload['members'][$i - 1]['debt_override_reason'] = 'Accord du groupe en assemblée générale.';
        }

        $preview = $this->postJson("/api/admin/campaigns/{$this->source->id}/renewal/preview", $payload)->assertOk()->json('data');
        $this->assertTrue($preview['valid'], implode(' | ', $preview['issues']));
        $this->assertSame(4, $preview['schedule']['rounds_count']);
        $this->assertSame(24000, $preview['schedule']['pot_minor'], '4 membres × 3 cotisations × 2 000');
        $this->assertCount(3, $preview['previous_round_order']);

        $id = $this->renew($payload)->assertCreated()->assertJsonPath('data.campaign_type', 'rotating')->json('data.id');
        $new = Campaign::find($id);
        $this->assertNotNull($new->schedule_confirmed_at);
        $this->assertSame(4, $new->max_members);
        $this->assertSame(4, RotationRound::where('campaign_id', $id)->count());
        $this->assertSame(0, RotationRound::where('campaign_id', $id)->whereNotNull('beneficiary_membership_id')->count(),
            'l\'ancien ordre n\'est jamais appliqué sans confirmation');
        $this->assertSame(3, RotationRound::where('campaign_id', $this->source->id)->whereNotNull('beneficiary_membership_id')->count(),
            'les tours du cycle 1 sont intacts');
    }

    // ── Historique ───────────────────────────────────────────────────────

    public function test_cycle_history_has_per_cycle_indicators(): void
    {
        $this->closedSavings();
        $id = $this->renew($this->payload())->assertCreated()->json('data.id');

        Sanctum::actingAs($this->super);
        $cycles = $this->getJson("/api/admin/campaigns/{$id}/cycles")->assertOk()->json('data.cycles');
        $this->assertSame([1, 2], array_column($cycles, 'cycle_number'));
        $this->assertSame(5000, $cycles[0]['kpis']['unpaid_minor']);
        $this->assertSame(0, $cycles[1]['kpis']['collected_minor']);
        $this->assertSame(3, $cycles[0]['members']);
        $this->assertSame(0, $cycles[1]['members'], 'adhésions en attente non comptées');
    }
}
