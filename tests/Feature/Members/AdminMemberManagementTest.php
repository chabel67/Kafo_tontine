<?php

namespace Tests\Feature\Members;

use App\Modules\Audit\Infrastructure\Models\AuditLog;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Ledger\Infrastructure\Models\LedgerEntry;
use App\Modules\Payments\Infrastructure\Models\Payment;
use App\Modules\Tontine\Infrastructure\Models\Membership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\BuildsKafoFixtures;
use Tests\TestCase;

class AdminMemberManagementTest extends TestCase
{
    use RefreshDatabase, BuildsKafoFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    // ── Création / modification ─────────────────────────────────────────────

    public function test_manager_creates_member_without_membership_and_it_is_audited(): void
    {
        $this->actingAsStaff('manager');

        $response = $this->postJson('/api/admin/members', [
            'phone'      => '+229 01 97 00 00 01',
            'full_name'  => 'Afiavi Dossou',
            'email'      => 'Afiavi@Example.com',
            'birth_date' => '1990-05-12',
            'locality'   => 'Cotonou',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.phone', '+2290197000001')
            ->assertJsonPath('data.email', 'afiavi@example.com')
            ->assertJsonPath('data.pin_set', false);

        $user = User::where('phone', '+2290197000001')->firstOrFail();
        $this->assertSame(0, Membership::where('user_id', $user->id)->count(), 'aucune adhésion automatique');
        $this->assertDatabaseHas('audit_logs', ['action' => 'member.create', 'entity_id' => $user->id]);
    }

    public function test_phone_must_be_unique_and_well_formed(): void
    {
        $this->actingAsStaff('manager');
        $existing = $this->member(['phone' => '+2290197111111']);

        $this->postJson('/api/admin/members', ['phone' => $existing->phone, 'full_name' => 'Doublon'])
            ->assertStatus(422)
            ->assertJsonPath('error.errors.phone.0', 'Ce numéro de téléphone est déjà utilisé.');

        $this->postJson('/api/admin/members', ['phone' => '97111111', 'full_name' => 'Sans indicatif'])
            ->assertStatus(422)
            ->assertJsonPath('error.errors.phone.0', 'Le numéro doit être au format international (+229…).');
    }

    public function test_invalid_email_and_birth_date_are_rejected_with_explicit_messages(): void
    {
        $this->actingAsStaff('manager');

        $this->postJson('/api/admin/members', [
            'phone'      => '+2290197222222',
            'full_name'  => 'Test',
            'email'      => 'pas-un-email',
            'birth_date' => now()->subYears(5)->toDateString(),
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.errors.email.0', 'L\'adresse email n\'est pas valide.')
            ->assertJsonPath('error.errors.birth_date.0', 'Le membre doit avoir au moins 16 ans.');
    }

    public function test_update_keeps_own_phone_and_audits_only_changed_fields(): void
    {
        $this->actingAsStaff('manager');
        $member = $this->member(['full_name' => 'Ancien Nom', 'locality' => 'Abomey']);

        $this->patchJson("/api/admin/members/{$member->id}", [
            'phone'     => $member->phone,
            'full_name' => 'Nouveau Nom',
            'locality'  => 'Abomey',
        ])->assertOk()->assertJsonPath('data.full_name', 'Nouveau Nom');

        $log = AuditLog::where('action', 'member.update')->where('entity_id', $member->id)->firstOrFail();
        $this->assertSame(['full_name' => 'Ancien Nom'], $log->old_values);
        $this->assertSame(['full_name' => 'Nouveau Nom'], $log->new_values);
    }

    public function test_update_rejects_phone_of_another_member(): void
    {
        $this->actingAsStaff('manager');
        $member = $this->member();
        $other  = $this->member();

        $this->patchJson("/api/admin/members/{$member->id}", ['phone' => $other->phone, 'full_name' => 'X Y'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['phone'], 'error.errors');
    }

    // ── Liste : exclusion du staff, recherche, filtres, tri, pagination ────

    public function test_list_excludes_staff_and_is_paginated_server_side(): void
    {
        $this->actingAsStaff('treasurer');
        $this->staff('admin');
        for ($i = 0; $i < 7; $i++) {
            $this->member();
        }

        $this->getJson('/api/admin/members?per_page=5')
            ->assertOk()
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('meta.total', 7)
            ->assertJsonPath('meta.last_page', 2);

        $this->getJson('/api/admin/members?per_page=5&page=2')->assertJsonCount(2, 'data');
    }

    public function test_search_matches_name_phone_and_email(): void
    {
        $this->actingAsStaff('treasurer');
        $this->member(['full_name' => 'Josiane Akpovi']);
        $this->member(['phone' => '+2290166554433']);
        $this->member(['email' => 'kossi@kafo.test']);
        $this->member(['full_name' => 'Autre Personne']);

        $this->getJson('/api/admin/members?search=akpovi')->assertJsonPath('meta.total', 1);
        $this->getJson('/api/admin/members?search=66554433')->assertJsonPath('meta.total', 1);
        $this->getJson('/api/admin/members?search=kossi@')->assertJsonPath('meta.total', 1);
    }

    public function test_filters_by_status_pending_activation_and_campaign(): void
    {
        $this->actingAsStaff('treasurer');
        $campaign  = $this->campaign();
        $enrolled  = $this->member();
        $this->membership($enrolled, $campaign);
        $this->member(['pin_hash' => null]);
        $this->member(['status' => 'suspended']);
        $this->member(['status' => 'pending', 'pin_hash' => null]);

        $this->getJson('/api/admin/members?status=pin_not_set')->assertJsonPath('meta.total', 1);
        $this->getJson('/api/admin/members?status=pending')->assertJsonPath('meta.total', 1);
        $this->getJson('/api/admin/members?status=suspended')->assertJsonPath('meta.total', 1);
        $this->getJson("/api/admin/members?campaign_id={$campaign->id}")
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $enrolled->id)
            ->assertJsonPath('data.0.campaigns.0.name', $campaign->name);
    }

    public function test_regularity_and_trust_score_are_computed_and_sortable(): void
    {
        $this->actingAsStaff('treasurer');
        $good = $this->member(['full_name' => 'Bon Payeur']);
        $late = $this->member(['full_name' => 'Retardataire']);
        $this->installments($this->membership($good), ['paid', 'paid', 'pending']);
        $this->installments($this->membership($late), ['paid', 'late', 'pending']);

        $rows = $this->getJson('/api/admin/members?sort=trust_score&direction=asc')->assertOk()->json('data');

        $this->assertSame('Retardataire', $rows[0]['full_name']);
        // 80 − 10 (déjà en retard) − 20 (en retard actuellement)
        $this->assertSame(50, $rows[0]['trust_score']);
        $this->assertSame(50, $rows[0]['regularity']);
        $this->assertSame(80, collect($rows)->firstWhere('full_name', 'Bon Payeur')['trust_score']);

        $byName = $this->getJson('/api/admin/members?sort=full_name&direction=asc')->json('data');
        $this->assertSame('Bon Payeur', $byName[0]['full_name']);
    }

    // ── Permissions ─────────────────────────────────────────────────────────

    public function test_treasurer_can_read_but_not_manage_members(): void
    {
        $this->actingAsStaff('treasurer');
        $member = $this->member();

        $this->getJson("/api/admin/members/{$member->id}")->assertOk();
        $this->postJson('/api/admin/members', ['phone' => '+2290197333333', 'full_name' => 'X'])->assertForbidden();
        $this->patchJson("/api/admin/members/{$member->id}", ['phone' => $member->phone, 'full_name' => 'Y'])->assertForbidden();
        $this->postJson("/api/admin/members/{$member->id}/suspend", ['reason' => 'Motif suffisamment long'])->assertForbidden();
        $this->postJson("/api/admin/members/{$member->id}/notes", ['body' => 'Note'])->assertForbidden();
    }

    public function test_a_member_token_cannot_access_admin_member_routes(): void
    {
        $member = $this->member();
        $member->roles()->attach(\App\Modules\Identity\Infrastructure\Models\Role::where('name', 'member')->value('id'));
        Sanctum::actingAs($member->load('roles'));

        $this->getJson('/api/admin/members')->assertForbidden();
        $this->getJson("/api/admin/members/{$member->id}/notes")->assertForbidden();
    }

    public function test_kyc_documents_require_member_kyc_permission(): void
    {
        $this->actingAsStaff('manager');
        $member = $this->member();

        $this->getJson("/api/admin/members/{$member->id}/kyc")->assertForbidden();
    }

    public function test_staff_accounts_are_not_exposed_through_member_routes(): void
    {
        $this->actingAsStaff('admin');
        $treasurer = $this->staff('treasurer');

        $this->getJson("/api/admin/members/{$treasurer->id}")->assertNotFound();
        $this->postJson("/api/admin/members/{$treasurer->id}/suspend", ['reason' => 'Tentative sur un compte staff'])->assertNotFound();
    }

    // ── Suspension / réactivation ───────────────────────────────────────────

    public function test_suspension_requires_reason_keeps_financial_history_and_revokes_sessions(): void
    {
        $this->actingAsStaff('manager');
        $member     = $this->member();
        $membership = $this->membership($member);
        $this->creditSavings($membership, 15000);
        $member->createToken('app-membre');
        $entriesBefore = LedgerEntry::count();

        $this->postJson("/api/admin/members/{$member->id}/suspend", ['reason' => 'court'])
            ->assertStatus(422)->assertJsonValidationErrors(['reason'], 'error.errors');

        $this->postJson("/api/admin/members/{$member->id}/suspend", ['reason' => 'Fraude suspectée sur un encaissement'])
            ->assertOk()->assertJsonPath('data.status', 'suspended');

        $this->assertSame(0, $member->tokens()->count(), 'sessions révoquées');
        $this->assertSame('active', $membership->fresh()->status->value, 'adhésion inchangée');
        $this->assertSame($entriesBefore, LedgerEntry::count(), 'aucune écriture comptable');
        $this->assertDatabaseHas('audit_logs', ['action' => 'member.suspend', 'entity_id' => $member->id]);

        $this->postJson("/api/admin/members/{$member->id}/suspend", ['reason' => 'Deuxième suspension inutile'])
            ->assertStatus(422);

        $this->postJson("/api/admin/members/{$member->id}/reactivate")
            ->assertOk()->assertJsonPath('data.status', 'active');
        $this->assertDatabaseHas('audit_logs', ['action' => 'member.reactivate', 'entity_id' => $member->id]);
    }

    // ── Activation ──────────────────────────────────────────────────────────

    public function test_member_created_without_activation_cannot_log_in_until_activated(): void
    {
        $this->actingAsStaff('manager');

        $id = $this->postJson('/api/admin/members', [
            'phone' => '+2290197444444', 'full_name' => 'Compte À Activer', 'activate' => false,
        ])->assertCreated()->assertJsonPath('data.status', 'pending')->json('data.id');

        // Le membre ne peut pas créer son PIN tant que le compte n'est pas activé
        $this->postJson('/api/auth/otp/request', ['phone' => '+2290197444444', 'purpose' => 'signup'])->assertOk();
        $code = $this->latestOtp('+2290197444444');
        $this->postJson('/api/auth/pin/set', ['phone' => '+2290197444444', 'otp' => $code, 'pin' => '482915', 'pin_confirmation' => '482915'])
            ->assertForbidden()->assertJsonPath('error.code', 'account_not_activated');

        $this->postJson("/api/admin/members/{$id}/activate")->assertOk()->assertJsonPath('data.status', 'active');
        $this->assertDatabaseHas('audit_logs', ['action' => 'member.activate', 'entity_id' => $id]);
        $this->postJson("/api/admin/members/{$id}/activate")->assertStatus(422);

        // Une fois activé, le membre crée lui-même son PIN (DA-12)
        $this->postJson('/api/auth/otp/request', ['phone' => '+2290197444444', 'purpose' => 'signup'])->assertOk();
        $this->postJson('/api/auth/pin/set', [
            'phone' => '+2290197444444', 'otp' => $this->latestOtp('+2290197444444'), 'pin' => '482915', 'pin_confirmation' => '482915',
        ])->assertOk();
    }

    public function test_members_are_active_by_default_and_treasurer_cannot_activate(): void
    {
        $this->actingAsStaff('manager');
        $this->postJson('/api/admin/members', ['phone' => '+2290197555555', 'full_name' => 'Actif Direct'])
            ->assertCreated()->assertJsonPath('data.status', 'active');

        $pending = $this->member(['status' => 'pending', 'pin_hash' => null]);
        $this->actingAsStaff('treasurer');
        $this->postJson("/api/admin/members/{$pending->id}/activate")->assertForbidden();
    }

    /** L'OTP n'est jamais exposé par l'API : on le régénère côté test via le hash enregistré. */
    private function latestOtp(string $phone): string
    {
        $otp = \App\Modules\Identity\Infrastructure\Models\OtpCode::where('phone', $phone)->whereNull('consumed_at')->latest()->firstOrFail();
        $code = '424242';
        $otp->update(['code_hash' => \Illuminate\Support\Facades\Hash::make($code)]);

        return $code;
    }

    // ── Fiche : indicateurs, transactions, notes ───────────────────────────

    public function test_overview_kpis_come_from_real_data(): void
    {
        $this->actingAsStaff('treasurer');
        $member     = $this->member();
        $membership = $this->membership($member);
        $this->installments($membership, ['paid', 'late', 'pending', 'pending']);
        $this->creditSavings($membership, 5000);
        Payment::create([
            'reference' => 'PAY-T-1', 'idempotency_key' => (string) Str::uuid(), 'membership_id' => $membership->id,
            'channel' => 'cash', 'status' => 'confirmed', 'amount_minor' => 5000, 'confirmed_at' => now(),
        ]);
        Payment::create([
            'reference' => 'PAY-T-2', 'idempotency_key' => (string) Str::uuid(), 'membership_id' => $membership->id,
            'channel' => 'mtn', 'status' => 'pending', 'amount_minor' => 5000,
        ]);

        $kpis = $this->getJson("/api/admin/members/{$member->id}/overview")->assertOk()->json('data.kpis');

        $this->assertSame(5000, $kpis['paid_minor'], 'seuls les paiements confirmés sont encaissés');
        $this->assertSame(20000, $kpis['expected_minor'], '4 échéances × 5 000 (montant contractuel)');
        $this->assertSame(4, $kpis['installments_total']);
        $this->assertSame(1, $kpis['installments_paid']);
        $this->assertSame(1, $kpis['installments_late']);
        $this->assertSame(5000, $kpis['savings_minor']);
        $this->assertSame(50, $kpis['trust_score']);
    }

    public function test_transactions_are_paginated_and_typed(): void
    {
        $this->actingAsStaff('treasurer');
        $member     = $this->member();
        $membership = $this->membership($member);
        foreach (range(1, 12) as $i) {
            Payment::create([
                'reference' => "PAY-T-{$i}", 'idempotency_key' => (string) Str::uuid(), 'membership_id' => $membership->id,
                'channel' => 'cash', 'status' => 'confirmed', 'amount_minor' => 1000 * $i, 'confirmed_at' => now()->subDays($i),
            ]);
        }

        $this->getJson("/api/admin/members/{$member->id}/transactions?per_page=10")
            ->assertOk()
            ->assertJsonCount(10, 'data')
            ->assertJsonPath('meta.total', 12)
            ->assertJsonPath('data.0.type', 'contribution')
            ->assertJsonPath('data.0.reference', 'PAY-T-1');
    }

    public function test_internal_notes_are_listed_with_author_and_audited(): void
    {
        $manager = $this->actingAsStaff('manager');
        $member  = $this->member();

        $this->postJson("/api/admin/members/{$member->id}/notes", ['body' => 'Appelé pour régulariser son retard.'])
            ->assertCreated()
            ->assertJsonPath('data.author', $manager->full_name);

        $this->getJson("/api/admin/members/{$member->id}/notes")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.body', 'Appelé pour régulariser son retard.');

        $this->getJson("/api/admin/members/{$member->id}/history")
            ->assertOk()
            ->assertJsonPath('data.0.action', 'member.note.create');
    }
}
