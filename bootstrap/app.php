<?php

use App\Modules\Identity\Domain\Exceptions\BusinessException;
use App\Modules\Payments\Domain\Exceptions\AtypicalAmountException;
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
            'staff_idle' => \App\Modules\Identity\Http\Middleware\ExpireIdleStaffToken::class,
        ]);

        // R-AUTH-01 : l'expiration d'inactivité doit lire `last_used_at` AVANT que le
        // guard Sanctum ne le mette à jour → priorité explicite avant l'authentification.
        $middleware->prependToPriorityList(
            before: \Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests::class,
            prepend: \App\Modules\Identity\Http\Middleware\ExpireIdleStaffToken::class,
        );
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

        // R-PAY-05 : montant atypique — l'UI affiche attendu / saisi et propose
        // de confirmer (`confirm_atypical=true`).
        $exceptions->render(function (AtypicalAmountException $e, Request $request) {
            if ($request->is('api/*')) {
                $expected = $e->expectedMinor;
                $lower    = intdiv($e->amountMinor, $expected) * $expected;

                return ApiResponse::error(
                    $e->getErrorCode(),
                    $e->getMessage(),
                    $e->getHttpStatus(),
                    ['details' => [
                        'expected_amount_minor' => $expected,
                        'amount_minor'          => $e->amountMinor,
                        'lower_multiple_minor'  => $lower > 0 ? $lower : null,
                        'upper_multiple_minor'  => $lower + $expected,
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
                    'Les données transmises sont invalides.',
                    422,
                    ['errors' => $e->errors()],
                );
            }
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponse::error('unauthenticated', 'Authentification requise. Veuillez vous connecter.', 401);
            }
        });
    })->create();
