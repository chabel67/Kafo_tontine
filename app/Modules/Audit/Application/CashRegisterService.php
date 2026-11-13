<?php

namespace App\Modules\Audit\Application;

use App\Modules\Audit\Infrastructure\Models\AuditLog;
use App\Shared\BusinessTime;

/**
 * État de la caisse d'un membre du personnel pour un jour métier (EF-CASH-02, R-PAY-06).
 *
 * La clôture de caisse n'a pas de table dédiée : elle est tracée par l'entrée d'audit
 * `cash.close_day` (auteur = trésorier, `new_values.date` = jour métier). Ce service est la
 * surface publique lue par les autres modules (DA-09) — Payments refuse un encaissement
 * cash après clôture.
 *
 * Réouverture admin (R-PAY-06 « sauf réouverture admin avec justification ») : non
 * implémentée à ce jour — aucune route ni action d'audit ne l'existe.
 */
class CashRegisterService
{
    public const CLOSE_ACTION = 'cash.close_day';

    /**
     * Clôture enregistrée par $userId pour le jour métier $date (défaut : aujourd'hui).
     *
     * @return array{closed_at: string, physical_amount_minor: int|null, theoretical_minor: int|null, discrepancy_minor: int|null}|null
     */
    public function closureFor(string $userId, ?string $date = null): ?array
    {
        $date ??= BusinessTime::todayDate();

        $log = AuditLog::query()
            ->where('action', self::CLOSE_ACTION)
            ->where('user_id', $userId)
            ->whereRaw("new_values->>'date' = ?", [$date])
            ->orderByDesc('created_at')
            ->first();

        if (! $log) {
            return null;
        }

        $values = $log->new_values ?? [];

        return [
            'closed_at'             => $log->created_at?->toIso8601String(),
            'physical_amount_minor' => isset($values['physical_amount_minor']) ? (int) $values['physical_amount_minor'] : null,
            'theoretical_minor'     => isset($values['theoretical_minor']) ? (int) $values['theoretical_minor'] : null,
            'discrepancy_minor'     => isset($values['discrepancy_minor']) ? (int) $values['discrepancy_minor'] : null,
        ];
    }

    public function isClosed(string $userId, ?string $date = null): bool
    {
        return $this->closureFor($userId, $date) !== null;
    }
}
