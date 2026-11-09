<?php

namespace App\Services;

use App\Models\Studio;
use App\Models\User;
use App\Models\Video;
use App\Services\Mux\MuxClient;
use App\Services\Mux\MuxException;
use App\Support\VideoException;
use Illuminate\Support\Facades\Log;

/**
 * A studio's video library on Mux (roadmap step 10, decision 33): uploads straight from the
 * browser to Mux, imports from a link, and following each one until it's ready. There's no Mux
 * webhook: a pending video is checked when staff look at it, by the uploader while it waits, and
 * by the hourly housekeeping.
 */
class Videos
{
    /** A pending video is asked about at most this often (page loads, the uploader's polling). */
    public const CHECK_EVERY_SECONDS = 15;

    /** Housekeeping asks about pending videos it hasn't heard about for this long. */
    public const SWEEP_AFTER_MINUTES = 5;

    public function __construct(private readonly MuxClient $mux) {}

    /** Mux keys are set, so studios can add and play videos. */
    public function enabled(): bool
    {
        return $this->mux->configured() && $this->mux->canSign();
    }

    /** Each studio's allowance of stored video, in minutes (Loft pays Mux for storage and streaming). */
    public function minutesLimit(): int
    {
        return max(0, (int) config('loft.mux.studio_minutes'));
    }

    /** Minutes stored: every video that isn't a failed one, rounded up. */
    public function minutesUsed(Studio $studio): int
    {
        return (int) ceil($studio->videos()->where('status', '!=', 'errored')->sum('duration_seconds') / 60);
    }

    /**
     * A video waiting for its file: the browser sends the file straight to Mux, to upload_url.
     *
     * @param  array{title: string, description?: ?string, access?: string, published?: bool}  $data
     * @return array{video: Video, upload_url: string}
     *
     * @throws VideoException|MuxException
     */
    public function startUpload(Studio $studio, User $by, array $data, string $corsOrigin): array
    {
        $video = $this->create($studio, $by, $data, 'upload', 'uploading');
        try {
            $upload = $this->mux->createUpload($video, $corsOrigin);
        } catch (MuxException $e) {
            $video->delete();
            throw $e;
        }
        $video->update(['mux_upload_id' => $upload['id']]);

        return ['video' => $video, 'upload_url' => $upload['url']];
    }

    /**
     * A video Mux fetches from a link (a public .mp4, a shared Dropbox or Drive file…).
     *
     * @throws VideoException|MuxException
     */
    public function import(Studio $studio, User $by, array $data, string $url): Video
    {
        $video = $this->create($studio, $by, $data, 'url', 'processing');
        try {
            $asset = $this->mux->createAsset($video, $url);
        } catch (MuxException $e) {
            $video->delete();
            throw $e;
        }
        $video->update(['mux_asset_id' => $asset['id'] ?? null, 'checked_at' => now()]);

        return $video;
    }

    /**
     * Ask Mux how a pending video is getting on: the upload arrived, encoding finished, or it failed.
     * Not more than every CHECK_EVERY_SECONDS unless $force.
     *
     * @throws MuxException
     */
    public function refresh(Video $video, bool $force = false): Video
    {
        if (! $video->isPending() || (! $force && $video->checked_at?->gt(now()->subSeconds(self::CHECK_EVERY_SECONDS)))) {
            return $video;
        }
        $video->checked_at = now();

        if ($video->status === 'uploading' && $video->mux_upload_id) {
            $upload = $this->mux->upload($video->mux_upload_id);
            match ($upload['status'] ?? 'waiting') {
                'asset_created' => $video->fill(['status' => 'processing', 'mux_asset_id' => $upload['asset_id'] ?? null]),
                'errored' => $video->fill(['status' => 'errored', 'error' => $upload['error']['message'] ?? 'The upload didn’t work. Try again.']),
                'cancelled', 'timed_out' => $video->fill(['status' => 'errored', 'error' => 'The file never arrived. Try uploading it again.']),
                default => null, // still waiting for the file
            };
        }

        if ($video->status === 'processing' && $video->mux_asset_id) {
            $asset = $this->mux->asset($video->mux_asset_id);
            $playback = collect($asset['playback_ids'] ?? [])->firstWhere('policy', 'signed');
            if (($asset['status'] ?? null) === 'ready' && $playback) {
                $video->fill([
                    'status' => 'ready',
                    'error' => null,
                    'mux_playback_id' => $playback['id'],
                    'duration_seconds' => isset($asset['duration']) ? (int) round((float) $asset['duration']) : null,
                    'aspect_ratio' => $asset['aspect_ratio'] ?? null,
                ]);
                if ($video->published && ! $video->published_at) {
                    $video->published_at = now();
                }
            } elseif (($asset['status'] ?? null) === 'errored') {
                $messages = $asset['errors']['messages'] ?? [];
                $video->fill(['status' => 'errored', 'error' => mb_substr($messages ? implode(' ', (array) $messages) : 'Mux couldn’t read that file. Is it a video?', 0, 500)]);
            }
        }
        $video->save();

        return $video;
    }

