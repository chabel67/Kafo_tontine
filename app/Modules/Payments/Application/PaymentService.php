<?php

namespace App\Modules\Payments\Application;

use App\Modules\Ledger\Application\LedgerService;
use App\Modules\Ledger\Domain\Enums\AccountType;
use App\Modules\Payments\Domain\Enums\PaymentChannel;
use App\Modules\Payments\Domain\Enums\PaymentStatus;
use App\Modules\Payments\Domain\Exceptions\DuplicateCashPaymentException;
use App\Modules\Payments\Infrastructure\Models\InstallmentPayment;
use App\Modules\Payments\Infrastructure\Models\Payment;
use App\Modules\Tontine\Domain\Enums\InstallmentStatus;
use App\Modules\Tontine\Domain\Enums\MembershipStatus;
use App\Modules\Tontine\Infrastructure\Models\Installment;
use App\Modules\Tontine\Infrastructure\Models\Membership;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PaymentService
{
    public function __construct(private readonly LedgerService $ledger) {}

    /**
     * Enregistre et confirme immédiatement un paiement cash.
     * Génère les écritures ledger et affecte les échéances.
     *
     * R-PAY-08 : lève DuplicateCashPaymentException (409) si un cash confirmé
     * existe déjà aujourd'hui pour cette membership et que $force n'est pas
     * activé. R-PAY-10 : les MoMo confirmés le même jour ne comptent pas.
     */
    public function recordCash(
        Membership $membership,
        int        $amountMinor,
        string     $cashierId,
        ?string    $notes = null,
        bool       $force = false,
    ): Payment {
        if ($membership->status !== MembershipStatus::Active) {
            throw new \RuntimeException('La membership doit être active pour enregistrer un paiement.', 422);
        }

        if (! $force) {
            $existing = Payment::where('membership_id', $membership->id)
                ->where('channel', PaymentChannel::Cash->value)
                ->where('status', PaymentStatus::Confirmed->value)
                ->whereDate(
                    'created_at',
                    Carbon::today(config('app.timezone', 'Africa/Cotonou')),
                )
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

            $this->postLedgerCash($payment, $membership);
            $this->affectInstallments($payment, $membership);

            return $payment;
        });
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
            throw new \RuntimeException('La membership doit être active pour initier un paiement.', 422);
        }

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
        if ($payment->status !== PaymentStatus::Pending) {
            throw new \RuntimeException('Ce paiement n\'est pas en attente de confirmation.', 422);
        }

        return DB::transaction(function () use ($payment, $adminId) {
            $payment->update([
                'status'       => PaymentStatus::Confirmed->value,
                'confirmed_at' => now(),
                'notes'        => trim(($payment->notes ?? '') . ' | Confirmé manuellement', ' |'),
            ]);

            $membership = $payment->membership()->with(['campaign'])->first();

            if ($payment->channel === PaymentChannel::Cash) {
                $this->postLedgerCash($payment, $membership);
            } else {
                $this->postLedgerMomo($payment, $membership, $payment->channel->value);
            }

            $this->affectInstallments($payment, $membership);

            return $payment->fresh();
        });
    }

    /**
     * Annulation d'un paiement en attente.
     */
    public function cancelPayment(Payment $payment): Payment
    {
        if ($payment->status !== PaymentStatus::Pending) {
            throw new \RuntimeException('Seul un paiement en attente peut être annulé.', 422);
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
    ): Payment {
        $payment = Payment::where('idempotency_key', $idempotencyKey)->firstOrFail();

        if ($payment->status === PaymentStatus::Confirmed) {
            return $payment; // idempotent
        }

        return DB::transaction(function () use ($payment, $pspReference, $channel, $metadata) {
            $payment->update([
                'status'        => PaymentStatus::Confirmed->value,
                'psp_reference' => $pspReference,
                'confirmed_at'  => now(),
                'metadata'      => array_merge((array) $payment->metadata, $metadata ?? []),
            ]);

            $membership = $payment->membership()->with(['campaign'])->first();
            $this->postLedgerMomo($payment, $membership, $channel);
            $this->affectInstallments($payment, $membership);

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
        $floatKey   = "MOMO_FLOAT:" . strtoupper($channel);
        $savingsKey = "MEMBER_SAVINGS:{$membership->id}";

        $this->ledger->openAccount($floatKey, AccountType::MomoFloat, null, "Float {$channel}");

        if (!\App\Modules\Ledger\Infrastructure\Models\LedgerAccount::where('key', $savingsKey)->exists()) {
            $this->ledger->openAccount($savingsKey, AccountType::MemberSavings, $membership->user_id);
        }

        $this->ledger->post(
            legs: [
                ['account' => $floatKey,   'type' => 'debit',  'amount' => $payment->amount_minor],
                ['account' => $savingsKey, 'type' => 'credit', 'amount' => $payment->amount_minor],
            ],
            reference:   $this->ledger->nextReference(),
            description: "Paiement MoMo {$channel} — {$payment->reference}",
            createdById: $payment->created_by,
            metadata:    ['payment_id' => $payment->id, 'channel' => $channel],
        );
    }

    // -----------------------------------------------------------------------
    // Affectation des échéances
    // -----------------------------------------------------------------------

    private function affectInstallments(Payment $payment, Membership $membership): void
    {
        $remaining = $payment->amount_minor;

        $unpaid = Installment::where('membership_id', $membership->id)
            ->whereIn('status', [InstallmentStatus::Pending->value, InstallmentStatus::Late->value])
            ->orderBy('sequence_number')
            ->get();

        foreach ($unpaid as $installment) {
            if ($remaining <= 0) break;

            $allocated = min($remaining, $installment->amount_minor);

            InstallmentPayment::create([
                'payment_id'     => $payment->id,
                'installment_id' => $installment->id,
                'amount_minor'   => $allocated,
                'created_at'     => now(),
            ]);

            $remaining -= $allocated;

            // Marquer payé si le montant alloué couvre l'échéance complète
            if ($allocated >= $installment->amount_minor) {
                $installment->update([
                    'status'  => InstallmentStatus::Paid->value,
                    'paid_at' => now(),
                ]);
            }
        }
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function nextReference(): string
    {
        $year  = now()->year;
        $count = Payment::whereYear('created_at', $year)->count() + 1;
        return sprintf('PAY-%d-%06d', $year, $count);
    }
}
