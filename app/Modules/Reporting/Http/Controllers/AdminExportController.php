<?php

namespace App\Modules\Reporting\Http\Controllers;

use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Ledger\Application\LedgerJournalService;
use App\Modules\Ledger\Application\LedgerService;
use App\Modules\Ledger\Infrastructure\Models\LedgerEntry;
use App\Modules\Lending\Infrastructure\Models\Loan;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminExportController extends Controller
{
    public function __construct(private readonly LedgerService $ledger) {}

    /**
     * GET /admin/exports/ledger?from=YYYY-MM-DD&to=YYYY-MM-DD
     */
    public function ledger(Request $request): StreamedResponse
    {
        $from = $request->query('from') ? now()->parse($request->query('from'))->startOfDay() : now()->startOfMonth();
        $to   = $request->query('to')   ? now()->parse($request->query('to'))->endOfDay()   : now()->endOfDay();

        $filename = "grand-livre-{$from->format('Y-m-d')}-au-{$to->format('Y-m-d')}.csv";

        return response()->streamDownload(function () use ($from, $to) {
            $handle = fopen('php://output', 'w');
            fprintf($handle, chr(0xEF).chr(0xBB).chr(0xBF)); // BOM UTF-8 pour Excel

            fputcsv($handle, ['Date', 'Référence TXN', 'Description', 'Compte', 'Type compte', 'Sens', 'Montant (XOF)'], ';');

            LedgerEntry::with(['transaction', 'account'])
                ->whereHas('transaction', fn ($q) => $q->whereBetween('created_at', [$from, $to]))
                ->orderBy('created_at')
                ->chunk(500, function ($entries) use ($handle) {
                    foreach ($entries as $e) {
                        fputcsv($handle, [
                            $e->created_at?->format('Y-m-d H:i:s'),
                            $e->transaction?->reference,
                            $e->transaction?->description,
                            $e->account?->key,
                            $e->account?->type,
                            $e->entry_type === 'debit' ? 'Débit' : 'Crédit',
                            $e->amount_minor,
                        ], ';');
                    }
                });

            fclose($handle);
        }, $filename, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    /**
     * GET /admin/exports/members
     */
    public function members(Request $request): StreamedResponse
    {
        $filename = 'membres-' . now()->format('Y-m-d') . '.csv';

        return response()->streamDownload(function () {
            $handle = fopen('php://output', 'w');
            fprintf($handle, chr(0xEF).chr(0xBB).chr(0xBF));

            fputcsv($handle, ['Nom', 'Téléphone', 'Statut', 'KYC niveau', 'Date inscription'], ';');

            User::orderBy('created_at')->chunk(500, function ($users) use ($handle) {
                foreach ($users as $u) {
                    fputcsv($handle, [
                        $u->full_name,
                        $u->phone,
                        $u->status?->value ?? $u->status,
                        $u->kyc_level,
                        $u->created_at?->format('Y-m-d'),
                    ], ';');
                }
            });

            fclose($handle);
        }, $filename, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    /**
     * GET /admin/exports/loans
     */
    public function loans(Request $request): StreamedResponse
    {
        $filename = 'portefeuille-avances-' . now()->format('Y-m-d') . '.csv';

        return response()->streamDownload(function () {
            $handle = fopen('php://output', 'w');
            fprintf($handle, chr(0xEF).chr(0xBB).chr(0xBF));

            fputcsv($handle, [
                'Référence', 'Membre', 'Téléphone', 'Campagne',
                'Principal (XOF)', 'Restant dû (XOF)', 'Remboursé (XOF)',
                '% remboursé', 'Statut', 'Canal', 'Date décaissement',
            ], ';');

            Loan::with(['membership.user', 'membership.campaign'])
                ->orderBy('created_at')
                ->chunk(500, function ($loans) use ($handle) {
                    foreach ($loans as $l) {
                        $repaid  = $l->principal_minor - $l->outstanding_minor;
                        $pct     = $l->principal_minor > 0 ? round($repaid / $l->principal_minor * 100, 1) : 0;
                        fputcsv($handle, [
                            $l->reference,
                            $l->membership?->user?->full_name ?? '—',
                            $l->membership?->user?->phone ?? '—',
                            $l->membership?->campaign?->name ?? '—',
                            $l->principal_minor,
                            $l->outstanding_minor,
                            $repaid,
                            $pct . '%',
                            $l->status?->value ?? $l->status,
                            $l->disbursed_channel,
                            $l->disbursed_at?->format('Y-m-d'),
                        ], ';');
                    }
                });

            fclose($handle);
        }, $filename, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    /**
     * GET /admin/exports/journal
     *
     * Export CSV du journal des opérations — vue chronologique cross-compte
     * du point de vue trésorerie (R-LEDGER-06).
     */
    public function journal(Request $request, LedgerJournalService $journal): StreamedResponse
    {
        $tz   = config('app.timezone', 'Africa/Cotonou');
        $from = $request->query('from') ? Carbon::parse($request->query('from'), $tz) : null;
        $to   = $request->query('to')   ? Carbon::parse($request->query('to'),   $tz) : null;
        $channel = $request->query('channel');
        $operationType = $request->query('operation_type');

        $result = $journal->forPeriod(
            from:          $from,
            to:            $to,
            channel:       $channel ?: null,
            operationType: $operationType ?: null,
            perPage:       PHP_INT_MAX,
            page:          1,
        );

        $summary = $result['summary'];
        $dateFrom = Carbon::parse($summary['period_from'])->format('Y-m-d');
        $dateTo   = Carbon::parse($summary['period_to'])->format('Y-m-d');
        $filename = "journal-operations-{$dateFrom}-au-{$dateTo}.csv";

        return response()->streamDownload(function () use ($result) {
            $handle = fopen('php://output', 'w');
            fprintf($handle, chr(0xEF) . chr(0xBB) . chr(0xBF)); // BOM UTF-8

            fputcsv($handle, [
                'Date', 'Heure', 'Référence', 'Opération', 'Compte',
                'Entrée (XOF)', 'Sortie (XOF)',
                'Solde compte (XOF)', 'Solde global (XOF)',
            ], ';');

            foreach ($result['data'] as $row) {
                $dt = Carbon::parse($row['created_at'])->timezone(config('app.timezone', 'Africa/Cotonou'));
                fputcsv($handle, [
                    $dt->format('Y-m-d'),
                    $dt->format('H:i:s'),
                    $row['reference'],
                    $row['operation_label'],
                    $row['account_label'] ?? '—',
                    $row['in_minor']  ?: '',
                    $row['out_minor'] ?: '',
                    $row['account_balance_after_minor'] ?? '',
                    $row['treasury_balance_after_minor'],
                ], ';');
            }

            fclose($handle);
        }, $filename, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }
}
