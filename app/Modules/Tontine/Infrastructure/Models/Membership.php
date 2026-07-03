<?php

namespace App\Modules\Tontine\Infrastructure\Models;

use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Tontine\Domain\Enums\MembershipStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Membership extends Model
{
    use HasUuids;

    protected $table = 'memberships';

    protected $fillable = [
        'user_id', 'campaign_id', 'status', 'committed_amount_minor',
        'validated_l1_by', 'validated_l1_at',
        'validated_l2_by', 'validated_l2_at',
        'suspended_by', 'suspended_at', 'suspension_reason',
        'activated_at',
    ];

    protected $casts = [
        'status'                  => MembershipStatus::class,
        'committed_amount_minor'  => 'integer',
        'validated_l1_at'         => 'datetime',
        'validated_l2_at'         => 'datetime',
        'suspended_at'            => 'datetime',
        'activated_at'            => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function installments(): HasMany
    {
        return $this->hasMany(Installment::class);
    }

    public function validatorL1(): BelongsTo
    {
        return $this->belongsTo(User::class, 'validated_l1_by');
    }

    public function validatorL2(): BelongsTo
    {
        return $this->belongsTo(User::class, 'validated_l2_by');
    }
}
