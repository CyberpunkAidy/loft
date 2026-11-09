<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Studio;
use App\Services\Orders;
use App\Support\Csv;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Admin → Sales: the orders ledger, a month at a time, with totals and a CSV for the books. */
class SalesController extends Controller
{
    public function index(Request $request, Studio $studio): View
    {
        $toPickUp = $studio->orders()->where('status', 'paid')->where('fulfillment_status', 'to_pick_up');
        if ($request->boolean('pickup')) {
            return view('admin.sales.pickups', [
                'orders' => (clone $toPickUp)->with(['items', 'user', 'payment', 'studio'])->oldest('paid_at')->paginate(50)->withQueryString(),
            ]);
        }
        [$from, $to] = $this->month($request, $studio);
        $orders = $this->orders($studio, $from, $to);
        $paid = (clone $orders)->where('status', 'paid')->where('currency', $studio->currency);
        $net = DB::raw('total_cents - refunded_cents'); // what the studio kept

        return view('admin.sales.index', [
            'from' => $from,
            'orders' => (clone $orders)->with(['items', 'user', 'payment', 'studio'])->latest('paid_at')->latest('id')->paginate(50)->withQueryString(),
            'total' => (int) (clone $paid)->sum($net),
            'online' => (int) (clone $paid)->where('channel', 'online')->sum($net),
            'inPerson' => (int) (clone $paid)->where('channel', 'in_person')->sum($net),
            'refunds' => (int) (clone $orders)->whereIn('status', ['paid', 'refunded'])->where('currency', $studio->currency)->sum('refunded_cents'),
            'count' => (clone $paid)->where('method', '!=', 'comp')->count(),
            'comps' => (clone $paid)->where('method', 'comp')->count(),
            'toPickUp' => $toPickUp->count(),
        ]);
    }

    /** An online order was collected at the front desk. */
    public function pickedUp(Request $request, Studio $studio, Order $order, Orders $orders): RedirectResponse
    {
        if (! $order->awaitsPickup()) {
            return back()->with('error', "Order #{$order->number} isn’t waiting to be picked up.");
        }
        $orders->markPickedUp($order, $request->user());

        return back()->with('success', "Order #{$order->number} marked as picked up.");
    }

    /** The month's orders as CSV, for a spreadsheet or the accountant. */
    public function export(Request $request, Studio $studio): StreamedResponse
    {
        [$from, $to] = $this->month($request, $studio);
        $orders = $this->orders($studio, $from, $to)->with(['items', 'user'])->orderBy('paid_at')->orderBy('id');

        return response()->streamDownload(function () use ($studio, $orders) {
            $out = fopen('php://output', 'w');
            Csv::write($out, [[
                'Order', 'Date', 'Time zone', 'Member', 'Email', 'Items', 'Channel', 'Paid by', 'Subtotal', 'Discount', 'Tax', 'Total',
                'Refunded', 'Currency', 'Status', 'Stripe payment',
            ]]);
            foreach ($orders->lazy() as $order) {
                Csv::write($out, [[
                    $order->number,
                    $studio->local($order->paid_at)->format('Y-m-d H:i'),
                    $studio->timezone,
                    $order->user?->name ?? '(account deleted)',
                    $order->user?->email ?? '',
                    $order->itemsLabel(),
                    Order::CHANNELS[$order->channel] ?? $order->channel,
                    $order->methodLabel(),
                    number_format($order->subtotal_cents / 100, 2, '.', ''),
                    number_format($order->discount_cents / 100, 2, '.', ''),
                    number_format($order->tax_cents / 100, 2, '.', ''),
                    number_format($order->total_cents / 100, 2, '.', ''),
                    number_format($order->refunded_cents / 100, 2, '.', ''),
                    strtoupper($order->currency),
                    $order->statusLabel(),
                    $order->stripe_payment_intent_id ?? $order->stripe_invoice_id ?? '',
                ]]);
            }
            fclose($out);
        }, "sales-{$studio->slug}-".$this->month($request, $studio)[0]->format('Y-m').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @return array{0: Carbon, 1: Carbon} the start of the month (studio time) and of the next */
    private function month(Request $request, Studio $studio): array
    {
        $raw = (string) $request->query('month', '');
        $from = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $raw)
            ? Carbon::parse($raw.'-01', $studio->timezone)->startOfDay()
            : $studio->localNow()->startOfMonth();

        return [$from, $from->copy()->addMonthNoOverflow()];
    }

    private function orders(Studio $studio, Carbon $from, Carbon $to)
    {
        return $studio->orders()->where('paid_at', '>=', $from->copy()->utc())->where('paid_at', '<', $to->copy()->utc());
    }
}
