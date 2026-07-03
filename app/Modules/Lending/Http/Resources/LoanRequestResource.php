<?php

namespace App\Modules\Lending\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LoanRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                    => $this->id,
            'amount_minor'          => $this->amount_minor,
            'purpose'               => $this->purpose,
            'status'                => $this->status?->value,
            'status_label'          => $this->status?->label(),
            'eligibility_snapshot'  => $this->eligibility_snapshot,
            'rejected_reason'       => $this->rejected_reason,
            'decided_at'            => $this->decided_at?->toIso8601String(),
            'countersigned_at'      => $this->countersigned_at?->toIso8601String(),
            'disbursed_at'          => $this->disbursed_at?->toIso8601String(),
            'created_at'            => $this->created_at?->toIso8601String(),
            'membership'            => $this->whenLoaded('membership', fn () => [
                'id'     => $this->membership->id,
                'user'   => [
                    'id'        => $this->membership->user?->id,
                    'full_name' => $this->membership->user?->full_name,
                    'phone'     => $this->membership->user?->phone,
                ],
                'campaign' => [
                    'id'   => $this->membership->campaign?->id,
                    'name' => $this->membership->campaign?->name,
                ],
            ]),
            'decided_by'    => $this->whenLoaded('decidedBy', fn () => [
                'id'        => $this->decidedBy?->id,
                'full_name' => $this->decidedBy?->full_name,
            ]),
            'countersigned_by' => $this->whenLoaded('countersignedBy', fn () => [
                'id'        => $this->countersignedBy?->id,
                'full_name' => $this->countersignedBy?->full_name,
            ]),
            'loan'          => $this->whenLoaded('loan', fn () =>
                $this->loan ? new LoanResource($this->loan) : null
            ),
        ];
    }
}
