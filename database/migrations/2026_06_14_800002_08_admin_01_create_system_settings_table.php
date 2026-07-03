<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->text('value')->nullable();
            $table->string('type')->default('string'); // string | integer | boolean | json
            $table->string('label');
            $table->string('group')->default('general');
            $table->string('description')->nullable();
            $table->timestamps();
        });

        $settings = [
            // Maker/checker
            ['key' => 'maker_checker_loan_threshold',    'value' => '500000',  'type' => 'integer', 'label' => 'Seuil maker/checker avances (XOF)',         'group' => 'lending',   'description' => 'Montant au-delà duquel une avance requiert une double approbation'],
            ['key' => 'maker_checker_writeoff_threshold','value' => '0',       'type' => 'integer', 'label' => 'Seuil maker/checker write-off (XOF)',       'group' => 'lending',   'description' => 'Tous les write-offs requièrent une double approbation (0 = toujours)'],
            // Prêts
            ['key' => 'min_membership_age_days',         'value' => '30',      'type' => 'integer', 'label' => 'Ancienneté minimale adhésion (jours)',       'group' => 'lending',   'description' => "Nombre de jours d'adhésion active avant d'être éligible à une avance"],
            ['key' => 'min_trust_score',                 'value' => '60',      'type' => 'integer', 'label' => 'Score de confiance minimum',                 'group' => 'lending',   'description' => 'Score minimum (0-100) requis pour demander une avance'],
            ['key' => 'loan_coefficient_high',           'value' => '0.70',    'type' => 'string',  'label' => 'Coefficient emprunt score ≥75',              'group' => 'lending',   'description' => 'Coefficient appliqué à l\'épargne pour calculer le plafond d\'avance'],
            ['key' => 'loan_coefficient_medium',         'value' => '0.60',    'type' => 'string',  'label' => 'Coefficient emprunt score ≥60',              'group' => 'lending',   'description' => null],
            ['key' => 'loan_coefficient_low',            'value' => '0.50',    'type' => 'string',  'label' => 'Coefficient emprunt score <60',              'group' => 'lending',   'description' => null],
            // Caisse
            ['key' => 'cash_discrepancy_alert_threshold','value' => '5000',    'type' => 'integer', 'label' => 'Seuil alerte écart caisse (XOF)',            'group' => 'cash',      'description' => 'Écart au-delà duquel une alerte est envoyée au gestionnaire'],
            // Sécurité
            ['key' => 'session_timeout_minutes',         'value' => '30',      'type' => 'integer', 'label' => 'Timeout session (minutes)',                  'group' => 'security',  'description' => 'Durée d\'inactivité avant expiration de session'],
            ['key' => 'otp_ttl_seconds',                 'value' => '300',     'type' => 'integer', 'label' => 'Durée de vie OTP (secondes)',                'group' => 'security',  'description' => null],
            ['key' => 'otp_max_requests_per_window',     'value' => '3',       'type' => 'integer', 'label' => 'Max demandes OTP / 15 min',                  'group' => 'security',  'description' => null],
            // Canaux PSP
            ['key' => 'psp_cash_enabled',                'value' => 'true',    'type' => 'boolean', 'label' => 'Cash (encaissement physique)',               'group' => 'psp',       'description' => null],
            ['key' => 'psp_mtn_enabled',                 'value' => 'true',    'type' => 'boolean', 'label' => 'MTN Mobile Money',                           'group' => 'psp',       'description' => null],
            ['key' => 'psp_orange_enabled',              'value' => 'true',    'type' => 'boolean', 'label' => 'Orange Money',                               'group' => 'psp',       'description' => null],
            ['key' => 'psp_moov_enabled',                'value' => 'false',   'type' => 'boolean', 'label' => 'Moov Money',                                 'group' => 'psp',       'description' => null],
            ['key' => 'psp_wave_enabled',                'value' => 'false',   'type' => 'boolean', 'label' => 'Wave',                                       'group' => 'psp',       'description' => null],
        ];

        foreach ($settings as $s) {
            DB::table('system_settings')->insert([...$s, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('system_settings');
    }
};
