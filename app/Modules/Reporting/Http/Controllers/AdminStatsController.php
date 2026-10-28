<?php

namespace App\Modules\Reporting\Http\Controllers;

use App\Modules\Ledger\Application\LedgerService;
use App\Modules\Lending\Infrastructure\Models\Loan;
use App\Modules\Lending\Infrastructure\Models\LoanRequest;
use App\Modules\Payments\Infrastructure\Models\Payment;
use App\Modules\Tontine\Domain\Enums\EarlyWithdrawalStatus;
use App\Modules\Tontine\Infrastructure\Models\EarlyWithdrawal;
use App\Modules\Tontine\Infrastructure\Models\Installment;
use App\Modules\Tontine\Infrastructure\Models\Membership;
use App\Shared\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

class AdminStatsController extends Controller
{
    public function __construct(private readonly LedgerService $ledger) {}

    public function dashboard(Request $request): JsonResponse
    {
        $today = now()->startOfDay();

        // Encaissements du jour (tous canaux confondus, paiements confirmés)
        $todayPayments = Payment::where('status', 'confirmed')
            ->where('confirmed_at', '>=', $today)
            ->selectRaw('COUNT(*) as count, SUM(amount_minor) as total')
            ->first();

        // Cash vs MoMo du jour
        $todayCash = Payment::where('status', 'confirmed')
            ->where('channel', 'cash')
            ->where('confirmed_at', '>=', $today)
            ->sum('amount_minor');

        $todayMomo = Payment::where('status', 'confirmed')
            ->whereIn('channel', ['mtn', 'orange', 'moov', 'wave'])
            ->where('confirmed_at', '>=', $today)
            ->sum('amount_minor');

        // Adhésions
        $membresActifs        = Membership::where('status', 'active')->count();
        $validationsEnAttente = Membership::whereIn('status', ['pending_l1', 'pending_l2'])->count();

        // Avances
        $avancesActives = Loan::where('status', 'active')
            ->selectRaw('COUNT(*) as count, SUM(outstanding_minor) as encours')
            ->first();

        $demandesEnAttente = LoanRequest::whereIn('status', ['pending', 'approved', 'countersigning', 'countersigned'])
            ->count();

        // Taux de recouvrement = (principal - encours actuel) / principal décaissé × 100
        $loans = Loan::whereIn('status', ['active', 'repaid'])->get();
        $totalPrincipal = $loans->sum('principal_minor');
        $totalRepaid    = $loans->sum(fn ($l) => $l->principal_minor - $l->outstanding_minor);
        $tauxRecouvrement = $totalPrincipal > 0 ? round($totalRepaid / $totalPrincipal * 100, 1) : 100.0;

        // Échéances en retard
        $echeancesRetard = Installment::where('status', 'late')->count();

        // Trésorerie (solde caisse estimé = balance CASH_BOX)
        $soldeCaisse = 0;
        try { $soldeCaisse = $this->ledger->balance('CASH_BOX'); } catch (\Throwable) {}

        return ApiResponse::success([
            'encaissements_jour' => [
                'total' => (int) ($todayPayments->total ?? 0),
                'count' => (int) ($todayPayments->count ?? 0),
                'cash'  => (int) $todayCash,
                'momo'  => (int) $todayMomo,
            ],
            'membres_actifs'            => $membresActifs,
            'validations_en_attente'    => $validationsEnAttente,
            'avances_actives'           => [
                'count'   => (int) ($avancesActives->count ?? 0),
                'encours' => (int) ($avancesActives->encours ?? 0),
            ],
            'demandes_avance_en_attente' => $demandesEnAttente,
            'taux_recouvrement'          => $tauxRecouvrement,
            'echeances_retard'           => $echeancesRetard,
            'solde_caisse'               => $soldeCaisse,
        ]);
    }

