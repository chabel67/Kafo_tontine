<?php

use App\Modules\Identity\Domain\Exceptions\BusinessException;
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
            'step_up' => \App\Modules\Identity\Http\Middleware\RequireStepUpToken::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
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
