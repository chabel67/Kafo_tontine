<?php

namespace App\Modules\Tontine\Http\Controllers;

use App\Modules\Audit\Application\AuditService;
use App\Modules\Identity\Domain\Enums\KycStatus;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Http\Resources\UserResource;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Lending\Infrastructure\Models\LoanRequest;
use App\Modules\Tontine\Application\MemberOverviewService;
use App\Modules\Tontine\Application\TrustScoreService;
use App\Modules\Tontine\Domain\Enums\EarlyWithdrawalStatus;
use App\Modules\Tontine\Domain\Enums\InstallmentStatus;
use App\Modules\Tontine\Http\Requests\ReasonRequest;
use App\Modules\Tontine\Http\Requests\StoreMemberNoteRequest;
use App\Modules\Tontine\Http\Requests\StoreMemberRequest;
use App\Modules\Tontine\Http\Requests\UpdateMemberRequest;
use App\Modules\Tontine\Http\Resources\EarlyWithdrawalResource;
use App\Modules\Tontine\Http\Resources\MemberNoteResource;
use App\Modules\Tontine\Http\Resources\MembershipResource;
use App\Modules\Tontine\Infrastructure\Models\CampaignPayout;
use App\Modules\Tontine\Infrastructure\Models\EarlyWithdrawal;
use App\Modules\Tontine\Infrastructure\Models\Installment;
use App\Modules\Tontine\Infrastructure\Models\MemberNote;
use App\Modules\Tontine\Infrastructure\Models\Membership;
use App\Shared\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

class AdminMemberController extends Controller
{
    private const SORTS = ['full_name', 'created_at', 'trust_score'];

    public function __construct(
        private readonly AuditService          $audit,
        private readonly MemberOverviewService $overview,
    ) {}

    public function store(StoreMemberRequest $request): JsonResponse
    {
        $data     = $request->safe()->except('activate');
        $activate = $request->boolean('activate', true);

        // R-MEMB-09 : créé sans PIN ; aucune adhésion automatique
        $user = User::create([
            ...$data,
            'status'    => $activate ? UserStatus::Active : UserStatus::Pending,
            'kyc_level' => 0,
            'metadata'  => $activate ? ['activation' => $this->activationStamp($request)] : null,
        ]);

        $this->audit->logFromRequest($request, 'member.create', 'user', $user->id, [],
            [...$this->auditable($user), 'status' => $user->status->value]);

        return ApiResponse::created(new UserResource($user->load('roles')));
    }

