<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Diagnostic LECTURE SEULE avant / après l'harmonisation du 2026-11-13 (DA-17).
 * N'écrit rien : uniquement des SELECT. À lancer sur chaque environnement avant la
 * migration en production, puis après.
 *
 *   php artisan kafo:diagnose-ledger
 */
class DiagnoseLedgerHarmonisation extends Command
{
    protected $signature   = 'kafo:diagnose-ledger';
    protected $description = 'Diagnostic lecture seule : comptes MoMo en double, contre-passations multiples, équilibre, double usage de l\'épargne.';

    public function handle(): int
    {
        $this->info('Base : ' . DB::connection()->getDatabaseName() . ' (lecture seule)');
        $problems = 0;

        // 1. Comptes Mobile Money dont la clé n'est pas en majuscules / doublons par canal.
        $this->line("\n<comment>1. Comptes MOMO_FLOAT (clé attendue : MOMO_FLOAT:{CANAL} en majuscules)</comment>");
        $momo = DB::select("
            SELECT a.key, COALESCE(SUM(CASE WHEN e.entry_type='debit' THEN e.amount_minor ELSE -e.amount_minor END),0) AS balance,
                   COUNT(e.id) AS entries
            FROM ledger_accounts a LEFT JOIN ledger_entries e ON e.account_key = a.key
            WHERE a.key LIKE 'MOMO_FLOAT:%' GROUP BY a.key ORDER BY upper(a.key), a.key");
        $nonCanonical = array_filter($momo, fn ($r) => $r->key !== strtoupper($r->key));
        $this->table(['Compte', 'Solde (XOF)', 'Écritures'], array_map(fn ($r) => [$r->key, $r->balance, $r->entries], $momo));
        if ($nonCanonical) {
            $problems++;
            $this->warn(count($nonCanonical) . ' compte(s) hors convention : leur solde doit être transféré vers la clé en majuscules par une écriture de transfert (à valider).');
        }

        // 2. Contre-passations multiples (bloqueraient l'index unique sur reverses_id).
        $this->line("\n<comment>2. Transactions contre-passées plusieurs fois</comment>");
        $dups = DB::select("SELECT reverses_id, COUNT(*) AS n FROM ledger_transactions WHERE reverses_id IS NOT NULL GROUP BY reverses_id HAVING COUNT(*) > 1");
        $this->line($dups ? '' : '  aucune');
        if ($dups) {
            $problems++;
            $this->table(['Transaction', 'Contre-passations'], array_map(fn ($r) => [$r->reverses_id, $r->n], $dups));
        }

        // 3. Transactions déséquilibrées.
        $this->line("\n<comment>3. Transactions déséquilibrées (débits ≠ crédits)</comment>");
        $unbalanced = DB::select("SELECT transaction_id, SUM(CASE WHEN entry_type='debit' THEN amount_minor ELSE -amount_minor END) AS diff
            FROM ledger_entries GROUP BY transaction_id HAVING SUM(CASE WHEN entry_type='debit' THEN amount_minor ELSE -amount_minor END) <> 0");
        $this->line($unbalanced ? '' : '  aucune');
        if ($unbalanced) {
            $problems++;
            $this->table(['Transaction', 'Écart'], array_map(fn ($r) => [$r->transaction_id, $r->diff], $unbalanced));
        }

        // 4. Prêts sans emprunteur.
        $this->line("\n<comment>4. Prêts / demandes sans emprunteur (user_id)</comment>");
        foreach (['loans', 'loan_requests'] as $t) {
            if (! \Illuminate\Support\Facades\Schema::hasColumn($t, 'user_id')) {
                $this->line("  {$t} : colonne user_id absente (migration DA-17 pas encore appliquée)");
                continue;
            }
            $n = (int) DB::scalar("SELECT COUNT(*) FROM {$t} WHERE user_id IS NULL");
            $this->line("  {$t} : {$n}");
            $problems += $n > 0 ? 1 : 0;
        }

        // 5. Double usage de l'épargne (R-SAV-01) : réservations > épargne.
        $this->line("\n<comment>5. Adhésions où retraits en cours + avances dépassent l'épargne</comment>");
        $over = DB::select("
            WITH s AS (
                SELECT m.id, u.full_name,
                    COALESCE((SELECT SUM(CASE WHEN e.entry_type='credit' THEN e.amount_minor ELSE -e.amount_minor END)
                              FROM ledger_entries e WHERE e.account_key = 'MEMBER_SAVINGS:' || m.id), 0) AS savings,
                    COALESCE((SELECT SUM(w.amount_minor) FROM early_withdrawals w WHERE w.membership_id = m.id
                              AND w.status IN ('pending','under_review','info_requested','approved','payout_processing','payout_failed')), 0) AS withdrawals,
                    COALESCE((SELECT SUM(l.outstanding_minor) FROM loans l WHERE l.membership_id = m.id
                              AND l.product_type = 'advance' AND l.status = 'active'), 0) AS advances,
                    COALESCE((SELECT SUM(r.amount_minor) FROM loan_requests r WHERE r.membership_id = m.id
                              AND r.product_type = 'advance' AND r.status IN ('pending','approved','countersigning','countersigned')), 0) AS requests
                FROM memberships m JOIN users u ON u.id = m.user_id WHERE m.status IN ('active','suspended')
            ) SELECT * FROM s WHERE withdrawals + advances + requests > savings ORDER BY full_name");
        $this->line($over ? '' : '  aucune');
        if ($over) {
            $problems++;
            $this->table(['Membre', 'Épargne', 'Retraits en cours', 'Avances', 'Demandes d\'avance'],
                array_map(fn ($r) => [$r->full_name, $r->savings, $r->withdrawals, $r->advances, $r->requests], $over));
            $this->warn('Ces cas sont désormais signalés (avance, D1) ou refusés (retrait) ; les situations existantes sont à examiner au cas par cas.');
        }

        // 6. Payouts en attente gelés par un retrait en cours de versement.
        $this->line("\n<comment>6. Payouts en attente bloqués par un versement de retrait en cours</comment>");
        $frozen = DB::select("SELECT p.reference, w.reference AS withdrawal FROM campaign_payouts p
            JOIN early_withdrawals w ON w.membership_id = p.membership_id AND w.status = 'payout_processing'
            WHERE p.status = 'pending'");
        $this->line($frozen ? '' : '  aucun');
        if ($frozen) {
            $this->table(['Payout', 'Retrait en cours'], array_map(fn ($r) => [$r->reference, $r->withdrawal], $frozen));
        }

        $this->line('');
        $problems === 0
            ? $this->info('Aucun problème bloquant détecté.')
            : $this->warn("{$problems} point(s) à examiner.");

        return self::SUCCESS;
    }
}
