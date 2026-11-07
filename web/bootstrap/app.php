<?php

use App\Http\Middleware\RequireStudioRole;
use App\Http\Middleware\ResolveStudio;
use App\Models\Studio;
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
        $middleware->alias([
            'studio' => ResolveStudio::class,
            'studio.role' => RequireStudioRole::class,
        ]);
        // Stripe signs webhooks instead.
        $middleware->validateCsrfTokens(except: ['stripe/webhook']);
        // Guests on a studio page sign in on that studio's branded login page.
        $middleware->redirectGuestsTo(function (Request $request) {
            $studio = $request->route('studio');
            $slug = $studio instanceof Studio ? $studio->slug : $studio;

            return is_string($slug) && $slug !== '' ? route('studio.login', ['studio' => $slug]) : route('login');
        });
        $middleware->redirectUsersTo(fn () => route('studios'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
