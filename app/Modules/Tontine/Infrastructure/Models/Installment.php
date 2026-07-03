<?php

namespace App\Modules\Tontine\Infrastructure\Models;

use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Tontine\Domain\Enums\InstallmentStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Installment extends Model
{
    use HasUuids;

    protected $table = 'installments';

    protected $fillable = [
        'membership_id', 'campaign_id', 'sequence_number',
        'due_date', 'grace_period_ends_date', 'amount_minor', 'status',
        'paid_at', 'late_marked_at', 'waived_at', 'waived_by', 'waived_reason',
    ];

    protected $casts = [
        'status'               => InstallmentStatus::class,
        'amount_minor'         => 'integer',
        'sequence_number'      => 'integer',
        'due_date'             => 'date',
        'grace_period_ends_date' => 'date',
        'paid_at'              => 'datetime',
        'late_marked_at'       => 'datetime',
        'waived_at'            => 'datetime',
    ];

    public function membership(): BelongsTo
    {
        return $this->belongsTo(Membership::class);
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function waivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'waived_by');
    }
}
