@extends('layouts.studio')

@section('title', 'Plans')

@section('content')
<div class="page-head">
    <div>
        <h1>Memberships &amp; passes</h1>
        <p>Choose how you’d like to take classes. Checkout is secure, through Stripe.</p>
    </div>
</div>
@if ($plans->isEmpty())
    <div class="card empty">No plans are available yet. Please check back soon.</div>
@else
    <div class="plans">
        @foreach ($plans as $plan)
            @include('studio._plan-card', ['plan' => $plan])
        @endforeach
    </div>
@endif
@endsection
