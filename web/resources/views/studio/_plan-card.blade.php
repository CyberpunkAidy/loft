@php
    $member = auth()->user()?->hasRoleIn($studio);
    $seasonOver = $plan->kind === 'season' && $plan->season && $plan->season->ends_on->copy()->endOfDay()->isPast();
    $available = $studio->stripeReady() && $plan->isStripeReady() && ! $seasonOver;
    // A membership brought over from the studio's old system, which buying this plan carries on.
    $continues = $member && $plan->isRecurring() ? app(\App\Services\MemberInvitations::class)->continuation($studio, auth()->id(), $plan->id) : null;
@endphp
<div class="card plan" id="plan-{{ $plan->id }}">
    <div class="plan-kind">{{ $plan->kindLabel() }}</div>
    <h3>{{ $plan->name }}</h3>
    <div class="plan-price">{{ $plan->priceLabel() }}</div>
    <div class="plan-detail">{{ $plan->detailLabel().($plan->includes_videos ? ' · Includes videos' : '') }}</div>
    @if ($plan->description)<p class="small" style="margin:0">{{ $plan->description }}</p>@endif
    @if ($continues)
        <p class="plan-continues small">Yours until {{ $studio->local($continues->expires_at)->format('M j') }}, from before {{ $studio->name }} moved to Loft. Choose it to keep it going{{ $continues->expires_at->gt(now()->addHours(49)) ? ': your card is first charged on '.$studio->local($continues->expires_at)->format('M j').'.' : '.' }}</p>
    @endif
    @if (! $available)
        <span class="btn btn-secondary" aria-disabled="true">{{ $seasonOver ? 'Season ended' : 'Coming soon' }}</span>
    @elseif ($member)
        <form method="post" action="{{ route('studio.checkout', ['plan' => $plan]) }}">
            @csrf
            @isset($session)<input type="hidden" name="session_id" value="{{ $session->id }}">@endisset
            @isset($attendee)<input type="hidden" name="attendee_id" value="{{ $attendee->id }}">@endisset
            @if (! empty($apparatusId))<input type="hidden" name="apparatus_id" value="{{ $apparatusId }}">@endif
            @if (! empty($online))<input type="hidden" name="online" value="1">@endif
            <button class="btn btn-block">{{ $continues ? 'Keep it going' : 'Choose' }}</button>
        </form>
    @else
        <a class="btn" href="{{ route('studio.join') }}">Join to buy</a>
    @endif
</div>
