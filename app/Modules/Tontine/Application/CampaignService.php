<?php

namespace App\Modules\Tontine\Application;

use App\Modules\Identity\Domain\Exceptions\UnauthorizedException;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Tontine\Domain\Enums\CampaignStatus;
use App\Modules\Tontine\Domain\Exceptions\TontineRuleException;
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

        // R-CAMP-08 : la clôture est déléguée à CampaignClosureService (payouts, retraits,
        // demandes d'avance). R-CAMP-03 est supprimée : un prêt actif ne bloque pas la clôture
        // (avances compensées au règlement, prêts communautaires indépendants — R-LOAN-16).
        if ($newStatus === CampaignStatus::Closed) {
            app(CampaignClosureService::class)->close($campaign, $actor, $reason);
            return $campaign->fresh();
        }

        $timestamps = match ($newStatus) {
            CampaignStatus::Open   => ['opened_at'    => now()],
            CampaignStatus::Active => ['activated_at' => now()],
            default                => [],
        };

        $campaign->update([
            'status' => $newStatus->value,
            ...$timestamps,
        ]);

        return $campaign->fresh();
    }

    /** Règles « douces » encore modifiables une fois la campagne ouverte (R-CAMP-02). */
    private const SOFT_RULES = ['description', 'grace_period_days'];

    public function update(Campaign $campaign, array $data): Campaign
    {
        // R-CAMP-02 : règles dures verrouillées hors brouillon — refus explicite (plus d'abandon
        // silencieux). La politique de retrait a son propre endpoint motivé et audité.
        if ($campaign->status !== CampaignStatus::Draft) {
            $locked = array_values(array_diff(array_keys($data), self::SOFT_RULES));
            $locked = array_values(array_filter($locked, fn ($f) => $campaign->getAttribute($f) != $data[$f]));
            if ($locked !== []) {
                throw TontineRuleException::hardRuleLocked($locked);
            }
        }

        $campaign->update($data);
        return $campaign->fresh();
    }

    /**
     * R-WDR-08 : autorise ou suspend les NOUVELLES demandes de retrait anticipé.
     * Possible tant que la campagne n'est pas clôturée ; motif et audit côté contrôleur.
     */
    public function updateWithdrawalPolicy(Campaign $campaign, bool $allow): Campaign
    {
        if ($campaign->status === CampaignStatus::Closed) {
            throw TontineRuleException::campaignNotActive();
        }

        $campaign->update(['allow_early_withdrawals' => $allow]);

        return $campaign->fresh();
    }
}
