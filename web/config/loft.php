<?php

return [

    /*
    | Stripe Connect. The platform account's secret key; each studio connects its own Stripe
    | account from Admin → Payments. The webhook secret belongs to a *Connect* webhook endpoint
    | (Developers → Webhooks → "Events on connected accounts") pointing at /stripe/webhook.
    | The client pins the API version below for every call. Webhooks may use any API version: the
    | app reads only the object's id from them and fetches the object itself (StripeWebhooks).
    */
    'stripe' => [
        'secret' => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        'api_version' => env('STRIPE_API_VERSION', '2024-06-20'),
        'platform_fee_percent' => (float) env('LOFT_PLATFORM_FEE_PERCENT', 0),
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
