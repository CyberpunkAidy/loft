<?php

namespace App\Services;

use App\Models\Entitlement;
use App\Models\Plan;
use App\Models\Studio;
use App\Models\User;
use App\Models\Video;
use App\Models\VideoView;
use App\Services\Mux\MuxClient;
use Illuminate\Support\Collection;

/**
 * Who can watch a studio's videos, and the signed tokens that play them (roadmap step 10).
 * - "members" videos: anyone with an account at the studio.
 * - "plan" videos: members with a current plan that includes videos (Plan::includes_videos): a
 *   membership or season that's active, or a pack that hasn't expired or run out.
 * - Staff can watch everything, published or not.
 * Tokens last a few hours (loft.mux.token_hours) and expire on the hour, so a page's thumbnails
 * stay the same, and cacheable, for the hour.
 */
class VideoLibrary
{
    public function __construct(private readonly MuxClient $mux, private readonly DomainEvents $events) {}

    /** Why they can't watch it, or null if they can: sign_in, not_a_member or needs_plan. */
    public function lockReason(?User $user, Studio $studio, Video $video, ?bool $hasPlan = null): ?string
    {
        return match (true) {
            $user === null => 'sign_in',
            $user->hasRoleIn($studio, 'instructor') => null,
            ! $user->hasRoleIn($studio) => 'not_a_member',
            $video->needsPlan() && ! ($hasPlan ?? $this->hasVideoPlan($user, $studio)) => 'needs_plan',
            default => null,
        };
    }

    /** @return array<int, ?string> each video's lock reason, by ID */
    public function lockReasons(?User $user, Studio $studio, Collection $videos): array
    {
        $hasPlan = $user && $videos->contains(fn (Video $v) => $v->needsPlan()) ? $this->hasVideoPlan($user, $studio) : false;

        return $videos->mapWithKeys(fn (Video $v) => [$v->id => $this->lockReason($user, $studio, $v, $hasPlan)])->all();
    }

    public function hasVideoPlan(User $user, Studio $studio): bool
    {
        return Entitlement::where('studio_id', $studio->id)->where('user_id', $user->id)->where('status', 'active')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->where(fn ($q) => $q->where('kind', '!=', 'credits')->orWhere('credits_remaining', '>', 0))
            ->whereHas('plan', fn ($q) => $q->where('includes_videos', true))
            ->exists();
    }

    /** @return Collection<int, Plan> the plans on sale that include videos */
    public function plans(Studio $studio): Collection
    {
        return $studio->plans()->where('active', true)->where('includes_videos', true)->with(['studio', 'season'])->orderBy('sort')->orderBy('price_cents')->get();
    }

    public function lockMessage(string $reason, Studio $studio): string
    {
        return match ($reason) {
            'sign_in' => 'Sign in to watch.',
            'not_a_member' => "Join {$studio->name} to watch.",
            default => 'This video comes with a plan that includes videos.',
        };
    }

    /**
     * What a player needs: the playback ID and its tokens. Records that they watched it ($record),
     * once a day per video, for view counts and their timeline.
     *
     * @return array{playback_id: string, playback_token: string, thumbnail_token: string, storyboard_token: string, stream_url: string, expires_at: int}
     */
    public function playback(User $user, Video $video, bool $record = true): array
    {
        $id = (string) $video->mux_playback_id;
        $expires = $this->expiry();
        if ($record) {
            $this->recordView($user, $video);
        }
        $token = $this->mux->token($id, 'v', $expires);

        return [
            'playback_id' => $id,
            'playback_token' => $token,
            'thumbnail_token' => $this->mux->token($id, 't', $expires),
            'storyboard_token' => $this->mux->token($id, 's', $expires),
            'stream_url' => "https://stream.mux.com/{$id}.m3u8?token={$token}",
            'expires_at' => $expires,
        ];
    }

    /** A still from the video, $width pixels wide (signed, so it works without a member's sign-in). */
    public function thumbnailUrl(Video $video, int $width = 640): ?string
    {
        if (! $video->isReady() || ! $this->mux->canSign()) {
            return null;
        }
        $id = $video->mux_playback_id;

        return "https://image.mux.com/{$id}/thumbnail.webp?token=".$this->mux->token($id, 't', $this->expiry(), ['width' => $width]);
    }

    private function recordView(User $user, Video $video): void
    {
        $studio = $video->studio;
        $added = VideoView::query()->insertOrIgnore([
            'studio_id' => $studio->id, 'video_id' => $video->id, 'user_id' => $user->id,
            'viewed_on' => $studio->localNow()->toDateString(), 'created_at' => now(),
        ]);
        if ($added) {
            $this->events->record('video.watched', $studio, ['video_id' => $video->id, 'title' => $video->title], $user->id);
        }
    }

    /** token_hours from the end of this hour. */
    private function expiry(): int
    {
        return (intdiv(time(), 3600) + 1) * 3600 + max(1, (int) config('loft.mux.token_hours')) * 3600;
    }
}
