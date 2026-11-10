{{-- One line on what an import did (or will do). --}}
@if ($import->status === 'done' || $import->status === 'undone')
    @if ($import->kind === 'plans')
        {{ $import->tally('added_packs') }} {{ \Illuminate\Support\Str::plural('pack', $import->tally('added_packs')) }}, {{ $import->tally('added_memberships') }} {{ \Illuminate\Support\Str::plural('membership', $import->tally('added_memberships')) }}
    @else
        {{ $import->tally('added_members') }} {{ \Illuminate\Support\Str::plural('member', $import->tally('added_members')) }} ({{ $import->tally('created_accounts') }} new {{ \Illuminate\Support\Str::plural('account', $import->tally('created_accounts')) }}), {{ $import->tally('added_people') }} {{ $import->tally('added_people') === 1 ? 'person' : 'people' }}
    @endif
    @if ($import->tally('skipped')) · {{ $import->tally('skipped') }} skipped @endif
    @if ($import->status === 'undone') · undone {{ $studio->local($import->undone_at)->format('M j') }} @endif
@elseif ($import->status === 'failed')
    {{ \Illuminate\Support\Str::limit((string) $import->error, 80) }}
@else
    {{ $import->row_count }} {{ \Illuminate\Support\Str::plural('row', $import->row_count) }}
@endif
