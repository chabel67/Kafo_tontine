<?php

namespace App\Modules\Tontine\Application;

use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Ledger\Application\LedgerService;
use App\Modules\Ledger\Domain\Enums\AccountType;
use App\Modules\Ledger\Domain\ValueObjects\TreasuryAccount;
use App\Modules\Notifications\Application\NotificationService;
use App\Modules\Tontine\Domain\Enums\CampaignStatus;
use App\Modules\Tontine\Domain\Enums\MembershipStatus;
use App\Modules\Tontine\Domain\Enums\RotationRoundStatus as S;
use App\Modules\Tontine\Domain\Exceptions\MakerCheckerViolationException;
use App\Modules\Tontine\Domain\Exceptions\TontineRuleException;
use App\Modules\Tontine\Infrastructure\Models\Campaign;
use App\Modules\Tontine\Infrastructure\Models\RotationPayoutAttempt as Attempt;
use App\Modules\Tontine\Infrastructure\Models\RotationRound;
use App\Shared\BusinessTime;
use App\Shared\Reference;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Lot 5 — distribution d'un tour (cagnotte), calquée sur les retraits anticipés (D-R9) :
 *
 *   preview  : couverture, impayés, retards du bénéficiaire, caisse, frais, net, blocages ;
 *   approve  : `rotation.approve` ; impayés (D-R3) ou bénéficiaire en retard (D-R4) → motif
 *              OBLIGATOIRE, tracé (`override_reasons`) ; jamais plus que la caisse disponible ;
 *   initiate : `rotation.disburse` + step-up ; versant ≠ validateur ; tentative `processing`,
 *              AUCUNE écriture (une réponse réseau perdue ne provoque pas de second versement) ;
 *   confirm  : UNE écriture DR ROTATING_POOL (cagnotte) / CR trésorerie (net) + FEE_INCOME (retenue) ;
 *   fail     : aucune écriture, nouvelle tentative possible.
 *
 * Verrous : campagne (exclusif pour tout mouvement de la caisse) → tour → tentative.
 * Caisse disponible = solde ROTATING_POOL − cagnottes des AUTRES tours validés / en cours.
 */
class RotationPayoutService
{
    public function __construct(
        private readonly LedgerService           $ledger,
        private readonly RotationTrackingService $tracking,
        private readonly NotificationService     $notifications,
    ) {}

    public function preview(RotationRound $round): array
    {
        $round->loadMissing('campaign', 'beneficiary.user');
        $this->tracking->refreshStatuses($round->campaign);
        $round->refresh();

        $coverage  = $this->tracking->coverage($round);
        $available = $this->availablePool($round);
        $blockers  = $this->hardBlockers($round, $available);

        return [
            'round_id'        => $round->id,
            'round_number'    => $round->round_number,
            'status'          => $round->status->value,
            'status_label'    => $round->status->label(),
            'scheduled_date'  => $round->scheduled_date->toDateString(),
            'campaign'        => ['id' => $round->campaign->id, 'name' => $round->campaign->name],
            'beneficiary'     => $round->beneficiary ? [
                'membership_id' => $round->beneficiary->id,
                'full_name'     => $round->beneficiary->user?->full_name,
                'phone'         => $round->beneficiary->user?->phone,
            ] : null,
            'pot_minor'       => $round->expected_minor,
            'fee_minor'       => $round->fee_minor,
            'net_minor'       => $round->netMinor(),
            'collected_minor' => $coverage['collected_minor'],
            'missing_minor'   => $coverage['missing_minor'],
            'unpaid'          => $coverage['unpaid'],
            'beneficiary_arrears' => $coverage['beneficiary_arrears'],
            'pool_balance_minor'  => $coverage['pool_balance_minor'],
            'pool_available_minor' => $available,
            // Le personnel peut passer outre (avec motif) ; jamais pour les blocages durs.
            'overrides_required' => array_values(array_filter([
                $coverage['unpaid'] !== [] ? 'unpaid' : null,
                $coverage['beneficiary_arrears']['count'] > 0 ? 'beneficiary_arrears' : null,
            ])),
            'blockers'        => $blockers,
            'approved_by'     => $round->approved_by,
            'override_reasons' => $round->override_reasons,
            'attempts'        => $round->attempts()->get()->map(fn (Attempt $a) => [
                'id' => $a->id, 'attempt_no' => $a->attempt_no, 'status' => $a->status, 'channel' => $a->channel,
                'amount_minor' => $a->amount_minor, 'failure_reason' => $a->failure_reason,
                'initiated_at' => $a->initiated_at?->toIso8601String(), 'completed_at' => $a->completed_at?->toIso8601String(),
            ]),
        ];
    }

