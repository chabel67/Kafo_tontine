<?php

namespace App\Console\Commands;

use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Ledger\Application\LedgerManualEntryService;
use App\Modules\Ledger\Domain\Enums\ManualEntryCategory;
use App\Modules\Lending\Application\LendingService;
use App\Modules\Lending\Infrastructure\Models\Loan;
use App\Modules\Lending\Infrastructure\Models\LoanRequest;
use App\Modules\Lending\Jobs\MarkLateLoanRepaymentsJob;
use App\Modules\Payments\Application\PaymentService;
use App\Modules\Payments\Domain\Enums\PaymentChannel;
use App\Modules\Tontine\Application\CampaignClosureService;
use App\Modules\Tontine\Application\CampaignPayoutService;
use App\Modules\Tontine\Application\CampaignRenewalService;
use App\Modules\Tontine\Application\CampaignService;
use App\Modules\Tontine\Application\EarlyWithdrawalService;
use App\Modules\Tontine\Application\MembershipService;
use App\Modules\Tontine\Application\RotationAssignmentService;
use App\Modules\Tontine\Application\RotationPayoutService;
use App\Modules\Tontine\Application\RotationScheduleService;
use App\Modules\Tontine\Application\RotationTrackingService;
use App\Modules\Tontine\Domain\Enums\CampaignStatus;
use App\Modules\Tontine\Domain\Enums\InstallmentStatus;
use App\Modules\Tontine\Infrastructure\Models\Campaign;
use App\Modules\Tontine\Infrastructure\Models\CampaignPayout;
use App\Modules\Tontine\Infrastructure\Models\Installment;
use App\Modules\Tontine\Infrastructure\Models\MarketCalendar;
use App\Modules\Tontine\Infrastructure\Models\Membership;
use App\Modules\Tontine\Infrastructure\Models\RotationRound;
use App\Modules\Tontine\Jobs\MarkLateInstallmentsJob;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Données de démonstration réalistes (présentation) construites par les VRAIS services,
 * en avançant l'horloge jour après jour : écritures équilibrées, échéances, audit et
 * notifications cohérents. Une seule exécution : sans effet si les données existent déjà.
 * Tout se fait dans une transaction : une erreur ne laisse rien en base.
 *
 * Personnes fictives. Code PIN de tous les comptes de démonstration : 123456.
 */
class SeedDemoData extends Command
{
    protected $signature   = 'kafo:seed-demo';
    protected $description = 'Crée un jeu de données de démonstration complet (une seule fois).';

    private const MARKER_PHONE = '+2290197000001';
    private const PIN = '123456';

    private Carbon $today;
    private User $super;
    private User $treasurer;
    private User $manager;
    private User $admin;
    private User $admin2;
    /** @var User[] */
    private array $people = [];
    private int $seq = 0;

    /** Scénarios ajoutés après la première diffusion : chacun a son propre marqueur. */
    private const CLOSED_ROTATION = 'Tontine des vendeuses de Ganhi';

    public function handle(): int
    {
        $super = User::where('phone', '+22900000001')->first()
            ?? User::whereHas('roles', fn ($q) => $q->where('name', 'super_admin'))->first();
        if (! $super) {
            $this->error('[kafo:seed-demo] Aucun super-administrateur : lancer RolesSeeder et DevSuperAdminSeeder d\'abord.');

            return self::FAILURE;
        }

        $this->today = Carbon::today();
        $this->super = $super;
        mt_srand(20261114);
        $base   = ! User::where('phone', self::MARKER_PHONE)->exists();
        $closed = ! Campaign::where('name', self::CLOSED_ROTATION)->exists();
        if (! $base && ! $closed) {
            $this->info('[kafo:seed-demo] Données de démonstration déjà présentes : rien à faire.');

            return self::SUCCESS;
        }

        try {
            DB::transaction(function () use ($base, $closed) {
                $this->at(-140);
                $this->createStaff();
                if ($base) {
                    $this->createPeople();
                    $this->savingsMarket();
                    $this->savingsWomen();
                    $this->schoolCycles();
                    $this->rotatingTraders();
                    $this->openAndDraftCampaigns();
                    $this->loans();
                    $this->manualEntries();
                } else {
                    $this->people = User::where('phone', 'like', '+22901970%')->orderBy('phone')->get()->all();
                }
                if ($closed) {
                    $this->closedRotation();
                }
            });
        } finally {
            Carbon::setTestNow();
        }

        // Jobs quotidiens à la date réelle : retards d'échéances et de prêts.
        app()->call([new MarkLateInstallmentsJob(), 'handle']);
        app()->call([new MarkLateLoanRepaymentsJob(), 'handle']);

        $this->info('[kafo:seed-demo] Données de démonstration créées. PIN des comptes : ' . self::PIN);
        $this->table(['Élément', 'Total en base'], [
            ['Membres et personnel', User::count()],
            ['Campagnes', Campaign::count()],
            ['Adhésions', Membership::count()],
            ['Paiements confirmés', DB::table('payments')->where('status', 'confirmed')->count()],
            ['Échéances en retard', Installment::where('status', 'late')->count()],
            ['Demandes de prêt / prêts', LoanRequest::count() . ' / ' . Loan::count()],
            ['Retraits anticipés', DB::table('early_withdrawals')->count()],
            ['Transactions comptables', DB::table('ledger_transactions')->count()],
        ]);

        return self::SUCCESS;
    }

