<?php

namespace App\Services\Stripe;

use App\Models\Plan;
use App\Models\Studio;
use App\Models\User;
use App\Services\Entitlements;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * A thin Stripe REST client (no SDK), like the plugin's Stripe\Client, rebuilt for Stripe Connect:
 * the platform holds one secret key, each studio connects its own Stripe account, and every
 * studio-level call carries that account in the Stripe-Account header. Money goes to the studio;
 * the platform can take an application fee (LOFT_PLATFORM_FEE_PERCENT).
 *
 * Studio accounts are created and onboarded with Accounts v2 (/v2/core), which Stripe requires for
 * new Connect platforms. v2 accounts work with every v1 endpoint used here, so everything else,
 * including reading an account's status, stays on v1.
 */
class StripeClient
{
    public const API = 'https://api.stripe.com/v1/';

    public const API_V2 = 'https://api.stripe.com/v2/';

    public function __construct(
        private readonly ?string $secret,
        private readonly string $apiVersion,
        private readonly float $platformFeePercent = 0,
        private readonly string $v2ApiVersion = '2026-10-28.endive',
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
        $http = $this->http($this->apiVersion, $account, $idempotencyKey)->asForm();
        $url = self::API.ltrim($path, '/');
        $params = self::normalize($params);

        return $this->decode(match (strtoupper($method)) {
            'GET' => $http->get($url, $params),
            'DELETE' => $http->delete($url, $params),
            default => $http->post($url, $params),
        });
    }

    /**
     * POST to an API v2 endpoint (/v2/core/…): a JSON body, in the v2 API version.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     *
     * @throws StripeException
     */
    public function postV2(string $path, array $body): array
    {
        return $this->decode($this->http($this->v2ApiVersion)->asJson()->post(self::API_V2.ltrim($path, '/'), $body));
    }

    private function http(string $version, ?string $account = null, ?string $idempotencyKey = null): PendingRequest
    {
        if (! $this->configured()) {
            throw new StripeException('Stripe is not configured. Set STRIPE_SECRET.');
        }
        $headers = ['Stripe-Version' => $version];
        if ($account) {
            $headers['Stripe-Account'] = $account;
        }
        if ($idempotencyKey) {
            $headers['Idempotency-Key'] = $idempotencyKey;
        }

        return Http::withToken((string) $this->secret)->withHeaders($headers)->timeout(30);
    }

    /** @return array<string, mixed> */
    private function decode(Response $response): array
    {
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

    /**
     * Create the studio's connected account. Set up like the v1 Standard accounts it replaces: the
     * studio gets the full Stripe Dashboard, pays Stripe's fees itself and Stripe covers losses.
     * The account is in the platform's country, as v1 accounts were by default.
     */
    public function createAccount(Studio $studio, string $email): array
    {
        $body = [
            'contact_email' => $email,
            'display_name' => $studio->name,
            'dashboard' => 'full',
            'configuration' => ['merchant' => ['capabilities' => ['card_payments' => ['requested' => true]]]],
            'defaults' => ['responsibilities' => ['fees_collector' => 'stripe', 'losses_collector' => 'stripe']],
            'metadata' => ['loft_studio_id' => (string) $studio->id],
        ];
        if ($country = strtolower((string) ($this->get('account')['country'] ?? ''))) {
            $body['identity'] = ['country' => $country];
        }

        return $this->postV2('core/accounts', $body);
    }

    /** A one-time URL that takes the studio through Stripe's onboarding. */
    public function accountLink(string $accountId, string $refreshUrl, string $returnUrl): string
    {
        return $this->postV2('core/account_links', [
            'account' => $accountId,
            'use_case' => [
                'type' => 'account_onboarding',
                'account_onboarding' => ['refresh_url' => $refreshUrl, 'return_url' => $returnUrl],
            ],
        ])['url'];
    }

    /** v1 reads v2 accounts too, in the v1 shape (charges_enabled, details_submitted). */
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
     * @param  array{session_id?: int, attendee_id?: int, apparatus_id?: int}  $context  a booking to complete after payment
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
        if (! empty($context['apparatus_id'])) {
            $metadata['apparatus_id'] = (int) $context['apparatus_id'];
        }
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

    public function retrieveInvoice(Studio $studio, string $id): array
    {
        return $this->get('invoices/'.$id, [], $this->account($studio));
    }

    /** Cancel a subscription now, without proration. One that's gone or already cancelled counts as cancelled. */
    public function cancelSubscription(Studio $studio, string $id): void
    {
        try {
            $this->request('DELETE', 'subscriptions/'.$id, [], $this->account($studio));
        } catch (StripeException $e) {
            if ($e->httpStatus !== 404 && ($this->retrieveSubscription($studio, $id)['status'] ?? null) !== 'canceled') {
                throw $e;
            }
        }
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
