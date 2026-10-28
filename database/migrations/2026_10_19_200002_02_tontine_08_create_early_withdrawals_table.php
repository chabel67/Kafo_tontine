<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Retraits anticipés d'épargne sur une adhésion active (R-WDR-*).
 *
 * status : pending → approved → disbursed | pending → rejected | pending → cancelled
 * Plafond : solde MEMBER_SAVINGS:{membership} (re-vérifié au décaissement).
 * Décaissement manuel (cash ou MoMo) : DR MEMBER_SAVINGS / CR CASH_BOX|MOMO_FLOAT.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('early_withdrawals', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('reference')->unique();
            $table->string('idempotency_key')->unique();
            $table->foreignUuid('membership_id')->constrained('memberships');
            $table->foreignUuid('user_id')->constrained('users');
            $table->integer('amount_minor');
            $table->text('reason')->nullable();
            $table->string('status', 20)->default('pending');
            $table->string('requested_via', 10);               // member | staff
            $table->foreignUuid('requested_by')->constrained('users');
            $table->foreignUuid('decided_by')->nullable()->constrained('users');
            $table->timestamp('decided_at')->nullable();
            $table->text('rejected_reason')->nullable();
            $table->foreignUuid('disbursed_by')->nullable()->constrained('users');
            $table->timestamp('disbursed_at')->nullable();
            $table->string('disbursed_channel', 20)->nullable();
            $table->string('disbursed_phone', 20)->nullable();
            $table->uuid('ledger_transaction_id')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['membership_id', 'status']);
            $table->index(['user_id']);
        });

        DB::statement('ALTER TABLE early_withdrawals ADD CONSTRAINT early_withdrawals_amount_positive CHECK (amount_minor > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('early_withdrawals');
    }
};
