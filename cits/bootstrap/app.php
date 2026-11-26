<?php

use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\EnsureProjectSelected;
use App\Http\Middleware\EnsureUserActive;
use App\Http\Middleware\ForceHttps;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Shared host behind LiteSpeed / reverse proxy.
        $middleware->trustProxies(at: '*');

        $middleware->alias([
            'https' => ForceHttps::class,
            'active' => EnsureUserActive::class,
            'project' => EnsureProjectSelected::class,
            'admin' => EnsureAdmin::class,
        ]);

        // Every web request is covered by the HTTPS guard (no-op outside production).
        $middleware->prependToGroup('web', ForceHttps::class);
        // Closures so the URL keeps the sub-path (/mescits) instead of the domain root.
        $middleware->redirectUsersTo(fn () => route('home'));
        $middleware->redirectGuestsTo(fn () => route('home'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
