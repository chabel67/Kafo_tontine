<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Retraits anticipés v2 :
 *  - instruction : prise en charge (assigned_to), demande d'informations / réponse du membre ;
 *  - moyen de réception souhaité par le membre ;
 *  - versement en deux temps : tentatives (processing → succeeded | failed), relance possible,
 *    écriture ledger uniquement à la confirmation (une seule par retrait).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('early_withdrawals', function (Blueprint $table) {
            $table->string('payout_channel', 20)->nullable()->after('reason');
            $table->string('payout_phone', 20)->nullable()->after('payout_channel');
            $table->foreignUuid('assigned_to')->nullable()->after('requested_by')->constrained('users');
            $table->timestamp('reviewed_at')->nullable()->after('assigned_to');
            $table->text('info_request_message')->nullable()->after('reviewed_at');
            $table->timestamp('info_requested_at')->nullable()->after('info_request_message');
            $table->text('member_response')->nullable()->after('info_requested_at');
            $table->timestamp('responded_at')->nullable()->after('member_response');
        });

        Schema::create('early_withdrawal_payout_attempts', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('early_withdrawal_id')->constrained('early_withdrawals');
            $table->unsignedSmallInteger('attempt_no');
            $table->string('idempotency_key')->unique();
            $table->string('channel', 20);
            $table->string('phone', 20)->nullable();
            $table->string('status', 20)->default('processing');   // processing | succeeded | failed
            $table->string('external_reference')->nullable();
            $table->text('failure_reason')->nullable();
            $table->foreignUuid('initiated_by')->constrained('users');
            $table->timestamp('initiated_at');
            $table->foreignUuid('completed_by')->nullable()->constrained('users');
            $table->timestamp('completed_at')->nullable();
            $table->uuid('ledger_transaction_id')->nullable();
            $table->timestamps();

            $table->unique(['early_withdrawal_id', 'attempt_no']);
        });

        // Au plus une tentative en cours par retrait (protection double versement)
        DB::statement("CREATE UNIQUE INDEX early_withdrawal_one_processing_attempt
            ON early_withdrawal_payout_attempts (early_withdrawal_id) WHERE status = 'processing'");

        // Instruction : le trésorier peut examiner les demandes (R-WDR-04)
        $row = DB::table('roles')->where('name', 'treasurer')->first();
        if ($row) {
            $perms = json_decode($row->permissions ?? '[]', true) ?: [];
            if (! in_array('withdrawal.review', $perms, true)) {
                $perms[] = 'withdrawal.review';
                DB::table('roles')->where('name', 'treasurer')->update(['permissions' => json_encode($perms)]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('early_withdrawal_payout_attempts');

        Schema::table('early_withdrawals', function (Blueprint $table) {
            $table->dropConstrainedForeignId('assigned_to');
            $table->dropColumn([
                'payout_channel', 'payout_phone', 'reviewed_at',
                'info_request_message', 'info_requested_at', 'member_response', 'responded_at',
            ]);
        });

        $row = DB::table('roles')->where('name', 'treasurer')->first();
        if ($row) {
            $perms = array_values(array_diff(json_decode($row->permissions ?? '[]', true) ?: [], ['withdrawal.review']));
            DB::table('roles')->where('name', 'treasurer')->update(['permissions' => json_encode($perms)]);
        }
    }
};
