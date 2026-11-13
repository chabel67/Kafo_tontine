<?php

namespace App\Modules\Tontine\Application;

use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Notifications\Application\NotificationService;
use App\Modules\Tontine\Domain\Enums\CampaignStatus;
use App\Modules\Tontine\Domain\Enums\MembershipStatus;
use App\Modules\Tontine\Domain\Enums\RotationRoundStatus;
use App\Modules\Tontine\Domain\Exceptions\TontineRuleException;
use App\Modules\Tontine\Infrastructure\Models\Campaign;
use App\Modules\Tontine\Infrastructure\Models\Membership;
use App\Modules\Tontine\Infrastructure\Models\RotationRound;
use Illuminate\Support\Facades\DB;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Lot 3 — groupe fermé, attribution des tours et démarrage d'une tontine rotative.
 *
 *  - Attribution (D-R6) : avant le démarrage seulement, manuelle (ordre saisi) ou par tirage.
 *    Le tirage est un mélange déterministe d'une graine : la proposition est recalculable,
 *    l'ordre confirmé est vérifié contre la graine et tracé dans l'audit.
 *  - Démarrage : groupe complet (N adhésions actives, aucune en attente), calendrier confirmé,
 *    chaque tour a un bénéficiaire distinct. Ensuite l'ordre est FIGÉ et plus aucune adhésion
 *    ordinaire n'est acceptée.
 */
class RotationAssignmentService
{
    public function __construct(private readonly NotificationService $notifications) {}

    /**
     * Proposition de tirage (n'écrit rien).
     *
     * @return array{seed:int, order:string[]}
     */
    public function draw(Campaign $campaign, ?int $seed = null): array
    {
        $this->assertAssignable($campaign);
        $seed ??= random_int(1, 2_147_483_647);

        return ['seed' => $seed, 'order' => $this->shuffled($this->activeMembershipIds($campaign), $seed)];
    }

    /**
     * Fige l'ordre des bénéficiaires (avant démarrage ; une nouvelle attribution remplace l'ancienne).
     *
     * @param  string[]  $order  identifiants d'adhésion, du tour 1 au tour N
     */
    public function assign(Campaign $campaign, array $order, string $method, ?int $seed): array
    {
        return DB::transaction(function () use ($campaign, $order, $method, $seed) {
            $campaign = Campaign::whereKey($campaign->id)->lockForUpdate()->firstOrFail();
            $this->assertAssignable($campaign);

            $active = $this->activeMembershipIds($campaign);
            $n      = (int) $campaign->max_members;

            if (count($active) !== $n) {
                throw new TontineRuleException(
                    "Le groupe doit être complet avant l'attribution : {$n} adhésions actives attendues, " . count($active) . ' actuellement.',
                    'rotation_group_incomplete', 422);
            }
            if (count($order) !== $n || count(array_unique($order)) !== $n || array_diff($order, $active) !== []) {
                throw new TontineRuleException(
                    'L\'ordre doit contenir chaque membre actif exactement une fois.', 'rotation_invalid_order', 422);
            }
            if ($method === 'draw') {
                if ($seed === null || $this->shuffled($active, $seed) !== array_values($order)) {
                    throw new TontineRuleException(
                        'Cet ordre ne correspond pas au tirage indiqué : relancez le tirage.', 'rotation_draw_mismatch', 422);
                }
            }

            $rounds = RotationRound::where('campaign_id', $campaign->id)->orderBy('round_number')->lockForUpdate()->get();
            if ($rounds->count() !== $n) {
                throw new TontineRuleException('Calendrier non confirmé : aucun tour à attribuer.', 'rotation_schedule_not_confirmed', 422);
            }

            // Libère d'abord (l'index unique interdit deux tours pour un même membre).
            RotationRound::where('campaign_id', $campaign->id)->update(['beneficiary_membership_id' => null]);
            foreach ($rounds as $i => $round) {
                $round->update(['beneficiary_membership_id' => $order[$i]]);
            }

            return ['method' => $method, 'seed' => $seed, 'order' => array_values($order)];
        });
    }

