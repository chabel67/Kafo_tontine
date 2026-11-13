<?php

namespace Tests\Support;

use App\Modules\Ledger\Application\LedgerService;
use App\Modules\Tontine\Application\InstallmentGeneratorService;
use App\Modules\Tontine\Application\RotationScheduleService;
use App\Modules\Tontine\Infrastructure\Models\Campaign;
use App\Modules\Tontine\Infrastructure\Models\Membership;
use App\Modules\Tontine\Infrastructure\Models\RotationRound;

/** Tontine rotative démarrée, prête pour les tests de distribution (à combiner avec BuildsKafoFixtures). */
trait BuildsRotationFixtures
{
    protected Campaign $rotation;
    /** @var Membership[] */
    protected array $rotationMembers = [];

    /** 3 membres, 2 cotisations de 1 000 par tour, une par jour depuis $daysAgo jours ; tour t → membre t. */
    protected function startedRotation(string $feeMode = 'none', int $fee = 0, int $daysAgo = 2): Campaign
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
            $this->rotationMembers[] = $m;
        }
        foreach (RotationRound::where('campaign_id', $campaign->id)->orderBy('round_number')->get() as $i => $round) {
            $round->update(['beneficiary_membership_id' => $this->rotationMembers[$i]->id]);
        }
        $campaign->update(['status' => 'active', 'started_at' => now(), 'activated_at' => now()]);

        return $this->rotation = $campaign->fresh();
    }

    protected function payRotationCash(Membership $m, int $amount): \Illuminate\Testing\TestResponse
    {
        $this->actingAsStaff('treasurer');

        return $this->postJson('/api/admin/payments/cash', [
            'membership_id' => $m->id, 'amount_minor' => $amount, 'force' => true, 'confirm_atypical' => true,
        ]);
    }

    protected function round(int $number): RotationRound
    {
        return RotationRound::where('campaign_id', $this->rotation->id)->where('round_number', $number)->firstOrFail();
    }

    protected function ledgerBalance(string $key): int
    {
        return app(LedgerService::class)->balances([$key])[$key] ?? 0;
    }
}
