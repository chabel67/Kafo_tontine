<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tontine_campaigns', function (Blueprint $table) {
            // Mode de cotisation
            $table->string('contribution_mode', 20)->default('fixed')->after('currency');

            // Montant fixe devient nullable (null en mode custom)
            $table->unsignedBigInteger('contribution_amount_minor')->nullable()->change();

            // Bornes pour le mode custom (toutes deux optionnelles)
            $table->unsignedBigInteger('min_contribution_minor')->nullable()->after('contribution_amount_minor');
            $table->unsignedBigInteger('max_contribution_minor')->nullable()->after('min_contribution_minor');
        });

        Schema::table('memberships', function (Blueprint $table) {
            // Montant d'engagement du membre — source de vérité pour les échéances
            $table->unsignedBigInteger('committed_amount_minor')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('tontine_campaigns', function (Blueprint $table) {
            $table->dropColumn(['contribution_mode', 'min_contribution_minor', 'max_contribution_minor']);
            $table->unsignedBigInteger('contribution_amount_minor')->nullable(false)->change();
        });

        Schema::table('memberships', function (Blueprint $table) {
            $table->dropColumn('committed_amount_minor');
        });
    }
};
