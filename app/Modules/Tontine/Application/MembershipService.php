<?php

namespace App\Modules\Tontine\Application;

use App\Modules\Identity\Infrastructure\Models\AccessLog;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Ledger\Application\LedgerService;
use App\Modules\Ledger\Domain\Enums\AccountType;
use App\Modules\Tontine\Domain\Enums\ContributionMode;
use App\Modules\Tontine\Domain\Enums\MembershipStatus;
use App\Modules\Tontine\Domain\Exceptions\TontineRuleException;
use App\Modules\Tontine\Infrastructure\Models\Campaign;
use App\Modules\Tontine\Infrastructure\Models\Membership;
use Illuminate\Support\Facades\DB;

class MembershipService
{
    public function __construct(
        private readonly InstallmentGeneratorService $generator,
        private readonly LedgerService               $ledger,
    ) {}

    public function enroll(User $member, Campaign $campaign, ?int $committedAmount = null): Membership
    {
        return DB::transaction(fn () => $this->enrollLocked($member, $committedAmount,
            // Verrou campagne : sérialise les enrôlements (plafond max_members) et bloque une clôture concurrente.
            Campaign::whereKey($campaign->id)->lockForUpdate()->firstOrFail()));
    }

    private function enrollLocked(User $member, ?int $committedAmount, Campaign $campaign): Membership
    {
        // Tontine rotative : groupe fermé, adhésions seulement avant le démarrage (lot 3).
        if ($campaign->isRotating() && ($campaign->status->value !== 'open' || $campaign->started_at)) {
            throw new TontineRuleException('Les adhésions à cette tontine sont closes : le groupe est fermé depuis son démarrage.', 'rotation_group_closed', 422);
        }

        if ($campaign->status->value !== 'open' && $campaign->status->value !== 'active') {
            throw new TontineRuleException('La campagne n\'accepte pas de nouvelles adhésions.', 'campaign_not_accepting_members', 422);
        }

        if (Membership::where('user_id', $member->id)->where('campaign_id', $campaign->id)->exists()) {
            throw new TontineRuleException('Ce membre est déjà inscrit à cette campagne.', 'already_enrolled', 409);
        }

        if ($campaign->max_members !== null) {
            $current = Membership::where('campaign_id', $campaign->id)
                ->whereIn('status', ['pending_l1', 'pending_l2', 'active'])
                ->count();
            if ($current >= $campaign->max_members) {
                throw new TontineRuleException('La campagne est complète.', 'campaign_full', 422);
            }
        }

        // Résolution du montant d'engagement
        $resolved = $this->resolveCommittedAmount($campaign, $committedAmount);

        return Membership::create([
            'user_id'                => $member->id,
            'campaign_id'            => $campaign->id,
            'status'                 => MembershipStatus::PendingL1->value,
            'committed_amount_minor' => $resolved,
            // R-FEE-01 : frais d'adhésion figés au moment de l'enrôlement (contrat).
            'membership_fee_minor'   => (int) $campaign->membership_fee_minor,
        ]);
    }

    /**
     * R-FEE-02 : frais d'adhésion dus / payés / restant à payer (paiements confirmés
     * d'objet `membership_fee`). Jamais confondus avec les cotisations ni l'épargne.
     *
     * @return array{due_minor:int, paid_minor:int, remaining_minor:int}
     */
    public static function feeStatus(Membership $membership): array
    {
        $due  = (int) $membership->membership_fee_minor;
        $paid = $due > 0
            ? (int) \App\Modules\Payments\Infrastructure\Models\Payment::where('membership_id', $membership->id)
                ->where('purpose', 'membership_fee')
                ->where('status', 'confirmed')
                ->sum('amount_minor')
            : 0;

        return ['due_minor' => $due, 'paid_minor' => $paid, 'remaining_minor' => max(0, $due - $paid)];
    }

    /** R-FEE-03 : aucune activation tant que les frais d'adhésion ne sont pas entièrement réglés. */
    private function assertFeePaid(Membership $membership): void
    {
        $remaining = self::feeStatus($membership)['remaining_minor'];
        if ($remaining > 0) {
            throw TontineRuleException::membershipFeeUnpaid($remaining);
        }
    }

    private function resolveCommittedAmount(Campaign $campaign, ?int $provided): int
    {
        if ($campaign->contribution_mode === ContributionMode::Fixed) {
            return $campaign->contribution_amount_minor
                ?? throw new \RuntimeException('Fixed campaign must have contribution_amount_minor set.', 500);
        }

        // Mode custom : montant obligatoire
        if ($provided === null) {
            throw new TontineRuleException('Le montant d\'engagement est obligatoire en cotisation personnalisée.', 'committed_amount_required', 422);
        }

        if ($provided <= 0) {
            throw new TontineRuleException('Le montant d\'engagement doit être supérieur à zéro.', 'committed_amount_invalid', 422);
        }

        if ($campaign->min_contribution_minor !== null && $provided < $campaign->min_contribution_minor) {
            throw new TontineRuleException(
                "Montant d'engagement ({$provided} XOF) inférieur au minimum de la campagne ({$campaign->min_contribution_minor} XOF).",
                'committed_amount_out_of_bounds',
                422,
            );
        }

        if ($campaign->max_contribution_minor !== null && $provided > $campaign->max_contribution_minor) {
            throw new TontineRuleException(
                "Montant d'engagement ({$provided} XOF) supérieur au maximum de la campagne ({$campaign->max_contribution_minor} XOF).",
                'committed_amount_out_of_bounds',
                422,
            );
        }

        return $provided;
    }

