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
            'product_type'          => $this->product_type?->value,
            'product_type_label'    => $this->product_type?->label(),
            'campaign_id'           => $this->campaign_id,
            'amount_minor'          => $this->amount_minor,
            'purpose'               => $this->purpose,
            'status'                => $this->status?->value,
            'status_label'          => $this->status?->label(),
            'interest_rate_bps'     => $this->interest_rate_bps,
            'periodicity'           => $this->periodicity?->value,
            'installments_count'    => $this->installments_count,
            'first_due_date'        => $this->first_due_date?->toDateString(),
            'custom_due_dates'      => $this->custom_due_dates,
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
