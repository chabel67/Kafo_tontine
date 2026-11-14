<?php

namespace App\Modules\Tontine\Application;

use App\Modules\Tontine\Domain\Enums\InstallmentStatus;
use App\Modules\Tontine\Domain\Enums\MembershipStatus;
use App\Modules\Tontine\Infrastructure\Models\Campaign;
use App\Modules\Tontine\Infrastructure\Models\Installment;
use App\Modules\Tontine\Infrastructure\Models\Membership;
use App\Shared\BusinessTime;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Suivi des cotisations (EF-CAMP-05) : qui a payé, qui n'a pas encore payé.
 *
 * Lecture seule. Statut affiché d'une échéance (même règle sur le web et le mobile) :
 *   paid     payée                       waived  annulée
 *   late     marquée en retard (job)     partial payée en partie, reste > 0
 *   due      échue, non payée, en grâce  upcoming  échéance future
 *
 * Adhésions suivies : toutes sauf celles en attente de validation (pas d'échéance).
 */
class InstallmentTrackingService
{
    public const STATUSES = ['paid', 'partial', 'due', 'late', 'upcoming', 'waived'];

    /** Statuts « non payé » (filtre « Non payés »). */
    public const UNPAID = ['partial', 'due', 'late'];

    private const GRID_PER_PAGE = 50;

    /**
     * Dates d'échéance de la campagne, avec attendu / encaissé / comptes par statut,
     * et la date proposée par défaut.
     */
    public function dueDates(Campaign $campaign): array
    {
        $today = BusinessTime::todayDate();
        $rows  = $this->installments($campaign)->get();

        $dates = $rows->groupBy(fn (Installment $i) => $i->due_date->toDateString())
            ->map(function (Collection $items, string $date) use ($today, $campaign) {
                $counts = $this->counts($items, $today);

                return [
                    'due_date'        => $date,
                    'is_past'         => $date < $today,
                    'is_today'        => $date === $today,
                    'round_number'    => $this->roundFor($campaign, (int) $items->first()->sequence_number),
                    'members_count'   => $items->count(),
                    'expected_minor'  => (int) $items->sum(fn (Installment $i) => $this->dueAmount($i)),
                    'collected_minor' => (int) $items->sum(fn (Installment $i) => $this->paidAmount($i)),
                    'counts'          => $counts,
                    'paid_count'      => $counts['paid'],
                    'unpaid_count'    => $counts['partial'] + $counts['due'] + $counts['late'],
                ];
            })
            ->sortKeys()
            ->values();

        return [
            'default_due_date' => $this->defaultDate($dates->pluck('due_date'), $today),
            'today'            => $today,
            'dates'            => $dates->all(),
        ];
    }

    /**
     * Une ligne par adhésion ayant une échéance à cette date, non-payés d'abord.
     *
     * @param  'paid'|'unpaid'|'late'|string|null  $filter
     */
    public function byDueDate(Campaign $campaign, string $date, ?string $filter = null, ?string $search = null): array
    {
        $today = BusinessTime::todayDate();
        $items = $this->installments($campaign)
            ->whereDate('installments.due_date', $date)
            ->with('membership.user:id,full_name,phone')
            ->get();

        $lateCounts = $this->lateCountsByMembership($campaign);
        $lastPaid   = $this->lastPaymentByInstallment($items->pluck('id')->all());

        $rows = $items->map(function (Installment $i) use ($today, $lateCounts, $lastPaid) {
            $status = $this->displayStatus($i, $today);

            return [
                'installment_id'    => $i->id,
                'membership_id'     => $i->membership_id,
                'member_id'         => $i->membership?->user_id,
                'member_name'       => $i->membership?->user?->full_name,
                'member_phone'      => $i->membership?->user?->phone,
                'membership_status' => $i->membership?->status?->value,
                'sequence_number'   => (int) $i->sequence_number,
                'due_date'          => $i->due_date->toDateString(),
                'amount_minor'      => $this->dueAmount($i),
                'paid_minor'        => $this->paidAmount($i),
                'remaining_minor'   => $i->remainingMinor(),
                'status'            => $status,
                'last_payment_at'   => $lastPaid[$i->id] ?? null,
                'late_count'        => (int) ($lateCounts[$i->membership_id] ?? 0),
            ];
        });

        $totals = [
            'members_count'   => $rows->count(),
            'expected_minor'  => (int) $rows->sum('amount_minor'),
            'collected_minor' => (int) $rows->sum('paid_minor'),
            'remaining_minor' => (int) $rows->sum('remaining_minor'),
            'counts'          => $this->countsFromRows($rows),
        ];

        if ($search !== null && trim($search) !== '') {
            $needle = mb_strtolower(trim($search));
            $rows   = $rows->filter(fn ($r) => str_contains(mb_strtolower((string) $r['member_name']), $needle)
                || str_contains((string) $r['member_phone'], $needle));
        }
        $rows = match ($filter) {
            'paid'   => $rows->where('status', 'paid'),
            'unpaid' => $rows->whereIn('status', self::UNPAID),
            'late'   => $rows->where('status', 'late'),
            default  => $rows,
        };

        $order = ['late' => 0, 'due' => 1, 'partial' => 2, 'upcoming' => 3, 'paid' => 4, 'waived' => 5];
        $rows  = $rows->sortBy([
            fn ($a, $b) => $order[$a['status']] <=> $order[$b['status']],
            fn ($a, $b) => strcmp((string) $a['member_name'], (string) $b['member_name']),
        ])->values();

        return [
            'campaign_id'  => $campaign->id,
            'due_date'     => $date,
            'round_number' => $items->isEmpty() ? null : $this->roundFor($campaign, (int) $items->first()->sequence_number),
            'totals'       => $totals,
            'rows'         => $rows->all(),
        ];
    }

    /**
     * Grille adhésions × échéances, paginée par adhésion.
     * Colonnes = numéros d'échéance (avec la date la plus fréquente pour ce numéro).
     */
    public function grid(Campaign $campaign, int $page = 1, ?int $perPage = null): array
    {
        $perPage = $perPage ?? self::GRID_PER_PAGE;
        $today   = BusinessTime::todayDate();

        $memberships = $this->trackedMemberships($campaign)
            ->with('user:id,full_name,phone')
            ->join('users', 'users.id', '=', 'memberships.user_id')
            ->orderBy('users.full_name')
            ->select('memberships.*')
            ->paginate($perPage, ['*'], 'page', $page);

        $ids   = collect($memberships->items())->pluck('id')->all();
        $items = $this->installments($campaign)
            ->whereIn('installments.membership_id', $ids)
            ->orderBy('installments.sequence_number')
            ->get()
            ->groupBy('membership_id');

        $columns = Installment::query()
            ->where('installments.campaign_id', $campaign->id)
            ->whereIn('installments.membership_id', $this->trackedMemberships($campaign)->select('memberships.id'))
            ->selectRaw('installments.sequence_number, MIN(installments.due_date) AS due_date')
            ->groupBy('installments.sequence_number')
            ->orderBy('installments.sequence_number')
            ->get()
            ->map(fn ($c) => [
                'sequence_number' => (int) $c->sequence_number,
                'due_date'        => substr((string) $c->due_date, 0, 10),
                'round_number'    => $this->roundFor($campaign, (int) $c->sequence_number),
            ])->values();

        $rows = collect($memberships->items())->map(function (Membership $m) use ($items, $today) {
            $own   = $items->get($m->id, collect());
            $cells = [];
            foreach ($own as $i) {
                $cells[(string) $i->sequence_number] = [
                    'status'          => $this->displayStatus($i, $today),
                    'remaining_minor' => $i->remainingMinor(),
                    'due_date'        => $i->due_date->toDateString(),
                ];
            }
            $due = $own->filter(fn (Installment $i) => $i->due_date->toDateString() <= $today
                && $i->status !== InstallmentStatus::Waived);

            return [
                'membership_id'     => $m->id,
                'member_id'         => $m->user_id,
                'member_name'       => $m->user?->full_name,
                'member_phone'      => $m->user?->phone,
                'membership_status' => $m->status?->value,
                'paid_count'        => $own->where('status', InstallmentStatus::Paid)->count(),
                // Payées parmi les échues (pour « 18/21 ») et payées d'avance (échéance future déjà réglée).
                'paid_due_count'    => $due->where('status', InstallmentStatus::Paid)->count(),
                'paid_ahead_count'  => $own->where('status', InstallmentStatus::Paid)->count() - $due->where('status', InstallmentStatus::Paid)->count(),
                'due_count'         => $due->count(),
                'total_count'       => $own->where('status', '!=', InstallmentStatus::Waived)->count(),
                'late_count'        => $own->where('status', InstallmentStatus::Late)->count(),
                'outstanding_minor' => (int) $due->sum(fn (Installment $i) => $i->remainingMinor()),
                'cells'             => (object) $cells,
            ];
        })->values();

        return [
            'campaign_id' => $campaign->id,
            'today'       => $today,
            'columns'     => $columns->all(),
            'rows'        => $rows->all(),
            'meta'        => [
                'current_page' => $memberships->currentPage(),
                'last_page'    => $memberships->lastPage(),
                'per_page'     => $memberships->perPage(),
                'total'        => $memberships->total(),
            ],
        ];
    }

    /** Toutes les lignes de la grille (export CSV « vue d'ensemble »). */
    public function gridAll(Campaign $campaign): array
    {
        return $this->grid($campaign, 1, 10000);
    }

    // ------------------------------------------------------------------

    public function displayStatus(Installment $i, string $today): string
    {
        return match (true) {
            $i->status === InstallmentStatus::Paid   => 'paid',
            $i->status === InstallmentStatus::Waived => 'waived',
            $i->status === InstallmentStatus::Late   => 'late',
            $this->paidAmount($i) > 0                => 'partial',
            $i->due_date->toDateString() <= $today   => 'due',
            default                                  => 'upcoming',
        };
    }

    private function trackedMemberships(Campaign $campaign)
    {
        return Membership::query()
            ->where('memberships.campaign_id', $campaign->id)
            ->whereNotIn('memberships.status', [MembershipStatus::PendingL1->value, MembershipStatus::PendingL2->value]);
    }

    private function installments(Campaign $campaign)
    {
        return Installment::query()
            ->withPaidMinor()
            ->where('installments.campaign_id', $campaign->id)
            ->whereIn('installments.membership_id', $this->trackedMemberships($campaign)->select('memberships.id'));
    }

    private function dueAmount(Installment $i): int
    {
        return $i->status === InstallmentStatus::Waived ? 0 : $i->totalDueMinor();
    }

    private function paidAmount(Installment $i): int
    {
        return (int) ($i->paid_minor ?? 0);
    }

    private function counts(Collection $items, string $today): array
    {
        $counts = array_fill_keys(self::STATUSES, 0);
        foreach ($items as $i) {
            $counts[$this->displayStatus($i, $today)]++;
        }

        return $counts;
    }

    private function countsFromRows(Collection $rows): array
    {
        $counts = array_fill_keys(self::STATUSES, 0);
        foreach ($rows as $r) {
            $counts[$r['status']]++;
        }

        return $counts;
    }

    private function defaultDate(Collection $dates, string $today): ?string
    {
        if ($dates->isEmpty()) {
            return null;
        }
        if ($dates->contains($today)) {
            return $today;
        }

        return $dates->filter(fn ($d) => $d < $today)->last() ?? $dates->first();
    }

    /** @return array<string,int> membership_id → nombre d'échéances en retard */
    private function lateCountsByMembership(Campaign $campaign): array
    {
        return Installment::query()
            ->where('campaign_id', $campaign->id)
            ->where('status', InstallmentStatus::Late->value)
            ->selectRaw('membership_id, COUNT(*) AS n')
            ->groupBy('membership_id')
            ->pluck('n', 'membership_id')
            ->map(fn ($n) => (int) $n)
            ->all();
    }

    /** @return array<string,string> installment_id → date ISO du dernier paiement affecté */
    private function lastPaymentByInstallment(array $installmentIds): array
    {
        if ($installmentIds === []) {
            return [];
        }

        return DB::table('installment_payments')
            ->join('payments', 'payments.id', '=', 'installment_payments.payment_id')
            ->whereIn('installment_payments.installment_id', $installmentIds)
            ->selectRaw('installment_payments.installment_id, MAX(COALESCE(payments.confirmed_at, payments.created_at)) AS at')
            ->groupBy('installment_payments.installment_id')
            ->pluck('at', 'installment_id')
            ->map(fn ($at) => $at ? \Illuminate\Support\Carbon::parse($at)->toIso8601String() : null)
            ->all();
    }

    private ?array $rounds = null;

    private function roundFor(Campaign $campaign, int $sequence): ?int
    {
        if (! $campaign->isRotating()) {
            return null;
        }
        $this->rounds ??= DB::table('rotation_rounds')->where('campaign_id', $campaign->id)
            ->get(['round_number', 'first_sequence', 'last_sequence'])->all();
        foreach ($this->rounds as $r) {
            if ($sequence >= $r->first_sequence && $sequence <= $r->last_sequence) {
                return (int) $r->round_number;
            }
        }

        return null;
    }
}
