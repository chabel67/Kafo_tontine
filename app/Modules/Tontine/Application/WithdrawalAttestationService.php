<?php

namespace App\Modules\Tontine\Application;

use App\Modules\Ledger\Application\LedgerService;
use App\Modules\Payments\Domain\Enums\MobileNetwork;
use App\Modules\Payments\Domain\Enums\PaymentChannel;
use App\Modules\Tontine\Domain\Enums\EarlyWithdrawalStatus;
use App\Modules\Tontine\Infrastructure\Models\EarlyWithdrawal;
use App\Modules\Tontine\Infrastructure\Models\EarlyWithdrawalPayoutAttempt as Attempt;
use App\Shared\Documents\AttestationPdf;
use Illuminate\Http\Response;

/**
 * Attestation de versement d'un retrait anticipé (R-DOC-01) : disponible une fois le
 * versement confirmé (`disbursed`, écriture ledger passée), générée à la demande.
 */
class WithdrawalAttestationService
{
    public const VIEW = 'attestations.withdrawal';

    public function __construct(private readonly LedgerService $ledger) {}

    public static function isAvailable(EarlyWithdrawal $withdrawal): bool
    {
        return $withdrawal->status === EarlyWithdrawalStatus::Disbursed;
    }

    public function download(EarlyWithdrawal $withdrawal): Response
    {
        return AttestationPdf::download(self::VIEW, $this->data($withdrawal), "attestation-{$withdrawal->reference}.pdf");
    }

    public function html(EarlyWithdrawal $withdrawal): string
    {
        return AttestationPdf::html(self::VIEW, $this->data($withdrawal));
    }

    private function data(EarlyWithdrawal $w): array
    {
        $w->loadMissing(['user', 'membership.campaign', 'decidedBy', 'disbursedBy', 'attempts']);

        $attempt = $w->attempts->where('status', Attempt::SUCCEEDED)->sortByDesc('attempt_no')->first();
        $network = $w->disbursed_network ? MobileNetwork::tryFrom($w->disbursed_network) : null;

        return [
            'title'       => "Attestation de retrait anticipé",
            'reference'   => $w->reference,
            'beneficiary' => [
                'name'  => $w->user?->full_name ?? '—',
                'phone' => AttestationPdf::phone($w->user?->phone),
            ],
            'campaignName' => $w->membership?->campaign?->name ?? '—',
            'amount'       => AttestationPdf::money($w->amount_minor),
            'requestedAt'  => AttestationPdf::date($w->created_at),
            'decidedAt'    => AttestationPdf::date($w->decided_at),
            'decidedBy'    => $w->decidedBy?->full_name,
            'disbursedAt'  => AttestationPdf::date($w->disbursed_at),
            'channel'      => PaymentChannel::labelFor($w->disbursed_channel),
            'network'      => $network?->label(),
            'payoutPhone'  => $w->disbursed_phone ? AttestationPdf::phone($w->disbursed_phone) : null,
            'externalRef'  => $attempt?->external_reference,
            'agent'        => $w->disbursedBy?->full_name,
            'ledgerRef'    => $this->ledger->transactionReference($w->ledger_transaction_id),
        ];
    }
}
