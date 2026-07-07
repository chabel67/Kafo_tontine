<?php

namespace App\Providers;

use App\Modules\Audit\Application\AuditService;
use App\Modules\Identity\Application\AuthorizationService;
use App\Modules\Identity\Application\AuthService;
use App\Modules\Identity\Application\OtpService;
use App\Modules\Identity\Application\PinService;
use App\Modules\Ledger\Application\LedgerJournalService;
use App\Modules\Ledger\Application\LedgerManualEntryService;
use App\Modules\Ledger\Application\LedgerService;
use App\Modules\Lending\Application\EligibilityService;
use App\Modules\Lending\Application\LendingService;
use App\Modules\Lending\Application\RepaymentScheduleGeneratorService;
use App\Modules\Notifications\Application\NotificationService;
use App\Modules\Payments\Application\PaymentService;
use App\Modules\Payments\Domain\Contracts\PspDriver;
use App\Modules\Payments\Infrastructure\Psp\KkiapayDriver;
use App\Modules\Tontine\Application\CampaignClosureService;
use App\Modules\Tontine\Application\CampaignPayoutService;
use App\Modules\Tontine\Application\CampaignService;
use App\Modules\Tontine\Application\InstallmentGeneratorService;
use App\Modules\Tontine\Application\MembershipService;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class ModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Audit
        $this->app->singleton(AuditService::class);

        // Identity
        $this->app->singleton(OtpService::class);
        $this->app->singleton(PinService::class);
        $this->app->singleton(AuthorizationService::class);
        $this->app->singleton(AuthService::class, fn ($app) => new AuthService(
            $app->make(OtpService::class),
            $app->make(PinService::class),
        ));

        // Ledger
        $this->app->singleton(LedgerService::class);
        $this->app->singleton(LedgerJournalService::class, fn ($app) => new LedgerJournalService(
            $app->make(LedgerService::class),
        ));
        $this->app->singleton(LedgerManualEntryService::class, fn ($app) => new LedgerManualEntryService(
            $app->make(LedgerService::class),
        ));

        // Payments
        $this->app->singleton(PaymentService::class, fn ($app) => new PaymentService(
            $app->make(LedgerService::class),
        ));
        $this->app->singleton(PspDriver::class, fn ($app) => new KkiapayDriver(
            $app['config']->get('services.kkiapay', []),
        ));

        // Lending
        $this->app->singleton(EligibilityService::class, fn ($app) => new EligibilityService(
            $app->make(LedgerService::class),
        ));
        $this->app->singleton(RepaymentScheduleGeneratorService::class);
        $this->app->singleton(LendingService::class, fn ($app) => new LendingService(
            $app->make(EligibilityService::class),
            $app->make(LedgerService::class),
            $app->make(RepaymentScheduleGeneratorService::class),
        ));

        // Notifications
        $this->app->singleton(NotificationService::class);

        // Tontine
        $this->app->singleton(InstallmentGeneratorService::class);
        $this->app->singleton(CampaignService::class);
        $this->app->singleton(MembershipService::class, fn ($app) => new MembershipService(
            $app->make(InstallmentGeneratorService::class),
            $app->make(LedgerService::class),
        ));
        $this->app->singleton(CampaignClosureService::class, fn ($app) => new CampaignClosureService(
            $app->make(LedgerService::class),
        ));
        $this->app->singleton(CampaignPayoutService::class, fn ($app) => new CampaignPayoutService(
            $app->make(LedgerService::class),
        ));
    }

    public function boot(): void
    {
        $apiRoutes = [
            // Member-facing routes
            base_path('app/Modules/Identity/Routes/api.php'),
            base_path('app/Modules/Tontine/Routes/api.php'),
            base_path('app/Modules/Payments/Routes/api.php'),
            base_path('app/Modules/Lending/Routes/api.php'),
            // Admin (back-office) routes
            base_path('app/Modules/Identity/Routes/api_admin.php'),
            base_path('app/Modules/Tontine/Routes/api_admin.php'),
            base_path('app/Modules/Ledger/Routes/api_admin.php'),
            base_path('app/Modules/Payments/Routes/api_admin.php'),
            base_path('app/Modules/Lending/Routes/api_admin.php'),
            base_path('app/Modules/Notifications/Routes/api_admin.php'),
            base_path('app/Modules/Reporting/Routes/api_admin.php'),
            base_path('app/Modules/Audit/Routes/api_admin.php'),
        ];

        foreach ($apiRoutes as $routeFile) {
            Route::middleware('api')->prefix('api')->group($routeFile);
        }
    }
}
