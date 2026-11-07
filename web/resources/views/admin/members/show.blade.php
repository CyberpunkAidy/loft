@extends('layouts.admin')

@section('title', $member->name)

@section('content')
<p class="small"><a href="{{ route('studio.admin.members.index') }}">‹ Members</a></p>
<div class="page-head">
    <div>
        <h1>{{ $member->name }}</h1>
        <p><a href="mailto:{{ $member->email }}">{{ $member->email }}</a>@if ($member->phone) · {{ $member->phone }}@endif · {{ \App\Models\StudioUser::ROLES[$role] ?? $role }}</p>
    </div>
</div>

<div class="split">
    <div class="stack">
        <section>
            <h2>Family</h2>
            @if ($attendees->isEmpty())
                <div class="card empty">No attendees on this account.</div>
            @else
                <div class="card card-flush table-wrap">
                    <table class="table">
                        <thead><tr><th>Name</th><th>Age</th><th>Waiver</th><th>Notes</th></tr></thead>
                        <tbody>
                        @foreach ($attendees as $a)
                            @php $w = $waiverStatus[$a->id]; @endphp
                            <tr @class(['is-cancelled' => $a->status === 'archived'])>
                                <td>{{ $a->name }}@if ($a->is_self) <span class="muted">(self)</span>@endif @if ($a->status === 'archived')<span class="badge badge-muted">Removed</span>@endif</td>
                                <td>{{ $a->age() ?? '—' }}</td>
                                <td>@if (! $w['required'])—@elseif ($w['signed'])<span class="badge">Signed</span>@else<span class="badge badge-full">Missing</span>@endif</td>
                                <td class="small">{{ $a->medical_notes }}@if ($a->emergency_contact)<div class="muted">Emergency: {{ $a->emergency_contact }}</div>@endif</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>

        <section>
            <h2>Upcoming</h2>
            @if ($upcoming->isEmpty())
                <div class="card empty">No upcoming bookings.</div>
            @else
                <div class="card card-flush table-wrap">
                    <table class="table">
                        <tbody>
                        @foreach ($upcoming as $b)
                            <tr>
                                <td class="nowrap">{{ $studio->local($b->classSession->starts_at)->format('D M j · g:i A') }}</td>
                                <td><a href="{{ route('studio.admin.sessions.show', ['session' => $b->class_session_id]) }}">{{ $b->classSession->program->title }}</a></td>
                                <td>{{ $b->attendee->first_name }}</td>
                                <td>{{ $b->statusLabel() }}{{ $b->position ? ' #'.$b->position : '' }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>

        <section>
            <h2>Recent</h2>
            @if ($recent->isEmpty())
                <div class="card empty">No past classes.</div>
            @else
                <div class="card card-flush table-wrap">
                    <table class="table">
                        <tbody>
                        @foreach ($recent as $b)
                            <tr>
                                <td class="nowrap">{{ $studio->local($b->classSession->starts_at)->format('M j, Y') }}</td>
                                <td>{{ $b->classSession->program->title }}</td>
                                <td>{{ $b->attendee->first_name }}</td>
                                <td>{{ $b->statusLabel() }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    </div>

    <aside class="stack">
        <div class="card">
            <h3>Plans</h3>
            @forelse ($entitlements as $e)
                <div class="item small" style="padding:.4rem 0;border-bottom:1px solid var(--line)">
                    <div>
                        <strong>{{ $e->plan?->name ?? 'Plan' }}</strong>
                        <div class="muted">
                            @if ($e->isCredits()){{ $e->credits_remaining }}/{{ $e->credits_total }} credits @endif
                            @if ($e->expires_at) · ends {{ $studio->local($e->expires_at)->format('M j, Y') }}@elseif ($e->current_period_end) · renews {{ $studio->local($e->current_period_end)->format('M j') }}@endif
                            @unless ($e->stripe_subscription_id || $e->stripe_checkout_session_id) · comped @endunless
                        </div>
                    </div>
                    <span @class(['badge', 'badge-full' => $e->status === 'past_due', 'badge-muted' => in_array($e->status, ['cancelled', 'expired'])])>{{ $e->statusLabel() }}</span>
                </div>
            @empty
                <p class="muted small">None.</p>
            @endforelse
        </div>
        <div class="card">
            <h3>Comp a plan</h3>
            @if ($plans->isEmpty())
                <p class="muted small" style="margin:0"><a href="{{ route('studio.admin.plans.create') }}">Create a plan</a> first.</p>
            @else
                <form method="post" action="{{ route('studio.admin.members.comp', ['member' => $member]) }}" class="form" data-confirm="Grant this plan at no charge?">
                    @csrf
                    <x-select name="plan_id" label="Plan" :options="$plans->pluck('name', 'id')->all()" />
                    <button class="btn btn-secondary btn-small">Grant at no charge</button>
                </form>
            @endif
        </div>
    </aside>
</div>
@endsection