    public function validateL1(Membership $membership, User $validator): Membership
    {
        if ($membership->status !== MembershipStatus::PendingL1) {
            throw new TontineRuleException('L\'adhésion n\'attend pas de validation de niveau 1.', 'invalid_membership_transition', 422);
        }

        $needsL2 = $membership->campaign->rules['require_l2_validation'] ?? false;
        $newStatus = $needsL2 ? MembershipStatus::PendingL2 : MembershipStatus::Active;

        if ($newStatus === MembershipStatus::Active) {
            $this->assertFeePaid($membership);
        }

        DB::transaction(function () use ($membership, $validator, $newStatus) {
            $membership->update([
                'status'           => $newStatus->value,
                'validated_l1_by'  => $validator->id,
                'validated_l1_at'  => now(),
                'activated_at'     => $newStatus === MembershipStatus::Active ? now() : null,
            ]);

            if ($newStatus === MembershipStatus::Active) {
                $membership->load('campaign.marketCalendar');
                $this->generator->generate($membership);
                $this->openSavingsAccount($membership);
            }
        });

        AccessLog::record($validator->id, 'membership_validated_l1', null, null, [
            'membership_id' => $membership->id,
        ]);

        return $membership->fresh();
    }

    public function validateL2(Membership $membership, User $validator): Membership
    {
        if ($membership->status !== MembershipStatus::PendingL2) {
            throw new TontineRuleException('L\'adhésion n\'attend pas de validation de niveau 2.', 'invalid_membership_transition', 422);
        }

        if ($membership->validated_l1_by === $validator->id) {
            throw new TontineRuleException('Le validateur de niveau 2 doit être différent de celui du niveau 1.', 'maker_checker_violation', 422);
        }

        $this->assertFeePaid($membership);

        DB::transaction(function () use ($membership, $validator) {
            $membership->update([
                'status'          => MembershipStatus::Active->value,
                'validated_l2_by' => $validator->id,
                'validated_l2_at' => now(),
                'activated_at'    => now(),
            ]);

            $membership->load('campaign.marketCalendar');
            $this->generator->generate($membership);
            $this->openSavingsAccount($membership);
        });

        AccessLog::record($validator->id, 'membership_validated_l2', null, null, [
            'membership_id' => $membership->id,
        ]);

        return $membership->fresh();
    }

    public function suspend(Membership $membership, User $actor, string $reason): Membership
    {
        if ($membership->status !== MembershipStatus::Active) {
            throw new TontineRuleException('Seule une adhésion active peut être suspendue.', 'invalid_membership_transition', 422);
        }

        $membership->update([
            'status'            => MembershipStatus::Suspended->value,
            'suspended_by'      => $actor->id,
            'suspended_at'      => now(),
            'suspension_reason' => $reason,
        ]);

        AccessLog::record($actor->id, 'membership_suspended', null, null, [
            'membership_id' => $membership->id,
            'reason'        => $reason,
        ]);

        return $membership->fresh();
    }

    private function openSavingsAccount(Membership $membership): void
    {
        // Tontine rotative : pas d'épargne individuelle, la caisse commune suffit (R-ROT-06).
        if ($membership->campaign?->isRotating()) {
            return;
        }
        $memberName   = $membership->user->full_name   ?? $membership->user_id;
        $campaignName = $membership->campaign->name    ?? $membership->campaign_id;

        $this->ledger->openAccount(
            key:         "MEMBER_SAVINGS:{$membership->id}",
            type:        AccountType::MemberSavings,
            ownerId:     $membership->user_id,
            description: "Épargne — {$memberName} — {$campaignName}",
        );
    }

    public function reactivate(Membership $membership, User $actor): Membership
    {
        if ($membership->status !== MembershipStatus::Suspended) {
            throw new TontineRuleException('Seule une adhésion suspendue peut être réactivée.', 'invalid_membership_transition', 422);
        }

        $membership->update([
            'status'            => MembershipStatus::Active->value,
            'suspended_by'      => null,
            'suspended_at'      => null,
            'suspension_reason' => null,
        ]);

        AccessLog::record($actor->id, 'membership_reactivated', null, null, [
            'membership_id' => $membership->id,
        ]);

        return $membership->fresh();
    }
}
