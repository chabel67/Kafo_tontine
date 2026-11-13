<?php

namespace Tests\Feature\Identity;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\BuildsKafoFixtures;
use Tests\TestCase;

/** R-AUTH-03 : le code de confirmation (step-up) ne sert qu'à UNE action réussie. */
class StepUpSingleUseTest extends TestCase
{
    use RefreshDatabase, BuildsKafoFixtures;

    public function test_token_is_consumed_by_a_successful_action_but_kept_after_a_failure(): void
    {
        $this->seedRoles();
        $admin = $this->staff('admin');
        Sanctum::actingAs($admin);
        $first  = $this->campaign();
        $second = $this->campaign();
        $header = $this->stepUpHeader($admin);

        // Échec de validation (motif manquant) : le jeton reste utilisable.
        $this->postJson("/api/admin/campaigns/{$first->id}/close", [], $header)->assertStatus(422);
        $this->postJson("/api/admin/campaigns/{$first->id}/close", ['reason' => 'Fin du cycle de cotisation.'], $header)->assertOk();

        // Action réussie : le même jeton ne permet pas une seconde action sensible.
        $this->postJson("/api/admin/campaigns/{$second->id}/close", ['reason' => 'Fin du cycle de cotisation.'], $header)
            ->assertStatus(403)->assertJsonPath('error.code', 'step_up_required');
        $this->assertSame('active', $second->fresh()->status->value);
    }
}
