<?php

use App\Http\Middleware\AuthenticateViaAuthService;
use App\Http\Middleware\CheckUserRoles;
use App\Http\Middleware\ResolveUserRoles;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            Route::middleware('web')
                ->group(__DIR__.'/../routes/admin.php');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Alias untuk role-based middleware
        $middleware->alias([
            'roles' => CheckUserRoles::class,
            'auth.authservice' => AuthenticateViaAuthService::class,
        ]);

        // Global middleware
        $middleware->appendToGroup('web', [
            ResolveUserRoles::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
