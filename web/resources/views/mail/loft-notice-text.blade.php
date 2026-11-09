{!! $headline !!}

@foreach ($lines as $line)
{!! $line !!}

@endforeach
@if ($actionUrl)
{!! $actionLabel !!}: {!! $actionUrl !!}

@endif
@foreach ($after as $line)
{!! $line !!}

@endforeach
—
You're getting this because you own {!! $studio->name !!} on {!! config('app.name') !!}.
