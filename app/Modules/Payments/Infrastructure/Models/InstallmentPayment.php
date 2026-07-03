<?php

namespace App\Modules\Payments\Infrastructure\Models;

use App\Modules\Tontine\Infrastructure\Models\Installment;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InstallmentPayment extends Model
{
    use HasUuids;

    protected $table      = 'installment_payments';
    public    $timestamps = false;

    protected $fillable = ['payment_id', 'installment_id', 'amount_minor'];

    protected $casts = [
        'amount_minor' => 'integer',
        'created_at'   => 'datetime',
    ];

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function installment(): BelongsTo
    {
        return $this->belongsTo(Installment::class);
    }
}