    public function approve(RotationRound $round, User $actor, ?string $unpaidReason, ?string $arrearsReason): RotationRound
    {
        $this->tracking->refreshStatuses($round->campaign);

        $approved = DB::transaction(function () use ($round, $actor, $unpaidReason, $arrearsReason) {
            $this->lockCampaign($round->campaign_id);
            $r = RotationRound::whereKey($round->id)->lockForUpdate()->firstOrFail();

            if (! in_array($r->status, [S::Ready, S::Blocked], true)) {
                throw $this->transitionError($r, 'valider');
            }
            if ($blockers = $this->hardBlockers($r, $this->availablePool($r))) {
                throw new TontineRuleException(implode(' ', $blockers), 'rotation_round_blocked', 422);
            }

            $coverage  = $this->tracking->coverage($r);
            $overrides = [];
            if ($coverage['unpaid'] !== []) {
                if (mb_strlen(trim((string) $unpaidReason)) < 10) {
                    throw ValidationException::withMessages(['unpaid_reason' =>
                        'Des cotisations de ce tour sont impayées (' . $coverage['missing_minor'] . ' XOF manquants) : motif obligatoire (10 caractères minimum) pour verser quand même.']);
                }
                $overrides['unpaid'] = ['reason' => trim($unpaidReason), 'missing_minor' => $coverage['missing_minor'], 'unpaid_count' => count($coverage['unpaid'])];
            }
            if ($coverage['beneficiary_arrears']['count'] > 0) {
                if (mb_strlen(trim((string) $arrearsReason)) < 10) {
                    throw ValidationException::withMessages(['beneficiary_arrears_reason' =>
                        'Le bénéficiaire a ' . $coverage['beneficiary_arrears']['count'] . ' échéance(s) en retard : motif obligatoire (10 caractères minimum).']);
                }
                $overrides['beneficiary_arrears'] = ['reason' => trim($arrearsReason), ...$coverage['beneficiary_arrears']];
            }

            $r->update([
                'status'           => S::Approved,
                'approved_by'      => $actor->id,
                'approved_at'      => now(),
                'override_reasons' => $overrides ?: null,
            ]);

            return $r->fresh(['beneficiary', 'campaign']);
        });

        $this->notifyBeneficiary($approved, 'Votre cagnotte est prête',
            "Tontine « {$approved->campaign->name} » : votre cagnotte du tour {$approved->round_number} ("
            . $this->money($approved->netMinor()) . ') va vous être versée prochainement.');

        return $approved;
    }

