<?php

namespace App\Modules\Tontine\Http\Resources;

use App\Modules\Identity\Http\Resources\UserResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MembershipResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                      => $this->id,
            'status'                  => $this->status?->value,
            'status_label'            => $this->status?->label(),
            'committed_amount_minor'  => $this->committed_amount_minor,
            // R-FEE-02 : frais d'adhésion dus / payés / restant (jamais de l'épargne).
            'membership_fee'          => \App\Modules\Tontine\Application\MembershipService::feeStatus($this->resource),
            'user'             => $this->whenLoaded('user', fn () => new UserResource($this->user)),
            'campaign'         => $this->whenLoaded('campaign', fn () => [
                'id'   => $this->campaign->id,
                'name' => $this->campaign->name,
                'campaign_type' => $this->campaign->campaign_type?->value ?? 'savings',
                'cycle_number'  => (int) ($this->campaign->cycle_number ?? 1),
                'status'        => $this->campaign->status?->value,
            ]),
            'validated_l1_at'  => $this->validated_l1_at?->toIso8601String(),
            'validated_l2_at'  => $this->validated_l2_at?->toIso8601String(),
            'suspended_at'     => $this->suspended_at?->toIso8601String(),
            'suspension_reason' => $this->suspension_reason,
            'activated_at'     => $this->activated_at?->toIso8601String(),
            'created_at'       => $this->created_at?->toIso8601String(),
        ];
    }
}
