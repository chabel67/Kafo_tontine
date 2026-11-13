<?php

namespace App\Modules\Tontine\Infrastructure\Models;

use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tentative de versement rotatif (processing → succeeded | failed), même principe que les
 * retraits (D-R9) : aucune écriture au lancement, une seule à la confirmation.
 * Une seule tentative `processing` par cible (index uniques partiels).
 */
class RotationPayoutAttempt extends Model
{
    use HasUuids;

    public const PROCESSING = 'processing';
    public const SUCCEEDED  = 'succeeded';
    public const FAILED     = 'failed';

    public const KIND_ROUND     = 'round';
    public const KIND_DEPARTURE = 'departure_refund';
    public const KIND_SURPLUS   = 'surplus';

    protected $table = 'rotation_payout_attempts';

    protected $fillable = [
        'kind', 'campaign_id', 'membership_id', 'rotation_round_id', 'rotation_departure_id',
        'attempt_no', 'idempotency_key', 'amount_minor', 'reason', 'reference', 'channel', 'phone', 'status',
        'external_reference', 'failure_reason', 'initiated_by', 'initiated_at',
        'completed_by', 'completed_at', 'ledger_transaction_id',
    ];

    protected $casts = [
        'attempt_no'   => 'integer',
        'amount_minor' => 'integer',
        'initiated_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function round(): BelongsTo
    {
        return $this->belongsTo(RotationRound::class, 'rotation_round_id');
    }

    public function departure(): BelongsTo
    {
        return $this->belongsTo(RotationDeparture::class, 'rotation_departure_id');
    }

    public function membership(): BelongsTo
    {
        return $this->belongsTo(Membership::class);
    }

    public function initiatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by');
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }
}
