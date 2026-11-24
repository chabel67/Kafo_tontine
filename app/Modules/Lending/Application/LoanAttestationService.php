<?php

namespace App\Modules\Lending\Application;

use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Ledger\Application\LedgerService;
use App\Modules\Lending\Domain\Enums\LoanProductType;
use App\Modules\Lending\Infrastructure\Models\Loan;
use App\Modules\Payments\Domain\Enums\MobileNetwork;
use App\Modules\Payments\Domain\Enums\PaymentChannel;
use App\Shared\Documents\AttestationPdf;
use Illuminate\Http\Response;

/**
 * Attestation d'octroi d'un prêt (R-DOC-01) : disponible dès le décaissement,
 * générée à la demande. Prêt communautaire : échéancier inclus ; avance : mention
 * de la compensation au règlement de clôture (R-LOAN-12).
 */
class LoanAttestationService
{
    public const VIEW = 'attestations.loan';

    public function __construct(private readonly LedgerService $ledger) {}

    public static function isAvailable(Loan $loan): bool
    {
        return $loan->disbursed_at !== null;
    }

    public function download(Loan $loan): Response
    {
        return AttestationPdf::download(self::VIEW, $this->data($loan), "attestation-{$loan->reference}.pdf");
    }

    public function html(Loan $loan): string
    {
        return AttestationPdf::html(self::VIEW, $this->data($loan));
    }

    private function data(Loan $loan): array
    {
        $loan->loadMissing(['user', 'membership.campaign', 'schedules']);

        $isAdvance = $loan->product_type === LoanProductType::Advance;
        $interest  = (int) $loan->interest_total_minor;
        $schedule  = $isAdvance ? collect() : $loan->schedules;
        $network   = $loan->disbursed_network ? MobileNetwork::tryFrom($loan->disbursed_network) : null;

        return [
            'title'      => "Attestation d'octroi de prêt",
            'reference'  => $loan->reference,
            'isAdvance'  => $isAdvance,
            'beneficiary' => [
                'name'  => $loan->user?->full_name ?? '—',
                'phone' => AttestationPdf::phone($loan->user?->phone),
            ],
            'productLabel'   => $isAdvance ? 'Avance sur épargne' : 'Prêt communautaire',
            'campaignName'   => $loan->membership?->campaign?->name,
            'principal'      => AttestationPdf::money($loan->principal_minor),
            'interestRate'   => $loan->interest_rate_bps ? rtrim(rtrim(number_format($loan->interest_rate_bps / 100, 2, ',', ''), '0'), ',') . ' %' : null,
            'interestTotal'  => $interest > 0 ? AttestationPdf::money($interest) : null,
            'totalToRepay'   => AttestationPdf::money((int) $loan->principal_minor + $interest),
            'periodicity'    => $loan->periodicity?->label(),
            'installments'   => $loan->installments_count,
            'disbursedAt'    => AttestationPdf::date($loan->disbursed_at),
            'channel'        => PaymentChannel::labelFor($loan->disbursed_channel),
            'network'        => $network?->label(),
            'payoutPhone'    => $loan->disbursed_phone ? AttestationPdf::phone($loan->disbursed_phone) : null,
            'agent'          => $loan->disbursed_by ? User::whereKey($loan->disbursed_by)->value('full_name') : null,
            'ledgerRef'      => $this->ledger->firstReferenceByMetadata('loan_id', $loan->id, ['repayment_id']),
            'schedule'       => $schedule->map(fn ($item) => [
                'n'         => $item->sequence_number,
                'date'      => $item->due_date?->format('d/m/Y') ?? '—',
                'principal' => AttestationPdf::money($item->principal_minor),
                'interest'  => AttestationPdf::money($item->interest_minor),
                'total'     => AttestationPdf::money($item->amount_minor),
            ])->all(),
        ];
    }
}
