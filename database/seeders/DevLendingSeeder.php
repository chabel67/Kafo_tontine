<?php

namespace Database\Seeders;

use App\Modules\Ledger\Application\LedgerService;
use App\Modules\Ledger\Domain\Enums\AccountType;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Peuple la base avec des données réalistes et volumineuses pour tester le module prêt.
 *
 * 2 Campagnes :
 *   A — Campagne Marché Dantokpa 2026  (fixed  25 000 XOF, 12 échéances, active)
 *   B — Campagne Femmes Entrepreneurs  (fixed  15 000 XOF, 24 échéances, active)
 *
 * 18 membres répartis sur les deux campagnes avec :
 *   - Demandes en attente (pending)           → à approuver via le back-office
 *   - Demandes approuvées (approved)          → prêtes à décaisser
 *   - Demandes en countersigning             → montant > seuil 500 000 XOF
 *   - Prêts actifs (disbursed)              → en cours de remboursement
 *   - Prêts remboursés (repaid)             → historique propre
 *   - Prêts passés en perte (written_off)   → membre suspendu
 *   - Demandes rejetées                      → historique refus
 *   - Membres inéligibles                    → adhésion récente / score bas / prêt actif
 *
 * PIN commun : 123456
 * Reset propre : php artisan migrate:fresh --seed
 */
class DevLendingSeeder extends Seeder
{
    private LedgerService $ledger;
    private string $adminId;
    private int $loanCounter = 0;

