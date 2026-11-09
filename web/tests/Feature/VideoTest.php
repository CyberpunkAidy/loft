<?php

namespace Tests\Feature;

use App\Models\DomainEvent;
use App\Models\Studio;
use App\Models\User;
use App\Models\Video;
use App\Models\VideoView;
use App\Services\Videos;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** On-demand video on Mux (roadmap step 10): uploads, imports, following them, who can watch, signed playback. */
class VideoTest extends TestCase
{
    private static ?array $keys = null;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        if (self::$keys === null) {
            $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
            openssl_pkey_export($key, $private);
            self::$keys = ['private' => $private, 'public' => openssl_pkey_get_details($key)['key']];
        }
        config(['loft.mux' => [
            'token_id' => 'mux_id', 'token_secret' => 'mux_secret',
            'signing_key_id' => 'sk_1', 'signing_key' => base64_encode(self::$keys['private']),
            'studio_minutes' => 60, 'token_hours' => 6,
        ]]);
    }

    /** Fake api.mux.com: $routes maps a path after /video/v1/ to a response (or sequence). */
    private function fakeMux(array $routes = []): void
    {
        Http::preventStrayRequests();
        Http::fake(collect($routes)->mapWithKeys(fn ($response, $path) => ['api.mux.com/video/v1/'.$path => $response])->all());
    }

    private function data(array $data, int $status = 200): PromiseInterface
    {
        return Http::response(['data' => $data], $status);
    }

    /** The JWT's claims, after checking it's signed with our key. */
    private function claims(string $token): array
    {
        [$header, $claims, $signature] = explode('.', $token);
        $decode = fn (string $s) => base64_decode(strtr($s, '-_', '+/'));
        $this->assertSame(1, openssl_verify("{$header}.{$claims}", $decode($signature), self::$keys['public'], OPENSSL_ALGO_SHA256), 'signed with the signing key');
        $this->assertSame(['alg' => 'RS256', 'typ' => 'JWT', 'kid' => 'sk_1'], json_decode($decode($header), true));

        return json_decode($decode($claims), true);
    }

    private function video(Studio $studio, array $attributes = []): Video
    {
        static $n = 0;
        $n++;

        return $studio->videos()->create($attributes + [
            'title' => "Video {$n}", 'access' => 'members', 'published' => true, 'published_at' => now()->subDays($n),
            'status' => 'ready', 'mux_asset_id' => "as_{$n}", 'mux_playback_id' => "pb_{$n}", 'duration_seconds' => 600, 'aspect_ratio' => '16:9',
        ]);
    }

    private function api(string $method, string $uri, ?string $token, array $data = []): TestResponse
    {
        $this->app['auth']->forgetGuards();
        $response = $this->json($method, '/api/v1/'.$uri, $data, $token ? ['Authorization' => "Bearer {$token}"] : []);
        $this->app['auth']->forgetGuards();
        $this->app['auth']->setDefaultDriver('web');

        return $response;
    }

    public function test_staff_upload_a_video_and_follow_it_until_it_is_ready(): void
    {
        $studio = $this->studio();
        $this->actingAs($this->userIn($studio, 'owner'));
        $this->fakeMux([
            'uploads' => $this->data(['id' => 'up_1', 'url' => 'https://storage.googleapis.com/video-uploads/up_1?sig=x', 'status' => 'waiting'], 201),
            'uploads/up_1' => Http::sequence()
                ->push(['data' => ['id' => 'up_1', 'status' => 'waiting']])
                ->push(['data' => ['id' => 'up_1', 'status' => 'asset_created', 'asset_id' => 'as_1']]),
            'assets/as_1' => Http::sequence()
                ->push(['data' => ['id' => 'as_1', 'status' => 'preparing']])
                ->push(['data' => ['id' => 'as_1', 'status' => 'ready', 'duration' => 125.4, 'aspect_ratio' => '9:16',
                    'playback_ids' => [['id' => 'pb_open', 'policy' => 'public'], ['id' => 'pb_1', 'policy' => 'signed']]]]),
        ]);
        $base = "/{$studio->slug}/admin/videos";

        $this->get($base)->assertOk()->assertSee('Add a video')->assertSee('of 60 minutes used', false)->assertSee('No videos yet.')
            ->assertSee('No plan includes videos yet');
        $fields = ['title' => 'Silks: climbs', 'description' => 'Warm up first.', 'access' => 'members', 'published' => '1', 'source' => 'upload'];

        // Without JavaScript, a file can't be sent.
        $this->post($base, $fields)->assertRedirect()->assertSessionHas('error', 'Uploading a file needs JavaScript. Paste a link to the file instead.');

        // The browser gets a one-time address at Mux; the file never comes through Loft.
        $response = $this->postJson($base, $fields)->assertCreated();
        $video = Video::sole();
        $response->assertExactJson([
            'id' => $video->id,
            'upload_url' => 'https://storage.googleapis.com/video-uploads/up_1?sig=x',
            'status_url' => url("{$base}/{$video->id}/status"),
            'edit_url' => url("{$base}/{$video->id}/edit"),
        ]);
        $this->assertSame(['Silks: climbs', 'uploading', 'up_1', true, null], [$video->title, $video->status, $video->mux_upload_id, $video->published, $video->published_at]);
        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r->url() === 'https://api.mux.com/video/v1/uploads'
            && $r->header('Authorization') === ['Basic '.base64_encode('mux_id:mux_secret')]
            && $r['cors_origin'] === url('/') && $r['timeout'] === 21600
            && $r['new_asset_settings'] === ['playback_policies' => ['signed'], 'video_quality' => 'basic', 'passthrough' => "loft:video:{$video->id}"]);

        // The uploader waits: Mux hasn't got the file, then is encoding it, then it's ready.
        $this->getJson("{$base}/{$video->id}/status")->assertExactJson(['status' => 'uploading', 'label' => 'Waiting for the file', 'error' => null, 'ready' => false]);
        $this->getJson("{$base}/{$video->id}/status")->assertJsonPath('status', 'uploading');
        Http::assertSentCount(2); // asked once: at most every 15 seconds
        $this->travel(16)->seconds();
        $this->getJson("{$base}/{$video->id}/status")->assertJsonPath('status', 'processing')->assertJsonPath('label', 'Processing');
        $this->assertSame('as_1', $video->fresh()->mux_asset_id);
        $this->get("{$base}/{$video->id}/edit")->assertOk()->assertSee('Mux is getting it ready to stream')->assertSee('<meta http-equiv="refresh" content="15">', false);
        $this->travel(16)->seconds();
        $this->getJson("{$base}/{$video->id}/status")->assertExactJson(['status' => 'ready', 'label' => 'Ready', 'error' => null, 'ready' => true]);
        $video->refresh();
        $this->assertSame(['ready', 'pb_1', 125, '9:16'], [$video->status, $video->mux_playback_id, $video->duration_seconds, $video->aspect_ratio]);
        $this->assertNotNull($video->published_at);

        // Staff preview it with signed tokens; previews aren't views.
        $page = $this->get("{$base}/{$video->id}/edit")->assertOk()->assertSee('playback-id="pb_1"', false)->assertSee('aspect-ratio: 9 / 16', false)
            ->assertSee('cdn.jsdelivr.net/npm/@mux/mux-player@3.14.1/dist/mux-player.js', false)->assertSee('disable-cookies', false)
            ->assertSee('See it in the library')->assertDontSee('http-equiv="refresh"', false);
        preg_match('/playback-token="([^"]+)"/', $page->getContent(), $m);
        $claims = $this->claims($m[1]);
        $this->assertSame(['pb_1', 'v', 'sk_1'], [$claims['sub'], $claims['aud'], $claims['kid']]);
        $this->assertGreaterThan(now()->addHours(6)->timestamp, $claims['exp']);
        $this->assertLessThanOrEqual(now()->addHours(7)->timestamp, $claims['exp']);
        $this->assertSame(0, VideoView::count());

        $this->get($base)->assertSee('In the library')->assertSee('2:05')->assertSee('<strong>3</strong> of 60 minutes used', false);
        $this->get("/{$studio->slug}")->assertSee('>Videos</a>', false);
    }

    public function test_videos_from_a_link_and_what_goes_wrong(): void
    {
        $studio = $this->studio();
        $this->actingAs($this->userIn($studio, 'owner'));
        $base = "/{$studio->slug}/admin/videos";
        $fields = ['title' => 'Lyra basics', 'access' => 'members', 'source' => 'url'];

        $this->fakeMux([
            'assets' => Http::sequence()
                ->push(['error' => ['type' => 'invalid_parameters', 'messages' => ['Input URL is not a valid URL.']]], 400)
                ->push(['data' => ['id' => 'as_2', 'status' => 'preparing']], 201),
            'assets/as_2' => $this->data(['id' => 'as_2', 'status' => 'errored', 'errors' => ['type' => 'invalid_input', 'messages' => ['The file has no video track.']]]),
        ]);
        $this->post($base, $fields + ['url' => 'http://files.example.com/lyra.mp4'])->assertSessionHasErrors('url');
        $this->post($base, $fields)->assertSessionHasErrors(['url' => 'Paste the link to the video file.']);
        $this->post($base, $fields + ['url' => 'https://files.example.com/lyra.mp4'])
            ->assertSessionHasErrors(['url' => 'Mux didn’t accept that: Input URL is not a valid URL.']);
        $this->assertSame(0, Video::count(), 'nothing left behind');

        $this->post($base, $fields + ['url' => 'https://files.example.com/lyra.mp4'])->assertRedirect()
            ->assertSessionHas('success', 'Mux is fetching the video. It’ll be ready in a few minutes; this page shows when.');
        $video = Video::sole();
        $this->assertSame(['url', 'processing', 'as_2', false], [$video->source, $video->status, $video->mux_asset_id, $video->published]);
        Http::assertSent(fn (Request $r) => $r->url() === 'https://api.mux.com/video/v1/assets' && $r['inputs'] === [['url' => 'https://files.example.com/lyra.mp4']]
            && $r['playback_policies'] === ['signed'] && $r['video_quality'] === 'basic');
        $this->travel(20)->seconds();
        $this->get("{$base}/{$video->id}/edit")->assertOk()->assertSee('That didn’t work.')->assertSee('The file has no video track.');
        $this->assertSame('errored', $video->fresh()->status);
        $this->get($base)->assertSee('Didn’t work');

        // An upload that never arrived.
        $this->fakeMux(['uploads/up_9' => $this->data(['id' => 'up_9', 'status' => 'timed_out'])]);
        $lost = $this->video($studio, ['status' => 'uploading', 'mux_upload_id' => 'up_9', 'mux_asset_id' => null, 'mux_playback_id' => null, 'duration_seconds' => null]);
        $this->getJson("{$base}/{$lost->id}/status")->assertJsonPath('status', 'errored')->assertJsonPath('error', 'The file never arrived. Try uploading it again.');

        // Each studio's minutes: failed videos don't count.
        $this->video($studio, ['duration_seconds' => 3600]);
        $this->video($studio, ['status' => 'errored', 'duration_seconds' => 600]);
        $this->assertSame(60, app(Videos::class)->minutesUsed($studio));
        $this->get($base)->assertSee('Your studio has used its 60 minutes. Delete a video to make room.')->assertDontSee('Add video</button>', false);
        $this->postJson($base, ['source' => 'upload'] + $fields)->assertStatus(422)->assertJsonPath('errors.title.0', 'Your studio has used its 60 minutes of video. Delete a video to make room.');

        // Without Mux keys, nothing can be added.
        config(['loft.mux.token_id' => null]);
        $this->get($base)->assertSee('Video isn’t switched on for Loft yet.');
        $this->post($base, $fields + ['url' => 'https://files.example.com/lyra.mp4'])->assertSessionHasErrors(['title' => 'Video isn’t switched on for Loft yet.']);
    }

    public function test_staff_edit_and_delete_videos(): void
    {
        $studio = $this->studio();
        $this->actingAs($this->userIn($studio, 'admin'));
        $base = "/{$studio->slug}/admin/videos";
        $video = $this->video($studio, ['published' => false, 'published_at' => null]);

        $this->patch("{$base}/{$video->id}", ['title' => 'Flexibility flow', 'description' => 'Hips and hamstrings.', 'access' => 'plan', 'published' => '1'])
            ->assertSessionHas('success', 'Saved. It’s in the library.');
        $video->refresh();
        $this->assertSame(['Flexibility flow', 'plan', true], [$video->title, $video->access, $video->published]);
        $this->assertNotNull($video->published_at);
        $this->patch("{$base}/{$video->id}", ['title' => 'Flexibility flow', 'access' => 'nobody'])->assertSessionHasErrors('access');
        $this->patch("{$base}/{$video->id}", ['title' => 'Flexibility flow', 'access' => 'plan'])->assertSessionHas('success', 'Saved. It isn’t in the library.');
        $pending = $this->video($studio, ['status' => 'processing', 'mux_playback_id' => null, 'published' => false, 'published_at' => null, 'checked_at' => now()]);
        $this->patch("{$base}/{$pending->id}", ['title' => 'Soon', 'access' => 'members', 'published' => '1'])->assertSessionHas('success', 'Saved. It goes in the library when it’s ready.');
        $this->assertNull($pending->fresh()->published_at);

        // Deleting removes it from Mux; if Mux fails, it stays.
        $this->fakeMux(["assets/{$video->mux_asset_id}" => Http::sequence()->push(['error' => ['messages' => ['Service unavailable']]], 503)->push(null, 204)]);
        $this->delete("{$base}/{$video->id}")->assertSessionHas('error', 'Mux didn’t delete it, so it’s still here: Service unavailable Try again in a minute.');
        $this->assertNotNull($video->fresh());
        $this->delete("{$base}/{$video->id}")->assertRedirect($base)->assertSessionHas('success', '“Flexibility flow” is deleted.');
        $this->assertNull($video->fresh());
        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && $r->url() === "https://api.mux.com/video/v1/assets/{$video->mux_asset_id}");

        // One still uploading: if the file arrived since we last asked, its asset goes too; if not, the upload is cancelled.
        $this->fakeMux([
            'uploads/up_a' => $this->data(['id' => 'up_a', 'status' => 'asset_created', 'asset_id' => 'as_a']),
            'assets/as_a' => Http::response(null, 204),
            'uploads/up_b' => $this->data(['id' => 'up_b', 'status' => 'waiting']),
            'uploads/up_b/cancel' => $this->data(['id' => 'up_b', 'status' => 'cancelled']),
        ]);
        $a = $this->video($studio, ['status' => 'uploading', 'mux_upload_id' => 'up_a', 'mux_asset_id' => null, 'mux_playback_id' => null, 'checked_at' => now()]);
        $b = $this->video($studio, ['status' => 'uploading', 'mux_upload_id' => 'up_b', 'mux_asset_id' => null, 'mux_playback_id' => null, 'checked_at' => now()]);
        $this->delete("{$base}/{$a->id}")->assertRedirect($base);
        $this->delete("{$base}/{$b->id}")->assertRedirect($base);
        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/assets/as_a'));
        Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && str_ends_with($r->url(), '/uploads/up_b/cancel'));
        $this->assertSame([$pending->id], Video::pluck('id')->all());

        // Already gone from Mux: deleted here too.
        $this->fakeMux(['assets/as_gone' => Http::response(['error' => ['messages' => ['Not found']]], 404)]);
        $gone = $this->video($studio, ['mux_asset_id' => 'as_gone']);
        $this->delete("{$base}/{$gone->id}")->assertRedirect($base)->assertSessionHas('success');
        $this->assertNull($gone->fresh());

        // Instructors don't manage videos, and another studio's are out of reach.
        $this->actingAs($this->userIn($studio, 'instructor'));
        $this->get($base)->assertForbidden();
        $other = $this->studio();
        $this->actingAs($this->userIn($other, 'owner'));
        $this->get("/{$other->slug}/admin/videos/{$pending->id}/edit")->assertNotFound();
    }

    public function test_who_can_watch_what(): void
    {
        $studio = $this->studio(['name' => 'Skyline']);
        $open = $this->video($studio, ['title' => 'Warm-up', 'access' => 'members']);
        $planned = $this->video($studio, ['title' => 'Drops', 'access' => 'plan']);
        $hidden = $this->video($studio, ['title' => 'Draft class', 'published' => false]);
        $coming = $this->video($studio, ['title' => 'Coming soon', 'status' => 'processing', 'mux_playback_id' => null]);
        $plan = $this->plan($studio, ['name' => 'Unlimited + videos', 'includes_videos' => true, 'stripe_price_id' => 'price_1']);
        $this->plan($studio, ['name' => 'Classes only']);

        // Anyone can browse; watching needs an account.
        $library = $this->get("/{$studio->slug}/videos")->assertOk()->assertSee('Warm-up')->assertSee('Drops')
            ->assertDontSee('Draft class')->assertDontSee('Coming soon')->assertSee('Sign in to watch')->assertSee('>Videos</a>', false);
        preg_match('#image\.mux\.com/pb_\d+/thumbnail\.webp\?token=([^"]+)#', $library->getContent(), $m);
        $this->assertSame(['t', 640], [$this->claims($m[1])['aud'], $this->claims($m[1])['width']]);
        $this->get("/{$studio->slug}/videos/{$open->id}")->assertOk()->assertSee('Sign in to watch.')->assertDontSee('<mux-player', false);
        $this->assertSame(url("/{$studio->slug}/videos/{$open->id}"), session('url.intended'));
        $this->get("/{$studio->slug}/videos/{$hidden->id}")->assertNotFound();
        $this->get("/{$studio->slug}/videos/{$coming->id}")->assertNotFound();

        $this->actingAs(User::factory()->create());
        $this->get("/{$studio->slug}/videos/{$open->id}")->assertSee('Join Skyline to watch.')->assertDontSee('<mux-player', false);

        // A member: "members" videos play; "plan" ones need a plan that includes videos.
        $member = $this->userIn($studio, 'member');
        $this->actingAs($member);
        $this->get("/{$studio->slug}/videos")->assertSee('With a plan')->assertSee('come with Unlimited + videos', false);
        $this->get("/{$studio->slug}/videos/{$open->id}")->assertOk()->assertSee('playback-id="'.$open->mux_playback_id.'"', false);
        $this->get("/{$studio->slug}/videos/{$open->id}")->assertOk();
        $this->assertSame(1, VideoView::where('video_id', $open->id)->count(), 'once a day');
        $this->assertSame(['video.watched'], DomainEvent::where('user_id', $member->id)->pluck('type')->all());
        $this->travel(1)->days();
        $this->get("/{$studio->slug}/videos/{$open->id}")->assertOk();
        $this->assertSame(2, VideoView::where('video_id', $open->id)->count());
        $this->get("/{$studio->slug}/videos/{$planned->id}")->assertOk()->assertSee('This video comes with a plan that includes videos.')
            ->assertSee('Plans that include videos')->assertSee('Unlimited + videos')->assertSee('Includes videos')->assertDontSee('Classes only')->assertDontSee('<mux-player', false);

        // Packs count while they have credits and haven't expired; memberships while active.
        $pack = $this->credits($studio, $member, 0, ['plan_id' => $plan->id]);
        $this->get("/{$studio->slug}/videos/{$planned->id}")->assertDontSee('<mux-player', false);
        $pack->update(['credits_remaining' => 2]);
        $this->get("/{$studio->slug}/videos/{$planned->id}")->assertSee('<mux-player', false);
        $pack->update(['expires_at' => now()->subDay()]);
        $this->get("/{$studio->slug}/videos/{$planned->id}")->assertDontSee('<mux-player', false);
        $membership = $this->membership($studio, $member, null, ['plan_id' => $plan->id, 'status' => 'past_due']);
        $this->get("/{$studio->slug}/videos/{$planned->id}")->assertDontSee('<mux-player', false);
        $membership->update(['status' => 'active']);
        $this->get("/{$studio->slug}/videos/{$planned->id}")->assertSee('<mux-player', false);
        $plan->update(['includes_videos' => false]);
        $this->get("/{$studio->slug}/videos/{$planned->id}")->assertDontSee('<mux-player', false);

        // Staff can watch anything ready, published or not, without counting as views.
        $this->actingAs($this->userIn($studio, 'instructor'));
        $this->get("/{$studio->slug}/videos/{$hidden->id}")->assertOk()->assertSee('Only staff can see this')->assertSee('<mux-player', false);
        $this->get("/{$studio->slug}/videos/{$coming->id}")->assertNotFound();
        $this->get("/{$studio->slug}/videos/{$planned->id}")->assertSee('<mux-player', false); // no plan needed
        $this->assertSame(0, VideoView::where('video_id', $hidden->id)->count());

        // The member's timeline.
        $this->actingAs($this->userIn($studio, 'owner'));
        $this->get("/{$studio->slug}/admin/members/{$member->id}")->assertOk()->assertSee('Watched “Warm-up”', false);
        $this->get("/{$studio->slug}/admin/videos")->assertOk()->assertSee('Warm-up');
        $this->get("/{$studio->slug}/admin/videos/{$open->id}/edit")->assertSee('2 views by 1 person');

        // Another studio's video isn't here.
        $elsewhere = $this->video($this->studio());
        $this->get("/{$studio->slug}/videos/{$elsewhere->id}")->assertNotFound();
    }

    public function test_the_api_lists_videos_and_hands_out_playback_tokens(): void
    {
        $studio = $this->studio();
        $open = $this->video($studio, ['title' => 'Warm-up']);
        $planned = $this->video($studio, ['title' => 'Drops', 'access' => 'plan']);
        $hidden = $this->video($studio, ['published' => false]);
        $plan = $this->plan($studio, ['name' => 'Videos', 'includes_videos' => true]);
        $member = $this->userIn($studio);
        $token = $member->createToken('Phone', ['member'])->plainTextToken;

        $this->api('GET', "studios/{$studio->slug}/videos", null)->assertOk()
            ->assertJsonCount(2, 'data')->assertJsonPath('data.0.title', 'Warm-up')->assertJsonPath('data.0.can_watch', false)
            ->assertJsonPath('data.0.locked_reason', 'sign_in')->assertJsonPath('data.0.duration_label', '10:00')
            ->assertJsonPath('meta.plans.0.name', 'Videos')->assertJsonPath('meta.plans.0.includes_videos', true);
        $list = $this->api('GET', "studios/{$studio->slug}/videos", $token)->assertOk()
            ->assertJsonPath('data.0.can_watch', true)->assertJsonPath('data.0.locked_reason', null)
            ->assertJsonPath('data.1.can_watch', false)->assertJsonPath('data.1.locked_reason', 'needs_plan');
        $this->assertStringStartsWith("https://image.mux.com/{$open->mux_playback_id}/thumbnail.webp?token=", $list->json('data.0.thumbnail_url'));

        $playback = $this->api('POST', "studios/{$studio->slug}/videos/{$open->id}/playback", $token)->assertOk()
            ->assertJsonPath('data.playback_id', $open->mux_playback_id)->json('data');
        $this->assertSame(['sub' => $open->mux_playback_id, 'aud' => 'v'], array_intersect_key($this->claims($playback['tokens']['playback']), ['sub' => 1, 'aud' => 1]));
        $this->assertSame('t', $this->claims($playback['tokens']['thumbnail'])['aud']);
        $this->assertSame('s', $this->claims($playback['tokens']['storyboard'])['aud']);
        $this->assertSame("https://stream.mux.com/{$open->mux_playback_id}.m3u8?token={$playback['tokens']['playback']}", $playback['stream_url']);
        $this->assertSame(gmdate('Y-m-d\TH:i:s\Z', $this->claims($playback['tokens']['playback'])['exp']), $playback['expires_at']);
        $this->assertSame(1, VideoView::where('user_id', $member->id)->count());

        $this->api('POST', "studios/{$studio->slug}/videos/{$planned->id}/playback", $token)->assertStatus(422)
            ->assertJsonPath('error.code', 'needs_plan')->assertJsonPath('error.plans.0.id', $plan->id);
        $this->api('POST', "studios/{$studio->slug}/videos/{$hidden->id}/playback", $token)->assertNotFound()->assertJsonPath('error.code', 'not_found');
        $this->api('POST', "studios/{$studio->slug}/videos/{$open->id}/playback", null)->assertUnauthorized();
        $outsider = User::factory()->create()->createToken('Phone', ['member'])->plainTextToken;
        $this->api('POST', "studios/{$studio->slug}/videos/{$open->id}/playback", $outsider)->assertForbidden()->assertJsonPath('error.code', 'not_a_member');
    }

    public function test_housekeeping_follows_videos_nobody_is_watching(): void
    {
        $studio = $this->studio();
        $waiting = $this->video($studio, ['status' => 'processing', 'mux_asset_id' => 'as_w', 'mux_playback_id' => null, 'duration_seconds' => null, 'checked_at' => null]);
        $recent = $this->video($studio, ['status' => 'processing', 'mux_asset_id' => 'as_r', 'mux_playback_id' => null, 'checked_at' => now()]);
        $this->fakeMux(['assets/as_w' => $this->data(['id' => 'as_w', 'status' => 'ready', 'duration' => 30, 'playback_ids' => [['id' => 'pb_w', 'policy' => 'signed']]])]);

        $this->artisan('loft:housekeeping')->expectsOutputToContain('Videos finished: 1')->assertSuccessful();
        $this->assertSame(['ready', 'pb_w', 30], [$waiting->fresh()->status, $waiting->fresh()->mux_playback_id, $waiting->fresh()->duration_seconds]);
        $this->assertSame('processing', $recent->fresh()->status, 'checked a moment ago, so left alone');
        Http::assertSentCount(1);
    }

    public function test_closing_a_studio_removes_its_videos_from_mux(): void
    {
        $studio = $this->studio();
        $owner = $this->userIn($studio, 'owner');
        $this->video($studio, ['mux_asset_id' => 'as_close']);
        $this->video($studio, ['mux_asset_id' => 'as_broken']);
        $this->video($studio, ['status' => 'uploading', 'mux_upload_id' => 'up_close', 'mux_asset_id' => null, 'mux_playback_id' => null]);
        $kept = $this->video($this->studio(), ['mux_asset_id' => 'as_other']);
        $this->fakeMux([
            'assets/as_close' => Http::response(null, 204),
            'assets/as_broken' => Http::response(['error' => ['messages' => ['Service unavailable']]], 503),
            'uploads/up_close/cancel' => $this->data(['id' => 'up_close', 'status' => 'cancelled']),
        ]);
        Log::spy();

        $this->actingAs($owner)->delete("/{$studio->slug}/account", ['delete_password' => 'password', 'studios' => [$studio->id => 'close']])->assertRedirect('/');

        $this->assertSame([$kept->id], Video::pluck('id')->all());
        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/assets/as_close'));
        Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && str_ends_with($r->url(), '/uploads/up_close/cancel'));
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'as_other'));
        Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context) => str_contains($message, 'closed studio') && $context['mux_asset_id'] === 'as_broken')->once();
    }

    public function test_plans_can_include_the_video_library(): void
    {
        $studio = $this->studio();
        $this->actingAs($this->userIn($studio, 'owner'));
        $this->get("/{$studio->slug}/admin/plans/create")->assertOk()->assertSee('Includes the video library');
        $this->post("/{$studio->slug}/admin/plans", ['name' => 'Online', 'kind' => 'subscription', 'price' => '30', 'active' => '1', 'includes_videos' => '1', 'scope_all' => '1'])
            ->assertRedirect();
        $plan = $studio->plans()->sole();
        $this->assertTrue($plan->includes_videos);
        $this->get("/{$studio->slug}/plans")->assertSee('Includes videos');
        $this->api('GET', "studios/{$studio->slug}/plans", null)->assertJsonPath('data.0.includes_videos', true);
    }
}
