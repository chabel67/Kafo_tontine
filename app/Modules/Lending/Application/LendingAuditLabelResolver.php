<?php

namespace App\Modules\Lending\Application;

use App\Modules\Audit\Domain\Contracts\AuditEntityLabelResolver;
use App\Modules\Lending\Domain\Enums\LoanProductType;
use App\Modules\Lending\Infrastructure\Models\Loan;
use App\Modules\Lending\Infrastructure\Models\LoanRepaymentSchedule;
use App\Modules\Lending\Infrastructure\Models\LoanRequest;
use App\Shared\Money;

/**
 * Libellés des entités d'audit du module Lending :
 *  - loan                    : « Avance LOAN-… — Nom » / « Prêt LOAN-… — Nom » ;
 *  - loan_request            : « Demande d'avance de 50 000 FCFA — Nom » (pas de référence) ;
 *  - loan_repayment_schedule : « Échéance n° 2 — LOAN-… ».
 */
class LendingAuditLabelResolver implements AuditEntityLabelResolver
{
    public const ENTITY_TYPES = ['loan', 'loan_request', 'loan_repayment_schedule'];

    public function labels(string $entityType, array $ids): array
    {
        return match ($entityType) {
            'loan' => Loan::with('user:id,full_name')
                ->whereIn('id', $ids)->get(['id', 'reference', 'product_type', 'user_id'])
                ->mapWithKeys(fn (Loan $l) => [
                    $l->id => ($l->product_type === LoanProductType::Standard ? 'Prêt ' : 'Avance ')
                        . $l->reference . ' — ' . ($l->user?->full_name ?? 'Membre'),
                ])->all(),

            'loan_request' => LoanRequest::with('user:id,full_name')
                ->whereIn('id', $ids)->get(['id', 'amount_minor', 'product_type', 'user_id'])
                ->mapWithKeys(fn (LoanRequest $r) => [
                    $r->id => ($r->product_type === LoanProductType::Standard ? 'Demande de prêt de ' : 'Demande d\'avance de ')
                        . Money::format((int) $r->amount_minor)
                        . ($r->user?->full_name ? ' — ' . $r->user->full_name : ''),
                ])->all(),

            'loan_repayment_schedule' => LoanRepaymentSchedule::with('loan:id,reference')
                ->whereIn('id', $ids)->get(['id', 'loan_id', 'sequence_number'])
                ->mapWithKeys(fn (LoanRepaymentSchedule $s) => [
                    $s->id => 'Échéance n° ' . $s->sequence_number . ' — ' . ($s->loan?->reference ?? 'Prêt'),
                ])->all(),

            default => [],
        };
    }
}
