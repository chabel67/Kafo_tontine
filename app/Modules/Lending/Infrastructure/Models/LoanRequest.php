<?php

namespace App\Modules\Lending\Infrastructure\Models;

use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Lending\Domain\Enums\LoanRequestStatus;
use App\Modules\Tontine\Infrastructure\Models\Membership;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class LoanRequest extends Model
{
    use HasUuids;

    protected $table = 'loan_requests';

    protected $fillable = [
        'membership_id', 'amount_minor', 'purpose', 'status',
        'eligibility_snapshot', 'created_by',
        'decided_by', 'decided_at',
        'countersigned_by', 'countersigned_at',
        'disbursed_by', 'disbursed_at',
        'rejected_reason',
    ];

    protected $casts = [
        'status'                => LoanRequestStatus::class,
        'amount_minor'          => 'integer',
        'eligibility_snapshot'  => 'array',
        'decided_at'            => 'datetime',
        'countersigned_at'      => 'datetime',
        'disbursed_at'          => 'datetime',
    ];

    public function membership(): BelongsTo
    {
        return $this->belongsTo(Membership::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function countersignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'countersigned_by');
    }

    public function loan(): HasOne
    {
        return $this->hasOne(Loan::class);
    }
}
