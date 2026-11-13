<?php

namespace App\Modules\Ledger\Application;

use App\Modules\Audit\Domain\Contracts\AuditEntityLabelResolver;
use App\Modules\Ledger\Infrastructure\Models\LedgerTransaction;

/** Libellé des entités d'audit `LedgerTransaction` (R-LEDGER-09) : « Écriture TX-… ». */
class LedgerAuditLabelResolver implements AuditEntityLabelResolver
{
    public function labels(string $entityType, array $ids): array
    {
        return LedgerTransaction::whereIn('id', $ids)->pluck('reference', 'id')
            ->map(fn ($ref) => 'Écriture ' . $ref)
            ->all();
    }
}
