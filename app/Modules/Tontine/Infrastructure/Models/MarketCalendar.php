<?php

namespace App\Modules\Tontine\Infrastructure\Models;

use App\Modules\Tontine\Domain\Enums\CadenceType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MarketCalendar extends Model
{
    protected $table = 'market_calendars';

    protected $fillable = ['name', 'cadence_type', 'cadence_value', 'timezone', 'description'];

    protected $casts = [
        'cadence_type'  => CadenceType::class,
        'cadence_value' => 'array',
    ];

    public function campaigns(): HasMany
    {
        return $this->hasMany(Campaign::class);
    }
}
