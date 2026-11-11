@props(['studio'])
{{-- On every page of a demo studio (decision 44): what it is, when it goes, and the way out. --}}
@if ($studio->isDemo())
    @php
        $demo = app(\App\Services\Demo\DemoStudios::class)->fromCookie(request());
        $mine = $demo && $demo['studio']->is($studio) && in_array(auth()->id(), [$demo['owner']->id, $demo['member']->id], true);
        $asOwner = $mine && auth()->id() === $demo['owner']->id;
    @endphp
    @push('head')<meta name="robots" content="noindex, nofollow">@endpush
    <div class="demo-banner" role="note">
        <p><strong>Demo studio.</strong> Nothing here is real: no emails go out and no cards are charged. It’s deleted {{ $studio->demo_expires_at->diffForHumans() }}.</p>
        <div class="demo-actions">
            @if ($mine)
                <form method="post" action="{{ route('demo.switch') }}">@csrf<button class="btn btn-small btn-secondary">{{ $asOwner ? 'See it as a member' : 'Back to the owner' }}</button></form>
            @endif
            <form method="post" action="{{ route('demo.leave') }}">@csrf<button class="btn btn-small">Start your own studio</button></form>
        </div>
    </div>
@endif
