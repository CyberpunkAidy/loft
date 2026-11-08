@extends('layouts.admin')

@section('title', 'Rooms')

@section('content')
<div class="page-head">
    <div>
        <h1>Rooms</h1>
        <p>Each room’s capacity limit, and the apparatus rigged in it. Classes that use apparatus take bookings per apparatus, so nobody is booked onto a silk that’s already taken.</p>
    </div>
    <a class="btn" href="{{ route('studio.admin.rooms.create') }}">Add a room</a>
</div>
@if ($rooms->isEmpty())
    <div class="card empty">
        <p>No rooms yet. Rooms are optional: add them when classes need a capacity limit or bookable apparatus.</p>
    </div>
@else
    <div class="card card-flush table-wrap">
        <table class="table">
            <thead><tr><th>Room</th><th>Capacity</th><th>Apparatus</th><th>Upcoming classes</th></tr></thead>
            <tbody>
            @foreach ($rooms as $room)
                <tr>
                    <td><a href="{{ route('studio.admin.rooms.edit', ['room' => $room]) }}"><strong>{{ $room->name }}</strong></a></td>
                    <td>{{ $room->max_capacity }}</td>
                    <td class="small">
                        @forelse ($room->apparatus as $a)
                            <div>{{ $a->name }} <span class="muted">· {{ $a->summary() }}</span></div>
                        @empty
                            <span class="muted">None</span>
                        @endforelse
                    </td>
                    <td>{{ $room->upcoming_count }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
@endif
@endsection