    /**
     * Liste paginée des membres (le personnel en est exclu).
     * Filtres : search (nom, téléphone, email), status (active|pending_activation|suspended|closed), campaign_id.
     * Tri : sort=full_name|created_at|trust_score, direction=asc|desc.
     * Régularité et score sont agrégés en sous-requêtes SQL (pas de N+1).
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'status'      => ['nullable', 'in:pending,active,pin_not_set,suspended,closed'],
            'campaign_id' => ['nullable', 'uuid'],
            'sort'        => ['nullable', 'in:' . implode(',', self::SORTS)],
            'direction'   => ['nullable', 'in:asc,desc'],
            'per_page'    => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        $installmentCount = fn (callable $where) => Installment::query()
            ->selectRaw('COUNT(*)')
            ->join('memberships', 'memberships.id', '=', 'installments.membership_id')
            ->whereColumn('memberships.user_id', 'users.id')
            ->where($where);

        $base = User::query()
            ->whereDoesntHave('roles', fn ($q) => $q->whereIn('name', User::STAFF_ROLES))
            ->select('users.*')
            ->selectSub($installmentCount(fn ($q) => $q->whereDate('installments.due_date', '<=', today())
                ->where('installments.status', '!=', InstallmentStatus::Waived->value)), 'due_count')
            ->selectSub($installmentCount(fn ($q) => $q->whereDate('installments.due_date', '<=', today())
                ->where('installments.status', InstallmentStatus::Paid->value)), 'paid_due_count')
            ->selectSub($installmentCount(fn ($q) => $q->whereNotNull('installments.late_marked_at')), 'ever_late_count')
            ->selectSub($installmentCount(fn ($q) => $q->where('installments.status', InstallmentStatus::Late->value)), 'late_count')
            ->when($request->query('search'), function ($q, $s) {
                $like = '%' . str_replace(['%', '_'], ['\%', '\_'], trim($s)) . '%';
                $q->where(fn ($w) => $w->where('full_name', 'ilike', $like)
                    ->orWhere('phone', 'ilike', $like)
                    ->orWhere('email', 'ilike', $like));
            })
            // pin_not_set : compte activé mais PIN pas encore défini par le membre (DA-12)
            ->when($request->query('status'), fn ($q, $s) => $s === 'pin_not_set'
                ? $q->whereNull('pin_hash')->where('status', UserStatus::Active->value)
                : $q->where('status', $s))
            ->when($request->query('campaign_id'), fn ($q, $id) => $q->whereExists(fn ($e) => $e
                ->selectRaw('1')->from('memberships')
                ->whereColumn('memberships.user_id', 'users.id')
                ->where('memberships.campaign_id', $id)));

        $sort      = $request->query('sort', 'created_at');
        $direction = $request->query('direction', $sort === 'full_name' ? 'asc' : 'desc');

        $query = User::query()->fromSub($base, 'users')->select('users.*')
            ->selectRaw(TrustScoreService::sqlExpression('due_count', 'ever_late_count', 'late_count') . ' as trust_score');

        $query = $sort === 'full_name'
            ? $query->orderByRaw("LOWER(full_name) {$direction} NULLS LAST")
            : $query->orderBy($sort, $direction);

        $members = $query->orderBy('id')->paginate((int) $request->query('per_page', 15));

        // Campagnes de la page courante : une seule requête
        $campaignsByUser = Membership::with('campaign:id,name')
            ->whereIn('user_id', $members->pluck('id'))
            ->get()
            ->groupBy('user_id');

        $rows = $members->getCollection()->map(fn (User $u) => [
            ...(new UserResource($u))->resolve($request),
            'campaigns'   => ($campaignsByUser[$u->id] ?? collect())->map(fn ($m) => [
                'membership_id' => $m->id,
                'id'            => $m->campaign?->id,
                'name'          => $m->campaign?->name,
                'status'        => $m->status->value,
            ])->values(),
            // Régularité = échéances échues payées / échéances échues
            'regularity'  => (int) $u->due_count > 0 ? (int) round($u->paid_due_count / $u->due_count * 100) : null,
            'late_count'  => (int) $u->late_count,
            'trust_score' => (int) $u->trust_score,
        ]);

        return ApiResponse::success($rows, 200, [
            'total'        => $members->total(),
            'per_page'     => $members->perPage(),
            'current_page' => $members->currentPage(),
            'last_page'    => $members->lastPage(),
        ]);
    }

    public function show(User $user): JsonResponse
    {
        $this->assertMember($user);
        $user->load(['roles']);

        $memberships = Membership::where('user_id', $user->id)
            ->with('campaign')
            ->orderByDesc('created_at')
            ->get();

        return ApiResponse::success([
            'user'        => new UserResource($user),
            'suspension'  => $user->metadata['suspension'] ?? null,
            'activation'  => $user->metadata['activation'] ?? null,
            'memberships' => MembershipResource::collection($memberships),
        ]);
    }

    public function update(UpdateMemberRequest $request, User $user): JsonResponse
    {
        $this->assertMember($user);

        $old = $this->auditable($user);
        $user->update($request->safe()->except('activate'));
        $new = $this->auditable($user->fresh());

        // Seuls les champs réellement modifiés sont tracés
        $changed = array_keys(array_filter($new, fn ($value, $key) => $value !== $old[$key], ARRAY_FILTER_USE_BOTH));
        if ($changed) {
            $this->audit->logFromRequest($request, 'member.update', 'user', $user->id,
                array_intersect_key($old, array_flip($changed)),
                array_intersect_key($new, array_flip($changed)));
        }

        return ApiResponse::success(new UserResource($user->fresh()->load('roles')));
    }

    /**
     * Suspension du compte : bloque la connexion (AuthService exige status=active) et révoque
     * les sessions ouvertes. Ne touche ni aux adhésions, ni aux dettes, ni au ledger.
     */
    public function suspend(ReasonRequest $request, User $user): JsonResponse
    {
        $this->assertMember($user);
        if ($user->status !== UserStatus::Active) {
            return ApiResponse::error('invalid_status_transition', 'Seul un compte actif peut être suspendu.', 422);
        }

        $reason = $request->validated('reason');
        $user->update([
            'status'   => UserStatus::Suspended,
            'metadata' => [...($user->metadata ?? []), 'suspension' => [
                'reason' => $reason,
                'by'     => $request->user()->full_name,
                'at'     => now()->toIso8601String(),
            ]],
        ]);
        $user->tokens()->delete();

        $this->audit->logFromRequest($request, 'member.suspend', 'user', $user->id,
            ['status' => 'active'], ['status' => 'suspended', 'reason' => $reason]);

        return ApiResponse::success(new UserResource($user->fresh()->load('roles')));
    }

