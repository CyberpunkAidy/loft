@extends('layouts.studio')

@section('title', $video->title)

@section('content')
<p class="small"><a href="{{ route('studio.videos') }}">‹ Videos</a></p>
<div class="video-page">
    @if ($playback)
        @include('videos._player', ['video' => $video, 'playback' => $playback])
    @else
        <div class="video-locked" style="aspect-ratio: {{ $video->cssAspectRatio() }}">
            @if ($poster)<img src="{{ $poster }}" alt="">@endif
            <div class="video-locked-message">
                <x-icon name="lock" />
                <p>{{ $message ?? 'This video can’t play right now. Please try again later.' }}</p>
                @if ($lock === 'sign_in')
                    <a class="btn" href="{{ route('studio.login') }}">Sign in</a>
                @elseif ($lock === 'not_a_member')
                    <a class="btn" href="{{ route('studio.join') }}">Join</a>
                @elseif ($lock === 'needs_plan')
                    <a class="btn" href="{{ route('studio.plans') }}">See plans</a>
                @endif
            </div>
        </div>
    @endif
    <h1 class="mt">{{ $video->title }}</h1>
    <p class="muted">{{ $video->durationLabel() }}@if (! $video->published) · Only staff can see this @endif</p>
    @if ($video->description)<p class="video-description">{!! nl2br(e($video->description)) !!}</p>@endif

    @if ($plans->isNotEmpty())
        <h2 class="mt">Plans that include videos</h2>
        <div class="plans">
            @foreach ($plans as $plan)
                @include('studio._plan-card', ['plan' => $plan])
            @endforeach
        </div>
    @endif
</div>
@endsection
