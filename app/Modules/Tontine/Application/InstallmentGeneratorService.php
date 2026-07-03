<?php

namespace App\Modules\Tontine\Application;

use App\Modules\Tontine\Domain\Enums\CadenceType;
use App\Modules\Tontine\Infrastructure\Models\Campaign;
use App\Modules\Tontine\Infrastructure\Models\Membership;
use Carbon\Carbon;

class InstallmentGeneratorService
{
    public function generate(Membership $membership): void
    {
        $campaign = $membership->campaign;
        $dates    = $this->computeDueDates($campaign);

        $installments = [];
        foreach ($dates as $i => $dueDate) {
            $graceEnds = (clone $dueDate)->addDays($campaign->grace_period_days);
            // committed_amount_minor est la source de vérité (fixe ou personnalisée)
            $installments[] = [
                'id'                     => (string) \Illuminate\Support\Str::uuid(),
                'membership_id'          => $membership->id,
                'campaign_id'            => $campaign->id,
                'sequence_number'        => $i + 1,
                'due_date'               => $dueDate->toDateString(),
                'grace_period_ends_date' => $graceEnds->toDateString(),
                'amount_minor'           => $membership->committed_amount_minor,
                'status'                 => 'pending',
                'created_at'             => now(),
                'updated_at'             => now(),
            ];
        }

        \Illuminate\Support\Facades\DB::table('installments')->insert($installments);
    }

    /** @return Carbon[] */
    private function computeDueDates(Campaign $campaign): array
    {
        $count    = $campaign->duration_installments;
        $first    = Carbon::parse($campaign->first_installment_date);
        $calendar = $campaign->marketCalendar;

        if (! $calendar) {
            return $this->everyNDays($first, 30, $count);
        }

        return match ($calendar->cadence_type) {
            CadenceType::EveryNDays    => $this->everyNDays($first, $calendar->cadence_value['n'] ?? 5, $count),
            CadenceType::NamedWeekdays => $this->namedWeekdays($first, $calendar->cadence_value['weekdays'] ?? [], $count),
            CadenceType::Monthly       => $this->monthly($first, $calendar->cadence_value['day_of_month'] ?? 1, $count),
        };
    }

    private function everyNDays(Carbon $start, int $n, int $count): array
    {
        $dates = [];
        $current = clone $start;
        for ($i = 0; $i < $count; $i++) {
            $dates[] = clone $current;
            $current->addDays($n);
        }
        return $dates;
    }

    private function namedWeekdays(Carbon $start, array $weekdays, int $count): array
    {
        $map = [
            'monday' => 1, 'tuesday' => 2, 'wednesday' => 3,
            'thursday' => 4, 'friday' => 5, 'saturday' => 6, 'sunday' => 0,
        ];
        $dayNumbers = array_map(fn ($d) => $map[strtolower($d)] ?? 1, $weekdays);
        sort($dayNumbers);

        $dates   = [];
        $current = clone $start;
        while (count($dates) < $count) {
            if (in_array($current->dayOfWeek, $dayNumbers, true)) {
                $dates[] = clone $current;
            }
            $current->addDay();
        }
        return $dates;
    }

    private function monthly(Carbon $start, int $dayOfMonth, int $count): array
    {
        $dates   = [];
        $current = clone $start;
        for ($i = 0; $i < $count; $i++) {
            $date = $current->copy()->startOfMonth()->addDays($dayOfMonth - 1);
            if ($i === 0 && $date->lt($start)) {
                $current->addMonth();
                $date = $current->copy()->startOfMonth()->addDays($dayOfMonth - 1);
            }
            $dates[]  = $date;
            $current->addMonth();
        }
        return $dates;
    }
}
