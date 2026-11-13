<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Campagnes rotatives (tontine à tours) — lot 1, modèle de données (additif).
 *
 *  - tontine_campaigns : type (`savings` | `rotating`), paramètres rotatifs (k échéances par
 *    tour, mode et montant de frais D-R5, règle de fréquence), dates de confirmation / démarrage ;
 *  - campaign_schedule_dates : calendrier des cotisations CONFIRMÉ et figé (D-R1/D-R2) ;
 *  - rotation_rounds : un tour par membre, un bénéficiaire unique par campagne ;
 *  - rotation_departures : départ d'un membre non encore servi (remboursement, remplaçant, D-R7) ;
 *  - rotation_payout_attempts : versements en deux temps (tour, remboursement, reliquat — D-R9/D-R11) ;
 *  - installments : complément de cotisation (D-R5) et pénalité décidée par le personnel (D-R10).
 *
 * Les campagnes existantes deviennent `savings` : aucun comportement ne change pour elles.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tontine_campaigns', function (Blueprint $table) {
            $table->string('campaign_type', 20)->default('savings')->after('status');
            $table->unsignedSmallInteger('installments_per_round')->nullable()->after('duration_installments');
            $table->string('rotation_fee_mode', 30)->default('none')->after('installments_per_round');
            $table->unsignedBigInteger('rotation_fee_minor')->default(0)->after('rotation_fee_mode');
            $table->json('contribution_schedule')->nullable()->after('rotation_fee_minor');
            $table->timestamp('schedule_confirmed_at')->nullable()->after('activated_at');
            $table->timestamp('started_at')->nullable()->after('schedule_confirmed_at');
            $table->index('campaign_type');
        });
        DB::statement("ALTER TABLE tontine_campaigns ADD CONSTRAINT tontine_campaigns_type_check CHECK (campaign_type IN ('savings','rotating'))");
        DB::statement("ALTER TABLE tontine_campaigns ADD CONSTRAINT tontine_campaigns_rotation_fee_mode_check CHECK (rotation_fee_mode IN ('none','payout_deduction','contribution_surcharge'))");

        Schema::create('campaign_schedule_dates', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('campaign_id')->constrained('tontine_campaigns');
            $table->unsignedSmallInteger('sequence');
            $table->date('due_date');
            $table->timestamps();
            $table->unique(['campaign_id', 'sequence']);
            $table->unique(['campaign_id', 'due_date']);
        });

        Schema::create('rotation_rounds', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('campaign_id')->constrained('tontine_campaigns');
            $table->unsignedSmallInteger('round_number');
            $table->unsignedSmallInteger('first_sequence');
            $table->unsignedSmallInteger('last_sequence');
            $table->date('scheduled_date');
            $table->foreignUuid('beneficiary_membership_id')->nullable()->constrained('memberships');
            $table->string('status', 20)->default('scheduled');
            $table->unsignedBigInteger('expected_minor');
            $table->unsignedBigInteger('fee_minor')->default(0);
            $table->unsignedBigInteger('distributed_minor')->default(0);
            $table->string('reference')->nullable()->unique();
            $table->foreignUuid('approved_by')->nullable()->constrained('users');
            $table->timestamp('approved_at')->nullable();
            $table->json('override_reasons')->nullable();
            $table->uuid('ledger_transaction_id')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['campaign_id', 'round_number']);
            $table->index(['campaign_id', 'status']);
        });
        // D-R6 : un membre ne bénéficie qu'une fois par cycle.
        DB::statement('CREATE UNIQUE INDEX rotation_rounds_one_round_per_beneficiary
            ON rotation_rounds (campaign_id, beneficiary_membership_id) WHERE beneficiary_membership_id IS NOT NULL');
        DB::statement('ALTER TABLE rotation_rounds ADD CONSTRAINT rotation_rounds_sequence_range CHECK (first_sequence >= 1 AND last_sequence >= first_sequence)');

        Schema::create('rotation_departures', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('campaign_id')->constrained('tontine_campaigns');
            $table->foreignUuid('membership_id')->unique()->constrained('memberships');
            $table->unsignedBigInteger('refund_minor')->default(0);
            $table->text('reason');
            $table->string('status', 20)->default('pending');   // pending | refunding | refunded | cancelled
            $table->foreignUuid('replacement_membership_id')->nullable()->constrained('memberships');
            $table->foreignUuid('decided_by')->constrained('users');
            $table->timestamp('decided_at');
            $table->timestamps();
        });

        Schema::create('rotation_payout_attempts', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('kind', 20);                          // round | departure_refund | surplus
            $table->foreignUuid('campaign_id')->constrained('tontine_campaigns');
            $table->foreignUuid('membership_id')->constrained('memberships');   // qui reçoit l'argent
            $table->foreignUuid('rotation_round_id')->nullable()->constrained('rotation_rounds');
            $table->foreignUuid('rotation_departure_id')->nullable()->constrained('rotation_departures');
            $table->unsignedSmallInteger('attempt_no');
            $table->string('idempotency_key')->unique();
            $table->unsignedBigInteger('amount_minor');
            $table->string('channel', 20);
            $table->string('phone', 20)->nullable();
            $table->string('status', 20)->default('processing'); // processing | succeeded | failed
            $table->string('external_reference')->nullable();
            $table->text('failure_reason')->nullable();
            $table->foreignUuid('initiated_by')->constrained('users');
            $table->timestamp('initiated_at');
            $table->foreignUuid('completed_by')->nullable()->constrained('users');
            $table->timestamp('completed_at')->nullable();
            $table->uuid('ledger_transaction_id')->nullable();
            $table->timestamps();
            $table->index(['campaign_id', 'kind', 'status']);
        });
        DB::statement("ALTER TABLE rotation_payout_attempts ADD CONSTRAINT rotation_payout_attempts_kind_check CHECK (kind IN ('round','departure_refund','surplus'))");
        DB::statement('ALTER TABLE rotation_payout_attempts ADD CONSTRAINT rotation_payout_attempts_amount_positive CHECK (amount_minor > 0)');
        // Une seule tentative en cours par cible (anti double versement, D-R9).
        DB::statement("CREATE UNIQUE INDEX rotation_attempts_one_processing_per_round
            ON rotation_payout_attempts (rotation_round_id) WHERE status = 'processing' AND rotation_round_id IS NOT NULL");
        DB::statement("CREATE UNIQUE INDEX rotation_attempts_one_processing_per_departure
            ON rotation_payout_attempts (rotation_departure_id) WHERE status = 'processing' AND rotation_departure_id IS NOT NULL");
        DB::statement("CREATE UNIQUE INDEX rotation_attempts_one_processing_surplus_per_member
            ON rotation_payout_attempts (campaign_id, membership_id) WHERE status = 'processing' AND kind = 'surplus'");

        Schema::table('installments', function (Blueprint $table) {
            // D-R5 : part de `amount_minor` qui est un complément de frais (pas la caisse commune).
            $table->unsignedBigInteger('fee_minor')->default(0)->after('amount_minor');
            // D-R10 : pénalité décidée par le personnel, s'ajoute au reste dû.
            $table->unsignedBigInteger('penalty_minor')->default(0)->after('fee_minor');
            $table->text('penalty_reason')->nullable()->after('penalty_minor');
            $table->foreignUuid('penalty_by')->nullable()->after('penalty_reason')->constrained('users');
            $table->timestamp('penalty_at')->nullable()->after('penalty_by');
        });
    }

    public function down(): void
    {
        Schema::table('installments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('penalty_by');
            $table->dropColumn(['fee_minor', 'penalty_minor', 'penalty_reason', 'penalty_at']);
        });
        Schema::dropIfExists('rotation_payout_attempts');
        Schema::dropIfExists('rotation_departures');
        Schema::dropIfExists('rotation_rounds');
        Schema::dropIfExists('campaign_schedule_dates');
        DB::statement('ALTER TABLE tontine_campaigns DROP CONSTRAINT IF EXISTS tontine_campaigns_type_check');
        DB::statement('ALTER TABLE tontine_campaigns DROP CONSTRAINT IF EXISTS tontine_campaigns_rotation_fee_mode_check');
        Schema::table('tontine_campaigns', function (Blueprint $table) {
            $table->dropIndex(['campaign_type']);
            $table->dropColumn([
                'campaign_type', 'installments_per_round', 'rotation_fee_mode', 'rotation_fee_minor',
                'contribution_schedule', 'schedule_confirmed_at', 'started_at',
            ]);
        });
    }
};