    // ── Horloge ──────────────────────────────────────────────────────────

    /** Place l'horloge à J+$offset (relatif à aujourd'hui), à l'heure donnée. */
    private function at(int $offset, int $hour = 9, int $minute = 0): Carbon
    {
        $now = $this->today->copy()->addDays($offset)->setTime($hour, $minute);
        Carbon::setTestNow($now);

        return $now;
    }

    private function day(int $offset): string
    {
        return $this->today->copy()->addDays($offset)->toDateString();
    }

    private function offsetOf(Carbon|string $date): int
    {
        return (int) $this->today->diffInDays(Carbon::parse($date)->startOfDay(), false);
    }

    // ── Personnes ────────────────────────────────────────────────────────

    private function createStaff(): void
    {
        $this->treasurer = $this->staffUser('+2290197900001', 'Rachidatou Bello', 'treasurer');
        $this->manager   = $this->staffUser('+2290197900002', 'Koffi Hounkpatin', 'manager');
        $this->admin     = $this->staffUser('+2290197900003', 'Sènan Agossou', 'admin');
        $this->admin2    = $this->staffUser('+2290197900004', 'Mariam Soumanou', 'admin');
    }

    private function staffUser(string $phone, string $name, string $role): User
    {
        $user = User::where('phone', $phone)->first() ?? User::create([
            'phone' => $phone, 'full_name' => $name, 'status' => UserStatus::Active,
            'pin_hash' => Hash::make(self::PIN), 'kyc_level' => 2,
        ]);
        $roleId = Role::where('name', $role)->value('id');
        if (! $user->roles()->where('roles.id', $roleId)->exists()) {
            $user->roles()->attach($roleId);
        }

        return $user->load('roles');
    }

    private function createPeople(): void
    {
        $names = [
            'Awa Dossou', 'Bio Sanni', 'Koffi Agbo', 'Afiavi Houngbédji', 'Rémi Akplogan', 'Chimène Adjovi',
            'Ganiou Lawani', 'Fifamè Ahouansou', 'Sika Gbaguidi', 'Moussa Yacoubou', 'Edwige Kpadonou', 'Fatoumata Issa',
            'Rodrigue Hounsou', 'Blandine Tossou', 'Ismaël Bachabi', 'Yvette Zinsou', 'Pélagie Assogba', 'Abdou Karimou',
            'Clarisse Dégbé', 'Hermann Fagla', 'Nafissatou Alassane', 'Prudence Avocè', 'Sylvain Gnonlonfoun', 'Adjoa Mensah',
            'Martine Kiki', 'Djamilou Orou', 'Rosine Amoussou', 'Euloge Ahanhanzo', 'Bérénice Djossou', 'Zénabou Chabi',
            'Arnaud Quenum', 'Huguette Sossa', 'Valentin Kouton', 'Gisèle Hounyèmè', 'Salifou Bouraïma', 'Odile Atchadé',
            'Narcisse Glèlè', 'Aïchatou Moumouni', 'Félicité Zannou', 'Théophile Akakpo', 'Rachelle Vodounou', 'Ousmane Sabi',
            'Victoire Adandé', 'Gilles Tchibozo', 'Mireille Ayéna', 'Bachirou Seidou', 'Lucienne Dansou', 'Firmin Ogoubiyi',
        ];
        $localities = ['Cotonou', 'Porto-Novo', 'Abomey-Calavi', 'Ouidah', 'Bohicon', 'Parakou', 'Adjarra', 'Sèmè-Podji'];
        foreach ($names as $i => $name) {
            $this->at(-140 + intdiv($i, 4));
            $this->people[] = User::create([
                'phone'      => sprintf('+22901970%05d', $i + 1),
                'full_name'  => $name,
                'status'     => UserStatus::Active,
                'pin_hash'   => Hash::make(self::PIN),
                'kyc_level'  => $i % 5 === 0 ? 0 : ($i % 3 === 0 ? 2 : 1),
                'locality'   => $localities[$i % count($localities)],
                'birth_date' => Carbon::create(1965 + ($i * 7) % 35, 1 + $i % 12, 1 + ($i * 3) % 27)->toDateString(),
            ]);
        }
        // Deux comptes à activer, un compte suspendu : visibles dans la liste des membres.
        $this->at(-10);
        foreach (['Jacob Hounkanrin', 'Estelle Amoussa'] as $k => $name) {
            User::create(['phone' => sprintf('+22901971%05d', $k + 1), 'full_name' => $name,
                'status' => UserStatus::Pending, 'kyc_level' => 0, 'locality' => 'Cotonou']);
        }
    }

    /** @return User[] */
    private function pick(int $from, int $count): array
    {
        return array_slice($this->people, $from, $count);
    }

    // ── Briques communes ─────────────────────────────────────────────────

    private function calendar(string $name, int $n, string $description): MarketCalendar
    {
        return MarketCalendar::firstOrCreate(['name' => $name], [
            'cadence_type' => 'every_n_days', 'cadence_value' => ['n' => $n],
            'timezone' => 'Africa/Cotonou', 'description' => $description,
        ]);
    }

    private function campaign(array $data, int $createdOffset): Campaign
    {
        $this->at($createdOffset, 8);

        return app(CampaignService::class)->create($data, $this->super);
    }

