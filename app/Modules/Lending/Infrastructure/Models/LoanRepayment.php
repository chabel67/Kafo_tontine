<?php

namespace App\Modules\Lending\Infrastructure\Models;

use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoanRepayment extends Model
{
    use HasUuids;

    protected $table = 'loan_repayments';

    protected $fillable = [
        'loan_id', 'idempotency_key', 'amount_minor', 'channel', 'campaign_payout_id', 'notes', 'recorded_by',
    ];

    protected $casts = [
        'amount_minor' => 'integer',
    ];

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