    public function workQueue(): JsonResponse
    {
        $pendingMemberships = Membership::with(['user', 'campaign'])
            ->whereIn('status', ['pending_l1', 'pending_l2'])
            ->orderBy('created_at')
            ->limit(10)
            ->get()
            ->map(fn ($m) => [
                'type'        => 'membership',
                'id'          => $m->id,
                'label'       => $m->user?->full_name ?? '—',
                'sub'         => $m->campaign?->name ?? '—',
                'status'      => $m->status->label(),
                'action_url'  => "/memberships/{$m->id}/validate",
                'created_at'  => $m->created_at?->toIso8601String(),
            ]);

        $pendingLoans = LoanRequest::with(['membership.user', 'membership.campaign'])
            ->whereIn('status', ['pending', 'approved', 'countersigning', 'countersigned'])
            ->orderBy('created_at')
            ->limit(10)
            ->get()
            ->map(fn ($r) => [
                'type'       => 'loan_request',
                'id'         => $r->id,
                'label'      => $r->membership?->user?->full_name ?? '—',
                'sub'        => number_format($r->amount_minor) . ' XOF — ' . ($r->membership?->campaign?->name ?? '—'),
                'status'     => $r->status->label(),
                'action_url' => "/loans/requests/{$r->id}",
                'created_at' => $r->created_at?->toIso8601String(),
            ]);

        $pendingWithdrawals = EarlyWithdrawal::with(['user', 'membership.campaign'])
            ->whereIn('status', EarlyWithdrawalStatus::committed())
            ->orderBy('created_at')
            ->limit(10)
            ->get()
            ->map(fn ($w) => [
                'type'       => 'early_withdrawal',
                'id'         => $w->id,
                'label'      => $w->user?->full_name ?? '—',
                'sub'        => number_format($w->amount_minor) . ' XOF — ' . ($w->membership?->campaign?->name ?? '—'),
                'status'     => $w->status->label(),
                'action_url' => "/withdrawals/{$w->id}",
                'created_at' => $w->created_at?->toIso8601String(),
            ]);

        $items = collect($pendingMemberships)->concat($pendingLoans)->concat($pendingWithdrawals)
            ->sortBy('created_at')
            ->values();

        return ApiResponse::success($items);
    }

