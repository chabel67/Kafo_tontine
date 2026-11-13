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
        if (($data['campaign_type'] ?? 'savings') === 'rotating') {
            // Une tontine rotative n'a pas d'épargne individuelle : jamais de retrait anticipé.
            $data['allow_early_withdrawals'] = false;
            $data['duration_installments']   = (int) $data['max_members'] * (int) $data['installments_per_round'];
            $data['first_installment_date']  ??= collect($data['contribution_schedule']['dates'] ?? [])->min();
            $data['rotation_fee_mode']       ??= 'none';
            if ($data['rotation_fee_mode'] === 'none') {
                $data['rotation_fee_minor'] = 0;
            }
        }

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

        // Lot 3 : le démarrage d'une tontine rotative vérifie le groupe et les tours.
        if ($newStatus === CampaignStatus::Active && $campaign->isRotating()) {
            return app(RotationAssignmentService::class)->start($campaign, $actor);
        }
        // Reconduction (R3) : un cycle ne démarre qu'après le règlement complet du précédent.
        if ($newStatus === CampaignStatus::Active) {
            app(CampaignRenewalService::class)->assertPreviousCycleSettled($campaign);
        }

        // Lot 2 : une tontine rotative n'ouvre ses adhésions qu'avec un calendrier confirmé (figé).
        if ($newStatus === CampaignStatus::Open && $campaign->isRotating() && ! $campaign->schedule_confirmed_at) {
            throw new TontineRuleException('Confirmez le calendrier de la tontine avant d\'ouvrir les adhésions.', 'rotation_schedule_not_confirmed', 422);
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

        // Cycle reconduit qui démarre : les membres validés sont prévenus.
        if ($newStatus === CampaignStatus::Active && $campaign->cycle_number > 1) {
            $first = \App\Modules\Tontine\Infrastructure\Models\Installment::where('campaign_id', $campaign->id)->min('due_date');
            foreach ($campaign->memberships()->where('status', 'active')->pluck('user_id') as $userId) {
                app(\App\Modules\Notifications\Application\NotificationService::class)->notify($userId, 'campaign.cycle_started',
                    "Le cycle {$campaign->cycle_number} commence",
                    "« {$campaign->name} » démarre." . ($first ? ' Première cotisation le ' . \Carbon\Carbon::parse($first)->format('d/m/Y') . '.' : ''),
                    '/campagnes');
            }
        }

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

        // Lot 2 : en brouillon, changer un paramètre du calendrier rotatif annule sa confirmation.
        if ($campaign->isRotating() && $campaign->schedule_confirmed_at
            && array_intersect(array_keys($data), self::ROTATION_SCHEDULE_FIELDS) !== []) {
            app(RotationScheduleService::class)->discard($campaign);
        }

        return $campaign->fresh();
    }

    /** Paramètres dont dépend le calendrier confirmé d'une tontine rotative. */
    private const ROTATION_SCHEDULE_FIELDS = [
        'max_members', 'installments_per_round', 'contribution_schedule', 'first_installment_date',
        'contribution_amount_minor', 'rotation_fee_mode', 'rotation_fee_minor', 'grace_period_days',
    ];

    /**
     * R-WDR-08 : autorise ou suspend les NOUVELLES demandes de retrait anticipé.
     * Possible tant que la campagne n'est pas clôturée ; motif et audit côté contrôleur.
     */
    public function updateWithdrawalPolicy(Campaign $campaign, bool $allow): Campaign
    {
        if ($campaign->status === CampaignStatus::Closed) {
            throw TontineRuleException::campaignNotActive();
        }
        if ($campaign->isRotating() && $allow) {
            throw TontineRuleException::notForRotatingCampaign();
        }

        $campaign->update(['allow_early_withdrawals' => $allow]);

        return $campaign->fresh();
    }
}
