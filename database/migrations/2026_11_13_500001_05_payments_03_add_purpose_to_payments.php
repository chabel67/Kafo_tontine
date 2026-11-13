<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Objet d'un paiement (R-FEE-02) : `contribution` (cotisation, créditée en épargne et
 * affectée aux échéances) ou `membership_fee` (frais d'adhésion, crédité en produit
 * FEE_INCOME, jamais en épargne). Les paiements existants sont des cotisations.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('purpose', 20)->default('contribution')->after('channel');
            $table->index(['membership_id', 'purpose', 'status'], 'payments_membership_purpose_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex('payments_membership_purpose_status_idx');
            $table->dropColumn('purpose');
        });
    }
};
