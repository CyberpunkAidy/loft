@props(['name', 'label' => null, 'options' => [], 'value' => null, 'placeholder' => null, 'hint' => null])
@php
    $key = str_replace(['[', ']'], ['.', ''], $name);
    $id = $attributes->get('id', 'f-'.preg_replace('/[^a-z0-9]+/i', '-', $name));
    $current = (string) old($key, $value);
@endphp
<label class="field @error($key) has-error @enderror" for="{{ $id }}">
    @if ($label)<span>{{ $label }}</span>@endif
    <select id="{{ $id }}" name="{{ $name }}" {{ $attributes->except('id') }}>
        @if ($placeholder !== null)<option value="">{{ $placeholder }}</option>@endif
        @foreach ($options as $optionValue => $optionLabel)
            <option value="{{ $optionValue }}" @selected($current === (string) $optionValue)>{{ $optionLabel }}</option>
        @endforeach
    </select>
    @if ($hint)<small class="hint">{{ $hint }}</small>@endif
    @error($key)<small class="error">{{ $message }}</small>@enderror
</label>
