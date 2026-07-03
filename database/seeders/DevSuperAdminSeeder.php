<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DevSuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        $userId = (string) Str::uuid();

        $existing = DB::table('users')->where('phone', '+22900000001')->first();
        if ($existing) {
            $userId = $existing->id;
        } else {
            DB::table('users')->insert([
                'id'        => $userId,
                'phone'     => '+22900000001',
                'full_name' => 'Super Admin Dev',
                'pin_hash'  => Hash::make('123456'),
                'kyc_level' => 2,
                'kyc_status' => 'verified',
                'status'    => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $superAdminRole = DB::table('roles')->where('name', 'super_admin')->first();
        if ($superAdminRole) {
            DB::table('role_user')->updateOrInsert(
                ['user_id' => $userId, 'role_id' => $superAdminRole->id, 'campaign_id' => null],
                ['created_at' => now(), 'updated_at' => now()],
            );
        }
    }
}
