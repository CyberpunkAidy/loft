<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\SeriesResource;
use App\Models\Series;
use App\Models\SeriesEnrollment;
use App\Models\Studio;
use App\Services\SeriesSignups;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Series (decision 47) for the app: the ones taking sign-ups, one in detail, and signing someone
 * up (Stripe Checkout in a browser, as for plans; a free series signs up at once).
 */
class SeriesController extends Controller
{
    public function index(Studio $studio): AnonymousResourceCollection
    {
        $series = $studio->series()->with(['program', 'studio'])->where('signups_open', true)
            ->whereHas('program', fn ($q) => $q->where('is_published', true))
            ->where('ends_on', '>=', now()->subDay()->toDateString())->orderBy('starts_on')->limit(50)->get();

        return SeriesResource::collection($series);
    }

    public function show(Request $request, Studio $studio, Series $series): SeriesResource
    {
        abort_unless($series->program->is_published, 404);
        $resource = new SeriesResource($series->load('studio'));
        $resource->withClasses = true;
        if ($user = $request->user('sanctum')) {
            $resource->mine = SeriesEnrollment::where('series_id', $series->id)->where('user_id', $user->id)
                ->whereIn('status', ['pending', 'active'])->pluck('status', 'attendee_id')->all();
        }

        return $resource;
    }

    /** Sign someone up. Errors are booking errors: questions_required, waiver_required, full… */
    public function store(Request $request, Studio $studio, Series $series, SeriesSignups $signups): JsonResponse
    {
        $data = $request->validate(['attendee_id' => ['required', 'integer']]);
        $attendee = $studio->attendees()->where('user_id', $request->user()->id)->where('status', 'active')->findOrFail($data['attendee_id']);
        $return = route('studio.checkout.return', ['studio' => $studio->slug]);
        $url = $signups->checkout($series, $attendee, $request->user(), $return.'?session_id={CHECKOUT_SESSION_ID}', $return.'?cancelled=1');
        $enrollment = $signups->enrollmentFor($series, $attendee);
        $checkoutId = $url && $enrollment ? $enrollment->orderItems()->with('order')->first()?->order?->stripe_checkout_session_id : null;

        return response()->json(['data' => [
            'signed_up' => $url === null, // a free series: done
            'checkout_id' => $checkoutId,  // GET …/checkouts/{id} says when it's paid
            'checkout_url' => $url,
            'return_url' => $return,
        ]], 201);
    }
}
