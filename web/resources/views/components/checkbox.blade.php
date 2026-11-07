@props(['name', 'label', 'checked' => false, 'hint' => null])
<label class="check">
    <input type="hidden" name="{{ $name }}" value="0">
    <input type="checkbox" name="{{ $name }}" value="1" @checked(old($name, $checked)) {{ $attributes }}>
    <span>{{ $label }}@if ($hint)<span class="hint">{{ $hint }}</span>@endif</span>
</label>
