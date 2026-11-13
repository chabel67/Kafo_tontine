<?php

namespace App\Modules\Tontine\Application;

use App\Modules\Identity\Infrastructure\Models\User;
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
use App\Modules\Tontine\Infrastructure\Models\RotationRound;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Lot 8 — départ d'un membre d'une tontine démarrée (D-R7).
 *
 *  - Seul un membre PAS ENCORE SERVI peut partir (son tour n'est ni validé, ni en versement,
 *    ni versé) ; un membre déjà servi garde ses obligations (suspension seulement).
 *  - L'équipe fixe le remboursement (0 ≤ montant ≤ cotisations qu'il a versées), avec motif.
 *    Le versement se fait en deux temps (RotationCashOutService, versant ≠ décideur).
 *  - Ses échéances non réglées sont annulées (`waived`, motif tracé) ; ses paiements restent.
 *    Son tour devient « sans bénéficiaire » (`vacant`).
 *  - Remplaçant facultatif : nouvelle adhésion active qui reprend le tour libéré et les
 *    échéances annulées du partant (mêmes dates, mêmes montants).
 */
class RotationDepartureService
{
    public function __construct(
        private readonly RotationTrackingService $tracking,
        private readonly NotificationService     $notifications,
    ) {}

    public function start(Membership $membership, int $refundMinor, string $reason, User $actor): RotationDeparture
    {
        $departure = DB::transaction(function () use ($membership, $refundMinor, $reason, $actor) {
            $campaign = Campaign::whereKey($membership->campaign_id)->lockForUpdate()->firstOrFail();
            $m        = Membership::whereKey($membership->id)->lockForUpdate()->firstOrFail();

            if (! $campaign->isRotating() || $campaign->status !== CampaignStatus::Active) {
                throw new TontineRuleException('Un départ ne s\'organise que dans une tontine en cours.', 'departure_not_allowed', 422);
            }
            if (! in_array($m->status, [MembershipStatus::Active, MembershipStatus::Suspended], true)) {
                throw new TontineRuleException('Cette adhésion n\'est plus en cours.', 'departure_not_allowed', 422);
            }
            if (RotationDeparture::where('membership_id', $m->id)->exists()) {
                throw new TontineRuleException('Un départ est déjà enregistré pour ce membre.', 'departure_exists', 409);
            }

            $round = RotationRound::where('campaign_id', $campaign->id)->where('beneficiary_membership_id', $m->id)->lockForUpdate()->first();
            if ($round && ($round->status->isLocked())) {
                throw new TontineRuleException(
                    'Ce membre a déjà reçu (ou est en train de recevoir) sa cagnotte : il ne peut pas partir, ses cotisations restent dues.',
                    'departure_already_served', 422);
            }

            $contributed = $this->contributed($m->id);
            if ($refundMinor < 0 || $refundMinor > $contributed) {
                throw new TontineRuleException("Le remboursement doit être compris entre 0 et {$contributed} XOF (cotisations versées).", 'departure_refund_out_of_bounds', 422);
            }

            $departure = RotationDeparture::create([
                'campaign_id'  => $campaign->id,
                'membership_id'=> $m->id,
                'refund_minor' => $refundMinor,
                'reason'       => $reason,
                'status'       => $refundMinor > 0 ? RotationDeparture::PENDING : RotationDeparture::REFUNDED,
                'decided_by'   => $actor->id,
                'decided_at'   => now(),
            ]);

            Installment::where('membership_id', $m->id)
                ->whereIn('status', [InstallmentStatus::Pending->value, InstallmentStatus::Late->value])
                ->update([
                    'status' => InstallmentStatus::Waived->value, 'waived_at' => now(), 'waived_by' => $actor->id,
                    'waived_reason' => "Départ de la tontine : {$reason}",
                ]);

            $m->update([
                'status'            => MembershipStatus::Suspended->value,
                'suspended_by'      => $actor->id,
                'suspended_at'      => now(),
                'suspension_reason' => "Départ de la tontine : {$reason}",
            ]);

            if ($round) {
                $round->update(['status' => S::Vacant, 'beneficiary_membership_id' => null]);
            }

            return $departure;
        });

        $this->notifications->notify($membership->user_id, 'rotation.departure', 'Départ de la tontine enregistré',
            'Votre départ est enregistré.' . ($refundMinor > 0
                ? ' Un remboursement de ' . number_format($refundMinor, 0, ',', ' ') . ' FCFA vous sera versé.'
                : ' Aucun remboursement n\'est prévu.'), '/campagnes');

        return $departure;
    }

