<?php

namespace App\Modules\Tontine\Application;

use App\Modules\Tontine\Domain\Enums\CadenceType;
use App\Modules\Tontine\Infrastructure\Models\MarketCalendar;
use Carbon\Carbon;

/**
 * Calcul des dates d'échéance à partir d'une règle de fréquence — service UNIQUE, utilisé
 * par la génération des échéances (épargne et rotatif), l'aperçu et la confirmation du
 * calendrier d'une campagne rotative.
 *
 * Règles (`contribution_schedule`) :
 *   - every_n_days    { n }                       journalier = n 1 ; jours de marché = n 5
 *   - weekly          { weekday, every_weeks }    ex. chaque samedi, ou un samedi sur deux
 *   - named_weekdays  { weekdays: [...] }         ex. lundi, mercredi, vendredi
 *   - monthly         { day_of_month }            jour absent du mois → dernier jour du mois
 *   - market_calendar { market_calendar_id }      réutilise un calendrier de marché existant
 *   - custom_dates    { dates: [Y-m-d, ...] }     saisies à la main : triées, sans doublon
 *
 * La première échéance n'est jamais antérieure à la date de début.
 */
class ScheduleCalculator
{
    public const TYPES = ['every_n_days', 'weekly', 'named_weekdays', 'monthly', 'market_calendar', 'custom_dates'];

    private const WEEKDAYS = [
        'sunday' => 0, 'monday' => 1, 'tuesday' => 2, 'wednesday' => 3,
        'thursday' => 4, 'friday' => 5, 'saturday' => 6,
    ];

    /**
     * Erreurs de forme d'une règle (vide = valide). Messages en français, clés = champ.
     *
     * @return array<string, string>
     */
    public function validate(?array $rule): array
    {
        $type = $rule['type'] ?? null;
        if (! in_array($type, self::TYPES, true)) {
            return ['contribution_schedule.type' => 'Fréquence inconnue.'];
        }

        $int = fn ($v) => is_int($v) || (is_string($v) && ctype_digit($v));

        return match ($type) {
            'every_n_days' => $int($rule['n'] ?? null) && (int) $rule['n'] >= 1 && (int) $rule['n'] <= 365
                ? [] : ['contribution_schedule.n' => "L'intervalle doit être compris entre 1 et 365 jours."],
            'weekly' => array_merge(
                isset(self::WEEKDAYS[strtolower((string) ($rule['weekday'] ?? ''))]) ? [] : ['contribution_schedule.weekday' => 'Jour de la semaine invalide.'],
                $int($rule['every_weeks'] ?? 1) && (int) ($rule['every_weeks'] ?? 1) >= 1 && (int) ($rule['every_weeks'] ?? 1) <= 52
                    ? [] : ['contribution_schedule.every_weeks' => "L'intervalle doit être compris entre 1 et 52 semaines."],
            ),
            'named_weekdays' => is_array($rule['weekdays'] ?? null) && $rule['weekdays'] !== []
                && collect($rule['weekdays'])->every(fn ($d) => isset(self::WEEKDAYS[strtolower((string) $d)]))
                ? [] : ['contribution_schedule.weekdays' => 'Choisissez au moins un jour de la semaine valide.'],
            'monthly' => $int($rule['day_of_month'] ?? null) && (int) $rule['day_of_month'] >= 1 && (int) $rule['day_of_month'] <= 31
                ? [] : ['contribution_schedule.day_of_month' => 'Le jour du mois doit être compris entre 1 et 31.'],
            'market_calendar' => MarketCalendar::whereKey($rule['market_calendar_id'] ?? 0)->exists()
                ? [] : ['contribution_schedule.market_calendar_id' => 'Calendrier de marché introuvable.'],
            'custom_dates' => $this->validateCustomDates($rule['dates'] ?? null),
        };
    }

