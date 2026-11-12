<?php

namespace App\Http\Controllers\Studio;

use App\Http\Controllers\Controller;
use App\Models\Attendee;
use App\Models\Order;
use App\Models\Series;
use App\Models\Studio;
use App\Services\Bookings;
use App\Services\Entitlements;
use App\Services\SeriesSignups;
use App\Services\Stripe\StripeClient;
use App\Services\Stripe\StripeException;
use App\Services\Waivers;
use App\Support\BookingException;
use App\Support\CheckoutException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * A series' page (decision 47): its classes and price, and signing someone up: the studio's
 * questions and the waiver first (as for a booking), then Stripe Checkout, then every class booked.
 */
class SeriesController extends Controller
{
    public function show(Request $request, Studio $studio, Series $series, SeriesSignups $signups, Waivers $waivers, Bookings $bookings, Entitlements $entitlements): View
    {
        abort_unless($series->program->is_published, 404);
        $user = $request->user();
        if ($user && $request->filled('cancelled')) {
            // Back from Checkout without paying: let go of the spot it held.
            $pending = Order::where('studio_id', $studio->id)->where('user_id', $user->id)->where('status', 'pending')->find($request->integer('cancelled'));
            if ($pending) {
                $signups->cancel($pending);
            }
        }
        $quote = $signups->quote($series);
        $people = $user && $user->hasRoleIn($studio)
            ? $studio->attendees()->where('user_id', $user->id)->where('status', 'active')->orderByDesc('is_self')->orderBy('first_name')->get()
            : collect();
        $states = $people->mapWithKeys(function (Attendee $a) use ($series, $signups, $waivers, $bookings, $studio, $quote) {
            $ageError = null;
            if ($quote['classes']->isNotEmpty()) {
                try {
                    $bookings->assertAge($quote['classes']->first(), $a);
                } catch (BookingException $e) {
                    $ageError = $e->getMessage();
                }
            }

            return [$a->id => [
                'enrolled' => $signups->enrollmentFor($series, $a)?->status,
                'age_error' => $ageError,
                'questions' => count($a->questionsNeeded($studio)),
                'needs_waiver' => $waivers->nextUnsigned($studio, $a, $series->program) !== null,
            ]];
        });
        $makeup = $user && $quote['classes']->isNotEmpty() ? $entitlements->makeupFor($studio, $user->id, $quote['classes']->first()) : null;

        return view('studio.series', [
            'series' => $series->load('program.instructor', 'program.room'),
            'quote' => $quote,
            'allClasses' => $series->classes(),
            'people' => $people,
            'states' => $states,
            'picked' => $request->integer('attendee'),
            'makeup' => $makeup,
        ]);
    }

    public function store(Request $request, Studio $studio, Series $series, SeriesSignups $signups): RedirectResponse
    {
        $data = $request->validate(['attendee_id' => ['required', 'integer']]);
        $attendee = $studio->attendees()->where('user_id', $request->user()->id)->where('status', 'active')->findOrFail($data['attendee_id']);
        if ($studio->isDemo() && $signups->quote($series)['price_cents'] > 0) {
            return back()->with('warning', 'Payments are switched off in the demo, so signing up online stops here. As the owner, you can add someone from Admin → Series.');
        }
        $back = route('studio.series', ['series' => $series, 'attendee' => $attendee->id], false);

        try {
            $url = $signups->checkout($series, $attendee, $request->user(),
                route('studio.series.done', ['series' => $series]).'?session_id={CHECKOUT_SESSION_ID}',
                route('studio.series', ['series' => $series]).'?cancelled=__ORDER__');
        } catch (BookingException $e) {
            return match ($e->reason) {
                'questions' => redirect()->route('studio.attendees.questions', ['attendee' => $attendee, 'return' => $back]),
                'waiver' => redirect()->route('studio.waiver', ['attendee' => $attendee, 'waiver' => $e->context['waiver']->id, 'return' => $back])->with('warning', $e->getMessage()),
                default => redirect($back)->with('error', $e->getMessage()),
            };
        } catch (CheckoutException|StripeException $e) {
            return redirect($back)->with('error', $e->getMessage());
        }

        return $url
            ? redirect()->away($url)
            : redirect()->route('studio.account')->with('success', "{$attendee->first_name} is signed up for {$series->name} and booked into every class.");
    }

    /** Stripe sends the member back here: settle it now rather than wait for the webhook. */
    public function done(Request $request, Studio $studio, Series $series, StripeClient $stripe, SeriesSignups $signups): RedirectResponse
    {
        $id = (string) $request->query('session_id', '');
        if (! str_starts_with($id, 'cs_')) {
            return redirect()->route('studio.series', ['series' => $series]);
        }
        try {
            $cs = $stripe->retrieveCheckoutSession($studio, $id);
            abort_unless((int) ($cs['metadata']['loft_user_id'] ?? 0) === (int) $request->user()->id, 404);
            $order = $signups->fulfil($studio, $cs);
        } catch (StripeException $e) {
            report($e);
            $order = null;
        }

        return redirect()->route('studio.account')->with('success', match (true) {
            $order?->status === 'paid' => "Thanks! You’re signed up for {$series->name}, and booked into every class. We’ve emailed you the details.",
            $order?->status === 'pending' => 'Thanks! Your payment is processing. Bank payments can take a few business days; your spot is held, and we’ll email you when it goes through.',
            default => 'Thanks! We’re confirming your payment; your classes will appear here shortly.',
        });
    }
}
