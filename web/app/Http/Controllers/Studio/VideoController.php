<?php

namespace App\Http\Controllers\Studio;

use App\Http\Controllers\Controller;
use App\Models\Studio;
use App\Models\Video;
use App\Services\VideoLibrary;
use App\Services\Videos;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** The studio's on-demand library: anyone can browse it; watching depends on the video (VideoLibrary). */
class VideoController extends Controller
{
    public function index(Request $request, Studio $studio, VideoLibrary $library): View
    {
        $videos = $studio->videos()->inLibrary()->with('studio')->latest('published_at')->latest('id')->get();
        $locks = $library->lockReasons($request->user(), $studio, $videos);

        return view('studio.videos.index', [
            'videos' => $videos,
            'locks' => $locks,
            'thumbs' => $videos->mapWithKeys(fn (Video $v) => [$v->id => $library->thumbnailUrl($v)]),
            'plans' => in_array('needs_plan', $locks, true) ? $library->plans($studio) : collect(),
        ]);
    }

    public function show(Request $request, Studio $studio, Video $video, VideoLibrary $library, Videos $videos): View
    {
        $user = $request->user();
        $staff = $user?->hasRoleIn($studio, 'instructor');
        abort_unless($video->inLibrary() || ($staff && $video->isReady()), 404);
        $video->setRelation('studio', $studio);
        $lock = $library->lockReason($user, $studio, $video);
        if ($lock === 'sign_in') {
            redirect()->setIntendedUrl($request->fullUrl()); // back here after signing in
        }

        return view('studio.videos.show', [
            'video' => $video,
            'lock' => $lock,
            'message' => $lock ? $library->lockMessage($lock, $studio) : null,
            'playback' => ! $lock && $videos->enabled() ? $library->playback($user, $video, record: ! $staff) : null,
            'poster' => $lock ? $library->thumbnailUrl($video, 1280) : null,
            'plans' => $lock === 'needs_plan' ? $library->plans($studio) : collect(),
        ]);
    }
}
