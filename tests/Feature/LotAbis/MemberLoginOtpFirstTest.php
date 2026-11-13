<?php

namespace Tests\Feature\LotAbis;

use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Infrastructure\Models\OtpCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsKafoFixtures;
use Tests\TestCase;

/**
 * Lot A-bis — point 5 : connexion membre, OTP vérifié avant tout état du compte
 * (même principe que loginStaff, lot A). Codes inchangés.
 */
class MemberLoginOtpFirstTest extends TestCase
{
    use RefreshDatabase, BuildsKafoFixtures;

    private function requestOtp(string $phone): void
    {
        $this->postJson('/api/auth/otp/request', ['phone' => $phone, 'purpose' => 'login'])->assertOk();
    }

    private function login(string $phone, string $otp, string $pin = '123456')
    {
        return $this->postJson('/api/auth/login', ['phone' => $phone, 'otp' => $otp, 'pin' => $pin]);
    }

    public function test_pending_account_is_revealed_only_after_a_valid_otp(): void
    {
        $member = $this->member(['status' => UserStatus::Pending]);
        $this->requestOtp($member->phone);

        // Sans l'OTP : erreur OTP, pas `account_not_activated`.
        $this->login($member->phone, '000000')->assertStatus(401)->assertJsonPath('error.code', 'otp_invalid');

        $code = $this->knownOtp($member->phone, 'login');
        $this->login($member->phone, $code)->assertStatus(403)->assertJsonPath('error.code', 'account_not_activated');
    }

    public function test_unknown_suspended_and_pending_accounts_are_indistinguishable_without_otp(): void
    {
        $pending   = $this->member(['status' => UserStatus::Pending]);
        $suspended = $this->member(['status' => UserStatus::Suspended]);

        foreach (['+2290199777777', $pending->phone, $suspended->phone] as $phone) {
            $this->login($phone, '123123')->assertStatus(401)->assertJsonPath('error.code', 'otp_expired');
        }
    }

    public function test_suspended_account_with_valid_otp_is_unauthorized_and_otp_consumed(): void
    {
        $member = $this->member(['status' => UserStatus::Suspended]);
        $this->requestOtp($member->phone);
        $code = $this->knownOtp($member->phone, 'login');

        $this->login($member->phone, $code)->assertStatus(403)->assertJsonPath('error.code', 'unauthorized');
        $this->assertSame(0, OtpCode::where('phone', $member->phone)->whereNull('consumed_at')->count());

        $log = \App\Modules\Identity\Infrastructure\Models\AccessLog::where('event', 'login_failed_user_not_found')->latest('created_at')->first();
        $this->assertNotNull($log);
        $this->assertStringNotContainsString($member->phone, json_encode($log->context ?? []), 'numéro masqué dans le journal');
    }

    public function test_active_member_login_still_returns_a_token(): void
    {
        $member = $this->member();
        $this->requestOtp($member->phone);
        $code = $this->knownOtp($member->phone, 'login');

        $this->login($member->phone, $code)->assertOk()->assertJsonStructure(['data' => ['token', 'user']]);
    }

    public function test_wrong_pin_after_valid_otp_keeps_pin_invalid_code(): void
    {
        $member = $this->member();
        $this->requestOtp($member->phone);
        $code = $this->knownOtp($member->phone, 'login');

        $this->login($member->phone, $code, '999999')->assertJsonPath('error.code', 'pin_invalid');
    }
}