    /**
     * Activation d'un compte créé « à activer » : débloque la connexion. Le membre
     * définit ensuite lui-même son PIN dans l'application (POST /auth/pin/set).
     */
    public function activate(Request $request, User $user): JsonResponse
    {
        $this->assertMember($user);
        if ($user->status !== UserStatus::Pending) {
            return ApiResponse::error('invalid_status_transition', 'Seul un compte en attente d\'activation peut être activé.', 422);
        }

        $user->update([
            'status'   => UserStatus::Active,
            'metadata' => [...($user->metadata ?? []), 'activation' => $this->activationStamp($request)],
        ]);

        $this->audit->logFromRequest($request, 'member.activate', 'user', $user->id,
            ['status' => 'pending'], ['status' => 'active']);

        return ApiResponse::success(new UserResource($user->fresh()->load('roles')));
    }

    public function reactivate(Request $request, User $user): JsonResponse
    {
        $this->assertMember($user);
        if ($user->status !== UserStatus::Suspended) {
            return ApiResponse::error('invalid_status_transition', 'Seul un compte suspendu peut être réactivé.', 422);
        }

        $metadata = $user->metadata ?? [];
        unset($metadata['suspension']);
        $user->update(['status' => UserStatus::Active, 'metadata' => $metadata]);

        $this->audit->logFromRequest($request, 'member.reactivate', 'user', $user->id,
            ['status' => 'suspended'], ['status' => 'active']);

        return ApiResponse::success(new UserResource($user->fresh()->load('roles')));
    }

    /** Indicateurs financiers, campagnes et prochaines échéances. */
    public function overview(User $user): JsonResponse
    {
        $this->assertMember($user);

        return ApiResponse::success($this->overview->overview($user));
    }

