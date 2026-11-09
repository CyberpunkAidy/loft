<?php

namespace App\Services\Stripe;

use App\Models\Order;
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
        if (! empty($context['online'])) {
            $metadata['online'] = 1;
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

    /**
     * The studio's sales tax rate as a Stripe Tax Rate on its account, for shop checkouts. Tax Rates
     * can't change percentage, so a new studio rate makes a new one. Null when the rate is 0.
     */
    public function ensureTaxRate(Studio $studio): ?string
    {
        $milli = (int) $studio->setting('sales_tax_milli');
        if ($milli <= 0) {
            return null;
        }
        if ($studio->stripe_tax_rate_id && (int) $studio->stripe_tax_rate_milli === $milli) {
            return $studio->stripe_tax_rate_id;
        }
        $rate = $this->post('tax_rates', [
            'display_name' => 'Sales tax',
            'percentage' => rtrim(rtrim(number_format($milli / 1000, 3, '.', ''), '0'), '.'),
            'inclusive' => 'false',
        ], $this->account($studio), "loft-txr-{$studio->id}-{$milli}");
        $studio->update(['stripe_tax_rate_id' => $rate['id'], 'stripe_tax_rate_milli' => $milli]);

        return $rate['id'];
    }

    /**
     * Hosted Checkout for an online shop order (App\Services\Shop): one line per product, the studio's
     * Tax Rate on taxable ones, held for $minutes (the order's stock is reserved meanwhile).
     */
    public function createShopCheckout(Studio $studio, User $user, Order $order, string $successUrl, string $cancelUrl, int $minutes): array
    {
        $taxRate = $this->ensureTaxRate($studio);
        $metadata = [
            'loft_studio_id' => $studio->id,
            'loft_user_id' => $user->id,
            'loft_order_id' => $order->id,
            'loft_kind' => 'shop',
        ];
        $params = [
            'customer' => $this->ensureCustomer($studio, $user),
            'mode' => 'payment',
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'expires_at' => now()->addMinutes($minutes)->getTimestamp(),
            'metadata' => $metadata,
            'payment_intent_data' => ['metadata' => $metadata],
            'line_items' => $order->items->map(fn ($item) => [
                'quantity' => $item->quantity,
                'price_data' => [
                    'currency' => $order->currency,
                    'unit_amount' => $item->unit_price_cents,
                    'product_data' => ['name' => $item->description],
                ],
            ] + ($taxRate && $item->variant?->product?->taxable ? ['tax_rates' => [$taxRate]] : []))->values()->all(),
        ];
        if ($this->platformFeePercent > 0) {
            $params['payment_intent_data']['application_fee_amount'] = (int) round($order->total_cents * $this->platformFeePercent / 100);
        }

        return $this->post('checkout/sessions', $params, $this->account($studio), 'loft-shop-'.$order->id);
    }

    /* ------------------------------------------------------------------ Terminal (card readers) */

    /** Test-mode keys can register simulated readers and present test cards to them. */
    public function testMode(): bool
    {
        return str_starts_with((string) $this->secret, 'sk_test_') || str_starts_with((string) $this->secret, 'rk_test_');
    }

    /**
     * The studio's Terminal Location (where its readers are), made once from its address.
     *
     * @param  array{line1: string, line2?: ?string, city: string, state?: ?string, postal_code: string, country: string}  $address
     */
    public function createTerminalLocation(Studio $studio, array $address): string
    {
        $location = $this->post('terminal/locations', ['display_name' => $studio->name, 'address' => $address], $this->account($studio));
        $studio->update(['stripe_terminal_location_id' => $location['id']]);

        return $location['id'];
    }

    /** Pair a reader using the code it shows on screen. */
    public function registerReader(Studio $studio, string $code, string $label): array
    {
        return $this->post('terminal/readers', [
            'registration_code' => $code,
            'label' => $label,
            'location' => $studio->stripe_terminal_location_id,
        ], $this->account($studio));
    }

    /** @return list<array<string, mixed>> the studio's readers as Stripe sees them (status: online or offline) */
    public function listReaders(Studio $studio): array
    {
        return $this->get('terminal/readers', ['limit' => 100], $this->account($studio))['data'] ?? [];
    }

    public function retrieveReader(Studio $studio, string $id): array
    {
        return $this->get('terminal/readers/'.$id, [], $this->account($studio));
    }

    public function deleteReader(Studio $studio, string $id): void
    {
        $this->request('DELETE', 'terminal/readers/'.$id, [], $this->account($studio));
    }

    /**
     * A card-present payment for a front-desk sale, captured as soon as it's authorized. The platform
     * fee applies as it does online.
     */
    public function createReaderPaymentIntent(Studio $studio, Order $order): array
    {
        $metadata = ['loft_studio_id' => $studio->id, 'loft_order_id' => $order->id, 'loft_kind' => 'reader'];
        $params = [
            'amount' => $order->total_cents,
            'currency' => $order->currency,
            'payment_method_types' => ['card_present'],
            'capture_method' => 'automatic',
            'description' => 'Front desk sale',
            'metadata' => $metadata,
        ];
        if ($this->platformFeePercent > 0) {
            $params['application_fee_amount'] = (int) round($order->total_cents * $this->platformFeePercent / 100);
        }

        return $this->post('payment_intents', $params, $this->account($studio), 'loft-reader-'.$order->id);
    }

    /** Show the payment on the reader and wait for a card. */
    public function processOnReader(Studio $studio, string $readerId, string $paymentIntentId): array
    {
        return $this->post("terminal/readers/{$readerId}/process_payment_intent", ['payment_intent' => $paymentIntentId], $this->account($studio));
    }

    /** Stop whatever the reader is doing (back to its idle screen). */
    public function cancelReaderAction(Studio $studio, string $readerId): array
    {
        return $this->post("terminal/readers/{$readerId}/cancel_action", [], $this->account($studio));
    }

    /** Test mode only: tap a test card on a simulated reader. */
    public function presentTestCard(Studio $studio, string $readerId, ?string $number = null): array
    {
        return $this->post("test_helpers/terminal/readers/{$readerId}/present_payment_method", $number ? ['card_present' => ['number' => $number]] : [], $this->account($studio));
    }

    public function retrievePaymentIntent(Studio $studio, string $id): array
    {
        return $this->get('payment_intents/'.$id, [], $this->account($studio));
    }

    public function capturePaymentIntent(Studio $studio, string $id): array
    {
        return $this->post("payment_intents/{$id}/capture", [], $this->account($studio), "loft-capture-{$id}");
    }

    public function cancelPaymentIntent(Studio $studio, string $id): array
    {
        return $this->post("payment_intents/{$id}/cancel", [], $this->account($studio));
    }

    /** Refund a payment in full, once. */
    public function refundPaymentIntent(Studio $studio, string $id): array
    {
        return $this->post('refunds', ['payment_intent' => $id], $this->account($studio), "loft-refund-{$id}");
    }

    /** Close a Checkout Session nobody will finish (the member came back to the cart). */
    public function expireCheckoutSession(Studio $studio, string $id): array
    {
        return $this->post("checkout/sessions/{$id}/expire", [], $this->account($studio));
    }

    /** @param  list<string>  $expand  e.g. ['payment_intent'] */
    public function retrieveCheckoutSession(Studio $studio, string $id, array $expand = []): array
    {
        return $this->get('checkout/sessions/'.$id, $expand ? ['expand' => $expand] : [], $this->account($studio));
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
