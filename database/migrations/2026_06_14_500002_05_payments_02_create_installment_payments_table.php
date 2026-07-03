<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('installment_payments', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('payment_id')->constrained('payments');
            $table->foreignUuid('installment_id')->constrained('installments');
            $table->unsignedBigInteger('amount_minor'); // portion allouée à cette échéance
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['payment_id', 'installment_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('installment_payments');
    }
};
