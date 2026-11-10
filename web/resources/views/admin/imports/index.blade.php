@extends('layouts.admin')

@section('title', 'Import')

@section('content')
<div class="page-head">
    <div>
        <h1>Import from another system</h1>
        <p>Moving from Mindbody, Momence, Punchpass, WellnessLiving, a spreadsheet or anything else? Export your clients as a CSV file and bring them over. Members hear nothing until you send invitations.</p>
    </div>
    <a class="btn btn-secondary" href="{{ route('studio.admin.members.index') }}">Members</a>
</div>

<div class="import-steps">
    <section class="card">
        <span class="step-number">1</span>
        <h2>Members and families</h2>
        <p class="small muted">Your client list: one row per person, with names, emails, phones and birthdays, and medical notes and emergency contacts if you have them. Children can share a parent’s email, or have a column with their parent’s email.</p>
        <form method="post" action="{{ route('studio.admin.imports.store') }}" enctype="multipart/form-data" class="form">
            @csrf
            <input type="hidden" name="kind" value="people">
            <label class="field @error('file_people') has-error @enderror">
                <span>Client list (CSV)</span>
                <input type="file" name="file_people" accept=".csv,text/csv,.txt,text/plain" required>
                @error('file_people')<small class="error">{{ $message }}</small>@enderror
            </label>
            <div class="actions">
                <button class="btn">Upload and preview</button>
                <a class="small" href="{{ route('studio.admin.imports.template', ['kind' => 'people']) }}">Download a template</a>
            </div>
        </form>
    </section>

    <section class="card">
        <span class="step-number">2</span>
        <h2>Credits and memberships</h2>
        <p class="small muted">What members have already paid for: packs with classes left, and memberships with the date they’re paid through. You’ll match each plan to one of yours. @if (! $hasPlans) <a href="{{ route('studio.admin.plans.index') }}">Create your plans</a> first. @endif Memberships run until that date; members then carry them on in Loft and are charged from when the old one ends.</p>
        <form method="post" action="{{ route('studio.admin.imports.store') }}" enctype="multipart/form-data" class="form">
            @csrf
            <input type="hidden" name="kind" value="plans">
            <label class="field @error('file_plans') has-error @enderror">
                <span>Credits and memberships (CSV)</span>
                <input type="file" name="file_plans" accept=".csv,text/csv,.txt,text/plain" required>
                @error('file_plans')<small class="error">{{ $message }}</small>@enderror
            </label>
            <div class="actions">
                <button class="btn @unless ($hasMembers) btn-secondary @endunless">Upload and preview</button>
                <a class="small" href="{{ route('studio.admin.imports.template', ['kind' => 'plans']) }}">Download a template</a>
            </div>
        </form>
    </section>

    <section class="card">
        <span class="step-number">3</span>
        <h2>Invite your members</h2>
        @if ($uninvited || $waiting || $accepted)
            <p class="small muted">Each member gets an email from {{ $studio->name }} with their family and plans, and a link to choose a password. Members who already use Loft at another studio just sign in.</p>
            <ul class="invite-stats">
                <li><strong>{{ $uninvited }}</strong> not invited yet</li>
                <li><strong>{{ $waiting }}</strong> invited, not in yet</li>
                <li><strong>{{ $accepted }}</strong> in</li>
            </ul>
            <div class="actions">
                @if ($uninvited)
                    <form method="post" action="{{ route('studio.admin.imports.invite') }}" data-confirm="Email {{ $uninvited }} {{ $uninvited === 1 ? 'member' : 'members' }} now?">
                        @csrf
                        <button class="btn">Send {{ $uninvited }} {{ $uninvited === 1 ? 'invitation' : 'invitations' }}</button>
                    </form>
                @endif
                @if ($remindable)
                    <form method="post" action="{{ route('studio.admin.imports.invite') }}" data-confirm="Remind {{ $remindable }} {{ $remindable === 1 ? 'member' : 'members' }}?">
                        @csrf
                        <input type="hidden" name="which" value="remind">
                        <button class="btn btn-secondary">Remind {{ $remindable }} who haven’t come in</button>
                    </form>
                @endif
            </div>
        @else
            <p class="small muted" style="margin:0">Once you’ve imported members, send them their invitations from here, when you’re ready to switch.</p>
        @endif
    </section>
</div>

<section class="mt">
    <h2>Imports</h2>
    @if ($imports->isEmpty())
        <div class="card empty">Nothing imported yet.</div>
    @else
        <div class="card card-flush table-wrap">
            <table class="table">
                <thead><tr><th>File</th><th>What</th><th>Status</th><th>Result</th><th>When</th></tr></thead>
                <tbody>
                @foreach ($imports as $import)
                    <tr>
                        <td><a href="{{ route('studio.admin.imports.show', ['import' => $import]) }}">{{ $import->file_name }}</a></td>
                        <td>{{ $import->kindLabel() }}</td>
                        <td><span @class(['badge', 'badge-muted' => in_array($import->status, ['draft', 'undone']), 'badge-info' => $import->inProgress(), 'badge-err' => $import->status === 'failed'])>{{ $import->statusLabel() }}</span></td>
                        <td class="small">@include('admin.imports._result', ['import' => $import])</td>
                        <td class="small nowrap">{{ $studio->local($import->created_at)->format('M j, g:i A') }}@if ($import->user) · {{ $import->user->first_name }}@endif</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif
</section>
@endsection
