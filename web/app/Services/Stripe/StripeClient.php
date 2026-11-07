<?php

namespace App\Services\Stripe;

use App\Models\Plan;
use App\Models\Studio;
use App\Models\User;
use App\Services\Entitlements;
use Illuminate\Support\Facades\Http;

/**
 * A thin Stripe REST client (no SDK), like the plugin's Stripe\Client, rebuilt for Stripe Connect:
 * the platform holds one secret key, each studio connects its own Stripe account, and every
 * studio-level call carries that account in the Stripe-Account header. Money goes to the studio;
 * the platform can take an application fee (LOFT_PLATFORM_FEE_PERCENT).
 */
class StripeClient
{
    public const API = 'https://api.stripe.com/v1/';

    public function __construct(
        private readonly ?string $secret,
        private readonly string $apiVersion,
        private readonly float $platformFeePercent = 0,
    ) {}

    public function configured(): bool
    {
        return (string) $this->secret !== '';
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     *
     * @throws StripeException
     */
    public function request(string $method, string $path, array $params = [], ?string $account = null, ?string $idempotencyKey = null): array
    {
        if (! $this->configured()) {
            throw new StripeException('Stripe is not configured. Set STRIPE_SECRET.');
        }
        $headers = ['Stripe-Version' => $this->apiVersion];
        if ($account) {
            $headers['Stripe-Account'] = $account;
        }
        if ($idempotencyKey) {
            $headers['Idempotency-Key'] = $idempotencyKey;
        }
        $http = Http::withToken((string) $this->secret)->withHeaders($headers)->timeout(30)->asForm();
        $url = self::API.ltrim($path, '/');
        $params = self::normalize($params);

        $response = strtoupper($method) === 'GET' ? $http->get($url, $params) : $http->post($url, $params);
        $body = $response->json() ?? [];
        if ($response->failed()) {
            throw new StripeException(
                $body['error']['message'] ?? 'Stripe error '.$response->status(),
                $body['error']['code'] ?? null,
                $response->status(),
            );
        }

        return is_array($body) ? $body : [];
    }

    public function get(string $path, array $params = [], ?string $account = null): array
    {
        return $this->request('GET', $path, $params, $account);
    }

    public function post(string $path, array $params = [], ?string $account = null, ?string $idempotencyKey = null): array
    {
        return $this->request('POST', $path, $params, $account, $idempotencyKey);
    }

    /* ------------------------------------------------------------------ Connect onboarding */

    /** Create the studio's connected account (Standard: the studio owns its Stripe dashboard). */
    public function createAccount(Studio $studio, string $email): array
    {
        return $this->post('accounts', [
            'type' => 'standard',
            'email' => $email,
            'business_profile' => ['name' => $studio->name],
            'metadata' => ['loft_studio_id' => $studio->id],
        ]);
    }

    /** A one-time URL that takes the studio through Stripe's onboarding. */
    public function accountLink(string $accountId, string $refreshUrl, string $returnUrl): string
    {
        return $this->post('account_links', [
            'account' => $accountId,
            'refresh_url' => $refreshUrl,
            'return_url' => $returnUrl,
            'type' => 'account_onboarding',
        ])['url'];
    }

    public function retrieveAccount(string $accountId): array
    {
        return $this->get('accounts/'.$accountId);
    }

    /** Copy the account's readiness onto the studio. */
    public function refreshAccountStatus(Studio $studio): void
    {
        if (! $studio->stripe_account_id) {
            return;
        }
        $account = $this->retrieveAccount($studio->stripe_account_id);
        $studio->update([
            'stripe_charges_enabled' => (bool) ($account['charges_enabled'] ?? false),
            'stripe_details_submitted' => (bool) ($account['details_submitted'] ?? false),
        ]);
    }

    /* ------------------------------------------------------------------ Customers */

    /** The member's Stripe customer on the studio's account, created on first purchase. */
    public function ensureCustomer(Studio $studio, User $user): string
    {
        $membership = $user->membershipIn($studio);
        if ($membership?->stripe_customer_id) {
            return $membership->stripe_customer_id;
        }
        $customer = $this->post('customers', [
            'email' => $user->email,
            'name' => $user->name,
            'phone' => $user->phone,
            'metadata' => ['loft_user_id' => $user->id, 'loft_studio_id' => $studio->id],
        ], $this->account($studio), 'loft-cust-'.$studio->id.'-'.$user->id);

        $studio->studioUsers()->updateOrCreate(['user_id' => $user->id], ['stripe_customer_id' => $customer['id']]);
        $user->forgetMemberships();

        return $customer['id'];
    }

    /* ------------------------------------------------------------------ Plans */

    /**
     * Create the Stripe product and price(s) a plan needs. Returns the plan fields to save.
     *
     * @return array<string, string>
     */
    public function syncPlan(Studio $studio, Plan $plan): array
    {
        $account = $this->account($studio);
        $out = [];
        $productId = $plan->stripe_product_id;
        if (! $productId) {
            $productId = $out['stripe_product_id'] = $this->post('products', [
                'name' => $plan->name,
                'metadata' => ['loft_plan_id' => $plan->id],
            ], $account)['id'];
        }
        if (! $plan->stripe_price_id) {
            $params = [
                'product' => $productId,
                'unit_amount' => $plan->price_cents,
                'currency' => $plan->currency,
                'metadata' => ['loft_plan_id' => $plan->id],
            ];
            if ($plan->isRecurring()) {
                $params['recurring'] = ['interval' => 'month'];
            }
            $out['stripe_price_id'] = $this->post('prices', $params, $account)['id'];
        }
        if ($plan->kind === 'season' && $plan->registration_fee_cents > 0 && ! $plan->stripe_fee_price_id) {
            $out['stripe_fee_price_id'] = $this->post('prices', [
                'product' => $productId,
                'unit_amount' => $plan->registration_fee_cents,
                'currency' => $plan->currency,
                'nickname' => 'Registration fee',
                'metadata' => ['loft_plan_id' => $plan->id, 'fee' => 1],
            ], $account)['id'];
        }

        return $out;
    }

    /* ------------------------------------------------------------------ Checkout */

    /**
     * Start a hosted Checkout for a plan. $successUrl may contain {CHECKOUT_SESSION_ID}.
     *
     * @param  array{session_id?: int, attendee_id?: int}  $context  a booking to complete after payment
     */
    public function createCheckout(Studio $studio, User $user, Plan $plan, string $successUrl, string $cancelUrl, array $context = []): array
    {
        if (! $plan->isStripeReady()) {
            throw new StripeException('This plan is not connected to Stripe yet.');
        }
        $metadata = [
            'loft_studio_id' => $studio->id,
            'loft_plan_id' => $plan->id,
            'loft_user_id' => $user->id,
            'session_id' => (int) ($context['session_id'] ?? 0),
            'attendee_id' => (int) ($context['attendee_id'] ?? 0),
        ];
        $params = [
            'customer' => $this->ensureCustomer($studio, $user),
            'mode' => $plan->isRecurring() ? 'subscription' : 'payment',
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'line_items' => [['price' => $plan->stripe_price_id, 'quantity' => 1]],
            'metadata' => $metadata,
            'allow_promotion_codes' => true,
        ];

        if ($plan->isRecurring()) {
            $params['subscription_data'] = ['metadata' => $metadata];
            if ($this->platformFeePercent > 0) {
                $params['subscription_data']['application_fee_percent'] = $this->platformFeePercent;
            }
            if ($plan->kind === 'season' && $plan->stripe_fee_price_id) {
                $params['line_items'][] = ['price' => $plan->stripe_fee_price_id, 'quantity' => 1];
            }
            // A season plan ends with its season. Checkout can't set cancel_at, so the
            // webhook sets it on the subscription once it exists (see setSeasonEnd).
        } else {
            $params['payment_intent_data'] = ['metadata' => $metadata];
            if ($this->platformFeePercent > 0) {
                $params['payment_intent_data']['application_fee_amount'] = (int) round($plan->price_cents * $this->platformFeePercent / 100);
            }
            $params['invoice_creation'] = ['enabled' => true];
        }

        return $this->post('checkout/sessions', $params, $this->account($studio));
    }

    public function retrieveCheckoutSession(Studio $studio, string $id): array
    {
        return $this->get('checkout/sessions/'.$id, [], $this->account($studio));
    }

    public function retrieveSubscription(Studio $studio, string $id): array
    {
        return $this->get('subscriptions/'.$id, [], $this->account($studio));
    }

    /** Season tuition: stop billing when the season ends. */
    public function setSeasonEnd(Studio $studio, Plan $plan, string $subscriptionId): void
    {
        $end = app(Entitlements::class)->seasonEnd($studio, $plan);
        if ($end && $end->isFuture()) {
            $this->post('subscriptions/'.$subscriptionId, ['cancel_at' => $end->getTimestamp()], $this->account($studio));
        }
    }

    /* ------------------------------------------------------------------ Customer portal */

    public function createPortal(Studio $studio, User $user, string $returnUrl): array
    {
        return $this->post('billing_portal/sessions', [
            'customer' => $this->ensureCustomer($studio, $user),
            'return_url' => $returnUrl,
            'configuration' => $this->ensurePortalConfiguration($studio),
        ], $this->account($studio));
    }

    /** Connected accounts start without a portal configuration, so create one: cards, invoices, cancel at period end. */
    public function ensurePortalConfiguration(Studio $studio): string
    {
        if ($studio->stripe_portal_configuration_id) {
            return $studio->stripe_portal_configuration_id;
        }
        $config = $this->post('billing_portal/configurations', [
            'business_profile' => ['headline' => 'Manage your '.$studio->name.' billing'],
            'features' => [
                'invoice_history' => ['enabled' => true],
                'payment_method_update' => ['enabled' => true],
                'subscription_cancel' => ['enabled' => true, 'mode' => 'at_period_end'],
            ],
        ], $this->account($studio));
        $studio->update(['stripe_portal_configuration_id' => $config['id']]);

        return $config['id'];
    }

    /* ------------------------------------------------------------------ Webhooks */

    /** Check a Stripe-Signature header (t=…,v1=…) against the endpoint's signing secret. */
    public static function verifySignature(string $payload, string $header, string $secret, int $tolerance = 300): bool
    {
        $timestamp = null;
        $signatures = [];
        foreach (explode(',', $header) as $part) {
            $kv = explode('=', trim($part), 2);
            if (count($kv) !== 2) {
                continue;
            }
            if ($kv[0] === 't') {
                $timestamp = (int) $kv[1];
            } elseif ($kv[0] === 'v1') {
                $signatures[] = $kv[1];
            }
        }
        if (! $timestamp || ! $signatures || abs(time() - $timestamp) > $tolerance) {
            return false;
        }
        $expected = hash_hmac('sha256', $timestamp.'.'.$payload, $secret);
        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }

    /* ------------------------------------------------------------------ Helpers */

    private function account(Studio $studio): string
    {
        if (! $studio->stripe_account_id) {
            throw new StripeException('This studio has not connected Stripe yet.');
        }

        return $studio->stripe_account_id;
    }

    /** Stripe wants "true"/"false" strings and no nulls. */
    private static function normalize(array $params): array
    {
        $out = [];
        foreach ($params as $key => $value) {
            if ($value === null) {
                continue;
            }
            $out[$key] = match (true) {
                is_array($value) => self::normalize($value),
                is_bool($value) => $value ? 'true' : 'false',
                default => $value,
            };
        }

        return $out;
    }
}
