<?php

namespace Tests\Support;

use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Ledger\Application\LedgerService;
use App\Modules\Ledger\Domain\Enums\AccountType;
use App\Modules\Tontine\Domain\Enums\InstallmentStatus;
use App\Modules\Tontine\Domain\Enums\MembershipStatus;
use App\Modules\Tontine\Infrastructure\Models\Campaign;
use App\Modules\Tontine\Infrastructure\Models\Installment;
use App\Modules\Tontine\Infrastructure\Models\Membership;
use Database\Seeders\RolesSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/**
 * Jeux de données construits explicitement (pas de factories génériques : le schéma Kafo
 * diffère du User Laravel par défaut).
 */
trait BuildsKafoFixtures
{
    private int $phoneSeq = 0;

    protected function seedRoles(): void
    {
        $this->seed(RolesSeeder::class);
    }

    protected function staff(string $role): User
    {
        $user = $this->member(['full_name' => ucfirst($role) . ' Test']);
        $user->roles()->attach(Role::where('name', $role)->value('id'));

        return $user->load('roles');
    }

    protected function member(array $attributes = []): User
    {
        return User::create([
            'phone'     => sprintf('+2290199%04d', ++$this->phoneSeq),
            'full_name' => 'Membre ' . $this->phoneSeq,
            'status'    => UserStatus::Active,
            'pin_hash'  => Hash::make('123456'),
            'kyc_level' => 0,
            ...$attributes,
        ]);
    }

    protected function actingAsStaff(string $role): User
    {
        $user = $this->staff($role);
        Sanctum::actingAs($user);

        return $user;
    }

    protected function campaign(array $attributes = []): Campaign
    {
        $creator = User::first() ?? $this->member();

        return Campaign::create([
            'created_by'                => $creator->id,
            'name'                      => 'Campagne ' . Str::random(5),
            'status'                    => 'active',
            'contribution_mode'         => 'fixed',
            'contribution_amount_minor' => 5000,
            'duration_installments'     => 4,
            'first_installment_date'    => now()->subDays(20)->toDateString(),
            ...$attributes,
        ]);
    }

    protected function membership(User $user, ?Campaign $campaign = null, array $attributes = []): Membership
    {
        $campaign ??= $this->campaign();

        return Membership::create([
            'user_id'                => $user->id,
            'campaign_id'            => $campaign->id,
            'status'                 => MembershipStatus::Active,
            'committed_amount_minor' => $campaign->contribution_amount_minor,
            'activated_at'           => now()->subDays(25),
            ...$attributes,
        ]);
    }

    /** Crée des échéances ; $statuses = ['paid', 'late', 'pending', …] (une par échéance, échues dans le passé sauf pending). */
    protected function installments(Membership $membership, array $statuses): void
    {
        foreach ($statuses as $i => $status) {
            $due = $status === 'pending' ? now()->addDays(5 + $i) : now()->subDays(15 - $i);
            Installment::create([
                'membership_id'          => $membership->id,
                'campaign_id'            => $membership->campaign_id,
                'sequence_number'        => $i + 1,
                'due_date'               => $due->toDateString(),
                'grace_period_ends_date' => $due->copy()->addDays(3)->toDateString(),
                'amount_minor'           => $membership->committed_amount_minor,
                'status'                 => InstallmentStatus::from($status),
                'paid_at'                => $status === 'paid' ? $due : null,
                'late_marked_at'         => $status === 'late' ? $due->copy()->addDays(4) : null,
            ]);
        }
    }

    /** Crédite l'épargne d'une adhésion comme un encaissement cash (DR CASH_BOX / CR MEMBER_SAVINGS). */
    protected function creditSavings(Membership $membership, int $amountMinor): void
    {
        $ledger = app(LedgerService::class);
        $key    = "MEMBER_SAVINGS:{$membership->id}";
        $ledger->openAccount($key, AccountType::MemberSavings, $membership->user_id);
        $ledger->openAccount('CASH_BOX', AccountType::CashBox);
        $ledger->post(
            legs: [
                ['account' => 'CASH_BOX', 'type' => 'debit',  'amount' => $amountMinor],
                ['account' => $key,       'type' => 'credit', 'amount' => $amountMinor],
            ],
            reference:   $ledger->nextReference(),
            description: 'Cotisation test',
        );
    }

    /** Jeton step-up valide pour l'utilisateur (même mécanisme que RequireStepUpToken). */
    protected function stepUpHeader(User $user): array
    {
        $token = Str::random(40);
        Cache::put("step_up:{$token}", $user->id, 300);

        return ['X-Step-Up-Token' => $token];
    }
}
