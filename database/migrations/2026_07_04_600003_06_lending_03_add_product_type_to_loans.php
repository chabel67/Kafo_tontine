<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ajoute la polymorphie product_type (advance | standard) à `loans` et
 * `loan_requests`, plus les champs propres au produit standard (périodicité,
 * échéances, taux d'intérêt) et le rattachement optionnel à une campagne.
 *
 * Rétrocompat : les enregistrements existants sont considérés comme des
 * avances (product_type='advance') puisque le code actuel les traite ainsi.
 * campaign_id est backfilé depuis memberships.campaign_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loan_requests', function (Blueprint $table) {
            $table->string('product_type', 20)->default('advance')->after('membership_id');
            $table->foreignUuid('campaign_id')->nullable()->after('product_type')
                ->constrained('tontine_campaigns');
            $table->integer('interest_rate_bps')->nullable()->after('purpose');
            $table->string('periodicity', 20)->nullable()->after('interest_rate_bps');
            $table->integer('installments_count')->nullable()->after('periodicity');
            $table->date('first_due_date')->nullable()->after('installments_count');
            $table->jsonb('custom_due_dates')->nullable()->after('first_due_date');

            $table->index(['product_type', 'status']);
            $table->index(['campaign_id', 'status']);
        });

        Schema::table('loans', function (Blueprint $table) {
            $table->string('product_type', 20)->default('advance')->after('membership_id');
            $table->foreignUuid('campaign_id')->nullable()->after('product_type')
                ->constrained('tontine_campaigns');
            $table->integer('interest_rate_bps')->nullable()->after('outstanding_minor');
            $table->integer('interest_total_minor')->default(0)->after('interest_rate_bps');
            $table->string('periodicity', 20)->nullable()->after('interest_total_minor');
            $table->integer('installments_count')->nullable()->after('periodicity');
            $table->date('first_due_date')->nullable()->after('installments_count');
            $table->date('next_due_date')->nullable()->after('first_due_date');
            $table->jsonb('custom_due_dates')->nullable()->after('next_due_date');

            $table->index(['product_type', 'status']);
            $table->index(['campaign_id', 'status']);
            $table->index(['status', 'next_due_date']);
        });

        // Prêts standards peuvent cibler un user sans membership active.
        Schema::table('loans', function (Blueprint $table) {
            $table->uuid('membership_id')->nullable()->change();
        });
        Schema::table('loan_requests', function (Blueprint $table) {
            $table->uuid('membership_id')->nullable()->change();
        });

        // Backfill : lie campaign_id via memberships pour tout l'existant.
        DB::statement(<<<'SQL'
            UPDATE loans
               SET campaign_id = m.campaign_id
              FROM memberships m
             WHERE loans.membership_id = m.id
               AND loans.campaign_id IS NULL
        SQL);

        DB::statement(<<<'SQL'
            UPDATE loan_requests
               SET campaign_id = m.campaign_id
              FROM memberships m
             WHERE loan_requests.membership_id = m.id
               AND loan_requests.campaign_id IS NULL
        SQL);
    }

    public function down(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->dropIndex(['product_type', 'status']);
            $table->dropIndex(['campaign_id', 'status']);
            $table->dropIndex(['status', 'next_due_date']);
            $table->dropConstrainedForeignId('campaign_id');
            $table->dropColumn([
                'product_type', 'interest_rate_bps', 'interest_total_minor',
                'periodicity', 'installments_count', 'first_due_date',
                'next_due_date', 'custom_due_dates',
            ]);
        });

        Schema::table('loan_requests', function (Blueprint $table) {
            $table->dropIndex(['product_type', 'status']);
            $table->dropIndex(['campaign_id', 'status']);
            $table->dropConstrainedForeignId('campaign_id');
            $table->dropColumn([
                'product_type', 'interest_rate_bps',
                'periodicity', 'installments_count', 'first_due_date',
                'custom_due_dates',
            ]);
        });
    }
};
