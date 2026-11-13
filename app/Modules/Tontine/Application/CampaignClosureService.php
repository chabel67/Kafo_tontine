<?php

namespace App\Modules\Tontine\Application;

use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Lending\Domain\Enums\LoanProductType;
use App\Modules\Lending\Domain\Enums\LoanRequestStatus;
use App\Modules\Lending\Infrastructure\Models\LoanRequest;
use App\Modules\Tontine\Domain\Enums\CampaignPayoutStatus;
use App\Modules\Tontine\Domain\Enums\CampaignStatus;
use App\Modules\Tontine\Domain\Enums\EarlyWithdrawalStatus;
use App\Modules\Tontine\Domain\Enums\MembershipStatus;
use App\Modules\Tontine\Domain\Exceptions\CampaignAlreadyClosedException;
use App\Modules\Tontine\Infrastructure\Models\Campaign;
use App\Modules\Tontine\Infrastructure\Models\CampaignPayout;
use App\Modules\Tontine\Infrastructure\Models\EarlyWithdrawal;
use App\Modules\Tontine\Infrastructure\Models\Membership;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Clôture d'une campagne, temps 1 (R-CAMP-08, R-CAMP-13, R-CAMP-15, R-LOAN-15).
 *
 * `preview()` — dry-run (mêmes règles que le règlement : PayoutCalculator).
 *
 * `close()`, dans une seule transaction :
 *   1. verrou exclusif sur la campagne (clôtures concurrentes, et toute opération qui prend
 *      la campagne en verrou partagé — retrait, avance — attend ou échoue sur `campaign_not_active`) ;
 *   2. retraits non versés → rejetés `campaign_closed`, sans notification (D2) ; les versements
 *      en cours sont conservés et gèlent le règlement de l'adhésion ;
 *   3. demandes d'AVANCE non décaissées → rejetées (R-LOAN-15). Les prêts communautaires et
 *      leurs demandes ne sont jamais touchés (R-LOAN-16, R-CAMP-03 supprimée) ;
 *   4. un payout `pending` par adhésion active (montants prévisionnels) ;
 *   5. campagne → `closed`.
 *
 * Le versement effectif est délégué à CampaignPayoutService::settle (temps 2).
 */
class CampaignClosureService
{
    public function __construct(
        private readonly PayoutCalculator       $calculator,
        private readonly EarlyWithdrawalService $withdrawals,
    ) {}

    /**
     * Dry-run : aucun changement d'état.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function preview(Campaign $campaign): Collection
    {
        $memberships = Membership::where('campaign_id', $campaign->id)
            ->where('status', MembershipStatus::Active)
            ->with('user')
            ->get();

        return $memberships->map(fn (Membership $m) => [
            'membership_id' => $m->id,
            'member_name'   => $m->user?->full_name ?? '—',
            ...$this->calculator->compute($m),
            'pending_withdrawals_minor' => (int) EarlyWithdrawal::where('membership_id', $m->id)
                ->whereIn('status', EarlyWithdrawalStatus::rejectedOnClosure())
                ->sum('amount_minor'),
        ]);
    }

    /**
     * @return array{payouts_count: int, cancelled_advances_count: int, rejected_withdrawals_count: int}
     */
    public function close(Campaign $campaign, User $actor, string $reason = ''): array
    {
        return DB::transaction(function () use ($campaign, $actor, $reason) {
            $locked = Campaign::whereKey($campaign->id)->lockForUpdate()->first();
            if (! $locked || $locked->status !== CampaignStatus::Active) {
                throw new CampaignAlreadyClosedException($campaign->id);
            }

            $memberships = Membership::where('campaign_id', $campaign->id)
                ->where('status', MembershipStatus::Active)
                ->lockForUpdate()
                ->get();

            // D2 / R-CAMP-15 : retraits non versés rejetés, montant laissé dans l'épargne.
            $rejectedWithdrawals = $this->withdrawals->rejectOpenForCampaignClosure($locked, $actor);

            // R-LOAN-15 : seules les demandes d'AVANCE sont liées à la campagne.
            $cancelled = LoanRequest::where('campaign_id', $campaign->id)
                ->where('product_type', LoanProductType::Advance->value)
                ->whereIn('status', LoanRequestStatus::open())
                ->update([
                    'status'          => LoanRequestStatus::Rejected->value,
                    'rejected_reason' => trim("campaign_closed: {$reason}"),
                    'decided_by'      => $actor->id,
                    'decided_at'      => now(),
                ]);

            $payoutsCount = 0;
            foreach ($memberships as $m) {
                $calc = $this->calculator->compute($m);

                CampaignPayout::create([
                    'reference'                   => $this->nextReference(),
                    'campaign_id'                 => $campaign->id,
                    'membership_id'               => $m->id,
                    'savings_minor'               => $calc['savings_minor'],
                    'advance_offset_minor'        => $calc['advance_offset_minor'],
                    'withdrawals_disbursed_minor' => $calc['withdrawals_disbursed_minor'],
                    'net_amount_minor'            => $calc['net_amount_minor'],
                    'status'                      => CampaignPayoutStatus::Pending->value,
                    'metadata'                    => [
                        'snapshot_at'                   => now()->toIso8601String(),
                        'shortfall'                     => $calc['shortfall_minor'],
                        'withdrawals_in_progress_minor' => $calc['withdrawals_in_progress_minor'],
                    ],
                ]);
                $payoutsCount++;
            }

            $locked->update([
                'status'    => CampaignStatus::Closed->value,
                'closed_at' => now(),
            ]);

            return [
                'payouts_count'              => $payoutsCount,
                'cancelled_advances_count'   => (int) $cancelled,
                'rejected_withdrawals_count' => $rejectedWithdrawals,
            ];
        });
    }

    private function nextReference(): string
    {
        return \App\Shared\Reference::next('PAYOUT', \App\Shared\Reference::PAYOUT);
    }
}