    public function run(): void
    {
        if (DB::table('users')->where('phone', '+22901000001')->exists()) {
            $this->command->info('[DevLendingSeeder] Données déjà présentes — ignoré. Faire migrate:fresh --seed pour réinitialiser.');
            return;
        }

        $this->ledger  = app(LedgerService::class);
        $this->adminId = DB::table('users')->where('phone', '+22900000001')->value('id');

        // ── Comptes ledger partagés ─────────────────────────────────────────
        $this->ledger->openAccount('CASH_BOX',          AccountType::CashBox,   description: 'Caisse physique');
        $this->ledger->openAccount('MOMO_FLOAT:mtn',    AccountType::MomoFloat, description: 'Float MTN MoMo');
        $this->ledger->openAccount('MOMO_FLOAT:orange', AccountType::MomoFloat, description: 'Float Orange Money');
        $this->ledger->openAccount('MOMO_FLOAT:moov',   AccountType::MomoFloat, description: 'Float Moov Money');
        $this->ledger->openAccount('EXPENSE_LOSS',      AccountType::ExpenseLoss, description: 'Pertes write-off');

        // ── Calendriers ─────────────────────────────────────────────────────
        $calA = DB::table('market_calendars')->insertGetId([
            'name'          => 'Marché Dantokpa (5 jours)',
            'cadence_type'  => 'every_n_days',
            'cadence_value' => json_encode(['n' => 5]),
            'timezone'      => 'Africa/Cotonou',
            'description'   => 'Cycle marché 5 jours — Cotonou',
            'created_at'    => now()->subDays(90),
            'updated_at'    => now(),
        ]);

        $calB = DB::table('market_calendars')->insertGetId([
            'name'          => 'Cotisation hebdomadaire (7 jours)',
            'cadence_type'  => 'every_n_days',
            'cadence_value' => json_encode(['n' => 7]),
            'timezone'      => 'Africa/Cotonou',
            'description'   => 'Cycle hebdomadaire',
            'created_at'    => now()->subDays(90),
            'updated_at'    => now(),
        ]);

        // ── Campagnes ───────────────────────────────────────────────────────
        $campaignA = $this->createCampaign([
            'calendar_id'       => $calA,
            'name'              => 'Campagne Marché Dantokpa 2026',
            'description'       => 'Épargne collective — cotisation 25 000 XOF / 5 jours.',
            'amount_minor'      => 25_000,
            'installments'      => 12,
            'days_ago_start'    => 70,
            'cadence_days'      => 5,
            'maker_threshold'   => 500_000,
        ]);

        $campaignB = $this->createCampaign([
            'calendar_id'       => $calB,
            'name'              => 'Campagne Femmes Entrepreneurs 2026',
            'description'       => 'Épargne solidaire — cotisation 15 000 XOF / semaine.',
            'amount_minor'      => 15_000,
            'installments'      => 24,
            'days_ago_start'    => 80,
            'cadence_days'      => 7,
            'maker_threshold'   => 300_000,
        ]);

        $memberRole = DB::table('roles')->where('name', 'member')->first();

        // ── Définition des membres ──────────────────────────────────────────
        // Format : [phone, name, campagne, days_active, paid, late, loan_scenario, ...]
        $members = [

            // ── CAMPAGNE A ──────────────────────────────────────────────────

            // 1. Demande pending — éligible, en attente d'approbation
            [
                'phone'       => '+22901000001', 'name' => 'Alice Kouassi',
                'campaign'    => $campaignA,     'days_active' => 55, 'cadence' => 5,
                'paid' => 10, 'late' => 0, 'total' => 12,
                'scenario'    => 'pending',
                'loan_amount' => 120_000, 'purpose' => 'Achat marchandises marché',
            ],
            // 2. Demande approved — prête à décaisser
            [
                'phone'       => '+22901000002', 'name' => 'Bob Dossou',
                'campaign'    => $campaignA,     'days_active' => 60, 'cadence' => 5,
                'paid' => 8,  'late' => 1, 'total' => 12,
                'scenario'    => 'approved',
                'loan_amount' => 80_000, 'purpose' => 'Frais de scolarité',
            ],
            // 3. Prêt actif, remboursement partiel — inéligible (prêt en cours)
            [
                'phone'       => '+22901000003', 'name' => 'Charlie Gbètondji',
                'campaign'    => $campaignA,     'days_active' => 65, 'cadence' => 5,
                'paid' => 9,  'late' => 0, 'total' => 12,
                'scenario'    => 'active_loan',
                'loan_amount' => 100_000, 'outstanding' => 70_000, 'repaid_amount' => 30_000,
                'purpose' => 'Réparation toiture',
            ],
            // 4. Prêt entièrement remboursé → historique + nouvelle demande pending
            [
                'phone'       => '+22901000004', 'name' => 'Diane Hounkpè',
                'campaign'    => $campaignA,     'days_active' => 65, 'cadence' => 5,
                'paid' => 11, 'late' => 0, 'total' => 12,
                'scenario'    => 'repaid_then_pending',
                'loan_amount_old' => 60_000,  'purpose_old' => 'Achat matériel couture',
                'loan_amount'     => 80_000,  'purpose' => 'Agrandissement boutique',
            ],
            // 5. Prêt passé en perte — membership suspendue
            [
                'phone'       => '+22901000005', 'name' => 'Emeka Bello',
                'campaign'    => $campaignA,     'days_active' => 70, 'cadence' => 5,
                'paid' => 6,  'late' => 3, 'total' => 12,
                'scenario'    => 'written_off',
                'loan_amount' => 50_000, 'purpose' => 'Commerce ambulant',
            ],
            // 6. Countersigning — montant > seuil 500 000
            [
                'phone'       => '+22901000006', 'name' => 'Fatou Savadogo',
                'campaign'    => $campaignA,     'days_active' => 65, 'cadence' => 5,
                'paid' => 12, 'late' => 0, 'total' => 12,
                'scenario'    => 'countersigning',
                'loan_amount' => 600_000, 'purpose' => 'Achat stock boutique principale',
            ],
            // 7. Demande rejetée (score insuffisant)
            [
                'phone'       => '+22901000007', 'name' => 'Georges Agossou',
                'campaign'    => $campaignA,     'days_active' => 50, 'cadence' => 5,
                'paid' => 3,  'late' => 4, 'total' => 12,
                'scenario'    => 'rejected',
                'loan_amount' => 30_000, 'purpose' => 'Achat moto', 'reject_reason' => 'Score de confiance insuffisant (40/100).',
            ],
            // 8. Adhésion trop récente — inéligible
            [
                'phone'       => '+22901000008', 'name' => 'Hortense Kpanake',
                'campaign'    => $campaignA,     'days_active' => 12, 'cadence' => 5,
                'paid' => 2,  'late' => 0, 'total' => 12,
                'scenario'    => 'none',
            ],
            // 9. Prêt actif — plusieurs remboursements enregistrés
            [
                'phone'       => '+22901000009', 'name' => 'Ibrahim Dabo',
                'campaign'    => $campaignA,     'days_active' => 60, 'cadence' => 5,
                'paid' => 9,  'late' => 0, 'total' => 12,
                'scenario'    => 'active_loan_multi_repay',
                'loan_amount' => 90_000, 'outstanding' => 40_000,
                'repayments'  => [25_000, 25_000],
                'purpose'     => 'Fonds de roulement commerce',
            ],

            // ── CAMPAGNE B ──────────────────────────────────────────────────

            // 10. Pending — campagne B
            [
                'phone'       => '+22901000010', 'name' => 'Joséphine Amoussou',
                'campaign'    => $campaignB,     'days_active' => 75, 'cadence' => 7,
                'paid' => 9,  'late' => 0, 'total' => 24,
                'scenario'    => 'pending',
                'loan_amount' => 70_000, 'purpose' => 'Achat machine à coudre',
            ],
            // 11. Approved — campagne B
            [
                'phone'       => '+22901000011', 'name' => 'Kofi Mensah',
                'campaign'    => $campaignB,     'days_active' => 78, 'cadence' => 7,
                'paid' => 10, 'late' => 0, 'total' => 24,
                'scenario'    => 'approved',
                'loan_amount' => 90_000, 'purpose' => 'Matériel agricole',
            ],
            // 12. Prêt actif campagne B, score parfait
            [
                'phone'       => '+22901000012', 'name' => 'Laure Houenontin',
                'campaign'    => $campaignB,     'days_active' => 80, 'cadence' => 7,
                'paid' => 11, 'late' => 0, 'total' => 24,
                'scenario'    => 'active_loan',
                'loan_amount' => 120_000, 'outstanding' => 80_000, 'repaid_amount' => 40_000,
                'purpose' => 'Rénovation atelier couture',
            ],
            // 13. Demande rejetée — montant dépassant le plafond
            [
                'phone'       => '+22901000013', 'name' => 'Marcel Gnanvi',
                'campaign'    => $campaignB,     'days_active' => 60, 'cadence' => 7,
                'paid' => 7,  'late' => 1, 'total' => 24,
                'scenario'    => 'rejected',
                'loan_amount' => 200_000, 'purpose' => 'Achat terrain',
                'reject_reason' => 'Montant demandé dépasse le plafond autorisé.',
            ],
            // 14. Adhésion récente campagne B — inéligible
            [
                'phone'       => '+22901000014', 'name' => 'Nadia Tossou',
                'campaign'    => $campaignB,     'days_active' => 20, 'cadence' => 7,
                'paid' => 2,  'late' => 0, 'total' => 24,
                'scenario'    => 'none',
            ],
            // 15. Deux prêts remboursés dans le passé + pending maintenant
            [
                'phone'       => '+22901000015', 'name' => 'Octave Vodounou',
                'campaign'    => $campaignB,     'days_active' => 78, 'cadence' => 7,
                'paid' => 20, 'late' => 0, 'total' => 24,
                'scenario'    => 'double_repaid_then_pending',
                'loan_amounts_old' => [45_000, 60_000],
                'purposes_old'     => ['Semences agriculture', 'Matériel de pêche'],
                'loan_amount'      => 100_000, 'purpose' => 'Achat groupe électrogène',
            ],
            // 16. Score limite — exactement à 60 (pending)
            [
                'phone'       => '+22901000016', 'name' => 'Pauline Agbossou',
                'campaign'    => $campaignA,     'days_active' => 45, 'cadence' => 5,
                'paid' => 6,  'late' => 2, 'total' => 12,
                'scenario'    => 'pending',
                'loan_amount' => 40_000, 'purpose' => 'Stock piment et épices',
            ],
            // 17. Countersigning campagne B (grande somme)
            [
                'phone'       => '+22901000017', 'name' => 'Rodrigue Hountondji',
                'campaign'    => $campaignB,     'days_active' => 78, 'cadence' => 7,
                'paid' => 22, 'late' => 0, 'total' => 24,
                'scenario'    => 'countersigning',
                'loan_amount' => 400_000, 'purpose' => 'Achat véhicule de transport',
            ],
            // 18. Pas de demande — éligible mais n'a pas encore demandé
            [
                'phone'       => '+22901000018', 'name' => 'Sandra Klikpo',
                'campaign'    => $campaignB,     'days_active' => 78, 'cadence' => 7,
                'paid' => 14, 'late' => 0, 'total' => 24,
                'scenario'    => 'none',
            ],
        ];

        foreach ($members as $p) {
            $this->seedMember($p, $memberRole);
        }

        $this->printSummary();
    }

