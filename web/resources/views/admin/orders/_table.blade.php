{{-- Orders: $orders, and $showMember for the member column. --}}
<div class="card card-flush table-wrap">
    <table class="table">
        <thead><tr><th>Date</th><th>Order</th>@if ($showMember)<th>Member</th>@endif<th>Items</th><th>Paid by</th><th class="right">Total</th><th></th></tr></thead>
        <tbody>
        @foreach ($orders as $order)
            <tr @class(['is-cancelled' => $order->isVoid()])>
                <td class="nowrap">{{ $studio->local($order->paid_at)->format('M j, Y') }}</td>
                <td class="nowrap">@if ($order->channel === 'in_person')<a href="{{ route('studio.admin.pos.receipt', ['order' => $order]) }}">#{{ $order->number }}</a>@else#{{ $order->number }}@endif</td>
                @if ($showMember)
                    <td>
                        @if ($order->user)<a href="{{ route('studio.admin.members.show', ['member' => $order->user_id]) }}">{{ $order->user->name }}</a>@else<span class="muted">{{ $order->customerLabel() }}</span>@endif
                    </td>
                @endif
                <td>
                    {{ $order->itemsLabel() }}
                    @if ($order->discount_cents > 0)<div class="small muted">Discount {{ \App\Support\Money::format($order->discount_cents, $order->currency) }}</div>@endif
                    @if ($order->noteText())<div class="small muted">{{ $order->noteText() }}</div>@endif
                    @if ($order->awaitsPickup())
                        <div class="row small" style="margin-top:.3rem">
                            <span class="badge badge-warn">To pick up</span>
                            <form method="post" action="{{ route('studio.admin.orders.picked_up', ['order' => $order]) }}" class="inline">@csrf<button class="link small">Mark picked up</button></form>
                        </div>
                    @elseif ($order->fulfillment_status === 'picked_up')
                        <div class="small muted">Picked up {{ $studio->local($order->picked_up_at)->format('M j') }}</div>
                    @endif
                </td>
                <td class="small">
                    @if ($url = $order->stripeUrl())<a href="{{ $url }}" target="_blank" rel="noopener">{{ $order->methodLabel() }} ↗</a>@else{{ $order->methodLabel() }}@endif
                </td>
                <td class="right nowrap">{{ $order->totalLabel() }}</td>
                <td class="right">
                    @if ($order->isVoid())
                        <span class="badge badge-muted">Void</span>
                    @elseif ($order->channel === 'in_person')
                        <form method="post" action="{{ route('studio.admin.orders.void', ['order' => $order]) }}" class="inline" data-confirm="Void order #{{ $order->number }}? Plans it added are cancelled and products go back into stock. Use this for mistakes.">
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
