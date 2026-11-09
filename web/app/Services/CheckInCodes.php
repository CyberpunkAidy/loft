<?php

namespace App\Services;

use App\Models\Attendee;
use App\Models\Studio;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/**
 * Check-in QR codes: one per person on a member's account, signed with the app key and changing every
 * STEP seconds, so a screenshot stops working within about a minute. Nothing is stored: a kiosk
 * checks the signature and the time. Format: LOFT1.{attendee id}.{time step}.{signature}.
 */
class CheckInCodes
{
    public const PREFIX = 'LOFT1';

    public const STEP = 30;

    /** Steps either side of now a code is still accepted: scanning takes a moment, and clocks drift. */
    public const LEEWAY = 2;

    /** @return array{code: string, expires_at: int} the code to show now, and when the next one takes over (unix time) */
    public function current(Attendee $attendee, ?int $now = null): array
    {
        $step = intdiv($now ?? time(), self::STEP);

        return ['code' => $this->code($attendee->studio_id, $attendee->id, $step), 'expires_at' => ($step + 1) * self::STEP];
    }

    public function isCode(string $raw): bool
    {
        return str_starts_with(trim($raw), self::PREFIX.'.');
    }

    /**
     * The person a code is for, if it's genuine, this studio's, and current at $at.
     *
     * @return array{attendee: ?Attendee, expired: bool}
     */
    public function verify(Studio $studio, string $raw, int $at): array
    {
        $parts = explode('.', trim($raw));
        if (count($parts) !== 4 || $parts[0] !== self::PREFIX || ! ctype_digit($parts[1]) || ! ctype_digit($parts[2])) {
            return ['attendee' => null, 'expired' => false];
        }
        [, $attendeeId, $step] = [$parts[0], (int) $parts[1], (int) $parts[2]];
        if (! hash_equals($this->code($studio->id, $attendeeId, $step), trim($raw))) {
            return ['attendee' => null, 'expired' => false]; // forged, or another studio's
        }
        if (abs(intdiv($at, self::STEP) - $step) > self::LEEWAY) {
            return ['attendee' => null, 'expired' => true];
        }

        return ['attendee' => $studio->attendees()->where('status', 'active')->find($attendeeId), 'expired' => false];
    }

    /** The code as an SVG QR image, for the website (the app draws its own). */
    public function svg(string $code, int $size = 240): string
    {
        $svg = (new Writer(new ImageRenderer(new RendererStyle($size, 2), new SvgImageBackEnd)))->writeString($code);

        return trim(preg_replace('/^<\?xml[^>]*>\s*/', '', $svg));
    }

    private function code(int $studioId, int $attendeeId, int $step): string
    {
        $key = hash_hmac('sha256', 'loft-check-in-codes', (string) config('app.key'));
        $signature = substr(hash_hmac('sha256', "{$studioId}|{$attendeeId}|{$step}", $key), 0, 20);

        return self::PREFIX.".{$attendeeId}.{$step}.{$signature}";
    }
}
