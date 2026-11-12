@extends('layouts.studio')

@section('title', $attendee->is_self ? 'About you' : 'About '.$attendee->first_name)

@section('content')
@php $count = count(\App\Models\Attendee::questionsAsked($studio)); @endphp
<div class="narrow" style="margin: 0 auto">
    <p class="small"><a href="{{ $return ?? route('studio.account.family') }}">‹ Back</a></p>
    <h1>{{ $count === 1 ? 'A quick question' : 'Two quick questions' }} {{ $attendee->is_self ? 'for you' : 'about '.$attendee->first_name }}</h1>
    <p class="muted">{{ $studio->name }} asks everyone before they book. You can change your {{ $count === 1 ? 'answer' : 'answers' }} any time under My account → Family.</p>
    <form method="post" action="{{ route('studio.attendees.answer', ['attendee' => $attendee]) }}" class="form card">
        @csrf
        @method('put')
        @if ($return)<input type="hidden" name="return" value="{{ $return }}">@endif
        @include('studio._person-questions')
        <div class="actions"><button class="btn">{{ $return ? 'Save and continue' : 'Save' }}</button></div>
    </form>
</div>
@endsection
