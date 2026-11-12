{{-- Before a class photo: who is out of it, by name (decision 45). Only people in the class itself. --}}
@if (in_array('photos', \App\Models\Attendee::questionsAsked($studio), true))
    @php
        $inClass = $roster->filter(fn ($b) => in_array($b->status, ['booked', 'attended'], true))->pluck('attendee')->unique('id');
        $groups = collect([
            'No photos' => $inClass->where('photo_consent', 'none'),
            'In-studio only' => $inClass->where('photo_consent', 'studio'),
            'Not asked yet' => $inClass->whereNull('photo_consent'),
        ])->filter->isNotEmpty();
    @endphp
    @if ($inClass->isNotEmpty())
        <p class="small photo-summary">
            <strong>Photos and video:</strong>
            @if ($groups->isEmpty())
                everyone in this class said yes.
            @else
                @foreach ($groups as $label => $people)
                    <span>{{ $label }}: {{ $people->pluck('name')->join(', ') }}.</span>
                @endforeach
            @endif
        </p>
    @endif
@endif
