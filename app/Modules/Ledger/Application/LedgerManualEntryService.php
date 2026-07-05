<?php

namespace App\Modules\Ledger\Application;

use App\Modules\Ledger\Domain\Enums\AccountType;
use App\Modules\Ledger\Domain\Enums\ManualEntryCategory;
use App\Modules\Ledger\Infrastructure\Models\LedgerTransaction;

/**
 * Enregistre une opération manuelle staff (R-LEDGER-09/10) : dépense
 * opérationnelle (loyer, salaires, fournitures…) ou recette diverse
 * (don reçu, subvention…). Produit une transaction ledger équilibrée
 * en partie double stricte via LedgerService::post().
 *
 * Contreparties automatiques :
 *   - Sortie : DR OPERATIONAL_EXPENSE / CR CASH_BOX ou MOMO_FLOAT:{channel}
 *   - Entrée : DR CASH_BOX ou MOMO_FLOAT:{channel} / CR MISC_REVENUE
 */
class LedgerManualEntryService
{
    public const DIRECTION_IN  = 'in';
    public const DIRECTION_OUT = 'out';

    private const CHANNELS = ['cash', 'mtn', 'orange', 'moov', 'wave'];

    public function __construct(private readonly LedgerService $ledger) {}

    public function create(
        string $direction,
        int    $amountMinor,
        string $channel,
        ManualEntryCategory $category,
        ?string $notes,
        string $userId,
    ): LedgerTransaction {
        if (! in_array($direction, [self::DIRECTION_IN, self::DIRECTION_OUT], true)) {
            throw new \InvalidArgumentException("Invalid direction: {$direction}");
        }
        if ($amountMinor < 1) {
            throw new \InvalidArgumentException("Amount must be positive.");
        }
        if (! in_array($channel, self::CHANNELS, true)) {
            throw new \InvalidArgumentException("Invalid channel: {$channel}");
        }

        // Comptes.
        [$treasuryKey, $treasuryType] = $this->treasuryAccount($channel);
        [$counterKey, $counterType]   = $direction === self::DIRECTION_OUT
            ? ['OPERATIONAL_EXPENSE', AccountType::OperationalExpense]
            : ['MISC_REVENUE',        AccountType::MiscRevenue];

        // Ouvre les comptes si absents (idempotent).
        $this->ledger->openAccount(
            key: $treasuryKey,
            type: $treasuryType,
        );
        $this->ledger->openAccount(
            key: $counterKey,
            type: $counterType,
            description: $counterType->label(),
        );

        // Legs équilibrés selon le sens.
        $legs = $direction === self::DIRECTION_OUT
            ? [
                ['account' => $counterKey,  'type' => 'debit',  'amount' => $amountMinor],
                ['account' => $treasuryKey, 'type' => 'credit', 'amount' => $amountMinor],
            ]
            : [
                ['account' => $treasuryKey, 'type' => 'debit',  'amount' => $amountMinor],
                ['account' => $counterKey,  'type' => 'credit', 'amount' => $amountMinor],
            ];

        // Description humaine (utilisée par LedgerJournalService::deriveOperation).
        $description = $this->buildDescription($direction, $category, $notes);

        return $this->ledger->post(
            legs:        $legs,
            reference:   $this->ledger->nextReference(),
            description: $description,
            createdById: $userId,
            metadata:    [
                'manual'    => true,
                'category'  => $category->value,
                'channel'   => $channel,
                'direction' => $direction,
                'notes'     => $notes,
            ],
        );
    }

    /** @return array{0:string,1:AccountType} */
    private function treasuryAccount(string $channel): array
    {
        return $channel === 'cash'
            ? ['CASH_BOX', AccountType::CashBox]
            : ['MOMO_FLOAT:' . strtolower($channel), AccountType::MomoFloat];
    }

    private function buildDescription(string $direction, ManualEntryCategory $category, ?string $notes): string
    {
        $prefix = $direction === self::DIRECTION_OUT
            ? 'Dépense opérationnelle'
            : 'Recette diverse';
        $base   = $prefix . ' — ' . $category->label();
        return ($notes !== null && $notes !== '')
            ? "{$base} : {$notes}"
            : $base;
    }
}
