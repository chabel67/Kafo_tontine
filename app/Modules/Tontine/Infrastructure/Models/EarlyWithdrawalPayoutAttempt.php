<?php

namespace App\Modules\Tontine\Infrastructure\Models;

use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tentative de versement d'un retrait anticipé (processing → succeeded | failed).
 * Une seule tentative `processing` à la fois (index unique partiel en base).
 */
class EarlyWithdrawalPayoutAttempt extends Model
{
    use HasUuids;

    public const PROCESSING = 'processing';
    public const SUCCEEDED  = 'succeeded';
    public const FAILED     = 'failed';

    protected $table = 'early_withdrawal_payout_attempts';

    protected $fillable = [
        'early_withdrawal_id', 'attempt_no', 'idempotency_key', 'channel', 'phone', 'network',
        'status', 'external_reference', 'failure_reason',
        'initiated_by', 'initiated_at', 'completed_by', 'completed_at', 'ledger_transaction_id',
    ];

    protected $casts = [
        'attempt_no'   => 'integer',
        'initiated_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function withdrawal(): BelongsTo
    {
        return $this->belongsTo(EarlyWithdrawal::class, 'early_withdrawal_id');
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
