<?php

namespace Tests\Feature\Demo;

use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Tontine\Infrastructure\Models\Campaign;
use Database\Seeders\DevSuperAdminSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsKafoFixtures;
use Tests\TestCase;

/** Jeu de démonstration : cohérent (grand livre équilibré, rapprochements) et rejouable sans effet. */
class SeedDemoDataTest extends TestCase
{
    use RefreshDatabase, BuildsKafoFixtures;

    public function test_demo_data_is_consistent_and_idempotent(): void
    {
        $this->seedRoles();
        $this->seed(DevSuperAdminSeeder::class);

        $this->artisan('kafo:seed-demo')->assertSuccessful();

        $this->assertGreaterThanOrEqual(48, User::where('phone', 'like', '+22901970%')->count());
        $this->assertSame(8, Campaign::count());
        $this->assertGreaterThan(500, DB::table('payments')->where('status', 'confirmed')->count());
        $this->assertLedgerBalanced();
        // Tontine : aucune épargne individuelle ouverte à la validation des adhésions (R-ROT-06).
        $rotatingIds = DB::table('memberships')->join('tontine_campaigns', 'tontine_campaigns.id', '=', 'memberships.campaign_id')
            ->where('campaign_type', 'rotating')->pluck('memberships.id');
        $this->assertSame(0, DB::table('ledger_accounts')->whereIn('key', $rotatingIds->map(fn ($id) => "MEMBER_SAVINGS:{$id}"))->count());
        $this->artisan('kafo:diagnose-ledger')->doesntExpectOutputToContain('écart -')->assertSuccessful();

        $counts = [User::count(), DB::table('payments')->count(), DB::table('ledger_transactions')->count()];
        $this->artisan('kafo:seed-demo')->assertSuccessful();
        $this->assertSame($counts, [User::count(), DB::table('payments')->count(), DB::table('ledger_transactions')->count()]);
    }
}
