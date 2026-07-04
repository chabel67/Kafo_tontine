<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Échéancier de remboursement pour les prêts standards (hors campagne).
 *
 * Chaque item porte son propre statut (pending|paid|late|waived) et cumule
 * les remboursements partiels via `paid_minor`. `amount_minor` est stocké
 * (principal + intérêt) plutôt que colonne calculée pour rester compatible
 * avec Postgres < 12 et simplifier les index sur due_date/status.
 *
 * Un `advance` (product_type sur Loan) NE génère PAS d'items ici — la
 * compensation se fait à la clôture campagne via CampaignPayoutService.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loan_repayment_schedules', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('loan_id')->constrained('loans')->cascadeOnDelete();
            $table->unsignedSmallInteger('sequence_number');
            $table->date('due_date');
            $table->integer('principal_minor');
            $table->integer('interest_minor')->default(0);
            $table->integer('amount_minor');
            $table->integer('paid_minor')->default(0);
            // pending → paid | late | waived
            $table->string('status', 20)->default('pending');
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('late_marked_at')->nullable();
            $table->foreignUuid('waived_by')->nullable()->constrained('users');
            $table->timestamp('waived_at')->nullable();
            $table->timestamps();

            $table->unique(['loan_id', 'sequence_number']);
            $table->index(['loan_id', 'status']);
            $table->index(['due_date', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loan_repayment_schedules');
    }
};
