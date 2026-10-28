<?php

namespace App\Modules\Tontine\Infrastructure\Models;

use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Tontine\Domain\Enums\EarlyWithdrawalStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EarlyWithdrawal extends Model
{
    use HasUuids;

    protected $table = 'early_withdrawals';

    protected $fillable = [
        'reference', 'idempotency_key', 'membership_id', 'user_id',
        'amount_minor', 'reason', 'payout_channel', 'payout_phone', 'status', 'requested_via', 'requested_by',
        'assigned_to', 'reviewed_at', 'info_request_message', 'info_requested_at', 'member_response', 'responded_at',
        'decided_by', 'decided_at', 'rejected_reason',
        'disbursed_by', 'disbursed_at', 'disbursed_channel', 'disbursed_phone',
        'ledger_transaction_id', 'cancelled_at',
    ];

    protected $casts = [
        'status'       => EarlyWithdrawalStatus::class,
        'amount_minor' => 'integer',
        'decided_at'   => 'datetime',
        'disbursed_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'reviewed_at'       => 'datetime',
        'info_requested_at' => 'datetime',
        'responded_at'      => 'datetime',
    ];

    public function membership(): BelongsTo
    {
        return $this->belongsTo(Membership::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(EarlyWithdrawalPayoutAttempt::class)->orderBy('attempt_no');
    }

    public function disbursedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'disbursed_by');
    }
}