    private function transition(Campaign $campaign, CampaignStatus $to, int $offset, string $reason): Campaign
    {
        $this->at($offset, 8, 30);

        return app(CampaignService::class)->transition($campaign->fresh(), $to, $this->super, $reason);
    }

    /** Enrôle, encaisse les frais d'adhésion puis valide (L1, et L2 si la campagne l'exige). */
    private function enrollAndValidate(User $user, Campaign $campaign, int $offset, ?int $committed = null, bool $validate = true): Membership
    {
        $this->at($offset, 10, $this->seq++ % 50);
        $m = app(MembershipService::class)->enroll($user, $campaign->fresh(), $committed);
        if (! $validate) {
            return $m;
        }
        $fee = (int) $m->fresh()->membership_fee_minor;
        if ($fee > 0) {
            app(PaymentService::class)->recordMembershipFee($m->fresh(), $fee, $this->treasurer->id, 'Frais d\'adhésion');
        }
        $m = app(MembershipService::class)->validateL1($m->fresh(), $this->treasurer);
        if ($m->status->value === 'pending_l2') {
            $this->at($offset, 15);
            $m = app(MembershipService::class)->validateL2($m->fresh(), $this->manager);
        }

        return $m->fresh();
    }

    private function payCash(Membership $m, int $amount, string $note = 'Cotisation'): void
    {
        app(PaymentService::class)->recordCash($m->fresh(), $amount, $this->treasurer->id, $note, force: true, confirmAtypical: true);
    }

    private function payMomo(Membership $m, int $amount, PaymentChannel $channel): void
    {
        $user = $m->user;
        $payment = app(PaymentService::class)->initiateMomo($m->fresh(), $amount, $channel, $user->phone, $user->id);
        app(PaymentService::class)->confirmFromWebhook($payment->idempotency_key, 'KKP' . strtoupper(Str::random(10)), $channel->value);
    }

    /**
     * Cotisations d'une adhésion jusqu'à aujourd'hui selon un profil :
     * regular (à l'heure), late (retards, rattrapage groupé), stopped (arrête en cours), ahead (paie d'avance).
     */
    private function contribute(Membership $m, string $profile, int $stopAfter = 0): void
    {
        $amount = (int) $m->committed_amount_minor;
        $due = Installment::where('membership_id', $m->id)->orderBy('sequence_number')->get()
            ->filter(fn ($i) => $i->due_date->lte($this->today));
        $backlog = 0;
        foreach ($due->values() as $k => $inst) {
            $offset = $this->offsetOf($inst->due_date);
            $channel = (crc32($m->id . $k) % 10) < 6 ? null : ((crc32($m->id . $k) % 2) ? PaymentChannel::Mtn : PaymentChannel::Moov);
            if ($profile === 'stopped' && $k >= $stopAfter) {
                return;
            }
            if ($profile === 'late' && $k % 4 !== 3 && $k < $due->count() - 1) {
                $backlog++;
                continue;
            }
            $count = 1 + ($profile === 'late' ? $backlog : 0) + ($profile === 'ahead' && $k % 5 === 0 ? 1 : 0);
            $backlog = 0;
            $this->at(min(0, $offset + ($profile === 'late' ? 1 : 0)), 8 + $k % 9, ($k * 7) % 60);
            $channel ? $this->payMomo($m, $amount * $count, $channel) : $this->payCash($m, $amount * $count);
        }
    }

    // ── Campagne A : épargne de marché, la plus fournie ──────────────────

    private function savingsMarket(): void
    {
        $cal = $this->calendar('Marché Dantokpa (5 jours)', 5, 'Cycle de marché de 5 jours — Cotonou');
        $c = $this->campaign([
            'name' => 'Épargne Marché Dantokpa 2026', 'description' => 'Épargne des commerçants de Dantokpa : cotisation chaque jour de marché.',
            'contribution_mode' => 'fixed', 'contribution_amount_minor' => 2000, 'duration_installments' => 40,
            'first_installment_date' => $this->day(-100), 'market_calendar_id' => $cal->id, 'grace_period_days' => 2,
            'membership_fee_minor' => 500, 'allow_early_withdrawals' => true, 'max_members' => 30,
        ], -112);
        $this->transition($c, CampaignStatus::Open, -110, 'Ouverture des adhésions.');

        $members = [];
        foreach ($this->pick(0, 24) as $i => $u) {
            $members[] = $this->enrollAndValidate($u, $c, -109 + intdiv($i, 4));
        }
        $this->transition($c, CampaignStatus::Active, -101, 'Démarrage des cotisations.');

        foreach ($members as $i => $m) {
            $profile = match (true) {
                in_array($i, [3, 11, 17], true) => 'late',
                in_array($i, [7, 20], true)     => 'stopped',
                in_array($i, [1, 9, 14], true)  => 'ahead',
                default                         => 'regular',
            };
            $this->contribute($m, $profile, $i === 7 ? 9 : 14);
        }
        $this->withdrawals($members);
        $this->advances($members);
        $this->market = $members;
    }

    /** @var Membership[] */
    private array $market = [];
    /** @var Membership[] */
    private array $women = [];

    // ── Campagne B : épargne en mode libre, validation à deux niveaux ────

