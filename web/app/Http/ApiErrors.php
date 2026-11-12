<?php

namespace App\Http;

use App\Http\Resources\Api\PlanResource;
use App\Services\Stripe\StripeException;
use App\Support\ApiException;
use App\Support\BookingException;
use App\Support\CheckoutException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Every error from /api as {"error": {"code": "…", "message": "…", …details}}. The code is stable,
 * for the app to act on; the message is written for the member and may change.
 */
class ApiErrors
{
    /** BookingException reasons → API codes and statuses. */
    private const BOOKING = [
        'questions' => ['questions_required', 422],
        'series_only' => ['series_only', 422],
        'already_enrolled' => ['already_enrolled', 409],
        'series_closed' => ['series_closed', 422],
        'waiver' => ['waiver_required', 422],
        'needs_plan' => ['needs_plan', 422],
        'waitlist_full' => ['full', 422],
        'duplicate' => ['already_booked', 409],
        'apparatus' => ['apparatus_invalid', 422],
        'forbidden' => ['forbidden', 403],
        'paused' => ['studio_paused', 403],
    ];

    public static function render(Throwable $e): ?JsonResponse
    {
        return match (true) {
            $e instanceof ApiException => self::json($e->errorCode, $e->getMessage(), $e->status, $e->details),
            $e instanceof BookingException => self::booking($e),
            $e instanceof CheckoutException => self::json('checkout_unavailable', $e->getMessage(), 422),
            $e instanceof StripeException => self::json('payment_provider_error', 'Stripe: '.$e->getMessage(), 502),
            $e instanceof ValidationException => self::json('validation_failed', $e->getMessage(), 422, ['fields' => $e->errors()]),
            $e instanceof AuthenticationException => self::json('unauthenticated', 'Sign in again: the token is missing, expired or revoked.', 401),
            $e instanceof HttpExceptionInterface => self::http($e),
            default => config('app.debug') ? null : self::json('server_error', 'Something went wrong on our side. Please try again.', 500),
        };
    }

    public static function json(string $code, string $message, int $status, array $details = [], array $headers = []): JsonResponse
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message] + $details], $status, $headers);
    }

    private static function booking(BookingException $e): JsonResponse
    {
        [$code, $status] = self::BOOKING[$e->reason] ?? [$e->reason, 422];
        $details = array_filter([
            'attendee_id' => isset($e->context['attendee']) ? $e->context['attendee']->id : null,
            'waiver_id' => isset($e->context['waiver']) ? $e->context['waiver']->id : null,
            'questions' => $e->context['questions'] ?? null,
            'series_id' => isset($e->context['series']) ? $e->context['series']->id : null,
            'plans' => isset($e->context['plans']) ? PlanResource::collection($e->context['plans'])->resolve() : null,
        ], fn ($value) => $value !== null);

        return self::json($code, $e->getMessage(), $status, $details);
    }

    private static function http(HttpExceptionInterface $e): JsonResponse
    {
        $status = $e->getStatusCode();
        [$code, $default] = match ($status) {
            403 => ['forbidden', 'You don’t have access to that.'],
            404 => ['not_found', 'Not found.'],
            405 => ['method_not_allowed', 'That method isn’t allowed here.'],
            429 => ['rate_limited', 'Too many requests. Wait a moment and try again.'],
            default => ['http_error', 'The request failed.'],
        };
        // 404s from missing models name the model class; keep those out.
        $message = $status === 404 || $e->getMessage() === '' ? $default : $e->getMessage();

        return self::json($code, $message, $status, [], $e->getHeaders());
    }
}
