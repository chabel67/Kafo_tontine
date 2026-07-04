<?php

namespace App\Modules\Lending\Infrastructure\Models;

use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Lending\Domain\Enums\RepaymentScheduleStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoanRepaymentSchedule extends Model
{
    use HasUuids;

    protected $table = 'loan_repayment_schedules';

    protected $fillable = [
        'loan_id', 'sequence_number', 'due_date',
        'principal_minor', 'interest_minor', 'amount_minor',
        'paid_minor', 'status',
        'paid_at', 'late_marked_at',
        'waived_by', 'waived_at',
    ];

    protected $casts = [
        'status'          => RepaymentScheduleStatus::class,
        'sequence_number' => 'integer',
        'principal_minor' => 'integer',
        'interest_minor'  => 'integer',
        'amount_minor'    => 'integer',
        'paid_minor'      => 'integer',
        'due_date'        => 'date',
        'paid_at'         => 'datetime',
        'late_marked_at'  => 'datetime',
        'waived_at'       => 'datetime',
    ];

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    public function waivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'waived_by');
    }

    /** Reste à payer sur cet item. */
    public function remainingMinor(): int
    {
        return max(0, $this->amount_minor - $this->paid_minor);
    }
}
