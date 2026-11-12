{{-- What staff need to know about one person from the studio's questions (decision 45): their photo
     answer when it isn't a plain yes, and which questions they haven't been asked yet. --}}
@php $notAsked = $person->questionsNeeded($studio); @endphp
@if ($person->photo_consent === 'none')
    <span class="badge badge-full" title="Said no to photos and video">No photos</span>
@elseif ($person->photo_consent === 'studio')
    <span class="badge badge-warn" title="Photos and video only for inside the studio, never posted online">In-studio photos only</span>
@endif
@if ($notAsked)
    <span class="badge badge-muted" title="Asked at their next booking. Until then, leave them out of photos.">Not asked yet: {{ collect($notAsked)->map(fn ($q) => $q === 'health' ? 'injuries' : 'photos')->join(', ') }}</span>
@endif
