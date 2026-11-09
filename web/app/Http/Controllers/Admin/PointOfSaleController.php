<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Studio;
use App\Services\Orders;
use App\Services\PointOfSale;
use App\Services\ReaderPayments;
use App\Services\Stripe\StripeClient;
use App\Services\Stripe\StripeException;
use App\Support\CheckoutException;
use App\Support\Search;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Admin → Point of sale: the front-desk till. The cart lives in the staff member's session, one per
 * studio; every button is a plain form, so it works without JavaScript. Card reader sales wait on a
 * page that refreshes itself until the customer has paid (App\Services\ReaderPayments).
 */
class PointOfSaleController extends Controller
{
    public function show(Request $request, Studio $studio, PointOfSale $pos): View
    {
        $cart = $this->cart($request, $studio);
        $lines = $pos->lines($studio, $cart);
        $q = trim((string) $request->query('q', ''));

        return view('admin.pos.show', [
            'products' => $studio->products()->where('active', true)->with(['variants' => fn ($v) => $v->where('active', true), 'studio'])->orderBy('name')->get(),
            'plans' => $studio->plans()->where('active', true)->orderBy('sort')->orderBy('name')->get(),
            'lines' => $lines,
            'totals' => $pos->totals($lines),
            'member' => ! empty($cart['member_id']) ? $studio->members()->find($cart['member_id']) : null,
            'walkIn' => (bool) ($cart['walk_in'] ?? false),
            'q' => $q,
            'matches' => $q === '' ? collect() : $studio->members()->tap(fn ($query) => Search::apply($query, ['first_name', 'last_name', 'email'], $q))
                ->orderBy('first_name')->limit(8)->get(),
            'methods' => Payment::METHODS,
            'readers' => $studio->stripeReady() ? $studio->cardReaders()->get() : collect(),
            'waiting' => $studio->orders()->where('status', 'pending')->where('method', 'reader')->with(['cardReader', 'user', 'items'])->oldest()->get(),
        ]);
    }

    public function add(Request $request, Studio $studio): RedirectResponse
    {
        $data = $request->validate([
            'type' => ['required', 'in:variant,plan'],
            'id' => ['required', 'integer', $request->input('type') === 'plan'
                ? Rule::exists('plans', 'id')->where('studio_id', $studio->id)->where('active', true)
                : Rule::exists('product_variants', 'id')->where('studio_id', $studio->id)->where('active', true)],
        ]);
        $cart = $this->cart($request, $studio);
        $found = false;
        foreach ($cart['lines'] as &$line) {
            if ($line['type'] === $data['type'] && $line['id'] === (int) $data['id']) {
                $line['qty'] = $data['type'] === 'plan' ? 1 : $line['qty'] + 1;
                $found = true;
            }
        }
        unset($line);
        if (! $found) {
            $cart['lines'][] = ['type' => $data['type'], 'id' => (int) $data['id'], 'qty' => 1];
        }

        return $this->save($request, $studio, $cart);
    }

    public function quantity(Request $request, Studio $studio, int $line): RedirectResponse
    {
        $qty = (int) $request->validate(['qty' => ['required', 'integer', 'between:0,999']])['qty'];
        $cart = $this->cart($request, $studio);
        if (isset($cart['lines'][$line])) {
            if ($qty === 0) {
                array_splice($cart['lines'], $line, 1);
            } else {
                $cart['lines'][$line]['qty'] = $qty;
            }
        }

        return $this->save($request, $studio, $cart);
    }

    public function customer(Request $request, Studio $studio): RedirectResponse
    {
        $data = $request->validate(['member_id' => ['nullable', 'integer'], 'walk_in' => ['nullable', 'boolean']]);
        $cart = $this->cart($request, $studio);
        $member = ! empty($data['member_id']) ? $studio->members()->findOrFail($data['member_id']) : null;
        $cart['member_id'] = $member?->id;
        $cart['walk_in'] = ! $member && $request->boolean('walk_in');

        return $this->save($request, $studio, $cart);
    }

    public function clear(Request $request, Studio $studio): RedirectResponse
    {
        $request->session()->forget($this->key($studio));

        return redirect()->route('studio.admin.pos');
    }

    public function checkout(Request $request, Studio $studio, PointOfSale $pos): RedirectResponse
    {
        $data = $request->validate([
            'method' => ['required', Rule::in(array_keys(Payment::METHODS))],
            'note' => ['nullable', 'string', 'max:190'],
        ]);
        try {
            $order = $pos->checkout($studio, $this->cart($request, $studio), $data['method'], $data['note'] ?? null, $request->user());
        } catch (CheckoutException $e) {
            return back()->with('error', $e->getMessage());
        }
        $request->session()->forget($this->key($studio));

        return redirect()->route('studio.admin.pos.receipt', ['order' => $order])->with('success', "Sale #{$order->number} recorded.");
    }

    public function receipt(Studio $studio, Order $order): View|RedirectResponse
    {
        if ($order->awaitsReader()) {
            return redirect()->route('studio.admin.pos.reader', ['order' => $order]);
        }
        abort_unless(in_array($order->status, ['paid', 'void'], true), 404);

        return view('admin.pos.receipt', ['order' => $order->load(['items', 'user', 'recordedBy', 'cardReader'])]);
    }

