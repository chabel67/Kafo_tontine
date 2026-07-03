<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('reference')->unique();           // PAY-2026-000001
            $table->string('idempotency_key')->unique();
            $table->foreignUuid('membership_id')->constrained('memberships');
            $table->string('channel');                       // cash | mtn | orange | moov | wave
            $table->string('psp_reference')->nullable();
            $table->unique(['channel', 'psp_reference']);
            $table->string('status');                        // pending | confirmed | failed | cancelled
            $table->unsignedBigInteger('amount_minor');
            $table->string('phone')->nullable();             // numéro MoMo utilisé
            $table->text('notes')->nullable();               // note trésorier
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
