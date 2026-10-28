<?php

namespace App\Modules\Tontine\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MemberNoteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'         => $this->id,
            'body'       => $this->body,
            'author'     => $this->whenLoaded('author', fn () => $this->author?->full_name ?? '—'),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
