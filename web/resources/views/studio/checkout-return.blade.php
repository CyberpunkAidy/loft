@extends('layouts.studio')

@section('title', request()->boolean('cancelled') ? 'Payment cancelled' : 'Thank you')

@section('content')
<div class="card narrow" style="margin:2rem auto;text-align:center">
    @if (request()->boolean('cancelled'))
        <h1>Payment cancelled</h1>
        <p>Nothing was charged. You can close this page and go back to the app.</p>
    @else
        <h1>Thank you!</h1>
        <p>Your payment is with {{ $studio->name }}. Close this page and go back to the app to see your plan.</p>
    @endif
</div>
@endsection
