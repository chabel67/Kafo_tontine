<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Prêts communautaires indépendants des campagnes (R-LOAN-16) et idempotence :
 *  - `user_id` sur `loan_requests` et `loans` : un prêt standard appartient au membre,
 *    pas à une adhésion (rempli depuis l'adhésion pour l'existant) ;
 *  - `idempotency_key` sur les demandes et les remboursements (anti double saisie) ;
 *  - `campaign_payout_id` sur un remboursement prélevé au règlement d'un payout (R-CAMP-14).
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['loan_requests', 'loans'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->foreignUuid('user_id')->nullable()->after('membership_id')->constrained('users');
                $table->index(['user_id', 'status']);
            });

            DB::statement("
                UPDATE {$tableName} t SET user_id = m.user_id
                FROM memberships m
                WHERE t.membership_id = m.id AND t.user_id IS NULL
            ");

            $orphans = (int) DB::scalar("SELECT COUNT(*) FROM {$tableName} WHERE user_id IS NULL");
            if ($orphans === 0) {
                DB::statement("ALTER TABLE {$tableName} ALTER COLUMN user_id SET NOT NULL");
            }
        }

        Schema::table('loan_requests', function (Blueprint $table) {
            $table->string('idempotency_key')->nullable()->unique()->after('id');
        });

        Schema::table('loan_repayments', function (Blueprint $table) {
            $table->string('idempotency_key')->nullable()->unique()->after('loan_id');
            $table->foreignUuid('campaign_payout_id')->nullable()->after('channel')->constrained('campaign_payouts');
        });
    }

    public function down(): void
    {
        Schema::table('loan_repayments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('campaign_payout_id');
            $table->dropUnique(['idempotency_key']);
            $table->dropColumn('idempotency_key');
        });

        Schema::table('loan_requests', function (Blueprint $table) {
            $table->dropUnique(['idempotency_key']);
            $table->dropColumn('idempotency_key');
        });

        foreach (['loans', 'loan_requests'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropIndex(['user_id', 'status']);
                $table->dropConstrainedForeignId('user_id');
            });
        }
    }
};
