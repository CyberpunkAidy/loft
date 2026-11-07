<?php

namespace App\Support;

use Illuminate\Http\Request;

/** A ?return=/path that is safe to redirect to: same-site paths only. */
class ReturnPath
{
    public static function from(Request $request, string $key = 'return'): ?string
    {
        $path = (string) $request->input($key, '');

        return self::isSafe($path) ? $path : null;
    }

    public static function isSafe(string $path): bool
    {
        return $path !== '' && str_starts_with($path, '/') && ! str_starts_with($path, '//') && ! str_contains($path, '\\')
            && ! preg_match('/[\x00-\x1f]/', $path);
    }
}