    // ──────────────────────────────────────────────────────────────────────────

    private function createCampaign(array $cfg): string
    {
        $id        = (string) Str::uuid();
        $startAgo  = now()->subDays($cfg['days_ago_start']);

        DB::table('tontine_campaigns')->insert([
            'id'                            => $id,
            'market_calendar_id'            => $cfg['calendar_id'],
            'created_by'                    => $this->adminId,
            'name'                          => $cfg['name'],
            'description'                   => $cfg['description'],
            'status'                        => 'active',
            'currency'                      => 'XOF',
            'contribution_mode'             => 'fixed',
            'contribution_amount_minor'     => $cfg['amount_minor'],
            'duration_installments'         => $cfg['installments'],
            'first_installment_date'        => $startAgo->toDateString(),
            'grace_period_days'             => 3,
            'min_membership_age_days'       => 30,
            'min_trust_score'               => 60,
            'maker_checker_threshold_minor' => $cfg['maker_threshold'],
            'max_members'                   => 30,
            'opened_at'                     => $startAgo->copy()->subDays(5),
            'activated_at'                  => $startAgo,
            'created_at'                    => $startAgo->copy()->subDays(10),
            'updated_at'                    => now(),
        ]);

        return $id;
    }

    private function seedMember(array $p, mixed $memberRole): void
    {
        $campaignId      = $p['campaign'];
        $daysActive      = (int) $p['days_active'];
        $cadenceDays     = (int) $p['cadence'];
        $paid            = (int) $p['paid'];
        $late            = (int) $p['late'];
        $totalInstall    = (int) $p['total'];
        $installAmount   = $this->getCampaignAmount($campaignId);
        $scenario        = $p['scenario'];

        // ── Utilisateur ──────────────────────────────────────────────────────
        $userId = (string) Str::uuid();
        DB::table('users')->insert([
            'id'         => $userId,
            'phone'      => $p['phone'],
            'full_name'  => $p['name'],
            'pin_hash'   => Hash::make('123456'),
            'kyc_level'  => 1,
            'kyc_status' => 'verified',
            'status'     => ($scenario === 'written_off') ? 'active' : 'active',
            'created_at' => now()->subDays($daysActive + 5),
            'updated_at' => now(),
        ]);

        if ($memberRole) {
            DB::table('role_user')->insert([
                'user_id'     => $userId,
                'role_id'     => $memberRole->id,
                'campaign_id' => null,
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);
        }

        // ── Adhésion ─────────────────────────────────────────────────────────
        $membershipId = (string) Str::uuid();
        $activatedAt  = now()->subDays($daysActive);
        $memberStatus = ($scenario === 'written_off') ? 'suspended' : 'active';

        DB::table('memberships')->insert([
            'id'                     => $membershipId,
            'user_id'                => $userId,
            'campaign_id'            => $campaignId,
            'status'                 => $memberStatus,
            'committed_amount_minor' => $installAmount,
            'validated_l1_by'        => $this->adminId,
            'validated_l1_at'        => $activatedAt->copy()->subDays(2),
            'validated_l2_by'        => $this->adminId,
            'validated_l2_at'        => $activatedAt,
            'activated_at'           => $activatedAt,
            'suspended_by'           => ($scenario === 'written_off') ? $this->adminId : null,
            'suspended_at'           => ($scenario === 'written_off') ? now()->subDays(3) : null,
            'suspension_reason'      => ($scenario === 'written_off') ? 'Write-off avance — impayé constaté' : null,
            'created_at'             => $activatedAt->copy()->subDays(3),
            'updated_at'             => now(),
        ]);

        // ── Compte épargne ────────────────────────────────────────────────────
        $savingsKey = "MEMBER_SAVINGS:{$membershipId}";
        $this->ledger->openAccount($savingsKey, AccountType::MemberSavings, ownerId: $userId, description: "Épargne {$p['name']}");

        // ── Échéances + écritures ledger ──────────────────────────────────────
        $channels = ['CASH_BOX', 'MOMO_FLOAT:mtn', 'MOMO_FLOAT:orange', 'MOMO_FLOAT:moov'];
        for ($seq = 1; $seq <= $totalInstall; $seq++) {
            $dueDate   = $activatedAt->copy()->addDays(($seq - 1) * $cadenceDays);
            $graceEnds = $dueDate->copy()->addDays(3);

            $status       = 'pending';
            $paidAt       = null;
            $lateMarkedAt = null;

            if ($seq <= $paid) {
                $status = 'paid';
                $paidAt = $dueDate->copy()->addHours(rand(1, 20));
            } elseif ($seq <= $paid + $late) {
                $status       = 'late';
                $lateMarkedAt = $dueDate->copy()->addDays(4);
            }

            DB::table('installments')->insert([
                'id'                     => (string) Str::uuid(),
                'membership_id'          => $membershipId,
                'campaign_id'            => $campaignId,
                'sequence_number'        => $seq,
                'due_date'               => $dueDate->toDateString(),
                'grace_period_ends_date' => $graceEnds->toDateString(),
                'amount_minor'           => $installAmount,
                'status'                 => $status,
                'paid_at'                => $paidAt,
                'late_marked_at'         => $lateMarkedAt,
                'created_at'             => $activatedAt,
                'updated_at'             => now(),
            ]);

            if ($status === 'paid') {
                $channel = $channels[$seq % count($channels)];
                $this->ledger->post(
                    legs: [
                        ['account' => $channel,    'type' => 'debit',  'amount' => $installAmount],
                        ['account' => $savingsKey, 'type' => 'credit', 'amount' => $installAmount],
                    ],
                    reference:   $this->ledger->nextReference(),
                    description: "Cotisation #{$seq} — {$p['name']}",
                    createdById: $this->adminId,
                    metadata:    ['membership_id' => $membershipId, 'seq' => $seq],
                );
            }
        }

        $savings     = $paid * $installAmount;
        $trustScore  = max(0, min(100, 80 - ($late * 10)));
        $coefficient = $trustScore >= 75 ? 0.70 : ($trustScore >= 60 ? 0.60 : 0.50);

        // ── Scénarios de prêt ─────────────────────────────────────────────────
        match ($scenario) {
            'pending'      => $this->scenarioPending($membershipId, $userId, $savings, $trustScore, $coefficient, $p),
            'approved'     => $this->scenarioApproved($membershipId, $userId, $savings, $trustScore, $coefficient, $p),
            'countersigning' => $this->scenarioCountersigning($membershipId, $userId, $savings, $trustScore, $coefficient, $p),
            'rejected'     => $this->scenarioRejected($membershipId, $userId, $savings, $trustScore, $coefficient, $p),
            'active_loan'  => $this->scenarioActiveLoan($membershipId, $userId, $savingsKey, $savings, $trustScore, $coefficient, $p),
            'active_loan_multi_repay' => $this->scenarioActiveLoanMultiRepay($membershipId, $userId, $savingsKey, $savings, $trustScore, $coefficient, $p),
            'repaid_then_pending' => $this->scenarioRepaidThenPending($membershipId, $userId, $savingsKey, $savings, $trustScore, $coefficient, $p),
            'double_repaid_then_pending' => $this->scenarioDoubleRepaidThenPending($membershipId, $userId, $savingsKey, $savings, $trustScore, $coefficient, $p),
            'written_off'  => $this->scenarioWrittenOff($membershipId, $userId, $savingsKey, $savings, $trustScore, $coefficient, $p),
            'none'         => null,
            default        => null,
        };
    }

    // ── Scénarios ────────────────────────────────────────────────────────────

    private function scenarioPending(string $mid, string $uid, int $savings, int $trust, float $coeff, array $p): void
    {
        $this->insertRequest($mid, $uid, $savings, $trust, $coeff, $p['loan_amount'], $p['purpose'] ?? null, 'pending');
    }

    private function scenarioApproved(string $mid, string $uid, int $savings, int $trust, float $coeff, array $p): void
    {
        $reqId = $this->insertRequest($mid, $uid, $savings, $trust, $coeff, $p['loan_amount'], $p['purpose'] ?? null, 'approved');
        DB::table('loan_requests')->where('id', $reqId)->update([
            'decided_by' => $this->adminId,
            'decided_at' => now()->subHours(rand(4, 12)),
        ]);
    }

    private function scenarioCountersigning(string $mid, string $uid, int $savings, int $trust, float $coeff, array $p): void
    {
        $reqId = $this->insertRequest($mid, $uid, $savings, $trust, $coeff, $p['loan_amount'], $p['purpose'] ?? null, 'countersigning');
        DB::table('loan_requests')->where('id', $reqId)->update([
            'decided_by' => $this->adminId,
            'decided_at' => now()->subHours(rand(6, 18)),
        ]);
    }

    private function scenarioRejected(string $mid, string $uid, int $savings, int $trust, float $coeff, array $p): void
    {
        $reqId = $this->insertRequest($mid, $uid, $savings, $trust, $coeff, $p['loan_amount'], $p['purpose'] ?? null, 'rejected');
        DB::table('loan_requests')->where('id', $reqId)->update([
            'decided_by'      => $this->adminId,
            'decided_at'      => now()->subDays(rand(1, 4)),
            'rejected_reason' => $p['reject_reason'] ?? 'Dossier incomplet.',
        ]);
    }

    private function scenarioActiveLoan(string $mid, string $uid, string $savingsKey, int $savings, int $trust, float $coeff, array $p): void
    {
        $amount      = (int) $p['loan_amount'];
        $outstanding = (int) ($p['outstanding'] ?? $amount);
        $repaid      = $amount - $outstanding;

        $reqId  = $this->insertRequest($mid, $uid, $savings, $trust, $coeff, $amount, $p['purpose'] ?? null, 'disbursed', daysAgo: 10);
        $loanId = $this->createLoan($reqId, $mid, $uid, $amount, $outstanding, 'active', 'mtn');
        $this->postDisbursement($loanId, $amount, $uid);

        if ($repaid > 0) {
            $this->postRepayment($loanId, $repaid, 'cash', $uid, 'Remboursement partiel');
        }
    }

    private function scenarioActiveLoanMultiRepay(string $mid, string $uid, string $savingsKey, int $savings, int $trust, float $coeff, array $p): void
    {
        $amount      = (int) $p['loan_amount'];
        $repayments  = $p['repayments'] ?? [];
        $totalRepaid = array_sum($repayments);
        $outstanding = $amount - $totalRepaid;

        $reqId  = $this->insertRequest($mid, $uid, $savings, $trust, $coeff, $amount, $p['purpose'] ?? null, 'disbursed', daysAgo: 15);
        $loanId = $this->createLoan($reqId, $mid, $uid, $amount, $outstanding, 'active', 'orange');
        $this->postDisbursement($loanId, $amount, $uid, 'MOMO_FLOAT:orange');

        foreach ($repayments as $i => $repayAmount) {
            $channel = ($i % 2 === 0) ? 'cash' : 'mtn';
            $this->postRepayment($loanId, $repayAmount, $channel, $uid, "Remboursement " . ($i + 1));
        }
    }

    private function scenarioRepaidThenPending(string $mid, string $uid, string $savingsKey, int $savings, int $trust, float $coeff, array $p): void
    {
        // Prêt passé (remboursé il y a 20 jours)
        $oldAmount = (int) ($p['loan_amount_old'] ?? 50_000);
        $reqOldId  = $this->insertRequest($mid, $uid, $savings, $trust, $coeff, $oldAmount, $p['purpose_old'] ?? null, 'disbursed', daysAgo: 30);
        $loanOldId = $this->createLoan($reqOldId, $mid, $uid, $oldAmount, 0, 'repaid', 'mtn', daysAgo: 28);
        $this->postDisbursement($loanOldId, $oldAmount, $uid);
        $this->postRepayment($loanOldId, $oldAmount, 'cash', $uid, 'Remboursement intégral', daysAgo: 20);

        // Nouvelle demande pending
        $this->insertRequest($mid, $uid, $savings, $trust, $coeff, (int) $p['loan_amount'], $p['purpose'] ?? null, 'pending');
    }

    private function scenarioDoubleRepaidThenPending(string $mid, string $uid, string $savingsKey, int $savings, int $trust, float $coeff, array $p): void
    {
        $amounts  = $p['loan_amounts_old'] ?? [40_000, 50_000];
        $purposes = $p['purposes_old']     ?? [null, null];

        foreach ($amounts as $i => $oldAmount) {
            $daysAgoDisb = 60 - ($i * 25);
            $daysAgoRepay = $daysAgoDisb - 15;
            $reqOldId  = $this->insertRequest($mid, $uid, $savings, $trust, $coeff, $oldAmount, $purposes[$i] ?? null, 'disbursed', daysAgo: $daysAgoDisb + 2);
            $loanOldId = $this->createLoan($reqOldId, $mid, $uid, $oldAmount, 0, 'repaid', 'mtn', daysAgo: $daysAgoDisb);
            $this->postDisbursement($loanOldId, $oldAmount, $uid);
            $this->postRepayment($loanOldId, $oldAmount, 'cash', $uid, 'Remboursement intégral', daysAgo: $daysAgoRepay);
        }

        $this->insertRequest($mid, $uid, $savings, $trust, $coeff, (int) $p['loan_amount'], $p['purpose'] ?? null, 'pending');
    }

    private function scenarioWrittenOff(string $mid, string $uid, string $savingsKey, int $savings, int $trust, float $coeff, array $p): void
    {
        $amount = (int) $p['loan_amount'];

        $reqId  = $this->insertRequest($mid, $uid, $savings, $trust, $coeff, $amount, $p['purpose'] ?? null, 'disbursed', daysAgo: 20);
        $loanId = $this->createLoan($reqId, $mid, $uid, $amount, 0, 'written_off', 'moov', daysAgo: 18);
        $this->postDisbursement($loanId, $amount, $uid, 'MOMO_FLOAT:moov');

        // Écriture write-off
        $receivableKey = "LOAN_RECEIVABLE:{$loanId}";
        $this->ledger->post(
            legs: [
                ['account' => 'EXPENSE_LOSS',  'type' => 'debit',  'amount' => $amount],
                ['account' => $receivableKey,  'type' => 'credit', 'amount' => $amount],
            ],
            reference:   $this->ledger->nextReference(),
            description: "Write-off — impayé constaté",
            createdById: $this->adminId,
            metadata:    ['loan_id' => $loanId],
        );

        DB::table('loans')->where('id', $loanId)->update([
            'written_off_by'     => $this->adminId,
            'written_off_at'     => now()->subDays(3),
            'written_off_reason' => $p['purpose'] ?? 'Impayé — prise en perte',
        ]);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function insertRequest(
        string $mid, string $uid, int $savings, int $trust, float $coeff,
        int $amount, ?string $purpose, string $status, int $daysAgo = 2
    ): string {
        $id       = (string) Str::uuid();
        $maxAmount = (int) ($savings * $coeff);
        $eligible  = in_array($status, ['pending', 'approved', 'countersigning', 'disbursed']);

        DB::table('loan_requests')->insert([
            'id'                   => $id,
            'membership_id'        => $mid,
            'amount_minor'         => $amount,
            'purpose'              => $purpose,
            'status'               => $status,
            'eligibility_snapshot' => json_encode([
                'eligible'         => $eligible,
                'reasons'          => $eligible ? [] : ['Score de confiance insuffisant'],
                'trust_score'      => $trust,
                'coefficient'      => $coeff,
                'savings_minor'    => $savings,
                'max_amount_minor' => $maxAmount,
                'checked_at'       => now()->subDays($daysAgo)->toIso8601String(),
            ]),
            'created_by'  => $uid,
            'created_at'  => now()->subDays($daysAgo),
            'updated_at'  => now()->subDays(max(0, $daysAgo - 1)),
        ]);

        return $id;
    }

    private function createLoan(
        string $reqId, string $mid, string $uid,
        int $principal, int $outstanding, string $status,
        string $channel, int $daysAgo = 8
    ): string {
        $this->loanCounter++;
        $loanId = (string) Str::uuid();
        $ref    = sprintf('LOAN-2026-%06d', $this->loanCounter);

        DB::table('loans')->insert([
            'id'                => $loanId,
            'reference'         => $ref,
            'loan_request_id'   => $reqId,
            'membership_id'     => $mid,
            'principal_minor'   => $principal,
            'outstanding_minor' => $outstanding,
            'status'            => $status,
            'disbursed_channel' => $channel,
            'disbursed_phone'   => DB::table('users')->where('id', $uid)->value('phone'),
            'disbursed_by'      => $this->adminId,
            'disbursed_at'      => now()->subDays($daysAgo),
            'created_at'        => now()->subDays($daysAgo),
            'updated_at'        => now(),
        ]);

        // Compte receivable
        $this->ledger->openAccount(
            "LOAN_RECEIVABLE:{$loanId}",
            AccountType::LoanReceivable,
            ownerId: $uid,
            description: "Avance {$ref}",
        );

        return $loanId;
    }

    private function postDisbursement(string $loanId, int $amount, string $uid, string $floatKey = 'MOMO_FLOAT:mtn'): void
    {
        $this->ledger->post(
            legs: [
                ['account' => "LOAN_RECEIVABLE:{$loanId}", 'type' => 'debit',  'amount' => $amount],
                ['account' => $floatKey,                   'type' => 'credit', 'amount' => $amount],
            ],
            reference:   $this->ledger->nextReference(),
            description: "Décaissement avance",
            createdById: $this->adminId,
            metadata:    ['loan_id' => $loanId],
        );
    }

    private function postRepayment(string $loanId, int $amount, string $channel, string $uid, string $notes = '', int $daysAgo = 2): void
    {
        $repayId = (string) Str::uuid();
        DB::table('loan_repayments')->insert([
            'id'           => $repayId,
            'loan_id'      => $loanId,
            'amount_minor' => $amount,
            'channel'      => $channel,
            'notes'        => $notes,
            'recorded_by'  => $this->adminId,
            'created_at'   => now()->subDays($daysAgo),
            'updated_at'   => now()->subDays($daysAgo),
        ]);

        $floatKey = ($channel === 'cash') ? 'CASH_BOX' : "MOMO_FLOAT:{$channel}";
        $this->ledger->post(
            legs: [
                ['account' => $floatKey,                   'type' => 'debit',  'amount' => $amount],
                ['account' => "LOAN_RECEIVABLE:{$loanId}", 'type' => 'credit', 'amount' => $amount],
            ],
            reference:   $this->ledger->nextReference(),
            description: "Remboursement avance — {$notes}",
            createdById: $this->adminId,
            metadata:    ['loan_id' => $loanId, 'repayment_id' => $repayId],
        );
    }

    private function getCampaignAmount(string $campaignId): int
    {
        return (int) DB::table('tontine_campaigns')->where('id', $campaignId)->value('contribution_amount_minor');
    }

    private function printSummary(): void
    {
        $this->command->info('[DevLendingSeeder] Terminé avec succès.');
        $this->command->newLine();
        $this->command->table(
            ['Téléphone', 'Membre', 'Campagne', 'Épargne', 'Trust', 'Scénario'],
            [
                ['+22901000001', 'Alice Kouassi',      'A', '250 000', '80', 'pending → à approuver'],
                ['+22901000002', 'Bob Dossou',          'A', '200 000', '70', 'approved → à décaisser'],
                ['+22901000003', 'Charlie Gbètondji',   'A', '225 000', '80', 'active_loan (70k restant)'],
                ['+22901000004', 'Diane Hounkpè',       'A', '275 000', '80', 'repaid + new pending'],
                ['+22901000005', 'Emeka Bello',         'A', '150 000', '50', 'written_off — suspendu'],
                ['+22901000006', 'Fatou Savadogo',      'A', '300 000', '80', 'countersigning 600k'],
                ['+22901000007', 'Georges Agossou',     'A',  '75 000', '40', 'rejected — score bas'],
                ['+22901000008', 'Hortense Kpanake',    'A',  '50 000', '80', 'none — trop récent (12j)'],
                ['+22901000009', 'Ibrahim Dabo',        'A', '225 000', '80', 'active_loan multi-repay (40k restant)'],
                ['+22901000010', 'Joséphine Amoussou',  'B', '135 000', '80', 'pending → à approuver'],
                ['+22901000011', 'Kofi Mensah',         'B', '150 000', '80', 'approved → à décaisser'],
                ['+22901000012', 'Laure Houenontin',    'B', '165 000', '80', 'active_loan (80k restant)'],
                ['+22901000013', 'Marcel Gnanvi',       'B', '105 000', '70', 'rejected — plafond dépassé'],
                ['+22901000014', 'Nadia Tossou',        'B',  '30 000', '80', 'none — trop récent (20j)'],
                ['+22901000015', 'Octave Vodounou',     'B', '300 000', '80', '2x repaid + new pending'],
                ['+22901000016', 'Pauline Agbossou',    'A', '150 000', '60', 'pending — score limite 60'],
                ['+22901000017', 'Rodrigue Hountondji', 'B', '330 000', '80', 'countersigning 400k'],
                ['+22901000018', 'Sandra Klikpo',       'B', '210 000', '80', 'none — éligible, pas de demande'],
            ]
        );
        $this->command->newLine();
        $this->command->line('PIN commun : <comment>123456</comment>');
        $this->command->line('Super-admin : <comment>+22900000001</comment> / PIN <comment>123456</comment>');
    }
}