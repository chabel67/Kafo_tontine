<?php

namespace App\Modules\Identity\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;

class OtpCode extends Model
{
    protected $table = 'otp_codes';

    protected $fillable = [
        'phone', 'purpose', 'code_hash', 'attempts',
        'ip_address', 'expires_at', 'consumed_at',
    ];

    protected $casts = [
        'expires_at'   => 'datetime',
        'consumed_at'  => 'datetime',
        'attempts'     => 'integer',
    ];

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isConsumed(): bool
    {
        return $this->consumed_at !== null;
    }

    public function isValid(): bool
    {
        return ! $this->isExpired() && ! $this->isConsumed();
    }
}