    /** Refresh pending videos staff are looking at, quietly: a Mux hiccup shouldn't break the page. */
    public function refreshQuietly(iterable $videos): void
    {
        foreach ($videos as $video) {
            try {
                $this->refresh($video);
            } catch (MuxException $e) {
                report($e);
            }
        }
    }

    /**
     * Gone from the library and from Mux. A video still uploading is checked first, in case its
     * file arrived since we last asked, so nothing is left behind on Mux.
     *
     * @throws MuxException
     */
    public function delete(Video $video): void
    {
        if ($video->status === 'uploading' && $video->mux_upload_id) {
            $this->refresh($video, force: true);
        }
        if ($video->mux_asset_id) {
            $this->mux->deleteAsset($video->mux_asset_id);
        } elseif ($video->mux_upload_id) {
            $this->mux->cancelUpload($video->mux_upload_id);
        }
        $video->delete();
    }

    /**
     * Remove videos from Mux after their rows are gone (a studio closed): best effort, as the
     * studio is gone either way. Failures are logged with the asset, for removing by hand.
     *
     * @param  iterable<Video>  $videos
     */
    public function purgeFromMux(iterable $videos): void
    {
        foreach ($videos as $video) {
            try {
                if ($video->mux_asset_id) {
                    $this->mux->deleteAsset($video->mux_asset_id);
                } elseif ($video->mux_upload_id) {
                    $this->mux->cancelUpload($video->mux_upload_id);
                }
            } catch (MuxException $e) {
                Log::warning('Couldn’t remove a closed studio’s video from Mux', ['video_id' => $video->id, 'mux_asset_id' => $video->mux_asset_id, 'mux_upload_id' => $video->mux_upload_id, 'error' => $e->getMessage()]);
            }
        }
    }

    /** Housekeeping: check every pending video nobody's looked at lately. Returns how many finished (ready or failed). */
    public function sweep(): int
    {
        if (! $this->mux->configured()) {
            return 0;
        }
        $finished = 0;
        Video::whereIn('status', ['uploading', 'processing'])
            ->where(fn ($q) => $q->whereNull('checked_at')->orWhere('checked_at', '<', now()->subMinutes(self::SWEEP_AFTER_MINUTES)))
            ->eachById(function (Video $video) use (&$finished) {
                try {
                    $finished += $this->refresh($video, force: true)->isPending() ? 0 : 1;
                } catch (MuxException $e) {
                    report($e);
                }
            });

        return $finished;
    }

    private function create(Studio $studio, User $by, array $data, string $source, string $status): Video
    {
        if (! $this->enabled()) {
            throw new VideoException('Video isn’t switched on for Loft yet.');
        }
        $limit = $this->minutesLimit();
        if ($this->minutesUsed($studio) >= $limit) {
            throw new VideoException("Your studio has used its {$limit} minutes of video. Delete a video to make room.");
        }

        return $studio->videos()->create([
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'access' => $data['access'] ?? 'members',
            'published' => (bool) ($data['published'] ?? false),
            'source' => $source,
            'status' => $status,
            'created_by_user_id' => $by->id,
        ]);
    }
}
