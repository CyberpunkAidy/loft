<?php

namespace App\Services;

use App\Models\Attendee;
use App\Models\Studio;
use App\Models\User;
use App\Models\WaiverSignature;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Waiver status and signing. Port of the plugin's Repos\Waivers.
 */
class Waivers
{
    /**
     * @return array{required: bool, signed: bool, signed_at: ?Carbon, version: int, expires_at: ?Carbon}
     */
    public function statusFor(Studio $studio, Attendee $attendee): array
    {
        $waiver = $studio->activeWaiver();
        if (! $waiver) {
            return ['required' => false, 'signed' => true, 'signed_at' => null, 'version' => 0, 'expires_at' => null];
        }

        $signature = WaiverSignature::query()
            ->where('attendee_id', $attendee->id)
            ->where('waiver_id', $waiver->id)
            ->where('waiver_version', $waiver->version)
            ->latest('signed_at')
            ->first();

        if (! $signature) {
            return ['required' => true, 'signed' => false, 'signed_at' => null, 'version' => $waiver->version, 'expires_at' => null];
        }

        $validity = (int) $studio->setting('waiver_validity_days');
        $expires = $validity > 0 ? $signature->signed_at->copy()->addDays($validity) : null;

        return [
            'required' => true,
            'signed' => $expires === null || $expires->isFuture(),
            'signed_at' => $signature->signed_at,
            'version' => $waiver->version,
            'expires_at' => $expires,
        ];
    }

    public function isSigned(Studio $studio, Attendee $attendee): bool
    {
        return $this->statusFor($studio, $attendee)['signed'];
    }

    /** Sign the active waiver for an attendee. Null when the studio has no active waiver. */
    public function sign(Studio $studio, Attendee $attendee, User $signer, string $signedName, ?string $signaturePng, ?string $ip, ?string $userAgent): ?WaiverSignature
    {
        $waiver = $studio->activeWaiver();
        if (! $waiver) {
            return null;
        }

        return WaiverSignature::create([
            'studio_id' => $studio->id,
            'waiver_id' => $waiver->id,
            'waiver_version' => $waiver->version,
            'attendee_id' => $attendee->id,
            'signed_by_user_id' => $signer->id,
            'signed_name' => $signedName,
            'signature_path' => $signaturePng ? $this->storeSignatureImage($studio, $attendee, $signaturePng) : null,
            'ip' => $ip,
            'user_agent' => $userAgent ? Str::limit($userAgent, 250, '') : null,
            'signed_at' => now(),
        ]);
    }

    /**
     * Store a drawn signature (a data:image/png;base64 URL, up to 512 KB) on the private disk.
     * Returns the stored path, or null if it isn't a PNG.
     */
    public function storeSignatureImage(Studio $studio, Attendee $attendee, string $dataUrl): ?string
    {
        if (! str_starts_with($dataUrl, 'data:image/png;base64,')) {
            return null;
        }
        $binary = base64_decode(substr($dataUrl, strlen('data:image/png;base64,')), true);
        if ($binary === false || strlen($binary) > 512 * 1024 || ! str_starts_with($binary, "\x89PNG\r\n\x1a\n")) {
            return null;
        }
        $path = sprintf('signatures/%d/sig-%d-%s.png', $studio->id, $attendee->id, Str::random(16));
        Storage::disk('local')->put($path, $binary);

        return $path;
    }
}
