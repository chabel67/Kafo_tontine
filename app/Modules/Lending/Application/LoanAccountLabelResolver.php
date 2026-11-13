<?php

namespace App\Modules\Lending\Application;

use App\Modules\Ledger\Domain\Contracts\AccountOwnerLabelResolver;
use App\Modules\Lending\Domain\Enums\LoanProductType;
use App\Modules\Lending\Infrastructure\Models\Loan;

/** Libellé des comptes LOAN_RECEIVABLE:{loan_id} : « Avance LOAN-2026-000007 — Nom ». */
class LoanAccountLabelResolver implements AccountOwnerLabelResolver
{
    public function labels(array $ids): array
    {
        $uuids = array_values(array_filter($ids, fn ($id) => preg_match('/^[0-9a-f-]{36}$/i', $id)));
        if (empty($uuids)) {
            return [];
        }

        return Loan::with('user:id,full_name')
            ->whereIn('id', $uuids)
            ->get(['id', 'reference', 'product_type', 'user_id'])
            ->mapWithKeys(fn (Loan $l) => [
                $l->id => ($l->product_type === LoanProductType::Standard ? 'Prêt ' : 'Avance ')
                    . $l->reference
                    . ' — ' . ($l->user?->full_name ?? 'Membre'),
            ])
            ->all();
    }
}
