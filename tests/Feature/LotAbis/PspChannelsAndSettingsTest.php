<?php

namespace Tests\Feature\LotAbis;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Support\BuildsKafoFixtures;
use Tests\TestCase;

/**
 * DA-20 — les moyens de paiement sont des agrégateurs (KKiaPay, FedaPay), activables
 * seulement s'ils sont configurés ; PATCH /admin/settings partiel, step-up maintenu.
 */
class PspChannelsAndSettingsTest extends TestCase
{
    use RefreshDatabase, BuildsKafoFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    private function setting(string $key): ?string
    {
        $v = DB::table('system_settings')->where('key', $key)->value('value');

        return $v === null ? null : (string) $v;
    }

    private function patchSettings(\App\Modules\Identity\Infrastructure\Models\User $admin, array $settings)
    {
        return $this->patchJson('/api/admin/settings', ['settings' => $settings], $this->stepUpHeader($admin));
    }

    public function test_settings_are_per_aggregator_not_per_network(): void
    {
        foreach (['mtn', 'moov', 'orange', 'wave'] as $network) {
            $this->assertNull($this->setting("psp_{$network}_enabled"));
        }
        $this->assertNotNull($this->setting('psp_kkiapay_enabled'));
        $this->assertSame('false', $this->setting('psp_fedapay_enabled'));
    }

    public function test_unconfigured_aggregator_cannot_be_enabled(): void
    {
        config()->set('services.fedapay', []);
        app()->forgetInstance(\App\Modules\Payments\Infrastructure\Psp\PspRegistry::class);
        $admin = $this->actingAsStaff('admin');

        $this->patchSettings($admin, [['key' => 'psp_fedapay_enabled', 'value' => 'true']])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'psp_not_configured')
            ->assertJsonPath('error.details.channel', 'fedapay');
        $this->assertSame('false', $this->setting('psp_fedapay_enabled'));

        // Requête refusée en bloc : la clé valide qui l'accompagne n'est pas écrite.
        $this->patchSettings($admin, [
            ['key' => 'min_trust_score', 'value' => '65'],
            ['key' => 'psp_fedapay_enabled', 'value' => 'true'],
        ])->assertStatus(422);
        $this->assertSame('60', $this->setting('min_trust_score'));

        // Une fois configuré : activation libre.
        $this->configurePsps(fedapayEnabled: false);
        $this->patchSettings($admin, [['key' => 'psp_fedapay_enabled', 'value' => 'true']])->assertOk();
        $this->assertSame('true', $this->setting('psp_fedapay_enabled'));
    }

    public function test_settings_exposure_flags_configured_aggregators(): void
    {
        $this->configurePsps();
        config()->set('services.fedapay.secret_key', null);
        app()->forgetInstance(\App\Modules\Payments\Infrastructure\Psp\PspRegistry::class);

        $this->actingAsStaff('admin');
        $psp = collect($this->getJson('/api/admin/settings')->assertOk()->json('data.psp'))->keyBy('key');

        $this->assertTrue($psp['psp_kkiapay_enabled']['configured']);
        $this->assertTrue($psp['psp_kkiapay_enabled']['effective_enabled']);
        $this->assertSame(['mtn', 'moov'], $psp['psp_kkiapay_enabled']['networks']);
        $this->assertFalse($psp['psp_fedapay_enabled']['configured']);
        $this->assertSame('true', $psp['psp_fedapay_enabled']['value'], 'la donnée stockée est exposée telle quelle');
        $this->assertFalse($psp['psp_fedapay_enabled']['effective_enabled']);
        $this->assertTrue($psp['psp_cash_enabled']['configured']);

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

    public function test_member_sees_only_available_aggregators(): void
    {
        $this->configurePsps(fedapayEnabled: false);
        Sanctum::actingAs($this->member());

        $this->getJson('/api/member/payment-methods')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.channel', 'kkiapay')
            ->assertJsonPath('data.0.networks', ['mtn', 'moov'])
            ->assertJsonPath('data.0.mode', 'widget');
    }

    public function test_member_initiation_refuses_networks_cash_and_disabled_aggregator(): void
    {
        $this->configurePsps(fedapayEnabled: false);
        $member     = $this->member();
        $membership = $this->membership($member);
        Sanctum::actingAs($member);

        // Un réseau n'est plus un moyen de paiement.
        $this->postJson('/api/member/payments/initiate', [
            'membership_id' => $membership->id, 'channel' => 'mtn', 'amount_minor' => 5000,
        ])->assertStatus(422);

        foreach (['cash', 'fedapay'] as $channel) {
            $this->postJson('/api/member/payments/initiate', [
                'membership_id' => $membership->id, 'channel' => $channel, 'amount_minor' => 5000,
            ])->assertStatus(422)->assertJsonPath('error.code', 'channel_not_supported')
                ->assertJsonPath('error.details.supported_channels', ['kkiapay']);
        }
        $this->assertDatabaseCount('payments', 0);

        $this->postJson('/api/member/payments/initiate', [
            'membership_id' => $membership->id, 'channel' => 'kkiapay', 'amount_minor' => 5000,
        ])->assertCreated()
            ->assertJsonPath('data.channel', 'kkiapay')
            ->assertJsonPath('data.checkout.provider', 'kkiapay')
            ->assertJsonPath('data.checkout.mode', 'widget');
    }
}
