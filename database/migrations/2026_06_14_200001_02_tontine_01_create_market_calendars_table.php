<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('market_calendars', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('cadence_type', 30);
            $table->json('cadence_value');
            $table->string('timezone', 50)->default('Africa/Porto-Novo');
            $table->text('description')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('market_calendars');
    }
};
