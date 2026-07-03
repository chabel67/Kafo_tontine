<?php

namespace App\Modules\Identity\Infrastructure\Models;

use App\Modules\Identity\Domain\Enums\KycStatus;
use App\Modules\Identity\Domain\Enums\UserStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasUuids, SoftDeletes;

    protected $table = 'users';

    protected $fillable = [
        'phone', 'full_name', 'pin_hash', 'kyc_level',
        'kyc_status', 'status', 'kyc_documents', 'metadata',
    ];

    protected $hidden = ['pin_hash'];

    protected $casts = [
        'kyc_level'     => 'integer',
        'kyc_status'    => KycStatus::class,
        'status'        => UserStatus::class,
        'kyc_documents' => 'array',
        'metadata'      => 'array',
    ];

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_user')
            ->withPivot('campaign_id')
            ->withTimestamps();
    }

    public function hasPermission(string $permission, ?string $campaignId = null): bool
    {
        foreach ($this->roles as $role) {
            $permissions = $role->permissions ?? [];

            if (in_array('*', $permissions, true)) {
                return true;
            }

            if ($campaignId && $role->pivot->campaign_id !== $campaignId) {
                continue;
            }

            if (in_array($permission, $permissions, true)) {
                return true;
            }
        }

        return false;
    }

    public function getRoleNames(): array
    {
        return $this->roles->pluck('name')->all();
    }
}
