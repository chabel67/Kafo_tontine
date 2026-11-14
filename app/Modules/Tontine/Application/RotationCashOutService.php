<?php

namespace App\Modules\Tontine\Application;

use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Ledger\Application\LedgerService;
use App\Modules\Ledger\Domain\Enums\AccountType;
use App\Modules\Ledger\Domain\ValueObjects\TreasuryAccount;
use App\Modules\Notifications\Application\NotificationService;
use App\Modules\Tontine\Domain\Enums\RotationRoundStatus;
use App\Modules\Tontine\Domain\Exceptions\MakerCheckerViolationException;
use App\Modules\Tontine\Domain\Exceptions\TontineRuleException;
use App\Modules\Tontine\Infrastructure\Models\Campaign;
use App\Modules\Tontine\Infrastructure\Models\Membership;
use App\Modules\Tontine\Infrastructure\Models\RotationDeparture;
use App\Modules\Tontine\Infrastructure\Models\RotationPayoutAttempt as Attempt;
use App\Modules\Tontine\Infrastructure\Models\RotationRound;
use App\Shared\Reference;
use Illuminate\Support\Facades\DB;

/**
 * Lot 8 — sorties de la caisse commune autres que les cagnottes, en deux temps (D-R9) :
 *  - `departure_refund` : remboursement d'un partant non servi (D-R7), montant fixé par l'équipe ;
 *  - `surplus`          : versement d'une part du reliquat à un membre à la clôture (D-R11).
 *
 * Lancement (step-up côté route, versant ≠ décideur pour un départ) : tentative `processing`,
 * AUCUNE écriture. Confirmation : UNE écriture DR ROTATING_POOL / CR trésorerie. Échec : rien.
 * Caisse disponible = solde − cagnottes des tours validés ou en versement (jamais dépassée).
 */
class RotationCashOutService
{
    public function __construct(
        private readonly LedgerService           $ledger,
        private readonly RotationTrackingService $tracking,
        private readonly NotificationService     $notifications,
    ) {}

    public function initiateDepartureRefund(RotationDeparture $departure, string $channel, ?string $phone, string $key, User $actor, ?string $network = null): Attempt
    {
        return $this->initiate(Attempt::KIND_DEPARTURE, $departure->campaign_id, $departure->membership_id, $departure->refund_minor,
            $channel, $phone, $key, $actor, $departure->reason, $network, function () use ($departure, $actor) {
                $d = RotationDeparture::whereKey($departure->id)->lockForUpdate()->firstOrFail();
                if (! in_array($d->status, [RotationDeparture::PENDING, RotationDeparture::REFUNDING], true) || $d->refund_minor <= 0) {
                    throw new TontineRuleException('Ce départ n\'a pas de remboursement à verser.', 'departure_not_refundable', 422);
                }
                if ($d->decided_by === $actor->id) {
                    throw new MakerCheckerViolationException();
                }
                if (Attempt::where('rotation_departure_id', $d->id)->where('status', Attempt::PROCESSING)->exists()) {
                    throw new TontineRuleException('Un versement est déjà en cours pour ce départ.', 'cash_out_in_progress', 422);
                }
                $d->update(['status' => RotationDeparture::REFUNDING]);

                return ['rotation_departure_id' => $d->id];
            });
    }

    public function initiateSurplus(Campaign $campaign, Membership $membership, int $amount, string $reason, string $channel, ?string $phone, string $key, User $actor, ?string $network = null): Attempt
    {
        if ($membership->campaign_id !== $campaign->id) {
            throw new TontineRuleException('Ce membre ne fait pas partie de cette tontine.', 'not_a_member', 422);
        }

        return $this->initiate(Attempt::KIND_SURPLUS, $campaign->id, $membership->id, $amount, $channel, $phone, $key, $actor, $reason, $network,
            function () use ($campaign) {
                if ($campaign->rounds()->whereNotIn('status', [RotationRoundStatus::Completed->value, RotationRoundStatus::Vacant->value])->exists()) {
                    throw new TontineRuleException('Le reliquat se répartit une fois tous les tours traités.', 'rounds_not_finished', 422);
                }

                return [];
            });
    }