    private function savingsWomen(): void
    {
        $cal = $this->calendar('Hebdomadaire (7 jours)', 7, 'Cotisation hebdomadaire');
        $c = $this->campaign([
            'name' => 'Épargne Femmes Entrepreneures de Cotonou', 'description' => 'Montant libre choisi par chaque membre, entre 1 000 et 10 000 FCFA par semaine.',
            'contribution_mode' => 'custom', 'min_contribution_minor' => 1000, 'max_contribution_minor' => 10000,
            'duration_installments' => 30, 'first_installment_date' => $this->day(-77), 'market_calendar_id' => $cal->id,
            'grace_period_days' => 3, 'membership_fee_minor' => 1000, 'allow_early_withdrawals' => true,
        ], -90);
        $c->update(['rules' => array_merge($c->rules ?? [], ['require_l2_validation' => true])]);
        $this->transition($c, CampaignStatus::Open, -88, 'Ouverture des adhésions.');

        $amounts = [5000, 3000, 10000, 2500, 7500, 1500, 4000, 6000, 2000, 8000, 3500, 5000, 1000, 9000, 4500, 6500];
        foreach ($this->pick(24, 16) as $i => $u) {
            $this->women[] = $this->enrollAndValidate($u, $c, -86 + intdiv($i, 3), $amounts[$i]);
        }
        $this->transition($c, CampaignStatus::Active, -78, 'Démarrage des cotisations.');
        foreach ($this->women as $i => $m) {
            $this->contribute($m, match ($i % 7) { 2 => 'late', 5 => 'ahead', default => 'regular' });
        }
        $this->withdrawals(array_slice($this->women, 2, 6), true);
    }

    // ── Campagne C : cycle clôturé, réglé, puis reconduit (cycle 2) ──────

    private function schoolCycles(): void
    {
        $cal = $this->calendar('Hebdomadaire (7 jours)', 7, 'Cotisation hebdomadaire');
        $c = $this->campaign([
            'name' => 'Épargne Rentrée scolaire', 'description' => 'Épargner pour les fournitures et la scolarité des enfants.',
            'contribution_mode' => 'fixed', 'contribution_amount_minor' => 5000, 'duration_installments' => 12,
            'first_installment_date' => $this->day(-135), 'market_calendar_id' => $cal->id, 'grace_period_days' => 2,
            'allow_early_withdrawals' => false,
        ], -140);
        $this->transition($c, CampaignStatus::Open, -139, 'Ouverture des adhésions.');
        $members = [];
        foreach (array_merge($this->pick(0, 6), $this->pick(30, 8)) as $i => $u) {
            $members[] = $this->enrollAndValidate($u, $c, -138 + intdiv($i, 5));
        }
        $this->transition($c, CampaignStatus::Active, -136, 'Démarrage des cotisations.');
        foreach ($members as $i => $m) {
            $this->contributeUntil($m, -57, $i === 4 ? 9 : 12);
        }

        // Clôture et règlement de tous les payouts (espèces ou MoMo).
        $this->at(-55, 9);
        app(CampaignClosureService::class)->close($c->fresh(), $this->super, 'Fin du cycle : rentrée scolaire.');
        foreach (CampaignPayout::where('campaign_id', $c->id)->get() as $k => $payout) {
            $this->at(-54 + intdiv($k, 5), 10 + $k % 6);
            $channel = $k % 3 === 0 ? 'mtn' : 'cash';
            app(CampaignPayoutService::class)->settle($payout->fresh(), $channel, $channel === 'cash' ? null : $payout->membership->user->phone,
                $this->manager, (string) Str::uuid());
        }

        // Reconduction : un débiteur retiré, deux nouveaux membres.
        $this->at(-45, 11);
        $service = app(CampaignRenewalService::class);
        $kept = [];
        foreach ($service->candidates($c->fresh()) as $cand) {
            if ($service->debt($cand)['minor'] > 0) {
                continue;   // débiteur de l'ancien cycle : décoché (R-REN-04)
            }
            $kept[] = ['membership_id' => $cand->id];
        }
        $payload = [
            'first_installment_date' => $this->day(-35),
            'contribution_amount_minor' => 6000,
            'members' => array_merge($kept, [['user_id' => $this->people[40]->id], ['user_id' => $this->people[41]->id]]),
        ];
        $cycle2 = $service->confirm($c->fresh(), $payload, (string) Str::uuid(), $this->super);

        $pending = Membership::where('campaign_id', $cycle2->id)->orderBy('created_at')->get();
        $active = [];
        foreach ($pending as $k => $m) {
            if ($k >= $pending->count() - 2) {
                continue;   // deux adhésions laissées « en attente de validation »
            }
            $this->at(-42 + intdiv($k, 6), 10, $k);
            $active[] = app(MembershipService::class)->validateL1($m->fresh(), $this->treasurer);
        }
        $this->transition($cycle2, CampaignStatus::Active, -36, 'Démarrage du cycle 2.');
        foreach ($active as $i => $m) {
            $this->contribute($m->fresh(), $i % 5 === 3 ? 'late' : 'regular');
        }
    }

    /** Paie les $count premières échéances, chacune à sa date (jusqu'à $untilOffset). */
    private function contributeUntil(Membership $m, int $untilOffset, int $count): void
    {
        $amount = (int) $m->committed_amount_minor;
        foreach (Installment::where('membership_id', $m->id)->orderBy('sequence_number')->take($count)->get() as $k => $inst) {
            $this->at(min($untilOffset, $this->offsetOf($inst->due_date)), 9 + $k % 8, $k * 5);
            $k % 3 === 2 ? $this->payMomo($m, $amount, PaymentChannel::Mtn) : $this->payCash($m, $amount);
        }
    }

