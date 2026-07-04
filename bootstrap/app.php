<?php

use App\Modules\Identity\Domain\Exceptions\BusinessException;
use App\Modules\Payments\Domain\Exceptions\DuplicateCashPaymentException;
use App\Shared\ApiResponse;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withProviders([
        App\Providers\ModuleServiceProvider::class,
    ])
    ->withMiddleware(function (Middleware $middleware) {
        // API-only app : ne jamais rediriger vers route('login') — retourner null
        // pour que AuthenticationException soit levée proprement et convertie en 401
        $middleware->redirectGuestsTo(fn () => null);

        $middleware->alias([
            'step_up'    => \App\Modules\Identity\Http\Middleware\RequireStepUpToken::class,
            'permission' => \App\Modules\Identity\Http\Middleware\EnsurePermission::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Cas spécial : conserve les détails structurés du Payment existant
        // pour que l'UI puisse afficher la modale de confirmation (R-PAY-08).
        // Doit être enregistré AVANT le handler générique BusinessException.
        $exceptions->render(function (DuplicateCashPaymentException $e, Request $request) {
            if ($request->is('api/*')) {
                $existing = $e->existing;
                return ApiResponse::error(
                    $e->getErrorCode(),
                    $e->getMessage(),
                    $e->getHttpStatus(),
                    ['details' => [
                        'existing_payment' => [
                            'id'           => $existing->id,
                            'reference'    => $existing->reference,
                            'amount_minor' => $existing->amount_minor,
                            'created_at'   => $existing->created_at?->toIso8601String(),
                            'notes'        => $existing->notes,
                        ],
                    ]],
                );
            }
        });

        $exceptions->render(function (BusinessException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponse::error($e->getErrorCode(), $e->getMessage(), $e->getHttpStatus());
            }
        });

        $exceptions->render(function (ValidationException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponse::error(
                    'validation_error',
                    'The given data was invalid.',
                    422,
                    ['errors' => $e->errors()],
                );
            }
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponse::error('unauthenticated', 'Authentication required.', 401);
            }
        });
    })->create();
