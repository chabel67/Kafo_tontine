<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Audit\Application\AuditService;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Http\Resources\UserResource;
use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Shared\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;

class AdminUsersController extends Controller
{
    public function __construct(private readonly AuditService $audit) {}

    public function index(Request $request): JsonResponse
    {
        $staffRoles = ['treasurer', 'manager', 'admin', 'super_admin'];

        $users = User::query()
            ->whereHas('roles', fn ($q) => $q->whereIn('name', $staffRoles))
            ->with('roles')
            ->when($request->query('search'), fn ($q, $s) =>
                $q->where('full_name', 'ilike', "%{$s}%")->orWhere('phone', 'ilike', "%{$s}%")
            )
            ->when($request->query('role'), fn ($q, $role) =>
                $q->whereHas('roles', fn ($r) => $r->where('name', $role))
            )
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->orderBy('full_name')
            ->paginate(10);

        return ApiResponse::success(
            UserResource::collection($users)->response()->getData(true)['data'],
            200,
            [
                'total'        => $users->total(),
                'per_page'     => $users->perPage(),
                'current_page' => $users->currentPage(),
                'last_page'    => $users->lastPage(),
            ],
        );
    }

    public function show(User $user): JsonResponse
    {
        $user->load('roles');
        return ApiResponse::success(new UserResource($user));
    }

    public function create(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone'     => ['required', 'string', 'regex:/^\+\d{7,15}$/', 'unique:users,phone'],
            'full_name' => ['required', 'string', 'min:2', 'max:100'],
            'role'      => ['required', 'string', 'in:treasurer,manager,admin,super_admin'],
        ]);

        $user = User::create([
            'phone'      => $data['phone'],
            'full_name'  => $data['full_name'],
            'kyc_level'  => 2,
            'kyc_status' => 'verified',
            'status'     => UserStatus::Active,
        ]);

        $role = Role::where('name', $data['role'])->firstOrFail();
        $user->roles()->attach($role->id);

        $this->audit->logFromRequest($request, 'admin_user.create', 'user', $user->id,
            [], ['phone' => $user->phone, 'full_name' => $user->full_name, 'role' => $data['role']]);

        return ApiResponse::created(new UserResource($user->load('roles')));
    }

    public function assignRole(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'role'        => ['required', 'string', 'in:treasurer,manager,admin,super_admin'],
            'campaign_id' => ['nullable', 'uuid', 'exists:tontine_campaigns,id'],
        ]);

        $role = Role::where('name', $data['role'])->firstOrFail();
        $user->roles()->syncWithoutDetaching([
            $role->id => ['campaign_id' => $data['campaign_id'] ?? null],
        ]);

        $this->audit->logFromRequest($request, 'admin_user.assign_role', 'user', $user->id,
            [], ['role' => $data['role'], 'campaign_id' => $data['campaign_id'] ?? null]);

        return ApiResponse::success(new UserResource($user->load('roles')));
    }

    public function removeRole(Request $request, User $user, string $roleName): JsonResponse
    {
        $role = Role::where('name', $roleName)->firstOrFail();
        $user->roles()->detach($role->id);

        $this->audit->logFromRequest($request, 'admin_user.remove_role', 'user', $user->id,
            ['role' => $roleName], []);

        return ApiResponse::success(new UserResource($user->load('roles')));
    }

    public function deactivate(Request $request, User $user): JsonResponse
    {
        $request->validate(['reason' => ['required', 'string', 'min:10']]);

        if ($user->id === $request->user()->id) {
            return response()->json([
                'error' => ['code' => 'cannot_deactivate_self', 'message' => 'Vous ne pouvez pas vous désactiver vous-même.'],
            ], 422);
        }

        $old = $user->status;
        $user->update(['status' => UserStatus::Suspended]);

        $this->audit->logFromRequest($request, 'admin_user.deactivate', 'user', $user->id,
            ['status' => $old], ['status' => 'suspended', 'reason' => $request->input('reason')]);

        return ApiResponse::success(new UserResource($user->load('roles')));
    }

    public function reactivate(Request $request, User $user): JsonResponse
    {
        $old = $user->status;
        $user->update(['status' => UserStatus::Active]);

        $this->audit->logFromRequest($request, 'admin_user.reactivate', 'user', $user->id,
            ['status' => $old], ['status' => 'active']);

        return ApiResponse::success(new UserResource($user->load('roles')));
    }

    public function roles(): JsonResponse
    {
        $roles = Role::whereIn('name', ['treasurer', 'manager', 'admin', 'super_admin'])
            ->select('name', 'label')
            ->get();

        return ApiResponse::success($roles);
    }
}
