<?php

namespace App\Modules\Tontine\Application;

use App\Modules\Identity\Infrastructure\Models\AccessLog;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Ledger\Application\LedgerService;
use App\Modules\Ledger\Domain\Enums\AccountType;
use App\Modules\Tontine\Domain\Enums\ContributionMode;
use App\Modules\Tontine\Domain\Enums\MembershipStatus;
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
        if ($campaign->status->value !== 'open' && $campaign->status->value !== 'active') {
            throw new \RuntimeException('Campaign is not accepting new members.', 422);
        }

        if (Membership::where('user_id', $member->id)->where('campaign_id', $campaign->id)->exists()) {
            throw new \RuntimeException('Member already enrolled in this campaign.', 409);
        }

        if ($campaign->max_members !== null) {
            $current = Membership::where('campaign_id', $campaign->id)
                ->whereIn('status', ['pending_l1', 'pending_l2', 'active'])
                ->count();
            if ($current >= $campaign->max_members) {
                throw new \RuntimeException('Campaign is full.', 422);
            }
        }

        // Résolution du montant d'engagement
        $resolved = $this->resolveCommittedAmount($campaign, $committedAmount);

        return Membership::create([
            'user_id'                => $member->id,
            'campaign_id'            => $campaign->id,
            'status'                 => MembershipStatus::PendingL1->value,
            'committed_amount_minor' => $resolved,
        ]);
    }

    private function resolveCommittedAmount(Campaign $campaign, ?int $provided): int
    {
        if ($campaign->contribution_mode === ContributionMode::Fixed) {
            return $campaign->contribution_amount_minor
                ?? throw new \RuntimeException('Fixed campaign must have contribution_amount_minor set.', 500);
        }

        // Mode custom : montant obligatoire
        if ($provided === null) {
            throw new \RuntimeException('committed_amount is required for custom contribution campaigns.', 422);
        }

        if ($provided <= 0) {
            throw new \RuntimeException('committed_amount must be greater than zero.', 422);
        }

        if ($campaign->min_contribution_minor !== null && $provided < $campaign->min_contribution_minor) {
            throw new \RuntimeException(
                "committed_amount ({$provided}) is below the minimum allowed ({$campaign->min_contribution_minor}).",
                422,
            );
        }

        if ($campaign->max_contribution_minor !== null && $provided > $campaign->max_contribution_minor) {
            throw new \RuntimeException(
                "committed_amount ({$provided}) exceeds the maximum allowed ({$campaign->max_contribution_minor}).",
                422,
            );
        }

        return $provided;
    }

    public function validateL1(Membership $membership, User $validator): Membership
    {
        if ($membership->status !== MembershipStatus::PendingL1) {
            throw new \RuntimeException('Membership is not pending L1 validation.', 422);
        }

        $needsL2 = $membership->campaign->rules['require_l2_validation'] ?? false;
        $newStatus = $needsL2 ? MembershipStatus::PendingL2 : MembershipStatus::Active;

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
            throw new \RuntimeException('Membership is not pending L2 validation.', 422);
        }

        if ($membership->validated_l1_by === $validator->id) {
            throw new \RuntimeException('L2 validator must be different from L1 validator.', 422);
        }

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
            throw new \RuntimeException('Only active memberships can be suspended.', 422);
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
            throw new \RuntimeException('Only suspended memberships can be reactivated.', 422);
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
