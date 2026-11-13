<?php

namespace Tests\Feature\LotAbis;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Support\BuildsKafoFixtures;
use Tests\TestCase;

/**
 * Lot A-bis — point 6 (Orange et Wave non routés par KKiaPay, DA-13) et point 12
 * (PATCH /admin/settings partiel, step-up maintenu).
 */
class PspChannelsAndSettingsTest extends TestCase
{
    use RefreshDatabase, BuildsKafoFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    private function setting(string $key): string
    {
        return (string) DB::table('system_settings')->where('key', $key)->value('value');
    }

    private function patchSettings(\App\Modules\Identity\Infrastructure\Models\User $admin, array $settings)
    {
        return $this->patchJson('/api/admin/settings', ['settings' => $settings], $this->stepUpHeader($admin));
    }

    public function test_unsupported_channels_cannot_be_enabled(): void
    {
        $admin = $this->actingAsStaff('admin');
        $this->assertSame('false', $this->setting('psp_wave_enabled'));

        $this->patchSettings($admin, [['key' => 'psp_wave_enabled', 'value' => 'true']])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'channel_not_supported')
            ->assertJsonPath('error.details.channel', 'wave');
        $this->assertSame('false', $this->setting('psp_wave_enabled'));

        // Requête refusée en bloc : la clé valide qui l'accompagne n'est pas écrite.
        $this->patchSettings($admin, [
            ['key' => 'min_trust_score', 'value' => '65'],
            ['key' => 'psp_wave_enabled', 'value' => 'true'],
        ])->assertStatus(422);
        $this->assertSame('60', $this->setting('min_trust_score'));

        // Désactiver un canal non supporté reste possible ; le réactiver, non.
        $this->assertSame('true', $this->setting('psp_orange_enabled'));
        $this->patchSettings($admin, [['key' => 'psp_orange_enabled', 'value' => 'false']])->assertOk();
        $this->patchSettings($admin, [['key' => 'psp_orange_enabled', 'value' => 'true']])
            ->assertStatus(422)->assertJsonPath('error.code', 'channel_not_supported');

        // Canaux routés : activation libre.
        $this->patchSettings($admin, [['key' => 'psp_moov_enabled', 'value' => 'true']])->assertOk();
        $this->assertSame('true', $this->setting('psp_moov_enabled'));
    }

    public function test_full_payload_with_already_enabled_orange_still_saves(): void
    {
        $admin = $this->actingAsStaff('admin');
        $full  = DB::table('system_settings')->get(['key', 'value'])
            ->map(fn ($r) => ['key' => $r->key, 'value' => $r->key === 'min_trust_score' ? '70' : $r->value])->all();

        $this->patchSettings($admin, $full)->assertOk();
        $this->assertSame('70', $this->setting('min_trust_score'));
        $this->assertSame('true', $this->setting('psp_orange_enabled'), 'valeur stockée inchangée');
    }

    public function test_settings_exposure_flags_supported_channels(): void
    {
        $this->actingAsStaff('admin');
        $psp = collect($this->getJson('/api/admin/settings')->assertOk()->json('data.psp'))->keyBy('key');

        $this->assertFalse($psp['psp_orange_enabled']['supported']);
        $this->assertSame('true', $psp['psp_orange_enabled']['value'], 'la donnée stockée est exposée telle quelle');
        $this->assertFalse($psp['psp_orange_enabled']['effective_enabled']);
        $this->assertFalse($psp['psp_wave_enabled']['supported']);
        $this->assertTrue($psp['psp_mtn_enabled']['supported']);
        $this->assertTrue($psp['psp_mtn_enabled']['effective_enabled']);
        $this->assertTrue($psp['psp_cash_enabled']['supported']);

        $lending = collect($this->getJson('/api/admin/settings')->json('data.lending'))->first();
        $this->assertArrayNotHasKey('supported', $lending);
    }

    public function test_partial_settings_update_writes_only_given_keys_and_keeps_step_up(): void
    {
        $admin = $this->actingAsStaff('admin');

        $this->patchJson('/api/admin/settings', ['settings' => [['key' => 'loan_coefficient_high', 'value' => '0.75']]])
            ->assertStatus(403)->assertJsonPath('error.code', 'step_up_required');

        $before = DB::table('system_settings')->where('key', '<>', 'loan_coefficient_high')->pluck('updated_at', 'key');
        $this->travel(5)->minutes();

        $this->patchSettings($admin, [['key' => 'loan_coefficient_high', 'value' => '0.75']])->assertOk();
        $this->assertSame('0.75', $this->setting('loan_coefficient_high'));
        $this->assertEquals($before, DB::table('system_settings')->where('key', '<>', 'loan_coefficient_high')->pluck('updated_at', 'key'));

        $audit = \App\Modules\Audit\Infrastructure\Models\AuditLog::where('action', 'settings.update')->firstOrFail();
        $this->assertSame(['loan_coefficient_high' => '0.75'], $audit->new_values);
        $this->assertSame(['loan_coefficient_high' => '0.70'], $audit->old_values);

        $this->patchSettings($admin, [])->assertStatus(422);
    }

    public function test_member_initiation_refuses_orange_wave_and_cash(): void
    {
        $member     = $this->member();
        $membership = $this->membership($member);
        Sanctum::actingAs($member);

        foreach (['orange', 'wave', 'cash'] as $channel) {
            $this->postJson('/api/member/payments/initiate', [
                'membership_id' => $membership->id, 'channel' => $channel, 'amount_minor' => 5000,
            ])->assertStatus(422)->assertJsonPath('error.code', 'channel_not_supported')->assertJsonPath('error.details.channel', $channel);
        }
        $this->assertDatabaseCount('payments', 0);

        $this->postJson('/api/member/payments/initiate', [
            'membership_id' => $membership->id, 'channel' => 'mtn', 'amount_minor' => 5000,
        ])->assertCreated()->assertJsonPath('data.channel', 'mtn');
    }
}
