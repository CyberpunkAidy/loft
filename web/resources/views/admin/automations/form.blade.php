@extends('layouts.admin')

@section('title', $automation->label())

@section('content')
<p class="small"><a href="{{ route('studio.admin.automations.index') }}">‹ Automations</a></p>
<div class="page-head">
    <div>
        <h1>{{ $automation->label() }}</h1>
        <p>{{ \App\Models\Automation::TRIGGERS[$automation->trigger]['description'] }}</p>
    </div>
    <div class="actions">
        <form method="post" action="{{ route('studio.admin.automations.test', ['trigger' => $automation->trigger]) }}" class="inline">
            @csrf
            <button class="btn btn-secondary btn-small">Send me a test</button>
        </form>
    </div>
</div>

<div class="split">
    <form method="post" action="{{ route('studio.admin.automations.update', ['trigger' => $automation->trigger]) }}" class="form">
        @csrf @method('put')
        <fieldset class="fieldset form">
            <legend>When</legend>
            @if ($automation->trigger === 'first_class')
                <x-field name="delay_hours" label="Hours after the class" type="number" min="0" max="168" :value="intdiv($automation->delay_minutes, 60)" required hint="Counted from check-in. 0 sends it right away." />
            @elseif ($automation->trigger === 'win_back')
                <x-field name="days" label="Days since their last class" type="number" min="7" max="365" :value="$automation->days()" required hint="Sent once per absence, to members with nothing booked. Coming back and lapsing again sends it again." />
            @elseif ($automation->trigger === 'churn_risk')
                <p class="small" style="margin:0">From 10 AM studio time, the day a member’s churn risk turns <strong>high</strong> (see <a href="{{ route('studio.admin.members.index', ['risk' => 'at_risk']) }}">members at risk</a>). At most once a month each, and not within two weeks of a “We miss you” email.</p>
            @else
                <p class="small" style="margin:0">On the birthday, from 10 AM studio time. Children’s birthdays go to the parent’s account.</p>
            @endif
            <x-checkbox name="active" label="On" :checked="$automation->active" hint="Off until you turn it on." />
        </fieldset>

        <fieldset class="fieldset form">
            <legend>Message</legend>
            <x-field name="subject" label="Subject" :value="$automation->subject" required maxlength="150" />
            <x-textarea name="body" label="Message" :value="$automation->body" rows="9" required hint="Plain text. Leave a blank line between paragraphs. A button to your schedule is added below it." />
            <div class="small">
                <span class="muted">Placeholders:</span>
                <ul style="margin:.3rem 0 0;padding-left:1.1rem">
                    @foreach ($automation->placeholders() as $placeholder)
                        <li><code>{{ '{'.$placeholder.'}' }}</code> <span class="muted">{{ \App\Models\Automation::PLACEHOLDERS[$placeholder] }}</span></li>
                    @endforeach
                </ul>
            </div>
        </fieldset>
        <div class="actions"><button class="btn">Save</button></div>
    </form>

    <aside class="card">
        <h3>Preview</h3>
        <p class="small muted">The saved version, with sample names. Save to update it.</p>
        <div class="email-preview">
            <div class="small muted">[{{ $studio->name }}] {{ $sample['headline'] }}</div>
            <h4 style="margin:.6rem 0">{{ $sample['headline'] }}</h4>
            @foreach ($sample['lines'] as $line)
                <p class="small">{{ $line }}</p>
            @endforeach
            <span class="btn btn-small" aria-hidden="true">{{ $sample['action'] }}</span>
            <p class="small muted" style="margin:.8rem 0 0">You’re getting this because you asked for news from {{ $studio->name }}. Unsubscribe</p>
        </div>
    </aside>
</div>
@endsection
