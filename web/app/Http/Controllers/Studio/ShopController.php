<?php

namespace App\Http\Controllers\Studio;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\Studio;
use App\Services\PointOfSale;
use App\Services\Shop;
use App\Services\Stripe\StripeClient;
use App\Services\Stripe\StripeException;
use App\Support\CheckoutException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** The online shop: browse, a cart in the session, Stripe Checkout, pick up at the studio. */
class ShopController extends Controller
{
    public function index(Request $request, Studio $studio): View
    {
        return view('studio.shop.index', [
            'products' => $studio->products()->where('active', true)
                ->with(['variants' => fn ($v) => $v->where('active', true), 'studio'])
                ->whereHas('variants', fn ($v) => $v->where('active', true))->orderBy('name')->get(),
            'count' => array_sum($this->items($request, $studio)),
        ]);
    }

    public function add(Request $request, Studio $studio): RedirectResponse
    {
        $data = $request->validate([
            'variant_id' => ['required', 'integer', Rule::exists('product_variants', 'id')->where('studio_id', $studio->id)->where('active', true)
                ->whereIn('product_id', $studio->products()->where('active', true)->select('id'))],
            'qty' => ['nullable', 'integer', 'between:1,99'],
        ]);
        $cart = $this->items($request, $studio);
        $cart[$data['variant_id']] = min(99, ($cart[$data['variant_id']] ?? 0) + (int) ($data['qty'] ?? 1));
        $request->session()->put($this->key($studio), $cart);

        return back()->with('success', 'Added to your cart.');
    }

    public function cart(Request $request, Studio $studio, Shop $shop, PointOfSale $pos): View
    {
        if ($request->filled('cancelled')) {
            // Back from Checkout without paying: let the stock it held go.
            $pending = Order::where('studio_id', $studio->id)->where('user_id', $request->user()?->id)->where('status', 'pending')->find($request->integer('cancelled'));
            if ($pending) {
                $shop->cancel($pending);
            }
        }
        $lines = $shop->lines($studio, $this->items($request, $studio));
        if (! $request->user()) {
            redirect()->setIntendedUrl(route('studio.shop.cart')); // signing in comes back to the cart
        }

        return view('studio.shop.cart', ['lines' => $lines, 'totals' => $pos->totals($lines)]);
    }

    public function update(Request $request, Studio $studio, int $variant): RedirectResponse
    {
        $qty = (int) $request->validate(['qty' => ['required', 'integer', 'between:0,99']])['qty'];
        abort_unless($qty === 0 || ProductVariant::where('studio_id', $studio->id)->whereKey($variant)->exists(), 404);
        $cart = $this->items($request, $studio);
        if ($qty === 0) {
            unset($cart[$variant]);
        } else {
            $cart[$variant] = $qty;
        }
        $request->session()->put($this->key($studio), $cart);

        return redirect()->route('studio.shop.cart');
    }

    public function checkout(Request $request, Studio $studio, Shop $shop): RedirectResponse
    {
        try {
            $url = $shop->checkout($studio, $request->user(), $this->items($request, $studio),
                route('studio.shop.complete').'?session_id={CHECKOUT_SESSION_ID}',
                route('studio.shop.cart').'?cancelled=__ORDER__');
        } catch (CheckoutException $e) {
            return back()->with('error', $e->getMessage());
        } catch (StripeException $e) {
            report($e);

            return back()->with('error', 'We couldn’t start checkout: '.$e->getMessage());
        }

        return redirect()->away($url);
    }

    /** Stripe sends the member back here; settle it now rather than wait for the webhook. */
    public function complete(Request $request, Studio $studio, StripeClient $stripe, Shop $shop): RedirectResponse
    {
        $id = (string) $request->query('session_id', '');
        if (! str_starts_with($id, 'cs_')) {
            return redirect()->route('studio.shop');
        }
        try {
            $cs = $stripe->retrieveCheckoutSession($studio, $id);
            abort_unless((int) ($cs['metadata']['loft_user_id'] ?? 0) === (int) $request->user()->id, 404);
            $order = $shop->fulfil($studio, $cs);
        } catch (StripeException $e) {
            report($e);
            $order = null;
        }
        $request->session()->forget($this->key($studio));

        return redirect()->route('studio.account.billing')->with('success', match (true) {
            $order?->status === 'paid' => "Thanks! Order #{$order->number} is ready to pick up at the front desk.",
            $order?->status === 'pending' => 'Thanks! Your payment is processing. Bank payments can take a few business days; we’ll email you when it goes through.',
            default => 'Thanks! We’re confirming your payment; your order will appear here shortly.',
        });
    }

    private function key(Studio $studio): string
    {
        return "shop.cart.{$studio->id}";
    }

    /** @return array<int, int> variant id => quantity */
    private function items(Request $request, Studio $studio): array
    {
        return array_map('intval', (array) $request->session()->get($this->key($studio), []));
    }
}
