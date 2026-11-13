<?php

namespace App\Modules\Tontine\Infrastructure\Models;

use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Tontine\Domain\Enums\CampaignStatus;
use App\Modules\Tontine\Domain\Enums\ContributionMode;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Campaign extends Model
{
    use HasUuids;

    protected $table = 'tontine_campaigns';

    protected $fillable = [
        'market_calendar_id', 'created_by', 'name', 'description', 'status', 'campaign_type',
        'series_id', 'cycle_number', 'previous_campaign_id',
        'installments_per_round', 'rotation_fee_mode', 'rotation_fee_minor', 'contribution_schedule',
        'schedule_confirmed_at', 'started_at', 'rotation_surplus_revenue_minor',
        'currency', 'contribution_mode',
        'contribution_amount_minor', 'min_contribution_minor', 'max_contribution_minor',
        'duration_installments', 'first_installment_date', 'grace_period_days',
        'min_membership_age_days', 'min_trust_score', 'maker_checker_threshold_minor',
        'max_members', 'allow_early_withdrawals', 'membership_fee_minor', 'rules', 'opened_at', 'activated_at', 'closed_at',
    ];

    protected $casts = [
        'status'                        => CampaignStatus::class,
        'contribution_mode'             => ContributionMode::class,
        'contribution_amount_minor'     => 'integer',
        'min_contribution_minor'        => 'integer',
        'max_contribution_minor'        => 'integer',
        'duration_installments'         => 'integer',
        'grace_period_days'             => 'integer',
        'min_membership_age_days'       => 'integer',
        'min_trust_score'               => 'integer',
        'maker_checker_threshold_minor' => 'integer',
        'max_members'                   => 'integer',
        'allow_early_withdrawals'       => 'boolean',
        'membership_fee_minor'          => 'integer',
        'first_installment_date'        => 'date',
        'opened_at'                     => 'datetime',
        'activated_at'                  => 'datetime',
        'closed_at'                     => 'datetime',
        'rules'                         => 'array',
        'campaign_type'                 => \App\Modules\Tontine\Domain\Enums\CampaignType::class,
        'installments_per_round'        => 'integer',
        'rotation_fee_mode'             => \App\Modules\Tontine\Domain\Enums\RotationFeeMode::class,
        'rotation_fee_minor'            => 'integer',
        'contribution_schedule'         => 'array',
        'schedule_confirmed_at'         => 'datetime',
        'started_at'                    => 'datetime',
        'rotation_surplus_revenue_minor' => 'integer',
        'cycle_number'                  => 'integer',
    ];

    /** Reconduction (R1) : une nouvelle campagne démarre une série, sauf si elle en prolonge une. */
    protected static function booted(): void
    {
        static::creating(function (self $c) {
            if (! $c->id) {
                $c->id = (string) \Illuminate\Support\Str::uuid();
            }
            $c->series_id ??= $c->id;
        });
    }

    public function previousCycle(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(self::class, 'previous_campaign_id');
    }

    public function nextCycle(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(self::class, 'previous_campaign_id');
    }

    public function isRotating(): bool
    {
        return $this->campaign_type === \App\Modules\Tontine\Domain\Enums\CampaignType::Rotating;
    }

    /** Calendrier des cotisations confirmé (campagne rotative). */
    public function scheduleDates(): HasMany
    {
        return $this->hasMany(CampaignScheduleDate::class)->orderBy('sequence');
    }

    public function rounds(): HasMany
    {
        return $this->hasMany(RotationRound::class)->orderBy('round_number');
    }

    public function isFixedMode(): bool
    {
        return $this->contribution_mode === ContributionMode::Fixed;
    }

    public function isCustomMode(): bool
    {
        return $this->contribution_mode === ContributionMode::Custom;
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function marketCalendar(): BelongsTo
    {
        return $this->belongsTo(MarketCalendar::class);
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    public function activeMembershipsCount(): int
    {
        return $this->memberships()->where('status', 'active')->count();
    }
}
