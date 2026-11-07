@php
    $member = auth()->user()?->hasRoleIn($studio);
    $seasonOver = $plan->kind === 'season' && $plan->season && $plan->season->ends_on->copy()->endOfDay()->isPast();
    $available = $studio->stripeReady() && $plan->isStripeReady() && ! $seasonOver;
@endphp
<div class="card plan">
    <div class="plan-kind">{{ $plan->kindLabel() }}</div>
    <h3>{{ $plan->name }}</h3>
    <div class="plan-price">{{ $plan->priceLabel() }}</div>
    <div class="plan-detail">{{ $plan->detailLabel() }}</div>
    @if ($plan->description)<p class="small" style="margin:0">{{ $plan->description }}</p>@endif
    @if (! $available)
        <span class="btn btn-secondary" aria-disabled="true">{{ $seasonOver ? 'Season ended' : 'Coming soon' }}</span>
    @elseif ($member)
        <form method="post" action="{{ route('studio.checkout', ['plan' => $plan]) }}">
            @csrf
            @isset($session)<input type="hidden" name="session_id" value="{{ $session->id }}">@endisset
            @isset($attendee)<input type="hidden" name="attendee_id" value="{{ $attendee->id }}">@endisset
            <button class="btn btn-block">Choose</button>
        </form>
    @else
        <a class="btn" href="{{ route('studio.join') }}">Join to buy</a>
    @endif
</div>
