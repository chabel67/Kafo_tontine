<?php

namespace App\Modules\Tontine\Application;

use App\Modules\Identity\Domain\Exceptions\UnauthorizedException;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Tontine\Domain\Enums\CampaignStatus;
use App\Modules\Tontine\Domain\Exceptions\ActiveLoansBlockClosureException;
use App\Modules\Tontine\Domain\Exceptions\InvalidStatusTransitionException;
use App\Modules\Tontine\Infrastructure\Models\Campaign;

class CampaignService
{
    public function create(array $data, User $creator): Campaign
    {
        $campaign = Campaign::create([
            ...$data,
            'created_by' => $creator->id,
            'status'     => CampaignStatus::Draft->value,
            'currency'   => $data['currency'] ?? 'XOF',
        ]);

        return $campaign->fresh();
    }

    public function transition(Campaign $campaign, CampaignStatus $newStatus, User $actor, string $reason = ''): Campaign
    {
        if (! $campaign->status->canTransitionTo($newStatus)) {
            throw new InvalidStatusTransitionException($campaign->status->value, $newStatus->value);
        }

        if ($newStatus === CampaignStatus::Closed) {
            // R-CAMP-03 : block closure if active loans exist (placeholder — Lending module not built yet)
            // $activeLoans = $campaign->loans()->whereIn('status', ['disbursed', 'overdue'])->count();
            // if ($activeLoans > 0) throw new ActiveLoansBlockClosureException($activeLoans);
        }

        $timestamps = match ($newStatus) {
            CampaignStatus::Open   => ['opened_at'    => now()],
            CampaignStatus::Active => ['activated_at' => now()],
            CampaignStatus::Closed => ['closed_at'    => now()],
            default                => [],
        };

        $campaign->update([
            'status' => $newStatus->value,
            ...$timestamps,
        ]);

        return $campaign->fresh();
    }

    public function update(Campaign $campaign, array $data): Campaign
    {
        // R-CAMP-02 : hard rules locked once open
        if ($campaign->status !== CampaignStatus::Draft) {
            $data = array_intersect_key($data, array_flip([
                'description', 'grace_period_days', 'rules',
            ]));
        }

        $campaign->update($data);
        return $campaign->fresh();
    }
}
