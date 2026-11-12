{{-- The studio's questions for one person (decision 45): injuries and health, and photos. Used by the
     person form and by the questions page on the way to a booking. Needs $attendee (may be new). --}}
@php
    $asked = \App\Models\Attendee::questionsAsked($studio);
    $health = old('has_health_notes', $attendee->health_answered_at || $attendee->medical_notes ? ($attendee->medical_notes ? '1' : '0') : null);
    $photo = old('photo_consent', $attendee->photo_consent);
    $photoHints = [
        'public' => 'Class photos and clips may be shared on the studio’s website and social media.',
        'studio' => 'For feedback in class and for the studio itself. Never posted online.',
        'none' => 'The studio will keep them out of photos and video.',
    ];
@endphp
@if (in_array('health', $asked, true))
    <fieldset class="question">
        <legend>Injuries and health</legend>
        <p class="small muted">Any injuries, health conditions or allergies the instructors should know about?</p>
        <div class="choice-row" role="radiogroup">
            <label class="pick"><input type="radio" name="has_health_notes" value="0" @checked((string) $health === '0')><span class="grow"><strong>No</strong></span></label>
            <label class="pick"><input type="radio" name="has_health_notes" value="1" @checked((string) $health === '1')><span class="grow"><strong>Yes</strong></span></label>
        </div>
        @error('has_health_notes')<small class="error-text">{{ $message }}</small>@enderror
        <div data-show-when="has_health_notes=1">
            <x-textarea name="medical_notes" label="Tell us about them" :value="$attendee->medical_notes" rows="3" hint="For example a recent shoulder injury, asthma or a nut allergy. Instructors see this on the class list." />
        </div>
    </fieldset>
@else
    <x-textarea name="medical_notes" label="Medical notes / allergies" :value="$attendee->medical_notes" hint="Shown to instructors on the class roster." />
@endif
@if (in_array('photos', $asked, true))
    <fieldset class="question">
        <legend>Photos and video</legend>
        <p class="small muted">{{ $studio->name }} sometimes takes photos and short videos in class. Is that OK?</p>
        @if ($attendee->exists && $attendee->photoRecordedByStudio())
            <p class="small notice-line">{{ $studio->name }} recorded this answer on {{ $studio->local($attendee->photo_consent_at)->format('M j, Y') }}, from what you told them (“{{ $attendee->photo_consent_note }}”). If it isn’t right, choose another.</p>
        @endif
        <div class="list" role="radiogroup">
            @foreach (\App\Models\Attendee::PHOTO_CONSENT as $value => $label)
                <label class="pick">
                    <input type="radio" name="photo_consent" value="{{ $value }}" @checked($photo === $value)>
                    <span class="grow"><strong>{{ $label }}</strong><span class="muted small" style="display:block">{{ $photoHints[$value] }}</span></span>
                </label>
            @endforeach
        </div>
        @error('photo_consent')<small class="error-text">{{ $message }}</small>@enderror
    </fieldset>
@endif
