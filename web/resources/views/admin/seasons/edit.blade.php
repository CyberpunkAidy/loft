@extends('layouts.admin')

@section('title', 'Edit '.$season->name)

@section('content')
<p class="small"><a href="{{ route('studio.admin.seasons.index') }}">‹ Seasons</a></p>
<h1>Edit season</h1>
<form method="post" action="{{ route('studio.admin.seasons.update', ['season' => $season]) }}" class="form card narrow">
    @csrf @method('put')
    <x-field name="name" label="Name" :value="$season->name" required />
    <div class="grid-2">
        <x-field name="starts_on" label="Starts" type="date" :value="$season->starts_on->format('Y-m-d')" required />
        <x-field name="ends_on" label="Ends" type="date" :value="$season->ends_on->format('Y-m-d')" required />
    </div>
    <x-checkbox name="is_current" label="Current season" :checked="$season->is_current" />
    <div class="actions"><button class="btn">Save</button></div>
</form>
@endsection
