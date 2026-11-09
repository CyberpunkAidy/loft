<?php

namespace App\Http\Middleware;

use App\Support\ApiException;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * An Idempotency-Key header makes a POST safe to retry: the first successful response is kept for a
 * day and replayed (with Idempotent-Replayed: true) for the same key, from the same member (or device), on the
 * same route. Errors aren't kept, so a failed request can be retried with the same key. A key reused
 * with a different body is refused, and so is one whose first request is still running.
 */
class Idempotency
{
    public const TTL_HOURS = 24;

    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('Idempotency-Key');
        if ($key === null || $request->isMethodSafe()) {
            return $next($request);
        }
        if ($key === '' || strlen($key) > 255) {
            throw new ApiException('invalid_idempotency_key', 'Idempotency-Key must be 1 to 255 characters.', 400);
        }
        $who = $request->user()?->getAuthIdentifier() ?? (($device = $request->attributes->get('access_device')) ? 'device:'.$device->id : $request->ip());
        $id = 'idempotency:'.sha1($who.'|'.$request->method().'|'.$request->path().'|'.$key);
        $fingerprint = sha1($request->getContent());

        $lock = Cache::lock($id.':lock', 30);
        if (! $lock->get()) {
            throw new ApiException('request_in_progress', 'A request with this Idempotency-Key is still being handled. Try again in a moment.', 409);
        }
        try {
            if ($saved = Cache::get($id)) {
                if ($saved['fingerprint'] !== $fingerprint) {
                    throw new ApiException('idempotency_key_reused', 'This Idempotency-Key was already used for a different request.', 422);
                }

                return response($saved['body'], $saved['status'], ['Content-Type' => 'application/json', 'Idempotent-Replayed' => 'true']);
            }
            $response = $next($request);
            if ($response->isSuccessful()) {
                Cache::put($id, ['fingerprint' => $fingerprint, 'status' => $response->getStatusCode(), 'body' => $response->getContent()], now()->addHours(self::TTL_HOURS));
            }

            return $response;
        } finally {
            $lock->release();
        }
    }
}
