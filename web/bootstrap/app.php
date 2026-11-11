<?php

use App\Http\ApiErrors;
use App\Http\Middleware\AuthenticateDevice;
use App\Http\Middleware\EnsureStudioActive;
use App\Http\Middleware\Idempotency;
use App\Http\Middleware\NotInDemo;
use App\Http\Middleware\RememberSignupSource;
use App\Http\Middleware\RequireStudioRole;
use App\Http\Middleware\ResolveStudio;
use App\Http\Middleware\SecurityHeaders;
use App\Models\Studio;
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
        $middleware->alias([
            'studio' => ResolveStudio::class,
            'studio.role' => RequireStudioRole::class,
            'studio.active' => EnsureStudioActive::class,
            'idempotent' => Idempotency::class,
            'device' => AuthenticateDevice::class,
            'demo.off' => NotInDemo::class,
        ]);
        // First in, last out: every response gets them, the maintenance page and errors included.
        $middleware->prepend(SecurityHeaders::class);
        // Where a guest's visit began, saved with the studio if they start one (decision 43).
        $middleware->web(append: RememberSignupSource::class);
        // Stripe signs webhooks instead; unsubscribe links are signed URLs, and mail apps POST to them
        // for one-click unsubscribe (RFC 8058) without a session.
        $middleware->validateCsrfTokens(except: ['stripe/webhook', 'stripe/billing-webhook', '*/unsubscribe/*']);
        // Behind a load balancer that terminates HTTPS (Render, Fly, Cloudflare…), trust its
        // X-Forwarded-* headers so URLs are https and client IPs are real. Off unless set:
        // TRUSTED_PROXIES=* or a comma-separated list of proxy IPs/CIDRs.
        if ($proxies = env('TRUSTED_PROXIES')) {
            $middleware->trustProxies(at: $proxies === '*' ? '*' : array_map('trim', explode(',', $proxies)));
        }
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
        // The mobile API's errors: one shape, with stable codes (App\Http\ApiErrors).
        $exceptions->render(fn (Throwable $e, Request $request) => $request->is('api/*') ? ApiErrors::render($e) : null);
    })->create();
