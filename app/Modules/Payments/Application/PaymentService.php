<?php

namespace App\Modules\Payments\Application;

use App\Modules\Audit\Application\CashRegisterService;
use App\Modules\Ledger\Application\LedgerService;
use App\Modules\Ledger\Domain\Enums\AccountType;
use App\Modules\Payments\Domain\Enums\PaymentChannel;
use App\Modules\Payments\Domain\Enums\PaymentStatus;
use App\Modules\Payments\Domain\Exceptions\AtypicalAmountException;
use App\Modules\Payments\Domain\Exceptions\CashRegisterClosedException;
use App\Modules\Payments\Domain\Exceptions\DuplicateCashPaymentException;
use App\Modules\Payments\Infrastructure\Models\InstallmentPayment;
use App\Modules\Payments\Infrastructure\Models\Payment;
use App\Modules\Tontine\Domain\Enums\InstallmentStatus;
use App\Modules\Tontine\Application\MembershipService;
use App\Modules\Tontine\Domain\Enums\MembershipStatus;
use App\Modules\Tontine\Infrastructure\Models\Installment;
use App\Modules\Tontine\Infrastructure\Models\Membership;
use App\Shared\BusinessTime;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PaymentService
{
    public function __construct(
        private readonly LedgerService        $ledger,
        private readonly ?CashRegisterService $cashRegister = null,
    ) {}

    /**
     * Enregistre et confirme immédiatement un paiement cash.
     * Génère les écritures ledger et affecte les échéances.
     *
     * R-PAY-08 : lève DuplicateCashPaymentException (409) si un cash confirmé
     * existe déjà aujourd'hui pour cette membership et que $force n'est pas
     * activé. R-PAY-10 : les MoMo confirmés le même jour ne comptent pas.
     *
     * R-PAY-05 : lève AtypicalAmountException (422) si le montant n'est pas un
     * multiple entier de `committed_amount_minor` et que $confirmAtypical n'est
     * pas activé. Les deux contournements sont indépendants (`force` ne vaut pas
     * confirmation d'un montant atypique, et inversement).
     *
     * R-PAY-06 : lève CashRegisterClosedException (409 `cash_register_closed`) si le
     * caissier a clôturé sa caisse pour le jour métier.
     */
    public function recordCash(
        Membership $membership,
        int        $amountMinor,
        string     $cashierId,
        ?string    $notes = null,
        bool       $force = false,
        bool       $confirmAtypical = false,
    ): Payment {
        if ($membership->status !== MembershipStatus::Active) {
            throw new \App\Modules\Tontine\Domain\Exceptions\TontineRuleException('La membership doit être active pour enregistrer un paiement.', 'membership_not_active', 422);
        }
        $this->assertCampaignAcceptsContributions($membership);

        // R-PAY-06 : plus d'encaissement cash après la clôture de la caisse du trésorier
        // pour le jour métier (API publique du module Audit, DA-09). Ni `force` ni
        // `confirm_atypical` ne lèvent ce blocage.
        $today = BusinessTime::todayDate();
        if (($this->cashRegister ?? app(CashRegisterService::class))->isClosed($cashierId, $today)) {
            throw new CashRegisterClosedException(BusinessTime::parse($today)->format('d/m/Y'));
        }

        // R-PAY-05 : montant atypique (non multiple entier de l'engagement) —
        // contrôlé avant le doublon : une faute de frappe se corrige avant tout.
        if (! $confirmAtypical && $this->isAtypicalAmount($membership, $amountMinor)) {
            throw new AtypicalAmountException((int) $membership->committed_amount_minor, $amountMinor);
        }

        if (! $force) {
            // R-PAY-08 : « aujourd'hui » = jour local du fuseau métier, converti
            // en bornes UTC (les created_at sont stockés en UTC).
            [$dayStart, $dayEnd] = BusinessTime::dayBoundsUtc();

            $existing = Payment::where('membership_id', $membership->id)
                ->where('channel', PaymentChannel::Cash->value)
                ->where('status', PaymentStatus::Confirmed->value)
                ->whereBetween('created_at', [$dayStart, $dayEnd])
                ->orderByDesc('created_at')
                ->first();
            if ($existing) {
                throw new DuplicateCashPaymentException($existing);
            }
        }

        return DB::transaction(function () use ($membership, $amountMinor, $cashierId, $notes) {
            $payment = Payment::create([
                'reference'       => $this->nextReference(),
                'idempotency_key' => Str::uuid()->toString(),
                'membership_id'   => $membership->id,
                'channel'         => PaymentChannel::Cash->value,
                'status'          => PaymentStatus::Confirmed->value,
                'amount_minor'    => $amountMinor,
                'notes'           => $notes,
                'confirmed_at'    => now(),
                'created_by'      => $cashierId,
            ]);

            $this->bookContribution($payment, $membership, 'cash');

            return $payment;
        });
    }

    /**
     * R-FEE-02 : encaissement en espèces des frais d'adhésion par le personnel (D3).
     *
     * Accepté tant que l'adhésion n'est pas clôturée, dans la limite du reste dû.
     * Écriture DR CASH_BOX / CR FEE_INCOME : jamais d'épargne, jamais d'affectation aux
     * échéances, hors contrôle de doublon cash R-PAY-08 (un frais n'est pas une cotisation).
     */
    public function recordMembershipFee(Membership $membership, int $amountMinor, string $cashierId, ?string $notes = null): Payment
    {
        $today = BusinessTime::todayDate();
        if (($this->cashRegister ?? app(CashRegisterService::class))->isClosed($cashierId, $today)) {
            throw new CashRegisterClosedException(BusinessTime::parse($today)->format('d/m/Y'));
        }

        return DB::transaction(function () use ($membership, $amountMinor, $cashierId, $notes) {
            // Verrou adhésion : deux encaissements simultanés ne dépassent pas le reste dû.
            $membership = Membership::whereKey($membership->id)->lockForUpdate()->firstOrFail();

            if (in_array($membership->status, [MembershipStatus::Closed], true)) {
                throw new \App\Modules\Tontine\Domain\Exceptions\TontineRuleException(
                    'Cette adhésion est clôturée.', 'membership_closed', 422);
            }

            $remaining = MembershipService::feeStatus($membership)['remaining_minor'];
            if ($amountMinor > $remaining) {
                throw new \App\Modules\Tontine\Domain\Exceptions\TontineRuleException(
                    "Montant ({$amountMinor} XOF) supérieur au reste dû des frais d'adhésion ({$remaining} XOF).",
                    'membership_fee_exceeds_due', 422);
            }

            $payment = Payment::create([
                'reference'       => $this->nextReference(),
                'idempotency_key' => Str::uuid()->toString(),
                'membership_id'   => $membership->id,
                'channel'         => PaymentChannel::Cash->value,
                'purpose'         => 'membership_fee',
                'status'          => PaymentStatus::Confirmed->value,
                'amount_minor'    => $amountMinor,
                'notes'           => $notes,
                'confirmed_at'    => now(),
                'created_by'      => $cashierId,
            ]);

            $this->ledger->openAccount('CASH_BOX', AccountType::CashBox, null, 'Caisse physique');
            $this->ledger->openAccount('FEE_INCOME', AccountType::FeeIncome, null, 'Frais d\'adhésion');
            $this->ledger->post(
                legs: [
                    ['account' => 'CASH_BOX',   'type' => 'debit',  'amount' => $amountMinor],
                    ['account' => 'FEE_INCOME', 'type' => 'credit', 'amount' => $amountMinor],
                ],
                reference:   $this->ledger->nextReference(),
                description: "Frais d'adhésion — {$payment->reference}",
                createdById: $cashierId,
                metadata:    ['payment_id' => $payment->id, 'channel' => 'cash', 'purpose' => 'membership_fee', 'membership_id' => $membership->id],
            );

            return $payment;
        });
    }

    /** Plus de cotisation sur une campagne clôturée (le règlement de clôture est en cours). */
    private function assertCampaignAcceptsContributions(Membership $membership): void
    {
        $status = $membership->campaign()->value('status');
        if ($status === 'closed') {
            throw \App\Modules\Tontine\Domain\Exceptions\TontineRuleException::campaignNotActive();
        }
    }

    /**
     * Initie un paiement MoMo (simulation en dev via LOG).
     * En prod : appel API PSP → retourne une référence d'initiation.
     */
    public function initiateMomo(
        Membership $membership,
        int        $amountMinor,
        PaymentChannel $channel,
        string     $phone,
        string     $initiatedById,
    ): Payment {
        if ($membership->status !== MembershipStatus::Active) {
            throw new \App\Modules\Tontine\Domain\Exceptions\TontineRuleException('La membership doit être active pour initier un paiement.', 'membership_not_active', 422);
        }
        $this->assertCampaignAcceptsContributions($membership);

        $payment = DB::transaction(fn () => Payment::create([
            'reference'       => $this->nextReference(),
            'idempotency_key' => Str::uuid()->toString(),
            'membership_id'   => $membership->id,
            'channel'         => $channel->value,
            'status'          => PaymentStatus::Pending->value,
            'amount_minor'    => $amountMinor,
            'phone'           => $phone,
            'created_by'      => $initiatedById,
        ]));

        // En dev, on simule la confirmation immédiate (pas de vrai PSP)
        Log::info("MoMo [{$channel->value}] initié {$phone}: {$amountMinor} XOF — réf: {$payment->reference}");

        return $payment;
    }

    /**
     * Confirmation manuelle par un admin (dev / paiements bloqués).
     */
    public function confirmManual(Payment $payment, string $adminId): Payment
    {
        return DB::transaction(function () use ($payment, $adminId) {
            // Relecture sous verrou : une confirmation manuelle concurrente d'un webhook n'écrit qu'une fois.
            $payment = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if ($payment->status !== PaymentStatus::Pending) {
                throw new \App\Modules\Tontine\Domain\Exceptions\TontineRuleException('Ce paiement n\'est pas en attente de confirmation.', 'payment_not_pending', 422);
            }

            $payment->update([
                'status'       => PaymentStatus::Confirmed->value,
                'confirmed_at' => now(),
                'notes'        => trim(($payment->notes ?? '') . ' | Confirmé manuellement', ' |'),
            ]);

            $membership = $payment->membership()->with(['campaign'])->first();

            $this->bookContribution($payment, $membership, $payment->channel->value);

            return $payment->fresh();
        });
    }

    /**
     * Annulation d'un paiement en attente.
     */
    public function cancelPayment(Payment $payment): Payment
    {
        if ($payment->status !== PaymentStatus::Pending) {
            throw new \App\Modules\Tontine\Domain\Exceptions\TontineRuleException('Seul un paiement en attente peut être annulé.', 'payment_not_pending', 422);
        }

        $payment->update([
            'status'    => PaymentStatus::Cancelled->value,
            'failed_at' => now(),
        ]);

        return $payment->fresh();
    }

    /**
     * Confirme un paiement MoMo depuis un webhook PSP.
     * Idempotent : si déjà confirmé, retourne le paiement sans erreur.
     */
    public function confirmFromWebhook(
        string $idempotencyKey,
        string $pspReference,
        string $channel,
        ?array $metadata = null,
        ?string $network = null,
    ): Payment {
        return DB::transaction(function () use ($idempotencyKey, $pspReference, $channel, $metadata, $network) {
            // Relecture sous verrou : deux webhooks simultanés ne confirment (et n'écrivent) qu'une fois.
            $payment = Payment::where('idempotency_key', $idempotencyKey)->lockForUpdate()->firstOrFail();

            if ($payment->status === PaymentStatus::Confirmed) {
                return $payment; // idempotent
            }

            $payment->update([
                'status'        => PaymentStatus::Confirmed->value,
                'psp_reference' => $pspReference,
                'confirmed_at'  => now(),
                'network'       => $network ?? $payment->network,
                'metadata'      => array_merge((array) $payment->metadata, $metadata ?? []),
            ]);

            $membership = $payment->membership()->with(['campaign'])->first();
            $this->bookContribution($payment, $membership, $channel);

            return $payment->fresh();
        });
    }

    /**
     * Marque un paiement comme échoué depuis un webhook PSP.
     * Idempotent : si déjà failed/cancelled, retourne le paiement sans erreur.
     * Aucune écriture ledger — un paiement échoué ne crédite rien.
     */
    public function markFailedFromWebhook(
        string  $idempotencyKey,
        string  $pspReference,
        ?string $failureCode = null,
        ?string $failureMessage = null,
    ): Payment {
        $payment = Payment::where('idempotency_key', $idempotencyKey)->firstOrFail();

        if (in_array($payment->status, [PaymentStatus::Failed, PaymentStatus::Cancelled], true)) {
            return $payment;
        }

        $payment->update([
            'status'        => PaymentStatus::Failed->value,
            'psp_reference' => $pspReference,
            'failed_at'     => now(),
            'metadata'      => array_merge((array) $payment->metadata, array_filter([
                'failure_code'    => $failureCode,
                'failure_message' => $failureMessage,
            ])),
        ]);

        return $payment->fresh();
    }

    // -----------------------------------------------------------------------
    // Ledger
    // -----------------------------------------------------------------------

    /**
     * Comptabilise une cotisation confirmée et l'affecte aux échéances.
     *  - Épargne : DR trésorerie / CR MEMBER_SAVINGS (inchangé).
     *  - Tontine rotative : DR trésorerie / CR ROTATING_POOL (part cotisation, et tout surplus non
     *    affecté) + FEE_INCOME (complément D-R5) + PENALTY_INCOME (pénalité D-R10). Jamais d'épargne.
     */
    private function bookContribution(Payment $payment, Membership $membership, string $channel): void
    {
        $campaign = $membership->relationLoaded('campaign') ? $membership->campaign : $membership->campaign()->first();

        if (! $campaign?->isRotating()) {
            $channel === 'cash' ? $this->postLedgerCash($payment, $membership) : $this->postLedgerMomo($payment, $membership, $channel);
            $this->affectInstallments($payment, $membership);
            return;
        }

        $split    = $this->affectInstallments($payment, $membership);
        $pool     = $payment->amount_minor - $split['penalty'] - $split['fee'];
        $poolKey  = "ROTATING_POOL:{$campaign->id}";
        $treasury = \App\Modules\Ledger\Domain\ValueObjects\TreasuryAccount::key($channel);

        $this->ledger->openAccount($treasury, \App\Modules\Ledger\Domain\ValueObjects\TreasuryAccount::type($channel));
        $this->ledger->openAccount($poolKey, AccountType::RotatingPool, null, "Caisse tontine — {$campaign->name}");

        $legs = [['account' => $treasury, 'type' => 'debit', 'amount' => $payment->amount_minor]];
        if ($pool > 0) {
            $legs[] = ['account' => $poolKey, 'type' => 'credit', 'amount' => $pool];
        }
        if ($split['fee'] > 0) {
            $this->ledger->openAccount('FEE_INCOME', AccountType::FeeIncome);
            $legs[] = ['account' => 'FEE_INCOME', 'type' => 'credit', 'amount' => $split['fee']];
        }
        if ($split['penalty'] > 0) {
            $this->ledger->openAccount('PENALTY_INCOME', AccountType::PenaltyIncome);
            $legs[] = ['account' => 'PENALTY_INCOME', 'type' => 'credit', 'amount' => $split['penalty']];
        }

        $this->ledger->post(
            legs:        $legs,
            reference:   $this->ledger->nextReference(),
            description: "Cotisation tontine — {$payment->reference}",
            createdById: $payment->created_by,
            metadata:    ['payment_id' => $payment->id, 'channel' => $channel, 'campaign_id' => $campaign->id, 'membership_id' => $membership->id, ...$split],
        );
    }

    private function postLedgerCash(Payment $payment, Membership $membership): void
    {
        // Ouvre CASH_BOX s'il n'existe pas
        $this->ledger->openAccount('CASH_BOX', AccountType::CashBox, null, 'Caisse physique');

        $savingsKey = "MEMBER_SAVINGS:{$membership->id}";

        // Vérifie que le compte épargne existe
        if (!\App\Modules\Ledger\Infrastructure\Models\LedgerAccount::where('key', $savingsKey)->exists()) {
            $this->ledger->openAccount($savingsKey, AccountType::MemberSavings, $membership->user_id);
        }

        $this->ledger->post(
            legs: [
                ['account' => 'CASH_BOX',    'type' => 'debit',  'amount' => $payment->amount_minor],
                ['account' => $savingsKey,    'type' => 'credit', 'amount' => $payment->amount_minor],
            ],
            reference:   $this->ledger->nextReference(),
            description: "Encaissement cash — {$payment->reference}",
            createdById: $payment->created_by,
            metadata:    ['payment_id' => $payment->id, 'channel' => 'cash'],
        );
    }

    private function postLedgerMomo(Payment $payment, Membership $membership, string $channel): void
    {
        $floatKey   = \App\Modules\Ledger\Domain\ValueObjects\TreasuryAccount::key($channel);
        $savingsKey = "MEMBER_SAVINGS:{$membership->id}";

        $this->ledger->openAccount($floatKey, \App\Modules\Ledger\Domain\ValueObjects\TreasuryAccount::type($channel), null, "Solde " . \App\Modules\Payments\Domain\Enums\PaymentChannel::labelFor($channel));

        if (!\App\Modules\Ledger\Infrastructure\Models\LedgerAccount::where('key', $savingsKey)->exists()) {
            $this->ledger->openAccount($savingsKey, AccountType::MemberSavings, $membership->user_id);
        }

        $this->ledger->post(
            legs: [
                ['account' => $floatKey,   'type' => 'debit',  'amount' => $payment->amount_minor],
                ['account' => $savingsKey, 'type' => 'credit', 'amount' => $payment->amount_minor],
            ],
            reference:   $this->ledger->nextReference(),
            description: "Paiement MoMo via " . \App\Modules\Payments\Domain\Enums\PaymentChannel::labelFor($channel) . " — {$payment->reference}",
            createdById: $payment->created_by,
            metadata:    ['payment_id' => $payment->id, 'channel' => $channel],
        );
    }

    // -----------------------------------------------------------------------
    // Affectation des échéances
    // -----------------------------------------------------------------------

    /**
     * R-SCHED-05 : affecte le paiement aux échéances non réglées, par numéro croissant.
     * Le montant déjà affecté (paiements partiels antérieurs) est déduit : une échéance
     * ne reçoit que son reste dû, et passe `paid` quand le cumul atteint son montant.
     */
    /**
     * @return array{penalty:int, fee:int, contribution:int} ventilation du montant affecté
     */
    private function affectInstallments(Payment $payment, Membership $membership): array
    {
        $remaining = $payment->amount_minor;
        $split     = ['penalty' => 0, 'fee' => 0, 'contribution' => 0];

        $unpaid = Installment::where('membership_id', $membership->id)
            ->whereIn('status', [InstallmentStatus::Pending->value, InstallmentStatus::Late->value])
            ->orderBy('sequence_number')
            ->lockForUpdate()
            ->get();

        $alreadyPaid = InstallmentPayment::whereIn('installment_id', $unpaid->pluck('id'))
            ->groupBy('installment_id')
            ->selectRaw('installment_id, SUM(amount_minor) AS total')
            ->pluck('total', 'installment_id');

        foreach ($unpaid as $installment) {
            if ($remaining <= 0) break;

            $paidBefore = (int) ($alreadyPaid[$installment->id] ?? 0);
            // Total dû = cotisation (+ complément) + pénalité éventuelle (D-R10)
            $due        = $installment->totalDueMinor() - $paidBefore;

            if ($due > 0) {
                $allocated = min($remaining, $due);

                InstallmentPayment::create([
                    'payment_id'     => $payment->id,
                    'installment_id' => $installment->id,
                    'amount_minor'   => $allocated,
                    'created_at'     => now(),
                ]);

                $before = $installment->splitPaid($paidBefore);
                $after  = $installment->splitPaid($paidBefore + $allocated);
                foreach ($split as $part => $_) {
                    $split[$part] += $after[$part] - $before[$part];
                }

                $remaining -= $allocated;
                $due       -= $allocated;
            }

            // Cumul atteint (ou déjà atteint par des partiels antérieurs) : échéance réglée
            if ($due <= 0) {
                $installment->update([
                    'status'  => InstallmentStatus::Paid->value,
                    'paid_at' => now(),
                ]);
            }
        }

        return $split;
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function nextReference(): string
    {
        return \App\Shared\Reference::next('PAY', \App\Shared\Reference::PAY);
    }

    /**
     * R-PAY-05 : un montant est « typique » s'il est un multiple entier (≥ 1)
     * du montant contractuel de l'adhésion. Sans montant contractuel, pas de contrôle.
     */
    public function isAtypicalAmount(Membership $membership, int $amountMinor): bool
    {
        $committed = (int) $membership->committed_amount_minor;

        if ($committed <= 0) {
            return false;
        }

        if ($amountMinor >= $committed && $amountMinor % $committed === 0) {
            return false;
        }

        // Montant qui solde exactement les k premières échéances non réglées
        // (reste dû après paiements partiels) : attendu, donc non atypique.
        $cumulative = 0;
        $unpaid = Installment::where('membership_id', $membership->id)
            ->whereIn('status', [InstallmentStatus::Pending->value, InstallmentStatus::Late->value])
            ->withPaidMinor()
            ->orderBy('sequence_number')
            ->get();

        foreach ($unpaid as $installment) {
            $cumulative += $installment->remainingMinor();
            if ($cumulative === $amountMinor) return false;
            if ($cumulative > $amountMinor) break;
        }

        return true;
    }
}
