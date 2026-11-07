@extends('layouts.admin')

@section('title', 'Seasons')

@section('content')
<div class="page-head">
    <div>
        <h1>Seasons</h1>
        <p>Weekly programs run on their weekday from a season’s first day to its last. Seasonal tuition bills monthly until its season ends.</p>
    </div>
</div>
<div class="split">
    <section>
        @if ($seasons->isEmpty())
            <div class="card empty">No seasons yet.</div>
        @else
            <div class="card card-flush table-wrap">
                <table class="table">
                    <thead><tr><th>Season</th><th>Dates</th><th>Programs</th><th></th></tr></thead>
                    <tbody>
                    @foreach ($seasons as $s)
                        <tr>
                            <td><strong>{{ $s->name }}</strong>@if ($s->is_current) <span class="badge">Current</span>@endif</td>
                            <td class="small">{{ $s->starts_on->format('M j, Y') }} – {{ $s->ends_on->format('M j, Y') }}</td>
                            <td>{{ $s->programs_count }}</td>
                            <td class="right">
                                <div class="actions">
                                    <a href="{{ route('studio.admin.seasons.edit', ['season' => $s]) }}">Edit</a>
                                    <form method="post" action="{{ route('studio.admin.seasons.destroy', ['season' => $s]) }}" class="inline" data-confirm="Delete {{ $s->name }}? Sessions already created are kept.">
                                        @csrf @method('delete')
                                        <button class="link link-danger">Delete</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
    <aside class="card">
        <h3>Add a season</h3>
        <form method="post" action="{{ route('studio.admin.seasons.store') }}" class="form">
            @csrf
            <x-field name="name" label="Name" required placeholder="2026–27 Season" />
            <div class="grid-2">
                <x-field name="starts_on" label="Starts" type="date" required />
                <x-field name="ends_on" label="Ends" type="date" required />
            </div>
            <x-checkbox name="is_current" label="Current season" :checked="true" hint="New programs default to it." />
            <button class="btn btn-small">Add season</button>
        </form>
    </aside>
</div>
@endsection
