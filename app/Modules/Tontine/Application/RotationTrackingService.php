<?php

namespace App\Modules\Tontine\Application;

use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Ledger\Application\LedgerService;
use App\Modules\Tontine\Domain\Enums\InstallmentStatus;
use App\Modules\Tontine\Domain\Enums\RotationRoundStatus as S;
use App\Modules\Tontine\Domain\Exceptions\TontineRuleException;
use App\Modules\Tontine\Infrastructure\Models\Campaign;
use App\Modules\Tontine\Infrastructure\Models\Installment;
use App\Modules\Tontine\Infrastructure\Models\Membership;
use App\Modules\Tontine\Infrastructure\Models\RotationRound;
use App\Shared\BusinessTime;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Lot 4 — suivi d'une tontine rotative.
 *
 *  - Couverture d'un tour : parts « cotisation » (hors complément et pénalité) des échéances
 *    first..last de chaque membre ; manquant = cagnotte − encaissé ; impayés nominatifs.
 *  - Statut dérivé des tours non verrouillés : scheduled → collecting → ready | blocked
 *    (ready = groupe entièrement payé ET bénéficiaire à jour ; sinon blocked, D-R3/D-R4).
 *  - Suivi d'un membre : attendu, payé, restant, retards, prochaine échéance, son tour,
 *    distribution reçue — ses obligations continuent après sa distribution.
 *  - Pénalités au cas par cas (D-R10), sur une échéance échue non réglée.
 */
class RotationTrackingService
{
    public function __construct(private readonly LedgerService $ledger) {}

    /** Recalcule les statuts dérivés des tours (rien n'est modifié sur un tour verrouillé ou versé). */
    public function refreshStatuses(Campaign $campaign): void
    {
        if (! $campaign->isRotating() || ! $campaign->started_at) {
            return;
        }

        $today     = BusinessTime::todayDate();
        $firstDate = $campaign->scheduleDates()->pluck('due_date', 'sequence');

        foreach ($campaign->rounds()->get() as $round) {
            if (! in_array($round->status, [S::Scheduled, S::Collecting, S::Ready, S::Blocked], true)) {
                continue;
            }

            $raw    = $firstDate[$round->first_sequence] ?? null;
            $start  = $raw ? \Illuminate\Support\Carbon::parse($raw)->toDateString() : null;
            $target = match (true) {
                $start !== null && $today < $start                   => S::Scheduled,
                $today < $round->scheduled_date->toDateString()      => S::Collecting,
                default => $this->isFullyCovered($round) ? S::Ready : S::Blocked,
            };

            if ($target !== $round->status) {
                $round->update(['status' => $target]);
            }
        }
    }

    /** Couverture détaillée d'un tour (aperçu de distribution, suivi par tour). */
    public function coverage(RotationRound $round): array
    {
        $rows = $this->windowInstallments($round);

        $collected = 0;
        $expected  = 0;
        $unpaid    = [];
        $perMember = [];

        foreach ($rows as $i) {
            $contribution = (int) $i->amount_minor - (int) $i->fee_minor;
            if ($i->status === InstallmentStatus::Waived) {
                continue;
            }
            $expected  += $contribution;
            $paidPart   = $i->splitPaid((int) $i->paid_minor)['contribution'];
            $collected += $paidPart;
            $perMember[$i->membership_id] ??= true;

            if ($i->status !== InstallmentStatus::Paid) {
                $perMember[$i->membership_id] = false;
                $unpaid[] = [
                    'membership_id'   => $i->membership_id,
                    'full_name'       => $i->membership?->user?->full_name,
                    'sequence'        => $i->sequence_number,
                    'due_date'        => $i->due_date->toDateString(),
                    'status'          => $i->status->value,
                    'remaining_minor' => $i->remainingMinor(),
                ];
            }
        }

        $arrears = $round->beneficiary_membership_id ? $this->arrears($round->beneficiary_membership_id) : ['count' => 0, 'amount_minor' => 0];

        return [
            'round_id'             => $round->id,
            'round_number'         => $round->round_number,
            'pot_minor'            => $round->expected_minor,
            'expected_minor'       => $expected,
            'collected_minor'      => $collected,
            'missing_minor'        => max(0, $round->expected_minor - $collected),
            'members_up_to_date'   => count(array_filter($perMember)),
            'members_late'         => count($perMember) - count(array_filter($perMember)),
            'unpaid'               => $unpaid,
            'beneficiary_arrears'  => $arrears,
            'pool_balance_minor'   => $this->poolBalance($round->campaign_id),
        ];
    }

    public function isFullyCovered(RotationRound $round): bool
    {
        $c = $this->coverage($round);

        return $c['unpaid'] === [] && $c['missing_minor'] === 0 && $c['beneficiary_arrears']['count'] === 0;
    }

