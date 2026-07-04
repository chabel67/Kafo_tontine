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
                    'member.view',
                    'membership.validate_l1',
                    'payment.view', 'payment.confirm', 'payment.validate',
                    'cash.validate',
                    'loan.view',
                    'treasury.view',
                ]),
            ],
            [
                'name'        => 'manager',
                'label'       => 'Gestionnaire',
                'permissions' => json_encode([
                    'member.view', 'member.manage',
                    'membership.validate_l1', 'membership.validate_l2',
                    'membership.suspend', 'membership.reactivate',
                    'payment.view', 'payment.confirm', 'payment.validate',
                    'payment.cancel',
                    'cash.validate',
                    'loan.view', 'loan.review', 'loan.reject',
                    'campaign.view', 'campaign.manage',
                    'treasury.view', 'treasury.reconcile',
                ]),
            ],
            [
                'name'        => 'admin',
                'label'       => 'Administrateur',
                'permissions' => json_encode([
                    'member.view', 'member.manage', 'member.kyc',
                    'membership.validate_l1', 'membership.validate_l2',
                    'membership.suspend', 'membership.reactivate',
                    'payment.view', 'payment.confirm', 'payment.validate',
                    'payment.cancel', 'payment.unblock',
                    'cash.validate',
                    'loan.view', 'loan.review', 'loan.approve',
                    'loan.reject', 'loan.countersign', 'loan.disburse',
                    'campaign.view', 'campaign.manage', 'campaign.create',
                    'market_calendar.manage',
                    'treasury.view', 'treasury.reconcile',
                    'settings.edit', 'psp.manage',
                    'audit.view', 'admin.users', 'reporting.view',
                ]),
            ],
            [
                'name'        => 'super_admin',
                'label'       => 'Super Administrateur',
                // Wildcard `*` couvre TOUTES les permissions, y compris celles
                // réservées explicitement au super-admin (loan.writeoff,
                // ledger.reverse, campaign.transition).
                'permissions' => json_encode(['*']),
            ],
        ];

        foreach ($roles as $role) {
            DB::table('roles')->updateOrInsert(['name' => $role['name']], $role);
        }
    }
}
