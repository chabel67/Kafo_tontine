<?php

namespace Tests\Feature\Profile;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuildsKafoFixtures;
use Tests\TestCase;

/** Page « Mon profil » du back-office : changement de PIN et photo de l'agent connecté. */
class StaffProfileTest extends TestCase
{
    use RefreshDatabase, BuildsKafoFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    public function test_staff_changes_own_pin_and_other_sessions_are_revoked(): void
    {
        $staff = $this->staff('treasurer');
        $current = $staff->createToken('current')->plainTextToken;
        $staff->createToken('other-device');

        $this->withToken($current)->postJson('/api/admin/auth/pin/change', [
            'current_pin' => '123456', 'new_pin' => '482915', 'new_pin_confirmation' => '482915',
        ])->assertOk()->assertJsonPath('data.other_sessions_revoked', 1);

        $this->assertTrue(Hash::check('482915', $staff->fresh()->pin_hash));
        $this->assertSame(1, $staff->tokens()->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.staff_pin_change', 'entity_id' => $staff->id]);
    }

    public function test_wrong_current_pin_weak_pin_and_same_pin_are_refused(): void
    {
        $staff = $this->actingAsStaff('manager');

        $this->postJson('/api/admin/auth/pin/change', ['current_pin' => '000000', 'new_pin' => '482915', 'new_pin_confirmation' => '482915'])
            ->assertStatus(422)->assertJsonPath('error.code', 'pin_invalid');
        $this->postJson('/api/admin/auth/pin/change', ['current_pin' => '123456', 'new_pin' => '111111', 'new_pin_confirmation' => '111111'])
            ->assertStatus(422)->assertJsonPath('error.code', 'pin_weak');
        $this->postJson('/api/admin/auth/pin/change', ['current_pin' => '123456', 'new_pin' => '123456', 'new_pin_confirmation' => '123456'])
            ->assertStatus(422);

        $this->assertTrue(Hash::check('123456', $staff->fresh()->pin_hash));
    }

    public function test_pin_change_requires_authentication(): void
    {
        $this->postJson('/api/admin/auth/pin/change', [])->assertStatus(401);
    }

    public function test_staff_uploads_and_removes_photo(): void
    {
        Storage::fake('public');
        $staff = $this->actingAsStaff('treasurer');

        $res = $this->postJson('/api/admin/auth/photo', ['photo' => UploadedFile::fake()->image('moi.jpg', 200, 200)])
            ->assertOk()
            ->assertJsonPath('data.id', $staff->id);
        $this->assertNotNull($res->json('data.avatar_url'));
        $this->assertNotEmpty($res->json('data.permissions'));
        $path = $staff->fresh()->metadata['avatar_path'];
        Storage::disk('public')->assertExists($path);

        $this->postJson('/api/admin/auth/photo', ['photo' => UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf')])
            ->assertStatus(422);

        $this->deleteJson('/api/admin/auth/photo')->assertOk()->assertJsonPath('data.avatar_url', null);
        Storage::disk('public')->assertMissing($path);
    }
}
