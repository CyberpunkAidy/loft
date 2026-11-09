<?php

namespace App\Providers;

use App\Listeners\StudioEmails;
use App\Models\Studio;
use App\Services\Messages;
use App\Services\Stripe\StripeClient;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(StripeClient::class, fn () => new StripeClient(
            config('loft.stripe.secret'),
            (string) config('loft.stripe.api_version'),
            (float) config('loft.stripe.platform_fee_percent'),
            (string) config('loft.stripe.v2_api_version'),
        ));
    }

    public function boot(): void
    {
        Event::subscribe(StudioEmails::class);
        Event::listen(MessageSent::class, fn (MessageSent $event) => app(Messages::class)->markSent($event));

        // Explicit binding runs before implicit ones, so {program}, {booking}, … under {studio}
        // always resolve through the studio's relationships (scoped bindings), whatever the controller signature.
        Route::bind('studio', fn (string $slug) => Studio::where('slug', $slug)->firstOrFail());
        Paginator::defaultView('partials.pagination');

        // The mobile API: 120 requests a minute per member (per address before signing in); sign-in
        // and registration, 10 a minute per address.
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)->by('api:'.($request->user('sanctum')?->id ?? $request->ip())));
        RateLimiter::for('auth', fn (Request $request) => Limit::perMinute(10)->by('auth:'.$request->ip()));

        // Password resets and staff invitations: a Loft-branded email instead of Laravel's default.
        ResetPassword::toMailUsing(fn (object $user, string $token) => (new MailMessage)
            ->subject('Choose your '.config('app.name').' password')
            ->view(['mail.password', 'mail.password-text'], [
                'url' => route('password.reset', ['token' => $token, 'email' => $user->getEmailForPasswordReset()]),
                'minutes' => (int) config('auth.passwords.'.config('auth.defaults.passwords').'.expire'),
            ]));
    }
}