    /** Remplaçant : reprend le tour libéré et les échéances annulées du partant. */
    public function replace(RotationDeparture $departure, User $newMember, User $actor): Membership
    {
        $replacement = DB::transaction(function () use ($departure, $newMember, $actor) {
            $campaign = Campaign::whereKey($departure->campaign_id)->lockForUpdate()->firstOrFail();
            $d        = RotationDeparture::whereKey($departure->id)->lockForUpdate()->firstOrFail();

            if ($campaign->status !== CampaignStatus::Active) {
                throw new TontineRuleException('La tontine n\'est plus en cours.', 'departure_not_allowed', 422);
            }
            if ($d->replacement_membership_id) {
                throw new TontineRuleException('Ce départ a déjà un remplaçant.', 'replacement_exists', 409);
            }
            if (Membership::where('campaign_id', $campaign->id)->where('user_id', $newMember->id)->exists()) {
                throw new TontineRuleException('Ce membre participe déjà à cette tontine.', 'already_enrolled', 409);
            }

            $leaver = Membership::findOrFail($d->membership_id);
            $round  = RotationRound::where('campaign_id', $campaign->id)->where('status', S::Vacant->value)
                ->whereNull('beneficiary_membership_id')->orderBy('round_number')->lockForUpdate()->first();

            $replacement = Membership::create([
                'user_id'                => $newMember->id,
                'campaign_id'            => $campaign->id,
                'status'                 => MembershipStatus::Active->value,
                'committed_amount_minor' => $leaver->committed_amount_minor,
                'membership_fee_minor'   => 0,   // V1 : pas de frais d'adhésion pour un remplaçant
                'validated_l1_by'        => $actor->id,
                'validated_l1_at'        => now(),
                'activated_at'           => now(),
            ]);

            // Reprend exactement les échéances annulées du partant (mêmes dates, mêmes montants).
            $now = now();
            $rows = Installment::where('membership_id', $leaver->id)->where('status', InstallmentStatus::Waived->value)
                ->orderBy('sequence_number')->get()
                ->map(fn (Installment $i) => [
                    'id' => (string) Str::uuid(), 'membership_id' => $replacement->id, 'campaign_id' => $campaign->id,
                    'sequence_number' => $i->sequence_number, 'due_date' => $i->due_date->toDateString(),
                    'grace_period_ends_date' => $i->grace_period_ends_date->toDateString(),
                    'amount_minor' => $i->amount_minor, 'fee_minor' => $i->fee_minor, 'status' => 'pending',
                    'created_at' => $now, 'updated_at' => $now,
                ])->all();
            if ($rows !== []) {
                Installment::insert($rows);
            }

            if ($round) {
                $round->update(['status' => S::Scheduled, 'beneficiary_membership_id' => $replacement->id]);
            }
            $d->update(['replacement_membership_id' => $replacement->id]);

            return $replacement->fresh();
        });

        $this->tracking->refreshStatuses(Campaign::find($departure->campaign_id));
        $round = RotationRound::where('beneficiary_membership_id', $replacement->id)->first();
        $this->notifications->notify($newMember->id, 'rotation.round_assigned', 'Bienvenue dans la tontine',
            'Vous remplacez un membre parti' . ($round ? " : vous recevrez la cagnotte au tour {$round->round_number}, prévu le "
                . $round->scheduled_date->format('d/m/Y') : '') . '. Vos cotisations commencent selon le calendrier.', '/campagnes');

        return $replacement;
    }

    /** Part « cotisation » déjà versée par le membre (hors compléments et pénalités). */
    public function contributed(string $membershipId): int
    {
        return (int) Installment::where('membership_id', $membershipId)->withPaidMinor()->get()
            ->sum(fn (Installment $i) => $i->splitPaid((int) $i->paid_minor)['contribution']);
    }
}
