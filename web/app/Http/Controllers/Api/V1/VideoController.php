<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\PlanResource;
use App\Http\Resources\Api\VideoResource;
use App\Models\Studio;
use App\Models\Video;
use App\Services\VideoLibrary;
use App\Services\Videos;
use App\Support\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The on-demand library (roadmap step 10): anyone can list it; members get signed tokens to play. */
class VideoController extends Controller
{
    public function index(Request $request, Studio $studio, VideoLibrary $library): JsonResponse
    {
        $videos = $studio->videos()->inLibrary()->latest('published_at')->latest('id')->get()->each->setRelation('studio', $studio);
        $locks = $library->lockReasons($request->user('sanctum'), $studio, $videos);

        return response()->json([
            'data' => $videos->map(function (Video $video) use ($library, $locks, $request) {
                $resource = new VideoResource($video);
                $resource->thumbnailUrl = $library->thumbnailUrl($video);
                $resource->lockedReason = $locks[$video->id];

                return $resource->toArray($request);
            })->values(),
            'meta' => ['plans' => PlanResource::collection($library->plans($studio))->resolve()],
        ]);
    }

    /**
     * What the app's player needs: the playback ID, signed tokens (they last a few hours) and the
     * HLS stream URL. Counts as a view.
     */
    public function playback(Request $request, Studio $studio, Video $video, VideoLibrary $library, Videos $videos): JsonResponse
    {
        $user = $request->user();
        $staff = $user->hasRoleIn($studio, 'instructor');
        if (! ($video->inLibrary() || ($staff && $video->isReady()))) {
            throw new ApiException('not_found', 'That video isn’t in the library.', 404);
        }
        $video->setRelation('studio', $studio);
        if ($lock = $library->lockReason($user, $studio, $video)) {
            throw new ApiException($lock, $library->lockMessage($lock, $studio), $lock === 'needs_plan' ? 422 : 403,
                $lock === 'needs_plan' ? ['plans' => PlanResource::collection($library->plans($studio))->resolve()] : []);
        }
        if (! $videos->enabled()) {
            throw new ApiException('video_unavailable', 'Videos can’t play right now. Please try again later.', 503);
        }
        $playback = $library->playback($user, $video, record: ! $staff);

        return response()->json(['data' => [
            'video_id' => $video->id,
            'playback_id' => $playback['playback_id'],
            'tokens' => ['playback' => $playback['playback_token'], 'thumbnail' => $playback['thumbnail_token'], 'storyboard' => $playback['storyboard_token']],
            'stream_url' => $playback['stream_url'],
            'expires_at' => gmdate('Y-m-d\TH:i:s\Z', $playback['expires_at']),
        ]]);
    }
}
