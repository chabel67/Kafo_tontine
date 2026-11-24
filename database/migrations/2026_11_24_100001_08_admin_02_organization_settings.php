<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Paramètres « Organisation » (R-DOC-01) : en-tête et pied des attestations PDF
 * (prêt décaissé, retrait anticipé versé). Modifiables par PATCH /admin/settings.
 */
return new class extends Migration
{
    private const SETTINGS = [
        ['org_name',            'Kafo', "Nom de l'organisation",   "Affiché en en-tête des attestations et dans la zone de signature."],
        ['org_address',         '',     'Adresse',                 'Adresse postale ou siège, affichée sous le nom.'],
        ['org_phone',           '',     'Téléphone',               'Numéro de contact affiché en en-tête.'],
        ['org_email',           '',     'Adresse e-mail',          'E-mail de contact affiché en en-tête.'],
        ['org_document_footer', '',     'Mentions de bas de page', 'Texte libre en pied des attestations (agrément, RCCM, IFU…).'],
    ];

    public function up(): void
    {
        $now = now();
        foreach (self::SETTINGS as [$key, $value, $label, $description]) {
            if (! DB::table('system_settings')->where('key', $key)->exists()) {
                DB::table('system_settings')->insert([
                    'key' => $key, 'value' => $value, 'type' => 'string', 'label' => $label,
                    'group' => 'organization', 'description' => $description,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::table('system_settings')->whereIn('key', array_column(self::SETTINGS, 0))->delete();
    }
};
