<?php

namespace App\Modules\Lending\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LoanResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                   => $this->id,
            'reference'            => $this->reference,
            'product_type'         => $this->product_type?->value,
            'product_type_label'   => $this->product_type?->label(),
            'campaign_id'          => $this->campaign_id,
            'principal_minor'      => $this->principal_minor,
            'outstanding_minor'    => $this->outstanding_minor,
            // Reste dû total (principal + intérêts restants) : borne d'un remboursement.
            'total_due_minor'      => $this->resource->totalDueMinor(),
            'user_id'              => $this->user_id,
            // Emprunteur (toujours renseigné, y compris pour un prêt communautaire sans adhésion).
            'user'                 => $this->user_id ? [
                'id'        => $this->user?->id,
                'full_name' => $this->user?->full_name,
                'phone'     => $this->user?->phone,
            ] : null,
            'interest_rate_bps'    => $this->interest_rate_bps,
            'interest_total_minor' => $this->interest_total_minor,
            'periodicity'          => $this->periodicity?->value,
            'installments_count'   => $this->installments_count,
            'first_due_date'       => $this->first_due_date?->toDateString(),
            'next_due_date'        => $this->next_due_date?->toDateString(),
            'status'               => $this->status?->value,
            'status_label'         => $this->status?->label(),
            'disbursed_channel'    => $this->disbursed_channel,
            'disbursed_phone'      => $this->disbursed_phone,
            'disbursed_network'    => $this->disbursed_network,
            'disbursed_at'         => $this->disbursed_at?->toIso8601String(),
            // R-DOC-01 : attestation d'octroi téléchargeable (GET …/loans/{id}/attestation).
            'attestation_available' => \App\Modules\Lending\Application\LoanAttestationService::isAvailable($this->resource),
            'written_off_at'       => $this->written_off_at?->toIso8601String(),
            'written_off_reason'   => $this->written_off_reason,
            'created_at'           => $this->created_at?->toIso8601String(),
            'membership'        => $this->whenLoaded('membership', fn () => [
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
            'repayments'        => $this->whenLoaded('repayments', fn () =>
                $this->repayments->map(fn ($r) => [
                    'id'           => $r->id,
                    'amount_minor' => $r->amount_minor,
                    'channel'      => $r->channel,
                    'notes'        => $r->notes,
                    'created_at'   => $r->created_at?->toIso8601String(),
                ])
            ),
            'schedules'         => $this->whenLoaded('schedules', fn () =>
                LoanRepaymentScheduleResource::collection($this->schedules)
            ),
        ];
    }
}
