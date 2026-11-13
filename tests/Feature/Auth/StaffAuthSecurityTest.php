<?php

namespace Tests\Feature\Auth;

use App\Modules\Identity\Infrastructure\Models\OtpCode;
use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Support\BuildsKafoFixtures;
use Tests\TestCase;

/**
 * Lot A — sécurité de l'authentification :
 *  a) pas d'énumération via `requires_pin_setup` (OTP vérifié d'abord) ;
 *  b) `pin/set` refuse de remplacer un PIN existant (`pin_already_set`) ;
 *  c) expiration des jetons staff après inactivité (`session_expired`).
 */
class StaffAuthSecurityTest extends TestCase
{
    use RefreshDatabase, BuildsKafoFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    private function requestStaffOtp(string $phone, string $purpose = 'login'): void
    {
        $this->postJson('/api/admin/auth/otp/request', ['phone' => $phone, 'purpose' => $purpose])->assertOk();
    }

    // ── a) Login staff : OTP vérifié avant requires_pin_setup ────────────────

    public function test_staff_without_pin_must_prove_otp_before_requires_pin_setup(): void
    {
        $staff = $this->staff('treasurer');
        $staff->update(['pin_hash' => null]);
        $this->requestStaffOtp($staff->phone);

        // Mauvais OTP : erreur OTP, jamais `requires_pin_setup` (plus d'énumération possible).
        $this->postJson('/api/admin/auth/login', ['phone' => $staff->phone, 'otp' => '000000', 'pin' => '000000'])
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'otp_invalid')
            ->assertJsonMissingPath('data.requires_pin_setup');

        // Bon OTP : le compte sans PIN est signalé, sans jeton.
        $code = $this->knownOtp($staff->phone, 'login');
        $this->postJson('/api/admin/auth/login', ['phone' => $staff->phone, 'otp' => $code, 'pin' => '000000'])
            ->assertOk()
            ->assertJsonPath('data.requires_pin_setup', true)
            ->assertJsonMissingPath('data.token');

