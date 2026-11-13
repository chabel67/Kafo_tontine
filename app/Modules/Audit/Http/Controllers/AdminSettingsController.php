<?php

namespace App\Modules\Audit\Http\Controllers;

use App\Modules\Audit\Application\AuditService;
use App\Modules\Audit\Application\CashRegisterService;
use App\Modules\Payments\Domain\Enums\PaymentChannel;
use App\Shared\ApiResponse;
use App\Shared\BusinessTime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

class AdminSettingsController extends Controller
{
    public function __construct(
        private readonly AuditService        $audit,
        private readonly CashRegisterService $cashRegister,
    ) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->groupedSettings()]);
    }

    /**
     * PATCH /admin/settings — `settings` = liste de {key, value}. Un sous-ensemble de clés
     * suffit (EF-ADMIN-02) : seules les clés transmises sont écrites et auditées ; le
     * payload complet reste accepté.
     *
     * DA-13 : un canal non routé par KKiaPay (Orange, Wave) ne peut pas être activé
     * (422 `channel_not_supported`). Le désactiver reste possible ; renvoyer la valeur
     * déjà stockée (payload complet) n'est pas une activation et passe.
     */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'settings'         => ['required', 'array', 'min:1'],
            'settings.*.key'   => ['required', 'string', 'distinct', 'exists:system_settings,key'],
            'settings.*.value' => ['required'],
        ]);

        $current = DB::table('system_settings')
            ->whereIn('key', array_column($data['settings'], 'key'))
            ->pluck('value', 'key');

        // Contrôle avant toute écriture : la requête est acceptée ou refusée en bloc.
        foreach ($data['settings'] as $item) {
            $channel = PaymentChannel::fromSettingKey($item['key']);
            if ($channel && ! $channel->isSupported()
                && self::truthy($item['value']) && ! self::truthy($current[$item['key']] ?? null)) {
                return ApiResponse::error(
                    'channel_not_supported',
                    "Le canal {$channel->label()} n'est pas pris en charge par l'agrégateur de paiement (KKiaPay) : il ne peut pas être activé.",
                    422,
                    ['details' => ['key' => $item['key'], 'channel' => $channel->value]],
                );
            }
        }

        $old = [];
        $new = [];

        DB::transaction(function () use ($data, $current, &$old, &$new) {
            foreach ($data['settings'] as $item) {
                $old[$item['key']] = $current[$item['key']] ?? null;
                $new[$item['key']] = $item['value'];
                DB::table('system_settings')->where('key', $item['key'])->update([
                    'value'      => (string) $item['value'],
                    'updated_at' => now(),
                ]);
            }
        });

        $this->audit->logFromRequest($request, 'settings.update', null, null, $old, $new);

        return response()->json(['data' => $this->groupedSettings()]);
    }

    /**
     * Paramètres groupés. Additif (lot A-bis) : chaque ligne du groupe `psp` porte
     * `supported` (canal routé par le PSP, DA-13) et `effective_enabled`
     * (valeur stockée ET canal supporté). La valeur stockée n'est jamais modifiée.
     */
    private function groupedSettings(): array
    {
        $rows = DB::table('system_settings')->orderBy('group')->orderBy('key')->get()
            ->map(function ($row) {
                $channel = PaymentChannel::fromSettingKey($row->key);
                if ($channel) {
                    $row->supported         = $channel->isSupported();
                    $row->effective_enabled = $channel->isSupported() && self::truthy($row->value);
                }
                return $row;
            });

        return $rows->groupBy('group')->map(fn ($items) => $items->values())->toArray();
    }

    private static function truthy(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * GET /admin/cash/summary — caisse du jour métier.
     *
     * `confirmed` / `pending` : toutes caisses (inchangé). Lot A-bis, additif : bloc `mine`
     * = caisse du membre du personnel connecté (paiements cash qu'il a saisis, `created_by`)
     * + état de sa clôture (R-PAY-06).
     */
    public function cashSummary(Request $request): JsonResponse
    {
        // Jour de caisse = jour local métier (bornes UTC pour les timestamps stockés).
        $today = BusinessTime::todayDate();
        [$dayStart, $dayEnd] = BusinessTime::dayBoundsUtc($today);

        $totals = function (string $status, ?string $cashierId = null) use ($dayStart, $dayEnd) {
            $row = DB::table('payments')
                ->where('channel', 'cash')
                ->where('status', $status)
                ->whereBetween('created_at', [$dayStart, $dayEnd])
                ->when($cashierId, fn ($q) => $q->where('created_by', $cashierId))
                ->selectRaw('COUNT(*) as count, COALESCE(SUM(amount_minor),0) as total')
                ->first();

            return ['count' => (int) $row->count, 'total' => (int) $row->total];
        };

        $userId  = $request->user()->id;
        $closure = $this->cashRegister->closureFor($userId, $today);

        return response()->json([
            'data' => [
                'date'      => $today,
                'confirmed' => $totals('confirmed'),
                // R-FEE-02 : part des encaissements du jour qui sont des frais d'adhésion (inclus dans `confirmed`).
                'membership_fees' => [
                    'count' => (int) DB::table('payments')->where('channel', 'cash')->where('status', 'confirmed')
                        ->where('purpose', 'membership_fee')->whereBetween('created_at', [$dayStart, $dayEnd])->count(),
                    'total' => (int) DB::table('payments')->where('channel', 'cash')->where('status', 'confirmed')
                        ->where('purpose', 'membership_fee')->whereBetween('created_at', [$dayStart, $dayEnd])->sum('amount_minor'),
                ],
                'pending'   => $totals('pending'),
                'mine'      => [
                    'confirmed' => $totals('confirmed', $userId),
                    'pending'   => $totals('pending', $userId),
                    'closed'    => $closure !== null,
                    'closure'   => $closure,
                ],
            ],
        ]);
    }

    public function closeDay(Request $request): JsonResponse
    {
        $data = $request->validate([
            'physical_amount_minor' => ['required', 'integer', 'min:0'],
            'notes'                 => ['nullable', 'string', 'max:500'],
        ]);

        // Jour de caisse = jour local métier (bornes UTC pour les timestamps stockés).
        $today = BusinessTime::todayDate();
        [$dayStart, $dayEnd] = BusinessTime::dayBoundsUtc($today);
        $theoretical = (int) DB::table('payments')
            ->where('channel', 'cash')
            ->where('status', 'confirmed')
            ->whereBetween('created_at', [$dayStart, $dayEnd])
            ->sum('amount_minor');

        $discrepancy = $data['physical_amount_minor'] - $theoretical;

        $this->audit->logFromRequest($request, CashRegisterService::CLOSE_ACTION, null, null, [], [
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
