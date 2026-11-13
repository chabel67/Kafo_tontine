<?php

namespace App\Modules\Tontine\Infrastructure\Models;

use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Trace d'une reconduction : cycle source → nouveau cycle, composition et configuration retenues. */
class CampaignRenewal extends Model
{
    use HasUuids;

    protected $table = 'campaign_renewals';

    protected $fillable = ['source_campaign_id', 'target_campaign_id', 'idempotency_key', 'roster', 'config_changes', 'created_by'];

    protected $casts = ['roster' => 'array', 'config_changes' => 'array'];

    public function source(): BelongsTo
    {
        return $this->belongsTo(Campaign::class, 'source_campaign_id');
    }

    public function target(): BelongsTo
    {
        return $this->belongsTo(Campaign::class, 'target_campaign_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
