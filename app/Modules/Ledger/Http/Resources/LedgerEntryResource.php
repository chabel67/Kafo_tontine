<?php

namespace App\Modules\Ledger\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LedgerEntryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'transaction_id' => $this->transaction_id,
            'reference'      => $this->transaction?->reference,
            'description'    => $this->transaction?->description,
            'account_key'    => $this->account_key,
            'entry_type'     => $this->entry_type,
            'amount_minor'   => $this->amount_minor,
            'running_balance'=> $this->when(isset($this->running_balance), fn () => $this->running_balance),
            'created_at'     => $this->created_at?->toIso8601String(),
        ];
    }
}
