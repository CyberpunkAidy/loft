@extends('layouts.admin')

@section('title', $program->exists ? $program->title : 'Add a program')

@section('content')
@php
    $selected = old('categories', $selected);
@endphp
<p class="small"><a href="{{ route('studio.admin.programs.index') }}">‹ Programs</a></p>
<div class="page-head">
    <h1>{{ $program->exists ? $program->title : 'Add a program' }}</h1>
    @if ($program->exists)
        <div class="actions">
            <a class="btn btn-secondary btn-small" href="{{ route('studio.program', ['program' => $program]) }}">View on site ↗</a>
            <form method="post" action="{{ route('studio.admin.programs.destroy', ['program' => $program]) }}" class="inline" data-confirm="Delete this program and its sessions? Programs with bookings are hidden instead.">
                @csrf @method('delete')
                <button class="btn btn-danger btn-small">Delete</button>
            </form>
        </div>
    @endif
</div>

<form method="post" action="{{ $program->exists ? route('studio.admin.programs.update', ['program' => $program]) : route('studio.admin.programs.store') }}" class="form">
    @csrf
    @if ($program->exists) @method('put') @endif
    <div class="split">
        <div class="form">
            <fieldset class="fieldset form">
                <legend>About</legend>
                <x-field name="title" label="Title" :value="$program->title" required placeholder="Kids Aerial Silks" />
                <x-field name="excerpt" label="One-line summary" :value="$program->excerpt" hint="Shown on the class page." />
                <x-textarea name="description" label="Description" :value="$program->description" rows="5" />
            </fieldset>

            <fieldset class="fieldset form">
                <legend>Schedule</legend>
                <x-select name="type" label="Type" :options="['class' => 'Weekly class (runs all season)', 'event' => 'One-off event or workshop']" :value="$program->type" />
                <div class="grid-2" data-show-when="type=class">
                    <x-select name="weekday" label="Day of the week" :options="\App\Models\Program::WEEKDAYS" :value="$program->weekday" placeholder="Choose a day" />
                    <x-select name="season_id" label="Season" :options="$seasons->pluck('name', 'id')->all()" :value="$program->season_id" placeholder="Current season" />
                </div>
                <div data-show-when="type=event">
                    <x-field name="event_date" label="Event date" type="date" :value="$program->event_date?->format('Y-m-d')" />
                </div>
                <div class="grid-3">
                    <x-field name="start_time" label="Start time" type="time" :value="$program->start_time" required />
                    <x-field name="duration_min" label="Length (minutes)" type="number" min="15" max="600" :value="$program->duration_min" required />
                    <x-field name="capacity" label="Capacity" type="number" min="1" max="500" :value="$program->capacity" required />
                </div>
                <div class="grid-2">
                    <x-select name="instructor_id" label="Instructor" :options="$instructors->mapWithKeys(fn ($u) => [$u->id => $u->name])->all()" :value="$program->instructor_id" placeholder="—" hint="Add instructors under Staff." />
                    <x-field name="waitlist_capacity" label="Waitlist limit" type="number" min="0" max="500" :value="$program->waitlist_capacity" hint="Empty for no limit; 0 for no waitlist." />
                </div>
            </fieldset>

            <fieldset class="fieldset form">
                <legend>Room and apparatus</legend>
                @if ($rooms->isEmpty())
                    <p class="small muted" style="margin:0">Optional. <a href="{{ route('studio.admin.rooms.create') }}">Add a room</a> to cap classes at its capacity and take bookings per apparatus, such as one person per silk.</p>
                @else
                    <x-select name="room_id" label="Room" :options="$rooms->mapWithKeys(fn ($r) => [$r->id => $r->name.' (holds '.$r->max_capacity.')'])->all()" :value="$program->room_id" placeholder="No room" />
                    @include('admin._apparatus-fields', ['rooms' => $rooms, 'current' => $allocation])
                @endif
            </fieldset>

            <fieldset class="fieldset form">
                <legend>Who it’s for</legend>
                <div class="grid-2">
                    <x-field name="min_age" label="Minimum age" type="number" min="0" max="120" :value="$program->min_age" />
                    <x-field name="max_age" label="Maximum age" type="number" min="0" max="120" :value="$program->max_age" />
                </div>
                @foreach (['age_group' => 'Age groups', 'level' => 'Levels'] as $type => $label)
                    <div class="field">
                        <span>{{ $label }}</span>
                        <div class="checks">
                            @foreach ($categories[$type] ?? [] as $c)
                                <label class="check"><input type="checkbox" name="categories[]" value="{{ $c->id }}" @checked(in_array($c->id, $selected))> <span>{{ $c->name }}</span></label>
                            @endforeach
                        </div>
                        <input class="input" name="new_categories[{{ $type }}]" value="{{ old('new_categories.'.$type) }}" placeholder="Add new, separated by commas" aria-label="New {{ strtolower($label) }}">
                    </div>
                @endforeach
                <p class="small muted" style="margin:0">Age groups and levels become the filters on your schedule.</p>
            </fieldset>
        </div>

        <div class="form">
            <fieldset class="fieldset form">
                <legend>Pricing</legend>
                <div class="field">
                    <span>Plan tags</span>
                    <div class="checks">
                        @foreach ($categories['plan_tag'] ?? [] as $c)
                            <label class="check"><input type="checkbox" name="categories[]" value="{{ $c->id }}" @checked(in_array($c->id, $selected))> <span>{{ $c->name }}</span></label>
                        @endforeach
                    </div>
                    <input class="input" name="new_categories[plan_tag]" value="{{ old('new_categories.plan_tag') }}" placeholder="Add new, separated by commas" aria-label="New plan tags">
                    <small class="hint">Plans cover programs by tag. A plan for “recreational” covers every program tagged recreational.</small>
                </div>
                <x-money name="dropin_price" label="Drop-in price (shown on the schedule)" :value="\App\Support\Money::toAmount($program->dropin_price_cents)" :currency="$studio->currency" hint="Display only; members pay through a Drop-in plan." />
                <x-checkbox name="members_only" label="Members only" :checked="$program->members_only" hint="Only memberships and seasonal tuition can book it — no packs or drop-ins." />
            </fieldset>
            <fieldset class="fieldset form">
                <legend>Visibility</legend>
                <x-checkbox name="is_published" label="Show on the schedule" :checked="$program->is_published" />
            </fieldset>
            @if ($program->exists)
                <x-checkbox name="apply_upcoming" label="Apply to upcoming sessions" hint="Copies the capacity, waitlist limit, room and apparatus to every upcoming session, replacing changes made to single sessions. People already booked keep their spots." />
            @endif
            <button class="btn btn-block">{{ $program->exists ? 'Save program' : 'Create program' }}</button>
            <p class="small muted">Saving creates any missing sessions and removes future sessions that no longer fit the schedule (only if nobody booked them).</p>
        </div>
    </div>
