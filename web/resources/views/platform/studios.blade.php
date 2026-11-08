@extends('layouts.platform')

@section('title', 'My studios')

@section('content')
<div class="narrow" style="margin: 0 auto">
    <div class="page-head">
        <div>
            <h1>My studios</h1>
            <p>Signed in as {{ auth()->user()->email }}.</p>
        </div>
        <a class="btn btn-secondary btn-small" href="{{ route('signup') }}">Start another studio</a>
    </div>

    @forelse ($memberships as $membership)
        <div class="card item">
            <div class="row">
                <span class="brand-mark" style="--accent: {{ $membership->studio->accent() }}; --accent-ink: {{ $membership->studio->accentInk() }}" aria-hidden="true">{{ $membership->studio->initial() }}</span>
                <div>
                    <strong>{{ $membership->studio->name }}</strong>
                    <div class="muted small">{{ \App\Models\StudioUser::ROLES[$membership->role] ?? $membership->role }}</div>
                </div>
            </div>
            <div class="item-actions">
                @if ($membership->isStaff())
                    <a class="btn btn-small" href="{{ route('studio.admin.dashboard', ['studio' => $membership->studio]) }}">Studio admin</a>
                @endif
                <a class="btn btn-small btn-secondary" href="{{ route('studio.schedule', ['studio' => $membership->studio]) }}">Schedule</a>
                <a class="btn btn-small btn-secondary" href="{{ route('studio.account', ['studio' => $membership->studio]) }}">My account</a>
            </div>
        </div>
    @empty
        <div class="card empty">
            <p>You’re not part of any studio yet.</p>
            <p class="small">Members: open the link your studio shared with you and choose “Join”.</p>
            <a class="btn" href="{{ route('signup') }}">Start a studio</a>
        </div>
    @endforelse
</div>
@endsection
