<?php

namespace App\Modules\Tontine\Application;

use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Lending\Domain\Enums\LoanProductType;
use App\Modules\Lending\Domain\Enums\LoanStatus;
use App\Modules\Lending\Infrastructure\Models\Loan;
use App\Modules\Notifications\Application\NotificationService;
use App\Modules\Payments\Infrastructure\Models\Payment;
use App\Modules\Tontine\Domain\Enums\CampaignPayoutStatus;
use App\Modules\Tontine\Domain\Enums\CampaignStatus;
use App\Modules\Tontine\Domain\Enums\InstallmentStatus;
use App\Modules\Tontine\Domain\Enums\MembershipStatus;
use App\Modules\Tontine\Domain\Enums\RotationRoundStatus;
use App\Modules\Tontine\Domain\Exceptions\TontineRuleException;
use App\Modules\Tontine\Http\Requests\CreateCampaignRequest;
use App\Modules\Tontine\Infrastructure\Models\Campaign;
use App\Modules\Tontine\Infrastructure\Models\CampaignPayout;
use App\Modules\Tontine\Infrastructure\Models\CampaignRenewal;
use App\Modules\Tontine\Infrastructure\Models\Installment;
use App\Modules\Tontine\Infrastructure\Models\Membership;
use App\Modules\Tontine\Infrastructure\Models\RotationDeparture;
use App\Modules\Tontine\Infrastructure\Models\RotationRound;
use App\Shared\BusinessTime;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Reconduction d'une campagne (épargne ou rotative) — R1 à R4.
 *
 * Une reconduction crée une NOUVELLE campagne liée (même série, cycle n+1, lien vers le cycle
 * précédent), ouverte aux adhésions, avec une adhésion `pending_l1` par membre retenu (validation
 * par l'équipe, R2). Rien de l'ancien cycle n'est modifié : ni adhésions, ni échéances, ni soldes,
 * ni écritures ; aucune dette n'est transférée (R4). Idempotente (clé unique) et unique par source.
 *
 * Payload commun à l'aperçu et à la confirmation :
 *   name?, description?, first_installment_date, contribution_amount_minor?, min/max_contribution_minor?,
 *   grace_period_days?, membership_fee_minor?, allow_early_withdrawals? (épargne),
 *   duration_installments?, market_calendar_id? (épargne),
 *   installments_per_round?, contribution_schedule?, rotation_fee_mode?, rotation_fee_minor? (rotatif),
 *   members: [{ membership_id (ancien cycle) | user_id (nouveau), committed_amount_minor?, debt_override_reason? }]
 */
class CampaignRenewalService
{
    /** Paramètres repris de la source quand le payload ne les modifie pas. */
    private const INHERITED = [
        'description', 'contribution_mode', 'contribution_amount_minor', 'min_contribution_minor', 'max_contribution_minor',
        'duration_installments', 'market_calendar_id', 'grace_period_days', 'min_membership_age_days', 'min_trust_score',
        'maker_checker_threshold_minor', 'max_members', 'allow_early_withdrawals', 'membership_fee_minor',
        'installments_per_round', 'contribution_schedule', 'rotation_fee_mode', 'rotation_fee_minor',
    ];

    public function __construct(
        private readonly CampaignService         $campaigns,
        private readonly MembershipService       $memberships,
        private readonly RotationScheduleService $rotationSchedule,
        private readonly ScheduleCalculator      $calculator,
        private readonly NotificationService     $notifications,
    ) {}

    public function preview(Campaign $source, array $payload): array
    {
        $issues = [];

        if ($source->status !== CampaignStatus::Closed) {
            $issues[] = 'La campagne doit être clôturée avant d\'être reconduite.';
        }
        if ($source->nextCycle()->exists()) {
            $issues[] = 'Cette campagne a déjà été reconduite.';
        }

        $members = $this->members($source, $payload['members'] ?? [], $issues);
        $data    = $this->targetData($source, $payload, $members->count());

        if ($members->isEmpty()) {
            $issues[] = 'Retenez au moins un membre pour le nouveau cycle.';
        }
        if (($data['contribution_mode'] ?? 'fixed') === 'custom') {
            foreach ($members as $m) {
                $amount = $m['committed_amount_minor'];
                if (! $amount
                    || (isset($data['min_contribution_minor']) && $amount < $data['min_contribution_minor'])
                    || (isset($data['max_contribution_minor']) && $amount > $data['max_contribution_minor'])) {
                    $issues[] = "Montant d'engagement absent ou hors bornes pour {$m['full_name']}.";
                }
            }
        }

        // Validation identique à une création (CreateCampaignRequest), sans dupliquer les règles.
        $request = CreateCampaignRequest::create('/', 'POST', $data);
        $request->setContainer(app());
        $validator = Validator::make($data, $request->rules(), $request->messages());
        $request->withValidator($validator);
        foreach ($validator->errors()->all() as $error) {
            $issues[] = $error;
        }

        $schedule = $validator->errors()->isEmpty() ? $this->schedule($data) : null;
        if ($schedule && $schedule['issues'] !== []) {
            array_push($issues, ...$schedule['issues']);
        }

        return [
            'valid'    => $issues === [],
            'issues'   => array_values(array_unique($issues)),
            'source'   => $this->cycleSummary($source),
            'target'   => [
                'name' => $data['name'], 'campaign_type' => $data['campaign_type'], 'cycle_number' => $this->nextCycleNumber($source),
                'config' => array_intersect_key($data, array_flip([...self::INHERITED, 'first_installment_date'])),
                'config_changes' => $this->diff($source, $data),
            ],
            'schedule' => $schedule,
            'members'  => [
                'kept'      => $members->where('origin', 'kept')->values(),
                'added'     => $members->where('origin', 'added')->values(),
                'removed'   => $this->removed($source, $members),
                'count'     => $members->count(),
            ],
            'previous_round_order' => $this->previousOrder($source),
            'activation_conditions' => [
                'Chaque membre doit être validé par l\'équipe (frais d\'adhésion réglés le cas échéant).',
                'Le démarrage attend le règlement complet du cycle ' . ($source->cycle_number ?? 1) . ' (' . ($this->isSettled($source) ? 'réglé' : 'pas encore réglé') . ').',
                ...($source->isRotating() ? ['Les tours doivent être attribués (l\'ancien ordre est proposé, jamais appliqué sans confirmation).'] : []),
            ],
        ];
    }

    public function confirm(Campaign $source, array $payload, string $idempotencyKey, User $actor): Campaign
    {
        if ($existing = CampaignRenewal::where('idempotency_key', $idempotencyKey)->first()) {
            if ($existing->source_campaign_id !== $source->id) {
                throw TontineRuleException::idempotencyKeyReused();
            }
            return $existing->target()->firstOrFail();
        }

        [$target, $renewal] = DB::transaction(function () use ($source, $payload, $idempotencyKey, $actor) {
            $source = Campaign::whereKey($source->id)->lockForUpdate()->firstOrFail();

            if ($source->nextCycle()->exists()) {
                throw new TontineRuleException('Cette campagne a déjà été reconduite.', 'campaign_already_renewed', 409);
            }
            $preview = $this->preview($source, $payload);
            if (! $preview['valid']) {
                throw new TontineRuleException('Reconduction impossible : ' . implode(' ', $preview['issues']), 'renewal_invalid', 422);
            }

            $members = $this->members($source, $payload['members'] ?? [], $ignored);
            $data    = $this->targetData($source, $payload, $members->count());

            $target = $this->campaigns->create([
                ...$data,
                'series_id'            => $source->series_id,
                'cycle_number'         => $this->nextCycleNumber($source),
                'previous_campaign_id' => $source->id,
            ], $actor);

            if ($target->isRotating()) {
                $target = $this->rotationSchedule->confirm($target);
            }
            $target->update(['status' => CampaignStatus::Open->value, 'opened_at' => now()]);
            $target = $target->fresh();

            foreach ($members as $m) {
                $this->memberships->enroll(User::findOrFail($m['user_id']), $target,
                    $target->isCustomMode() ? (int) $m['committed_amount_minor'] : null);
            }

            $renewal = CampaignRenewal::create([
                'source_campaign_id' => $source->id,
                'target_campaign_id' => $target->id,
                'idempotency_key'    => $idempotencyKey,
                'roster'             => [
                    'kept'    => $members->where('origin', 'kept')->map(fn ($m) => array_intersect_key($m, array_flip(['user_id', 'previous_membership_id', 'committed_amount_minor', 'debt_minor', 'debt_override_reason'])))->values(),
                    'added'   => $members->where('origin', 'added')->map(fn ($m) => array_intersect_key($m, array_flip(['user_id', 'committed_amount_minor'])))->values(),
                    'removed' => $preview['members']['removed'],
                ],
                'config_changes'     => $preview['target']['config_changes'],
                'created_by'         => $actor->id,
            ]);

            return [$target, $renewal];
        });

        foreach ($renewal->roster['kept'] as $m) {
            $this->notifyProposed($m['user_id'], $target, true);
        }
        foreach ($renewal->roster['added'] as $m) {
            $this->notifyProposed($m['user_id'], $target, false);
        }

        return $target;
    }

    /** R3 : le nouveau cycle ne démarre qu'une fois le précédent entièrement réglé. */
    public function assertPreviousCycleSettled(Campaign $campaign): void
    {
        if (! $campaign->previous_campaign_id) {
            return;
        }
        $previous = Campaign::find($campaign->previous_campaign_id);
        if ($previous && ! $this->isSettled($previous)) {
            throw new TontineRuleException(
                "Le cycle {$previous->cycle_number} n'est pas encore entièrement réglé (versements de clôture en attente) : ce cycle ne peut pas démarrer.",
                'previous_cycle_not_settled', 422);
        }
    }

    public function isSettled(Campaign $campaign): bool
    {
        if ($campaign->status !== CampaignStatus::Closed) {
            return false;
        }
        if ($campaign->isRotating()) {
            return true;   // clôture rotative = caisse à zéro (R-ROT-13)
        }

        return ! CampaignPayout::where('campaign_id', $campaign->id)->where('status', CampaignPayoutStatus::Pending->value)->exists();
    }

    /** Historique de la série : un cycle par campagne, indicateurs calculés sur ses propres données. */
    public function cycles(Campaign $campaign): array
    {
        return Campaign::where('series_id', $campaign->series_id)->orderBy('cycle_number')->get()
            ->map(fn (Campaign $c) => $this->cycleSummary($c, true))->values()->all();
    }

    // ── Interne ──────────────────────────────────────────────────────────

    /** Membres retenus (anciens conservés + nouveaux), avec dettes de l'ancien cycle (R4). */
    private function members(Campaign $source, array $requested, ?array &$issues): Collection
    {
        $issues ??= [];
        $seen = [];
        $out  = collect();

        foreach ($requested as $i => $row) {
            $previous = isset($row['membership_id'])
                ? Membership::with('user')->where('campaign_id', $source->id)->find($row['membership_id'])
                : null;
            $user = $previous?->user ?? (isset($row['user_id']) ? User::find($row['user_id']) : null);

            if (! $user) {
                $issues[] = 'Membre introuvable (ligne ' . ($i + 1) . ').';
                continue;
            }
            if (isset($seen[$user->id])) {
                $issues[] = "{$user->full_name} apparaît deux fois.";
                continue;
            }
            $seen[$user->id] = true;

            if ($user->status !== UserStatus::Active) {
                $issues[] = "Le compte de {$user->full_name} n'est pas actif.";
            }
            if ($user->roles()->whereIn('name', User::STAFF_ROLES)->exists()) {
                $issues[] = "{$user->full_name} est un compte du personnel.";
            }

            $debt = $previous ? $this->debt($previous) : ['minor' => 0, 'details' => []];
            $reason = trim((string) ($row['debt_override_reason'] ?? ''));
            if ($debt['minor'] > 0 && mb_strlen($reason) < 10) {
                $issues[] = "{$user->full_name} doit encore {$debt['minor']} XOF sur le cycle précédent : motif obligatoire (10 caractères minimum) pour le reconduire.";
            }

            $out->push([
                'origin'                 => $previous ? 'kept' : 'added',
                'user_id'                => $user->id,
                'full_name'              => $user->full_name,
                'phone'                  => $user->phone,
                'previous_membership_id' => $previous?->id,
                'previous_committed_minor' => $previous?->committed_amount_minor,
                'committed_amount_minor' => isset($row['committed_amount_minor']) ? (int) $row['committed_amount_minor'] : $previous?->committed_amount_minor,
                'debt_minor'             => $debt['minor'],
                'debt_details'           => $debt['details'],
                'debt_override_reason'   => $debt['minor'] > 0 ? $reason : null,
            ]);
        }

        return $out;
    }

    /** Candidats de l'ancien cycle non retenus (restent dans son historique). */
    private function removed(Campaign $source, Collection $kept): array
    {
        $keptIds = $kept->pluck('previous_membership_id')->filter()->all();

        return $this->candidates($source)
            ->reject(fn (Membership $m) => in_array($m->id, $keptIds, true))
            ->map(fn (Membership $m) => [
                'membership_id' => $m->id, 'user_id' => $m->user_id, 'full_name' => $m->user?->full_name,
                'debt_minor' => $this->debt($m)['minor'],
            ])->values()->all();
    }

    /** Participants de l'ancien cycle proposables (hors adhésions jamais validées et partants). */
    public function candidates(Campaign $source): Collection
    {
        $departed = RotationDeparture::where('campaign_id', $source->id)->pluck('membership_id')->all();

        return Membership::with('user:id,full_name,phone,status')
            ->where('campaign_id', $source->id)
            ->whereIn('status', [MembershipStatus::Active->value, MembershipStatus::Suspended->value, MembershipStatus::Closed->value])
            ->whereNotIn('id', $departed)
            ->get();
    }

    /** Dette dans l'ancien cycle : échéances non réglées (pénalités comprises) + avance non soldée. */
    public function debt(Membership $membership): array
    {
        $unpaid = (int) Installment::where('membership_id', $membership->id)
            ->whereIn('status', [InstallmentStatus::Pending->value, InstallmentStatus::Late->value])
            ->whereDate('due_date', '<=', BusinessTime::todayDate())
            ->withPaidMinor()->get()->sum(fn ($i) => $i->remainingMinor());
        $advances = (int) Loan::where('membership_id', $membership->id)
            ->where('product_type', LoanProductType::Advance->value)
            ->where('status', LoanStatus::Active->value)->sum('outstanding_minor');

        return [
            'minor'   => $unpaid + $advances,
            'details' => array_filter(['unpaid_installments_minor' => $unpaid, 'advances_minor' => $advances]),
        ];
    }

    private function targetData(Campaign $source, array $payload, int $memberCount): array
    {
        $data = [
            'name'          => $payload['name'] ?? ($source->name . ' — Cycle ' . $this->nextCycleNumber($source)),
            'campaign_type' => $source->campaign_type?->value ?? 'savings',
            'first_installment_date' => $payload['first_installment_date'] ?? null,
        ];
        foreach (self::INHERITED as $field) {
            $value = array_key_exists($field, $payload) ? $payload[$field] : $source->getAttribute($field);
            $data[$field] = $value instanceof \BackedEnum ? $value->value : $value;
        }

        if ($source->isRotating()) {
            // N tours = nombre de membres du nouveau groupe ; durée déduite.
            $data['max_members'] = $memberCount;
            unset($data['duration_installments'], $data['market_calendar_id'], $data['allow_early_withdrawals'],
                $data['min_contribution_minor'], $data['max_contribution_minor']);
            if (($data['contribution_schedule']['type'] ?? null) === 'custom_dates') {
                unset($data['first_installment_date']);
            }
        } else {
            unset($data['installments_per_round'], $data['contribution_schedule'], $data['rotation_fee_mode'], $data['rotation_fee_minor']);
            if (($data['contribution_mode'] ?? 'fixed') === 'fixed') {
                unset($data['min_contribution_minor'], $data['max_contribution_minor']);
            } else {
                unset($data['contribution_amount_minor']);
            }
        }

        return array_filter($data, fn ($v) => $v !== null);
    }

    /** Aperçu du calendrier du nouveau cycle, sans rien écrire. */
    private function schedule(array $data): array
    {
        if (($data['campaign_type'] ?? 'savings') === 'rotating') {
            $draft = new Campaign([...$data, 'duration_installments' => (int) $data['max_members'] * (int) $data['installments_per_round']]);
            $p     = $this->rotationSchedule->preview($draft);

            return [
                'issues' => $p['issues'], 'installments_count' => $p['installments_count'], 'rounds_count' => $p['rounds_count'],
                'pot_minor' => $p['pot_minor'], 'amount_due_per_installment_minor' => $p['amount_due_per_installment_minor'],
                'total_per_member_minor' => $p['total_per_member_minor'], 'first_date' => $p['first_date'], 'last_date' => $p['last_date'],
                'rounds' => $p['rounds'],
            ];
        }

        $calendar = isset($data['market_calendar_id']) ? \App\Modules\Tontine\Infrastructure\Models\MarketCalendar::find($data['market_calendar_id']) : null;
        $dates    = $this->calculator->dates(ScheduleCalculator::ruleFromCalendar($calendar), Carbon::parse($data['first_installment_date']), (int) $data['duration_installments']);
        $amount   = (int) ($data['contribution_amount_minor'] ?? 0);

        return [
            'issues' => [], 'installments_count' => count($dates), 'rounds_count' => null, 'pot_minor' => null,
            'amount_due_per_installment_minor' => $amount ?: null,
            'total_per_member_minor' => $amount ? $amount * count($dates) : null,
            'first_date' => isset($dates[0]) ? $dates[0]->toDateString() : null,
            'last_date'  => $dates !== [] ? end($dates)->toDateString() : null, 'rounds' => [],
        ];
    }

    private function previousOrder(Campaign $source): array
    {
        if (! $source->isRotating()) {
            return [];
        }

        return RotationRound::with('beneficiary.user:id,full_name')->where('campaign_id', $source->id)->orderBy('round_number')->get()
            ->map(fn ($r) => [
                'round_number' => $r->round_number, 'previous_membership_id' => $r->beneficiary_membership_id,
                'user_id' => $r->beneficiary?->user_id, 'full_name' => $r->beneficiary?->user?->full_name,
                'status' => $r->status === RotationRoundStatus::Vacant ? 'vacant' : $r->status->value,
            ])->values()->all();
    }

    private function cycleSummary(Campaign $c, bool $withKpis = false): array
    {
        $dates = Installment::where('campaign_id', $c->id)->selectRaw('MIN(due_date) AS first, MAX(due_date) AS last')->first();
        $summary = [
            'id' => $c->id, 'name' => $c->name, 'cycle_number' => (int) $c->cycle_number,
            'campaign_type' => $c->campaign_type?->value ?? 'savings',
            'status' => $c->status->value, 'status_label' => $c->status->label(),
            'first_date' => $dates?->first ?? $c->first_installment_date?->toDateString(),
            'last_date'  => $dates?->last,
            'members'    => Membership::where('campaign_id', $c->id)->whereNotIn('status', [MembershipStatus::PendingL1->value, MembershipStatus::PendingL2->value])->count(),
            'settled'    => $this->isSettled($c),
        ];
        if (! $withKpis) {
            return $summary;
        }

        $membershipIds = Membership::where('campaign_id', $c->id)->pluck('id');
        $collected = (int) Payment::whereIn('membership_id', $membershipIds)->where('status', 'confirmed')->where('purpose', 'contribution')->sum('amount_minor');
        $distributed = $c->isRotating()
            ? (int) RotationRound::where('campaign_id', $c->id)->sum('distributed_minor')
            : (int) CampaignPayout::where('campaign_id', $c->id)->where('status', CampaignPayoutStatus::Settled->value)
                ->get()->sum(fn ($p) => $p->net_amount_minor - (int) $p->loan_repayment_minor);
        $due = Installment::where('campaign_id', $c->id)->where('status', '!=', InstallmentStatus::Waived->value)
            ->whereDate('due_date', '<=', BusinessTime::todayDate())->withPaidMinor()->get();
        $expected = (int) $due->sum(fn ($i) => $i->totalDueMinor());
        $paid     = (int) $due->sum(fn ($i) => min((int) $i->paid_minor, $i->totalDueMinor()));

        return [...$summary, 'kpis' => [
            'collected_minor'   => $collected,
            'distributed_minor' => $distributed,
            'unpaid_minor'      => $expected - $paid,
            'recovery_rate'     => $expected > 0 ? round($paid / $expected * 100, 1) : null,
        ]];
    }

    private function diff(Campaign $source, array $data): array
    {
        $changes = [];
        foreach ([...self::INHERITED, 'first_installment_date'] as $field) {
            if (! array_key_exists($field, $data)) {
                continue;
            }
            $before = $source->getAttribute($field);
            $before = $before instanceof \BackedEnum ? $before->value : ($before instanceof \DateTimeInterface ? $before->format('Y-m-d') : $before);
            if ($before != $data[$field]) {
                $changes[$field] = ['before' => $before, 'after' => $data[$field]];
            }
        }

        return $changes;
    }

    private function nextCycleNumber(Campaign $source): int
    {
        return (int) Campaign::where('series_id', $source->series_id)->max('cycle_number') + 1;
    }

    private function notifyProposed(string $userId, Campaign $target, bool $renewed): void
    {
        $this->notifications->notify($userId, 'campaign.renewal_proposed',
            $renewed ? "Nouveau cycle : {$target->name}" : "Nouvelle campagne : {$target->name}",
            ($renewed ? 'Votre participation au cycle ' . $target->cycle_number . ' est proposée.' : 'Votre participation est proposée.')
            . ' L\'équipe confirmera votre adhésion (frais d\'adhésion éventuels à régler) avant toute cotisation.', '/campagnes');
    }
}
