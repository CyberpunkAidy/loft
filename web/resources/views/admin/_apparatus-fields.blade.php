{{--
    Which of the chosen room's apparatus a class uses: one block per room, shown for the room
    picked in the room_id select. $rooms have apparatus loaded; $current is
    [apparatus_id => ['quantity' => n, 'max_per_item' => n]].
--}}
@foreach ($rooms as $room)
    <div class="apparatus-fields form" data-show-when="room_id={{ $room->id }}">
        @if ($room->apparatus->isEmpty())
            <p class="small muted" style="margin:0">Nothing is rigged in {{ $room->name }}. <a href="{{ route('studio.admin.rooms.edit', ['room' => $room]) }}">Add apparatus</a> to take bookings per apparatus.</p>
        @else
            <p class="small muted" style="margin:0">Apparatus in {{ $room->name }}. Each booking takes a spot on one; leave at 0 what this class doesn’t use.</p>
            @foreach ($room->apparatus as $a)
                <div class="grid-2">
                    <x-field :name="'apparatus['.$a->id.'][quantity]'" :label="$a->name.' used'" type="number" min="0" :max="$a->quantity" :value="$current[$a->id]['quantity'] ?? 0" :hint="$a->quantity.' in the room'" />
                    <x-field :name="'apparatus['.$a->id.'][max_per_item]'" label="People on each" type="number" min="1" :max="$a->max_per_item" :value="$current[$a->id]['max_per_item'] ?? $a->max_per_item" :hint="'Up to '.$a->max_per_item" />
                </div>
            @endforeach
        @endif
    </div>
@endforeach
