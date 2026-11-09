@extends('layouts.studio')

@section('title', 'Videos')

@section('content')
<div class="page-head">
    <div>
        <h1>Videos</h1>
        <p>Classes and tutorials to watch any time.</p>
    </div>
</div>

@if ($videos->isEmpty())
    <div class="card empty">No videos yet. Please check back soon.</div>
@else
    @if ($plans->isNotEmpty())
        <div class="alert alert-info"><div>Videos marked <strong>With a plan</strong> come with {{ $plans->pluck('name')->join(', ', ' or ') }}. <a href="{{ route('studio.plans') }}">See plans</a></div></div>
    @endif
    <div class="video-grid">
        @foreach ($videos as $video)
            @php $lock = $locks[$video->id]; @endphp
            <a class="card card-flush video-card" href="{{ route('studio.videos.show', ['video' => $video]) }}">
                <div class="video-cover" style="aspect-ratio: 16 / 9">
                    @if ($thumbs[$video->id])<img src="{{ $thumbs[$video->id] }}" alt="" loading="lazy">@endif
                    <span class="video-cover-icon" aria-hidden="true"><x-icon :name="$lock ? 'lock' : 'play'" /></span>
                    @if ($video->durationLabel())<span class="video-duration">{{ $video->durationLabel() }}</span>@endif
                </div>
                <div class="video-card-body">
                    <h3>{{ $video->title }}</h3>
                    @if ($video->description)<p class="small muted">{{ Str::limit($video->description, 110) }}</p>@endif
                    @if ($lock === 'needs_plan')<span class="badge badge-muted">With a plan</span>
                    @elseif ($lock)<span class="badge badge-muted">{{ $lock === 'sign_in' ? 'Sign in to watch' : 'For members' }}</span>@endif
                </div>
            </a>
        @endforeach
    </div>
@endif
@endsection
