<?php

namespace App\Modules\Audit\Http\Controllers;

use App\Modules\Audit\Application\AuditService;
use App\Shared\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

class AdminSettingsController extends Controller
{
    public function __construct(private readonly AuditService $audit) {}

    public function index(): JsonResponse
    {
        $rows = DB::table('system_settings')->orderBy('group')->orderBy('key')->get();

        $grouped = $rows->groupBy('group')->map(fn ($items) => $items->values())->toArray();

        return response()->json(['data' => $grouped]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'settings'         => ['required', 'array'],
            'settings.*.key'   => ['required', 'string', 'exists:system_settings,key'],
            'settings.*.value' => ['required'],
        ]);

        $old = [];
        $new = [];

        foreach ($data['settings'] as $item) {
            $current = DB::table('system_settings')->where('key', $item['key'])->first();
            if ($current) {
                $old[$item['key']] = $current->value;
                $new[$item['key']] = $item['value'];
                DB::table('system_settings')->where('key', $item['key'])->update([
                    'value'      => (string) $item['value'],
                    'updated_at' => now(),
                ]);
            }
        }

        $this->audit->logFromRequest($request, 'settings.update', null, null, $old, $new);

        $rows    = DB::table('system_settings')->orderBy('group')->orderBy('key')->get();
        $grouped = $rows->groupBy('group')->map(fn ($items) => $items->values())->toArray();

        return response()->json(['data' => $grouped]);
    }

    public function cashSummary(): JsonResponse
    {
        $today = now()->toDateString();

        $confirmed = DB::table('payments')
            ->where('channel', 'cash')
            ->where('status', 'confirmed')
            ->whereDate('created_at', $today)
            ->selectRaw('COUNT(*) as count, COALESCE(SUM(amount_minor),0) as total')
            ->first();

        $pending = DB::table('payments')
            ->where('channel', 'cash')
            ->where('status', 'pending')
            ->whereDate('created_at', $today)
            ->selectRaw('COUNT(*) as count, COALESCE(SUM(amount_minor),0) as total')
            ->first();

        return response()->json([
            'data' => [
                'date'      => $today,
                'confirmed' => ['count' => (int) $confirmed->count, 'total' => (int) $confirmed->total],
                'pending'   => ['count' => (int) $pending->count,   'total' => (int) $pending->total],
            ],
        ]);
    }

    public function closeDay(Request $request): JsonResponse
    {
        $data = $request->validate([
            'physical_amount_minor' => ['required', 'integer', 'min:0'],
            'notes'                 => ['nullable', 'string', 'max:500'],
        ]);

        $today = now()->toDateString();
        $theoretical = (int) DB::table('payments')
            ->where('channel', 'cash')
            ->where('status', 'confirmed')
            ->whereDate('created_at', $today)
            ->sum('amount_minor');

        $discrepancy = $data['physical_amount_minor'] - $theoretical;

        $this->audit->logFromRequest($request, 'cash.close_day', null, null, [], [
            'date'                  => $today,
            'physical_amount_minor' => $data['physical_amount_minor'],
            'theoretical_minor'     => $theoretical,
            'discrepancy_minor'     => $discrepancy,
            'notes'                 => $data['notes'] ?? null,
        ]);

        return response()->json([
            'data' => [
                'date'                  => $today,
                'physical_amount_minor' => $data['physical_amount_minor'],
                'theoretical_minor'     => $theoretical,
                'discrepancy_minor'     => $discrepancy,
            ],
        ]);
    }
}