    /** Échéances échues et non réglées d'une adhésion (retards au sens large, D-R4). */
    public function arrears(string $membershipId): array
    {
        $rows = Installment::where('membership_id', $membershipId)
            ->whereIn('status', [InstallmentStatus::Pending->value, InstallmentStatus::Late->value])
            ->whereDate('due_date', '<', BusinessTime::todayDate())
            ->withPaidMinor()
            ->get();

        return ['count' => $rows->count(), 'amount_minor' => (int) $rows->sum(fn ($i) => $i->remainingMinor())];
    }

    public function poolBalance(string $campaignId): int
    {
        $key = "ROTATING_POOL:{$campaignId}";

        return $this->ledger->balances([$key])[$key] ?? 0;
    }

    /** Vue d'ensemble d'une campagne rotative (back-office). */
    public function overview(Campaign $campaign): array
    {
        $this->refreshStatuses($campaign);
        $rounds = $campaign->rounds()->with('beneficiary.user:id,full_name')->get();

        $roundRows = $rounds->map(fn (RotationRound $r) => [
            'id'             => $r->id,
            'round_number'   => $r->round_number,
            'scheduled_date' => $r->scheduled_date->toDateString(),
            'status'         => $r->status->value,
            'status_label'   => $r->status->label(),
            'beneficiary'    => $r->beneficiary ? ['membership_id' => $r->beneficiary->id, 'full_name' => $r->beneficiary->user?->full_name] : null,
            ...array_intersect_key($this->coverage($r), array_flip(['pot_minor', 'collected_minor', 'missing_minor', 'members_up_to_date', 'members_late'])),
            'net_minor'         => $r->netMinor(),
            'distributed_minor' => $r->distributed_minor,
        ]);

        $members = Membership::with('user:id,full_name,phone')->where('campaign_id', $campaign->id)->get()
            ->map(fn (Membership $m) => $this->memberSummary($m, $rounds));

        $current = $rounds->first(fn ($r) => ! in_array($r->status, [S::Completed, S::Vacant], true));

        return [
            'participants'          => (int) $campaign->max_members,
            'rounds_count'          => $rounds->count(),
            'current_round'         => $current?->round_number,
            'pot_minor'             => (int) ($rounds->first()?->expected_minor ?? 0),
            'pool_balance_minor'    => $this->poolBalance($campaign->id),
            'collected_minor'       => (int) $this->collectedContributions($campaign->id),
            'distributed_minor'     => (int) $rounds->sum('distributed_minor'),
            'remaining_to_collect_minor' => (int) $members->sum('remaining_minor'),
            'rounds'                => $roundRows->values(),
            'members'               => $members->values(),
        ];
    }

    /** Suivi d'un membre : ses obligations continuent après sa distribution. */
    public function memberSummary(Membership $membership, ?Collection $rounds = null): array
    {
        $installments = Installment::where('membership_id', $membership->id)->withPaidMinor()->orderBy('sequence_number')->get();
        $active       = $installments->where('status', '!==', InstallmentStatus::Waived);
        $round        = ($rounds ?? RotationRound::where('campaign_id', $membership->campaign_id)->get())
            ->firstWhere('beneficiary_membership_id', $membership->id);
        $next         = $active->first(fn ($i) => in_array($i->status, [InstallmentStatus::Pending, InstallmentStatus::Late], true));
        $arrears      = $this->arrears($membership->id);

        return [
            'membership_id'     => $membership->id,
            'full_name'         => $membership->user?->full_name,
            'status'            => $membership->status->value,
            'expected_minor'    => (int) $active->sum(fn ($i) => $i->totalDueMinor()),
            'paid_minor'        => (int) $active->sum(fn ($i) => min((int) $i->paid_minor, $i->totalDueMinor())),
            'remaining_minor'   => (int) $active->sum(fn ($i) => $i->remainingMinor()),
            'installments_total'=> $active->count(),
            'installments_paid' => $active->where('status', InstallmentStatus::Paid)->count(),
            'arrears_count'     => $arrears['count'],
            'arrears_minor'     => $arrears['amount_minor'],
            'next_installment'  => $next ? [
                'sequence' => $next->sequence_number, 'due_date' => $next->due_date->toDateString(),
                'remaining_minor' => $next->remainingMinor(), 'status' => $next->status->value,
            ] : null,
            'round'             => $round ? [
                'round_number'   => $round->round_number,
                'scheduled_date' => $round->scheduled_date->toDateString(),
                'status'         => $round->status->value,
                'status_label'   => $round->status->label(),
                'net_minor'      => $round->netMinor(),
                'received'       => $round->status === S::Completed,
                'received_minor' => $round->distributed_minor,
            ] : null,
        ];
    }

