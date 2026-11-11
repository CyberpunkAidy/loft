{{-- Who invited this visitor, from the referral link they followed (decision 43). Guests only. --}}
@php
    $referrals = app(\App\Services\Referrals::class);
    $invite = auth()->guest() && $referrals->bonusDays() > 0 ? $referrals->fromCookie(request()) : null;
    $days = (int) config('loft.billing.trial_days') + $referrals->bonusDays();
@endphp
@if ($invite)
    <div class="invite-banner" role="note">
        <x-icon name="gift" />
        <p>
            @if ($invite->studio)<strong>{{ $invite->studio->name }}</strong> invited you to Loft:@else You’re invited to Loft:@endif
            your first {{ $days }} days are free.
        </p>
        @unless (request()->routeIs('signup'))<a class="btn btn-small" href="{{ route('signup') }}">Start your studio</a>@endunless
    </div>
@endif
