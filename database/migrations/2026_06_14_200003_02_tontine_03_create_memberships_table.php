<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('memberships', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('user_id');
            $table->uuid('campaign_id');
            $table->string('status', 20)->default('pending_l1');
            $table->uuid('validated_l1_by')->nullable();
            $table->timestamp('validated_l1_at')->nullable();
            $table->uuid('validated_l2_by')->nullable();
            $table->timestamp('validated_l2_at')->nullable();
            $table->uuid('suspended_by')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->text('suspension_reason')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('campaign_id')->references('id')->on('tontine_campaigns')->cascadeOnDelete();
            $table->foreign('validated_l1_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('validated_l2_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('suspended_by')->references('id')->on('users')->nullOnDelete();

            $table->unique(['user_id', 'campaign_id']);
            $table->index(['campaign_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('memberships');
    }
};