    /**
     * Vue membre (D-R8) : sa situation + calendrier des tours avec « Prénom N. » du bénéficiaire,
     * jamais de téléphone ni de retards des autres.
     */
    public function memberView(Membership $membership): array
    {
        $campaign = $membership->campaign;
        $this->refreshStatuses($campaign);
        $rounds = $campaign->rounds()->with('beneficiary.user:id,full_name')->get();

        return [
            'campaign' => [
                'id' => $campaign->id, 'name' => $campaign->name, 'status' => $campaign->status->value,
                'contribution_minor' => (int) $campaign->contribution_amount_minor,
                'surcharge_minor'    => $campaign->rotation_fee_mode?->value === 'contribution_surcharge' ? (int) $campaign->rotation_fee_minor : 0,
                'contribution_schedule' => $campaign->contribution_schedule,
                'installments_per_round' => (int) $campaign->installments_per_round,
            ],
            'me'       => $this->memberSummary($membership->loadMissing('user'), $rounds),
            'rounds'   => $rounds->map(fn (RotationRound $r) => [
                'round_number'   => $r->round_number,
                'scheduled_date' => $r->scheduled_date->toDateString(),
                'status'         => $r->status === S::Completed ? 'completed' : ($r->status === S::Vacant ? 'vacant' : 'upcoming'),
                'is_me'          => $r->beneficiary_membership_id === $membership->id,
                'beneficiary'    => self::maskName($r->beneficiary?->user?->full_name),
            ])->values(),
        ];
    }

    // ── Pénalités (D-R10) ─────────────────────────────────────────────────

    public function applyPenalty(Installment $installment, int $amountMinor, string $reason, User $actor): Installment
    {
        return DB::transaction(function () use ($installment, $amountMinor, $reason, $actor) {
            $i = Installment::whereKey($installment->id)->withPaidMinor()->lockForUpdate()->firstOrFail();
            $this->assertPenaltyEditable($i);

            if ($i->due_date->toDateString() >= BusinessTime::todayDate()) {
                throw new TontineRuleException('Une pénalité ne s\'applique qu\'à une échéance échue.', 'penalty_not_due', 422);
            }

            $i->update([
                'penalty_minor' => $amountMinor, 'penalty_reason' => $reason,
                'penalty_by' => $actor->id, 'penalty_at' => now(),
            ]);

            return $i->fresh();
        });
    }

    public function cancelPenalty(Installment $installment, User $actor): Installment
    {
        return DB::transaction(function () use ($installment) {
            $i = Installment::whereKey($installment->id)->withPaidMinor()->lockForUpdate()->firstOrFail();
            $this->assertPenaltyEditable($i);
            $i->update(['penalty_minor' => 0, 'penalty_reason' => null, 'penalty_by' => null, 'penalty_at' => null]);

            return $i->fresh();
        });
    }

    /** « Charbel Bocovo » → « Charbel B. » (D-R8). */
    public static function maskName(?string $fullName): ?string
    {
        $parts = preg_split('/\s+/', trim((string) $fullName)) ?: [];
        if ($parts === [] || $parts[0] === '') {
            return null;
        }
        $first = array_shift($parts);

        return $parts === [] ? $first : $first . ' ' . mb_strtoupper(mb_substr(end($parts), 0, 1)) . '.';
    }

    // ── Interne ──────────────────────────────────────────────────────────

    private function assertPenaltyEditable(Installment $i): void
    {
        if (! $i->campaign()->first()?->isRotating()) {
            throw TontineRuleException::notRotating();
        }
        if (! in_array($i->status, [InstallmentStatus::Pending, InstallmentStatus::Late], true)) {
            throw new TontineRuleException('Échéance réglée ou annulée : la pénalité ne peut plus changer.', 'penalty_locked', 422);
        }
        if ($i->splitPaid((int) $i->paid_minor)['penalty'] > 0) {
            throw new TontineRuleException('La pénalité est déjà (en partie) payée : elle ne peut plus changer.', 'penalty_locked', 422);
        }
    }

    private function windowInstallments(RotationRound $round): Collection
    {
        return Installment::with('membership.user:id,full_name')
            ->where('campaign_id', $round->campaign_id)
            ->whereBetween('sequence_number', [$round->first_sequence, $round->last_sequence])
            ->withPaidMinor()
            ->orderBy('sequence_number')
            ->get();
    }

    private function collectedContributions(string $campaignId): int
    {
        return (int) Installment::where('campaign_id', $campaignId)->withPaidMinor()->get()
            ->sum(fn ($i) => $i->splitPaid((int) $i->paid_minor)['contribution']);
    }
}
