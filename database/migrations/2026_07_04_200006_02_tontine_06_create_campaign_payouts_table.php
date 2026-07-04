<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Payouts de clôture — un par membership active à la clôture d'une campagne.
 *
 * `net_amount_minor = max(0, savings_minor − advance_offset_minor)`.
 * Cas déficitaire (advance > savings) : le reliquat est passé en
 * EXPENSE_LOSS via une transaction ledger séparée (voir R-CAMP-09).
 *
 * status : pending → settled | cancelled.
 * `metadata` stocke le snapshot ledger au moment de la génération pour audit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_payouts', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('reference')->unique();
            $table->foreignUuid('campaign_id')->constrained('tontine_campaigns');
            $table->foreignUuid('membership_id')->constrained('memberships');
            $table->integer('savings_minor');
            $table->integer('advance_offset_minor')->default(0);
            $table->integer('net_amount_minor');
            // pending → settled | cancelled
            $table->string('status', 20)->default('pending');
            $table->string('settled_channel', 20)->nullable();
            $table->string('settled_phone')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->foreignUuid('settled_by')->nullable()->constrained('users');
            $table->text('cancelled_reason')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->unique(['campaign_id', 'membership_id']);
            $table->index(['status']);
            $table->index(['campaign_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_payouts');
    }
};
