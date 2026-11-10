<?php

namespace App\Http\Controllers\Studio;

use App\Http\Controllers\Controller;
use App\Models\Studio;
use App\Services\StudioLogos;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;

/**
 * /{studio}/logo/{hash}.{type}: the studio's logo (decision 41), for its pages, emails and the app.
 * The hash changes whenever the logo does, so the image is cached for good. An older hash (an email
 * sent before the logo changed) is sent on to the current logo.
 */
class LogoController extends Controller
{
    public function show(Studio $studio, string $hash, string $type, StudioLogos $logos): Response|RedirectResponse
    {
        abort_unless($studio->hasLogo(), 404);
        if ($hash !== $studio->logo_hash || $type !== $studio->logo_type) {
            return redirect()->to($studio->logoUrl())->header('Cache-Control', 'no-cache');
        }
        $bytes = $logos->bytes($studio);
        abort_if($bytes === null, 404);

        return response($bytes, 200, [
            'Content-Type' => $type === 'png' ? 'image/png' : 'image/jpeg',
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'ETag' => '"'.$studio->logo_hash.'"',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'",
        ]);
    }
}
