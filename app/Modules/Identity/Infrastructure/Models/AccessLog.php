<?php

namespace App\Modules\Identity\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;

class AccessLog extends Model
{
    protected $table = 'access_logs';
    public    $timestamps = false;

    protected $fillable = ['user_id', 'event', 'ip_address', 'user_agent', 'context', 'created_at'];

    protected $casts = [
        'context'    => 'array',
        'created_at' => 'datetime',
    ];

    public static function record(
        ?string $userId,
        string $event,
        ?string $ip = null,
        ?string $userAgent = null,
        array $context = [],
    ): void {
        static::create([
            'user_id'    => $userId,
            'event'      => $event,
            'ip_address' => $ip,
            'user_agent' => $userAgent,
            'context'    => $context ?: null,
            'created_at' => now(),
        ]);
    }
}
