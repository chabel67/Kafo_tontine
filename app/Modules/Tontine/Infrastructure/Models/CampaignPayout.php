<?php

namespace App\Modules\Tontine\Infrastructure\Models;

use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Tontine\Domain\Enums\CampaignPayoutStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CampaignPayout extends Model
{
    use HasUuids;

    protected $table = 'campaign_payouts';

    protected $fillable = [
        'reference', 'campaign_id', 'membership_id',
        'savings_minor', 'advance_offset_minor', 'net_amount_minor',
        'status', 'settled_channel', 'settled_phone',
        'settled_at', 'settled_by', 'cancelled_reason',
        'metadata',
    ];

    protected $casts = [
        'status'               => CampaignPayoutStatus::class,
        'savings_minor'        => 'integer',
        'advance_offset_minor' => 'integer',
        'net_amount_minor'     => 'integer',
        'metadata'             => 'array',
        'settled_at'           => 'datetime',
    ];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function membership(): BelongsTo
    {
        return $this->belongsTo(Membership::class);
    }

    public function settledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'settled_by');
    }
}
