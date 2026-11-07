{!! $headline !!}

@foreach ($lines as $line)
{!! $line !!}

@endforeach
@foreach ($details as $label => $value)
{!! $label !!}: {!! $value !!}
@endforeach

@foreach ($after as $line)
{!! $line !!}

@endforeach
@if ($actionUrl)
{!! $actionLabel !!}: {!! $actionUrl !!}
@endif

— {!! $studio->name !!}
