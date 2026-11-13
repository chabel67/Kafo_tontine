<?php

namespace Tests\Feature\LotAbis;

use App\Modules\Identity\Domain\Enums\KycStatus;
use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Support\BuildsKafoFixtures;
use Tests\TestCase;

/**
 * Lot A-bis — point 1 : KYC niveau 2 (R-KYC-01) après un niveau 1 vérifié, et cohérence
 * de l'approbation staff du niveau 2.
 */
class KycLevel2Test extends TestCase
{
    use RefreshDatabase, BuildsKafoFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seedRoles();
    }

    private function submitLevel2(User $member)
    {
        Sanctum::actingAs($member);

        return $this->post('/api/member/kyc/submit', [
            'level'            => 2,
            'selfie'           => UploadedFile::fake()->image('selfie.jpg'),
            'proof_of_address' => UploadedFile::fake()->image('justificatif.jpg'),
        ], ['Accept' => 'application/json']);
    }

    private function submitLevel1(User $member)
    {
        Sanctum::actingAs($member);

        return $this->post('/api/member/kyc/submit', [
            'level'     => 1,
            'cni_front' => UploadedFile::fake()->image('cni.jpg'),
        ], ['Accept' => 'application/json']);
    }

    public function test_level2_can_be_submitted_after_a_verified_level1(): void
    {
        $member = $this->member(['kyc_level' => 1, 'kyc_status' => KycStatus::Verified]);

        $this->submitLevel2($member)->assertOk()->assertJsonPath('data.kyc_status', 'pending');

        $member->refresh();
        $this->assertSame(1, $member->kyc_level, 'le niveau ne change qu\'à l\'approbation');
        $this->assertSame(KycStatus::Pending, $member->kyc_status);
        $this->assertNotEmpty($member->kyc_documents['level2']['selfie_path']);
        Storage::disk('local')->assertExists($member->kyc_documents['level2']['proof_path']);
    }

    public function test_submission_refused_while_pending(): void
    {
        $member = $this->member(['kyc_level' => 1, 'kyc_status' => KycStatus::Pending]);

        $this->submitLevel2($member)->assertStatus(409)->assertJsonPath('error.code', 'kyc_not_submittable');
    }

    public function test_level1_duplicate_refused_once_verified(): void
    {
        $member = $this->member(['kyc_level' => 1, 'kyc_status' => KycStatus::Verified]);

        $this->submitLevel1($member)->assertStatus(409)->assertJsonPath('error.code', 'kyc_not_submittable');
    }

    public function test_level2_refused_when_already_level2(): void
    {
        $verified = $this->member(['kyc_level' => 2, 'kyc_status' => KycStatus::Verified]);
        $this->submitLevel2($verified)->assertStatus(409)->assertJsonPath('error.code', 'kyc_not_submittable');

        // Même après un refus ultérieur, un niveau déjà acquis ne se renvoie pas.
        $rejected = $this->member(['kyc_level' => 2, 'kyc_status' => KycStatus::Rejected]);
        $this->submitLevel2($rejected)->assertStatus(409);
    }

    public function test_level2_requires_level1_and_resubmission_after_rejection_is_allowed(): void
    {
        $level0 = $this->member(['kyc_level' => 0, 'kyc_status' => KycStatus::None]);
        $this->submitLevel2($level0)->assertStatus(422)->assertJsonPath('error.code', 'kyc_level_required');

        // Refus du niveau 2 : kyc_level reste 1, nouvel envoi possible.
        $rejected = $this->member(['kyc_level' => 1, 'kyc_status' => KycStatus::Rejected]);
        $this->submitLevel2($rejected)->assertOk();

        // Refus du niveau 1 : nouvel envoi du niveau 1 possible (inchangé).
        $l1Rejected = $this->member(['kyc_level' => 0, 'kyc_status' => KycStatus::Rejected]);
        $this->submitLevel1($l1Rejected)->assertOk();
    }

    public function test_staff_approval_of_level2_requires_level1_and_documents(): void
    {
        $noDocs = $this->member(['kyc_level' => 1, 'kyc_status' => KycStatus::Pending]);
        $noL1   = $this->member(['kyc_level' => 0, 'kyc_status' => KycStatus::Pending]);

        $member = $this->member(['kyc_level' => 1, 'kyc_status' => KycStatus::Verified]);
        $this->submitLevel2($member)->assertOk();

        $this->actingAsStaff('admin');

        $this->postJson("/api/admin/members/{$noL1->id}/kyc/approve", ['level' => 2])
            ->assertStatus(422)->assertJsonPath('error.code', 'kyc_level_required');
        $this->postJson("/api/admin/members/{$noDocs->id}/kyc/approve", ['level' => 2])
            ->assertStatus(422)->assertJsonPath('error.code', 'kyc_documents_missing');

        $this->postJson("/api/admin/members/{$member->id}/kyc/approve", ['level' => 2])
            ->assertOk()->assertJsonPath('data.kyc_level', 2)->assertJsonPath('data.kyc_status', 'verified');

        $this->assertDatabaseHas('audit_logs', ['action' => 'kyc.approve', 'entity_id' => $member->id]);
    }
}
