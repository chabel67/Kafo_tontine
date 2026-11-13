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
            // Additif : paiements partiels (présents si la requête a chargé withPaidMinor)
            'paid_minor'             => $this->when($this->paid_minor !== null, fn () => (int) $this->paid_minor),
            'remaining_minor'        => $this->when($this->paid_minor !== null, fn () => $this->remainingMinor()),
        ];
    }
}