    // ── Campagne D : tontine rotative en cours ───────────────────────────

    private function rotatingTraders(): void
    {
        $first = $this->today->copy()->addDays(-49);
        $c = $this->campaign([
            'name' => 'Tontine des commerçantes d\'Adjarra', 'description' => 'Huit commerçantes cotisent 10 000 FCFA chaque semaine ; chacune reçoit la cagnotte à son tour.',
            'campaign_type' => 'rotating', 'contribution_mode' => 'fixed', 'contribution_amount_minor' => 10000,
            'max_members' => 8, 'installments_per_round' => 1, 'first_installment_date' => $first->toDateString(),
            'contribution_schedule' => ['type' => 'weekly', 'weekday' => strtolower($first->englishDayOfWeek), 'every_weeks' => 1],
            'rotation_fee_mode' => 'payout_deduction', 'rotation_fee_minor' => 2000, 'grace_period_days' => 1,
        ], -62);
        $this->at(-61);
        app(RotationScheduleService::class)->confirm($c->fresh());
        $this->transition($c, CampaignStatus::Open, -60, 'Ouverture des adhésions.');

        $members = [];
        foreach (array_merge($this->pick(10, 4), $this->pick(26, 4)) as $i => $u) {
            $members[] = $this->enrollAndValidate($u, $c, -59 + intdiv($i, 3));
        }
        $this->at(-52, 16);
        $assign = app(RotationAssignmentService::class);
        $draw = $assign->draw($c->fresh(), 4242);
        $assign->assign($c->fresh(), $draw['order'], 'draw', $draw['seed']);
        $this->at(-50, 9);
        $assign->start($c->fresh(), $this->super);

        // Cotisations hebdomadaires. Un membre paie la semaine J-14 avec 3 jours de retard
        // et n'a pas encore payé celle de J-7 (pénalité) : le tour 7 reste « bloqué ».
        $late = $members[5]->id;
        foreach ($members as $m) {
            foreach (Installment::where('membership_id', $m->id)->orderBy('sequence_number')->get() as $k => $inst) {
                $offset = $this->offsetOf($inst->due_date);
                if ($offset >= 0 || ($m->id === $late && $offset === -7)) {
                    continue;
                }
                $payOffset = $m->id === $late && $offset === -14 ? -11 : $offset;
                $this->at($payOffset, 7 + $k % 5, ($k * 11) % 60);
                $k % 2 ? $this->payMomo($m, 10000, PaymentChannel::Moov) : $this->payCash($m, 10000, 'Cotisation tontine');
            }
        }
        $lateInst = Installment::where('membership_id', $late)->where('due_date', $this->day(-7))->first();
        if ($lateInst) {
            $this->at(-4, 11);
            app(RotationTrackingService::class)->applyPenalty($lateInst->fresh(), 1000, 'Retard de plus de trois jours constaté au marché.', $this->manager);
        }

        // Tours 1 à 5 versés ; tour 6 validé et versement lancé (à confirmer) ; tour 7 bloqué ; tour 8 en collecte.
        $payouts = app(RotationPayoutService::class);
        foreach (RotationRound::where('campaign_id', $c->id)->orderBy('round_number')->get() as $round) {
            $offset = $this->offsetOf($round->scheduled_date);
            if ($offset > -14) {
                continue;
            }
            $approveAt = $offset === -14 ? -11 : $offset;
            $this->at($approveAt, 17);
            $round = $payouts->approve($round->fresh(), $this->manager, null, null);
            $this->at($approveAt + 1, 10);
            $momo = $offset % 14 === 0;
            $payouts->initiate($round->fresh(), $momo ? 'mtn' : 'cash',
                $momo ? Membership::find($round->beneficiary_membership_id)->user->phone : null, (string) Str::uuid(), $this->admin);
            if ($offset !== -14) {
                $payouts->confirm($round->fresh(), 'REMISE-' . abs($offset), $this->admin);
            }
        }
    }

    // ── Tontine terminée et clôturée (cycle 1), reconduite et en cours (cycle 2) ──

