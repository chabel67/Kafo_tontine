<?php

namespace App\Modules\Lending\Infrastructure\Models;

use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Lending\Domain\Enums\LoanStatus;
use App\Modules\Tontine\Infrastructure\Models\Membership;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Loan extends Model
{
    use HasUuids;

    protected $table = 'loans';

    protected $fillable = [
        'reference', 'loan_request_id', 'membership_id',
        'principal_minor', 'outstanding_minor', 'status',
        'disbursed_channel', 'disbursed_phone',
        'disbursed_by', 'disbursed_at',
        'written_off_by', 'written_off_at', 'written_off_reason',
    ];

    protected $casts = [
        'status'            => LoanStatus::class,
        'principal_minor'   => 'integer',
        'outstanding_minor' => 'integer',
        'disbursed_at'      => 'datetime',
        'written_off_at'    => 'datetime',
    ];

    public function membership(): BelongsTo
    {
        return $this->belongsTo(Membership::class);
    }

    public function loanRequest(): BelongsTo
    {
        return $this->belongsTo(LoanRequest::class);
    }

    public function repayments(): HasMany
    {
        return $this->hasMany(LoanRepayment::class);
    }
}
