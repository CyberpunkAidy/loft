@extends('layouts.admin')

@section('title', $series->exists ? 'Edit '.$series->name : 'New series')

@section('content')
<p class="small"><a href="{{ $series->exists ? route('studio.admin.series.show', ['series' => $series]) : route('studio.admin.series.index') }}">‹ {{ $series->exists ? $series->name : 'Series' }}</a></p>
<div class="page-head"><h1>{{ $series->exists ? 'Edit '.$series->name : 'New series' }}</h1></div>

<form method="post" action="{{ $series->exists ? route('studio.admin.series.update', ['series' => $series]) : route('studio.admin.series.store') }}" class="form narrow" style="max-width:640px">
    @csrf
    @if ($series->exists) @method('put') @endif
    <fieldset class="fieldset form">
        <legend>The series</legend>
        <x-select name="program_id" label="Program" :options="$programs" :value="$series->program_id" placeholder="Choose a weekly program" required hint="Its classes on the dates below make up the series." />
        <x-field name="name" label="Name" :value="$series->name" required maxlength="120" placeholder="Silks Level 1 · Winter" hint="What members see, on the schedule and their receipt." />
        <div class="grid-2">
            <x-field name="starts_on" label="First day" type="date" :value="$series->starts_on?->format('Y-m-d')" required />
            <x-field name="ends_on" label="Last day" type="date" :value="$series->ends_on?->format('Y-m-d')" required />
        </div>
        <x-field name="price" label="Price for the whole series ({{ strtoupper($studio->currency) }})" type="number" min="0" step="0.01" :value="$series->exists ? number_format($series->price_cents / 100, 2, '.', '') : null" required placeholder="240" hint="Paid in full when signing up. Use 0 for a free series." />
    </fieldset>

    <fieldset class="fieldset form">
        <legend>Rules</legend>
        <x-field name="join_until_class" label="People can join until class…" type="number" min="1" :value="$series->join_until_class" required hint="1 = only before it starts. 2 = they can still join after missing the first class, and so on. Late joiners pay for the classes left." />
        <x-checkbox name="makeups" label="Make-up classes" :checked="$series->makeups" hint="Cancelling a class before the late-cancel window gives a make-up credit for another class, good until two weeks after the series ends." />
        <x-checkbox name="allow_dropins" label="Let others book open spots one class at a time" :checked="$series->allow_dropins" hint="Off: only people in the series (and make-ups) can book its classes." />
        <x-checkbox name="signups_open" label="Sign-ups are open" :checked="$series->signups_open" hint="Untick to stop new sign-ups. Staff can still add people." />
    </fieldset>
    <div class="actions"><button class="btn">{{ $series->exists ? 'Save' : 'Create series' }}</button></div>
</form>
@endsection
