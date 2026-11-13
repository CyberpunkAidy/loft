@extends('layouts.admin')

@section('title', 'Automations')

@section('content')
<div class="page-head">
    <div>
        <h1>Automations</h1>
        <p>Emails and texts that go out by themselves. They count as marketing, so they go only to members who opted in to your news and offers, with an unsubscribe link: <strong>{{ $optedIn }} of {{ $members }}</strong> {{ $members === 1 ? 'member' : 'members' }} by email so far@if ($textOptedIn !== null), and <strong>{{ $textOptedIn }}</strong> by text@endif.</p>
    </div>
</div>

<div class="list">
    @foreach ($automations as $trigger => $automation)
        <div class="card item">
            <div>
                <h3 style="margin:0 0 .2rem">
                    <a href="{{ route('studio.admin.automations.edit', ['trigger' => $trigger]) }}">{{ $automation->label() }}</a>
                    @if ($automation->active)<span class="badge">On</span>@else<span class="badge badge-muted">Off</span>@endif
                </h3>
                <div class="muted small">{{ \App\Models\Automation::TRIGGERS[$trigger]['description'] }}</div>
                <div class="small" style="margin-top:.35rem">{{ $automation->timing() }} · {{ $automation->channelLabel() }} · <span class="muted">“{{ $automation->sendsEmail() ? $automation->subject : \Illuminate\Support\Str::limit($automation->smsText(), 60) }}”</span></div>
            </div>
            <div class="item-actions">
                @if ($automation->exists)<span class="small muted">{{ $sent[$automation->id] ?? 0 }} sent in 30 days</span>@endif
                <a class="btn btn-secondary btn-small" href="{{ route('studio.admin.automations.edit', ['trigger' => $trigger]) }}">{{ $automation->exists ? 'Edit' : 'Set up' }}</a>
            </div>
        </div>
    @endforeach
</div>
@unless ($studio->textsReady())
    <p class="small muted">To send these by text as well, <a href="{{ route('studio.admin.texts') }}">connect your Twilio number</a>.</p>
@endunless
@endsection
