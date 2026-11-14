<?php

namespace App\Modules\Tontine\Application;

use App\Shared\BusinessTime;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Ledger\Application\LedgerService;
use App\Modules\Audit\Application\AuditService;
use App\Modules\Ledger\Domain\Enums\AccountType;
use App\Modules\Ledger\Domain\ValueObjects\TreasuryAccount;
use App\Modules\Tontine\Domain\Enums\CampaignStatus;
use App\Modules\Tontine\Domain\Exceptions\TontineRuleException;
use App\Modules\Tontine\Infrastructure\Models\Campaign;
use App\Modules\Notifications\Application\NotificationService;
use App\Modules\Tontine\Domain\Enums\EarlyWithdrawalStatus as Status;
use App\Modules\Tontine\Domain\Enums\MembershipStatus;
use App\Modules\Tontine\Domain\Exceptions\InvalidWithdrawalTransitionException;
use App\Modules\Tontine\Domain\Exceptions\MakerCheckerViolationException;
use App\Modules\Tontine\Domain\Exceptions\MembershipNotActiveException;
use App\Modules\Tontine\Domain\Exceptions\WithdrawalExceedsAvailableException;
use App\Modules\Tontine\Infrastructure\Models\EarlyWithdrawal;
use App\Modules\Tontine\Infrastructure\Models\EarlyWithdrawalPayoutAttempt as Attempt;
use App\Modules\Tontine\Infrastructure\Models\Membership;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Retraits anticipés d'épargne (R-WDR-01 → R-WDR-09).
 *
 * Instruction :  request → startReview → requestInfo ⇄ respond → approve | reject ; cancel
 * Versement   :  initiatePayout (tentative « processing », aucune écriture)
 *                → confirmPayout (UNE écriture ledger DR MEMBER_SAVINGS / CR CASH_BOX|MOMO_FLOAT)
 *                | failPayout (aucune écriture) → nouvelle tentative possible
 *
 * Plafond (R-SAV-01, SavingsAvailabilityService) : solde MEMBER_SAVINGS − retraits engagés
 * − avances actives − demandes d'avance en cours. Les prêts communautaires sont affichés, non déduits.
 * Garde-fous : campagne active autorisant les retraits (R-WDR-08), adhésion active.
 * Ordre des verrous : campagne (partagé) → adhésion → retrait, le même que la clôture.
 * Chaque transition verrouille la ligne (lockForUpdate) : ni double approbation, ni double versement.
 */
class EarlyWithdrawalService
{
    /** Frais de retrait : aucun (décision MVP). Centralisé pour l'affichage du net. */
    public const FEES_MINOR = 0;

    /** Motif posé sur les retraits rejetés automatiquement à la clôture (R-CAMP-15). */
    public const CLOSURE_REASON = 'campaign_closed';

    public function __construct(
        private readonly LedgerService              $ledger,
        private readonly NotificationService        $notifications,
        private readonly SavingsAvailabilityService $availability,
        private readonly AuditService               $audit,
    ) {}

    /**
     * Détail du calcul du montant retirable — seule source de vérité (le frontend ne fait qu'afficher).
     */
    public function breakdown(Membership $membership, ?string $excludeId = null): array
    {
        return $this->availability->breakdown($membership, $excludeId);
    }

    public function available(Membership $membership, ?string $excludeId = null): array
    {
        return $this->breakdown($membership, $excludeId);
    }

