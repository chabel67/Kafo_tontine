<?php

namespace App\Providers;

use App\Modules\Audit\Application\AuditService;
use App\Modules\Identity\Application\AuthorizationService;
use App\Modules\Identity\Application\AuthService;
use App\Modules\Identity\Application\OtpService;
use App\Modules\Identity\Application\PinService;
use App\Modules\Ledger\Application\LedgerAccountLabeler;
use App\Modules\Ledger\Application\LedgerJournalService;
use App\Modules\Ledger\Application\LedgerManualEntryService;
use App\Modules\Ledger\Application\LedgerService;
use App\Modules\Ledger\Domain\Enums\AccountType;
use App\Modules\Lending\Application\EligibilityService;
use App\Modules\Lending\Application\LendingService;
use App\Modules\Lending\Application\LoanAccountLabelResolver;
use App\Modules\Lending\Application\RepaymentScheduleGeneratorService;
use App\Modules\Notifications\Application\NotificationService;
use App\Modules\Payments\Application\PaymentService;
use App\Modules\Payments\Domain\Contracts\PspDriver;
use App\Modules\Payments\Infrastructure\Psp\KkiapayDriver;
use App\Modules\Tontine\Application\CampaignClosureService;
use App\Modules\Tontine\Application\CampaignPayoutService;
use App\Modules\Tontine\Application\CampaignService;
use App\Modules\Tontine\Application\InstallmentGeneratorService;
use App\Modules\Tontine\Application\MembershipAccountLabelResolver;
use App\Modules\Tontine\Application\MembershipService;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class ModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Audit
        $this->app->singleton(AuditService::class);
        $this->app->singleton(\App\Modules\Audit\Application\CashRegisterService::class);
        $this->app->singleton(\App\Modules\Audit\Application\AuditEntityLabeler::class, function () {
            // Libellés `entity_label` du journal d'audit (DA-09 : chaque module fournit
            // le libellé de ses propres entités, l'Audit ne lit pas leurs modèles).
            $labeler = new \App\Modules\Audit\Application\AuditEntityLabeler();
            $labeler->registerResolver(['user'], new \App\Modules\Identity\Application\UserAuditLabelResolver());
            $labeler->registerResolver(
                \App\Modules\Tontine\Application\TontineAuditLabelResolver::ENTITY_TYPES,
                new \App\Modules\Tontine\Application\TontineAuditLabelResolver(),
            );
            $labeler->registerResolver(['payment'], new \App\Modules\Payments\Application\PaymentAuditLabelResolver());
            $labeler->registerResolver(
                \App\Modules\Lending\Application\LendingAuditLabelResolver::ENTITY_TYPES,
                new \App\Modules\Lending\Application\LendingAuditLabelResolver(),
            );
            $labeler->registerResolver(['LedgerTransaction'], new \App\Modules\Ledger\Application\LedgerAuditLabelResolver());

            return $labeler;
        });

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
        $this->app->singleton(LedgerAccountLabeler::class, function () {
            // Libellés des comptes possédés par d'autres modules (DA-09 : pas d'accès
            // Eloquent du Ledger vers Tontine/Lending — chaque module fournit son resolver).
            $labeler = new LedgerAccountLabeler();
            $labeler->registerResolver(AccountType::MemberSavings, new MembershipAccountLabelResolver());
            $labeler->registerResolver(AccountType::LoanReceivable, new LoanAccountLabelResolver());

            return $labeler;
        });
        $this->app->singleton(LedgerJournalService::class, fn ($app) => new LedgerJournalService(
            $app->make(LedgerService::class),
            $app->make(LedgerAccountLabeler::class),
        ));
        $this->app->singleton(LedgerManualEntryService::class, fn ($app) => new LedgerManualEntryService(
            $app->make(LedgerService::class),
        ));

        // Payments
        $this->app->singleton(PaymentService::class, fn ($app) => new PaymentService(
            $app->make(LedgerService::class),
            $app->make(\App\Modules\Audit\Application\CashRegisterService::class),
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
            $app->make(NotificationService::class),
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
        // R-SAV-01 / R-CAMP-16 : calculs uniques (disponible d'une adhésion, règlement de clôture).
        $this->app->singleton(\App\Modules\Tontine\Application\SavingsAvailabilityService::class);
        $this->app->singleton(\App\Modules\Tontine\Application\PayoutCalculator::class);
        $this->app->singleton(CampaignClosureService::class, fn ($app) => new CampaignClosureService(
            $app->make(\App\Modules\Tontine\Application\PayoutCalculator::class),
            $app->make(\App\Modules\Tontine\Application\EarlyWithdrawalService::class),
        ));
        $this->app->singleton(CampaignPayoutService::class, fn ($app) => new CampaignPayoutService(
            $app->make(LedgerService::class),
            $app->make(\App\Modules\Tontine\Application\PayoutCalculator::class),
            $app->make(LendingService::class),
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
            base_path('app/Modules/Notifications/Routes/api.php'),
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

        // `staff_idle` (R-AUTH-01) est posé au niveau du groupe pour s'exécuter avant
        // `auth:sanctum` (qui écrase last_used_at). Il n'expire que les jetons staff
        // `backoffice` : les jetons membres traversent sans contrôle. Il couvre aussi
        // les routes membres pour qu'un jeton staff ne puisse pas y être « rafraîchi ».
        foreach ($apiRoutes as $routeFile) {
            Route::middleware(['api', 'staff_idle'])->prefix('api')->group($routeFile);
        }
    }
}
