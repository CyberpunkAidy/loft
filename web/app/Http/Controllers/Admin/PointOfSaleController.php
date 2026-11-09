<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Studio;
use App\Services\Orders;
use App\Services\PointOfSale;
use App\Support\CheckoutException;
use App\Support\Search;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Admin → Point of sale: the front-desk till. The cart lives in the staff member's session, one per
 * studio; every button is a plain form, so it works without JavaScript.
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

    public function receipt(Studio $studio, Order $order): View
    {
        return view('admin.pos.receipt', ['order' => $order->load(['items', 'user', 'recordedBy'])]);
    }

    /** Void an in-person sale made by mistake (from Sales, a member's page or the receipt). */
    public function void(Request $request, Studio $studio, Order $order, Orders $orders): RedirectResponse
    {
        if ($order->channel !== 'in_person') {
            return back()->with('error', 'Online sales are refunded in Stripe.');
        }
        if ($order->isVoid()) {
            return back()->with('error', 'That sale is already void.');
        }
        $orders->void($order, $request->user());

        return back()->with('success', "Sale #{$order->number} voided: plans it added were cancelled and products went back into stock.");
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