    public function request(
        Membership $membership,
        int $amountMinor,
        ?string $reason,
        string $payoutChannel,
        ?string $payoutPhone,
        User $requester,
        string $via,
        string $idempotencyKey,
        ?string $payoutNetwork = null,
    ): EarlyWithdrawal {
        // R-PAY-02 appliqué aux retraits : une même clé ne crée jamais deux demandes
        $existing = EarlyWithdrawal::where('idempotency_key', $idempotencyKey)->first();
        if ($existing) {
            if ($existing->membership_id !== $membership->id || $existing->requested_by !== $requester->id) {
                throw ValidationException::withMessages(['idempotency_key' => 'Clé de requête déjà utilisée.']);
            }
            return $existing;
        }

        $withdrawal = DB::transaction(function () use ($membership, $amountMinor, $reason, $payoutChannel, $payoutPhone, $requester, $via, $idempotencyKey, $payoutNetwork) {
            // Verrous : campagne (partagé, bloque une clôture concurrente) puis adhésion
            // (sérialise les demandes concurrentes sur le même plafond).
            $membership = $this->lockMembershipForOperation($membership->id);

            if ($membership->status !== MembershipStatus::Active) {
                throw new MembershipNotActiveException();
            }
            if (! $membership->campaign->allow_early_withdrawals) {
                throw TontineRuleException::earlyWithdrawalsDisabled();
            }

            $available = $this->breakdown($membership)['available_minor'];
            if ($amountMinor > $available) {
                throw new WithdrawalExceedsAvailableException($amountMinor, $available);
            }

            return EarlyWithdrawal::create([
                'reference'       => $this->nextReference(),
                'idempotency_key' => $idempotencyKey,
                'membership_id'   => $membership->id,
                'user_id'         => $membership->user_id,
                'amount_minor'    => $amountMinor,
                'reason'          => $reason,
                'payout_channel'  => $payoutChannel,
                'payout_phone'    => $payoutChannel === 'cash' ? null : $payoutPhone,
                'payout_network'  => $payoutChannel === 'cash' ? null : $payoutNetwork,
                'status'          => Status::Pending,
                'requested_via'   => $via,
                'requested_by'    => $requester->id,
            ]);
        });

        $this->notify($withdrawal, 'Demande de retrait reçue',
            "Votre demande {$withdrawal->reference} de {$this->money($withdrawal->amount_minor)} a bien été reçue. Elle va être examinée.");

        return $withdrawal;
    }

    public function startReview(EarlyWithdrawal $withdrawal, User $actor): EarlyWithdrawal
    {
        return $this->transition($withdrawal, Status::UnderReview, function (EarlyWithdrawal $w) use ($actor) {
            $w->update(['status' => Status::UnderReview, 'assigned_to' => $actor->id, 'reviewed_at' => now()]);
            $this->notify($w, 'Demande en cours d\'analyse', "Votre demande {$w->reference} est en cours d'analyse.");
        });
    }

    public function requestInfo(EarlyWithdrawal $withdrawal, string $message, User $actor): EarlyWithdrawal
    {
        return $this->transition($withdrawal, Status::InfoRequested, function (EarlyWithdrawal $w) use ($message, $actor) {
            $w->update([
                'status'               => Status::InfoRequested,
                'assigned_to'          => $w->assigned_to ?? $actor->id,
                'info_request_message' => $message,
                'info_requested_at'    => now(),
                'member_response'      => null,
                'responded_at'         => null,
            ]);
            $this->notify($w, 'Informations complémentaires requises', "Demande {$w->reference} : {$message}");
        });
    }

    /** Réponse du membre à une demande d'informations : la demande repart en analyse. */
    public function respond(EarlyWithdrawal $withdrawal, string $response): EarlyWithdrawal
    {
        return $this->transition($withdrawal, Status::UnderReview, function (EarlyWithdrawal $w) use ($response) {
            // Seule une demande d'informations appelle une réponse (pas une demande encore en attente).
            if ($w->status !== Status::InfoRequested) {
                throw new InvalidWithdrawalTransitionException($w->status->value, Status::UnderReview->value);
            }
            $w->update(['status' => Status::UnderReview, 'member_response' => $response, 'responded_at' => now()]);
            if ($w->assigned_to) {
                $this->notifications->notify($w->assigned_to, 'early_withdrawal.responded',
                    'Réponse du membre reçue', "Le membre a répondu sur le retrait {$w->reference}.", "/withdrawals/{$w->id}");
            }
        });
    }

    public function approve(EarlyWithdrawal $withdrawal, User $actor): EarlyWithdrawal
    {
        // Verrous dans l'ordre de la clôture (campagne → adhésion → retrait) : pas d'interblocage.
        return DB::transaction(function () use ($withdrawal, $actor) {
            $membership = $this->lockMembershipForOperation($withdrawal->membership_id);

            return $this->transition($withdrawal, Status::Approved, function (EarlyWithdrawal $w) use ($actor, $membership) {
                // Le disponible a pu baisser depuis la demande (avance accordée, autre retrait…).
                $this->assertAvailable($w, $membership);

                $w->update([
                    'status'      => Status::Approved,
                    'decided_by'  => $actor->id,
                    'decided_at'  => now(),
                    'assigned_to' => $w->assigned_to ?? $actor->id,
                ]);
                $this->notify($w, 'Demande de retrait approuvée',
                    "Votre retrait {$w->reference} de {$this->money($w->amount_minor)} est approuvé. Le versement sera effectué prochainement.");
            });
        });
    }

