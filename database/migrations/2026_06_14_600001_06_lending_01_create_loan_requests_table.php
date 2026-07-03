<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loan_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('membership_id')->constrained('memberships');
            $table->integer('amount_minor');
            $table->string('purpose')->nullable();
            // pending → approved | countersigning → countersigned → disbursed | rejected | cancelled
            $table->string('status')->default('pending');
            $table->jsonb('eligibility_snapshot')->nullable();

            $table->foreignUuid('created_by')->nullable()->constrained('users');
            $table->foreignUuid('decided_by')->nullable()->constrained('users');
            $table->timestamp('decided_at')->nullable();
            $table->foreignUuid('countersigned_by')->nullable()->constrained('users');
            $table->timestamp('countersigned_at')->nullable();
            $table->foreignUuid('disbursed_by')->nullable()->constrained('users');
            $table->timestamp('disbursed_at')->nullable();
            $table->text('rejected_reason')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loan_requests');
    }
};
