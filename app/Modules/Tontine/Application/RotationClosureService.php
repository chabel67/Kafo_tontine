<?php

namespace App\Modules\Tontine\Application;

use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Ledger\Application\LedgerService;
use App\Modules\Ledger\Domain\Enums\AccountType;
use App\Modules\Notifications\Application\NotificationService;
use App\Modules\Tontine\Domain\Enums\CampaignStatus;
use App\Modules\Tontine\Domain\Enums\InstallmentStatus;
use App\Modules\Tontine\Domain\Enums\MembershipStatus;
use App\Modules\Tontine\Domain\Enums\RotationRoundStatus as S;
use App\Modules\Tontine\Domain\Exceptions\TontineRuleException;
use App\Modules\Tontine\Infrastructure\Models\Campaign;
use App\Modules\Tontine\Infrastructure\Models\Installment;
use App\Modules\Tontine\Infrastructure\Models\Membership;
use App\Modules\Tontine\Infrastructure\Models\RotationDeparture;
use App\Modules\Tontine\Infrastructure\Models\RotationPayoutAttempt as Attempt;
use Illuminate\Support\Facades\DB;

/**
 * Lot 8 — clôture financière d'une tontine rotative (distincte de la clôture d'épargne).
 *
 * On distingue : fin du calendrier, fin des distributions (tous les tours versés ou sans
 * bénéficiaire) et clôture financière définitive, possible seulement quand :
 *  - aucun tour n'est en attente / en versement ; aucune sortie de caisse en cours ;
 *  - aucun remboursement de départ n'attend d'être versé ;
 *  - la caisse est à ZÉRO : le reliquat a été réparti entre membres (versements en deux temps)
 *    ou passé en produit, sur décision tracée de l'équipe (D-R11).
 * Les cotisations impayées ne disparaissent jamais : elles sont listées (obligations restantes).
 */
class RotationClosureService
{
    public function __construct(
        private readonly LedgerService                  $ledger,
        private readonly RotationTrackingService        $tracking,
        private readonly RotationCashOutService         $cashOut,
        private readonly RotationReconciliationService  $reconciliation,
        private readonly NotificationService            $notifications,
    ) {}

    public function preview(Campaign $campaign): array
    {
        $this->assertRotating($campaign);
        $this->tracking->refreshStatuses($campaign);

        $rounds   = $campaign->rounds()->get();
        $pending  = $rounds->whereNotIn('status', [S::Completed, S::Vacant]);
        $inFlight = Attempt::where('campaign_id', $campaign->id)->where('status', Attempt::PROCESSING)->count();
        $refunds  = RotationDeparture::where('campaign_id', $campaign->id)
            ->whereIn('status', [RotationDeparture::PENDING, RotationDeparture::REFUNDING])->count();
        $pool     = $this->tracking->poolBalance($campaign->id);

        $obligations = Installment::with('membership.user:id,full_name')
            ->where('campaign_id', $campaign->id)
            ->whereIn('status', [InstallmentStatus::Pending->value, InstallmentStatus::Late->value])
            ->withPaidMinor()->get()
            ->groupBy('membership_id')
            ->map(fn ($rows) => [
                'membership_id'   => $rows->first()->membership_id,
                'full_name'       => $rows->first()->membership?->user?->full_name,
                'count'           => $rows->count(),
                'remaining_minor' => (int) $rows->sum(fn ($i) => $i->remainingMinor()),
            ])->values();

        $blockers = array_values(array_filter([
            $campaign->status !== CampaignStatus::Active ? 'La tontine n\'est pas en cours.' : null,
            $pending->isNotEmpty() ? $pending->count() . ' tour(s) pas encore versé(s) : ' . $pending->pluck('round_number')->implode(', ') . '.' : null,
            $inFlight > 0 ? "{$inFlight} versement(s) en cours : confirmez-les ou déclarez leur échec." : null,
            $refunds > 0 ? "{$refunds} remboursement(s) de départ à verser." : null,
            $pool !== 0 ? "La caisse n'est pas à zéro ({$pool} XOF) : répartissez le reliquat ou passez-le en produit." : null,
        ]));

        return [
            'campaign_id'          => $campaign->id,
            'rounds_completed'     => $rounds->where('status', S::Completed)->count(),
            'rounds_vacant'        => $rounds->where('status', S::Vacant)->count(),
            'rounds_pending'       => $pending->count(),
            'pool_balance_minor'   => $pool,
            'pool_available_minor' => $this->cashOut->availablePool($campaign->id),
            'surplus_revenue_minor'=> (int) $campaign->rotation_surplus_revenue_minor,
            'remaining_obligations'=> $obligations,
            'reconciliation'       => $this->reconciliation->reconcile($campaign),
            'blockers'             => $blockers,
            'closable'             => $blockers === [],
        ];
    }

