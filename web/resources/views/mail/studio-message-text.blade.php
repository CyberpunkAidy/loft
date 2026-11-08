{!! $studio->name !!}

{!! $headline !!}

@foreach ($lines as $line)
{!! $line !!}

@endforeach
@foreach ($details as $label => $value)
{!! $label !!}: {!! $value !!}
@endforeach

@if ($actionUrl)
{!! $actionLabel !!}: {!! $actionUrl !!}

@endif
@foreach ($after as $line)
{!! $line !!}

@endforeach
—
Manage your classes and billing: {!! route('studio.account', $studio) !!}
@if ($studio->contact_email)
Questions? Just reply to this email.
@endif
{!! $studio->name !!}