    private function closedRotation(): void
    {
        $first = $this->today->copy()->addDays(-126);
        $c = $this->campaign([
            'name' => self::CLOSED_ROTATION, 'description' => 'Six vendeuses cotisent 15 000 FCFA chaque semaine (+ 500 FCFA de frais) ; cycle terminé et clôturé.',
            'campaign_type' => 'rotating', 'contribution_mode' => 'fixed', 'contribution_amount_minor' => 15000,
            'max_members' => 6, 'installments_per_round' => 1, 'first_installment_date' => $first->toDateString(),
            'contribution_schedule' => ['type' => 'weekly', 'weekday' => strtolower($first->englishDayOfWeek), 'every_weeks' => 1],
            'rotation_fee_mode' => 'contribution_surcharge', 'rotation_fee_minor' => 500, 'grace_period_days' => 1,
        ], -138);
        $this->at(-137);
        app(RotationScheduleService::class)->confirm($c->fresh());
        $this->transition($c, CampaignStatus::Open, -136, 'Ouverture des adhésions.');

        $members = [];
        foreach ($this->pick(30, 6) as $i => $u) {
            $members[] = $this->enrollAndValidate($u, $c, -135 + intdiv($i, 3));
        }
        $assign = app(RotationAssignmentService::class);
        $this->at(-130, 16);
        $draw = $assign->draw($c->fresh(), 2026);
        $assign->assign($c->fresh(), $draw['order'], 'draw', $draw['seed']);
        $this->at(-128, 9);
        $assign->start($c->fresh(), $this->super);

        // Cotisations : un retard pénalisé (payé avec la pénalité), un trop-perçu de 3 000 la dernière semaine.
        $tracking = app(RotationTrackingService::class);
        foreach ($members as $i => $m) {
            foreach (Installment::where('membership_id', $m->id)->orderBy('sequence_number')->get() as $k => $inst) {
                $offset = $this->offsetOf($inst->due_date);
                $amount = (int) $inst->amount_minor;
                if ($i === 2 && $k === 2) {
                    $this->at($offset + 2, 9);
                    $tracking->applyPenalty($inst->fresh(), 1500, 'Cotisation arrivée deux jours après le marché.', $this->manager);
                    $this->at($offset + 2, 15);
                    $this->payCash($m, $amount + 1500, 'Cotisation et pénalité de retard');
                    continue;
                }
                $extra = $i === 4 && $k === 5 ? 3000 : 0;
                $this->at($offset, 8 + $k % 6, ($i * 9) % 60);
                $k % 2 ? $this->payMomo($m, $amount + $extra, PaymentChannel::Mtn) : $this->payCash($m, $amount + $extra, 'Cotisation tontine');
            }
        }

        $payouts = app(RotationPayoutService::class);
        foreach (RotationRound::where('campaign_id', $c->id)->orderBy('round_number')->get() as $round) {
            $offset = $this->offsetOf($round->scheduled_date);
            $this->at($offset + ($round->round_number === 3 ? 2 : 0), 17);
            $round = $payouts->approve($round->fresh(), $this->manager, null, null);
            $this->at($offset + 3, 10);
            $payouts->initiate($round->fresh(), 'cash', null, (string) Str::uuid(), $this->admin);
            $payouts->confirm($round->fresh(), 'REMISE-GANHI-' . $round->round_number, $this->admin);
        }

        // Reliquat (D-R11) : 2 000 rendus au membre (deux temps), 1 000 passés en produit, puis clôture.
        $this->at(-85, 10);
        $cash = app(\App\Modules\Tontine\Application\RotationCashOutService::class);
        $attempt = $cash->initiateSurplus($c->fresh(), $members[4]->fresh(), 2000, 'Rendu du trop-perçu de la dernière semaine.',
            'cash', null, (string) Str::uuid(), $this->admin);
        $cash->confirm($attempt->fresh(), 'RELIQUAT-1', $this->admin2);
        $closure = app(\App\Modules\Tontine\Application\RotationClosureService::class);
        $this->at(-85, 15);
        $closure->surplusToRevenue($c->fresh(), 1000, 'Solde décidé en assemblée : frais de réunion.', $this->super);
        $this->at(-84, 11);
        $closure->close($c->fresh(), $this->super);

        // Cycle 2 : mêmes membres, ordre du cycle 1 repris, démarré il y a un mois.
        $this->at(-40, 10);
        $renewal = app(CampaignRenewalService::class);
        $cycle2 = $renewal->confirm($c->fresh(), [
            'first_installment_date' => $this->day(-28),
            'members' => array_map(fn ($m) => ['membership_id' => $m->id], $members),
        ], (string) Str::uuid(), $this->super);

        $byUser = [];
        foreach (Membership::where('campaign_id', $cycle2->id)->get() as $k => $m) {
            $this->at(-38 + intdiv($k, 3), 10, $k);
            $byUser[$m->user_id] = app(MembershipService::class)->validateL1($m->fresh(), $this->treasurer);
        }
        $previous = RotationRound::where('campaign_id', $c->id)->orderBy('round_number')->get();
        $order = $previous->map(fn ($r) => $byUser[Membership::find($r->beneficiary_membership_id)->user_id]->id)->all();
        $this->at(-31, 16);
        $assign->assign($cycle2->fresh(), $order, 'manual', null);
        $this->at(-29, 9);
        $assign->start($cycle2->fresh(), $this->super);

        foreach ($byUser as $m) {
            foreach (Installment::where('membership_id', $m->id)->orderBy('sequence_number')->get() as $k => $inst) {
                $offset = $this->offsetOf($inst->due_date);
                if ($offset >= 0) {
                    continue;
                }
                $this->at($offset, 9 + $k % 5);
                $k % 2 ? $this->payMomo($m, (int) $inst->amount_minor, PaymentChannel::Moov) : $this->payCash($m, (int) $inst->amount_minor, 'Cotisation tontine');
            }
        }
        foreach (RotationRound::where('campaign_id', $cycle2->id)->orderBy('round_number')->get() as $round) {
            $offset = $this->offsetOf($round->scheduled_date);
            if ($offset > -14) {
                continue;
            }
            $this->at($offset, 17);
            $round = $payouts->approve($round->fresh(), $this->manager, null, null);
            $this->at($offset + 1, 10);
            $payouts->initiate($round->fresh(), 'cash', null, (string) Str::uuid(), $this->admin);
            $payouts->confirm($round->fresh(), 'REMISE-GANHI2-' . $round->round_number, $this->admin);
        }
    }

