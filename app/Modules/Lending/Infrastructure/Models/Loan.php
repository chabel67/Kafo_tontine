<?php

namespace App\Modules\Lending\Infrastructure\Models;

use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Lending\Domain\Enums\LoanPeriodicity;
use App\Modules\Lending\Domain\Enums\LoanProductType;
use App\Modules\Lending\Domain\Enums\LoanStatus;
use App\Modules\Tontine\Infrastructure\Models\Campaign;
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
        'product_type', 'campaign_id',
        'principal_minor', 'outstanding_minor', 'status',
        'interest_rate_bps', 'interest_total_minor',
        'periodicity', 'installments_count',
        'first_due_date', 'next_due_date', 'custom_due_dates',
        'disbursed_channel', 'disbursed_phone',
        'disbursed_by', 'disbursed_at',
        'written_off_by', 'written_off_at', 'written_off_reason',
    ];

    protected $casts = [
        'status'               => LoanStatus::class,
        'product_type'         => LoanProductType::class,
        'periodicity'          => LoanPeriodicity::class,
        'principal_minor'      => 'integer',
        'outstanding_minor'    => 'integer',
        'interest_rate_bps'    => 'integer',
        'interest_total_minor' => 'integer',
        'installments_count'   => 'integer',
        'custom_due_dates'     => 'array',
        'first_due_date'       => 'date',
        'next_due_date'        => 'date',
        'disbursed_at'         => 'datetime',
        'written_off_at'       => 'datetime',
    ];

    public function membership(): BelongsTo
    {
        return $this->belongsTo(Membership::class);
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function loanRequest(): BelongsTo
    {
        return $this->belongsTo(LoanRequest::class);
    }

    public function repayments(): HasMany
    {
        return $this->hasMany(LoanRepayment::class);
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(LoanRepaymentSchedule::class)
            ->orderBy('sequence_number');
    }
}
