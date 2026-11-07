@extends('layouts.studio')

@section('title', 'History')

@section('content')
@include('studio.account._tabs')

@if ($bookings->isEmpty())
    <div class="card empty">No past classes yet.</div>
@else
    <div class="card card-flush table-wrap">
        <table class="table">
            <thead><tr><th>Date</th><th>Class</th><th>Attendee</th><th>Status</th></tr></thead>
            <tbody>
            @foreach ($bookings as $b)
                <tr>
                    <td class="nowrap">{{ $studio->local($b->classSession->starts_at)->format('M j, Y · g:i A') }}</td>
                    <td>{{ $b->classSession->program->title }}</td>
                    <td>{{ $b->attendee->name }}</td>
                    <td>{{ $b->statusLabel() }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
@endif
@endsection