    public function reject(EarlyWithdrawal $withdrawal, string $reason, User $actor): EarlyWithdrawal
    {
        return $this->transition($withdrawal, Status::Rejected, function (EarlyWithdrawal $w) use ($reason, $actor) {
            $w->update([
                'status'          => Status::Rejected,
                'decided_by'      => $actor->id,
                'decided_at'      => now(),
                'rejected_reason' => $reason,
            ]);
            $this->notify($w, 'Demande de retrait refusée', "Votre retrait {$w->reference} a été refusé. Motif : {$reason}");
        });
    }

    public function cancel(EarlyWithdrawal $withdrawal): EarlyWithdrawal
    {
        return $this->transition($withdrawal, Status::Cancelled, function (EarlyWithdrawal $w) {
            $w->update(['status' => Status::Cancelled, 'cancelled_at' => now()]);
        });
    }

    /**
     * Lance une tentative de versement (manuel : remise d'espèces ou transfert depuis le compte agrégateur, DA-13/DA-20).
     * Aucune écriture comptable tant que le versement n'est pas confirmé.
     */
    public function initiatePayout(EarlyWithdrawal $withdrawal, string $channel, ?string $phone, string $idempotencyKey, User $actor, ?string $network = null): Attempt
    {
        // Idempotence : rejouer la même requête renvoie la même tentative
        $existing = Attempt::where('idempotency_key', $idempotencyKey)->first();
        if ($existing) {
            if ($existing->early_withdrawal_id !== $withdrawal->id) {
                throw ValidationException::withMessages(['idempotency_key' => 'Clé de requête déjà utilisée.']);
            }
            return $existing;
        }

        $attempt = DB::transaction(function () use ($withdrawal, $channel, $phone, $idempotencyKey, $actor, $network) {
            $membership = $this->lockMembershipForOperation($withdrawal->membership_id);
            $w = EarlyWithdrawal::whereKey($withdrawal->id)->lockForUpdate()->firstOrFail();
            $this->assertCanTransition($w, Status::PayoutProcessing);

            // Maker/checker : l'approbateur ne verse pas
            if ($w->decided_by === $actor->id) {
                throw new MakerCheckerViolationException();
            }

            $this->assertAvailable($w, $membership);

            $attempt = Attempt::create([
                'early_withdrawal_id' => $w->id,
                'attempt_no'          => (int) $w->attempts()->max('attempt_no') + 1,
                'idempotency_key'     => $idempotencyKey,
                'channel'             => $channel,
                'phone'               => $channel === 'cash' ? null : $phone,
                'network'             => $channel === 'cash' ? null : $network,
                'status'              => Attempt::PROCESSING,
                'initiated_by'        => $actor->id,
                'initiated_at'        => now(),
            ]);

            $w->update(['status' => Status::PayoutProcessing]);

            return $attempt;
        });

        $this->notify($withdrawal->fresh(), 'Versement en cours', "Le versement de votre retrait {$withdrawal->reference} est en cours.");

        return $attempt;
    }

    /**
     * Confirme le versement effectif : la seule étape qui écrit au grand livre (une fois par retrait).
     */
    public function confirmPayout(EarlyWithdrawal $withdrawal, ?string $externalReference, User $actor): EarlyWithdrawal
    {
        $confirmed = DB::transaction(function () use ($withdrawal, $externalReference, $actor) {
            $w = EarlyWithdrawal::whereKey($withdrawal->id)->lockForUpdate()->firstOrFail();
            $this->assertCanTransition($w, Status::Disbursed);

            $attempt    = $this->processingAttempt($w);
            $membership = Membership::whereKey($w->membership_id)->lockForUpdate()->firstOrFail();

            // L'argent est déjà remis : le versement reste confirmable même après la clôture
            // (le règlement du payout attend cette confirmation). La réservation a été
            // vérifiée au lancement ; on garantit seulement que l'épargne ne devient pas négative.
            $savingsKey = "MEMBER_SAVINGS:{$membership->id}";
            $balance    = $this->ledger->balances([$savingsKey])[$savingsKey];
            if ($w->amount_minor > $balance) {
                throw new WithdrawalExceedsAvailableException($w->amount_minor, $balance);
            }

            $floatKey = TreasuryAccount::key($attempt->channel);
            $this->ledger->openAccount(key: $savingsKey, type: AccountType::MemberSavings, ownerId: $membership->user_id);
            $this->ledger->openAccount(key: $floatKey, type: TreasuryAccount::type($attempt->channel));

            $txn = $this->ledger->post(
                legs: [
                    ['account' => $savingsKey, 'type' => 'debit',  'amount' => $w->amount_minor],
                    ['account' => $floatKey,   'type' => 'credit', 'amount' => $w->amount_minor],
                ],
                reference:   $this->ledger->nextReference(),
                description: "Retrait anticipé {$w->reference}",
                createdById: $actor->id,
                metadata:    [
                    'early_withdrawal_id' => $w->id,
                    'payout_attempt_id'   => $attempt->id,
                    'membership_id'       => $membership->id,
                    'campaign_id'         => $membership->campaign_id,
                ],
            );

            $attempt->update([
                'status'                => Attempt::SUCCEEDED,
                'external_reference'    => $externalReference,
                'completed_by'          => $actor->id,
                'completed_at'          => now(),
                'ledger_transaction_id' => $txn->id,
            ]);

            $w->update([
                'status'                => Status::Disbursed,
                'disbursed_by'          => $actor->id,
                'disbursed_at'          => now(),
                'disbursed_channel'     => $attempt->channel,
                'disbursed_phone'       => $attempt->phone,
                'disbursed_network'     => $attempt->network,
                'ledger_transaction_id' => $txn->id,
            ]);

            return $w->fresh();
        });

        $this->notify($confirmed, 'Versement confirmé',
            "Votre retrait {$confirmed->reference} de {$this->money($confirmed->amount_minor)} a été versé.");

        return $confirmed;
    }

