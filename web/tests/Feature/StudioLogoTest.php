<?php

namespace Tests\Feature;

use App\Mail\StudioMessage;
use App\Models\Studio;
use App\Models\StudioLogo;
use App\Services\StudioLogos;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/** Studio logos (decision 41): what's accepted, how it's redrawn and served, and everywhere it shows. */
class StudioLogoTest extends TestCase
{
    /** An image drawn with GD: a coloured band on a transparent (or white, for JPEG) background. */
    private function image(int $width, int $height, string $type = 'png'): string
    {
        $im = imagecreatetruecolor($width, $height);
        imagealphablending($im, false);
        imagesavealpha($im, true);
        imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
        imagefilledrectangle($im, (int) ($width / 4), (int) ($height / 4), (int) ($width * 3 / 4), (int) ($height * 3 / 4), imagecolorallocatealpha($im, 15, 118, 110, 0));
        ob_start();
        match ($type) {
            'jpg' => imagejpeg($im, null, 90),
            'webp' => imagewebp($im, null, 90),
            default => imagepng($im),
        };

        return (string) ob_get_clean();
    }

    /** Just a PNG header claiming $width × $height: enough for a size check, nothing to decode. */
    private function pngHeader(int $width, int $height): string
    {
        $ihdr = 'IHDR'.pack('NNCCCCC', $width, $height, 8, 6, 0, 0, 0);

        return "\x89PNG\r\n\x1a\n".pack('N', 13).$ihdr.pack('N', crc32($ihdr));
    }

    private function upload(Studio $studio, string $bytes, string $name = 'logo.png')
    {
        return $this->post("/{$studio->slug}/admin/settings/logo", ['logo' => UploadedFile::fake()->createWithContent($name, $bytes)]);
    }

