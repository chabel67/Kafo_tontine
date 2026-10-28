<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Champs d'identité complémentaires de la fiche membre (module Gestion des membres).
 * Tous facultatifs : le téléphone reste l'identifiant unique (R-MEMB-09).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('email', 150)->nullable()->unique()->after('phone');
            $table->date('birth_date')->nullable()->after('full_name');
            $table->string('locality', 120)->nullable()->after('birth_date');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['email']);
            $table->dropColumn(['email', 'birth_date', 'locality']);
        });
    }
};
