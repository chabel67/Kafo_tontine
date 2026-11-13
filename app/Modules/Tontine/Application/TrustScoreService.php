<?php

namespace App\Modules\Tontine\Application;

use App\Shared\BusinessTime;
use App\Modules\Tontine\Domain\Enums\InstallmentStatus;
use App\Modules\Tontine\Infrastructure\Models\Installment;

/**
 * Score de confiance (0–100) fondé sur la régularité des cotisations (Q-07 : règle MVP).
 *
 *   aucune échéance échue  → 80 (bonne foi par défaut)
 *   sinon                  → 80 − 10 × échéances déjà passées en retard − 20 × échéances en retard actuellement
 *
 * Même formule que EligibilityService (Lending) et le dashboard membre.
 */
class TrustScoreService
{
    public const DEFAULT_SCORE = 80;

    /** @param string[] $membershipIds */
    public function forMemberships(array $membershipIds): int
    {
        if (empty($membershipIds)) {
            return self::DEFAULT_SCORE;
        }

        $counts = Installment::whereIn('membership_id', $membershipIds)
            ->selectRaw('
                COUNT(*) FILTER (WHERE due_date <= ?)             as due,
                COUNT(*) FILTER (WHERE late_marked_at IS NOT NULL) as ever_late,
                COUNT(*) FILTER (WHERE status = ?)                 as late
            ', [BusinessTime::todayDate(), InstallmentStatus::Late->value])
            ->first();

        return self::score((int) $counts->due, (int) $counts->ever_late, (int) $counts->late);
    }

    public static function score(int $due, int $everLate, int $late): int
    {
        if ($due === 0) {
            return self::DEFAULT_SCORE;
        }

        return max(0, min(100, self::DEFAULT_SCORE - $everLate * 10 - $late * 20));
    }

    /**
     * Expression SQL équivalente à score(), à partir de colonnes déjà agrégées
     * (utilisée pour trier la liste des membres côté base).
     */
    public static function sqlExpression(string $due, string $everLate, string $late): string
    {
        $default = self::DEFAULT_SCORE;

        return "CASE WHEN {$due} = 0 THEN {$default}
                     ELSE GREATEST(0, LEAST(100, {$default} - {$everLate} * 10 - {$late} * 20)) END";
    }
}