    public function initiate(RotationRound $round, string $channel, ?string $phone, string $idempotencyKey, User $actor): Attempt
    {
        if ($existing = Attempt::where('idempotency_key', $idempotencyKey)->first()) {
            if ($existing->rotation_round_id !== $round->id) {
                throw TontineRuleException::idempotencyKeyReused();
            }
            return $existing;
        }

        return DB::transaction(function () use ($round, $channel, $phone, $idempotencyKey, $actor) {
            $this->lockCampaign($round->campaign_id);
            $r = RotationRound::whereKey($round->id)->lockForUpdate()->firstOrFail();

            if (! $r->status->canTransitionTo(S::Processing)) {
                throw $this->transitionError($r, 'verser');
            }
            // Maker/checker (D-R9) : celui qui valide ne verse pas.
            if ($r->approved_by === $actor->id) {
                throw new MakerCheckerViolationException();
            }
            $available = $this->availablePool($r);
            if ($available < $r->expected_minor) {
                throw $this->poolShortage($available, $r->expected_minor);
            }

            $attempt = Attempt::create([
                'kind'              => Attempt::KIND_ROUND,
                'campaign_id'       => $r->campaign_id,
                'membership_id'     => $r->beneficiary_membership_id,
                'rotation_round_id' => $r->id,
                'attempt_no'        => (int) $r->attempts()->max('attempt_no') + 1,
                'idempotency_key'   => $idempotencyKey,
                'amount_minor'      => $r->netMinor(),
                'channel'           => $channel,
                'phone'             => $channel === 'cash' ? null : $phone,
                'status'            => Attempt::PROCESSING,
                'initiated_by'      => $actor->id,
                'initiated_at'      => now(),
            ]);

            $r->update(['status' => S::Processing]);

            return $attempt;
        });
    }

    public function confirm(RotationRound $round, ?string $externalReference, User $actor): RotationRound
    {
        $completed = DB::transaction(function () use ($round, $externalReference, $actor) {
            $campaign = $this->lockCampaign($round->campaign_id);
            $r        = RotationRound::whereKey($round->id)->lockForUpdate()->firstOrFail();

            if (! $r->status->canTransitionTo(S::Completed)) {
                throw $this->transitionError($r, 'confirmer');
            }
            $attempt = Attempt::where('rotation_round_id', $r->id)->where('status', Attempt::PROCESSING)->lockForUpdate()->firstOrFail();

            // L'argent est remis : seule garantie exigée, la caisse ne devient pas négative.
            $poolKey = "ROTATING_POOL:{$campaign->id}";
            $balance = $this->tracking->poolBalance($campaign->id);
            if ($balance < $r->expected_minor) {
                throw $this->poolShortage($balance, $r->expected_minor);
            }

            $treasury = TreasuryAccount::key($attempt->channel);
            $this->ledger->openAccount($treasury, TreasuryAccount::type($attempt->channel));
            $this->ledger->openAccount($poolKey, AccountType::RotatingPool, null, "Caisse tontine — {$campaign->name}");

            $legs = [
                ['account' => $poolKey,  'type' => 'debit',  'amount' => $r->expected_minor],
                ['account' => $treasury, 'type' => 'credit', 'amount' => $r->netMinor()],
            ];
            if ($r->fee_minor > 0) {
                $this->ledger->openAccount('FEE_INCOME', AccountType::FeeIncome);
                $legs[] = ['account' => 'FEE_INCOME', 'type' => 'credit', 'amount' => $r->fee_minor];
            }

            $reference = Reference::next('RND', Reference::RND);
            $txn = $this->ledger->post(
                legs:        $legs,
                reference:   $this->ledger->nextReference(),
                description: "Cagnotte tontine {$reference} — tour {$r->round_number}",
                createdById: $actor->id,
                metadata:    [
                    'rotation_round_id' => $r->id, 'payout_attempt_id' => $attempt->id,
                    'campaign_id' => $campaign->id, 'membership_id' => $r->beneficiary_membership_id,
                ],
            );

            $attempt->update([
                'status' => Attempt::SUCCEEDED, 'external_reference' => $externalReference,
                'completed_by' => $actor->id, 'completed_at' => now(), 'ledger_transaction_id' => $txn->id,
            ]);
            $r->update([
                'status' => S::Completed, 'reference' => $reference, 'distributed_minor' => $r->netMinor(),
                'ledger_transaction_id' => $txn->id, 'completed_at' => now(),
            ]);

            return $r->fresh(['beneficiary', 'campaign']);
        });

        $this->tracking->refreshStatuses($completed->campaign);
        $this->notifyBeneficiary($completed, 'Cagnotte versée',
            "Tontine « {$completed->campaign->name} » : votre cagnotte ({$this->money($completed->distributed_minor)}) a été versée. "
            . 'Vos cotisations restent dues jusqu\'à la fin de la tontine.');

        return $completed;
    }