    /**
     * Historique des opérations financières du membre (paginé côté base) :
     * cotisations, remboursements de prêt, décaissements de prêt, retraits versés.
     */
    public function transactions(Request $request, User $user): JsonResponse
    {
        $this->assertMember($user);
        $request->validate(['type' => ['nullable', 'in:contribution,loan_repayment,loan_disbursement,withdrawal']]);

        $membershipIds = Membership::where('user_id', $user->id)->pluck('id');

        $payments = DB::table('payments as p')
            ->join('memberships as m', 'm.id', '=', 'p.membership_id')
            ->join('tontine_campaigns as c', 'c.id', '=', 'm.campaign_id')
            ->whereIn('p.membership_id', $membershipIds)
            ->selectRaw("p.id, 'contribution' as type, COALESCE(p.confirmed_at, p.created_at) as date,
                p.amount_minor, c.name as campaign_name, p.channel, p.status, p.reference, 'in' as direction, p.id as link_id");

        // Avances : campaign_id peut être nul sur le prêt → campagne de l'adhésion
        $repayments = DB::table('loan_repayments as r')
            ->join('loans as l', 'l.id', '=', 'r.loan_id')
            ->leftJoin('memberships as m', 'm.id', '=', 'l.membership_id')
            ->leftJoin('tontine_campaigns as c', 'c.id', '=', DB::raw('COALESCE(l.campaign_id, m.campaign_id)'))
            ->whereIn('l.membership_id', $membershipIds)
            ->selectRaw("r.id, 'loan_repayment' as type, r.created_at as date,
                r.amount_minor, c.name as campaign_name, r.channel, 'confirmed' as status, l.reference, 'in' as direction, l.id as link_id");

        $disbursements = DB::table('loans as l')
            ->leftJoin('memberships as m', 'm.id', '=', 'l.membership_id')
            ->leftJoin('tontine_campaigns as c', 'c.id', '=', DB::raw('COALESCE(l.campaign_id, m.campaign_id)'))
            ->whereIn('l.membership_id', $membershipIds)
            ->whereNotNull('l.disbursed_at')
            ->selectRaw("l.id, 'loan_disbursement' as type, l.disbursed_at as date,
                l.principal_minor as amount_minor, c.name as campaign_name, l.disbursed_channel as channel,
                'confirmed' as status, l.reference, 'out' as direction, l.id as link_id");

        $withdrawals = DB::table('early_withdrawals as w')
            ->join('memberships as m', 'm.id', '=', 'w.membership_id')
            ->join('tontine_campaigns as c', 'c.id', '=', 'm.campaign_id')
            ->where('w.user_id', $user->id)
            ->where('w.status', EarlyWithdrawalStatus::Disbursed->value)
            ->selectRaw("w.id, 'withdrawal' as type, w.disbursed_at as date,
                w.amount_minor, c.name as campaign_name, w.disbursed_channel as channel,
                'confirmed' as status, w.reference, 'out' as direction, w.id as link_id");

        $union = $payments->unionAll($repayments)->unionAll($disbursements)->unionAll($withdrawals);

        $page = DB::query()->fromSub($union, 't')
            ->when($request->query('type'), fn ($q, $t) => $q->where('type', $t))
            ->orderByDesc('date')
            ->orderBy('id')
            ->paginate($this->perPage($request, 10));

        return ApiResponse::success($page->items(), 200, $this->pageMeta($page));
    }

    /** Demandes de prêt (et prêts associés), retraits anticipés et payouts de clôture du membre. */
    public function finance(User $user): JsonResponse
    {
        $this->assertMember($user);

        $membershipIds = Membership::where('user_id', $user->id)->pluck('id');

        $loanRequests = LoanRequest::with(['campaign:id,name', 'membership.campaign:id,name', 'decidedBy:id,full_name', 'loan'])
            ->whereIn('membership_id', $membershipIds)
            ->orderByDesc('created_at')
            ->get();

        $repaid = DB::table('loan_repayments')
            ->whereIn('loan_id', $loanRequests->pluck('loan.id')->filter())
            ->selectRaw('loan_id, SUM(amount_minor) as total')
            ->groupBy('loan_id')
            ->pluck('total', 'loan_id');

        $withdrawals = EarlyWithdrawal::with(['membership.campaign:id,name', 'decidedBy:id,full_name', 'disbursedBy:id,full_name', 'requestedBy:id,full_name'])
            ->where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->get();

        $payouts = CampaignPayout::with('campaign:id,name')
            ->whereIn('membership_id', $membershipIds)
            ->orderByDesc('created_at')
            ->get();

        return ApiResponse::success([
            'loan_requests' => $loanRequests->map(fn (LoanRequest $r) => [
                'id'              => $r->id,
                'product_type'    => $r->product_type?->value,
                'campaign_name'   => $r->campaign?->name ?? $r->membership?->campaign?->name,
                'amount_minor'    => $r->amount_minor,
                'purpose'         => $r->purpose,
                'status'          => $r->status?->value,
                'decided_by'      => $r->decidedBy?->full_name,
                'decided_at'      => $r->decided_at?->toIso8601String(),
                'rejected_reason' => $r->rejected_reason,
                'created_at'      => $r->created_at?->toIso8601String(),
                'loan'            => $r->loan ? [
                    'id'                => $r->loan->id,
                    'reference'         => $r->loan->reference,
                    'status'            => $r->loan->status?->value,
                    'principal_minor'   => $r->loan->principal_minor,
                    'outstanding_minor' => $r->loan->outstanding_minor,
                    'repaid_minor'      => (int) ($repaid[$r->loan->id] ?? 0),
                    'disbursed_at'      => $r->loan->disbursed_at?->toIso8601String(),
                ] : null,
            ]),
            'withdrawals'   => EarlyWithdrawalResource::collection($withdrawals),
            'payouts'       => $payouts->map(fn (CampaignPayout $p) => [
                'id'               => $p->id,
                'reference'        => $p->reference,
                'campaign_name'    => $p->campaign?->name,
                'net_amount_minor' => $p->net_amount_minor,
                'status'           => $p->status?->value,
                'settled_at'       => $p->settled_at?->toIso8601String(),
            ]),
        ]);
    }

    /** Historique des actions administratives sur le membre, ses adhésions et ses retraits. */
    public function history(Request $request, User $user): JsonResponse
    {
        $this->assertMember($user);

        $page = $this->audit->forEntities([
            'user'             => [$user->id],
            'membership'       => Membership::where('user_id', $user->id)->pluck('id')->all(),
            'early_withdrawal' => EarlyWithdrawal::where('user_id', $user->id)->pluck('id')->all(),
        ], $this->perPage($request, 10));

        return ApiResponse::success(
            collect($page->items())->map(fn ($log) => [
                'id'          => $log->id,
                'action'      => $log->action,
                'entity_type' => $log->entity_type,
                'user_name'   => $log->user_name,
                'old_values'  => $log->old_values,
                'new_values'  => $log->new_values,
                'created_at'  => $log->created_at?->toIso8601String(),
            ]),
            200,
            $this->pageMeta($page),
        );
    }

    public function notes(User $user): JsonResponse
    {
        $this->assertMember($user);

        $notes = MemberNote::with('author:id,full_name')
            ->where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->limit(100)
            ->get();

        return ApiResponse::success(MemberNoteResource::collection($notes));
    }

    public function addNote(StoreMemberNoteRequest $request, User $user): JsonResponse
    {
        $this->assertMember($user);

        $note = MemberNote::create([
            'user_id'   => $user->id,
            'author_id' => $request->user()->id,
            'body'      => $request->validated('body'),
        ]);

        $this->audit->logFromRequest($request, 'member.note.create', 'user', $user->id, [], ['note_id' => $note->id]);

        return ApiResponse::created(new MemberNoteResource($note->load('author:id,full_name')));
    }

    /** Les comptes du personnel ne sont pas gérés par ce module. */
    private function assertMember(User $user): void
    {
        if ($user->roles()->whereIn('name', User::STAFF_ROLES)->exists()) {
            abort(404);
        }
    }

    private function activationStamp(Request $request): array
    {
        return ['by' => $request->user()->full_name, 'at' => now()->toIso8601String()];
    }

    private function auditable(User $user): array
    {
        return [
            'phone'      => $user->phone,
            'full_name'  => $user->full_name,
            'email'      => $user->email,
            'birth_date' => $user->birth_date?->toDateString(),
            'locality'   => $user->locality,
        ];
    }

    private function perPage(Request $request, int $default): int
    {
        return min(max((int) $request->query('per_page', $default), 5), 50);
    }

    private function pageMeta($page): array
    {
        return [
            'total'        => $page->total(),
            'per_page'     => $page->perPage(),
            'current_page' => $page->currentPage(),
            'last_page'    => $page->lastPage(),
        ];
    }

    public function approveKyc(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'level' => ['required', 'integer', 'in:1,2'],
        ]);

        $old = ['kyc_status' => $user->kyc_status, 'kyc_level' => $user->kyc_level];
        $user->update([
            'kyc_status' => KycStatus::Verified,
            'kyc_level'  => max($user->kyc_level, (int) $data['level']),
        ]);

        $this->audit->logFromRequest($request, 'kyc.approve', 'user', $user->id, $old,
            ['kyc_status' => 'verified', 'kyc_level' => $user->kyc_level]);

        return ApiResponse::success(new UserResource($user->fresh()->load('roles')));
    }

    public function rejectKyc(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'in:illegible,expired,suspicious,other'],
            'notes'  => ['nullable', 'string', 'max:500'],
        ]);

