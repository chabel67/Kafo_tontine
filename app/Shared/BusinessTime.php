<?php

namespace App\Shared;

use Illuminate\Support\Carbon;

/**
 * Horloge métier Kafo.
 *
 * Les horodatages sont stockés en UTC (APP_TIMEZONE=UTC), mais toutes les règles
 * « du jour » (doublon cash R-PAY-08, clôture de caisse, journal R-LEDGER-08,
 * exports, stats du jour, échéances échues) raisonnent sur le jour local
 * du fuseau métier (`kafo.business_timezone`, Bénin = Africa/Porto-Novo).
 *
 * Règle d'or : une borne calculée ici doit être passée en UTC (`->utc()`)
 * avant d'être comparée à une colonne timestamp — Laravel sérialise un Carbon
 * en « Y-m-d H:i:s » sans conversion de fuseau.
 */
final class BusinessTime
{
    public const FALLBACK_TIMEZONE = 'Africa/Porto-Novo';

    /** Fuseau métier validé (repli sur Africa/Porto-Novo si la valeur configurée est inconnue). */
    public static function timezone(): string
    {
        $tz = (string) config('kafo.business_timezone', self::FALLBACK_TIMEZONE);

        return in_array($tz, \DateTimeZone::listIdentifiers(), true) ? $tz : self::FALLBACK_TIMEZONE;
    }

    /** Instant présent exprimé dans le fuseau métier. */
    public static function now(): Carbon
    {
        return Carbon::now(self::timezone());
    }

    /** Début du jour local (fuseau métier). */
    public static function today(): Carbon
    {
        return self::now()->startOfDay();
    }

    /** Date locale du jour au format Y-m-d (à comparer aux colonnes `date` : due_date…). */
    public static function todayDate(): string
    {
        return self::today()->toDateString();
    }

    /** Interprète une date saisie (Y-m-d ou datetime sans fuseau) comme une date locale métier. */
    public static function parse(string $value): Carbon
    {
        return Carbon::parse($value, self::timezone());
    }

    /**
     * Bornes UTC [début, fin] d'un jour local.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public static function dayBoundsUtc(Carbon|string|null $day = null): array
    {
        $local = match (true) {
            $day === null       => self::now(),
            is_string($day)     => self::parse($day),
            default             => $day->copy()->setTimezone(self::timezone()),
        };

        return [
            $local->copy()->startOfDay()->utc(),
            $local->copy()->endOfDay()->utc(),
        ];
    }

    /**
     * Expression SQL (PostgreSQL) de la date locale d'une colonne timestamp stockée en UTC.
     * Le fuseau provient de timezone() (liste IANA) : pas d'injection possible.
     */
    public static function localDateSql(string $column): string
    {
        $tz = self::timezone();

        return "DATE(({$column} AT TIME ZONE 'UTC') AT TIME ZONE '{$tz}')";
    }
}
