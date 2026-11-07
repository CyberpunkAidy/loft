@foreach (['success' => 'success', 'status' => 'success', 'warning' => 'warning', 'error' => 'error'] as $key => $kind)
    @if (session($key))
        <div class="alert alert-{{ $kind }}" role="{{ $kind === 'error' ? 'alert' : 'status' }}">{{ session($key) }}</div>
    @endif
@endforeach
@if ($errors->any())
    <div class="alert alert-error" role="alert">Please check the highlighted {{ $errors->count() === 1 ? 'field' : 'fields' }} below.</div>
@endif
