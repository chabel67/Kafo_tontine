<?php

namespace App\Modules\Tontine\Infrastructure\Models;

use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** D-R7 : départ d'un membre non encore servi (remboursement fixé par l'équipe, remplaçant facultatif). */
class RotationDeparture extends Model
{
    use HasUuids;

    public const PENDING   = 'pending';
    public const REFUNDING = 'refunding';
    public const REFUNDED  = 'refunded';
    public const CANCELLED = 'cancelled';

    protected $table = 'rotation_departures';

    protected $fillable = [
        'campaign_id', 'membership_id', 'refund_minor', 'reason', 'status',
        'replacement_membership_id', 'decided_by', 'decided_at',
    ];

    protected $casts = ['refund_minor' => 'integer', 'decided_at' => 'datetime'];

    public function membership(): BelongsTo
    {
        return $this->belongsTo(Membership::class);
    }

    public function replacement(): BelongsTo
    {
        return $this->belongsTo(Membership::class, 'replacement_membership_id');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(RotationPayoutAttempt::class)->orderBy('attempt_no');
    }
}
