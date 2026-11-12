{{-- Staff record someone's photo answer from what they were told (decision 46). Needs $person and
     $account (who gets the email). Opens by itself when its own form had an error. --}}
@if (in_array('photos', \App\Models\Attendee::questionsAsked($studio), true) && $person->status === 'active')
    @php $bag = $errors->getBag('photos'.$person->id); $mine = $bag->any(); @endphp
    <details class="photo-record" id="photos-{{ $person->id }}" @if ($mine) open @endif>
        <summary>{{ $person->photo_consent ? 'Change photo answer' : 'Record photo answer' }}</summary>
        <form method="post" action="{{ route('studio.admin.people.photos', ['attendee' => $person]) }}" class="photo-record-form">
            @csrf
            <select class="input" name="photo_consent" required aria-label="Photo answer for {{ $person->first_name }}">
                <option value="">What did they say?</option>
                @foreach (\App\Models\Attendee::PHOTO_CONSENT as $value => $label)
                    <option value="{{ $value }}" @selected($mine && old('photo_consent') === $value)>{{ $label }}</option>
                @endforeach
            </select>
            <input class="input" name="photo_note" required maxlength="190" value="{{ $mine ? old('photo_note') : '' }}" placeholder="How they told you, e.g. at the front desk" aria-label="How {{ $person->first_name }}’s answer was given">
            <button class="btn btn-small btn-secondary">Record</button>
        </form>
        @if ($mine)<small class="error-text">{{ $bag->first() }}</small>@endif
        <p class="small muted" style="margin:.35rem 0 0">{{ $account ? 'We’ll email '.$account->first_name.' so they can check it.' : 'Their account has been deleted, so nobody is emailed.' }}</p>
    </details>
@endif
