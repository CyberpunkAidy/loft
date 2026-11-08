@extends('layouts.admin')

@section('title', $session->program->title)

@section('content')
@php $start = $studio->local($session->starts_at); @endphp
<p class="small"><a href="{{ route('studio.admin.sessions.index', ['from' => $start->format('Y-m-d')]) }}">‹ Sessions</a></p>
<div class="page-head">
    <div>
        <h1>{{ $session->program->title }}</h1>
        <p>{{ $start->format('l, F j, Y · g:i') }} – {{ $studio->local($session->ends_at)->format('g:i A') }} · {{ $session->bookedCount() }}/{{ $session->capacity }} booked · {{ $session->waitlistCount() }} waitlisted</p>
    </div>
    <div class="actions">
        @if ($session->isCancelled())
            <span class="badge badge-muted">Cancelled</span>
        @else
            <form method="post" action="{{ route('studio.admin.sessions.cancel', ['session' => $session]) }}" class="inline" data-confirm="Cancel this class? Everyone booked is emailed and credits are returned.">
                @csrf
                <button class="btn btn-danger btn-small">Cancel class</button>
            </form>
        @endif
        @if ($roster->isEmpty())
            <form method="post" action="{{ route('studio.admin.sessions.destroy', ['session' => $session]) }}" class="inline" data-confirm="Delete this session permanently?">
                @csrf @method('delete')
                <button class="btn btn-danger btn-small">Delete</button>
            </form>
        @endif
    </div>
</div>

<div class="split">
    <section>
        @if ($roster->isEmpty())
            <div class="card empty">Nobody booked yet.</div>
        @else
            <div class="card card-flush table-wrap">
                <table class="table">
                    <thead><tr><th>Attendee</th><th>Account</th><th>Status</th><th>Paid with</th><th></th></tr></thead>
                    <tbody>
                    @foreach ($roster as $b)
                        <tr>
                            <td>
                                <strong>{{ $b->attendee->name }}</strong>@if ($b->attendee->age() !== null) <span class="muted">({{ $b->attendee->age() }})</span>@endif
                                @unless ($waiverOk[$b->id])<span class="badge badge-full">No waiver</span>@endunless
                                @if ($b->attendee->medical_notes)<div class="medical small">⚠ {{ $b->attendee->medical_notes }}</div>@endif
                            </td>
                            <td class="small"><a href="{{ route('studio.admin.members.show', ['member' => $b->user_id]) }}">{{ $b->user->name }}</a><div class="muted">{{ $b->user->email }}@if ($b->user->phone) · {{ $b->user->phone }}@endif</div></td>
                            <td>{{ $b->statusLabel() }}{{ $b->position ? ' #'.$b->position : '' }}</td>
                            <td class="small">{{ $b->entitlement?->plan?->name ?? ($b->entitlement_id ? 'Plan' : 'Comp') }}</td>
                            <td class="right">
                                @if ($b->isActive())
                                    <form method="post" action="{{ route('studio.admin.bookings.cancel', ['booking' => $b]) }}" class="inline" data-confirm="Remove {{ $b->attendee->first_name }} from this class? Any credit is returned.">
                                        @csrf
                                        <button class="link link-danger small">Remove</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        @unless ($session->isCancelled())
            <div class="card mt">
                <h3>Add someone to this class</h3>
                <form method="get" class="row mb">
                    <input class="input grow" type="search" name="q" value="{{ $q }}" placeholder="Search attendees by name" aria-label="Search attendees">
                    <button class="btn btn-secondary btn-small">Search</button>
                </form>
                @if ($candidates->isEmpty())
                    <p class="muted small" style="margin:0">No matching attendees.</p>
                @else
                    <form method="post" action="{{ route('studio.admin.sessions.book', ['session' => $session]) }}" class="form">
                        @csrf
                        <x-select name="attendee_id" label="Attendee" :options="$candidates->mapWithKeys(fn ($a) => [$a->id => $a->name.' — '.$a->user->name])->all()" placeholder="Choose…" required />
                        <x-checkbox name="comp" label="Comp" hint="Add them free, without using a membership or credit." />
                        <x-field name="waiver_override" label="No signed waiver? Reason to add them anyway" maxlength="190" placeholder="Signed a paper copy" hint="Only needed if they haven’t signed every waiver this class requires. It’s kept with the booking." />
                        <div class="actions"><button class="btn btn-small">Add to class</button></div>
                    </form>
                @endif
            </div>
        @endunless
    </section>

    <aside class="card">
        <h3>Session details</h3>
        <form method="post" action="{{ route('studio.admin.sessions.update', ['session' => $session]) }}" class="form">
            @csrf @method('patch')
            <x-field name="capacity" label="Capacity" type="number" min="1" max="500" :value="$session->capacity" required hint="Raising it moves people in from the waitlist." />
            <x-select name="instructor_id" label="Instructor" :options="$instructors->mapWithKeys(fn ($u) => [$u->id => $u->name])->all()" :value="$session->instructor_id" placeholder="—" />
            <x-textarea name="notes" label="Notes" :value="$session->notes" rows="3" />
            <button class="btn btn-secondary btn-small">Save</button>
        </form>
    </aside>
</div>
@endsection