    /** Send the cart to a card reader, then wait for the card. */
    public function charge(Request $request, Studio $studio, ReaderPayments $readers): RedirectResponse
    {
        $data = $request->validate([
            'reader_id' => ['required', 'integer', Rule::exists('card_readers', 'id')->where('studio_id', $studio->id)],
            'note' => ['nullable', 'string', 'max:190'],
        ]);
        try {
            $order = $readers->charge($studio, $this->cart($request, $studio), $studio->cardReaders()->findOrFail($data['reader_id']),
                $data['note'] ?? null, $request->user());
        } catch (CheckoutException $e) {
            return back()->with('error', $e->getMessage());
        }
        $request->session()->put($this->readerKey($studio), $order->id); // its cart is cleared once paid

        return redirect()->route('studio.admin.pos.reader', ['order' => $order]);
    }

    /** Waiting for the card: asks Stripe each time it loads, and reloads itself while the reader waits. */
    public function reader(Request $request, Studio $studio, Order $order, ReaderPayments $readers, StripeClient $stripe): View|RedirectResponse
    {
        abort_unless($order->method === 'reader', 404);
        $error = null;
        try {
            $status = $readers->settle($order);
        } catch (StripeException $e) {
            report($e);
            [$status, $error] = [['state' => 'waiting', 'message' => null], 'Couldn’t reach Stripe just now: '.$e->getMessage()];
        }

        return match ($status['state']) {
            'paid' => $this->paid($request, $studio, $order),
            'closed' => redirect()->route('studio.admin.pos')->with('warning', 'That card reader sale was cancelled. Nothing was charged.'),
            default => view('admin.pos.reader', [
                'order' => $order->load(['items', 'user', 'cardReader']),
                'state' => $status['state'],
                'message' => $status['message'],
                'error' => $error,
                'testMode' => $stripe->testMode(),
            ]),
        };
    }

    public function cancelReader(Request $request, Studio $studio, Order $order, ReaderPayments $readers): RedirectResponse
    {
        abort_unless($order->method === 'reader', 404);
        try {
            $state = $readers->cancel($order)['state'];
        } catch (StripeException $e) {
            report($e);

            return back()->with('error', 'Couldn’t cancel it: '.$e->getMessage());
        }

        return match ($state) {
            'paid' => $this->paid($request, $studio, $order),
            'closed' => redirect()->route('studio.admin.pos')->with('success', 'Cancelled. Nothing was charged, and the cart is still here.'),
            default => back()->with('error', 'The payment couldn’t be cancelled yet. Wait a moment and try again.'),
        };
    }

    public function retryReader(Studio $studio, Order $order, ReaderPayments $readers): RedirectResponse
    {
        abort_unless($order->method === 'reader', 404);
        try {
            $readers->retry($order);
        } catch (CheckoutException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('studio.admin.pos.reader', ['order' => $order]);
    }

    /** Test mode: tap a test card on a simulated reader. */
    public function simulateReader(Request $request, Studio $studio, Order $order, ReaderPayments $readers, StripeClient $stripe): RedirectResponse
    {
        abort_unless($order->method === 'reader' && $stripe->testMode(), 404);
        try {
            $readers->simulateTap($order, $request->boolean('decline'));
        } catch (StripeException $e) {
            return back()->with('error', 'Stripe: '.$e->getMessage());
        }

        return redirect()->route('studio.admin.pos.reader', ['order' => $order]);
    }

    /**
     * Undo an in-person sale made by mistake (from Sales, a member's page or the receipt). A card reader
     * sale is refunded to the card first.
     */
    public function void(Request $request, Studio $studio, Order $order, Orders $orders, ReaderPayments $readers): RedirectResponse
    {
        if ($order->channel !== 'in_person') {
            return back()->with('error', 'Online sales are refunded in Stripe.');
        }
        if ($order->isVoid()) {
            return back()->with('error', 'That sale is already void.');
        }
        if ($order->status !== 'paid') {
            return back()->with('error', 'Only paid sales can be voided.');
        }
        if ($order->method === 'reader') {
            try {
                $readers->refund($order, $request->user());
            } catch (StripeException $e) {
                report($e);

                return back()->with('error', 'Stripe couldn’t refund it: '.$e->getMessage());
            }

            return back()->with('success', "Sale #{$order->number} refunded to the card and voided: plans it added were cancelled and products went back into stock.");
        }
        $orders->void($order, $request->user());

        return back()->with('success', "Sale #{$order->number} voided: plans it added were cancelled and products went back into stock.");
    }

    /** Paid on the reader: on to the receipt, emptying the till's cart if it was this sale's. */
    private function paid(Request $request, Studio $studio, Order $order): RedirectResponse
    {
        if ((int) $request->session()->get($this->readerKey($studio)) === $order->id) {
            $request->session()->forget([$this->key($studio), $this->readerKey($studio)]);
        }

        return redirect()->route('studio.admin.pos.receipt', ['order' => $order])->with('success', "Paid on the card reader. Sale #{$order->number} recorded.");
    }

    private function readerKey(Studio $studio): string
    {
        return "pos.reader.{$studio->id}";
    }

    private function key(Studio $studio): string
    {
        return "pos.cart.{$studio->id}";
    }

    /** @return array{member_id: ?int, walk_in: bool, lines: list<array{type: string, id: int, qty: int}>} */
    private function cart(Request $request, Studio $studio): array
    {
        return $request->session()->get($this->key($studio), []) + ['member_id' => null, 'walk_in' => false, 'lines' => []];
    }

    private function save(Request $request, Studio $studio, array $cart): RedirectResponse
    {
        $request->session()->put($this->key($studio), $cart);

        return redirect()->route('studio.admin.pos', array_filter(['q' => $request->input('q')]));
    }
}
