<?php

namespace App\Modules\Ledger\Http\Resources;

use App\Modules\Ledger\Application\LedgerService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LedgerAccountResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'key'         => $this->key,
            'type'        => $this->type?->value,
            'type_label'  => $this->type?->label(),
            'owner_id'    => $this->owner_id,
            'description' => $this->description,
            'balance_minor' => $this->when(
                isset($this->balance_minor),
                fn () => $this->balance_minor,
            ),
            'created_at'  => $this->created_at?->toIso8601String(),
        ];
    }
}
