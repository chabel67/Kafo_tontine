<?php

namespace App\Modules\Ledger\Http\Controllers;

use App\Modules\Audit\Application\AuditService;
use App\Modules\Ledger\Application\LedgerJournalService;
use App\Modules\Ledger\Application\LedgerManualEntryService;
use App\Modules\Ledger\Application\LedgerService;
use App\Modules\Ledger\Domain\Enums\ManualEntryCategory;
use App\Modules\Ledger\Infrastructure\Models\LedgerAccount;
use App\Modules\Ledger\Infrastructure\Models\LedgerEntry;
use App\Modules\Ledger\Infrastructure\Models\LedgerTransaction;
use App\Modules\Ledger\Http\Resources\LedgerAccountResource;
use App\Modules\Ledger\Http\Resources\LedgerEntryResource;
use App\Shared\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class AdminLedgerController extends Controller
{
    public function __construct(
        private readonly LedgerService $ledger,
        private readonly AuditService  $audit,
    ) {}

    /** GET /admin/ledger/accounts */
    public function accounts(Request $request): JsonResponse
    {
        $accounts = LedgerAccount::query()
            ->when($request->query('type'), fn ($q, $t) => $q->where('type', $t))
            ->when($request->query('search'), fn ($q, $s) =>
                $q->where('key', 'ilike', "%{$s}%")
                  ->orWhere('description', 'ilike', "%{$s}%")
            )
            ->orderBy('type')
            ->orderBy('key')
            ->get();

        // Attach balance to each account
        $result = $accounts->map(function (LedgerAccount $account) {
            $credits = LedgerEntry::where('account_key', $account->key)->where('entry_type', 'credit')->sum('amount_minor');
            $debits  = LedgerEntry::where('account_key', $account->key)->where('entry_type', 'debit')->sum('amount_minor');
            $account->balance_minor = $account->type->isAsset()
                ? (int)($debits - $credits)
                : (int)($credits - $debits);
            return $account;
        });

        return ApiResponse::success(LedgerAccountResource::collection($result)->resolve());
    }

    /** GET /admin/ledger/accounts/{key}/entries */
    public function entries(Request $request, string $key): JsonResponse
    {
        $account  = LedgerAccount::where('key', $key)->firstOrFail();
        $isAsset  = $account->type->isAsset();
        $perPage  = 25;
        $page     = max(1, (int) $request->query('page', 1));
        $offset   = ($page - 1) * $perPage;
        $total    = LedgerEntry::where('account_key', $key)->count();

        // Compute running balance for entries preceding this page
        $allBefore = LedgerEntry::where('account_key', $key)
            ->orderBy('created_at')
            ->limit($offset)
            ->get(['entry_type', 'amount_minor']);

        $running = 0;
        foreach ($allBefore as $e) {
            if ($isAsset) {
                $running += $e->entry_type === 'debit' ? $e->amount_minor : -$e->amount_minor;
            } else {
                $running += $e->entry_type === 'credit' ? $e->amount_minor : -$e->amount_minor;
            }
        }

        $entries = LedgerEntry::with('transaction')
            ->where('account_key', $key)
            ->orderBy('created_at')
            ->skip($offset)
            ->take($perPage)
            ->get();

        $enriched = $entries->map(function (LedgerEntry $entry) use (&$running, $isAsset) {
            if ($isAsset) {
                $running += $entry->entry_type === 'debit' ? $entry->amount_minor : -$entry->amount_minor;
            } else {
                $running += $entry->entry_type === 'credit' ? $entry->amount_minor : -$entry->amount_minor;
            }
            $entry->running_balance = $running;
            return $entry;
        });

        // Full balance for account header (credits - debits or debits - credits)
        $credits = LedgerEntry::where('account_key', $key)->where('entry_type', 'credit')->sum('amount_minor');
        $debits  = LedgerEntry::where('account_key', $key)->where('entry_type', 'debit')->sum('amount_minor');
        $balance = $isAsset ? (int)($debits - $credits) : (int)($credits - $debits);

        return ApiResponse::success(
            LedgerEntryResource::collection($enriched)->resolve(),
            200,
            [
                'account'      => (new LedgerAccountResource($account->setAttribute('balance_minor', $balance)))->resolve(),
                'total'        => $total,
                'per_page'     => $perPage,
                'current_page' => $page,
                'last_page'    => max(1, (int) ceil($total / $perPage)),
            ],
        );
    }

    /** GET /admin/ledger/transactions/{id} */
    public function transaction(string $id): JsonResponse
    {
        $txn = LedgerTransaction::with('entries')->findOrFail($id);

        return ApiResponse::success([
            'id'          => $txn->id,
            'reference'   => $txn->reference,
            'description' => $txn->description,
            'reverses_id' => $txn->reverses_id,
            'metadata'    => $txn->metadata,
            'created_at'  => $txn->created_at?->toIso8601String(),
            'entries'     => LedgerEntryResource::collection($txn->entries)->resolve(),
        ]);
    }

    /** POST /admin/ledger/transactions/{id}/reverse */
    public function reverse(Request $request, string $id): JsonResponse
    {
        $txn = LedgerTransaction::with('entries')->findOrFail($id);

        $data     = $request->validate(['description' => ['required', 'string', 'min:10']]);
        $reversal = $this->ledger->reverse($txn, $data['description'], $request->user()?->id);

        return ApiResponse::created([
            'id'        => $reversal->id,
            'reference' => $reversal->reference,
        ]);
    }

    /**
     * POST /admin/ledger/entries
     *
     * Enregistre une opération manuelle staff — dépense opérationnelle
     * ou recette diverse — sur la trésorerie (R-LEDGER-09/10/11).
     */
    public function createManualEntry(Request $request, LedgerManualEntryService $service): JsonResponse
    {
        $data = $request->validate([
            'direction'    => ['required', 'string', Rule::in(['in', 'out'])],
            'amount_minor' => ['required', 'integer', 'min:1'],
            'channel'      => ['required', 'string', Rule::in(['cash', 'mtn', 'orange', 'moov', 'wave'])],
            'category'     => ['required', 'string', Rule::in(array_map(fn ($c) => $c->value, ManualEntryCategory::cases()))],
            'notes'        => ['nullable', 'string', 'max:500'],
        ]);

        $txn = $service->create(
            direction:    $data['direction'],
            amountMinor:  (int) $data['amount_minor'],
            channel:      $data['channel'],
            category:     ManualEntryCategory::from($data['category']),
            notes:        $data['notes'] ?? null,
            userId:       $request->user()->id,
        );

        $this->audit->logFromRequest(
            $request,
            'ledger.manual_entry.create',
            'LedgerTransaction',
            $txn->id,
            [],
            [
                'reference'    => $txn->reference,
                'direction'    => $data['direction'],
                'amount_minor' => (int) $data['amount_minor'],
                'channel'      => $data['channel'],
                'category'     => $data['category'],
                'notes'        => $data['notes'] ?? null,
            ],
        );

        return ApiResponse::created([
            'id'        => $txn->id,
            'reference' => $txn->reference,
        ]);
    }

    /**
     * GET /admin/ledger/journal
     *
     * Journal des opérations — vue chronologique cross-compte.
     * Colonnes du point de vue trésorerie (R-LEDGER-06/07/08).
     */
    public function journal(Request $request, LedgerJournalService $journal): JsonResponse
    {
        $data = $request->validate([
            'from'           => ['nullable', 'date'],
            'to'             => ['nullable', 'date', 'after_or_equal:from'],
            'search'         => ['nullable', 'string', 'max:100'],
            'channel'        => ['nullable', 'string', 'in:cash,mtn,orange,moov,wave'],
            'operation_type' => ['nullable', 'string', 'max:40'],
            'page'           => ['nullable', 'integer', 'min:1'],
            'per_page'       => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $tz   = config('app.timezone', 'Africa/Cotonou');
        $from = isset($data['from']) ? Carbon::parse($data['from'], $tz) : null;
        $to   = isset($data['to'])   ? Carbon::parse($data['to'],   $tz) : null;

        $result = $journal->forPeriod(
            from:          $from,
            to:            $to,
            search:        $data['search'] ?? null,
            channel:       $data['channel'] ?? null,
            operationType: $data['operation_type'] ?? null,
            perPage:       (int) ($data['per_page'] ?? 25),
            page:          (int) ($data['page'] ?? 1),
        );

        return ApiResponse::success(
            $result['data'],
            200,
            $result['meta'] + ['summary' => $result['summary']],
        );
    }
}