    public function test_a_wide_png_is_redrawn_shrunk_served_for_good_and_replaces_the_name(): void
    {
        $studio = $this->studio(['name' => 'Skyline Aerial']);
        $this->actingAs($this->userIn($studio, 'owner'));
        // A PNG with a text chunk tucked in: redrawing it keeps only the pixels.
        $png = $this->image(1920, 480);
        $chunk = 'tEXtComment'."\0".'hi';
        $png = substr($png, 0, 33).pack('N', strlen($chunk) - 4).$chunk.pack('N', crc32($chunk)).substr($png, 33);

        $this->upload($studio, $png)->assertSessionHasNoErrors()->assertSessionHas('success', 'Logo saved. It now shows on your studio’s pages and emails.');
        $studio->refresh();
        $this->assertSame(['png', 960, 240], [$studio->logo_type, $studio->logo_width, $studio->logo_height]);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{12}$/', $studio->logo_hash);
        $this->assertStringEndsWith("/{$studio->slug}/logo/{$studio->logo_hash}.png", $studio->logoUrl());

        auth()->logout();
        $response = $this->get($studio->logoUrl())->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('ETag', '"'.$studio->logo_hash.'"');
        $this->assertStringContainsString('immutable', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('public', $response->headers->get('Cache-Control'));
        $this->assertSame([], $response->headers->getCookies(), 'no session or cookies on the logo');
        $served = $response->getContent();
        $this->assertStringNotContainsString('tEXt', $served);
        $this->assertSame([960, 240, IMAGETYPE_PNG], array_slice(getimagesizefromstring($served), 0, 3));
        $im = imagecreatefromstring($served);
        $this->assertSame(127, (imagecolorat($im, 5, 5) >> 24) & 0x7F, 'the transparent corner stays transparent');
        $this->assertSame(0, (imagecolorat($im, 480, 120) >> 24) & 0x7F, 'the band is solid');

        // A wide logo stands for the name on the studio's pages (as its alt text).
        $this->get("/{$studio->slug}")->assertOk()
            ->assertSee('<img class="brand-logo" src="'.$studio->logoUrl().'" width="144" height="36" alt="Skyline Aerial">', false)
            ->assertDontSee('class="brand-mark"', false)
            ->assertDontSee('<span class="brand-name">', false);
    }

    public function test_a_square_jpeg_stays_a_jpeg_and_sits_beside_the_name(): void
    {
        $studio = $this->studio(['name' => 'Skyline Aerial']);
        $this->actingAs($this->userIn($studio, 'owner'));

        $this->upload($studio, $this->image(600, 600, 'jpg'), 'logo.jpg')->assertSessionHasNoErrors();
        $studio->refresh();
        $this->assertSame(['jpg', 240, 240], [$studio->logo_type, $studio->logo_width, $studio->logo_height]);
        $this->assertSame([], $this->get($studio->logoUrl())->assertOk()->assertHeader('Content-Type', 'image/jpeg')->headers->getCookies(), 'signed in, still no cookies');

        $this->get("/{$studio->slug}")->assertOk()
            ->assertSee('<img class="brand-logo" src="'.$studio->logoUrl().'" width="36" height="36" alt="">', false)
            ->assertSee('<span class="brand-name">Skyline Aerial</span>', false);
        $this->get("/{$studio->slug}/admin")->assertOk()->assertSee('width="32" height="32" alt="">', false);
        $this->get("/{$studio->slug}/admin/settings")->assertOk()
            ->assertSee('Replace your logo')
            ->assertSee('Remove logo')
            ->assertSee('This is how your studio’s pages and emails show it.', false);
    }

    public function test_webp_is_accepted_and_small_logos_keep_their_size(): void
    {
        $studio = $this->studio();
        $this->actingAs($this->userIn($studio, 'admin'));

        $this->upload($studio, $this->image(300, 100, 'webp'), 'logo.webp')->assertSessionHasNoErrors();
        $this->assertSame(['png', 300, 100], [$studio->refresh()->logo_type, $studio->logo_width, $studio->logo_height]);
        $this->assertSame([300, 100, IMAGETYPE_PNG], array_slice(getimagesizefromstring(app(StudioLogos::class)->bytes($studio)), 0, 3));
    }

    public function test_svg_and_other_files_too_small_or_too_big_are_refused(): void
    {
        $studio = $this->studio();
        $this->actingAs($this->userIn($studio, 'owner'));
        $gif = (function () {
            $im = imagecreatetruecolor(100, 100);
            ob_start();
            imagegif($im);

            return (string) ob_get_clean();
        })();

        $cases = [
            ['<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"><rect width="100" height="100"/></svg>', 'logo.svg', 'Upload your logo as a PNG, JPEG or WebP image.'],
            [$gif, 'logo.gif', 'Upload your logo as a PNG, JPEG or WebP image.'],
            ['not an image at all', 'logo.png', 'Upload your logo as a PNG, JPEG or WebP image.'],
            [$this->image(200, 20), 'tiny.png', 'That image is too small to look sharp. Use one at least 32 pixels tall.'],
            [$this->pngHeader(6000, 6000), 'huge.png', 'That image is too large. Use one under 5,000 × 5,000 pixels.'],
            [$this->pngHeader(400, 400), 'broken.png', 'That image couldn’t be read. Try saving it again as a PNG.'],
            [str_repeat('x', StudioLogos::MAX_BYTES + 1), 'big.png', 'That file is over 2 MB. Try a smaller version of your logo.'],
        ];
        foreach ($cases as [$bytes, $name, $message]) {
            $this->upload($studio, $bytes, $name)->assertSessionHasErrors(['logo' => $message]);
        }
        $this->post("/{$studio->slug}/admin/settings/logo")->assertSessionHasErrors(['logo' => 'Choose an image to upload.']);
        $this->assertFalse($studio->refresh()->hasLogo());
        $this->assertSame(0, StudioLogo::count());
    }

    public function test_replacing_the_logo_changes_its_address_and_old_addresses_lead_to_the_new_one(): void
    {
        $studio = $this->studio();
        $other = $this->studio();
        $this->actingAs($this->userIn($studio, 'owner'));
        $this->upload($studio, $this->image(400, 100));
        $old = $studio->refresh()->logoUrl();

        $this->upload($studio, $this->image(500, 100));
        $new = $studio->refresh()->logoUrl();
        $this->assertNotSame($old, $new);
        $this->assertSame(1, StudioLogo::count(), 'replaced, not added');
        $this->get($old)->assertRedirect($new); // an email sent before the change
        $this->get($new)->assertOk();

        // Another studio's address with this studio's hash: that studio has no logo.
        $this->get(str_replace("/{$studio->slug}/", "/{$other->slug}/", $new))->assertNotFound();
        $this->get("/{$studio->slug}/logo/abc.png")->assertNotFound();
    }

    public function test_removing_the_logo_brings_back_the_initial(): void
    {
        $studio = $this->studio(['name' => 'Skyline Aerial']);
        $this->actingAs($this->userIn($studio, 'owner'));
        $this->upload($studio, $this->image(400, 400));
        $url = $studio->refresh()->logoUrl();

        $this->delete("/{$studio->slug}/admin/settings/logo")->assertSessionHas('success', 'Logo removed.');
        $this->assertFalse($studio->refresh()->hasLogo());
        $this->assertSame(0, StudioLogo::count());
        $this->get($url)->assertNotFound();
        $this->get("/{$studio->slug}")->assertOk()->assertSee('<span class="brand-mark" aria-hidden="true">S</span> Skyline Aerial', false);
        $this->get("/{$studio->slug}/admin/settings")->assertOk()->assertSee('Upload your logo')->assertDontSee('Remove logo');
    }

    public function test_only_admins_and_owners_change_the_logo(): void
    {
        $studio = $this->studio();
        foreach (['instructor', 'member'] as $role) {
            $this->actingAs($this->userIn($studio, $role));
            $this->upload($studio, $this->image(400, 100))->assertForbidden();
            $this->delete("/{$studio->slug}/admin/settings/logo")->assertForbidden();
        }
        $this->assertFalse($studio->refresh()->hasLogo());
    }

    public function test_emails_the_api_the_kiosk_and_my_studios_show_the_logo(): void
    {
        $studio = $this->studio(['name' => 'Skyline Aerial']);
        $member = $this->userIn($studio, 'member');
        app(StudioLogos::class)->store($studio, $this->image(1200, 300));
        $studio->refresh();

        // Emails: a fixed size for email apps that ignore CSS, the name as alt text, no initial badge.
        $html = (new StudioMessage($studio, 'You’re booked!', ['See you in class.'], [], [], null, null, 'booked'))->render();
        $this->assertStringContainsString('<img src="'.$studio->logoUrl().'" width="160" height="40" alt="Skyline Aerial"', $html);
        $this->assertStringNotContainsString('>S</td>', $html);

        $this->getJson("/api/v1/studios/{$studio->slug}")->assertOk()
            ->assertJsonPath('data.logo', ['url' => $studio->logoUrl(), 'width' => 960, 'height' => 240]);

        $this->actingAs($member);
        $this->get('/studios')->assertOk()->assertSee('<img class="studio-thumb" src="'.$studio->logoUrl().'" width="120" height="30" alt="">', false);

        // The check-in kiosk, bigger.
        $this->actingAs($this->userIn($studio, 'owner'))->post("/{$studio->slug}/admin/access/devices", ['name' => 'Front door']);
        $token = session('new_device')['token'];
        $this->withCookie("loft_kiosk_{$studio->id}", $token)->get("/{$studio->slug}/kiosk")->assertOk()
            ->assertSee('<img class="brand-logo" src="'.$studio->logoUrl().'" width="224" height="56" alt="Skyline Aerial">', false);

        // Without a logo, the API says so.
        app(StudioLogos::class)->remove($studio);
        $this->getJson("/api/v1/studios/{$studio->slug}")->assertOk()->assertJsonPath('data.logo', null);
    }

    public function test_closing_a_studio_deletes_its_logo(): void
    {
        $studio = $this->studio();
        app(StudioLogos::class)->store($studio, $this->image(400, 400));
        $this->assertSame(1, StudioLogo::count());

        $studio->delete();
        $this->assertSame(0, StudioLogo::count());
    }
}
