<?php

namespace App\Modules\Tontine\Jobs;

use App\Modules\Notifications\Application\NotificationService;
use App\Modules\Tontine\Domain\Enums\InstallmentStatus;
use App\Modules\Tontine\Domain\Enums\MembershipStatus;
use App\Modules\Tontine\Infrastructure\Models\Installment;
use App\Modules\Tontine\Infrastructure\Models\Membership;
use App\Shared\BusinessTime;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Job quotidien (B-03, R-SCHED-03) : passe `late` les échéances encore `pending` dont la
 * fin de période de grâce est dépassée (jour local métier), pose `late_marked_at` UNE fois
 * (jamais effacé) et notifie chaque membre concerné (une notification groupée).
 *
 * Adhésions actives ou suspendues uniquement (une suspension n'efface pas les obligations,
 * R-MEMB-05) ; une adhésion clôturée n'est plus suivie. Idempotent : relancé le même jour,
 * il ne trouve plus rien à marquer.
 */
class MarkLateInstallmentsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(NotificationService $notifications): void
    {
        $today = BusinessTime::todayDate();

        $marked = DB::transaction(function () use ($today) {
            $rows = Installment::query()
                ->where('status', InstallmentStatus::Pending->value)
                ->whereDate('grace_period_ends_date', '<', $today)
                ->whereIn('membership_id', Membership::whereIn('status', [
                    MembershipStatus::Active->value, MembershipStatus::Suspended->value,
                ])->select('id'))
                ->lockForUpdate()
                ->get(['id', 'membership_id']);

            if ($rows->isNotEmpty()) {
                Installment::whereIn('id', $rows->pluck('id'))->update([
                    'status'         => InstallmentStatus::Late->value,
                    'late_marked_at' => now(),
                ]);
            }

            return $rows->countBy('membership_id');
        });

        $memberships = Membership::with('campaign:id,name')->whereIn('id', $marked->keys())->get(['id', 'user_id', 'campaign_id']);
        foreach ($memberships as $m) {
            $n = $marked[$m->id];
            $notifications->notify(
                $m->user_id,
                'installment.late',
                $n > 1 ? "{$n} cotisations en retard" : 'Cotisation en retard',
                ($n > 1 ? "{$n} échéances" : 'Une échéance') . ' de la campagne « ' . ($m->campaign?->name ?? '—')
                    . ' » ' . ($n > 1 ? 'ont dépassé' : 'a dépassé') . ' leur délai de grâce. Pensez à régulariser.',
                '/echeancier',
            );
        }

        Log::info('MarkLateInstallmentsJob: ' . $marked->sum() . " échéance(s) marquée(s) en retard (jour {$today}).");
    }
}
