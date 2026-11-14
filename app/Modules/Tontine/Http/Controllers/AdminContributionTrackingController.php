<?php

namespace App\Modules\Tontine\Http\Controllers;

use App\Modules\Tontine\Application\InstallmentTrackingService;
use App\Modules\Tontine\Infrastructure\Models\Campaign;
use App\Shared\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Suivi des cotisations (EF-CAMP-05) — lecture seule : qui a payé, qui n'a pas encore payé,
 * par date d'échéance ou en grille adhésions × échéances.
 */
class AdminContributionTrackingController extends Controller
{
    private const STATUS_LABELS = [
        'paid' => 'Payé', 'partial' => 'Partiel', 'due' => 'À payer',
        'late' => 'En retard', 'upcoming' => 'À venir', 'waived' => 'Annulée',
    ];

    public function __construct(private readonly InstallmentTrackingService $tracking) {}

    /** GET /admin/campaigns/{campaign}/contributions/due-dates */
    public function dueDates(Campaign $campaign): JsonResponse
    {
        return ApiResponse::success($this->tracking->dueDates($campaign));
    }

    /** GET /admin/campaigns/{campaign}/contributions?due_date=&status=paid|unpaid|late&search= */
    public function byDueDate(Request $request, Campaign $campaign): JsonResponse
    {
        $data = $request->validate([
            'due_date' => ['nullable', 'date_format:Y-m-d'],
            'status'   => ['nullable', 'in:paid,unpaid,late'],
            'search'   => ['nullable', 'string', 'max:100'],
        ]);

        $date = $data['due_date'] ?? $this->tracking->dueDates($campaign)['default_due_date'];
        if ($date === null) {
            return ApiResponse::success([
                'campaign_id' => $campaign->id, 'due_date' => null, 'round_number' => null,
                'totals' => ['members_count' => 0, 'expected_minor' => 0, 'collected_minor' => 0, 'remaining_minor' => 0,
                    'counts' => array_fill_keys(InstallmentTrackingService::STATUSES, 0)],
                'rows' => [],
            ]);
        }

        return ApiResponse::success($this->tracking->byDueDate($campaign, $date, $data['status'] ?? null, $data['search'] ?? null));
    }

    /** GET /admin/campaigns/{campaign}/contributions/grid?page=&per_page= */
    public function grid(Request $request, Campaign $campaign): JsonResponse
    {
        $data = $request->validate([
            'page'     => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $grid = $this->tracking->grid($campaign, (int) ($data['page'] ?? 1), isset($data['per_page']) ? (int) $data['per_page'] : null);
        $meta = $grid['meta'];
        unset($grid['meta']);

        return ApiResponse::success($grid, meta: $meta);
    }

    /**
     * GET /admin/campaigns/{campaign}/contributions/export?due_date=
     * Avec due_date : une ligne par membre pour cette échéance. Sans : vue d'ensemble par membre.
     */
    public function export(Request $request, Campaign $campaign): StreamedResponse
    {
        $data = $request->validate(['due_date' => ['nullable', 'date_format:Y-m-d']]);
        $slug = \Illuminate\Support\Str::slug($campaign->name);

        if (! empty($data['due_date'])) {
            $result = $this->tracking->byDueDate($campaign, $data['due_date']);

            return response()->streamDownload(function () use ($result) {
                $h = fopen('php://output', 'w');
                fprintf($h, chr(0xEF) . chr(0xBB) . chr(0xBF)); // BOM UTF-8 pour Excel
                fputcsv($h, ['Membre', 'Téléphone', 'Échéance n°', 'Date', 'Montant (XOF)', 'Payé (XOF)', 'Reste (XOF)', 'Statut', 'Dernier paiement', 'Retards cumulés'], ';');
                foreach ($result['rows'] as $r) {
                    fputcsv($h, [
                        $r['member_name'], $r['member_phone'], $r['sequence_number'], $r['due_date'],
                        $r['amount_minor'], $r['paid_minor'], $r['remaining_minor'],
                        self::STATUS_LABELS[$r['status']] ?? $r['status'],
                        $r['last_payment_at'] ? substr($r['last_payment_at'], 0, 10) : '', $r['late_count'],
                    ], ';');
                }
                fclose($h);
            }, "cotisations-{$slug}-{$data['due_date']}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
        }

        $grid = $this->tracking->gridAll($campaign);

        return response()->streamDownload(function () use ($grid) {
            $h = fopen('php://output', 'w');
            fprintf($h, chr(0xEF) . chr(0xBB) . chr(0xBF));
            fputcsv($h, ['Membre', 'Téléphone', 'Échéances payées', 'Échéances échues', 'Échéances au total', 'En retard', 'Reste dû à ce jour (XOF)'], ';');
            foreach ($grid['rows'] as $r) {
                fputcsv($h, [
                    $r['member_name'], $r['member_phone'], $r['paid_count'], $r['due_count'],
                    $r['total_count'], $r['late_count'], $r['outstanding_minor'],
                ], ';');
            }
            fclose($h);
        }, "cotisations-{$slug}-ensemble.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
