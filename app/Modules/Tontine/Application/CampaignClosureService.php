<?php

namespace App\Modules\Tontine\Application;

use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Ledger\Application\LedgerService;
use App\Modules\Lending\Domain\Enums\LoanProductType;
use App\Modules\Lending\Domain\Enums\LoanRequestStatus;
use App\Modules\Lending\Domain\Enums\LoanStatus;
use App\Modules\Lending\Infrastructure\Models\Loan;
use App\Modules\Lending\Infrastructure\Models\LoanRequest;
use App\Modules\Tontine\Domain\Enums\CampaignPayoutStatus;
use App\Modules\Tontine\Domain\Enums\CampaignStatus;
use App\Modules\Tontine\Domain\Enums\MembershipStatus;
use App\Modules\Tontine\Domain\Exceptions\CampaignAlreadyClosedException;
use App\Modules\Tontine\Infrastructure\Models\Campaign;
use App\Modules\Tontine\Infrastructure\Models\CampaignPayout;
use App\Modules\Tontine\Infrastructure\Models\Membership;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Orchestration de la clôture d'une campagne (R-CAMP-08 à R-CAMP-12).
 *
 * `preview()` — dry-run, calcule les payouts sans écriture. Utilisé pour la
 * modale de confirmation UI et l'endpoint `GET /admin/campaigns/{id}/close/preview`.
 *
 * `close()` — verrou pessimiste sur la campagne, génère les CampaignPayout
 * (status=pending) pour toutes les memberships actives, annule les demandes
 * d'avance non décaissées (R-LOAN-15), passe la campagne à `closed`.
 *
 * Le versement effectif des payouts est délégué à CampaignPayoutService::settle.
 */
class CampaignClosureService
{
    public function __construct(private readonly LedgerService $ledger) {}

    /**
     * Calcul dry-run des payouts sans écriture — utilisé pour l'aperçu.
     * @return Collection<int, array{
     *   membership_id: string,
     *   member_name: string,
     *   savings_minor: int,
     *   advance_offset_minor: int,
     *   net_amount_minor: int,
     *   shortfall_minor: int
     * }>
     */
    public function preview(Campaign $campaign): Collection
    {
        $memberships = Membership::where('campaign_id', $campaign->id)
            ->where('status', MembershipStatus::Active)
            ->with('user')
            ->get();

        return $memberships->map(function (Membership $m) {
            $savings = $this->ledger->balance("MEMBER_SAVINGS:{$m->id}");
            $offset  = (int) Loan::where('membership_id', $m->id)
                ->where('product_type', LoanProductType::Advance->value)
                ->where('status', LoanStatus::Active->value)
                ->sum('outstanding_minor');
            $net       = max(0, $savings - $offset);
            $shortfall = max(0, $offset - $savings);

            return [
                'membership_id'        => $m->id,
                'member_name'          => $m->user?->full_name ?? '—',
                'savings_minor'        => $savings,
                'advance_offset_minor' => $offset,
                'net_amount_minor'     => $net,
                'shortfall_minor'      => $shortfall,
            ];
        });
    }

    /**
     * Clôture la campagne : génère les payouts, annule les demandes pending.
     *
     * @return array{payouts_count: int, cancelled_advances_count: int}
     */
    public function close(Campaign $campaign, User $actor, string $reason = ''): array
    {
        return DB::transaction(function () use ($campaign, $actor, $reason) {
            // Verrou pessimiste — évite les clôtures concurrentes.
            $locked = Campaign::whereKey($campaign->id)->lockForUpdate()->first();
            if (! $locked || $locked->status !== CampaignStatus::Active) {
                throw new CampaignAlreadyClosedException($campaign->id);
            }

            // R-LOAN-15 : annule les demandes non décaissées de la campagne.
            $cancellable = [
                LoanRequestStatus::Pending->value,
                LoanRequestStatus::Approved->value,
                LoanRequestStatus::Countersigning->value,
                LoanRequestStatus::Countersigned->value,
            ];
            $cancelled = LoanRequest::where('campaign_id', $campaign->id)
                ->whereIn('status', $cancellable)
                ->update([
                    'status'          => LoanRequestStatus::Rejected->value,
                    'rejected_reason' => "campaign_closed: {$reason}",
                    'decided_by'      => $actor->id,
                    'decided_at'      => now(),
                ]);

            // Génère les payouts pour toutes les memberships actives.
            $memberships = Membership::where('campaign_id', $campaign->id)
                ->where('status', MembershipStatus::Active)
                ->lockForUpdate()
                ->get();

            $payoutsCount = 0;
            foreach ($memberships as $m) {
                $savings = $this->ledger->balance("MEMBER_SAVINGS:{$m->id}");
                $offset  = (int) Loan::where('membership_id', $m->id)
                    ->where('product_type', LoanProductType::Advance->value)
                    ->where('status', LoanStatus::Active->value)
                    ->sum('outstanding_minor');
                $net = max(0, $savings - $offset);

                CampaignPayout::create([
                    'reference'            => $this->nextReference(),
                    'campaign_id'          => $campaign->id,
                    'membership_id'        => $m->id,
                    'savings_minor'        => $savings,
                    'advance_offset_minor' => $offset,
                    'net_amount_minor'     => $net,
                    'status'               => CampaignPayoutStatus::Pending->value,
                    'metadata'             => [
                        'snapshot_at' => now()->toIso8601String(),
                        'shortfall'   => max(0, $offset - $savings),
                    ],
                ]);
                $payoutsCount++;
            }

            $locked->update([
                'status'    => CampaignStatus::Closed->value,
                'closed_at' => now(),
            ]);

            return [
                'payouts_count'            => $payoutsCount,
                'cancelled_advances_count' => (int) $cancelled,
            ];
        });
    }

    private function nextReference(): string
    {
        $year  = now()->year;
        $count = CampaignPayout::whereYear('created_at', $year)->count() + 1;
        return sprintf('PAYOUT-%d-%06d', $year, $count);
    }
}
