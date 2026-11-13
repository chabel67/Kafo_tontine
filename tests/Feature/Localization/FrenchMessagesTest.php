<?php

namespace Tests\Feature\Localization;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsKafoFixtures;
use Tests\TestCase;

/**
 * Lot A — messages en français (locale `fr`), codes d'erreur inchangés.
 */
class FrenchMessagesTest extends TestCase
{
    use RefreshDatabase, BuildsKafoFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    public function test_locale_is_french(): void
    {
        $this->assertSame('fr', app()->getLocale());
    }

    public function test_validation_messages_and_attributes_are_french(): void
    {
        $this->postJson('/api/auth/login', ['phone' => '', 'otp' => '12', 'pin' => ''])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_error')
            ->assertJsonPath('error.message', 'Les données transmises sont invalides.')
            ->assertJsonPath('error.errors.phone.0', 'Le champ numéro de téléphone est obligatoire.')
            ->assertJsonPath('error.errors.otp.0', 'Le code OTP doit contenir exactement 6 chiffres.')
            ->assertJsonPath('error.errors.pin.0', 'Le champ code PIN est obligatoire.');
    }

    public function test_unauthenticated_and_step_up_messages_are_french(): void
    {
        $this->getJson('/api/admin/auth/me')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'unauthenticated')
            ->assertJsonPath('error.message', 'Authentification requise. Veuillez vous connecter.');

        $this->actingAsStaff('super_admin');
        $this->postJson('/api/admin/ledger/transactions/00000000-0000-0000-0000-000000000000/reverse', ['description' => 'Contre-passation test'])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'step_up_required')
            ->assertJsonPath('error.message', 'Une vérification OTP renforcée (step-up) est requise pour cette action.');
    }

    public function test_otp_errors_are_french_with_same_codes(): void
    {
        $member = $this->member();

        $this->postJson('/api/auth/login', ['phone' => $member->phone, 'otp' => '123456', 'pin' => '123456'])
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'otp_expired')
            ->assertJsonPath('error.message', 'Le code OTP a expiré ou n\'existe pas. Demandez un nouveau code.');

        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/auth/otp/request', ['phone' => $member->phone])->assertOk();
        }
        $this->postJson('/api/auth/otp/request', ['phone' => $member->phone])
            ->assertStatus(429)
            ->assertJsonPath('error.code', 'otp_rate_limited')
            ->assertJsonPath('error.message', 'Trop de demandes de code OTP. Patientez avant de réessayer.');

        $this->postJson('/api/auth/login', ['phone' => $member->phone, 'otp' => '000000', 'pin' => '123456'])
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'otp_invalid')
            ->assertJsonPath('error.message', 'Le code OTP est invalide ou a déjà été utilisé.');

        $code = $this->knownOtp($member->phone, 'login');
        $this->postJson('/api/auth/login', ['phone' => $member->phone, 'otp' => $code, 'pin' => '999999'])
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'pin_invalid')
            ->assertJsonPath('error.message', 'Le code PIN est incorrect.');
    }

    public function test_missing_permission_message_is_french(): void
    {
        $this->actingAsStaff('treasurer');
        $this->getJson('/api/admin/staff')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'unauthorized')
            ->assertJsonPath('error.message', 'Vous n\'avez pas la permission « admin.users ».');
    }
}
