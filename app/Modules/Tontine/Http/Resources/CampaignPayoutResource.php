<?php

namespace App\Modules\Tontine\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CampaignPayoutResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                    => $this->id,
            'reference'             => $this->reference,
            'campaign_id'           => $this->campaign_id,
            'membership_id'         => $this->membership_id,
            'savings_minor'         => $this->savings_minor,
            'advance_offset_minor'  => $this->advance_offset_minor,
            'withdrawals_disbursed_minor' => $this->withdrawals_disbursed_minor,
            'net_amount_minor'      => $this->net_amount_minor,
            // R-CAMP-14 : part du net affectée au remboursement d'un prêt communautaire.
            'loan_repayment_minor'  => $this->loan_repayment_minor,
            'loan_repayment_id'     => $this->loan_repayment_id,
            'paid_out_minor'        => $this->net_amount_minor - $this->loan_repayment_minor,
            'status'                => $this->status?->value,
            'status_label'          => $this->status?->label(),
            'settled_channel'       => $this->settled_channel,
            'settled_phone'         => $this->settled_phone,
            'settled_network'       => $this->settled_network,
            'settled_at'            => $this->settled_at?->toIso8601String(),
            'cancelled_reason'      => $this->cancelled_reason,
            'metadata'              => $this->metadata,
            'created_at'            => $this->created_at?->toIso8601String(),
            'campaign' => $this->whenLoaded('campaign', fn () => [
                'id'   => $this->campaign?->id,
                'name' => $this->campaign?->name,
            ]),
            'membership' => $this->whenLoaded('membership', fn () => [
                'id'      => $this->membership?->id,
                'user_id' => $this->membership?->user_id,
                'user'    => [
                    'id'        => $this->membership?->user?->id,
                    'full_name' => $this->membership?->user?->full_name,
                    'phone'     => $this->membership?->user?->phone,
                ],
            ]),
        ];
    }
}
