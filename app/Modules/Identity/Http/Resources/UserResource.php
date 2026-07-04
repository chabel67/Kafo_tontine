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
            'full_name'   => $this->full_name,
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
