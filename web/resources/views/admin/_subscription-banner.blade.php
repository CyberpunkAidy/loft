{{-- The studio's Loft subscription, when it needs attention: during the free trial, and in grace before it pauses. --}}
@php
    use App\Support\BillingState;
    $link = '<a href="'.e(route('studio.admin.subscription')).'">'.e(auth()->user()->hasRoleIn($studio, 'owner') ? ($billing->paymentFailing() ? 'Update your card' : 'Subscribe') : 'See the subscription').'</a>';
@endphp
@if ($billing->status === BillingState::TRIAL)
    @php $left = $billing->trialDaysLeft(); @endphp
    <div @class(['alert', 'subscription-banner', 'alert-info' => $left > 7, 'alert-warning' => $left <= 7]) role="status">
        <span>Free trial: {{ $left === 0 ? 'last day' : $left.' '.\Illuminate\Support\Str::plural('day', $left).' left' }}. {!! $link !!}</span>
    </div>
@elseif ($billing->status === BillingState::GRACE)
    <div class="alert alert-warning subscription-banner" role="status">
        <span>
            @if ($billing->paymentFailing()) Your last Loft payment didn’t go through.
            @elseif ($billing->reason === 'cancelled') Your Loft subscription has ended.
            @else Your free trial has ended.
            @endif
            Online booking and staff access pause on {{ $studio->local($billing->pausesAt)->format('F j') }}. {!! $link !!}
        </span>
    </div>
@endif
