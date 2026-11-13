<?php

namespace App\Modules\Tontine\Infrastructure\Models;

use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Tontine\Domain\Enums\InstallmentStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

class Installment extends Model
{
    use HasUuids;

    protected $table = 'installments';

    protected $fillable = [
        'membership_id', 'campaign_id', 'sequence_number',
        'due_date', 'grace_period_ends_date', 'amount_minor', 'fee_minor', 'status',
        'penalty_minor', 'penalty_reason', 'penalty_by', 'penalty_at',
        'paid_at', 'late_marked_at', 'waived_at', 'waived_by', 'waived_reason',
    ];

    protected $casts = [
        'status'               => InstallmentStatus::class,
        'amount_minor'         => 'integer',
        'fee_minor'            => 'integer',
        'penalty_minor'        => 'integer',
        'penalty_at'           => 'datetime',
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

    /**
     * Ajoute `paid_minor` : somme déjà affectée à l'échéance (table installment_payments,
     * alimentée par Payments à la confirmation d'un paiement — R-SCHED-05).
     */
    public function scopeWithPaidMinor(Builder $query): Builder
    {
        if ($query->getQuery()->columns === null) {
            $query->select('installments.*');
        }

        return $query->addSelect(['paid_minor' => DB::table('installment_payments')
            ->selectRaw('COALESCE(SUM(amount_minor), 0)')
            ->whereColumn('installment_payments.installment_id', 'installments.id')]);
    }

    /** Total dû : montant (cotisation + complément éventuel) + pénalité décidée par le personnel (D-R10). */
    public function totalDueMinor(): int
    {
        return (int) $this->amount_minor + (int) $this->penalty_minor;
    }

    /** Reste dû : 0 pour une échéance payée ou annulée. */
    public function remainingMinor(): int
    {
        if (in_array($this->status, [InstallmentStatus::Paid, InstallmentStatus::Waived], true)) {
            return 0;
        }

        return max(0, $this->totalDueMinor() - (int) ($this->paid_minor ?? 0));
    }

    /**
     * Ventilation d'un cumul payé : pénalité d'abord, puis complément de frais, puis cotisation
     * (part de la caisse commune). Sert au grand livre et au suivi de couverture des tours.
     *
     * @return array{penalty:int, fee:int, contribution:int}
     */
    public function splitPaid(int $paid): array
    {
        $penalty      = min($paid, (int) $this->penalty_minor);
        $fee          = min(max(0, $paid - $penalty), (int) $this->fee_minor);
        $contribution = min(max(0, $paid - $penalty - $fee), (int) $this->amount_minor - (int) $this->fee_minor);

        return ['penalty' => $penalty, 'fee' => $fee, 'contribution' => $contribution];
    }
}