    public function confirm(Attempt $attempt, ?string $externalReference, User $actor): Attempt
    {
        $done = DB::transaction(function () use ($attempt, $externalReference, $actor) {
            $campaign = Campaign::whereKey($attempt->campaign_id)->lockForUpdate()->firstOrFail();
            $a = Attempt::whereKey($attempt->id)->lockForUpdate()->firstOrFail();
            $this->assertCashOut($a);
            if ($a->status !== Attempt::PROCESSING) {
                throw new TontineRuleException('Ce versement n\'est pas en cours.', 'cash_out_not_processing', 422);
            }

            $balance = $this->tracking->poolBalance($campaign->id);
            if ($balance < $a->amount_minor) {
                throw new TontineRuleException("Caisse insuffisante : {$balance} XOF pour {$a->amount_minor} XOF.", 'rotation_pool_insufficient', 422);
            }

            $poolKey  = "ROTATING_POOL:{$campaign->id}";
            $treasury = TreasuryAccount::key($a->channel);
            $this->ledger->openAccount($treasury, TreasuryAccount::type($a->channel));
            $reference = Reference::next('RTO', Reference::RTO);
            $label     = $a->kind === Attempt::KIND_DEPARTURE ? 'Remboursement départ tontine' : 'Reliquat tontine — versement';

            $txn = $this->ledger->post(
                legs: [
                    ['account' => $poolKey,  'type' => 'debit',  'amount' => $a->amount_minor],
                    ['account' => $treasury, 'type' => 'credit', 'amount' => $a->amount_minor],
                ],
                reference:   $this->ledger->nextReference(),
                description: "{$label} {$reference}",
                createdById: $actor->id,
                metadata:    ['payout_attempt_id' => $a->id, 'kind' => $a->kind, 'campaign_id' => $campaign->id, 'membership_id' => $a->membership_id],
            );

            $a->update([
                'status' => Attempt::SUCCEEDED, 'reference' => $reference, 'external_reference' => $externalReference,
                'completed_by' => $actor->id, 'completed_at' => now(), 'ledger_transaction_id' => $txn->id,
            ]);

            if ($a->rotation_departure_id) {
                RotationDeparture::whereKey($a->rotation_departure_id)->update(['status' => RotationDeparture::REFUNDED]);
            }

            return $a->fresh();
        });

        $user = Membership::whereKey($done->membership_id)->value('user_id');
        $this->notifications->notify($user, 'rotation.cash_out', 'Versement effectué',
            ($done->kind === Attempt::KIND_DEPARTURE ? 'Votre remboursement de départ' : 'Votre part du reliquat de la tontine')
            . ' (' . number_format($done->amount_minor, 0, ',', ' ') . ' FCFA) a été versé.', '/campagnes');

        return $done;
    }

    public function fail(Attempt $attempt, string $reason, User $actor): Attempt
    {
        return DB::transaction(function () use ($attempt, $reason, $actor) {
            $a = Attempt::whereKey($attempt->id)->lockForUpdate()->firstOrFail();
            $this->assertCashOut($a);
            if ($a->status !== Attempt::PROCESSING) {
                throw new TontineRuleException('Ce versement n\'est pas en cours.', 'cash_out_not_processing', 422);
            }
            $a->update(['status' => Attempt::FAILED, 'failure_reason' => $reason, 'completed_by' => $actor->id, 'completed_at' => now()]);

            return $a->fresh();
        });
    }

    /** Caisse disponible : solde − cagnottes réservées − autres sorties en cours. */
    public function availablePool(string $campaignId, ?string $excludeAttemptId = null): int
    {
        $reservedRounds = (int) RotationRound::where('campaign_id', $campaignId)
            ->whereIn('status', [RotationRoundStatus::Approved->value, RotationRoundStatus::Processing->value, RotationRoundStatus::Failed->value])
            ->sum('expected_minor');
        $inFlight = (int) Attempt::where('campaign_id', $campaignId)
            ->whereIn('kind', [Attempt::KIND_DEPARTURE, Attempt::KIND_SURPLUS])
            ->where('status', Attempt::PROCESSING)
            ->when($excludeAttemptId, fn ($q) => $q->where('id', '!=', $excludeAttemptId))
            ->sum('amount_minor');

        return $this->tracking->poolBalance($campaignId) - $reservedRounds - $inFlight;
    }

    private function initiate(string $kind, string $campaignId, string $membershipId, int $amount, string $channel, ?string $phone,
        string $key, User $actor, string $reason, ?string $network, callable $guard): Attempt
    {
        if ($existing = Attempt::where('idempotency_key', $key)->first()) {
            if ($existing->kind !== $kind || $existing->membership_id !== $membershipId) {
                throw TontineRuleException::idempotencyKeyReused();
            }
            return $existing;
        }
        if ($amount <= 0) {
            throw new TontineRuleException('Le montant doit être supérieur à zéro.', 'invalid_amount', 422);
        }

        return DB::transaction(function () use ($kind, $campaignId, $membershipId, $amount, $channel, $phone, $key, $actor, $reason, $network, $guard) {
            Campaign::whereKey($campaignId)->lockForUpdate()->firstOrFail();
            $extra = $guard();

            $available = $this->availablePool($campaignId);
            if ($available < $amount) {
                throw new TontineRuleException("Caisse insuffisante : {$available} XOF disponibles pour {$amount} XOF.", 'rotation_pool_insufficient', 422);
            }

            return Attempt::create([
                'kind'            => $kind,
                'campaign_id'     => $campaignId,
                'membership_id'   => $membershipId,
                ...$extra,
                'attempt_no'      => (int) Attempt::where('campaign_id', $campaignId)->where('membership_id', $membershipId)->where('kind', $kind)->max('attempt_no') + 1,
                'idempotency_key' => $key,
                'amount_minor'    => $amount,
                'reason'          => $reason,
                'channel'         => $channel,
                'phone'           => $channel === 'cash' ? null : $phone,
                'network'         => $channel === 'cash' ? null : $network,
                'status'          => Attempt::PROCESSING,
                'initiated_by'    => $actor->id,
                'initiated_at'    => now(),
            ]);
        });
    }

    private function assertCashOut(Attempt $a): void
    {
        if (! in_array($a->kind, [Attempt::KIND_DEPARTURE, Attempt::KIND_SURPLUS], true)) {
            throw new TontineRuleException('Utilisez la distribution du tour pour une cagnotte.', 'invalid_cash_out', 422);
        }
    }
}
