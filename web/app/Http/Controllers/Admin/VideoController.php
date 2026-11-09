<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Studio;
use App\Models\Video;
use App\Services\Mux\MuxException;
use App\Services\VideoLibrary;
use App\Services\Videos;
use App\Support\VideoException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** Admin → Videos: the studio's on-demand library (roadmap step 10). */
class VideoController extends Controller
{
    public function index(Studio $studio, Videos $videos, VideoLibrary $library): View
    {
        $list = $studio->videos()->withCount('views')->latest('id')->get();
        $videos->refreshQuietly($list->filter->isPending()->take(5));

        return view('admin.videos.index', [
            'videos' => $list,
            'enabled' => $videos->enabled(),
            'used' => $videos->minutesUsed($studio),
            'limit' => $videos->minutesLimit(),
            'thumbs' => $list->mapWithKeys(fn (Video $v) => [$v->id => $library->thumbnailUrl($v, 320)]),
            'videoPlans' => $library->plans($studio)->count(),
        ]);
    }

    /**
     * A new video. From a file: answers JSON with the address the browser uploads it to (the file
     * goes straight to Mux, never through Loft). From a link: Mux fetches it.
     */
    public function store(Request $request, Studio $studio, Videos $videos): JsonResponse|RedirectResponse
    {
        $data = $this->validated($request) + $request->validate([
            'source' => ['required', Rule::in(['upload', 'url'])],
            'url' => ['nullable', 'required_if:source,url', 'url:https', 'max:2000'],
        ], ['url.required_if' => 'Paste the link to the video file.']);

        try {
            if ($data['source'] === 'url') {
                $video = $videos->import($studio, $request->user(), $data, $data['url']);

                return redirect()->route('studio.admin.videos.edit', ['video' => $video])
                    ->with('success', 'Mux is fetching the video. It’ll be ready in a few minutes; this page shows when.');
            }
            if (! $request->expectsJson()) {
                return back()->withInput()->with('error', 'Uploading a file needs JavaScript. Paste a link to the file instead.');
            }
            $started = $videos->startUpload($studio, $request->user(), $data, rtrim(url('/'), '/'));
        } catch (VideoException $e) {
            throw ValidationException::withMessages(['title' => $e->getMessage()]);
        } catch (MuxException $e) {
            report($e);
            throw ValidationException::withMessages([$data['source'] === 'url' ? 'url' : 'title' => 'Mux didn’t accept that: '.$e->getMessage()]);
        }
        $video = $started['video'];

        return response()->json([
            'id' => $video->id,
            'upload_url' => $started['upload_url'],
            'status_url' => route('studio.admin.videos.status', ['video' => $video]),
            'edit_url' => route('studio.admin.videos.edit', ['video' => $video]),
        ], 201);
    }

    public function edit(Request $request, Studio $studio, Video $video, Videos $videos, VideoLibrary $library): View
    {
        $videos->refreshQuietly([$video]);

        return view('admin.videos.edit', [
            'video' => $video->loadCount('views'),
            'playback' => $video->isReady() && $videos->enabled() ? $library->playback($request->user(), $video, record: false) : null,
            'viewers' => $video->views()->distinct()->count('user_id'),
        ]);
    }

    public function update(Request $request, Studio $studio, Video $video): RedirectResponse
    {
        $data = $this->validated($request);
        $video->fill($data);
        if ($video->published && $video->isReady() && ! $video->published_at) {
            $video->published_at = now();
        }
        $video->save();

        return back()->with('success', match (true) {
            $video->inLibrary() => 'Saved. It’s in the library.',
            $video->published => 'Saved. It goes in the library when it’s ready.',
            default => 'Saved. It isn’t in the library.',
        });
    }

    public function destroy(Studio $studio, Video $video, Videos $videos): RedirectResponse
    {
        try {
            $videos->delete($video);
        } catch (MuxException $e) {
            report($e);

            return back()->with('error', 'Mux didn’t delete it, so it’s still here: '.$e->getMessage().' Try again in a minute.');
        }

        return redirect()->route('studio.admin.videos.index')->with('success', "“{$video->title}” is deleted.");
    }

    /** For the uploader while it waits: has Mux got the file, and is it ready? */
    public function status(Studio $studio, Video $video, Videos $videos): JsonResponse
    {
        try {
            $videos->refresh($video);
        } catch (MuxException $e) {
            report($e); // keep polling
        }

        return response()->json(['status' => $video->status, 'label' => $video->statusLabel(), 'error' => $video->error, 'ready' => $video->isReady()]);
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:190'],
            'description' => ['nullable', 'string', 'max:5000'],
            'access' => ['required', Rule::in(array_keys(Video::ACCESS))],
            'published' => ['nullable', 'boolean'],
        ]);

        return ['published' => $request->boolean('published')] + $data;
    }
}
