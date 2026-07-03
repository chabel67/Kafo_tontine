<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tontine_campaigns', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignId('market_calendar_id')->nullable()->constrained('market_calendars')->nullOnDelete();
            $table->uuid('created_by');
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->string('status', 20)->default('draft');
            $table->string('currency', 3)->default('XOF');
            $table->unsignedBigInteger('contribution_amount_minor');
            $table->unsignedSmallInteger('duration_installments');
            $table->date('first_installment_date');
            $table->unsignedTinyInteger('grace_period_days')->default(3);
            $table->unsignedSmallInteger('min_membership_age_days')->default(30);
            $table->unsignedTinyInteger('min_trust_score')->default(60);
            $table->unsignedBigInteger('maker_checker_threshold_minor')->default(0);
            $table->unsignedSmallInteger('max_members')->nullable();
            $table->json('rules')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->foreign('created_by')->references('id')->on('users');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tontine_campaigns');
    }
};
