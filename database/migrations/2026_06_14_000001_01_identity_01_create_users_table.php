<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('phone', 20)->unique();
            $table->string('full_name', 100)->nullable();
            $table->string('pin_hash', 255)->nullable();
            $table->tinyInteger('kyc_level')->default(0);
            $table->string('kyc_status', 20)->default('none');
            $table->string('status', 20)->default('active');
            $table->json('kyc_documents')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
            $table->index('kyc_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
