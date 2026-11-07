@extends('layouts.studio')

@section('title', $attendee->exists ? 'Edit '.$attendee->first_name : 'Add a family member')

@section('content')
<div class="narrow" style="margin: 0 auto">
    <p class="small"><a href="{{ $return ?? route('studio.account.family') }}">‹ Back</a></p>
    <h1>{{ $attendee->exists ? 'Edit '.$attendee->name : 'Add a family member' }}</h1>
    <form method="post" action="{{ $attendee->exists ? route('studio.attendees.update', ['attendee' => $attendee]) : route('studio.attendees.store') }}" class="form card">
        @csrf
        @if ($attendee->exists) @method('put') @endif
        @if ($return)<input type="hidden" name="return" value="{{ $return }}">@endif
        <div class="grid-2">
            <x-field name="first_name" label="First name" :value="$attendee->first_name" required />
            <x-field name="last_name" label="Last name" :value="$attendee->last_name ?? auth()->user()->last_name" required />
        </div>
        <x-field name="dob" label="Date of birth" type="date" :value="$attendee->dob?->format('Y-m-d')" hint="Used for age-limited classes and waivers for minors." />
        <x-field name="emergency_contact" label="Emergency contact (name & phone)" :value="$attendee->emergency_contact" />
        <x-textarea name="medical_notes" label="Medical notes / allergies" :value="$attendee->medical_notes" hint="Shown to instructors on the class roster." />
        <x-checkbox name="is_self" label="This is me" :checked="$attendee->is_self" />
        <div class="actions"><button class="btn">{{ $attendee->exists ? 'Save' : 'Add' }}</button></div>
    </form>
    @if ($attendee->exists)
        <form method="post" action="{{ route('studio.attendees.archive', ['attendee' => $attendee]) }}" class="mt" data-confirm="Remove {{ $attendee->first_name }} from your account? Past bookings are kept.">
            @csrf
            <button class="btn btn-danger btn-small">Remove from my account</button>
        </form>
    @endif
</div>
@endsection
