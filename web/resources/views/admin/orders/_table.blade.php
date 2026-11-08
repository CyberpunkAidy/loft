{{-- Orders: $orders, and $showMember for the member column. --}}
<div class="card card-flush table-wrap">
    <table class="table">
        <thead><tr><th>Date</th><th>Order</th>@if ($showMember)<th>Member</th>@endif<th>Items</th><th>Paid by</th><th class="right">Total</th><th></th></tr></thead>
        <tbody>
        @foreach ($orders as $order)
            <tr @class(['is-cancelled' => $order->isVoid()])>
                <td class="nowrap">{{ $studio->local($order->paid_at)->format('M j, Y') }}</td>
                <td class="nowrap">#{{ $order->number }}</td>
                @if ($showMember)
                    <td>
                        @if ($order->user)<a href="{{ route('studio.admin.members.show', ['member' => $order->user_id]) }}">{{ $order->user->name }}</a>@else<span class="muted">Account deleted</span>@endif
                    </td>
                @endif
                <td>
                    {{ $order->itemsLabel() }}
                    @if ($order->discount_cents > 0)<div class="small muted">Discount {{ \App\Support\Money::format($order->discount_cents, $order->currency) }}</div>@endif
                    @if ($order->payment?->note)<div class="small muted">{{ $order->payment->note }}</div>@endif
                </td>
                <td class="small">
                    @if ($url = $order->stripeUrl())<a href="{{ $url }}" target="_blank" rel="noopener">{{ $order->methodLabel() }} ↗</a>@else{{ $order->methodLabel() }}@endif
                </td>
                <td class="right nowrap">{{ $order->totalLabel() }}</td>
                <td class="right">
                    @if ($order->isVoid())
                        <span class="badge badge-muted">Void</span>
                    @elseif ($order->payment && ! $order->payment->isVoided())
                        <form method="post" action="{{ route('studio.admin.payments.void', ['payment' => $order->payment]) }}" class="inline" data-confirm="Void order #{{ $order->number }}? The plan it added is cancelled. Use this for mistakes.">
                            @csrf
                            <button class="link link-danger small">Void</button>
                        </form>
                    @endif
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>