    public function fail(RotationRound $round, string $reason, User $actor): RotationRound
    {
        $failed = DB::transaction(function () use ($round, $reason, $actor) {
            $r = RotationRound::whereKey($round->id)->lockForUpdate()->firstOrFail();
            if (! $r->status->canTransitionTo(S::Failed)) {
                throw $this->transitionError($r, 'déclarer en échec');
            }

            Attempt::where('rotation_round_id', $r->id)->where('status', Attempt::PROCESSING)->lockForUpdate()->firstOrFail()
                ->update(['status' => Attempt::FAILED, 'failure_reason' => $reason, 'completed_by' => $actor->id, 'completed_at' => now()]);
            $r->update(['status' => S::Failed]);

            return $r->fresh(['beneficiary', 'campaign']);
        });

        $this->notifyBeneficiary($failed, 'Échec du versement de votre cagnotte',
            "Le versement de votre cagnotte (tour {$failed->round_number}) n'a pas abouti. Une nouvelle tentative sera effectuée.");

        return $failed;
    }

    // ── Interne ──────────────────────────────────────────────────────────

    /** Caisse disponible pour ce tour : solde − cagnottes des autres tours validés ou en cours. */
    private function availablePool(RotationRound $round): int
    {
        $reserved = (int) RotationRound::where('campaign_id', $round->campaign_id)
            ->where('id', '!=', $round->id)
            ->whereIn('status', [S::Approved->value, S::Processing->value, S::Failed->value])
            ->sum('expected_minor');

        // Remboursements de départ / reliquats en cours de versement (lot 8) : déjà engagés.
        $inFlight = (int) Attempt::where('campaign_id', $round->campaign_id)
            ->whereIn('kind', [Attempt::KIND_DEPARTURE, Attempt::KIND_SURPLUS])
            ->where('status', Attempt::PROCESSING)
            ->sum('amount_minor');

        return $this->tracking->poolBalance($round->campaign_id) - $reserved - $inFlight;
    }

    /** Blocages durs : jamais contournables. @return string[] */
    private function hardBlockers(RotationRound $round, int $available): array
    {
        $round->loadMissing('beneficiary', 'campaign');
        $blockers = [];
        if ($round->campaign->status !== CampaignStatus::Active) {
            $blockers[] = 'La tontine n\'est pas en cours.';
        }
        if (! $round->beneficiary || $round->beneficiary->status === MembershipStatus::Closed) {
            $blockers[] = 'Ce tour n\'a pas de bénéficiaire.';
        }
        if ($round->scheduled_date->toDateString() > BusinessTime::todayDate()) {
            $blockers[] = 'La date du tour n\'est pas atteinte (' . $round->scheduled_date->format('d/m/Y') . ').';
        }
        if ($available < $round->expected_minor) {
            $blockers[] = "Caisse insuffisante : {$available} XOF disponibles pour une cagnotte de {$round->expected_minor} XOF.";
        }

        return $blockers;
    }

    private function lockCampaign(string $campaignId): Campaign
    {
        return Campaign::whereKey($campaignId)->lockForUpdate()->firstOrFail();
    }

    private function transitionError(RotationRound $r, string $action): TontineRuleException
    {
        return new TontineRuleException("Impossible de {$action} ce tour : il est « {$r->status->label()} ».", 'invalid_round_transition', 422);
    }

    private function poolShortage(int $available, int $needed): TontineRuleException
    {
        return new TontineRuleException("Caisse insuffisante : {$available} XOF disponibles pour {$needed} XOF.", 'rotation_pool_insufficient', 422);
    }

    private function notifyBeneficiary(RotationRound $round, string $title, string $body): void
    {
        $this->notifications->notify($round->beneficiary?->user_id, 'rotation.round_' . $round->status->value, $title, $body, '/campagnes');
    }

    private function money(int $minor): string
    {
        return number_format($minor, 0, ',', ' ') . ' FCFA';
    }
}