    public function start(Campaign $campaign, User $actor): Campaign
    {
        $started = DB::transaction(function () use ($campaign) {
            $campaign = Campaign::whereKey($campaign->id)->lockForUpdate()->firstOrFail();

            if (! $campaign->isRotating()) {
                throw TontineRuleException::notRotating();
            }
            if ($campaign->status !== CampaignStatus::Open) {
                throw new TontineRuleException('Seule une tontine ouverte aux adhésions peut démarrer.', 'rotation_not_startable', 422);
            }
            if (! $campaign->schedule_confirmed_at) {
                throw new TontineRuleException('Confirmez le calendrier avant de démarrer.', 'rotation_schedule_not_confirmed', 422);
            }
            // Reconduction (R3) : le cycle précédent doit être entièrement réglé.
            app(CampaignRenewalService::class)->assertPreviousCycleSettled($campaign);

            $n       = (int) $campaign->max_members;
            $active  = Membership::where('campaign_id', $campaign->id)->where('status', MembershipStatus::Active)->count();
            $pending = Membership::where('campaign_id', $campaign->id)
                ->whereIn('status', [MembershipStatus::PendingL1, MembershipStatus::PendingL2])->count();
            if ($active !== $n || $pending > 0) {
                throw new TontineRuleException(
                    "Groupe incomplet : {$active}/{$n} adhésions actives" . ($pending ? ", {$pending} encore en attente de validation" : '') . '.',
                    'rotation_group_incomplete', 422);
            }

            $unassigned = RotationRound::where('campaign_id', $campaign->id)->whereNull('beneficiary_membership_id')->count();
            if ($unassigned > 0) {
                throw new TontineRuleException("{$unassigned} tour(s) sans bénéficiaire : attribuez les tours avant de démarrer.", 'rotation_rounds_unassigned', 422);
            }

            RotationRound::where('campaign_id', $campaign->id)->where('round_number', 1)
                ->update(['status' => RotationRoundStatus::Collecting->value]);

            $campaign->update([
                'status'       => CampaignStatus::Active->value,
                'activated_at' => now(),
                'started_at'   => now(),
            ]);

            return $campaign->fresh();
        });

        // Chaque membre est informé de son tour et de sa date (D-R8 : la sienne seulement ici).
        $rounds = RotationRound::with('beneficiary:id,user_id')->where('campaign_id', $started->id)->get();
        foreach ($rounds as $r) {
            $this->notifications->notify(
                $r->beneficiary?->user_id,
                'rotation.round_assigned',
                'Votre tour de tontine',
                "Tontine « {$started->name} » : vous recevrez la cagnotte au tour {$r->round_number}, prévu le "
                    . $r->scheduled_date->format('d/m/Y') . '. Continuez à cotiser jusqu\'à la fin de la tontine.',
                '/campagnes',
            );
        }

        return $started;
    }

    /** Attribution possible tant que la tontine n'a pas démarré (ordre figé ensuite, D-R6). */
    private function assertAssignable(Campaign $campaign): void
    {
        if (! $campaign->isRotating()) {
            throw TontineRuleException::notRotating();
        }
        if (! in_array($campaign->status, [CampaignStatus::Draft, CampaignStatus::Open], true) || $campaign->started_at) {
            throw new TontineRuleException('L\'ordre des tours est figé depuis le démarrage de la tontine.', 'rotation_order_locked', 422);
        }
    }

    /** @return string[] */
    private function activeMembershipIds(Campaign $campaign): array
    {
        return Membership::where('campaign_id', $campaign->id)
            ->where('status', MembershipStatus::Active)
            ->orderBy('id')
            ->pluck('id')
            ->all();
    }

    /** Mélange déterministe (même graine, même liste triée → même ordre). */
    private function shuffled(array $ids, int $seed): array
    {
        sort($ids);

        return (new Randomizer(new Mt19937($seed)))->shuffleArray($ids);
    }
}
