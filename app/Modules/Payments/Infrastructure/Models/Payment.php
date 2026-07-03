<?php

namespace App\Modules\Payments\Infrastructure\Models;

use App\Modules\Payments\Domain\Enums\PaymentChannel;
use App\Modules\Payments\Domain\Enums\PaymentStatus;
use App\Modules\Tontine\Infrastructure\Models\Membership;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Payment extends Model
{
    use HasUuids;

    protected $table = 'payments';

    protected $fillable = [
        'reference', 'idempotency_key', 'membership_id',
        'channel', 'psp_reference', 'status',
        'amount_minor', 'phone', 'notes',
        'confirmed_at', 'failed_at', 'created_by', 'metadata',
    ];

    protected $casts = [
        'channel'      => PaymentChannel::class,
        'status'       => PaymentStatus::class,
        'amount_minor' => 'integer',
        'confirmed_at' => 'datetime',
        'failed_at'    => 'datetime',
        'metadata'     => 'array',
    ];

    public function membership(): BelongsTo
    {
        return $this->belongsTo(Membership::class);
    }

    public function installmentPayments(): HasMany
    {
        return $this->hasMany(InstallmentPayment::class);
    }
}