</form>

@if ($program->exists)
    <section class="mt">
        <div class="page-head">
            <h2 style="margin:0">Sessions <span class="muted small">{{ $sessionTotal }} total</span></h2>
            <form method="post" action="{{ route('studio.admin.programs.sync', ['program' => $program]) }}">
                @csrf
                <button class="btn btn-secondary btn-small">Sync sessions with schedule</button>
            </form>
        </div>
        @if ($sessions->isEmpty())
            <div class="card empty">No upcoming sessions. {{ $program->isEvent() ? 'Set the event date and save.' : 'Choose a day, make sure there’s a current season, and save.' }}</div>
        @else
            <div class="card card-flush table-wrap">
                <table class="table">
                    <thead><tr><th>Date</th><th>Time</th><th>Booked</th><th>Status</th><th></th></tr></thead>
                    <tbody>
                    @foreach ($sessions as $s)
                        <tr @class(['is-cancelled' => $s->isCancelled()])>
                            <td>{{ $studio->local($s->starts_at)->format('D M j, Y') }}</td>
                            <td>{{ $studio->local($s->starts_at)->format('g:i A') }} – {{ $studio->local($s->ends_at)->format('g:i A') }}@if ($s->room)<div class="muted small">{{ $s->room->name }}</div>@endif</td>
                            <td>{{ $s->bookedCount() }} / {{ $s->capacity }}@if ($s->waitlistCount()) <span class="muted">+{{ $s->waitlistCount() }} waiting</span>@endif</td>
                            <td>{{ ucfirst($s->status) }}</td>
                            <td class="right"><a href="{{ route('studio.admin.sessions.show', ['session' => $s]) }}">Roster</a></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
@endif
@endsection
