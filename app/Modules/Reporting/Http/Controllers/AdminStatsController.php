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
use App\Shared\BusinessTime;
use App\Shared\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

class AdminStatsController extends Controller
{
    public function __construct(private readonly LedgerService $ledger) {}

    public function dashboard(Request $request): JsonResponse
    {
        // Jour local métier (R : stats « du jour » en fuseau Bénin), borne passée en UTC.
        [$today] = BusinessTime::dayBoundsUtc();

        // Encaissements du jour (tous canaux confondus, paiements confirmés)
        $todayPayments = Payment::where('status', 'confirmed')
            ->where('confirmed_at', '>=', $today)
            ->selectRaw('COUNT(*) as count, SUM(amount_minor) as total')
            ->first();

        // Espèces vs agrégateurs (KKiaPay, FedaPay…) du jour — DA-20
        $todayCash = Payment::where('status', 'confirmed')
            ->where('channel', 'cash')
            ->where('confirmed_at', '>=', $today)
            ->sum('amount_minor');

        $todayMomo = Payment::where('status', 'confirmed')
            ->where('channel', '!=', 'cash')
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
                'type'          => 'membership',
                'id'            => $m->id,
                'label'         => $m->user?->full_name ?? '—',
                'sub'           => $m->campaign?->name ?? '—',
                'status'        => $m->status->label(),
                'action_url'    => "/memberships/{$m->id}/validate",
                'created_at'    => $m->created_at?->toIso8601String(),
                // Champs structurés (additifs) — le front n'a plus à parser `sub`.
                'amount_minor'  => null,
                'campaign_name' => $m->campaign?->name,
                'status_code'   => $m->status->value,
                'member_name'   => $m->user?->full_name,
            ]);

        $pendingLoans = LoanRequest::with(['user', 'membership.campaign'])
            ->whereIn('status', ['pending', 'approved', 'countersigning', 'countersigned'])
            ->orderBy('created_at')
            ->limit(10)
            ->get()
            ->map(fn ($r) => [
                'type'       => 'loan_request',
                'id'         => $r->id,
                'label'      => $r->user?->full_name ?? '—',
                'sub'        => Money::format((int) $r->amount_minor) . ' — ' . ($r->membership?->campaign?->name ?? 'Prêt communautaire'),
                'status'     => $r->status->label(),
                'action_url' => "/loans/requests/{$r->id}",
                'created_at' => $r->created_at?->toIso8601String(),
                'amount_minor'  => (int) $r->amount_minor,
                'campaign_name' => $r->membership?->campaign?->name,
                'status_code'   => $r->status->value,
                'member_name'   => $r->user?->full_name,
                'product_type'  => $r->product_type?->value,
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
                'sub'        => Money::format((int) $w->amount_minor) . ' — ' . ($w->membership?->campaign?->name ?? '—'),
                'status'     => $w->status->label(),
                'action_url' => "/withdrawals/{$w->id}",
                'created_at' => $w->created_at?->toIso8601String(),
                'amount_minor'  => (int) $w->amount_minor,
                'campaign_name' => $w->membership?->campaign?->name,
                'status_code'   => $w->status->value,
                'member_name'   => $w->user?->full_name,
            ]);

        $items = collect($pendingMemberships)->concat($pendingLoans)->concat($pendingWithdrawals)
            ->sortBy('created_at')
            ->values();

        return ApiResponse::success($items);
    }

    public function reconciliation(Request $request): JsonResponse
    {
        // Journées en date locale métier (les created_at sont stockés en UTC).
        $from      = $request->query('from', BusinessTime::now()->subDays(7)->toDateString());
        $to        = $request->query('to',   BusinessTime::todayDate());
        $localDate = BusinessTime::localDateSql('created_at');

        $rows = DB::table('payments')
            ->selectRaw("{$localDate} as date,
                channel,
                network,
                status,
                COUNT(*) as count,
                COALESCE(SUM(amount_minor), 0) as total_minor")
            ->whereBetween(DB::raw($localDate), [$from, $to])
            ->groupByRaw("{$localDate}, channel, network, status")
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
                // Réseau rapporté par l'agrégateur (information, DA-20).
                'by_network'            => $confirmed->whereNotNull('network')->groupBy('network')->map(fn ($n) => [
                    'confirmed_minor' => (int) $n->sum('total_minor'),
                    'confirmed_count' => (int) $n->sum('count'),
                ]),
            ];
        });

        return ApiResponse::success($byDate);
    }

    public function membersByCampaign(Request $request): JsonResponse
    {
        $period = $request->query('period', 'month');
        $now    = BusinessTime::now();
        $year   = (int) $request->query('year',  $now->year);
        $month  = (int) $request->query('month', $now->month);

        $frMonths = ['', 'Janvier', 'Février', 'Mars', 'Avril', 'Mai', 'Juin',
                     'Juillet', 'Août', 'Septembre', 'Octobre', 'Novembre', 'Décembre'];

        [$from, $to, $periodLabel] = match ($period) {
            'year'   => [
                $now->copy()->setYear($year)->startOfYear(),
                $now->copy()->setYear($year)->endOfYear(),
                (string) $year,
            ],
            'custom' => [
                BusinessTime::parse($request->query('from', $now->toDateString()))->startOfDay(),
                BusinessTime::parse($request->query('to',   $now->toDateString()))->endOfDay(),
                $request->query('from') . ' – ' . $request->query('to'),
            ],
            default  => [
                $now->copy()->setDay(1)->setYear($year)->setMonth($month)->startOfMonth(),
                $now->copy()->setDay(1)->setYear($year)->setMonth($month)->endOfMonth(),
                ($frMonths[$month] ?? '') . ' ' . $year,
            ],
        };

        // Bornes locales métier → UTC pour la comparaison aux timestamps stockés.
        $from = $from->copy()->utc();
        $to   = $to->copy()->utc();

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
        $now    = BusinessTime::now();
        $year   = (int) $request->query('year',  $now->year);
        $month  = (int) $request->query('month', $now->month);

        $frMonths = ['', 'Janvier', 'Février', 'Mars', 'Avril', 'Mai', 'Juin',
                     'Juillet', 'Août', 'Septembre', 'Octobre', 'Novembre', 'Décembre'];

        [$from, $to, $periodLabel] = match ($period) {
            'year'   => [
                $now->copy()->setYear($year)->startOfYear(),
                $now->copy()->setYear($year)->endOfYear(),
                (string) $year,
            ],
            'custom' => [
                BusinessTime::parse($request->query('from', $now->toDateString()))->startOfDay(),
                BusinessTime::parse($request->query('to',   $now->toDateString()))->endOfDay(),
                $request->query('from') . ' – ' . $request->query('to'),
            ],
            default  => [
                $now->copy()->setDay(1)->setYear($year)->setMonth($month)->startOfMonth(),
                $now->copy()->setDay(1)->setYear($year)->setMonth($month)->endOfMonth(),
                ($frMonths[$month] ?? '') . ' ' . $year,
            ],
        };

        // Bornes locales métier → UTC pour la comparaison aux timestamps stockés.
        $from = $from->copy()->utc();
        $to   = $to->copy()->utc();

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
                'network'      => $p->network,
                'channel_label' => $p->channel?->label(),
                'member'       => $p->membership?->user?->full_name ?? '—',
                'campaign'     => $p->membership?->campaign?->name ?? '—',
                'confirmed_at' => $p->confirmed_at?->toIso8601String(),
            ]);

        return ApiResponse::success($payments);
    }

    /**
     * Tendances du tableau de bord : série journalière des encaissements (cash vs MoMo),
     * variations mois en cours vs même période du mois précédent, répartition par canal.
     * Les journées sont calculées dans le fuseau métier (kafo.business_timezone ; timestamps stockés en UTC).
     */
    public function trends(Request $request): JsonResponse
    {
        $days = (int) $request->query('days', 7) === 30 ? 30 : 7;
        $now  = BusinessTime::now();

        // Série journalière
        $seriesFrom = $now->copy()->subDays($days - 1)->startOfDay();
        $localDate  = BusinessTime::localDateSql('confirmed_at');

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
