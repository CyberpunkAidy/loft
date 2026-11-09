@extends('layouts.admin')

@section('title', 'Videos')

@section('content')
<div class="page-head">
    <div>
        <h1>Videos</h1>
        <p>On-demand classes and tutorials. They play only for people you let in, on your studio’s pages and in the app.</p>
    </div>
    @if ($videos->where('published', true)->where('status', 'ready')->isNotEmpty())
        <div class="actions"><a class="btn btn-secondary" href="{{ route('studio.videos') }}">See the library</a></div>
    @endif
</div>

@unless ($enabled)
    <div class="alert alert-info"><div>Video isn’t switched on for Loft yet. Videos you’ve added stay here.</div></div>
@endunless

<div class="split">
    <section>
        <p class="small muted" style="margin-top:0">
            <strong>{{ $used }}</strong> of {{ $limit }} minutes used.
            @if ($used >= $limit) Delete a video to add another. @endif
        </p>
        <div class="meter mb" role="img" aria-label="{{ $used }} of {{ $limit }} minutes used"><span style="width: {{ $limit ? min(100, round($used / $limit * 100)) : 100 }}%"></span></div>

        @if ($videos->isEmpty())
            <div class="card empty"><p>No videos yet. Add a recorded class, a warm-up or a skill tutorial.</p></div>
        @else
            <div class="card card-flush table-wrap">
                <table class="table">
                    <thead><tr><th>Video</th><th>Length</th><th>Who can watch</th><th>Views</th></tr></thead>
                    <tbody>
                    @foreach ($videos as $video)
                        <tr>
                            <td>
                                <div class="video-row">
                                    @if ($thumbs[$video->id])
                                        <img class="video-thumb" src="{{ $thumbs[$video->id] }}" alt="" width="96" height="54" loading="lazy">
                                    @else
                                        <span class="video-thumb" aria-hidden="true"><x-icon name="clapperboard" /></span>
                                    @endif
                                    <div>
                                        <a href="{{ route('studio.admin.videos.edit', ['video' => $video]) }}"><strong>{{ $video->title }}</strong></a>
                                        <div class="tags" style="margin-top:.3rem">
                                            @if ($video->isPending())<span class="badge badge-warn">{{ $video->statusLabel() }}</span>
                                            @elseif ($video->status === 'errored')<span class="badge badge-err">{{ $video->statusLabel() }}</span>
                                            @elseif ($video->published)<span class="badge">In the library</span>
                                            @else<span class="badge badge-muted">Not in the library</span>@endif
                                        </div>
                                    </div>
                                </div>
                            </td>
                            <td>{{ $video->durationLabel() ?? '—' }}</td>
                            <td class="small">{{ $video->needsPlan() ? 'With a plan' : 'Members' }}</td>
                            <td>{{ $video->views_count }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    <aside class="card">
        <h3>Add a video</h3>
        @if (! $enabled)
            <p class="muted small" style="margin:0">Adding videos opens once video is switched on.</p>
        @elseif ($used >= $limit)
            <p class="muted small" style="margin:0">Your studio has used its {{ $limit }} minutes. Delete a video to make room.</p>
        @else
            <form method="post" action="{{ route('studio.admin.videos.store') }}" class="form" data-video-upload>
                @csrf
                <x-field name="title" label="Title" maxlength="190" required placeholder="Silks: climbs and wraps" />
                <x-textarea name="description" label="Description" rows="3" />
                <x-select name="access" label="Who can watch" :options="\App\Models\Video::ACCESS" value="members"
                    :hint="$videoPlans ? null : 'No plan includes videos yet: tick “Includes the video library” on a plan to use the second option.'" />
                <x-checkbox name="published" label="Put it in the library when it’s ready" :checked="true" />

                <div class="field" role="radiogroup" aria-labelledby="video-source">
                    <span id="video-source">The video</span>
                    <label class="check"><input type="radio" name="source" value="upload" @checked(old('source', 'upload') === 'upload')> <span>Upload a file</span></label>
                    <label class="check"><input type="radio" name="source" value="url" @checked(old('source') === 'url')> <span>From a link</span></label>
                </div>
                <div data-show-when="source=upload">
                    <label class="field" for="video-file">
                        <span>File</span>
                        <input id="video-file" type="file" accept="video/*" data-video-file>
                        <small class="hint">MP4 or MOV from a phone or camera. It goes straight to Mux, our video host. Keep this page open until it’s sent.</small>
                    </label>
                </div>
                <div data-show-when="source=url">
                    <x-field name="url" label="Link to the file" type="url" maxlength="2000" placeholder="https://…/class.mp4"
                        hint="A direct, public link to a video file (not a YouTube page). Mux fetches it." />
                </div>
                <div class="upload-progress" data-upload-progress hidden>
                    <progress max="100" value="0"></progress>
                    <span class="small" data-upload-status role="status"></span>
                </div>
                <div class="actions"><button class="btn">Add video</button></div>
            </form>
            <script src="{{ asset('js/video-upload.js') }}?v={{ @filemtime(public_path('js/video-upload.js')) }}" defer></script>
        @endif
    </aside>
</div>
@endsection
