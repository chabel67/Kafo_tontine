<?php

namespace App\Modules\Lending\Application;

use App\Modules\Ledger\Application\LedgerService;
use App\Modules\Lending\Domain\Enums\LoanProductType;
use App\Modules\Lending\Infrastructure\Models\Loan;
use App\Modules\Tontine\Domain\Enums\MembershipStatus;
use App\Modules\Tontine\Infrastructure\Models\Installment;
use App\Modules\Tontine\Infrastructure\Models\Membership;

class EligibilityService
{
    /** Minimum days a membership must be active before requesting an advance. */
    private const MIN_MEMBERSHIP_AGE_DAYS = 30;
    /** Minimum trust score to be eligible. */
    private const MIN_TRUST_SCORE = 60;
    /** Amount threshold requiring a countersign (maker/checker). */
    public const COUNTERSIGN_THRESHOLD = 500_000;

    public function __construct(private readonly LedgerService $ledger) {}

    /**
     * Snapshot informatif — ne bloque JAMAIS l'octroi (R-LOAN-11).
     * La décision reste discrétionnaire du staff.
     *
     * `eligible=true` toujours. Les autres clés (savings, trust, coefficient)
     * restent posées à titre d'aide à la décision.
     */
    public function snapshotFor(?Membership $membership, LoanProductType $type): array
    {
        if ($membership === null) {
            return [
                'eligible'          => true,
                'product_type'      => $type->value,
                'reasons'           => [],
                'trust_score'       => null,
                'coefficient'       => null,
                'savings_minor'     => 0,
                'max_amount_minor'  => null,
                'checked_at'        => now()->toIso8601String(),
            ];
        }

        $trustScore  = $this->computeTrustScore($membership);
        $coefficient = $this->scoreToCoefficient($trustScore);
        $savings     = $this->ledger->balance("MEMBER_SAVINGS:{$membership->id}");

        return [
            'eligible'         => true,
            'product_type'     => $type->value,
            'reasons'          => [],
            'trust_score'      => $trustScore,
            'coefficient'      => $coefficient,
            'savings_minor'    => $savings,
            'max_amount_minor' => (int) floor($savings * $coefficient),
            'checked_at'       => now()->toIso8601String(),
        ];
    }

    /**
     * @deprecated depuis R-LOAN-11 : les conditions dures ne sont plus
     * appliquées côté runtime. Conservé pour reporting/futur.
     */
    public function check(Membership $membership): array
    {
        $reasons     = [];
        $trustScore  = $this->computeTrustScore($membership);
        $coefficient = $this->scoreToCoefficient($trustScore);
        $savings     = $this->ledger->balance("MEMBER_SAVINGS:{$membership->id}");
        $maxAmount   = (int) floor($savings * $coefficient);

        // Rule: membership must be active
        if ($membership->status !== MembershipStatus::Active) {
            $reasons[] = 'Adhésion non active';
        }

        // Rule: minimum age
        if ($membership->activated_at) {
            $ageDays = (int) $membership->activated_at->diffInDays(now());
            if ($ageDays < self::MIN_MEMBERSHIP_AGE_DAYS) {
                $remaining = self::MIN_MEMBERSHIP_AGE_DAYS - $ageDays;
                $reasons[] = "Adhésion trop récente (encore {$remaining} jour(s) requis)";
            }
        } else {
            $reasons[] = 'Date d\'activation inconnue';
        }

        // Rule: minimum trust score
        if ($trustScore < self::MIN_TRUST_SCORE) {
            $reasons[] = "Score de confiance insuffisant ({$trustScore}/100, minimum " . self::MIN_TRUST_SCORE . ')';
        }

        // Rule: positive savings
        if ($savings <= 0) {
            $reasons[] = 'Aucune épargne disponible';
        }

        // Rule: no active loan for this membership
        $activeLoan = Loan::where('membership_id', $membership->id)
            ->where('status', 'active')
            ->exists();
        if ($activeLoan) {
            $reasons[] = 'Une avance est déjà en cours pour cette adhésion';
        }

        return [
            'eligible'        => empty($reasons),
            'reasons'         => $reasons,
            'trust_score'     => $trustScore,
            'coefficient'     => $coefficient,
            'savings_minor'   => $savings,
            'max_amount_minor' => $maxAmount,
            'checked_at'      => now()->toIso8601String(),
        ];
    }

    /**
     * Trust score (0–100) based on installment regularity.
     * Base 80 — 10 per ever-late installment, -20 if currently late.
     */
    private function computeTrustScore(Membership $membership): int
    {
        $totalDue    = Installment::where('membership_id', $membership->id)
            ->where('due_date', '<=', now())
            ->count();

        if ($totalDue === 0) {
            return 80; // no history yet → default good faith score
        }

        $everLate    = Installment::where('membership_id', $membership->id)
            ->whereNotNull('late_marked_at')
            ->count();

        $currentlyLate = Installment::where('membership_id', $membership->id)
            ->where('status', 'late')
            ->count();

        $score = 80 - ($everLate * 10) - ($currentlyLate * 20);

        return max(0, min(100, $score));
    }

    private function scoreToCoefficient(int $score): float
    {
        if ($score >= 75) return 0.70;
        if ($score >= 60) return 0.60;
        return 0.50; // below min — will be caught by eligibility check
    }
}
