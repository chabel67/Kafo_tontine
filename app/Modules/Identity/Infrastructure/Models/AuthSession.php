<?php

namespace App\Modules\Identity\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuthSession extends Model
{
    protected $table = 'auth_sessions';

    protected $fillable = [
        'user_id', 'token_hash', 'ip_address',
        'user_agent', 'expires_at', 'last_activity_at', 'revoked_at',
    ];

    protected $casts = [
        'expires_at'       => 'datetime',
        'last_activity_at' => 'datetime',
        'revoked_at'       => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null && $this->expires_at->isFuture();
    }
}
