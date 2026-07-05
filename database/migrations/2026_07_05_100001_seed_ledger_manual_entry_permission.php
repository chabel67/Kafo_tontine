<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Backfill idempotent : ajoute la permission `ledger.manual_entry` au rôle
 * `admin` sur les environnements déjà seedés avant l'arrivée de la feature
 * R-LEDGER-09.
 */
return new class extends Migration
{
    private const PERMISSION = 'ledger.manual_entry';

    public function up(): void
    {
        $row = DB::table('roles')->where('name', 'admin')->first();
        if (! $row) return;

        $perms = json_decode($row->permissions ?? '[]', true) ?: [];
        if (in_array(self::PERMISSION, $perms, true)) return;

        $perms[] = self::PERMISSION;
        DB::table('roles')->where('name', 'admin')->update([
            'permissions' => json_encode($perms),
        ]);
    }

    public function down(): void
    {
        $row = DB::table('roles')->where('name', 'admin')->first();
        if (! $row) return;

        $perms = json_decode($row->permissions ?? '[]', true) ?: [];
        $filtered = array_values(array_filter($perms, fn ($p) => $p !== self::PERMISSION));
        DB::table('roles')->where('name', 'admin')->update([
            'permissions' => json_encode($filtered),
        ]);
    }
};
