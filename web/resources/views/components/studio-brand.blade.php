{{-- The studio's mark (decision 41): its logo if it has one, otherwise the letter badge. A wide logo
     usually spells out the name, so it stands alone (the name is its alt text); a square one sits
     beside the name. --}}
@props(['studio', 'height' => 36, 'maxWidth' => 200])
@if ($studio->hasLogo())
    @php([$logoWidth, $logoHeight] = $studio->logoSize($height, $maxWidth))
    <img class="brand-logo" src="{{ $studio->logoUrl() }}" width="{{ $logoWidth }}" height="{{ $logoHeight }}" alt="{{ $studio->logoIsWide() ? $studio->name : '' }}">
    @unless ($studio->logoIsWide())<span class="brand-name">{{ $studio->name }}</span>@endunless
@else
    <span class="brand-mark" aria-hidden="true">{{ $studio->initial() }}</span> {{ $studio->name }}
@endif
