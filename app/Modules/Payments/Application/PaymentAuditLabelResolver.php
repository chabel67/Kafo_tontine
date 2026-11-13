<?php

namespace App\Modules\Payments\Application;

use App\Modules\Audit\Domain\Contracts\AuditEntityLabelResolver;
use App\Modules\Payments\Infrastructure\Models\Payment;

/** Libellé des entités d'audit `payment` : « PAY-2026-000123 — Nom ». */
class PaymentAuditLabelResolver implements AuditEntityLabelResolver
{
    public function labels(string $entityType, array $ids): array
    {
        return Payment::with('membership.user:id,full_name')
            ->whereIn('id', $ids)->get(['id', 'reference', 'membership_id'])
            ->mapWithKeys(fn (Payment $p) => [
                $p->id => $p->reference . ' — ' . ($p->membership?->user?->full_name ?? 'Membre'),
            ])->all();
    }
}