    // ── Campagnes ouvertes et brouillons ─────────────────────────────────

    private function openAndDraftCampaigns(): void
    {
        $cal = $this->calendar('Hebdomadaire (7 jours)', 7, 'Cotisation hebdomadaire');
        $open = $this->campaign([
            'name' => 'Épargne Agriculteurs de Glazoué', 'description' => 'Préparer la prochaine saison : semences et engrais.',
            'contribution_mode' => 'fixed', 'contribution_amount_minor' => 3000, 'duration_installments' => 24,
            'first_installment_date' => $this->day(12), 'market_calendar_id' => $cal->id, 'membership_fee_minor' => 1000,
        ], -9);
        $this->transition($open, CampaignStatus::Open, -8, 'Ouverture des adhésions.');
        foreach ($this->pick(42, 6) as $i => $u) {
            $this->enrollAndValidate($u, $open, -7 + $i, null, $i < 3);
        }

        $rot = $this->campaign([
            'name' => 'Tontine des artisans de Porto-Novo', 'description' => 'Dix artisans, cotisation de 5 000 FCFA tous les jours de marché.',
            'campaign_type' => 'rotating', 'contribution_mode' => 'fixed', 'contribution_amount_minor' => 5000,
            'max_members' => 10, 'installments_per_round' => 2, 'first_installment_date' => $this->day(20),
            'contribution_schedule' => ['type' => 'market_calendar', 'market_calendar_id' => $this->calendar('Marché Dantokpa (5 jours)', 5, '')->id],
            'rotation_fee_mode' => 'contribution_surcharge', 'rotation_fee_minor' => 250,
        ], -3);
        $this->at(-3, 9);
        app(RotationScheduleService::class)->confirm($rot->fresh());

        $this->campaign([
            'name' => 'Épargne Tabaski 2027', 'description' => 'Brouillon : épargne pour la fête, à finaliser.',
            'contribution_mode' => 'fixed', 'contribution_amount_minor' => 1500, 'duration_installments' => 30,
            'first_installment_date' => $this->day(30), 'market_calendar_id' => $cal->id,
        ], -1);
    }

    // ── Retraits anticipés à tous les stades ─────────────────────────────

    /** @param Membership[] $members */
    private function withdrawals(array $members, bool $short = false): void
    {
        $svc = app(EarlyWithdrawalService::class);
        $stages = $short ? ['disbursed', 'pending', 'approved'] : ['disbursed', 'disbursed', 'pending', 'under_review', 'info_requested', 'approved', 'rejected', 'cancelled', 'processing'];
        foreach ($stages as $k => $stage) {
            $m = $members[($k * 2 + 1) % count($members)]->fresh();
            $available = $svc->available($m)['available_minor'] ?? 0;
            $amount = (int) (floor(min($available * 0.4, 15000) / 500) * 500);
            if ($amount < 1000) {
                continue;
            }
            $offset = $stage === 'disbursed' ? -20 + $k * 3 : -6 + $k % 5;
            $this->at($offset, 9 + $k);
            $channel = $k % 2 ? 'cash' : 'mtn';
            $w = $svc->request($m, $amount, 'Besoin pour ' . ['un stock de marchandises', 'des frais de santé', 'la scolarité', 'une réparation', 'une cérémonie'][$k % 5],
                $channel, $channel === 'cash' ? null : $m->user->phone, $m->user, 'member', (string) Str::uuid());
            if ($stage === 'pending') {
                continue;
            }
            if ($stage === 'cancelled') {
                $svc->cancel($w->fresh());
                continue;
            }
            $this->at($offset, 14 + $k % 3);
            $w = $svc->startReview($w->fresh(), $this->treasurer);
            if ($stage === 'under_review') {
                continue;
            }
            if ($stage === 'info_requested') {
                $svc->requestInfo($w->fresh(), 'Pouvez-vous préciser l\'usage des fonds et la date souhaitée ?', $this->treasurer);
                continue;
            }
            if ($stage === 'rejected') {
                $svc->reject($w->fresh(), 'Montant trop proche de l\'épargne disponible, à revoir avec le gestionnaire.', $this->manager);
                continue;
            }
            $w = $svc->approve($w->fresh(), $this->manager);
            if ($stage === 'approved') {
                continue;
            }
            $this->at($offset + 1, 10);
            $svc->initiatePayout($w->fresh(), $channel, $channel === 'cash' ? null : $m->user->phone, (string) Str::uuid(), $this->admin);
            if ($stage === 'disbursed') {
                $svc->confirmPayout($w->fresh(), 'VERS-' . strtoupper(Str::random(6)), $this->admin);
            }
        }
    }

    // ── Avances sur épargne (campagne A) ─────────────────────────────────

