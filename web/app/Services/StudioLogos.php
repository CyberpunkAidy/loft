<?php

namespace App\Services;

use App\Models\Studio;
use App\Models\StudioLogo;
use App\Support\LogoException;
use GdImage;
use Illuminate\Support\Facades\DB;

/**
 * Studio logos (decision 41). An upload must be a PNG, JPEG or WebP image; SVG is refused because
 * it can carry scripts. Every logo is decoded and drawn again with GD, which drops anything but the
 * pixels (metadata, extra chunks, anything appended), shrunk to fit 960 × 240, and saved as PNG
 * when it may have transparency or JPEG when it can't. Studios show it at 36–48 pixels tall, so
 * 240 covers sharp screens with room to spare.
 */
class StudioLogos
{
    /** The largest upload accepted. */
    public const MAX_BYTES = 2 * 1024 * 1024;

    /** Images bigger than this aren't decoded at all, so a small file can't expand into gigabytes. */
    public const MAX_PIXELS = 25_000_000;

    /** The smallest logo that still looks sharp in the header. */
    public const MIN_SIDE = 32;

    public const MAX_WIDTH = 960;

    public const MAX_HEIGHT = 240;

    private const ACCEPTED = [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_WEBP];

    /** @throws LogoException */
    public function store(Studio $studio, string $bytes): void
    {
        [$data, $type, $width, $height] = $this->process($bytes);

        DB::transaction(function () use ($studio, $data, $type, $width, $height) {
            StudioLogo::updateOrCreate(['studio_id' => $studio->id], ['data' => base64_encode($data)]);
            $studio->update([
                'logo_hash' => substr(hash('sha256', $data), 0, 12),
                'logo_type' => $type,
                'logo_width' => $width,
                'logo_height' => $height,
            ]);
        });
    }

    /** Back to the letter badge. */
    public function remove(Studio $studio): void
    {
        DB::transaction(function () use ($studio) {
            $studio->logo()->delete();
            $studio->update(['logo_hash' => null, 'logo_type' => null, 'logo_width' => null, 'logo_height' => null]);
        });
    }

    /** The image's bytes, as stored. */
    public function bytes(Studio $studio): ?string
    {
        $data = $studio->logo()->value('data');

        return is_string($data) ? (base64_decode($data, true) ?: null) : null;
    }

    /**
     * Check and redraw an upload: the image's bytes, its type (png or jpg) and its size.
     *
     * @return array{0: string, 1: string, 2: int, 3: int}
     *
     * @throws LogoException
     */
    public function process(string $bytes): array
    {
        if (strlen($bytes) > self::MAX_BYTES) {
            throw new LogoException('That file is over 2 MB. Try a smaller version of your logo.');
        }
        $info = $bytes === '' ? false : @getimagesizefromstring($bytes);
        if (! $info || ! in_array($info[2], self::ACCEPTED, true)) {
            throw new LogoException('Upload your logo as a PNG, JPEG or WebP image.');
        }
        [$width, $height] = $info;
        if ($width * $height > self::MAX_PIXELS) {
            throw new LogoException('That image is too large. Use one under 5,000 × 5,000 pixels.');
        }
        if (min($width, $height) < self::MIN_SIDE) {
            throw new LogoException('That image is too small to look sharp. Use one at least '.self::MIN_SIDE.' pixels tall.');
        }
        $source = @imagecreatefromstring($bytes);
        if (! $source instanceof GdImage) {
            throw new LogoException('That image couldn’t be read. Try saving it again as a PNG.');
        }

        $transparent = $info[2] !== IMAGETYPE_JPEG;
        imagepalettetotruecolor($source);
        $scale = min(1, self::MAX_WIDTH / $width, self::MAX_HEIGHT / $height);
        $w = max(1, (int) round($width * $scale));
        $h = max(1, (int) round($height * $scale));

        $out = imagecreatetruecolor($w, $h);
        if ($transparent) {
            imagealphablending($out, false);
            imagesavealpha($out, true);
            imagefill($out, 0, 0, imagecolorallocatealpha($out, 0, 0, 0, 127));
        } else {
            imagefill($out, 0, 0, imagecolorallocate($out, 255, 255, 255));
        }
        imagecopyresampled($out, $source, 0, 0, 0, 0, $w, $h, $width, $height);

        ob_start();
        $transparent ? imagepng($out, null, 9) : imagejpeg($out, null, 88);
        $data = (string) ob_get_clean();

        return [$data, $transparent ? 'png' : 'jpg', $w, $h];
    }
}
