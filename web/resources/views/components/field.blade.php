@props(['name', 'label' => null, 'type' => 'text', 'value' => null, 'hint' => null])
@php
    $key = str_replace(['[', ']'], ['.', ''], $name);
    $id = $attributes->get('id', 'f-'.preg_replace('/[^a-z0-9]+/i', '-', $name));
@endphp
<label class="field @error($key) has-error @enderror" for="{{ $id }}">
    @if ($label)<span>{{ $label }}</span>@endif
    <input id="{{ $id }}" name="{{ $name }}" type="{{ $type }}" @if ($type !== 'password') value="{{ old($key, $value) }}" @endif {{ $attributes->except('id') }}>
    @if ($hint)<small class="hint">{{ $hint }}</small>@endif
    @error($key)<small class="error">{{ $message }}</small>@enderror
</label>
