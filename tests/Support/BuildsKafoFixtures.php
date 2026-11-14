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

    /** Demande d'avance en attente (via le service : snapshot R-SAV, verrous, user_id). */
    protected function advanceRequest(Membership $membership, int $amountMinor): \App\Modules\Lending\Infrastructure\Models\LoanRequest
    {
        return app(\App\Modules\Lending\Application\LendingService::class)->createRequest([
            'membership'   => $membership,
            'product_type' => 'advance',
            'amount_minor' => $amountMinor,
        ], (User::first() ?? $this->member())->id);
    }

    /** Avance décaissée en espèces (demande → approbation → décaissement). */
    protected function advance(Membership $membership, int $amountMinor): \App\Modules\Lending\Infrastructure\Models\Loan
    {
        $service = app(\App\Modules\Lending\Application\LendingService::class);
        $staff   = $this->staff('admin');
        $request = $this->advanceRequest($membership, $amountMinor);
        $service->approve($request, $staff->id);

        return $service->disburse($request->fresh(), $staff->id, 'cash', null);
    }

    /** Prêt communautaire décaissé (rattaché au membre, sans adhésion — R-LOAN-16). */
    protected function standardLoan(User $user, int $amountMinor, int $interestBps = 0, int $installments = 2): \App\Modules\Lending\Infrastructure\Models\Loan
    {
        $service = app(\App\Modules\Lending\Application\LendingService::class);
        $staff   = $this->staff('admin');
        $request = $service->createRequest([
            'user'               => $user,
            'product_type'       => 'standard',
            'amount_minor'       => $amountMinor,
            'interest_rate_bps'  => $interestBps ?: null,
            'periodicity'        => 'monthly',
            'installments_count' => $installments,
            'first_due_date'     => now()->addMonth()->toDateString(),
        ], $staff->id);
        $service->approve($request, $staff->id);
        $request = $request->fresh();
        if ($request->status->value === 'countersigning') {
            $service->countersign($request, $this->staff('admin')->id);
        }

        return $service->disburse($request->fresh(), $staff->id, 'cash', null);
    }

    /** Somme débits = somme crédits pour CHAQUE transaction du grand livre. */
    protected function assertLedgerBalanced(): void
    {
        $unbalanced = \Illuminate\Support\Facades\DB::table('ledger_entries')
            ->selectRaw("transaction_id, SUM(CASE WHEN entry_type = 'debit' THEN amount_minor ELSE -amount_minor END) AS diff")
            ->groupBy('transaction_id')
            ->havingRaw("SUM(CASE WHEN entry_type = 'debit' THEN amount_minor ELSE -amount_minor END) <> 0")
            ->count();
        $this->assertSame(0, $unbalanced, 'toutes les transactions du grand livre sont équilibrées');
    }

    protected function savingsBalance(Membership $membership): int
    {
        return app(LedgerService::class)->balance("MEMBER_SAVINGS:{$membership->id}");
    }

    /**
     * Configure les agrégateurs (clés factices) et reconstruit le registre (DA-20).
     * Sans clés, aucun agrégateur n'est disponible pour cotiser.
     */
    protected function configurePsps(bool $fedapayEnabled = true): void
    {
        config()->set('services.kkiapay', [
            'public_key' => 'pk_test', 'private_key' => 'prk_test', 'secret' => 'sk_test',
            'webhook_secret' => 'kkiapay-whsec', 'sandbox' => true, 'networks' => ['mtn', 'moov'],
        ]);
        config()->set('services.fedapay', [
            'public_key' => 'pk_fed', 'secret_key' => 'sk_fed', 'webhook_secret' => 'wh_sandbox_test',
            'sandbox' => true, 'sandbox_api_url' => 'https://sandbox-api.fedapay.com/v1',
            'return_url' => 'http://localhost:5174/cotiser', 'networks' => ['mtn', 'moov', 'celtiis'],
        ]);
        app()->forgetInstance(\App\Modules\Payments\Infrastructure\Psp\PspRegistry::class);

        \Illuminate\Support\Facades\DB::table('system_settings')->where('key', 'psp_kkiapay_enabled')->update(['value' => 'true']);
        \Illuminate\Support\Facades\DB::table('system_settings')->where('key', 'psp_fedapay_enabled')->update(['value' => $fedapayEnabled ? 'true' : 'false']);
    }

    /** Jeton step-up valide pour l'utilisateur (même mécanisme que RequireStepUpToken). */
    protected function stepUpHeader(User $user): array
    {
        $token = Str::random(40);
        Cache::put("step_up:{$token}", $user->id, 300);

        return ['X-Step-Up-Token' => $token];
    }

    /**
     * Rend connu le dernier OTP non consommé d'un numéro (l'API ne l'expose jamais) :
     * on remplace son hash par celui d'un code fixe.
     */
    protected function knownOtp(string $phone, string $purpose): string
    {
        $otp  = \App\Modules\Identity\Infrastructure\Models\OtpCode::where('phone', $phone)
            ->where('purpose', $purpose)
            ->whereNull('consumed_at')
            ->latest()
            ->firstOrFail();
        $code = '424242';
        $otp->update(['code_hash' => Hash::make($code)]);

        return $code;
    }
}
