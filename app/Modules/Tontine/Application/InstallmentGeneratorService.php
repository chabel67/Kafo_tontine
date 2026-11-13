<?php

namespace App\Modules\Tontine\Application;

use App\Modules\Tontine\Domain\Enums\RotationFeeMode;
use App\Modules\Tontine\Infrastructure\Models\Campaign;
use App\Modules\Tontine\Infrastructure\Models\Membership;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Génère les échéances d'une adhésion à son activation (R-MEMB-02).
 *
 *  - Épargne : dates calculées depuis le calendrier de marché de la campagne (comportement
 *    historique, via ScheduleCalculator) ; montant = `committed_amount_minor`.
 *  - Rotatif : dates du calendrier CONFIRMÉ (`campaign_schedule_dates`, jamais recalculé) ;
 *    montant = cotisation + complément éventuel (D-R5), le complément étant isolé dans `fee_minor`.
 */
class InstallmentGeneratorService
{
    public function __construct(private readonly ScheduleCalculator $calculator) {}

    public function generate(Membership $membership): void
    {
        $campaign = $membership->campaign;
        $dates    = $this->computeDueDates($campaign);

        $surcharge = $campaign->isRotating() && $campaign->rotation_fee_mode === RotationFeeMode::ContributionSurcharge
            ? (int) $campaign->rotation_fee_minor
            : 0;

        $installments = [];
        foreach ($dates as $i => $dueDate) {
            $installments[] = [
                'id'                     => (string) Str::uuid(),
                'membership_id'          => $membership->id,
                'campaign_id'            => $campaign->id,
                'sequence_number'        => $i + 1,
                'due_date'               => $dueDate->toDateString(),
                'grace_period_ends_date' => $dueDate->copy()->addDays($campaign->grace_period_days)->toDateString(),
                // committed_amount_minor est la source de vérité de la cotisation (R-MEMB-08)
                'amount_minor'           => $membership->committed_amount_minor + $surcharge,
                'fee_minor'              => $surcharge,
                'status'                 => 'pending',
                'created_at'             => now(),
                'updated_at'             => now(),
            ];
        }

        DB::table('installments')->insert($installments);
    }

    /** @return Carbon[] */
    public function computeDueDates(Campaign $campaign): array
    {
        if ($campaign->isRotating()) {
            return $campaign->scheduleDates()->get()->map(fn ($d) => $d->due_date->copy())->all();
        }

        return $this->calculator->dates(
            ScheduleCalculator::ruleFromCalendar($campaign->marketCalendar),
            Carbon::parse($campaign->first_installment_date),
            (int) $campaign->duration_installments,
        );
    }
}