    /** Échec du versement : aucune écriture comptable ; une nouvelle tentative reste possible. */
    public function failPayout(EarlyWithdrawal $withdrawal, string $reason, User $actor): EarlyWithdrawal
    {
        $failed = DB::transaction(function () use ($withdrawal, $reason, $actor) {
            $w = EarlyWithdrawal::whereKey($withdrawal->id)->lockForUpdate()->firstOrFail();
            $this->assertCanTransition($w, Status::PayoutFailed);

            $this->processingAttempt($w)->update([
                'status'         => Attempt::FAILED,
                'failure_reason' => $reason,
                'completed_by'   => $actor->id,
                'completed_at'   => now(),
            ]);
            $w->update(['status' => Status::PayoutFailed]);

            // Campagne clôturée entre-temps : pas de nouvelle tentative, le montant
            // rejoint le règlement de clôture (D2, R-CAMP-15).
            $campaignStatus = Membership::whereKey($w->membership_id)->with('campaign')->first()?->campaign?->status;
            if ($campaignStatus !== CampaignStatus::Active) {
                $this->rejectForClosure($w, $actor);
            }

            return $w->fresh();
        });

        if ($failed->status === Status::PayoutFailed) {
            $this->notify($failed, 'Échec du versement',
                "Le versement de votre retrait {$failed->reference} n'a pas abouti. Une nouvelle tentative sera effectuée.");
        }

        return $failed;
    }

    /**
     * R-CAMP-15 (D2) : à la clôture, les retraits non versés sont rejetés (motif
     * `campaign_closed`), SANS notification ; leur montant reste dans l'épargne et
     * rejoint le règlement final. Les versements en cours sont conservés : le règlement
     * de l'adhésion attend leur confirmation ou leur échec.
     *
     * Appelé dans la transaction de clôture (campagne et adhésions déjà verrouillées).
     *
     * @return int nombre de retraits rejetés
     */
    public function rejectOpenForCampaignClosure(Campaign $campaign, User $actor): int
    {
        $withdrawals = EarlyWithdrawal::whereIn('membership_id', Membership::where('campaign_id', $campaign->id)->select('id'))
            ->whereIn('status', Status::rejectedOnClosure())
            ->lockForUpdate()
            ->get();

        foreach ($withdrawals as $w) {
            $this->rejectForClosure($w, $actor);
        }

        return $withdrawals->count();
    }

    private function rejectForClosure(EarlyWithdrawal $w, User $actor): void
    {
        $from = $w->status->value;
        $w->update([
            'status'          => Status::Rejected,
            'decided_by'      => $actor->id,
            'decided_at'      => now(),
            'rejected_reason' => self::CLOSURE_REASON,
        ]);

        $this->audit->log(
            action:     'early_withdrawal.reject',
            entityType: 'early_withdrawal',
            entityId:   $w->id,
            oldValues:  ['status' => $from],
            newValues:  ['status' => Status::Rejected->value, 'reason' => self::CLOSURE_REASON, 'automatic' => true],
            userId:     $actor->id,
            userName:   $actor->full_name,
        );
    }

