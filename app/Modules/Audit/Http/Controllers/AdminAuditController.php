<?php

namespace App\Modules\Audit\Http\Controllers;

use App\Modules\Audit\Application\AuditEntityLabeler;
use App\Modules\Audit\Infrastructure\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminAuditController extends Controller
{
    /** Actions dont l'auteur est l'entité `user` elle-même. */
    private const SELF_AUTHORED_ACTIONS = ['auth.login_success', 'auth.staff_pin_set'];

    public function index(Request $request): JsonResponse
    {
        $query = AuditLog::query()->orderBy('created_at', 'desc');

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('user_name', 'ilike', "%{$search}%")
                  ->orWhere('action', 'ilike', "%{$search}%")
                  ->orWhere('entity_type', 'ilike', "%{$search}%");
            });
        }

        if ($action = $request->query('action')) {
            $query->where('action', $action);
        }

        if ($entityType = $request->query('entity_type')) {
            $query->where('entity_type', $entityType);
        }

        if ($userId = $request->query('user_id')) {
            $query->where('user_id', $userId);
        }

        if ($from = $request->query('from')) {
            $query->where('created_at', '>=', $from . ' 00:00:00');
        }

        if ($to = $request->query('to')) {
            $query->where('created_at', '<=', $to . ' 23:59:59');
        }

        $perPage = min((int) ($request->query('per_page', 50)), 200);
        $logs = $query->paginate($perPage);

        return response()->json([
            'data' => $this->withLabels($logs->items()),
            'meta' => [
                'current_page' => $logs->currentPage(),
                'last_page'    => $logs->lastPage(),
                'total'        => $logs->total(),
                'per_page'     => $logs->perPage(),
            ],
        ]);
    }

    public function show(string $id): JsonResponse
    {
        $log = AuditLog::findOrFail($id);

        return response()->json(['data' => $this->withLabels([$log])[0]]);
    }

    /**
     * Additif (lot A-bis) : `entity_label`, libellé lisible de l'entité fourni par son module
     * (AuditEntityLabeler, DA-09) ; null si type inconnu ou entité disparue.
     *
     * `user_name` : les connexions et créations de PIN staff antérieures au lot A-bis ont été
     * journalisées sans auteur (aucun jeton au moment de l'action). Pour ces deux actions,
     * l'auteur est l'entité elle-même : son nom est repris à la lecture. Les nouvelles
     * entrées portent `user_id` / `user_name` dès l'écriture.
     *
     * @param  array<int, AuditLog>  $logs
     * @return array<int, array<string, mixed>>
     */
    private function withLabels(array $logs): array
    {
        $labels = app(AuditEntityLabeler::class)->labelsFor($logs);

        return array_map(function (AuditLog $log) use ($labels) {
            $row   = $log->toArray();
            $label = $labels[$log->entity_type][$log->entity_id] ?? null;
            $row['entity_label'] = $label;

            if ($row['user_name'] === null && $log->entity_type === 'user' && $label !== null
                && in_array($log->action, self::SELF_AUTHORED_ACTIONS, true)) {
                $row['user_name'] = $label;
            }

            return $row;
        }, $logs);
    }

    public function export(Request $request): StreamedResponse
    {
        $query = AuditLog::query()->orderBy('created_at', 'desc');

        if ($from = $request->query('from')) {
            $query->where('created_at', '>=', $from . ' 00:00:00');
        }
        if ($to = $request->query('to')) {
            $query->where('created_at', '<=', $to . ' 23:59:59');
        }
        if ($action = $request->query('action')) {
            $query->where('action', $action);
        }

        $filename = 'audit-' . now()->format('Y-m-d') . '.csv';

        return response()->streamDownload(function () use ($query) {
            $handle = fopen('php://output', 'w');
            // UTF-8 BOM for Excel
            fprintf($handle, chr(0xEF) . chr(0xBB) . chr(0xBF));

            fputcsv($handle, [
                'Date', 'Utilisateur', 'Action', 'Entité', 'ID entité',
                'Valeurs avant', 'Valeurs après', 'IP', 'User-Agent',
            ], ';');

            $query->chunk(500, function ($logs) use ($handle) {
                foreach ($logs as $log) {
                    fputcsv($handle, [
                        $log->created_at?->format('d/m/Y H:i:s'),
                        $log->user_name ?? '—',
                        $log->action,
                        $log->entity_type ?? '—',
                        $log->entity_id ?? '—',
                        $log->old_values ? json_encode($log->old_values, JSON_UNESCAPED_UNICODE) : '—',
                        $log->new_values ? json_encode($log->new_values, JSON_UNESCAPED_UNICODE) : '—',
                        $log->ip_address ?? '—',
                        $log->user_agent ?? '—',
                    ], ';');
                }
            });

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function distinctActions(): JsonResponse
    {
        $actions = AuditLog::query()
            ->select('action')
            ->distinct()
            ->orderBy('action')
            ->pluck('action');

        return response()->json(['data' => $actions]);
    }
}
