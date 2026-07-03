<?php

namespace App\Modules\Notifications\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class InAppNotification extends Model
{
    use HasUuids;

    protected $table = 'in_app_notifications';

    protected $fillable = [
        'user_id', 'type', 'title', 'body', 'action_url', 'read_at',
    ];

    protected $casts = [
        'read_at' => 'datetime',
    ];
}
