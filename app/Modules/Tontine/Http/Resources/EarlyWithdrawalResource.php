<?php

namespace App\Modules\Tontine\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EarlyWithdrawalResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                   => $this->id,
            'reference'            => $this->reference,
            'membership_id'        => $this->membership_id,
            'user_id'              => $this->user_id,
            'amount_minor'         => $this->amount_minor,
            'reason'               => $this->reason,
            'payout_channel'       => $this->payout_channel,
            'payout_phone'         => $this->payout_phone,
            'payout_network'       => $this->payout_network,
            'status'               => $this->status?->value,
            'status_label'         => $this->status?->label(),
            'requested_via'        => $this->requested_via,
            'info_request_message' => $this->info_request_message,
            'info_requested_at'    => $this->info_requested_at?->toIso8601String(),
            'member_response'      => $this->member_response,
            'responded_at'         => $this->responded_at?->toIso8601String(),
            'rejected_reason'      => $this->rejected_reason,
            'disbursed_channel'    => $this->disbursed_channel,
            'disbursed_phone'      => $this->disbursed_phone,
            'disbursed_network'    => $this->disbursed_network,
            'created_at'           => $this->created_at?->toIso8601String(),
            'reviewed_at'          => $this->reviewed_at?->toIso8601String(),
            'decided_at'           => $this->decided_at?->toIso8601String(),
            'disbursed_at'         => $this->disbursed_at?->toIso8601String(),
            'cancelled_at'         => $this->cancelled_at?->toIso8601String(),
            'member'               => $this->whenLoaded('user', fn () => [
                'id'        => $this->user->id,
                'full_name' => $this->user->full_name,
                'phone'     => $this->user->phone,
            ]),
            'campaign'             => $this->whenLoaded('membership', fn () => $this->membership->relationLoaded('campaign') ? [
                'id'   => $this->membership->campaign?->id,
                'name' => $this->membership->campaign?->name,
            ] : null),
            'requested_by'         => $this->whenLoaded('requestedBy', fn () => $this->requestedBy?->full_name),
            'assigned_to'          => $this->whenLoaded('assignedTo', fn () => $this->assignedTo ? [
                'id'        => $this->assignedTo->id,
                'full_name' => $this->assignedTo->full_name,
            ] : null),
            'decided_by'           => $this->whenLoaded('decidedBy', fn () => $this->decidedBy ? [
                'id'        => $this->decidedBy->id,
                'full_name' => $this->decidedBy->full_name,
            ] : null),
            'disbursed_by'         => $this->whenLoaded('disbursedBy', fn () => $this->disbursedBy?->full_name),
            'attempts'             => $this->whenLoaded('attempts', fn () => $this->attempts->map(fn ($a) => [
                'id'                 => $a->id,
                'attempt_no'         => $a->attempt_no,
                'channel'            => $a->channel,
                'phone'              => $a->phone,
                'network'            => $a->network,
                'status'             => $a->status,
                'external_reference' => $a->external_reference,
                'failure_reason'     => $a->failure_reason,
                'initiated_by'       => $a->initiatedBy?->full_name,
                'initiated_at'       => $a->initiated_at?->toIso8601String(),
                'completed_by'       => $a->completedBy?->full_name,
                'completed_at'       => $a->completed_at?->toIso8601String(),
            ])),
        ];
    }
}
