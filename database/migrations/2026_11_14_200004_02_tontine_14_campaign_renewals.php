<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reconduction des campagnes (R1 : campagnes liées) — additif.
 *  - tontine_campaigns : série commune (`series_id`, = id pour l'existant), numéro de cycle,
 *    lien vers le cycle précédent ; une campagne n'est reconduite qu'UNE fois ;
 *  - campaign_renewals : trace de chaque reconduction (composition, diff de configuration),
 *    clé d'idempotence unique (double clic, requête rejouée).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tontine_campaigns', function (Blueprint $table) {
            $table->uuid('series_id')->nullable()->after('campaign_type');
            $table->unsignedSmallInteger('cycle_number')->default(1)->after('series_id');
            $table->foreignUuid('previous_campaign_id')->nullable()->after('cycle_number')->constrained('tontine_campaigns');
            $table->index('series_id');
        });
        DB::statement('UPDATE tontine_campaigns SET series_id = id WHERE series_id IS NULL');
        DB::statement('ALTER TABLE tontine_campaigns ALTER COLUMN series_id SET NOT NULL');
        DB::statement('CREATE UNIQUE INDEX tontine_campaigns_one_renewal
            ON tontine_campaigns (previous_campaign_id) WHERE previous_campaign_id IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX tontine_campaigns_series_cycle ON tontine_campaigns (series_id, cycle_number)');

        Schema::create('campaign_renewals', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('source_campaign_id')->constrained('tontine_campaigns');
            $table->foreignUuid('target_campaign_id')->unique()->constrained('tontine_campaigns');
            $table->string('idempotency_key')->unique();
            $table->json('roster');
            $table->json('config_changes')->nullable();
            $table->foreignUuid('created_by')->constrained('users');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_renewals');
        DB::statement('DROP INDEX IF EXISTS tontine_campaigns_series_cycle');
        DB::statement('DROP INDEX IF EXISTS tontine_campaigns_one_renewal');
        Schema::table('tontine_campaigns', function (Blueprint $table) {
            $table->dropIndex(['series_id']);
            $table->dropConstrainedForeignId('previous_campaign_id');
            $table->dropColumn(['series_id', 'cycle_number']);
        });
    }
};
