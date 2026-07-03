<?php

namespace App\Modules\Ledger\Infrastructure\Models;

use App\Modules\Ledger\Domain\Enums\AccountType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LedgerAccount extends Model
{
    use HasUuids;

    protected $table = 'ledger_accounts';

    protected $fillable = ['key', 'type', 'owner_id', 'description'];

    protected $casts = [
        'type' => AccountType::class,
    ];

    public function entries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class, 'account_key', 'key');
    }
}
