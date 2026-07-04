<?php

namespace App\Modules\Lending\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LoanRepaymentScheduleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->id,
            'loan_id'         => $this->loan_id,
            'sequence_number' => $this->sequence_number,
            'due_date'        => $this->due_date?->toDateString(),
            'principal_minor' => $this->principal_minor,
            'interest_minor'  => $this->interest_minor,
            'amount_minor'    => $this->amount_minor,
            'paid_minor'      => $this->paid_minor,
            'remaining_minor' => $this->remainingMinor(),
            'status'          => $this->status?->value,
            'status_label'    => $this->status?->label(),
            'paid_at'         => $this->paid_at?->toIso8601String(),
            'late_marked_at'  => $this->late_marked_at?->toIso8601String(),
            'waived_at'       => $this->waived_at?->toIso8601String(),
        ];
    }
}
