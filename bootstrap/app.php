<?php

use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\EnsureRealtyMember;
use App\Http\Middleware\TrustFrontendClientIp;
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
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // This app is an API: a guest gets a 401, never a redirect to a login page it doesn't have.
        $middleware->redirectGuestsTo(fn () => null);
        $middleware->prepend(TrustFrontendClientIp::class);
        $middleware->alias([
            'admin' => EnsureAdmin::class,
            'realty.member' => EnsureRealtyMember::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
