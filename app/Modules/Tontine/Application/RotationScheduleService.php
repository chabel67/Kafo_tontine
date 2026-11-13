<?php

namespace App\Modules\Tontine\Application;

use App\Modules\Tontine\Domain\Enums\CampaignStatus;
use App\Modules\Tontine\Domain\Enums\ContributionMode;
use App\Modules\Tontine\Domain\Enums\RotationFeeMode;
use App\Modules\Tontine\Domain\Enums\RotationRoundStatus;
use App\Modules\Tontine\Domain\Exceptions\TontineRuleException;
use App\Modules\Tontine\Infrastructure\Models\Campaign;
use App\Modules\Tontine\Infrastructure\Models\CampaignScheduleDate;
use App\Modules\Tontine\Infrastructure\Models\RotationRound;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Calendrier d'une campagne rotative (lot 2) — cagnotte FIXE (D-R1) :
 *
 *   N membres = N tours ; k échéances par tour ; N × k échéances par membre ;
 *   tour t financé par les échéances (t−1)·k+1 … t·k de chaque membre ;
 *   cagnotte = N × k × cotisation (hors complément de frais) ;
 *   date du tour = date de sa dernière échéance (D-R2).
 *
 * `preview()` calcule sans rien écrire et liste les incohérences BLOQUANTES.
 * `confirm()` fige dates et tours en base (brouillon uniquement) : ils ne seront plus jamais
 * recalculés silencieusement ; modifier un paramètre en brouillon annule la confirmation.
 */
class RotationScheduleService
{
    public function __construct(private readonly ScheduleCalculator $calculator) {}

    public function preview(Campaign $campaign): array
    {
        $issues = [];
        $n      = (int) $campaign->max_members;
        $k      = (int) $campaign->installments_per_round;
        $amount = (int) $campaign->contribution_amount_minor;
        $rule   = $campaign->contribution_schedule;
        $mode   = $campaign->rotation_fee_mode ?? RotationFeeMode::None;
        $fee    = (int) $campaign->rotation_fee_minor;

        if (! $campaign->isRotating()) {
            $issues[] = "Cette campagne n'est pas une tontine rotative.";
        }
        if ($campaign->contribution_mode !== ContributionMode::Fixed || $amount <= 0) {
            $issues[] = 'Une tontine rotative exige une cotisation fixe, identique pour tous.';
        }
        if ($n < 2) {
            $issues[] = 'Indiquez le nombre de participants (au moins 2) : il fixe le nombre de tours.';
        }
        if ($k < 1) {
            $issues[] = "Indiquez le nombre de cotisations par tour (au moins 1).";
        }
        foreach ($this->calculator->validate($rule) as $message) {
            $issues[] = $message;
        }
        if ($mode !== RotationFeeMode::None && $fee <= 0) {
            $issues[] = 'Indiquez le montant des frais, ou choisissez « Aucun frais ».';
        }

        $count = max(0, $n * $k);
        $pot   = $n * $k * $amount;
        if ($mode === RotationFeeMode::PayoutDeduction && $fee >= $pot && $pot > 0) {
            $issues[] = 'La retenue sur la cagnotte doit être inférieure à la cagnotte.';
        }

        $dates = [];
        if ($issues === [] && $count > 0) {
            if (($rule['type'] ?? null) === 'custom_dates' && count($rule['dates']) !== $count) {
                $issues[] = "Il faut exactement {$count} dates ({$n} tours × {$k} cotisations) : " . count($rule['dates']) . ' saisie(s).';
            }
            $start = ($rule['type'] ?? null) === 'custom_dates'
                ? Carbon::parse(collect($rule['dates'])->min())
                : Carbon::parse($campaign->first_installment_date ?? now());
            $dates = $this->calculator->dates($rule, $start, $count);
        }

        $rounds = [];
        if ($issues === [] && count($dates) === $count) {
            $roundFee = $mode === RotationFeeMode::PayoutDeduction ? $fee : 0;
            for ($t = 1; $t <= $n; $t++) {
                $first = ($t - 1) * $k + 1;
                $last  = $t * $k;
                $rounds[] = [
                    'round_number'   => $t,
                    'first_sequence' => $first,
                    'last_sequence'  => $last,
                    'scheduled_date' => $dates[$last - 1]->toDateString(),
                    'expected_minor' => $pot,
                    'fee_minor'      => $roundFee,
                    'net_minor'      => $pot - $roundFee,
                ];
            }
        }

        $surcharge = $mode === RotationFeeMode::ContributionSurcharge ? $fee : 0;

        return [
            'valid'           => $issues === [],
            'issues'          => $issues,
            'participants'    => $n,
            'rounds_count'    => $n,
            'installments_per_round' => $k,
            'installments_count'     => $count,
            'contribution_minor'     => $amount,
            'surcharge_minor'        => $surcharge,
            'amount_due_per_installment_minor' => $amount + $surcharge,
            'pot_minor'              => $pot,
            'total_per_member_minor' => $count * ($amount + $surcharge),
            'fee_mode'        => $mode->value,
            'dates'           => array_map(fn (Carbon $d, int $i) => ['sequence' => $i + 1, 'due_date' => $d->toDateString()], $dates, array_keys($dates)),
            'rounds'          => $rounds,
            'first_date'      => isset($dates[0]) ? $dates[0]->toDateString() : null,
            'last_date'       => $dates !== [] ? end($dates)->toDateString() : null,
            'confirmed_at'    => $campaign->schedule_confirmed_at?->toIso8601String(),
        ];
    }

