<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('transaction_id')->constrained('ledger_transactions');
            $table->string('account_key');
            $table->foreign('account_key')->references('key')->on('ledger_accounts');
            $table->string('entry_type');             // 'debit' | 'credit'
            $table->unsignedBigInteger('amount_minor'); // > 0 enforced by constraint
            $table->timestamp('created_at')->useCurrent();

            $table->index(['account_key', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_entries');
    }
};
