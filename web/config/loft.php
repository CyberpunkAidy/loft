<?php

use App\Services\AutomationTriggers;

return [

    /*
    | Stripe Connect. The platform account's secret key; each studio connects its own Stripe
    | account from Admin → Payments. The webhook secret belongs to a *Connect* webhook endpoint
    | (Developers → Webhooks → "Events on connected accounts") pointing at /stripe/webhook.
    | The client pins the API versions below: api_version for v1 calls, v2_api_version for the
    | Accounts v2 calls that create and onboard studio accounts. Webhooks may use any API version:
    | the app reads only the object's id from them and fetches the object itself (StripeWebhooks).
    */
    'stripe' => [
        'secret' => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        'api_version' => env('STRIPE_API_VERSION', '2024-06-20'),
        'v2_api_version' => env('STRIPE_V2_API_VERSION', '2026-10-28.endive'),
        'platform_fee_percent' => (float) env('LOFT_PLATFORM_FEE_PERCENT', 0),
    ],

    /*
    | Mux, for on-demand video (decision 33). Loft's own Mux account hosts every studio's videos.
    | token_id/token_secret: an access token with Mux Video read and write. signing_key_id and
    | signing_key: a URL signing key (Settings → Signing keys); the key is the base64 private key
    | Mux shows once. Playback is always signed, so videos only play for people Loft lets in.
    | studio_minutes caps each studio's stored video (Loft pays for storage and streaming).
    */
    'mux' => [
        'token_id' => env('MUX_TOKEN_ID'),
        'token_secret' => env('MUX_TOKEN_SECRET'),
        'signing_key_id' => env('MUX_SIGNING_KEY_ID'),
        'signing_key' => env('MUX_SIGNING_KEY'),
        'studio_minutes' => (int) env('LOFT_VIDEO_MINUTES_PER_STUDIO', 600),
        'token_hours' => (int) env('LOFT_VIDEO_TOKEN_HOURS', 6),
    ],

    /*
    | Consumers of the domain event outbox (App\Contracts\DomainEventHandler). Each new event is
    | relayed to every handler here; with none, events are simply kept as history.
    */
    'event_handlers' => [
        AutomationTriggers::class, // first-class follow-ups
    ],

    /*
    | Studio URLs are /{slug}. These can't be slugs because the platform uses them.
    */
    'reserved_slugs' => [
        'admin', 'api', 'app', 'account', 'billing', 'blog', 'css', 'dashboard', 'docs', 'favicon', 'forgot-password',
        'help', 'home', 'images', 'img', 'js', 'join', 'login', 'logout', 'mail', 'platform', 'pricing', 'privacy',
        'register', 'reset-password', 'robots', 'signup', 'static', 'storage', 'stripe', 'studios', 'support',
        'terms', 'up', 'www',
    ],

];
