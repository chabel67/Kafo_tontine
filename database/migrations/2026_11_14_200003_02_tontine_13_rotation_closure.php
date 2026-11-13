<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tontines rotatives — lot 8 (départs, clôture) :
 *  - reliquat de la caisse passé en produit à la clôture (D-R11), tracé sur la campagne ;
 *  - motif de départ porté sur la tentative de remboursement / de reliquat (audit lisible) ;
 *  - séquence des références de remboursement de départ et de reliquat (RTO-AAAA-NNNNNN).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tontine_campaigns', function (Blueprint $table) {
            $table->unsignedBigInteger('rotation_surplus_revenue_minor')->default(0)->after('started_at');
        });
        Schema::table('rotation_payout_attempts', function (Blueprint $table) {
            $table->text('reason')->nullable()->after('amount_minor');
            $table->string('reference')->nullable()->unique()->after('reason');
        });
        DB::statement('CREATE SEQUENCE IF NOT EXISTS ref_rto_seq START 1');
    }

    public function down(): void
    {
        DB::statement('DROP SEQUENCE IF EXISTS ref_rto_seq');
        Schema::table('rotation_payout_attempts', function (Blueprint $table) {
            $table->dropUnique(['reference']);
            $table->dropColumn(['reason', 'reference']);
        });
        Schema::table('tontine_campaigns', function (Blueprint $table) {
            $table->dropColumn('rotation_surplus_revenue_minor');
        });
    }
};
