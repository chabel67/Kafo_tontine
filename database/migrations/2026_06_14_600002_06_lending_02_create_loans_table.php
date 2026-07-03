<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loans', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('reference')->unique();
            $table->foreignUuid('loan_request_id')->constrained('loan_requests');
            $table->foreignUuid('membership_id')->constrained('memberships');
            $table->integer('principal_minor');
            $table->integer('outstanding_minor');     // decremented on each repayment
            // active → repaid | written_off
            $table->string('status')->default('active');
            $table->string('disbursed_channel');
            $table->string('disbursed_phone')->nullable();
            $table->foreignUuid('disbursed_by')->nullable()->constrained('users');
            $table->timestamp('disbursed_at')->nullable();
            $table->foreignUuid('written_off_by')->nullable()->constrained('users');
            $table->timestamp('written_off_at')->nullable();
            $table->text('written_off_reason')->nullable();
            $table->timestamps();
        });

        // Loan repayments — cash payments specifically attributed to a loan
        Schema::create('loan_repayments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('loan_id')->constrained('loans');
            $table->integer('amount_minor');
            $table->string('channel');
            $table->string('notes')->nullable();
            $table->foreignUuid('recorded_by')->nullable()->constrained('users');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loan_repayments');
        Schema::dropIfExists('loans');
    }
};
