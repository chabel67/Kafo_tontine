<?php

namespace App\Modules\Tontine\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InstallmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                     => $this->id,
            'sequence_number'        => $this->sequence_number,
            'due_date'               => $this->due_date?->toDateString(),
            'grace_period_ends_date' => $this->grace_period_ends_date?->toDateString(),
            'amount_minor'           => $this->amount_minor,
            'status'                 => $this->status?->value,
            'paid_at'                => $this->paid_at?->toIso8601String(),
            'late_marked_at'         => $this->late_marked_at?->toIso8601String(),
        ];
    }
}