    /**
     * @return Carbon[] exactement `$count` dates (ou moins pour des dates manuelles insuffisantes)
     */
    public function dates(array $rule, Carbon $start, int $count): array
    {
        $start = $start->copy()->startOfDay();

        return match ($rule['type']) {
            'every_n_days'    => $this->everyNDays($start, (int) $rule['n'], $count),
            'weekly'          => $this->weekly($start, (string) $rule['weekday'], (int) ($rule['every_weeks'] ?? 1), $count),
            'named_weekdays'  => $this->namedWeekdays($start, $rule['weekdays'], $count),
            'monthly'         => $this->monthly($start, (int) $rule['day_of_month'], $count),
            'market_calendar' => $this->dates(self::ruleFromCalendar(MarketCalendar::findOrFail($rule['market_calendar_id'])), $start, $count),
            'custom_dates'    => collect($rule['dates'])->map(fn ($d) => Carbon::parse($d)->startOfDay())
                ->sort()->values()->take($count)->all(),
        };
    }

    /** Règle équivalente à un calendrier de marché (comportement historique des campagnes d'épargne). */
    public static function ruleFromCalendar(?MarketCalendar $calendar): array
    {
        if (! $calendar) {
            return ['type' => 'every_n_days', 'n' => 30];
        }

        return match ($calendar->cadence_type) {
            CadenceType::EveryNDays    => ['type' => 'every_n_days', 'n' => (int) ($calendar->cadence_value['n'] ?? 5)],
            CadenceType::NamedWeekdays => ['type' => 'named_weekdays', 'weekdays' => $calendar->cadence_value['weekdays'] ?? ['monday']],
            CadenceType::Monthly       => ['type' => 'monthly', 'day_of_month' => (int) ($calendar->cadence_value['day_of_month'] ?? 1)],
        };
    }

    // ── Règles ────────────────────────────────────────────────────────────

    private function everyNDays(Carbon $start, int $n, int $count): array
    {
        $dates = [];
        for ($i = 0; $i < $count; $i++) {
            $dates[] = $start->copy()->addDays($i * max(1, $n));
        }
        return $dates;
    }

    private function weekly(Carbon $start, string $weekday, int $everyWeeks, int $count): array
    {
        $target = self::WEEKDAYS[strtolower($weekday)];
        $first  = $start->copy();
        while ($first->dayOfWeek !== $target) {
            $first->addDay();
        }

        $dates = [];
        for ($i = 0; $i < $count; $i++) {
            $dates[] = $first->copy()->addWeeks($i * max(1, $everyWeeks));
        }
        return $dates;
    }

    private function namedWeekdays(Carbon $start, array $weekdays, int $count): array
    {
        $days = array_map(fn ($d) => self::WEEKDAYS[strtolower((string) $d)] ?? 1, $weekdays);

        $dates   = [];
        $current = $start->copy();
        while (count($dates) < $count) {
            if (in_array($current->dayOfWeek, $days, true)) {
                $dates[] = $current->copy();
            }
            $current->addDay();
        }
        return $dates;
    }

    /** Jour absent du mois (29, 30, 31) → dernier jour du mois. */
    private function monthly(Carbon $start, int $dayOfMonth, int $count): array
    {
        $dayOfMonth = max(1, min(31, $dayOfMonth));
        $month      = $start->copy()->startOfMonth();
        $at         = fn (Carbon $m) => $m->copy()->day(min($dayOfMonth, $m->daysInMonth));

        if ($at($month)->lt($start)) {
            $month->addMonthNoOverflow();
        }

        $dates = [];
        for ($i = 0; $i < $count; $i++) {
            $dates[] = $at($month);
            $month->addMonthNoOverflow();
        }
        return $dates;
    }

    private function validateCustomDates(mixed $dates): array
    {
        if (! is_array($dates) || $dates === []) {
            return ['contribution_schedule.dates' => 'Saisissez au moins une date.'];
        }

        $parsed = [];
        foreach ($dates as $d) {
            if (! is_string($d) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) || ! checkdate((int) substr($d, 5, 2), (int) substr($d, 8, 2), (int) substr($d, 0, 4))) {
                return ['contribution_schedule.dates' => "Date invalide : « {$d} » (format AAAA-MM-JJ)."];
            }
            $parsed[] = $d;
        }

        if (count($parsed) !== count(array_unique($parsed))) {
            return ['contribution_schedule.dates' => 'Une même date est saisie plusieurs fois.'];
        }

        return [];
    }
}
