<?php

namespace App\Modules\Tontine\Http\Controllers;

use App\Modules\Audit\Application\AuditService;
use App\Modules\Identity\Domain\Enums\KycStatus;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Http\Resources\UserResource;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Tontine\Http\Resources\MembershipResource;
use App\Shared\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;

class AdminMemberController extends Controller
{
    public function __construct(private readonly AuditService $audit) {}

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone'     => ['required', 'string', 'regex:/^\+\d{7,15}$/', Rule::unique('users', 'phone')],
            'full_name' => ['required', 'string', 'max:150'],
        ], [
            'phone.required' => 'Le numéro de téléphone est obligatoire.',
            'phone.regex'    => 'Le numéro doit être au format international (+229…).',
            'phone.unique'   => 'Ce numéro de téléphone est déjà utilisé.',
            'full_name.required' => 'Le nom complet est obligatoire.',
            'full_name.max'      => 'Le nom ne doit pas dépasser 150 caractères.',
        ]);

        $user = User::create([
            'phone'     => $data['phone'],
            'full_name' => $data['full_name'],
            'status'    => UserStatus::Active,
            'kyc_level' => 0,
        ]);

        return ApiResponse::created(new UserResource($user->load('roles')));
    }

    public function index(Request $request): JsonResponse
    {
        $members = User::query()
            ->with('roles')
            ->when($request->query('search'), fn ($q, $s) =>
                $q->where('full_name', 'ilike', "%{$s}%")->orWhere('phone', 'ilike', "%{$s}%")
            )
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->orderByDesc('created_at')
            ->paginate(10);

        return ApiResponse::success(
            UserResource::collection($members)->response()->getData(true)['data'],
            200,
            [
                'total'        => $members->total(),
                'per_page'     => $members->perPage(),
                'current_page' => $members->currentPage(),
                'last_page'    => $members->lastPage(),
            ],
        );
    }

    public function show(User $user): JsonResponse
    {
        $user->load(['roles']);

        $memberships = \App\Modules\Tontine\Infrastructure\Models\Membership::where('user_id', $user->id)
            ->with('campaign')
            ->orderByDesc('created_at')
            ->get();

        return ApiResponse::success([
            'user'        => new UserResource($user),
            'memberships' => MembershipResource::collection($memberships),
        ]);
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
