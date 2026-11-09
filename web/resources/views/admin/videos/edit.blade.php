@extends('layouts.admin')

@section('title', $video->title)

@section('content')
@if ($video->isPending())
    @push('head')<meta http-equiv="refresh" content="15">@endpush
@endif
<p class="small"><a href="{{ route('studio.admin.videos.index') }}">‹ Videos</a></p>
<div class="page-head">
    <div>
        <h1>{{ $video->title }}</h1>
        <p>
            {{ $video->durationLabel() ?? 'Length not known yet' }}
            · {{ $video->views_count }} {{ Str::plural('view', $video->views_count) }} by {{ $viewers }} {{ Str::plural('person', $viewers) }}
            · added {{ $studio->local($video->created_at)->format('M j, Y') }}
        </p>
    </div>
    <div class="actions">
        @if ($video->inLibrary())
            <a class="btn btn-secondary btn-small" href="{{ route('studio.videos.show', ['video' => $video]) }}">See it in the library</a>
        @endif
        <form method="post" action="{{ route('studio.admin.videos.destroy', ['video' => $video]) }}" class="inline" data-confirm="Delete this video? It’s removed from Mux too, and can’t be brought back.">
            @csrf @method('delete')
            <button class="btn btn-danger btn-small">Delete</button>
        </form>
    </div>
</div>

<div class="split">
    <section>
        @if ($playback)
            @include('videos._player', ['video' => $video, 'playback' => $playback])
        @elseif ($video->isPending())
            <div class="card empty video-waiting">
                <p><span class="badge badge-warn">{{ $video->statusLabel() }}</span></p>
                <p class="small" style="margin:0">
                    @if ($video->status === 'uploading')
                        Waiting for the file. If the upload was stopped (the page was closed), delete this and add it again.
                    @else
                        Mux is getting it ready to stream. Short videos take a minute or two; this page checks every 15 seconds.
                    @endif
                </p>
            </div>
        @elseif ($video->status === 'errored')
            <div class="alert alert-error"><div><strong>That didn’t work.</strong> {{ $video->error }} Delete this and try again.</div></div>
        @endif
    </section>

    <aside class="card">
        <h3>Details</h3>
        <form method="post" action="{{ route('studio.admin.videos.update', ['video' => $video]) }}" class="form">
            @csrf @method('patch')
            <x-field name="title" label="Title" maxlength="190" :value="$video->title" required />
            <x-textarea name="description" label="Description" :value="$video->description" rows="4" />
            <x-select name="access" label="Who can watch" :options="\App\Models\Video::ACCESS" :value="$video->access" />
            <x-checkbox name="published" label="In the library" :checked="$video->published" hint="Members see it once it’s ready. Staff can always watch it here." />
            <button class="btn btn-secondary btn-small">Save</button>
        </form>
    </aside>
</div>
@endsection
