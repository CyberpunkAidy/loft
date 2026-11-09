<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Studio;
use App\Services\Refunds;
use App\Services\Stripe\StripeException;
use App\Support\Money;
use App\Support\RefundException;
use App\Support\ReturnPath;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Refund a sale paid through Stripe (online or on a card reader), after saying what it takes back. */
class RefundController extends Controller
{
    public function show(Request $request, Studio $studio, Order $order, Refunds $refunds): View|RedirectResponse
    {
        if (! $order->canRefund()) {
            return redirect($this->back($request, $studio, $order))->with('error', $order->isUndone()
                ? "Sale #{$order->number} was already ".strtolower($order->statusLabel()).'.'
                : "Sale #{$order->number} can’t be refunded from Loft. Refund it in the Stripe Dashboard.");
        }

        return view('admin.orders.refund', [
            'order' => $order->load(['items.entitlement.plan', 'items.variant', 'user']),
            'membership' => $refunds->membership($order),
            'return' => ReturnPath::from($request),
            'back' => $this->back($request, $studio, $order),
        ]);
    }

    public function store(Request $request, Studio $studio, Order $order, Refunds $refunds): RedirectResponse
    {
        $request->validate(['end_membership' => ['nullable', 'boolean']]);
        $back = $this->back($request, $studio, $order);
        $amount = Money::format($order->total_cents - $order->refunded_cents, $order->currency);
        $membership = $refunds->membership($order);
        try {
            $refunds->refund($order, $request->user(), $request->boolean('end_membership'));
        } catch (StripeException $e) {
            report($e);

            return back()->with('error', 'Stripe couldn’t refund it: '.$e->getMessage());
        } catch (RefundException $e) {
            return redirect($back)->with($e->refunded ? 'warning' : 'error', $e->getMessage());
        }

        $done = array_filter([
            $order->items->contains(fn ($i) => $i->entitlement_id && $i->entitlement_id !== $membership?->id) ? 'plans it added were cancelled' : null,
            $order->items->contains(fn ($i) => $i->product_variant_id) ? 'products went back into stock' : null,
            $membership ? ($request->boolean('end_membership') ? 'the membership has ended' : 'the membership keeps renewing') : null,
        ]);

        return redirect($back)->with('success', "Sale #{$order->number} refunded: {$amount} back to the customer"
            .($done ? '; '.implode(', ', $done) : '').'.');
    }

    /** Where to go afterwards: where staff came from, or that month's sales. */
    private function back(Request $request, Studio $studio, Order $order): string
    {
        return ReturnPath::from($request) ?? route('studio.admin.sales.index', ['month' => $studio->local($order->paid_at)->format('Y-m')]);
    }
}
