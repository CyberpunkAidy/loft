@extends('layouts.admin')

@section('title', $room->exists ? $room->name : 'Add a room')

@section('content')
<p class="small"><a href="{{ route('studio.admin.rooms.index') }}">‹ Rooms</a></p>
<div class="page-head">
    <h1>{{ $room->exists ? $room->name : 'Add a room' }}</h1>
    @if ($room->exists)
        <div class="actions">
            <form method="post" action="{{ route('studio.admin.rooms.destroy', ['room' => $room]) }}" class="inline" data-confirm="Delete {{ $room->name }}? Its classes lose their room and apparatus limits. People already booked keep their spots.">
                @csrf @method('delete')
                <button class="btn btn-danger btn-small">Delete</button>
            </form>
        </div>
    @endif
</div>

<form method="post" action="{{ $room->exists ? route('studio.admin.rooms.update', ['room' => $room]) : route('studio.admin.rooms.store') }}" class="form">
    @csrf
    @if ($room->exists) @method('put') @endif
    <fieldset class="fieldset form narrow">
        <legend>Room</legend>
        <x-field name="name" label="Name" :value="$room->name" required maxlength="60" placeholder="Main studio" />
        <x-field name="max_capacity" label="Capacity" type="number" min="1" max="1000" :value="$room->max_capacity" required hint="The most people allowed in the room at once, such as its fire-code limit. Classes here can’t be bigger." />
    </fieldset>

    <fieldset class="fieldset form">
        <legend>Apparatus</legend>
        <p class="small muted" style="margin:0">What’s rigged here, and the most people each one takes. Classes choose how many they use.</p>
        @error('apparatus')<div class="alert alert-error" style="margin:0">{{ $message }}</div>@enderror
        <div class="table-wrap">
            <table class="table apparatus-table">
                <thead><tr><th>Name</th><th>How many</th><th>People on each</th>@if ($room->exists && $room->apparatus->isNotEmpty())<th>Remove</th>@endif</tr></thead>
                <tbody>
                @if ($room->exists)
                    @foreach ($room->apparatus as $a)
                        <tr>
                            <td data-label="Name"><x-field :name="'apparatus['.$a->id.'][name]'" :value="$a->name" required maxlength="60" :aria-label="'Name of '.$a->name" /></td>
                            <td data-label="How many"><x-field :name="'apparatus['.$a->id.'][quantity]'" type="number" min="1" max="200" :value="$a->quantity" required :aria-label="'How many '.$a->name" /></td>
                            <td data-label="People on each"><x-field :name="'apparatus['.$a->id.'][max_per_item]'" type="number" min="1" max="20" :value="$a->max_per_item" required :aria-label="'People on each '.$a->name" /></td>
                            <td data-label="Remove"><label class="check"><input type="checkbox" name="apparatus[{{ $a->id }}][remove]" value="1" @checked(old('apparatus.'.$a->id.'.remove'))> <span class="sr-only">Remove {{ $a->name }}</span></label></td>
                        </tr>
                    @endforeach
                @endif
                @for ($i = 0; $i < $newRows; $i++)
                    <tr>
                        <td data-label="New apparatus"><x-field :name="'new_apparatus['.$i.'][name]'" maxlength="60" :placeholder="$room->apparatus->isEmpty() && $i < 2 ? ['Silks', 'Lyra'][$i] : 'Add another'" aria-label="New apparatus name" /></td>
                        <td data-label="How many"><x-field :name="'new_apparatus['.$i.'][quantity]'" type="number" min="1" max="200" aria-label="How many" /></td>
                        <td data-label="People on each"><x-field :name="'new_apparatus['.$i.'][max_per_item]'" type="number" min="1" max="20" aria-label="People on each" /></td>
                        @if ($room->exists && $room->apparatus->isNotEmpty())<td></td>@endif
                    </tr>
                @endfor
                </tbody>
            </table>
        </div>
        <p class="small muted" style="margin:0">Removing apparatus takes it off every class. People booked on it keep their spots.</p>
    </fieldset>
    <div class="actions"><button class="btn">{{ $room->exists ? 'Save room' : 'Create room' }}</button></div>
</form>
@endsection