    /** D-R11 : tout ou partie du reliquat passé en produit (MISC_REVENUE), motif tracé. */
    public function surplusToRevenue(Campaign $campaign, int $amount, string $reason, User $actor): Campaign
    {
        return DB::transaction(function () use ($campaign, $amount, $reason, $actor) {
            $c = Campaign::whereKey($campaign->id)->lockForUpdate()->firstOrFail();
            $this->assertRotating($c);
            if ($c->rounds()->whereNotIn('status', [S::Completed->value, S::Vacant->value])->exists()) {
                throw new TontineRuleException('Le reliquat se traite une fois tous les tours traités.', 'rounds_not_finished', 422);
            }
            $available = $this->cashOut->availablePool($c->id);
            if ($amount <= 0 || $amount > $available) {
                throw new TontineRuleException("Montant invalide : entre 1 et {$available} XOF.", 'invalid_amount', 422);
            }

            $poolKey = "ROTATING_POOL:{$c->id}";
            $this->ledger->openAccount('MISC_REVENUE', AccountType::MiscRevenue);
            $this->ledger->post(
                legs: [
                    ['account' => $poolKey,        'type' => 'debit',  'amount' => $amount],
                    ['account' => 'MISC_REVENUE',  'type' => 'credit', 'amount' => $amount],
                ],
                reference:   $this->ledger->nextReference(),
                description: "Reliquat tontine — produit ({$c->name})",
                createdById: $actor->id,
                metadata:    ['campaign_id' => $c->id, 'reason' => $reason, 'kind' => 'rotation_surplus_revenue'],
            );
            $c->update(['rotation_surplus_revenue_minor' => (int) $c->rotation_surplus_revenue_minor + $amount]);

            return $c->fresh();
        });
    }

    public function close(Campaign $campaign, User $actor): Campaign
    {
        $closed = DB::transaction(function () use ($campaign) {
            $c = Campaign::whereKey($campaign->id)->lockForUpdate()->firstOrFail();
            $preview = $this->preview($c);
            if (! $preview['closable']) {
                throw new TontineRuleException('Clôture impossible : ' . implode(' ', $preview['blockers']), 'rotation_not_closable', 422);
            }

            // Les adhésions sont closes ; les échéances impayées éventuelles RESTENT dues (jamais effacées).
            Membership::where('campaign_id', $c->id)
                ->whereIn('status', [MembershipStatus::Active->value, MembershipStatus::Suspended->value])
                ->update(['status' => MembershipStatus::Closed->value]);
            $c->update(['status' => CampaignStatus::Closed->value, 'closed_at' => now()]);

            return $c->fresh();
        });

        foreach (Membership::where('campaign_id', $closed->id)->pluck('user_id')->unique() as $userId) {
            $this->notifications->notify($userId, 'rotation.closed', 'Fin de la tontine',
                "La tontine « {$closed->name} » est terminée. Merci pour votre participation.", '/campagnes');
        }

        return $closed;
    }

    private function assertRotating(Campaign $campaign): void
    {
        if (! $campaign->isRotating()) {
            throw TontineRuleException::notRotating();
        }
    }
}