        $old = ['kyc_status' => $user->kyc_status];
        $user->update(['kyc_status' => KycStatus::Rejected]);

        $this->audit->logFromRequest($request, 'kyc.reject', 'user', $user->id, $old,
            ['kyc_status' => 'rejected', 'reason' => $data['reason'], 'notes' => $data['notes'] ?? null]);

        return ApiResponse::success(new UserResource($user->fresh()->load('roles')));
    }

    public function kycDocuments(User $user): JsonResponse
    {
        $docs = $user->kyc_documents ?? [];

        $map = [
            'level1' => [
                'cni_front' => ['label' => 'CNI Recto',  'key' => 'cni_front_path'],
                'cni_back'  => ['label' => 'CNI Verso',  'key' => 'cni_back_path'],
            ],
            'level2' => [
                'selfie'           => ['label' => 'Selfie',              'key' => 'selfie_path'],
                'proof_of_address' => ['label' => 'Justificatif domicile', 'key' => 'proof_path'],
            ],
        ];

        $result = [];
        foreach ($map as $levelKey => $types) {
            $level = (int) str_replace('level', '', $levelKey);
            if (!isset($docs[$levelKey])) continue;

            $entry = $docs[$levelKey];
            foreach ($types as $type => $meta) {
                if (empty($entry[$meta['key']])) continue;
                $result[] = [
                    'level'        => $level,
                    'type'         => $type,
                    'label'        => $meta['label'],
                    'submitted_at' => $entry['submitted_at'] ?? null,
                    'download_url' => route('admin.kyc.document', [
                        'user'  => $user->id,
                        'level' => $level,
                        'type'  => $type,
                    ]),
                ];
            }
        }

        return ApiResponse::success([
            'kyc_level'  => $user->kyc_level,
            'kyc_status' => $user->kyc_status?->value,
            'documents'  => $result,
        ]);
    }

    public function kycServeDocument(User $user, int $level, string $type): \Symfony\Component\HttpFoundation\StreamedResponse|\Illuminate\Http\JsonResponse
    {
        $allowed = [
            'level1' => ['cni_front' => 'cni_front_path', 'cni_back' => 'cni_back_path'],
            'level2' => ['selfie' => 'selfie_path', 'proof_of_address' => 'proof_path'],
        ];

        $levelKey = "level{$level}";
        if (!isset($allowed[$levelKey][$type])) {
            return ApiResponse::error('not_found', 'Document inconnu.', 404);
        }

        $docs = $user->kyc_documents ?? [];
        $path = $docs[$levelKey][$allowed[$levelKey][$type]] ?? null;

        if (!$path || !\Illuminate\Support\Facades\Storage::disk('local')->exists($path)) {
            return ApiResponse::error('not_found', 'Document introuvable.', 404);
        }

        $mime = \Illuminate\Support\Facades\Storage::disk('local')->mimeType($path);

        return \Illuminate\Support\Facades\Storage::disk('local')->response($path, basename($path), [
            'Content-Type'        => $mime,
            'Content-Disposition' => 'inline; filename="' . basename($path) . '"',
            'Cache-Control'       => 'no-store',
        ]);
    }
}