    public function reconciliation(Request $request): JsonResponse
    {
        $from = $request->query('from', now()->subDays(7)->toDateString());
        $to   = $request->query('to',   now()->toDateString());

        $rows = DB::table('payments')
            ->selectRaw("DATE(created_at) as date,
                channel,
                status,
                COUNT(*) as count,
                COALESCE(SUM(amount_minor), 0) as total_minor")
            ->whereBetween(DB::raw("DATE(created_at)"), [$from, $to])
            ->groupByRaw('DATE(created_at), channel, status')
            ->orderByDesc('date')
            ->orderBy('channel')
            ->get();

        // Group by date for easy frontend consumption
        $byDate = $rows->groupBy('date')->map(function ($dayRows) {
            $confirmed   = $dayRows->where('status', 'confirmed');
            $pending     = $dayRows->where('status', 'pending');
            $failed      = $dayRows->where('status', 'failed');

            return [
                'total_confirmed_minor' => (int) $confirmed->sum('total_minor'),
                'total_confirmed_count' => (int) $confirmed->sum('count'),
                'total_pending_minor'   => (int) $pending->sum('total_minor'),
                'total_pending_count'   => (int) $pending->sum('count'),
                'total_failed_count'    => (int) $failed->sum('count'),
                'by_channel'            => $dayRows->groupBy('channel')->map(fn ($ch) => [
                    'confirmed_minor' => (int) $ch->where('status', 'confirmed')->sum('total_minor'),
                    'confirmed_count' => (int) $ch->where('status', 'confirmed')->sum('count'),
                    'pending_count'   => (int) $ch->where('status', 'pending')->sum('count'),
                    'failed_count'    => (int) $ch->where('status', 'failed')->sum('count'),
                ]),
            ];
        });

        return ApiResponse::success($byDate);
    }

    public function membersByCampaign(Request $request): JsonResponse
    {
        $period = $request->query('period', 'month');
        $year   = (int) $request->query('year',  now()->year);
        $month  = (int) $request->query('month', now()->month);

        $frMonths = ['', 'Janvier', 'Février', 'Mars', 'Avril', 'Mai', 'Juin',
                     'Juillet', 'Août', 'Septembre', 'Octobre', 'Novembre', 'Décembre'];

        [$from, $to, $periodLabel] = match ($period) {
            'year'   => [
                now()->setYear($year)->startOfYear(),
                now()->setYear($year)->endOfYear(),
                (string) $year,
            ],
            'custom' => [
                now()->parse($request->query('from', now()->toDateString()))->startOfDay(),
                now()->parse($request->query('to',   now()->toDateString()))->endOfDay(),
                $request->query('from') . ' – ' . $request->query('to'),
            ],
            default  => [
                now()->setYear($year)->setMonth($month)->startOfMonth(),
                now()->setYear($year)->setMonth($month)->endOfMonth(),
                ($frMonths[$month] ?? '') . ' ' . $year,
            ],
        };

        $rows = DB::table('memberships')
            ->join('tontine_campaigns', 'memberships.campaign_id', '=', 'tontine_campaigns.id')
            ->whereBetween('memberships.created_at', [$from, $to])
            ->groupBy('tontine_campaigns.id', 'tontine_campaigns.name')
            ->selectRaw('tontine_campaigns.name as campaign_name, COUNT(memberships.id) as total')
            ->orderByDesc('total')
            ->get();

        return ApiResponse::success([
            'labels'       => $rows->pluck('campaign_name')->all(),
            'values'       => $rows->pluck('total')->map(fn ($v) => (int) $v)->all(),
            'period_label' => $periodLabel,
            'total'        => (int) $rows->sum('total'),
        ]);
    }

    public function collectionsByCampaign(Request $request): JsonResponse
    {
        $period = $request->query('period', 'month');
        $year   = (int) $request->query('year',  now()->year);
        $month  = (int) $request->query('month', now()->month);

        $frMonths = ['', 'Janvier', 'Février', 'Mars', 'Avril', 'Mai', 'Juin',
                     'Juillet', 'Août', 'Septembre', 'Octobre', 'Novembre', 'Décembre'];

        [$from, $to, $periodLabel] = match ($period) {
            'year'   => [
                now()->setYear($year)->startOfYear(),
                now()->setYear($year)->endOfYear(),
                (string) $year,
            ],
            'custom' => [
                now()->parse($request->query('from', now()->toDateString()))->startOfDay(),
                now()->parse($request->query('to',   now()->toDateString()))->endOfDay(),
                $request->query('from') . ' – ' . $request->query('to'),
            ],
            default  => [
                now()->setYear($year)->setMonth($month)->startOfMonth(),
                now()->setYear($year)->setMonth($month)->endOfMonth(),
                ($frMonths[$month] ?? '') . ' ' . $year,
            ],
        };

        $rows = DB::table('payments')
            ->join('memberships',       'payments.membership_id',   '=', 'memberships.id')
            ->join('tontine_campaigns', 'memberships.campaign_id',  '=', 'tontine_campaigns.id')
            ->where('payments.status', 'confirmed')
            ->whereBetween('payments.confirmed_at', [$from, $to])
            ->groupBy('tontine_campaigns.id', 'tontine_campaigns.name')
            ->selectRaw('tontine_campaigns.name as campaign_name, COALESCE(SUM(payments.amount_minor), 0) as total_minor')
            ->orderByDesc('total_minor')
            ->get();

        return ApiResponse::success([
            'labels'       => $rows->pluck('campaign_name')->all(),
            'values'       => $rows->pluck('total_minor')->map(fn ($v) => (int) $v)->all(),
            'period_label' => $periodLabel,
            'total'        => (int) $rows->sum('total_minor'),
        ]);
    }

    public function recentPayments(): JsonResponse
    {
        $payments = Payment::with(['membership.user', 'membership.campaign'])
            ->where('status', 'confirmed')
            ->orderByDesc('confirmed_at')
            ->limit(10)
            ->get()
            ->map(fn ($p) => [
                'id'           => $p->id,
                'reference'    => $p->reference,
                'amount_minor' => $p->amount_minor,
                'channel'      => $p->channel?->value,
                'channel_label' => $p->channel_label,
                'member'       => $p->membership?->user?->full_name ?? '—',
                'campaign'     => $p->membership?->campaign?->name ?? '—',
                'confirmed_at' => $p->confirmed_at?->toIso8601String(),
            ]);

        return ApiResponse::success($payments);
    }

    /**
     * Tendances du tableau de bord : série journalière des encaissements (cash vs MoMo),
     * variations mois en cours vs même période du mois précédent, répartition par canal.
     * Les journées sont calculées dans le fuseau de l'application (les timestamps sont stockés en UTC).
     */
    public function trends(Request $request): JsonResponse
    {
        $tz   = config('app.timezone', 'UTC');
        $days = (int) $request->query('days', 7) === 30 ? 30 : 7;
        $now  = now($tz);

        // Série journalière
        $seriesFrom = $now->copy()->subDays($days - 1)->startOfDay();
        $localDate  = "DATE((confirmed_at AT TIME ZONE 'UTC') AT TIME ZONE '{$tz}')";

        $rows = Payment::where('status', 'confirmed')
            ->where('confirmed_at', '>=', $seriesFrom->copy()->utc())
            ->selectRaw("{$localDate} as date,
                SUM(CASE WHEN channel = 'cash' THEN amount_minor ELSE 0 END) as cash,
                SUM(CASE WHEN channel <> 'cash' THEN amount_minor ELSE 0 END) as momo")
            ->groupByRaw($localDate)
            ->get()
            ->keyBy(fn ($r) => (string) $r->date);

        $series = [];
        for ($d = $seriesFrom->copy(); $d->lte($now); $d->addDay()) {
            $row  = $rows->get($d->toDateString());
            $cash = (int) ($row->cash ?? 0);
            $momo = (int) ($row->momo ?? 0);
            $series[] = ['date' => $d->toDateString(), 'cash' => $cash, 'momo' => $momo, 'total' => $cash + $momo];
        }

        // Mois en cours (jusqu'à maintenant) vs même durée du mois précédent
        $curFrom  = $now->copy()->startOfMonth();
        $prevFrom = $curFrom->copy()->subMonthNoOverflow();
        $prevTo   = $prevFrom->copy()->addSeconds($curFrom->diffInSeconds($now));

        $period = fn (string $model, string $column, string $agg) => function ($from, $to) use ($model, $column, $agg) {
            $q = $model::query()->whereBetween($column, [$from->copy()->utc(), $to->copy()->utc()]);
            if ($model === Payment::class) $q->where('status', 'confirmed');
            return (int) ($agg === 'count' ? $q->count() : $q->sum($agg));
        };

        $metrics = [
            'encaissements'      => $period(Payment::class,    'confirmed_at', 'amount_minor'),
            'nouveaux_membres'   => $period(Membership::class, 'activated_at', 'count'),
            'avances_decaissees' => $period(Loan::class,       'disbursed_at', 'principal_minor'),
        ];

        $deltas = [];
        foreach ($metrics as $key => $fn) {
            $current  = $fn($curFrom, $now);
            $previous = $fn($prevFrom, $prevTo);
            $deltas[$key] = [
                'current'  => $current,
                'previous' => $previous,
                'pct'      => $previous > 0 ? round(($current - $previous) / $previous * 100, 1) : null,
            ];
        }

        // Répartition des encaissements du mois par canal
        $channels = Payment::where('status', 'confirmed')
            ->where('confirmed_at', '>=', $curFrom->copy()->utc())
            ->selectRaw('channel, SUM(amount_minor) as total')
            ->groupBy('channel')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($r) => [
                'channel' => $r->channel instanceof \BackedEnum ? $r->channel->value : (string) $r->channel,
                'total'   => (int) $r->total,
            ])
            ->values();

        return ApiResponse::success([
            'days'     => $days,
            'series'   => $series,
            'deltas'   => $deltas,
            'channels' => $channels,
        ]);
    }
}