        // L'OTP login est consommé (anti-rejeu).
        $this->assertSame(0, OtpCode::where('phone', $staff->phone)->where('purpose', 'login')->whereNull('consumed_at')->count());
    }

    public function test_unknown_phone_and_account_without_pin_are_indistinguishable_without_otp(): void
    {
        $staff = $this->staff('manager');
        $staff->update(['pin_hash' => null]);

        // Aucun OTP n'est émis pour un numéro inconnu : même erreur qu'un staff sans OTP valide.
        $this->postJson('/api/admin/auth/otp/request', ['phone' => '+2290199888888'])->assertOk();
        $unknown = $this->postJson('/api/admin/auth/login', ['phone' => '+2290199888888', 'otp' => '123123', 'pin' => '000000']);
        $known   = $this->postJson('/api/admin/auth/login', ['phone' => $staff->phone,  'otp' => '123123', 'pin' => '000000']);

        $unknown->assertStatus(401)->assertJsonPath('error.code', 'otp_expired');
        $known->assertStatus(401)->assertJsonPath('error.code', 'otp_expired');
    }

    public function test_full_first_login_flow_then_pin_cannot_be_replaced_with_otp_alone(): void
    {
        $staff = $this->staff('admin');
        $staff->update(['pin_hash' => null]);

        // 1. login (OTP login + PIN quelconque) → requires_pin_setup
        $this->requestStaffOtp($staff->phone);
        $this->postJson('/api/admin/auth/login', [
            'phone' => $staff->phone, 'otp' => $this->knownOtp($staff->phone, 'login'), 'pin' => '000000',
        ])->assertOk()->assertJsonPath('data.requires_pin_setup', true);

        // 2. OTP signup + création du PIN → jeton
        $this->requestStaffOtp($staff->phone, 'signup');
        $this->postJson('/api/admin/auth/pin/set', [
            'phone' => $staff->phone, 'otp' => $this->knownOtp($staff->phone, 'signup'),
            'pin' => '482915', 'pin_confirmation' => '482915',
        ])->assertOk()->assertJsonStructure(['data' => ['token', 'user']]);

        // 3. Un nouvel OTP signup ne permet PAS de remplacer le PIN.
        $this->requestStaffOtp($staff->phone, 'signup');
        $this->postJson('/api/admin/auth/pin/set', [
            'phone' => $staff->phone, 'otp' => $this->knownOtp($staff->phone, 'signup'),
            'pin' => '739164', 'pin_confirmation' => '739164',
        ])->assertStatus(409)->assertJsonPath('error.code', 'pin_already_set');

        $this->assertTrue(Hash::check('482915', $staff->fresh()->pin_hash), 'le PIN initial est conservé');
    }

    public function test_member_pin_set_refuses_existing_pin(): void
    {
        $member = $this->member(); // PIN 123456 posé par la fixture

        $this->postJson('/api/auth/otp/request', ['phone' => $member->phone, 'purpose' => 'signup'])->assertOk();
        $this->postJson('/api/auth/pin/set', [
            'phone' => $member->phone, 'otp' => $this->knownOtp($member->phone, 'signup'),
            'pin' => '739164', 'pin_confirmation' => '739164',
        ])->assertStatus(409)->assertJsonPath('error.code', 'pin_already_set');

        $this->assertTrue(Hash::check('123456', $member->fresh()->pin_hash));
    }

    public function test_weak_pin_is_rejected_before_consuming_the_otp(): void
    {
        $member = $this->member(['pin_hash' => null]);
        $this->postJson('/api/auth/otp/request', ['phone' => $member->phone, 'purpose' => 'signup'])->assertOk();
        $code = $this->knownOtp($member->phone, 'signup');

        $this->postJson('/api/auth/pin/set', ['phone' => $member->phone, 'otp' => $code, 'pin' => '123456', 'pin_confirmation' => '123456'])
            ->assertStatus(422)->assertJsonPath('error.code', 'pin_weak');

        // L'OTP reste utilisable pour un PIN correct.
        $this->postJson('/api/auth/pin/set', ['phone' => $member->phone, 'otp' => $code, 'pin' => '482915', 'pin_confirmation' => '482915'])
            ->assertOk();
    }

    // ── c) Inactivité staff (R-AUTH-01) ─────────────────────────────────────

    private function tokenFor(User $user, string $name, int $idleMinutes): string
    {
        $new = $user->createToken($name);
        $new->accessToken->forceFill(['last_used_at' => now()->subMinutes($idleMinutes)])->save();

        return $new->plainTextToken;
    }

    public function test_idle_staff_token_is_revoked_with_session_expired(): void
    {
        $staff = $this->staff('treasurer');
        $token = $this->tokenFor($staff, 'backoffice', 31);

        $this->withToken($token)->getJson('/api/admin/auth/me')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'session_expired');

        $this->assertSame(0, PersonalAccessToken::where('tokenable_id', $staff->id)->count(), 'jeton révoqué');
        $this->assertDatabaseHas('access_logs', ['user_id' => $staff->id, 'event' => 'session_expired_idle']);
    }

    public function test_active_staff_token_keeps_working_and_is_refreshed(): void
    {
        $staff = $this->staff('treasurer');
        $token = $this->tokenFor($staff, 'backoffice', 29);

        $this->withToken($token)->getJson('/api/admin/auth/me')->assertOk();

        $last = PersonalAccessToken::where('tokenable_id', $staff->id)->value('last_used_at');
        $this->assertTrue(now()->diffInSeconds($last, true) < 60, 'last_used_at mis à jour par Sanctum');
    }

    public function test_idle_limit_is_configurable(): void
    {
        config(['kafo.staff_idle_minutes' => 5]);
        $staff = $this->staff('manager');
        $token = $this->tokenFor($staff, 'backoffice', 6);

        $this->withToken($token)->getJson('/api/admin/auth/me')
            ->assertStatus(401)->assertJsonPath('error.code', 'session_expired');
    }

    public function test_member_tokens_never_expire_on_inactivity(): void
    {
        $member = $this->member();
        $token  = $this->tokenFor($member, 'member', 60 * 24 * 10);

        $this->withToken($token)->getJson('/api/auth/me')->assertOk();
        $this->withToken($token)->getJson('/api/member/dashboard')->assertOk();
    }

    public function test_stale_staff_token_does_not_block_public_login_routes(): void
    {
        $staff = $this->staff('treasurer');
        $token = $this->tokenFor($staff, 'backoffice', 120);

        $this->withToken($token)->postJson('/api/admin/auth/otp/request', ['phone' => $staff->phone])->assertOk();
    }
}
