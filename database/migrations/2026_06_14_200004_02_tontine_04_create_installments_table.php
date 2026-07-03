<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('installments', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('membership_id');
            $table->uuid('campaign_id');
            $table->unsignedSmallInteger('sequence_number');
            $table->date('due_date');
            $table->date('grace_period_ends_date');
            $table->unsignedBigInteger('amount_minor');
            $table->string('status', 20)->default('pending');
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('late_marked_at')->nullable();
            $table->timestamp('waived_at')->nullable();
            $table->uuid('waived_by')->nullable();
            $table->text('waived_reason')->nullable();
            $table->timestamps();

            $table->foreign('membership_id')->references('id')->on('memberships')->cascadeOnDelete();
            $table->foreign('campaign_id')->references('id')->on('tontine_campaigns')->cascadeOnDelete();
            $table->foreign('waived_by')->references('id')->on('users')->nullOnDelete();

            $table->unique(['membership_id', 'sequence_number']);
            $table->index(['campaign_id', 'due_date', 'status']);
            $table->index(['membership_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('installments');
    }
};
