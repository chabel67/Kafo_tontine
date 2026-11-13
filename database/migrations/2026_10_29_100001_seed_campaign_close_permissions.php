<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Backfill idempotent (même principe que 2026_10_19_200003) :
 *  - trésorier : lecture des campagnes (`campaign.view`) ;
 *  - admin et trésorier : clôture de campagne (`campaign.close`, POST /close sous step-up).
 * Les autres transitions restent `campaign.transition` (super-admin, R-CAMP-01).
 * RolesSeeder porte la même matrice pour les nouvelles installations.
 */
return new class extends Migration
{
    private const MATRIX = [
        'treasurer' => ['campaign.view', 'campaign.close'],
        'admin'     => ['campaign.close'],
    ];

    public function up(): void
    {
        foreach (self::MATRIX as $role => $permissions) {
            $row = DB::table('roles')->where('name', $role)->first();
            if (! $row) continue;

            $current = json_decode($row->permissions ?? '[]', true) ?: [];
            $merged  = array_values(array_unique([...$current, ...$permissions]));
            DB::table('roles')->where('name', $role)->update(['permissions' => json_encode($merged)]);
        }
    }

    public function down(): void
    {
        foreach (self::MATRIX as $role => $permissions) {
            $row = DB::table('roles')->where('name', $role)->first();
            if (! $row) continue;

            $current  = json_decode($row->permissions ?? '[]', true) ?: [];
            $filtered = array_values(array_diff($current, $permissions));
            DB::table('roles')->where('name', $role)->update(['permissions' => json_encode($filtered)]);
        }
    }
};
