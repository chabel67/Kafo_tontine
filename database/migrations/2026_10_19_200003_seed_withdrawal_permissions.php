<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Backfill idempotent des permissions « retraits anticipés » sur les
 * environnements déjà seedés (même principe que 2026_07_05_100001).
 * RolesSeeder porte la même matrice pour les nouvelles installations.
 */
return new class extends Migration
{
    private const MATRIX = [
        'member'    => ['withdrawal.request'],
        'treasurer' => ['withdrawal.view'],
        'manager'   => ['withdrawal.view', 'withdrawal.request', 'withdrawal.review'],
        'admin'     => ['withdrawal.view', 'withdrawal.request', 'withdrawal.review', 'withdrawal.disburse'],
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
