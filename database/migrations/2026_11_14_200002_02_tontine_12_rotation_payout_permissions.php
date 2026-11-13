<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Tontines rotatives — lot 5 (distributions) :
 *  - permissions `rotation.approve` (gestionnaire, admin) et `rotation.disburse` (admin) ;
 *    le super-admin les a via `*`. Le versant doit être différent du validateur (D-R9).
 *  - séquence des références de distribution (RND-AAAA-NNNNNN, App\Shared\Reference).
 * RolesSeeder porte la même matrice pour les nouvelles installations.
 */
return new class extends Migration
{
    private const MATRIX = [
        'manager' => ['rotation.approve'],
        'admin'   => ['rotation.approve', 'rotation.disburse'],
    ];

    public function up(): void
    {
        foreach (self::MATRIX as $role => $permissions) {
            $row = DB::table('roles')->where('name', $role)->first();
            if (! $row) continue;

            $current = json_decode($row->permissions ?? '[]', true) ?: [];
            DB::table('roles')->where('name', $role)
                ->update(['permissions' => json_encode(array_values(array_unique([...$current, ...$permissions])))]);
        }

        DB::statement('CREATE SEQUENCE IF NOT EXISTS ref_rnd_seq START 1');
    }

    public function down(): void
    {
        DB::statement('DROP SEQUENCE IF EXISTS ref_rnd_seq');

        foreach (self::MATRIX as $role => $permissions) {
            $row = DB::table('roles')->where('name', $role)->first();
            if (! $row) continue;

            $current = json_decode($row->permissions ?? '[]', true) ?: [];
            DB::table('roles')->where('name', $role)
                ->update(['permissions' => json_encode(array_values(array_diff($current, $permissions)))]);
        }
    }
};
