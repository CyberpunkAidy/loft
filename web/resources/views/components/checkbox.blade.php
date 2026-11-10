@props(['name', 'label' => null, 'checked' => false, 'hint' => null])
<label class="check @error($name) has-error @enderror">
    <input type="hidden" name="{{ $name }}" value="0">
    <input type="checkbox" name="{{ $name }}" value="1" @checked(old($name, $checked)) {{ $attributes }}>
    <span>{{ $label }}{{ $slot }}@if ($hint)<span class="hint">{{ $hint }}</span>@endif</span>
</label>
@error($name)<small class="error">{{ $message }}</small>@enderror
