<?php

namespace App\Modules\Lending\Application;

use App\Modules\Lending\Domain\Enums\LoanPeriodicity;
use App\Modules\Lending\Domain\Enums\LoanProductType;
use App\Modules\Lending\Domain\Enums\RepaymentScheduleStatus;
use App\Modules\Lending\Infrastructure\Models\Loan;
use App\Modules\Lending\Infrastructure\Models\LoanRepaymentSchedule;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Génère un échéancier de remboursement pour un prêt `standard`.
 *
 * Ne s'applique PAS aux avances (`advance`) : elles sont compensées à la
 * clôture campagne via CampaignPayoutService et n'ont donc pas d'items ici.
 *
 * ## Calcul du capital
 * Réparti linéairement sur `installments_count` échéances. Le dernier item
 * absorbe le reliquat d'arrondi pour que la somme des principaux soit
 * exactement égale à `Loan::principal_minor`.
 *
 * ## Calcul de l'intérêt (simple linéaire)
 * `interest_total = round(principal * rate_bps/10000 * duration_years)`
 * où `duration_years = (nb_jours_entre_premier_et_dernier_due) / 365`.
 * Réparti à parts égales, dernier item absorbe l'arrondi. Si `rate_bps` est
 * NULL ou 0, `interest_total = 0` et aucune ligne d'intérêt n'est posée
 * (le ledger n'aura pas de leg REVENUE_INTEREST au remboursement).
 *
 * ## Périodicités
 * - `weekly` : `first_due_date + 7*i` pour i∈[0..count-1]
 * - `monthly` : `first_due_date + i mois` (garde le jour du mois si possible)
 * - `custom` : itère `custom_due_dates` (validées non vides côté FormRequest)
 */
class RepaymentScheduleGeneratorService
{
    /**
     * Génère et persiste les items du schedule, met à jour Loan
     * (`interest_total_minor`, `next_due_date`).
     *
     * @return Collection<int, LoanRepaymentSchedule>
     */
    public function generate(Loan $loan): Collection
    {
        if ($loan->product_type !== LoanProductType::Standard) {
            throw new \LogicException('Only standard loans have a repayment schedule.');
        }

        $dueDates = $this->buildDueDates($loan);
        $count    = count($dueDates);
        if ($count === 0) {
            throw new \LogicException('Cannot generate schedule with 0 items.');
        }

        $principals = $this->splitLinear($loan->principal_minor, $count);
        $interests  = $this->splitInterest($loan, $dueDates, $count);

        return DB::transaction(function () use ($loan, $dueDates, $principals, $interests) {
            $rows = [];
            foreach ($dueDates as $i => $due) {
                $principal = $principals[$i];
                $interest  = $interests[$i];
                $rows[] = [
                    'id'              => (string) Str::uuid(),
                    'loan_id'         => $loan->id,
                    'sequence_number' => $i + 1,
                    'due_date'        => $due->toDateString(),
                    'principal_minor' => $principal,
                    'interest_minor'  => $interest,
                    'amount_minor'    => $principal + $interest,
                    'paid_minor'      => 0,
                    'status'          => RepaymentScheduleStatus::Pending->value,
                    'created_at'      => now(),
                    'updated_at'      => now(),
                ];
            }
            LoanRepaymentSchedule::insert($rows);

            $loan->update([
                'interest_total_minor' => array_sum($interests),
                'next_due_date'        => $dueDates[0]->toDateString(),
            ]);

            return $loan->schedules()->get();
        });
    }

    /** @return Carbon[] */
    private function buildDueDates(Loan $loan): array
    {
        return match ($loan->periodicity) {
            LoanPeriodicity::Custom => array_map(
                fn($d) => Carbon::parse($d)->startOfDay(),
                $loan->custom_due_dates ?? [],
            ),
            LoanPeriodicity::Weekly, LoanPeriodicity::Monthly => (function () use ($loan) {
                $first = Carbon::parse($loan->first_due_date)->startOfDay();
                $out   = [];
                for ($i = 0; $i < (int) $loan->installments_count; $i++) {
                    $out[] = $loan->periodicity->nextDueDate($first, $i);
                }
                return $out;
            })(),
            null => throw new \LogicException('Loan periodicity is required for standard product.'),
        };
    }

    /**
     * Répartit [$total] en [$count] parts entières. Les [count-1] premières
     * sont égales à floor(total/count) ; la dernière absorbe le reliquat.
     * Garantit sum = $total.
     *
     * @return int[]
     */
    private function splitLinear(int $total, int $count): array
    {
        if ($count === 1) return [$total];
        $base   = intdiv($total, $count);
        $parts  = array_fill(0, $count - 1, $base);
        $parts[] = $total - $base * ($count - 1);
        return $parts;
    }

    /** @param Carbon[] $dueDates @return int[] */
    private function splitInterest(Loan $loan, array $dueDates, int $count): array
    {
        $rate = $loan->interest_rate_bps;
        if ($rate === null || $rate === 0) {
            return array_fill(0, $count, 0);
        }

        // Duration en années entre la date de décaissement (aujourd'hui) et
        // la dernière échéance. Approximation simple linéaire pour MVP.
        $start = $loan->disbursed_at ? Carbon::parse($loan->disbursed_at) : now();
        $end   = end($dueDates);
        $durationDays = max(1, (int) $start->startOfDay()->diffInDays($end));
        $durationYears = $durationDays / 365;

        $total = (int) round($loan->principal_minor * ($rate / 10000) * $durationYears);
        return $this->splitLinear($total, $count);
    }

    /**
     * Applique un remboursement au prochain item payable (FIFO).
     * Convention R-LOAN-14 : intérêt d'abord, puis principal.
     * Le paiement peut couvrir plusieurs items partiellement.
     *
     * @return array{principal:int, interest:int} portions cumulées effectivement affectées.
     */
    public function applyPayment(Loan $loan, int $received): array
    {
        $remaining     = $received;
        $totalInterest = 0;
        $totalPrincipal = 0;

        $items = $loan->schedules()
            ->whereIn('status', [
                RepaymentScheduleStatus::Pending->value,
                RepaymentScheduleStatus::Late->value,
            ])
            ->orderBy('sequence_number')
            ->lockForUpdate()
            ->get();

        foreach ($items as $item) {
            if ($remaining <= 0) break;

            $unpaid = $item->remainingMinor();
            if ($unpaid <= 0) continue;

            // Split : intérêt en premier, puis principal.
            $unpaidInterest  = max(0, $item->interest_minor - min($item->paid_minor, $item->interest_minor));
            $unpaidPrincipal = $unpaid - $unpaidInterest;

            $applyInterest = min($remaining, $unpaidInterest);
            $remaining     -= $applyInterest;
            $totalInterest += $applyInterest;

            $applyPrincipal = min($remaining, $unpaidPrincipal);
            $remaining      -= $applyPrincipal;
            $totalPrincipal += $applyPrincipal;

            $newPaid = $item->paid_minor + $applyInterest + $applyPrincipal;
            $updates = ['paid_minor' => $newPaid];
            if ($newPaid >= $item->amount_minor) {
                $updates['status']  = RepaymentScheduleStatus::Paid->value;
                $updates['paid_at'] = now();
            }
            $item->update($updates);
        }

        // Rafraîchit next_due_date sur le loan
        $next = $loan->schedules()
            ->whereIn('status', [
                RepaymentScheduleStatus::Pending->value,
                RepaymentScheduleStatus::Late->value,
            ])
            ->orderBy('due_date')
            ->first();
        $loan->update(['next_due_date' => $next?->due_date?->toDateString()]);

        return ['principal' => $totalPrincipal, 'interest' => $totalInterest];
    }
}
