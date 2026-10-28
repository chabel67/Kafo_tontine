<?php

namespace App\Modules\Tontine\Infrastructure\Models;

use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Note interne staff — append-only, jamais exposée au membre.
 */
class MemberNote extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $table = 'member_notes';

    protected $fillable = ['user_id', 'author_id', 'body'];

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
