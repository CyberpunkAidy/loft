@extends('layouts.admin')

@section('title', 'Import')

@php
    use Illuminate\Support\Carbon;
    use Illuminate\Support\Str;
    $people = $import->kind === 'people';
    $statusLabel = ['new' => 'New', 'existing' => 'Uses Loft elsewhere', 'member' => 'Already a member'];
    $report = $import->report ?? [];
@endphp

@if ($import->inProgress())
    @push('head')<meta http-equiv="refresh" content="3">@endpush
@endif

@section('content')
<div class="page-head">
    <div>
        <h1>{{ $import->kindLabel() }}</h1>
        <p>{{ $import->file_name }} · {{ $import->row_count }} {{ Str::plural('row', $import->row_count) }} · uploaded {{ $studio->local($import->created_at)->format('M j, g:i A') }}@if ($import->user) by {{ $import->user->name }}@endif</p>
    </div>
    <a class="btn btn-secondary" href="{{ route('studio.admin.imports.index') }}">All imports</a>
</div>

@if ($editable)
    @if ($import->status === 'failed')
        <div class="alert alert-error" role="alert">Importing stopped part-way: {{ $import->error }} Everything imported before that is kept, and importing again picks up the rest.</div>
    @endif

    <form method="post" action="{{ route('studio.admin.imports.update', ['import' => $import]) }}" class="card">
        @csrf
        @method('put')
        <h2>Match your columns</h2>
        <p class="small muted">We matched what we could from the column names. Check each one, then update the preview.</p>
        <div class="grid-2 import-mapping">
            @foreach ($fields as $field => $definition)
                <label class="field">
                    <span>{{ $definition['label'] }}</span>
                    <select name="mapping[{{ $field }}]">
                        <option value="">Not in this file</option>
                        @foreach ($headers as $index => $header)
                            <option value="{{ $index }}" @selected(($import->mapping[$field] ?? null) === $index)>{{ $header !== '' ? $header : 'Column '.($index + 1) }}{{ $samples[$index] !== '' ? ' (e.g. '.Str::limit($samples[$index], 28).')' : '' }}</option>
                        @endforeach
                    </select>
                    @if (! empty($definition['hint']))<small class="hint">{{ $definition['hint'] }}</small>@endif
                </label>
            @endforeach
            <label class="field">
                <span>Dates are written</span>
                <select name="date_order">
                    @foreach (\App\Services\Imports\Dates::ORDERS as $order => $label)
                        <option value="{{ $order }}" @selected(($import->options['date_order'] ?? 'mdy') === $order)>{{ $label }}</option>
                    @endforeach
                </select>
            </label>
        </div>

        @unless ($people)
            <h3 class="mt">Match your plans</h3>
            @if (empty($planNames))
                <p class="small muted">Choose the plan column above to see the plans in this file.</p>
            @else
                <p class="small muted">Each plan in the file becomes one of yours. Packs keep the classes they have left; memberships run until the date they’re paid through.</p>
                <div class="table-wrap">
                    <table class="table">
                        <thead><tr><th>In the file</th><th>Rows</th><th>Your plan</th></tr></thead>
                        <tbody>
                        @foreach ($planNames as $key => $name)
                            <tr>
                                <td>{{ $name['name'] }}</td>
                                <td>{{ $name['count'] }}</td>
                                <td>
                                    <select class="input" name="plans[{{ $key }}]" aria-label="Loft plan for {{ $name['name'] }}">
                                        <option value="0">Don’t import</option>
                                        @foreach ($studioPlans as $studioPlan)
                                            <option value="{{ $studioPlan->id }}" @selected((int) ($import->options['plans'][$key] ?? 0) === $studioPlan->id)>{{ $studioPlan->name }} ({{ $studioPlan->kindLabel() }})</option>
                                        @endforeach
                                    </select>
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
                @if ($studioPlans->isEmpty())
                    <p class="small">You don’t have any plans yet. <a href="{{ route('studio.admin.plans.create') }}">Create them</a>, then come back.</p>
                @endif
            @endif
        @endunless
        <div class="actions mt"><button class="btn btn-secondary">Update preview</button></div>
    </form>

    <section class="card mt">
        <h2>Preview</h2>
        @if ($problem)
            <div class="alert alert-warning" role="status">{{ $problem }}</div>
        @else
            @php
                $c = $plan->counts;
                $n = fn (int $count, string $one, string $many) => '<strong>'.$count.'</strong> '.e($count === 1 ? $one : $many);
                $parts = $people
                    ? array_filter([
                        $n($c['accounts_new'], 'new account', 'new accounts'),
                        $c['accounts_existing'] ? $n($c['accounts_existing'], 'person who already uses Loft at another studio', 'people who already use Loft at another studio') : null,
                        $c['accounts_member'] ? $n($c['accounts_member'], 'already a member here', 'already members here') : null,
                    ])
                    : [$n($c['packs'], 'pack', 'packs').' and '.$n($c['memberships'], 'membership', 'memberships').' to add'];
                $sentence = implode(', ', $parts).'.';
                if ($people) {
                    $sentence .= ' '.e($c['people'].' '.($c['people'] === 1 ? 'person' : 'people').' to add, '.$c['family'].' of them on someone’s family.');
                }
                if ($plan->skipped) {
                    $sentence .= ' '.e(count($plan->skipped).' '.Str::plural('row', count($plan->skipped)).' will be skipped.');
                }
            @endphp
            <p>{!! $sentence !!}</p>

            @if ($plan->items)
                <div class="table-wrap">
                    <table class="table">
                        @if ($people)
                            <thead><tr><th>Account</th><th>People</th><th></th></tr></thead>
                            <tbody>
                            @foreach (array_slice($plan->items, 0, 12) as $account)
                                <tr>
                                    <td><strong>{{ $account['first_name'] }} {{ $account['last_name'] }}</strong><br><span class="small muted">{{ $account['email'] }}@if ($account['phone']) · {{ $account['phone'] }}@endif</span></td>
                                    <td class="small">
                                        @forelse ($account['people'] as $person)
                                            {{ $person['first_name'] }} {{ $person['last_name'] }}@if ($person['dob']) ({{ Carbon::parse($person['dob'])->age }})@endif @if ($person['medical_notes'])<span class="tag" title="{{ $person['medical_notes'] }}">Medical notes</span>@endif @if (! $loop->last)<br>@endif
                                        @empty
                                            <span class="muted">Nobody new</span>
                                        @endforelse
                                    </td>
                                    <td><span @class(['badge', 'badge-info' => $account['status'] !== 'new'])>{{ $statusLabel[$account['status']] }}</span></td>
                                </tr>
                            @endforeach
                            </tbody>
                        @else
                            <thead><tr><th>Member</th><th>Plan</th><th>Classes left</th><th>Until</th></tr></thead>
                            <tbody>
                            @foreach (array_slice($plan->items, 0, 12) as $item)
                                <tr>
                                    <td>{{ $item['name'] }}</td>
                                    <td>{{ $item['plan_name'] }}</td>
                                    <td>{{ $item['credits'] ?? '—' }}</td>
                                    <td class="nowrap">{{ $item['expires_at'] ? $studio->local(Carbon::parse($item['expires_at']))->format('M j, Y') : 'No expiry' }}</td>
                                </tr>
                            @endforeach
                            </tbody>
                        @endif
                    </table>
                </div>
                @if (count($plan->items) > 12)<p class="small muted">And {{ count($plan->items) - 12 }} more.</p>@endif
            @endif

            @include('admin.imports._problems', ['skipped' => $plan->skipped, 'warnings' => $plan->warnings, 'moreSkipped' => 0, 'moreWarnings' => 0])
        @endif

        <div class="actions mt">
            <form method="post" action="{{ route('studio.admin.imports.run', ['import' => $import]) }}">
                @csrf
                <button class="btn" @disabled($problem || ! $plan->items)>Import {{ $people ? count($plan->items).' '.Str::plural('account', count($plan->items)) : count($plan->items).' '.Str::plural('plan', count($plan->items)) }}</button>
            </form>
            <form method="post" action="{{ route('studio.admin.imports.destroy', ['import' => $import]) }}" data-confirm="Discard this upload?">
                @csrf
                @method('delete')
                <button class="btn btn-secondary">Discard</button>
            </form>
        </div>
        <p class="small muted" style="margin:.75rem 0 0">Nobody is emailed. You can undo an import for {{ \App\Models\Import::UNDO_DAYS }} days.</p>
    </section>
