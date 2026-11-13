<?php

namespace App\Modules\Tontine\Infrastructure\Models;

use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Tontine\Domain\Enums\RotationRoundStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tour d'une campagne rotative : financé par les échéances `first_sequence`..`last_sequence`
 * de chaque membre (D-R1), distribuable à `scheduled_date` (dernière échéance, D-R2).
 */
class RotationRound extends Model
{
    use HasUuids;

    protected $table = 'rotation_rounds';

    protected $fillable = [
        'campaign_id', 'round_number', 'first_sequence', 'last_sequence', 'scheduled_date',
        'beneficiary_membership_id', 'status', 'expected_minor', 'fee_minor', 'distributed_minor',
        'reference', 'approved_by', 'approved_at', 'override_reasons', 'ledger_transaction_id', 'completed_at',
    ];

    protected $casts = [
        'status'            => RotationRoundStatus::class,
        'round_number'      => 'integer',
        'first_sequence'    => 'integer',
        'last_sequence'     => 'integer',
        'scheduled_date'    => 'date',
        'expected_minor'    => 'integer',
        'fee_minor'         => 'integer',
        'distributed_minor' => 'integer',
        'override_reasons'  => 'array',
        'approved_at'       => 'datetime',
        'completed_at'      => 'datetime',
    ];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function beneficiary(): BelongsTo
    {
        return $this->belongsTo(Membership::class, 'beneficiary_membership_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(RotationPayoutAttempt::class)->orderBy('attempt_no');
    }

    /** Montant net versé au bénéficiaire (cagnotte − retenue éventuelle, D-R5). */
    public function netMinor(): int
    {
        return max(0, $this->expected_minor - $this->fee_minor);
    }
}
