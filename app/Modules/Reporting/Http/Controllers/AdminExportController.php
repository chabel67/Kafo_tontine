<?php

namespace App\Modules\Reporting\Http\Controllers;

use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Ledger\Application\LedgerService;
use App\Modules\Ledger\Infrastructure\Models\LedgerEntry;
use App\Modules\Lending\Infrastructure\Models\Loan;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
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
}
