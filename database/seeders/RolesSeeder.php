<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RolesSeeder extends Seeder
{
    public function run(): void
    {
        $roles = [
            [
                'name'        => 'member',
                'label'       => 'Membre',
                'permissions' => json_encode([
                    'member.view_own', 'payment.view_own', 'loan.request',
                ]),
            ],
            [
                'name'        => 'treasurer',
                'label'       => 'Trésorier',
                'permissions' => json_encode([
                    'member.view', 'payment.view', 'payment.validate',
                    'cash.validate', 'loan.view', 'treasury.view',
                ]),
            ],
            [
                'name'        => 'manager',
                'label'       => 'Gestionnaire',
                'permissions' => json_encode([
                    'member.view', 'member.manage', 'payment.view', 'payment.validate',
                    'cash.validate', 'loan.view', 'loan.review', 'campaign.view',
                    'campaign.manage', 'treasury.view', 'treasury.reconcile',
                ]),
            ],
            [
                'name'        => 'admin',
                'label'       => 'Administrateur',
                'permissions' => json_encode([
                    'member.view', 'member.manage', 'member.kyc',
                    'payment.view', 'payment.validate', 'payment.unblock',
                    'cash.validate', 'loan.view', 'loan.review', 'loan.approve',
                    'campaign.view', 'campaign.manage', 'campaign.create',
                    'treasury.view', 'treasury.reconcile',
                    'audit.view', 'admin.users', 'reporting.view',
                ]),
            ],
            [
                'name'        => 'super_admin',
                'label'       => 'Super Administrateur',
                'permissions' => json_encode(['*']),
            ],
        ];

        foreach ($roles as $role) {
            DB::table('roles')->updateOrInsert(['name' => $role['name']], $role);
        }
    }
}
