<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DA-20 (2026-11-14) — les moyens de paiement sont des agrégateurs (KKiaPay, FedaPay),
 * plus des réseaux. Le réseau devient une information.
 *
 *  - payments.network : réseau rapporté par l'agrégateur. Backfill : les encaissements
 *    MoMo existants (channel = mtn|moov|orange|wave) passaient tous par KKiaPay →
 *    channel = 'kkiapay', network = ancienne valeur. Données de paiement, pas le ledger
 *    (le ledger n'est jamais modifié : `kafo:migrate-psp-treasury` transfère les soldes).
 *  - colonnes *_network à côté de chaque numéro de versement (historique non réécrit).
 *  - réglages psp : psp_kkiapay_enabled / psp_fedapay_enabled remplacent les réglages par réseau.
 */
return new class extends Migration
{
    private const LEGACY = ['mtn', 'moov', 'orange', 'wave'];

    public function up(): void
    {
        Schema::table('payments', fn (Blueprint $t) => $t->string('network', 20)->nullable()->after('channel'));

        DB::table('payments')->whereIn('channel', self::LEGACY)->update([
            'network' => DB::raw('channel'),
            'channel' => 'kkiapay',
        ]);

        Schema::table('early_withdrawals', function (Blueprint $t) {
            $t->string('payout_network', 20)->nullable()->after('payout_phone');
            $t->string('disbursed_network', 20)->nullable()->after('disbursed_phone');
        });
        Schema::table('early_withdrawal_payout_attempts', fn (Blueprint $t) => $t->string('network', 20)->nullable()->after('phone'));
        Schema::table('rotation_payout_attempts', fn (Blueprint $t) => $t->string('network', 20)->nullable()->after('phone'));
        Schema::table('campaign_payouts', fn (Blueprint $t) => $t->string('settled_network', 20)->nullable()->after('settled_phone'));
        Schema::table('loans', fn (Blueprint $t) => $t->string('disbursed_network', 20)->nullable()->after('disbursed_phone'));

        // Demandes de retrait non versées : la préférence « réseau » devient « Mobile Money » + réseau.
        DB::table('early_withdrawals')->whereIn('payout_channel', self::LEGACY)->update([
            'payout_network' => DB::raw('payout_channel'),
            'payout_channel' => 'mobile_money',
        ]);

        // Réglages : KKiaPay actif si MTN ou Moov l'était ; FedaPay inactif tant qu'il n'est pas configuré.
        $wasOn = DB::table('system_settings')
            ->whereIn('key', ['psp_mtn_enabled', 'psp_moov_enabled'])
            ->whereIn('value', ['true', '1'])
            ->exists();

        $now = now();
        foreach ([
            ['psp_kkiapay_enabled', $wasOn ? 'true' : 'false', 'KKiaPay (MTN, Moov…)'],
            ['psp_fedapay_enabled', 'false', 'FedaPay (MTN, Moov, Celtiis…)'],
        ] as [$key, $value, $label]) {
            if (! DB::table('system_settings')->where('key', $key)->exists()) {
                DB::table('system_settings')->insert([
                    'key' => $key, 'value' => $value, 'type' => 'boolean', 'label' => $label,
                    'group' => 'psp', 'description' => null, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }
        DB::table('system_settings')->whereIn('key', array_map(fn ($c) => "psp_{$c}_enabled", self::LEGACY))->delete();
    }

    public function down(): void
    {
        DB::table('early_withdrawals')->where('payout_channel', 'mobile_money')->whereNotNull('payout_network')->update([
            'payout_channel' => DB::raw('payout_network'),
        ]);
        DB::table('payments')->where('channel', 'kkiapay')->whereNotNull('network')->update(['channel' => DB::raw('network')]);

        Schema::table('loans', fn (Blueprint $t) => $t->dropColumn('disbursed_network'));
        Schema::table('campaign_payouts', fn (Blueprint $t) => $t->dropColumn('settled_network'));
        Schema::table('rotation_payout_attempts', fn (Blueprint $t) => $t->dropColumn('network'));
        Schema::table('early_withdrawal_payout_attempts', fn (Blueprint $t) => $t->dropColumn('network'));
        Schema::table('early_withdrawals', fn (Blueprint $t) => $t->dropColumn(['payout_network', 'disbursed_network']));
        Schema::table('payments', fn (Blueprint $t) => $t->dropColumn('network'));

        DB::table('system_settings')->whereIn('key', ['psp_kkiapay_enabled', 'psp_fedapay_enabled'])->delete();
    }
};
