<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Harmonisation campagnes / retraits / avances / clôture (2026-11-13) :
 *  - campagne : autorisation des retraits anticipés (R-WDR-08) et frais d'adhésion (R-FEE-01) ;
 *  - adhésion : copie figée des frais d'adhésion au moment de l'enrôlement ;
 *  - payout de clôture : idempotence du règlement, remboursement volontaire d'un prêt
 *    communautaire (R-CAMP-14), retraits déjà versés (affichage), transaction ledger.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tontine_campaigns', function (Blueprint $table) {
            $table->boolean('allow_early_withdrawals')->default(true)->after('max_members');
            $table->unsignedBigInteger('membership_fee_minor')->default(0)->after('allow_early_withdrawals');
        });

        Schema::table('memberships', function (Blueprint $table) {
            $table->unsignedBigInteger('membership_fee_minor')->default(0)->after('committed_amount_minor');
        });

        Schema::table('campaign_payouts', function (Blueprint $table) {
            $table->string('idempotency_key')->nullable()->unique()->after('reference');
            $table->unsignedBigInteger('withdrawals_disbursed_minor')->default(0)->after('advance_offset_minor');
            $table->unsignedBigInteger('loan_repayment_minor')->default(0)->after('net_amount_minor');
            $table->uuid('loan_repayment_id')->nullable()->after('loan_repayment_minor');
            $table->uuid('ledger_transaction_id')->nullable()->after('loan_repayment_id');
        });

        DB::statement('ALTER TABLE tontine_campaigns ADD CONSTRAINT tontine_campaigns_membership_fee_non_negative CHECK (membership_fee_minor >= 0)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE tontine_campaigns DROP CONSTRAINT IF EXISTS tontine_campaigns_membership_fee_non_negative');

        Schema::table('campaign_payouts', function (Blueprint $table) {
            $table->dropUnique(['idempotency_key']);
            $table->dropColumn(['idempotency_key', 'withdrawals_disbursed_minor', 'loan_repayment_minor', 'loan_repayment_id', 'ledger_transaction_id']);
        });

        Schema::table('memberships', function (Blueprint $table) {
            $table->dropColumn('membership_fee_minor');
        });

        Schema::table('tontine_campaigns', function (Blueprint $table) {
            $table->dropColumn(['allow_early_withdrawals', 'membership_fee_minor']);
        });
    }
};
