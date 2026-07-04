<?php

namespace App\Modules\Lending\Jobs;

use App\Modules\Lending\Domain\Enums\LoanStatus;
use App\Modules\Lending\Domain\Enums\RepaymentScheduleStatus;
use App\Modules\Lending\Infrastructure\Models\Loan;
use App\Modules\Lending\Infrastructure\Models\LoanRepaymentSchedule;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Job quotidien — marque `late` les items d'échéancier dépassés (R-LOAN-13).
 *
 * Ne touche que les prêts standards `active`. La période de grâce
 * (`grace_period_days`) est prise sur `settings.loan_grace_period_days`, ou
 * défaut 0 (pas de grâce).
 *
 * Met aussi à jour `Loan::next_due_date` avec la prochaine échéance non
 * réglée pour affichage rapide sur le dashboard.
 */
class MarkLateLoanRepaymentsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        $grace  = (int) (config('kafo.loan_grace_period_days', 0));
        $cutoff = Carbon::today()->subDays($grace);

        $lateCount = LoanRepaymentSchedule::query()
            ->where('status', RepaymentScheduleStatus::Pending->value)
            ->whereDate('due_date', '<', $cutoff)
            ->update([
                'status'         => RepaymentScheduleStatus::Late->value,
                'late_marked_at' => now(),
            ]);

        // Met à jour next_due_date pour tous les prêts standards actifs
        // qui ont encore des items pending/late.
        $activeLoans = Loan::where('status', LoanStatus::Active)
            ->whereNotNull('installments_count')
            ->get(['id']);

        foreach ($activeLoans as $loan) {
            $next = LoanRepaymentSchedule::where('loan_id', $loan->id)
                ->whereIn('status', [
                    RepaymentScheduleStatus::Pending->value,
                    RepaymentScheduleStatus::Late->value,
                ])
                ->orderBy('due_date')
                ->first();
            $loan->update(['next_due_date' => $next?->due_date?->toDateString()]);
        }

        Log::info("MarkLateLoanRepaymentsJob: {$lateCount} item(s) marqué(s) late (cutoff={$cutoff->toDateString()}).");
    }
}