    /** Statistiques sur données réelles (montants et nombres), éventuellement bornées sur une période. */
    public function stats(?string $from, ?string $to): array
    {
        $rows = EarlyWithdrawal::query()
            ->when($from, fn ($q) => $q->where('created_at', '>=', BusinessTime::dayBoundsUtc($from)[0]))
            ->when($to, fn ($q) => $q->where('created_at', '<=', BusinessTime::dayBoundsUtc($to)[1]))
            ->selectRaw('status, COUNT(*) as count, COALESCE(SUM(amount_minor), 0) as total')
            ->groupBy('status')
            ->get()
            ->keyBy(fn ($r) => $r->status instanceof Status ? $r->status->value : (string) $r->status);

        $sum = fn (array $statuses) => [
            'count'        => (int) collect($statuses)->sum(fn ($s) => $rows[$s]->count ?? 0),
            'amount_minor' => (int) collect($statuses)->sum(fn ($s) => $rows[$s]->total ?? 0),
        ];

        return [
            'requested' => $sum(array_column(Status::cases(), 'value')),
            'pending'   => $sum(Status::awaitingDecision()),
            'approved'  => $sum([Status::Approved->value, Status::PayoutProcessing->value, Status::PayoutFailed->value, Status::Disbursed->value]),
            'disbursed' => $sum([Status::Disbursed->value]),
            'rejected'  => $sum([Status::Rejected->value]),
            'failed'    => $sum([Status::PayoutFailed->value]),
        ];
    }

    // ── Interne ────────────────────────────────────────────────────────────

    /**
     * Verrouille la campagne (partagé : bloque une clôture concurrente sans bloquer les autres
     * membres) puis l'adhésion (exclusif), et exige une campagne active.
     */
    private function lockMembershipForOperation(string $membershipId): Membership
    {
        $campaignId = Membership::whereKey($membershipId)->value('campaign_id');
        $campaign   = Campaign::whereKey($campaignId)->sharedLock()->firstOrFail();

        // Tontine rotative : caisse commune, pas d'épargne individuelle à retirer.
        if ($campaign->isRotating()) {
            throw TontineRuleException::notForRotatingCampaign();
        }
        if ($campaign->status !== CampaignStatus::Active) {
            throw TontineRuleException::campaignNotActive();
        }

        $membership = Membership::whereKey($membershipId)->lockForUpdate()->firstOrFail();
        $membership->setRelation('campaign', $campaign);

        return $membership;
    }

    /** Transition verrouillée : relit la ligne sous verrou puis applique $apply si autorisée. */
    private function transition(EarlyWithdrawal $withdrawal, Status $target, callable $apply): EarlyWithdrawal
    {
        return DB::transaction(function () use ($withdrawal, $target, $apply) {
            $w = EarlyWithdrawal::whereKey($withdrawal->id)->lockForUpdate()->firstOrFail();
            $this->assertCanTransition($w, $target);
            $apply($w);

            return $w->fresh();
        });
    }

    private function assertCanTransition(EarlyWithdrawal $w, Status $target): void
    {
        if (! $w->status->canTransitionTo($target)) {
            throw new InvalidWithdrawalTransitionException($w->status->value, $target->value);
        }
    }

    /** Re-vérification du plafond (le solde a pu bouger depuis la demande). */
    private function assertAvailable(EarlyWithdrawal $w, Membership $membership): void
    {
        $available = $this->breakdown($membership, $w->id)['available_minor'];
        if ($w->amount_minor > $available) {
            throw new WithdrawalExceedsAvailableException($w->amount_minor, $available);
        }
    }

    private function processingAttempt(EarlyWithdrawal $w): Attempt
    {
        return Attempt::where('early_withdrawal_id', $w->id)
            ->where('status', Attempt::PROCESSING)
            ->lockForUpdate()
            ->firstOrFail();
    }

    /** Notification in-app au membre (canal existant ; SMS quand B-02 sera livré). */
    private function notify(EarlyWithdrawal $w, string $title, string $body): void
    {
        $this->notifications->notify($w->user_id, 'early_withdrawal.' . $w->status->value, $title, $body, '/retraits');
    }

    private function money(int $minor): string
    {
        return number_format($minor, 0, ',', ' ') . ' FCFA';
    }

    private function nextReference(): string
    {
        return \App\Shared\Reference::next('WDR', \App\Shared\Reference::WDR);
    }
}
