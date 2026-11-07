@props(['name', 'label' => null, 'value' => null, 'hint' => null])
@php
    $key = str_replace(['[', ']'], ['.', ''], $name);
    $id = $attributes->get('id', 'f-'.preg_replace('/[^a-z0-9]+/i', '-', $name));
@endphp
<label class="field @error($key) has-error @enderror" for="{{ $id }}">
    @if ($label)<span>{{ $label }}</span>@endif
    <textarea id="{{ $id }}" name="{{ $name }}" {{ $attributes->except('id') }}>{{ old($key, $value) }}</textarea>
    @if ($hint)<small class="hint">{{ $hint }}</small>@endif
    @error($key)<small class="error">{{ $message }}</small>@enderror
</label>
