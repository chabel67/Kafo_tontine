<?php

namespace App\Modules\Identity\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'phone'       => $this->phone,
            'email'       => $this->email,
            'full_name'   => $this->full_name,
            'birth_date'  => $this->birth_date?->toDateString(),
            'locality'    => $this->locality,
            // Compte créé par le staff, PIN pas encore défini par le membre (DA-12)
            'pin_set'     => $this->pin_hash !== null,
            'avatar_url'  => $this->metadata['avatar_url'] ?? null,
            'kyc_level'   => $this->kyc_level,
            'kyc_status'  => $this->kyc_status?->value,
            'status'      => $this->status?->value,
            'roles'       => $this->whenLoaded('roles', fn () => $this->getRoleNames()),
            'permissions' => $this->whenLoaded('roles', fn () => $this->collectPermissions()),
            'created_at'  => $this->created_at?->toIso8601String(),
        ];
    }

    private function collectPermissions(): array
    {
        $all = [];
        foreach ($this->roles as $role) {
            foreach ($role->permissions ?? [] as $perm) {
                if ($perm === '*') {
                    return ['*'];
                }
                $all[$perm] = true;
            }
        }
        return array_keys($all);
    }
}