    public function confirm(Campaign $campaign): Campaign
    {
        return DB::transaction(function () use ($campaign) {
            $campaign = Campaign::whereKey($campaign->id)->lockForUpdate()->firstOrFail();

            if (! $campaign->isRotating()) {
                throw TontineRuleException::notRotating();
            }
            if ($campaign->status !== CampaignStatus::Draft) {
                throw new TontineRuleException('Le calendrier ne se confirme qu\'en brouillon : il est figé une fois la campagne ouverte.', 'rotation_schedule_locked', 422);
            }

            $preview = $this->preview($campaign);
            if (! $preview['valid']) {
                throw new TontineRuleException('Calendrier incohérent : ' . implode(' ', $preview['issues']), 'rotation_schedule_invalid', 422);
            }

            $this->discard($campaign);

            $now = now();
            CampaignScheduleDate::insert(array_map(fn ($d) => [
                'id' => (string) \Illuminate\Support\Str::uuid(), 'campaign_id' => $campaign->id,
                'sequence' => $d['sequence'], 'due_date' => $d['due_date'], 'created_at' => $now, 'updated_at' => $now,
            ], $preview['dates']));

            foreach ($preview['rounds'] as $r) {
                RotationRound::create([
                    'campaign_id'    => $campaign->id,
                    'round_number'   => $r['round_number'],
                    'first_sequence' => $r['first_sequence'],
                    'last_sequence'  => $r['last_sequence'],
                    'scheduled_date' => $r['scheduled_date'],
                    'expected_minor' => $r['expected_minor'],
                    'fee_minor'      => $r['fee_minor'],
                    'status'         => RotationRoundStatus::Scheduled,
                ]);
            }

            $campaign->update([
                'duration_installments'  => $preview['installments_count'],
                'first_installment_date' => $preview['first_date'],
                'schedule_confirmed_at'  => $now,
            ]);

            return $campaign->fresh();
        });
    }

    /**
     * Annule une confirmation (brouillon seulement) : appelée quand un paramètre du calendrier
     * change. Rien n'est encore engagé en brouillon (aucune adhésion, aucun tour attribué).
     */
    public function discard(Campaign $campaign): void
    {
        RotationRound::where('campaign_id', $campaign->id)->delete();
        CampaignScheduleDate::where('campaign_id', $campaign->id)->delete();
        if ($campaign->schedule_confirmed_at) {
            $campaign->update(['schedule_confirmed_at' => null]);
        }
    }
}
