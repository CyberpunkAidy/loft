@props(['name', 'label' => null, 'value' => null, 'hint' => null, 'currency' => 'usd'])
@php
    $key = str_replace(['[', ']'], ['.', ''], $name);
    $id = 'f-'.preg_replace('/[^a-z0-9]+/i', '-', $name);
    $symbol = ['usd' => '$', 'cad' => 'CA$', 'eur' => '€', 'gbp' => '£', 'aud' => 'A$', 'nzd' => 'NZ$'][strtolower($currency)] ?? strtoupper($currency);
@endphp
<label class="field @error($key) has-error @enderror" for="{{ $id }}">
    @if ($label)<span>{{ $label }}</span>@endif
    <span class="prefix"><span>{{ $symbol }}</span><input id="{{ $id }}" name="{{ $name }}" type="number" step="0.01" min="0" inputmode="decimal" value="{{ old($key, $value) }}" {{ $attributes }}></span>
    @if ($hint)<small class="hint">{{ $hint }}</small>@endif
    @error($key)<small class="error">{{ $message }}</small>@enderror
</label>
