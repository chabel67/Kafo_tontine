<?php

namespace App\Console\Commands;

use App\Modules\Ledger\Application\LedgerService;
use App\Modules\Ledger\Domain\ValueObjects\TreasuryAccount;
use App\Modules\Ledger\Infrastructure\Models\LedgerAccount;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * DA-20 — transfère le solde des anciens comptes par réseau (`MOMO_FLOAT:MTN`, `:MOOV`…)
 * vers le compte de l'agrégateur qui détenait réellement l'argent (`PSP_FLOAT:KKIAPAY`).
 *
 * Le ledger reste en ajout seul (R-LEDGER-01) : une transaction équilibrée de transfert
 * par compte (motif `psp_treasury_migration`), aucune écriture modifiée. Sans effet sur
 * la trésorerie globale (les deux comptes sont de la trésorerie).
 *
 *   php artisan kafo:migrate-psp-treasury            # aperçu, n'écrit rien
 *   php artisan kafo:migrate-psp-treasury --execute  # écrit les transferts
 *
 * Idempotente : une fois transférés, les soldes sont à zéro et un second passage ne fait rien.
 */
class MigratePspTreasury extends Command
{
    protected $signature = 'kafo:migrate-psp-treasury
        {--execute : Écrit les transferts (sans cette option : aperçu seulement)}
        {--to=kkiapay : Agrégateur qui détenait les fonds}';

    protected $description = 'Transfère les soldes MOMO_FLOAT:{RÉSEAU} vers PSP_FLOAT:{AGRÉGATEUR} (DA-20).';

    public function handle(LedgerService $ledger): int
    {
        $target = TreasuryAccount::key((string) $this->option('to'));
        if ($target === 'CASH_BOX') {
            $this->error('La cible doit être un agrégateur (kkiapay, fedapay).');
            return self::INVALID;
        }

        $execute = (bool) $this->option('execute');
        $this->info('Base : ' . DB::connection()->getDatabaseName() . ($execute ? ' — ÉCRITURE' : ' — aperçu (aucune écriture)'));

        $keys = LedgerAccount::where('key', 'like', 'MOMO_FLOAT:%')->orderBy('key')->pluck('key')->all();
        $rows = [];
        foreach ($keys as $key) {
            $rows[] = ['key' => $key, 'balance' => $ledger->balance($key)];
        }
        $this->table(['Compte', 'Solde (XOF)'], array_map(fn ($r) => [$r['key'], number_format($r['balance'], 0, ',', ' ')], $rows));

        $toMove = array_filter($rows, fn ($r) => $r['balance'] !== 0);
        if (! $toMove) {
            $this->info('Rien à transférer.');
            return self::SUCCESS;
        }

        if (! $execute) {
            $this->warn(count($toMove) . " transfert(s) seraient écrits vers {$target}. Relancer avec --execute.");
            return self::SUCCESS;
        }

        $ledger->openAccount($target, TreasuryAccount::type((string) $this->option('to')), null, 'Solde agrégateur');

        DB::transaction(function () use ($ledger, $toMove, $target) {
            foreach ($toMove as $r) {
                $amount = abs($r['balance']);
                // Solde débiteur (fonds présents) : DR cible / CR ancien. Solde créditeur : l'inverse.
                [$dr, $cr] = $r['balance'] > 0 ? [$target, $r['key']] : [$r['key'], $target];
                $txn = $ledger->post(
                    legs: [
                        ['account' => $dr, 'type' => 'debit',  'amount' => $amount],
                        ['account' => $cr, 'type' => 'credit', 'amount' => $amount],
                    ],
                    reference:   $ledger->nextReference(),
                    description: "Transfert de trésorerie — {$r['key']} vers {$target} (moyens de paiement = agrégateurs)",
                    metadata:    ['reason' => 'psp_treasury_migration', 'from' => $r['key'], 'to' => $target],
                );
                $this->line("  {$r['key']} → {$target} : {$amount} XOF ({$txn->reference})");
            }
        });

        $this->info('Transferts écrits.');
        return self::SUCCESS;
    }
}
