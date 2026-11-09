<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\BookingResource;
use App\Http\Resources\Api\EntitlementResource;
use App\Models\Studio;
use App\Services\PlanCheckout;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Buying a plan from the app: Stripe Checkout's page, opened in a browser. Creating one returns its
 * URL; when the browser reaches /{studio}/checkout/return, the app closes it and asks this API how it
 * went (which also records the purchase straight away).
 */
class CheckoutController extends Controller
{
    public function store(Request $request, Studio $studio, PlanCheckout $checkout): JsonResponse
    {
        $data = $request->validate([
            'plan_id' => ['required', 'integer', Rule::exists('plans', 'id')->where('studio_id', $studio->id)->where('active', true)],
            'session_id' => ['nullable', 'integer'],
            'attendee_id' => ['nullable', 'integer'],
            'apparatus_id' => ['nullable', 'integer'],
        ]);
        $context = $checkout->bookingContext($studio, $request->user(), $data['session_id'] ?? null, $data['attendee_id'] ?? null, $data['apparatus_id'] ?? null);
        $return = route('studio.checkout.return', ['studio' => $studio->slug]);
        $cs = $checkout->start($studio, $request->user(), $studio->plans()->findOrFail($data['plan_id']), $context,
            $return.'?session_id={CHECKOUT_SESSION_ID}', $return.'?cancelled=1');

        return response()->json(['data' => [
            'id' => $cs['id'],
            'url' => $cs['url'],
            'expires_at' => isset($cs['expires_at']) ? gmdate('Y-m-d\TH:i:s\Z', (int) $cs['expires_at']) : null,
            'return_url' => $return,
        ]], 201);
    }

    /** How a checkout went. Paid ones are recorded now if the webhook hasn't yet (once either way). */
    public function show(Request $request, Studio $studio, string $checkout, PlanCheckout $checkouts): JsonResponse
    {
        abort_unless(str_starts_with($checkout, 'cs_'), 404);
        $confirmed = $checkouts->confirm($studio, $request->user(), $checkout);
        abort_unless($confirmed !== null, 404);
        ['cs' => $cs, 'result' => $result] = $confirmed;
        $booking = $result->booking?->load(['attendee', 'apparatus', 'classSession.program', 'classSession.room']);
        $booking?->classSession->setRelation('studio', $studio);

        return response()->json(['data' => [
            'id' => $cs['id'],
            'status' => $cs['status'] ?? null,               // open | complete | expired
            'payment_status' => $cs['payment_status'] ?? null, // paid | unpaid (a bank debit still clearing) | no_payment_required
            'fulfilled' => $result->entitlement !== null,
            'entitlement' => $result->entitlement ? (new EntitlementResource($result->entitlement->load('plan')))->resolve() : null,
            'booking' => $booking ? (new BookingResource($booking))->resolve() : null,
            'booking_error' => $result->bookingError,
        ]]);
    }
}
