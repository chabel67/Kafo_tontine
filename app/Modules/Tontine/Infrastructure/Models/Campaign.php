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
        'market_calendar_id', 'created_by', 'name', 'description', 'status',
        'currency', 'contribution_mode',
        'contribution_amount_minor', 'min_contribution_minor', 'max_contribution_minor',
        'duration_installments', 'first_installment_date', 'grace_period_days',
        'min_membership_age_days', 'min_trust_score', 'maker_checker_threshold_minor',
        'max_members', 'rules', 'opened_at', 'activated_at', 'closed_at',
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
        'first_installment_date'        => 'date',
        'opened_at'                     => 'datetime',
        'activated_at'                  => 'datetime',
        'closed_at'                     => 'datetime',
        'rules'                         => 'array',
    ];

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
