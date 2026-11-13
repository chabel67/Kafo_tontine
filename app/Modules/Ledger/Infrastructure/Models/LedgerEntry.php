<?php

namespace App\Modules\Ledger\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LedgerEntry extends Model
{
    use HasUuids;

    protected $table      = 'ledger_entries';
    public    $timestamps = false;

    // created_at posé explicitement par LedgerService (UTC, comme le reste du schéma).
    protected $fillable = ['transaction_id', 'account_key', 'entry_type', 'amount_minor', 'created_at'];

    protected $casts = [
        'amount_minor' => 'integer',
        'created_at'   => 'datetime',
    ];

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(LedgerTransaction::class, 'transaction_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'account_key', 'key');
    }
}
