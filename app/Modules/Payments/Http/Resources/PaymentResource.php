<?php

namespace App\Modules\Payments\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->id,
            'reference'       => $this->reference,
            'channel'         => $this->channel?->value,
            'channel_label'   => $this->channel?->label(),
            'status'          => $this->status?->value,
            'status_label'    => $this->status?->label(),
            'amount_minor'    => $this->amount_minor,
            'phone'           => $this->phone,
            'notes'           => $this->notes,
            'psp_reference'   => $this->psp_reference,
            'confirmed_at'    => $this->confirmed_at?->toIso8601String(),
            'failed_at'       => $this->failed_at?->toIso8601String(),
            'created_at'      => $this->created_at?->toIso8601String(),
            'membership'      => $this->whenLoaded('membership', fn () => [
                'id'       => $this->membership->id,
                'user'     => [
                    'id'        => $this->membership->user?->id,
                    'full_name' => $this->membership->user?->full_name,
                    'phone'     => $this->membership->user?->phone,
                ],
                'campaign' => [
                    'id'   => $this->membership->campaign?->id,
                    'name' => $this->membership->campaign?->name,
                ],
            ]),
            'installment_payments' => $this->whenLoaded('installmentPayments', fn () =>
                $this->installmentPayments->map(fn ($ip) => [
                    'installment_id'  => $ip->installment_id,
                    'sequence_number' => $ip->installment?->sequence_number,
                    'amount_minor'    => $ip->amount_minor,
                ])
            ),
        ];
    }
}
