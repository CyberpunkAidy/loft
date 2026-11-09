<?php

namespace App\Services;

use App\Mail\StudioMessage;
use App\Models\Order;
use App\Models\Plan;
use App\Models\ProductVariant;
use App\Models\Studio;
use App\Models\User;
use App\Support\CheckoutException;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The front-desk point of sale (roadmap step 6). A cart of product variants and plans, for a member
 * or a walk-in, paid in person (cash, check, a card terminal…) or comped. Checking out is one
 * transaction: stock is taken (never below zero), plans are granted as in-person payments are, and
 * one order records it all. Sales tax (the studio's rate) applies to taxable products only. Card
 * readers connected to Stripe charge the same cart through App\Services\ReaderPayments.
 *
 * The cart is a plain array kept in the staff member's session:
 * ['member_id' => ?int, 'walk_in' => bool, 'lines' => [['type' => 'variant'|'plan', 'id' => int, 'qty' => int]]]
 */
class PointOfSale
{
    public function __construct(
        private readonly Orders $orders,
        private readonly OfflinePayments $payments,
        private readonly Messages $messages,
    ) {}

    /**
     * The cart's lines priced: description, unit price, quantity, total, tax, and the model.
     *
     * @return Collection<int, array{type: string, model: ProductVariant|Plan, description: string, unit: int, qty: int, total: int, tax: int}>
     */
    public function lines(Studio $studio, array $cart): Collection
    {
        $rate = (int) $studio->setting('sales_tax_milli'); // thousandths of a percent
        $variants = ProductVariant::with('product')->where('studio_id', $studio->id)
            ->whereIn('id', collect($cart['lines'] ?? [])->where('type', 'variant')->pluck('id'))->get()->keyBy('id');
        $plans = Plan::where('studio_id', $studio->id)->whereIn('id', collect($cart['lines'] ?? [])->where('type', 'plan')->pluck('id'))->get()->keyBy('id');

        return collect($cart['lines'] ?? [])->map(function (array $line) use ($variants, $plans, $rate) {
            $model = $line['type'] === 'variant' ? $variants->get($line['id']) : $plans->get($line['id']);
            if (! $model) {
                return null; // deleted since it went in the cart
            }
            $qty = $line['type'] === 'plan' ? 1 : max(1, (int) $line['qty']);
            $unit = $model instanceof ProductVariant ? $model->price() : $model->price_cents;
            $total = $unit * $qty;

            return [
                'type' => $line['type'],
                'model' => $model,
                'description' => $model instanceof ProductVariant ? $model->label() : $model->name,
                'unit' => $unit,
                'qty' => $qty,
                'total' => $total,
                'tax' => $model instanceof ProductVariant && $model->product->taxable ? (int) round($total * $rate / 100000) : 0,
            ];
        })->filter()->values();
    }

    /** @return array{subtotal: int, tax: int, total: int} */
    public function totals(Collection $lines): array
    {
        $subtotal = (int) $lines->sum('total');
        $tax = (int) $lines->sum('tax');

        return ['subtotal' => $subtotal, 'tax' => $tax, 'total' => $subtotal + $tax];
    }

    /**
     * The cart's lines and member, checked: not empty, and plans only for a member.
     *
     * @return array{0: Collection, 1: ?User}
     *
     * @throws CheckoutException
     */
    public function prepare(Studio $studio, array $cart): array
    {
        $lines = $this->lines($studio, $cart);
        if ($lines->isEmpty()) {
            throw new CheckoutException('The cart is empty.');
        }
        $member = ! empty($cart['member_id']) ? $studio->members()->find($cart['member_id']) : null;
        if (! $member && $lines->contains('type', 'plan')) {
            throw new CheckoutException('Plans go on a member’s account: choose the member first.');
        }

        return [$lines, $member];
    }

    /**
     * Take the stock for the cart's products and build the order's items. Plans are granted through
     * $grant (Plan, price → Entitlement); without it they're granted later, when a card reader payment
     * goes through. Call inside a transaction.
     *
     * @return list<array<string, mixed>>
     *
     * @throws CheckoutException
     */
    public function items(Collection $lines, bool $comp = false, ?callable $grant = null): array
    {
        $items = [];
        foreach ($lines as $line) {
            $model = $line['model'];
            if ($model instanceof ProductVariant) {
                // Never sell stock that isn't there, even if two tills race for the last one.
                if ($model->isTracked() && ProductVariant::whereKey($model->id)->where('stock', '>=', $line['qty'])->decrement('stock', $line['qty']) === 0) {
                    $left = (int) ProductVariant::whereKey($model->id)->value('stock');
                    throw new CheckoutException($left > 0 ? "Only {$left} of {$line['description']} left." : "{$line['description']} is sold out.");
                }
                $items[] = [
                    'kind' => 'product', 'product_variant_id' => $model->id, 'description' => $line['description'], 'quantity' => $line['qty'],
                    'unit_price_cents' => $comp ? 0 : $line['unit'], 'total_cents' => $comp ? 0 : $line['total'], 'tax_cents' => $comp ? 0 : $line['tax'],
                ];
            } else {
                $items[] = [
                    'kind' => 'plan', 'plan_id' => $model->id, 'entitlement_id' => $grant ? $grant($model, $comp ? 0 : $line['unit'])->id : null,
                    'description' => $line['description'], 'quantity' => 1,
                    'unit_price_cents' => $comp ? 0 : $line['unit'], 'total_cents' => $comp ? 0 : $line['total'], 'tax_cents' => 0,
                ];
            }
        }

        return $items;
    }

    /**
     * Take payment for the cart, paid in person some other way (cash, check, the studio's own
     * terminal…). Comps record everything at no charge. Card readers: App\Services\ReaderPayments.
     *
     * @throws CheckoutException
     */
    public function checkout(Studio $studio, array $cart, string $method, ?string $note, User $staff): Order
    {
        [$lines, $member] = $this->prepare($studio, $cart);
        $comp = $method === 'comp';
        $today = $studio->localNow()->toDateString();

        $order = DB::transaction(function () use ($studio, $lines, $member, $method, $comp, $today, $note, $staff) {
            $items = $this->items($lines, $comp, fn (Plan $plan, int $price) => $this->payments->grant($plan, $member, $method, $today, null, $price));
            $subtotal = (int) array_sum(array_column($items, 'total_cents'));
            $tax = (int) array_sum(array_column($items, 'tax_cents'));

            return $this->orders->recordSale($studio, [
                'user_id' => $member?->id,
                'walk_in' => ! $member,
                'channel' => 'in_person',
                'method' => $method,
                'currency' => $studio->currency,
                'subtotal_cents' => $subtotal,
                'tax_cents' => $tax,
                'total_cents' => $subtotal + $tax,
                'recorded_by_user_id' => $staff->id,
                'note' => $note,
                'paid_at' => now(),
            ], $items);
        });

        if ($member) {
            $this->messages->email($member, $this->receipt($studio, $order), "receipt:order:{$order->id}");
        }

        return $order;
    }

    /** The member's receipt email. */
    public function receipt(Studio $studio, Order $order): StudioMessage
    {
        $order->loadMissing('items');
        $details = $order->items->mapWithKeys(fn ($i) => [$i->description.($i->quantity > 1 ? ' ×'.$i->quantity : '') => Money::format($i->total_cents, $order->currency)])->all();
        if ($order->tax_cents > 0) {
            $details['Tax'] = Money::format($order->tax_cents, $order->currency);
        }
        $details['Total'] = $order->totalLabel().' · '.$order->methodLabel();
        $details['Order'] = '#'.$order->number.' · '.$studio->local($order->paid_at)->format('M j, Y');

        return new StudioMessage($studio, 'Thanks for your purchase', ['Here’s your receipt from the front desk.'], $details, [],
            route('studio.account.billing', $studio), 'See your purchases', 'receipt');
    }
}
