{{-- The text opt-in boxes (decision 48). $membership: their membership, or null when joining; $marketing: offer the news-and-offers box too. --}}
@php
    $membership ??= null;
    $marketing ??= true;
@endphp
<fieldset class="fieldset form">
    <legend>Texts from {{ $studio->name }}</legend>
    @if ($membership?->sms_stopped_at)
        <p class="small" style="margin:0">You replied STOP to {{ $studio->name }}’s texts on {{ $studio->local($membership->sms_stopped_at)->format('M j') }}, so they’ve stopped. To get them again, text <strong>START</strong> to {{ $studio->textsFrom() }}.</p>
    @else
        <x-checkbox name="text_updates" :label="\App\Services\Texts::optInLabel($studio, 'updates')" :checked="(bool) $membership?->sms_updates_at" hint="Reminders before class, a spot opening on the waitlist, a class cancelled." />
        @if ($marketing)
            <x-checkbox name="text_marketing" :label="\App\Services\Texts::optInLabel($studio, 'marketing')" :checked="(bool) $membership?->sms_marketing_at" />
        @endif
        <p class="small muted" style="margin:0">To your mobile number, from {{ $studio->textsFrom() }}. {{ \App\Services\Texts::DISCLOSURE }} You don’t need texts to book or buy anything. See the <a href="{{ route('privacy') }}#texts" target="_blank">Privacy Policy</a>.</p>
    @endif
</fieldset>
