<?php

namespace App\Modules\Ledger\Application;

use App\Modules\Ledger\Domain\Enums\AccountType;
use App\Modules\Ledger\Domain\Exceptions\InvalidJournalPeriodException;
use App\Modules\Ledger\Infrastructure\Models\LedgerAccount;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Journal des opérations — vue chronologique cross-compte du ledger.
 *
 * Une ligne = une transaction ledger. Colonnes Entrée/Sortie exprimées du
 * point de vue trésorerie (Δ signé sur `CASH_BOX` ∪ `MOMO_FLOAT:*`), voir
 * R-LEDGER-06. Le compte principal affiché est le premier compte de
 * trésorerie touché par la transaction (R-LEDGER-07). La période est bornée
 * à `MAX_PERIOD_DAYS` (R-LEDGER-08).
 */
class LedgerJournalService
{
    private const MAX_PERIOD_DAYS = 90;
    private const DEFAULT_DAYS    = 30;

    /** Types de comptes considérés comme "trésorerie" pour le calcul du solde global. */
    private const TREASURY_TYPES = [
        AccountType::CashBox,
        AccountType::MomoFloat,
    ];

    public function __construct(private readonly LedgerService $ledger) {}

    /**
     * @return array{data: array, meta: array, summary: array}
     */
    public function forPeriod(
        ?Carbon $from = null,
        ?Carbon $to = null,
        ?string $search = null,
        ?string $channel = null,
        ?string $operationType = null,
        int $perPage = 25,
        int $page = 1,
    ): array {
        [$from, $to] = $this->normalizePeriod($from, $to);

        $treasuryTypes = array_map(fn ($t) => $t->value, self::TREASURY_TYPES);

        // 1) Solde de trésorerie à la borne d'ouverture (avant `from`).
        $openingTreasury = $this->treasuryBalanceBefore($from, $treasuryTypes);

        // 2) Fetch des transactions de la période avec :
        //    - Δ trésorerie signé
        //    - compte principal impacté (1er compte de trésorerie touché)
        //    - Δ du compte principal
        $rows = $this->fetchTransactionsWithDeltas($from, $to, $search, $treasuryTypes);

        // 3) Calcul PHP des running balances (trésorerie globale + compte principal).
        //    Ordre chronologique croissant pour cumul, puis renvoi décroissant.
        usort($rows, fn ($a, $b) => strcmp($a['created_at'], $b['created_at'])
            ?: strcmp($a['id'], $b['id']));

        $principalKeys = array_values(array_unique(array_filter(array_column($rows, 'principal_account_key'))));
        $principalOpenings = $this->accountBalancesBefore($principalKeys, $from);

        $runningTreasury = $openingTreasury;
        $runningPrincipal = $principalOpenings; // clone du map
        $totalIn = 0;
        $totalOut = 0;

        foreach ($rows as $i => $row) {
            $delta = (int) $row['treasury_delta'];
            $runningTreasury += $delta;
            $rows[$i]['treasury_balance_after_minor'] = $runningTreasury;

            $key = $row['principal_account_key'];
            if ($key) {
                $runningPrincipal[$key] = ($runningPrincipal[$key] ?? 0) + (int) $row['principal_delta'];
                $rows[$i]['account_balance_after_minor'] = $runningPrincipal[$key];
            } else {
                $rows[$i]['account_balance_after_minor'] = null;
            }

            if ($delta > 0)      $totalIn  += $delta;
            elseif ($delta < 0)  $totalOut += -$delta;
        }

        // 4) Enrichir chaque ligne : operation_type, operation_label, in/out, account_label
        $accountLabels = $this->accountLabels(array_values(array_unique(array_filter(array_column($rows, 'principal_account_key')))));

        foreach ($rows as $i => $row) {
            $delta = (int) $row['treasury_delta'];
            $isReversal = ! empty($row['reverses_id']);
            [$type, $label] = $this->deriveOperation($row['description'] ?? '', $isReversal);
            $rows[$i]['operation_type']  = $type;
            $rows[$i]['operation_label'] = $label;
            $rows[$i]['in_minor']  = max(0, $delta);
            $rows[$i]['out_minor'] = max(0, -$delta);
            $rows[$i]['account_label'] = $row['principal_account_key']
                ? ($accountLabels[$row['principal_account_key']] ?? null)
                : null;
            $rows[$i]['account_key'] = $row['principal_account_key'];
        }

        // 5) Filtres post-cumul : channel, operation_type.
        if ($channel) {
            $expected = $this->expectedAccountKeyForChannel($channel);
            $rows = array_values(array_filter(
                $rows,
                fn ($r) => $expected === 'CASH_BOX'
                    ? $r['account_key'] === 'CASH_BOX'
                    : $r['account_key'] === $expected,
            ));
        }
        if ($operationType) {
            $rows = array_values(array_filter($rows, fn ($r) => $r['operation_type'] === $operationType));
        }

        // 6) Ordre chronologique décroissant pour affichage
        usort($rows, fn ($a, $b) => strcmp($b['created_at'], $a['created_at'])
            ?: strcmp($b['id'], $a['id']));

        // 7) Pagination in-memory (post-filtres)
        $total    = count($rows);
        $perPage  = max(1, min($perPage, 100));
        $lastPage = (int) max(1, ceil($total / $perPage));
        $page     = max(1, min($page, $lastPage));
        $paged    = array_slice($rows, ($page - 1) * $perPage, $perPage);

        return [
            'data' => array_map(fn ($r) => $this->serializeRow($r), $paged),
            'meta' => [
                'total'        => $total,
                'per_page'     => $perPage,
                'current_page' => $page,
                'last_page'    => $lastPage,
            ],
            'summary' => [
                'period_from'             => $from->toIso8601String(),
                'period_to'               => $to->toIso8601String(),
                'opening_treasury_minor'  => $openingTreasury,
                'closing_treasury_minor'  => $openingTreasury + $totalIn - $totalOut,
                'total_in_minor'          => $totalIn,
                'total_out_minor'         => $totalOut,
                'transactions_count'      => count($rows),
            ],
        ];
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function normalizePeriod(?Carbon $from, ?Carbon $to): array
    {
        $tz  = config('app.timezone', 'Africa/Cotonou');
        $to  = ($to ?? Carbon::now($tz))->copy()->endOfDay();
        $from = ($from ?? $to->copy()->subDays(self::DEFAULT_DAYS - 1))->copy()->startOfDay();
        $days = (int) $from->diffInDays($to) + 1;
        if ($days > self::MAX_PERIOD_DAYS) {
            throw new InvalidJournalPeriodException(self::MAX_PERIOD_DAYS, $days);
        }
        return [$from, $to];
    }

    /** Somme signée Δ (asset : DR-CR) sur les comptes de trésorerie avant `$from`. */
    private function treasuryBalanceBefore(Carbon $from, array $treasuryTypes): int
    {
        // Pour asset (CASH_BOX + MOMO_FLOAT) : balance = SUM(debit) - SUM(credit).
        $row = DB::table('ledger_entries as e')
            ->join('ledger_accounts as a', 'a.key', '=', 'e.account_key')
            ->whereIn('a.type', $treasuryTypes)
            ->where('e.created_at', '<', $from)
            ->selectRaw("
                COALESCE(SUM(CASE WHEN e.entry_type = 'debit'  THEN e.amount_minor ELSE 0 END), 0) as dr,
                COALESCE(SUM(CASE WHEN e.entry_type = 'credit' THEN e.amount_minor ELSE 0 END), 0) as cr
            ")
            ->first();
        return (int) ($row?->dr ?? 0) - (int) ($row?->cr ?? 0);
    }

    /**
     * Pour chaque `account_key` fourni, calcule le solde à la borne `$from`
     * en tenant compte du type (asset ou passif) pour la convention.
     * @param string[] $keys
     * @return array<string,int>
     */
    private function accountBalancesBefore(array $keys, Carbon $from): array
    {
        if (empty($keys)) return [];
        $rows = DB::table('ledger_entries as e')
            ->join('ledger_accounts as a', 'a.key', '=', 'e.account_key')
            ->whereIn('e.account_key', $keys)
            ->where('e.created_at', '<', $from)
            ->selectRaw("
                e.account_key,
                a.type as account_type,
                COALESCE(SUM(CASE WHEN e.entry_type = 'debit'  THEN e.amount_minor ELSE 0 END), 0) as dr,
                COALESCE(SUM(CASE WHEN e.entry_type = 'credit' THEN e.amount_minor ELSE 0 END), 0) as cr
            ")
            ->groupBy('e.account_key', 'a.type')
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $type = AccountType::from($r->account_type);
            $out[$r->account_key] = $type->isAsset()
                ? (int) $r->dr - (int) $r->cr
                : (int) $r->cr - (int) $r->dr;
        }
        // Comptes sans historique pré-période → 0
        foreach ($keys as $k) {
            $out[$k] ??= 0;
        }
        return $out;
    }

    /**
     * Récupère les transactions dans [from, to] avec Δ trésorerie,
     * compte principal impacté et Δ du compte principal.
     * @return array<int,array>
     */
    private function fetchTransactionsWithDeltas(
        Carbon $from,
        Carbon $to,
        ?string $search,
        array $treasuryTypes,
    ): array {
        $typeList = "'" . implode("','", array_map(fn ($t) => addslashes($t), $treasuryTypes)) . "'";
        $searchWhere = '';
        $bindings = [
            'from' => $from,
            'to'   => $to,
        ];
        if ($search) {
            $searchWhere = "AND (t.reference ILIKE :search OR t.description ILIKE :search)";
            $bindings['search'] = '%' . $search . '%';
        }

        $sql = <<<SQL
            WITH tx_deltas AS (
                SELECT
                    t.id, t.reference, t.description, t.created_at, t.reverses_id, t.metadata,
                    COALESCE(SUM(
                        CASE WHEN a.type IN ({$typeList}) THEN
                            CASE WHEN e.entry_type = 'debit' THEN e.amount_minor ELSE -e.amount_minor END
                        ELSE 0 END
                    ), 0) AS treasury_delta,
                    (
                        SELECT e2.account_key
                        FROM ledger_entries e2
                        JOIN ledger_accounts a2 ON a2.key = e2.account_key
                        WHERE e2.transaction_id = t.id
                          AND a2.type IN ({$typeList})
                        ORDER BY e2.created_at ASC LIMIT 1
                    ) AS principal_account_key
                FROM ledger_transactions t
                LEFT JOIN ledger_entries  e ON e.transaction_id = t.id
                LEFT JOIN ledger_accounts a ON a.key = e.account_key
                WHERE t.created_at BETWEEN :from AND :to
                  {$searchWhere}
                GROUP BY t.id
            )
            SELECT
                d.*,
                COALESCE((
                    SELECT SUM(
                        CASE WHEN e3.entry_type = 'debit' THEN e3.amount_minor ELSE -e3.amount_minor END
                    )
                    FROM ledger_entries e3
                    WHERE e3.transaction_id = d.id
                      AND e3.account_key = d.principal_account_key
                ), 0) AS principal_delta
            FROM tx_deltas d
            ORDER BY d.created_at ASC, d.id ASC
        SQL;

        return array_map(fn ($r) => (array) $r, DB::select($sql, $bindings));
    }

    /** @param string[] $keys */
    private function accountLabels(array $keys): array
    {
        if (empty($keys)) return [];
        $rows = LedgerAccount::whereIn('key', $keys)->get(['key', 'type', 'description']);
        $out = [];
        foreach ($rows as $a) {
            /** @var LedgerAccount $a */
            $type = AccountType::from($a->type instanceof AccountType ? $a->type->value : (string) $a->type);
            // Ex : "MOMO_FLOAT:MTN" → "Float Mobile Money · MTN"
            $suffix = str_contains($a->key, ':')
                ? ' · ' . strtoupper(explode(':', $a->key, 2)[1])
                : '';
            $out[$a->key] = $type->label() . $suffix;
        }
        return $out;
    }

    private function expectedAccountKeyForChannel(string $channel): string
    {
        return $channel === 'cash'
            ? 'CASH_BOX'
            : 'MOMO_FLOAT:' . strtoupper($channel);
    }

    /** @return array{0:string,1:string} [operation_type, operation_label] */
    private function deriveOperation(string $description, bool $isReversal): array
    {
        $d = trim($description);
        $type = 'other';
        $label = $d;

        if (str_starts_with($d, 'Encaissement cash')) {
            $type = 'cash_in';
            $label = 'Encaissement cash';
        } elseif (str_starts_with($d, 'Encaissement MoMo')) {
            $type = 'cash_in';
            $label = 'Encaissement Mobile Money';
        } elseif (str_starts_with($d, 'Décaissement')) {
            $type = 'disbursement';
            $label = $d;
        } elseif (str_starts_with($d, 'Remboursement')) {
            $type = 'loan_repayment';
            $label = $d;
        } elseif (str_starts_with($d, 'Payout de clôture')) {
            $type = 'campaign_payout';
            $label = 'Payout de clôture';
        } elseif (str_starts_with($d, 'Reliquat non couvert')) {
            $type = 'payout_shortfall';
            $label = 'Reliquat de payout (perte)';
        } elseif (str_starts_with($d, 'Write-off')) {
            $type = 'writeoff';
            $label = 'Passage en perte (write-off)';
        }

        if ($isReversal) {
            return ['reversal', 'Contre-passation — ' . $label];
        }
        return [$type, $label];
    }

    private function serializeRow(array $r): array
    {
        return [
            'id'                          => $r['id'],
            'reference'                   => $r['reference'],
            'created_at'                  => Carbon::parse($r['created_at'])->toIso8601String(),
            'description'                 => $r['description'] ?? '',
            'operation_type'              => $r['operation_type'],
            'operation_label'             => $r['operation_label'],
            'account_key'                 => $r['account_key'] ?? null,
            'account_label'               => $r['account_label'] ?? null,
            'in_minor'                    => (int) $r['in_minor'],
            'out_minor'                   => (int) $r['out_minor'],
            'account_balance_after_minor' => $r['account_balance_after_minor'] !== null
                ? (int) $r['account_balance_after_minor']
                : null,
            'treasury_balance_after_minor' => (int) $r['treasury_balance_after_minor'],
        ];
    }
}