    /** @param Membership[] $members */
    private function advances(array $members): void
    {
        $lending = app(LendingService::class);
        $plan = [
            [2, 15000, 'disbursed', -40], [5, 10000, 'disbursed', -30], [13, 12000, 'disbursed', -18],
            [16, 8000, 'approved', -4], [19, 10000, 'pending', -2], [22, 6000, 'pending', -1], [8, 20000, 'rejected', -12],
        ];
        foreach ($plan as [$idx, $amount, $stage, $offset]) {
            $m = $members[$idx]->fresh();
            $this->at($offset, 9);
            $req = $lending->createRequest(['product_type' => 'advance', 'membership' => $m, 'amount_minor' => $amount,
                'purpose' => 'Achat de marchandises avant la fête'], $m->user_id);
            if ($stage === 'pending') {
                continue;
            }
            $this->at($offset, 15);
            if ($stage === 'rejected') {
                $lending->reject($req->fresh(), $this->manager->id, 'Avance déjà sollicitée ce mois-ci, à reporter.');
                continue;
            }
            $req = $lending->approve($req->fresh(), $this->manager->id);
            if ($stage === 'disbursed') {
                $this->at($offset + 1, 10);
                $lending->disburse($req->fresh(), $this->admin->id, 'mtn', $m->user->phone);
            }
        }
    }

    // ── Prêts communautaires avec échéancier ─────────────────────────────

    private function loans(): void
    {
        $lending = app(LendingService::class);
        $plan = [
            // [personne, montant, taux bps, périodicité, nb, jour demande, issue]
            [0, 150000, 500, 'monthly', 4, -100, 'repaid'],
            [4, 200000, 300, 'monthly', 6, -80, 'current'],
            [12, 100000, 0, 'weekly', 10, -60, 'current'],
            [28, 250000, 500, 'monthly', 5, -70, 'late'],
            [33, 80000, 0, 'weekly', 8, -45, 'written_off'],
            [36, 120000, 400, 'monthly', 4, -25, 'current'],
            [6, 600000, 600, 'monthly', 12, -3, 'countersigning'],
            [24, 90000, 0, 'weekly', 6, -2, 'pending'],
            [38, 175000, 300, 'monthly', 6, -5, 'approved'],
        ];
        foreach ($plan as [$p, $amount, $bps, $period, $count, $offset, $outcome]) {
            $user = $this->people[$p];
            $this->at($offset, 9);
            $first = $this->today->copy()->addDays($offset + ($period === 'weekly' ? 8 : 31))->toDateString();
            $req = $lending->createRequest([
                'product_type' => 'standard', 'user' => $user, 'amount_minor' => $amount, 'interest_rate_bps' => $bps ?: null,
                'periodicity' => $period, 'installments_count' => $count, 'first_due_date' => $first,
                'purpose' => ['Fonds de roulement', 'Achat d\'un moulin', 'Agrandissement de la boutique', 'Matériel de couture', 'Achat d\'une moto-taxi'][$p % 5],
            ], $this->manager->id);
            if ($outcome === 'pending') {
                continue;
            }
            $this->at($offset + 1, 11);
            $req = $lending->approve($req->fresh(), $this->manager->id);
            if ($outcome === 'countersigning') {
                continue;
            }
            if ($outcome === 'approved') {
                continue;
            }
            $this->at($offset + 2, 10);
            $loan = $lending->disburse($req->fresh(), $this->admin->id, $p % 2 ? 'cash' : 'mtn', $user->phone);
            $this->repayLoan($loan, $outcome);
            if ($outcome === 'written_off') {
                $this->at(-3, 16);
                $lending->writeOff($loan->fresh(), $this->super->id, 'Membre parti sans laisser d\'adresse, relances restées sans réponse depuis six semaines.');
            }
        }
    }

    private function repayLoan(Loan $loan, string $outcome): void
    {
        $lending = app(LendingService::class);
        $items = DB::table('loan_repayment_schedules')->where('loan_id', $loan->id)->orderBy('due_date')->get();
        foreach ($items as $k => $item) {
            $offset = $this->offsetOf($item->due_date);
            $stop = match ($outcome) { 'late' => $k >= 1, 'written_off' => $k >= 2, default => false };
            if ($offset > 0 && $outcome !== 'repaid') {
                return;
            }
            if ($stop) {
                return;
            }
            $amount = (int) $item->amount_minor;
            $this->at(min(0, $offset - ($k % 3)), 12);
            $lending->recordRepayment($loan->fresh(), $amount, $k % 2 ? 'cash' : 'mtn', 'Échéance ' . ($k + 1), $this->treasurer->id);
        }
    }

    // ── Dépenses et recettes de fonctionnement ───────────────────────────

    private function manualEntries(): void
    {
        $svc = app(LedgerManualEntryService::class);
        $entries = [
            [-95, 'out', 25000, 'cash', ManualEntryCategory::Rent, 'Loyer du local — août'],
            [-65, 'out', 25000, 'cash', ManualEntryCategory::Rent, 'Loyer du local — septembre'],
            [-35, 'out', 25000, 'cash', ManualEntryCategory::Rent, 'Loyer du local — octobre'],
            [-60, 'out', 7500, 'cash', ManualEntryCategory::Supplies, 'Carnets et reçus'],
            [-40, 'out', 6000, 'mtn', ManualEntryCategory::Communications, 'Forfaits téléphoniques des collecteurs'],
            [-22, 'out', 4500, 'cash', ManualEntryCategory::Transport, 'Déplacements au marché'],
            [-50, 'in', 50000, 'cash', ManualEntryCategory::DonationReceived, 'Don de l\'association des commerçants'],
        ];
        foreach ($entries as [$offset, $dir, $amount, $channel, $cat, $notes]) {
            $this->at($offset, 16);
            $svc->create($dir, $amount, $channel, $cat, $notes, $this->admin->id);
        }
    }
}