@elseif ($import->inProgress())
    <div class="card">
        <h2>Importing…</h2>
        <p style="margin:0">{{ $import->row_count }} {{ Str::plural('row', $import->row_count) }}. This page updates by itself, and you can leave it: the import carries on.</p>
    </div>
@else
    <div class="card">
        <h2>{{ match ($import->status) { 'done' => 'Imported', 'undone' => 'Undone', default => 'Import failed' } }}</h2>
        <p>@include('admin.imports._result', ['import' => $import])</p>
        @if ($import->status === 'failed')
            <p class="small muted">{{ $import->error }}</p>
        @endif
        @if ($import->status === 'done')
            <p class="small" style="margin-bottom:0">
                @if ($people)
                    Next: <a href="{{ route('studio.admin.imports.index') }}">import credits and memberships</a>, or <a href="{{ route('studio.admin.members.index') }}">check your members</a>. Nobody has been emailed; send invitations from the <a href="{{ route('studio.admin.imports.index') }}">import page</a> when you’re ready.
                @else
                    Members see these under their plans. Imported memberships run until the date shown; a week before, members who’ve been invited are asked to carry them on in Loft.
                @endif
            </p>
        @endif
        @if ($import->canUndo())
            <form method="post" action="{{ route('studio.admin.imports.undo', ['import' => $import]) }}" class="mt" data-confirm="Remove what this import added? Anything used since (booked, invitation accepted, plan used) is kept.">
                @csrf
                <button class="btn btn-secondary">Undo this import</button>
            </form>
        @endif
    </div>
    @include('admin.imports._problems', ['skipped' => $report['skipped'] ?? [], 'warnings' => $report['warnings'] ?? [], 'moreSkipped' => $report['more_skipped'] ?? 0, 'moreWarnings' => $report['more_warnings'] ?? 0])
@endif
@endsection
