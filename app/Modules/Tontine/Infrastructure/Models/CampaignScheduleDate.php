<?php

namespace App\Modules\Tontine\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Date de cotisation du calendrier CONFIRMÉ d'une campagne (figé, jamais recalculé). */
class CampaignScheduleDate extends Model
{
    use HasUuids;

    protected $table = 'campaign_schedule_dates';

    protected $fillable = ['campaign_id', 'sequence', 'due_date'];

    protected $casts = ['sequence' => 'integer', 'due_date' => 'date'];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }
}
