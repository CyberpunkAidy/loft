<?php

namespace App\Services;

use App\Mail\StudioMessage;
use App\Models\Attendee;
use App\Models\Booking;
use App\Models\ClassSession;
use App\Models\Entitlement;
use App\Models\Order;
use App\Models\Series;
use App\Models\SeriesEnrollment;
use App\Models\Studio;
use App\Models\User;
use App\Services\Stripe\StripeClient;
use App\Services\Stripe\StripeException;
use App\Support\BookingException;
use App\Support\CheckoutException;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Signing up for a series (decision 47): paid in full, it books the person into every class still
 * to come. Online, it works like a shop order: a pending enrollment holds their spot and a pending
 * order goes to Stripe Checkout; paying turns both into the real thing, and a checkout that expires
 * or fails lets the spot go. Staff can also add someone, paid at the desk or free.
 *
 * Joining late is allowed until the series' join_until_class starts, for the classes left, at that
 * share of the price (7 of 8 classes: 7/8 of it).
 */
class SeriesSignups
{
    /** How long an online sign-up holds a spot while the member pays. */
    public const HOLD_MINUTES = 30;

    public function __construct(
        private readonly Bookings $bookings,
        private readonly Orders $orders,
        private readonly StripeClient $stripe,
        private readonly Messages $messages,
        private readonly Waivers $waivers,
        private readonly DomainEvents $events,
    ) {}

    /**
     * What signing up now gets: the classes still to come and their price, the spots left, and why
     * it's closed if it is.
     *
     * @return array{classes: Collection<int, ClassSession>, total: int, left: int, price_cents: int, spots_left: int, open: bool, closed: ?string}
     */
    public function quote(Series $series): array
    {
        $all = $series->classes();
        $left = $all->reject(fn (ClassSession $s) => $s->hasStarted())->values();
        $total = $all->count();
        $started = $total - $left->count();
        $price = $total > 0 && $left->count() < $total ? (int) round($series->price_cents * $left->count() / $total) : $series->price_cents;
        $spots = $this->spotsLeft($series, $left);
        $closed = match (true) {
            $total === 0 => 'This series has no classes yet.',
            $left->isEmpty() => 'This series has finished.',
            ! $series->signups_open => 'Sign-ups for this series are closed.',
            $started >= $series->join_until_class => 'It’s too late to join this series. Ask the studio about the next one.',
            $spots === 0 => 'This series is full.',
            default => null,
        };

        return ['classes' => $left, 'total' => $total, 'left' => $left->count(), 'price_cents' => $price, 'spots_left' => $spots, 'open' => $closed === null, 'closed' => $closed];
    }

    /**
     * Spots left: the fewest open spots in any class still to come (apparatus included), less the
     * people paying right now, whose classes aren't booked yet.
     */
    public function spotsLeft(Series $series, Collection $left): int
    {
        if ($left->isEmpty()) {
            return 0;
        }
        $paying = $series->enrollments()->where('status', 'pending')->count();

        return max(0, (int) $left->map(fn (ClassSession $s) => $s->spotsLeft())->min() - $paying);
    }

    /**
     * Can this person sign up? Members answer the studio's questions first; everyone needs the
     * program's waivers signed and the right age for the series' classes. Staff can add someone
     * after sign-ups close, but not to a full series.
     *
     * @throws BookingException
     */
    public function assertCanJoin(Series $series, Attendee $attendee, array $quote, bool $byStaff = false): void
    {
        if ((int) $attendee->studio_id !== (int) $series->studio_id || $attendee->status !== 'active') {
            throw new BookingException('forbidden', 'That person is not on your account.');
        }
        if ($this->enrollmentFor($series, $attendee)) {
            throw new BookingException('already_enrolled', "{$attendee->first_name} is already signed up for this series.", ['attendee' => $attendee]);
        }
        if (! $quote['open'] && ! ($byStaff && $quote['left'] > 0 && $quote['spots_left'] > 0)) {
            throw new BookingException($quote['spots_left'] === 0 && $quote['left'] > 0 ? 'full' : 'series_closed', $quote['closed'] ?? 'Sign-ups for this series are closed.');
        }
        if (! $byStaff && ($questions = $attendee->questionsNeeded($series->studio))) {
            throw new BookingException('questions', $attendee->questionsMessage($questions), ['attendee' => $attendee, 'questions' => $questions]);
        }
        if ($unsigned = $this->waivers->nextUnsigned($series->studio, $attendee, $series->program)) {
            throw new BookingException('waiver', "{$attendee->first_name} needs to sign “{$unsigned->title}” before signing up.", ['attendee' => $attendee, 'waiver' => $unsigned]);
        }
        foreach ([$quote['classes']->first(), $quote['classes']->last()] as $session) {
            $this->bookings->assertAge($session, $attendee);
        }
    }

    /** Their sign-up for this series, if they're in it or paying for it now. */
    public function enrollmentFor(Series $series, Attendee $attendee): ?SeriesEnrollment
    {
        return SeriesEnrollment::where('series_id', $series->id)->where('attendee_id', $attendee->id)->whereIn('status', ['pending', 'active'])->first();
    }

    /**
     * Sign up online. Holds the spot and returns Stripe Checkout's URL; for a free series, signs them
     * up at once and returns null. "__ORDER__" in $cancelUrl becomes the order's ID.
     *
     * @throws BookingException|CheckoutException|StripeException
     */
    public function checkout(Series $series, Attendee $attendee, User $member, string $successUrl, string $cancelUrl): ?string
    {
        $studio = $series->studio;
        if ((int) $attendee->user_id !== (int) $member->id) {
            throw new BookingException('forbidden', 'That person is not on your account.');
        }
        if ($studio->isPaused()) {
            throw new CheckoutException($studio->name.' isn’t taking sign-ups right now. Please contact the studio.');
        }
        // A sign-up they walked away from holds a spot for half an hour: let it go first.
        SeriesEnrollment::where('series_id', $series->id)->where('attendee_id', $attendee->id)->where('status', 'pending')->get()
            ->each(fn (SeriesEnrollment $e) => ($order = $this->orderOf($e)) ? $this->cancel($order) : $this->releaseEnrollment($e));

        $this->assertCanJoin($series, $attendee, $this->quote($series));
        [$enrollment, $order] = DB::transaction(function () use ($series, $attendee, $member, $studio) {
            Series::whereKey($series->id)->lockForUpdate()->first(); // one sign-up at a time takes the last spot
            $quote = $this->quote($series);
            if (! $quote['open']) {
                throw new BookingException($quote['spots_left'] === 0 ? 'full' : 'series_closed', $quote['closed']);
            }
            if ($quote['price_cents'] > 0 && ! $studio->stripeReady()) {
                throw new CheckoutException('Online payments aren’t set up for this studio yet. Please contact the studio to sign up.');
            }
            $enrollment = $this->createEnrollment($series, $attendee, $quote, 'web');
            $order = $quote['price_cents'] > 0 ? $this->orders->createPending($studio, [
                'user_id' => $member->id,
                'channel' => 'online',
                'method' => 'stripe',
                'currency' => $studio->currency,
                'subtotal_cents' => $quote['price_cents'],
                'total_cents' => $quote['price_cents'],
            ], [$this->item($enrollment, $quote)]) : null;

            return [$enrollment, $order];
        });

        if (! $order) {
            $this->activate($enrollment);

            return null;
        }
        try {
            $session = $this->stripe->createShopCheckout($studio, $member, $order->load('items'), $successUrl,
                str_replace('__ORDER__', (string) $order->id, $cancelUrl), self::HOLD_MINUTES, 'series');
        } catch (Throwable $e) {
            $this->release($order, 'failed');
            throw $e;
        }
        $order->update(['stripe_checkout_session_id' => $session['id']]);

        return $session['url'];
    }

    /**
     * Staff add someone: paid at the desk ($method, $amountCents) or free ("comp"). Their classes are
     * booked and they're emailed, as when signing up online.
     *
     * @throws BookingException
     */
    public function addByStaff(Series $series, Attendee $attendee, User $staff, string $method, int $amountCents, ?string $note = null): SeriesEnrollment
    {
        $studio = $series->studio;
        $this->assertCanJoin($series, $attendee, $this->quote($series), byStaff: true);
        $enrollment = DB::transaction(function () use ($series, $attendee, $staff, $method, $amountCents, $note, $studio) {
            Series::whereKey($series->id)->lockForUpdate()->first();
            $quote = $this->quote($series);
            if ($quote['spots_left'] === 0 || $quote['left'] === 0) {
                throw new BookingException('full', $quote['left'] === 0 ? 'This series has finished.' : 'This series is full.');
            }
            $amount = $method === 'comp' ? 0 : max(0, $amountCents);
            $enrollment = $this->createEnrollment($series, $attendee, ['price_cents' => $amount] + $quote, 'staff');
            $this->orders->recordSale($studio, [
                'user_id' => $attendee->user_id,
                'channel' => 'in_person',
                'method' => $method,
                'currency' => $studio->currency,
                'subtotal_cents' => $amount,
                'total_cents' => $amount,
                'recorded_by_user_id' => $staff->id,
                'note' => $note,
                'paid_at' => now(),
            ], [$this->item($enrollment, ['price_cents' => $amount] + $quote)]);

            return $enrollment;
        });
        $this->activate($enrollment);

        return $enrollment->fresh();
    }

    /**
     * Paid (or free, or added by staff): book every class still to come, keeping the same apparatus
     * week to week where it can, and email the member once. A class they were already booked into
     * keeps that booking.
     */
    public function activate(SeriesEnrollment $enrollment): void
    {
        $activated = DB::transaction(function () use ($enrollment) {
            $locked = SeriesEnrollment::whereKey($enrollment->id)->lockForUpdate()->first();
            if (! $locked || $locked->status !== 'pending') {
                return false;
            }
            $locked->update(['status' => 'active', 'enrolled_at' => now()]);
            $enrollment->setRawAttributes($locked->getAttributes(), true);

            return true;
        });
        if (! $activated) {
            return;
        }

        $series = $enrollment->series;
        $attendee = $enrollment->attendee;
        [$booked, $waitlisted, $apparatus] = [0, 0, null];
        foreach ($this->quote($series)['classes'] as $session) {
            try {
                $booking = $this->bookings->book($session, $attendee, (int) $attendee->user_id, 'series', skipEntitlement: true,
                    apparatusId: $apparatus && ($slot = $session->apparatusSlots()->get($apparatus)) && ! $slot->isFull() ? $apparatus : null, skipQuestions: true, seriesEnrollment: $enrollment);
                $apparatus ??= $booking->apparatus_id;
                $booking->status === 'waitlisted' ? $waitlisted++ : $booked++;
            } catch (BookingException $e) {
                if ($e->reason !== 'duplicate') {
                    report($e); // too old or young for that week, or the class filled: staff see the gap on the roster
                }
            }
        }
        $enrollment->update(['classes' => $booked + $waitlisted]);
        $this->events->record('series.enrolled', $enrollment->studio_id, [
            'series_id' => $series->id,
            'series_name' => $series->name,
            'enrollment_id' => $enrollment->id,
            'classes' => $booked + $waitlisted,
            'price_cents' => $enrollment->price_cents,
            'source' => $enrollment->source,
        ], $enrollment->user_id, $enrollment->attendee_id);
        $this->confirm($enrollment, $booked, $waitlisted);
    }

    /**
     * Take someone out of a series (staff, or their payment refunded or voided): their classes still
     * to come are cancelled without an email each, unused make-up credits from it end, and one email
     * says so. Returns how many classes were cancelled.
     */
    public function withdraw(SeriesEnrollment $enrollment, string $reason = 'staff'): int
    {
        if (! in_array($enrollment->status, ['pending', 'active'], true)) {
            return 0;
        }
        $wasActive = $enrollment->status === 'active';
        $cancelled = DB::transaction(function () use ($enrollment, $reason) {
            $enrollment->update(['status' => 'withdrawn', 'withdrawn_at' => now()]);
            $cancelled = $enrollment->bookings()->with(['classSession.program', 'studio'])->whereIn('status', ['booked', 'waitlisted'])->get()
                ->reject(fn (Booking $b) => $b->classSession->hasStarted())->values();
            foreach ($cancelled as $booking) {
                $from = $booking->status;
                $booking->update(['status' => 'cancelled', 'cancelled_at' => now(), 'position' => null]);
                $this->events->forBooking('booking.cancelled', $booking, ['by' => 'staff', 'late' => false, 'from' => $from, 'credit_refunded' => false, 'series_withdrawn' => true]);
            }
            Entitlement::where('series_enrollment_id', $enrollment->id)->where('status', 'active')->update(['status' => 'cancelled']);
            $this->events->record('series.withdrawn', $enrollment->studio_id, [
                'series_id' => $enrollment->series_id,
                'series_name' => $enrollment->series?->name,
                'enrollment_id' => $enrollment->id,
                'reason' => $reason,
                'classes_cancelled' => $cancelled->count(),
            ], $enrollment->user_id, $enrollment->attendee_id);

            return $cancelled;
        });
        $cancelled->pluck('classSession')->unique('id')->each(fn (ClassSession $s) => $this->bookings->promoteWaitlist($s));
        if ($wasActive && $enrollment->user) {
            $series = $enrollment->series;
            $this->messages->email($enrollment->user, new StudioMessage($series->studio, 'You’ve been taken out of a series',
                ["{$enrollment->attendee->first_name} is no longer signed up for {$series->name}, so their classes still to come were cancelled."],
                ['Series' => $series->name, 'Who' => $enrollment->attendee->name, 'Classes cancelled' => (string) $cancelled->count()],
                [$reason === 'refunded' ? 'Your payment was refunded.' : 'If that’s a surprise, just reply to this email.'],
                route('studio.account', $series->studio), 'View your classes', 'series_withdrawn'), "series_withdrawn:enrollment:{$enrollment->id}");
        }

        return $cancelled->count();
    }

    /* ------------------------------------------------------------------ Online payment */

    /** Its Checkout Session completed (webhook, return page or sweep): paid, so sign them up, once. */
    public function fulfil(Studio $studio, array $cs): ?Order
    {
        $order = $this->orderFor($studio, $cs);
        if (! $order || ($cs['payment_status'] ?? '') !== 'paid') {
            return $order; // a delayed payment (bank debit) holds the spot until it clears
        }
        $paid = $this->orders->markPaid($order, [
            'subtotal_cents' => (int) ($cs['amount_subtotal'] ?? $order->subtotal_cents),
            'discount_cents' => (int) ($cs['total_details']['amount_discount'] ?? 0),
            'tax_cents' => (int) ($cs['total_details']['amount_tax'] ?? 0),
            'total_cents' => (int) ($cs['amount_total'] ?? $order->total_cents),
            'stripe_checkout_session_id' => $cs['id'] ?? $order->stripe_checkout_session_id,
            'stripe_payment_intent_id' => is_array($cs['payment_intent'] ?? null) ? $cs['payment_intent']['id'] : ($cs['payment_intent'] ?? null),
        ]);
        if ($paid && ($enrollment = $order->items()->first()?->seriesEnrollment)) {
            $this->activate($enrollment);
        }

        return $order->fresh();
    }

    /** A delayed payment failed: let the spot go and tell the member, once. */
    public function failed(Studio $studio, array $cs): ?Order
    {
        $order = $this->orderFor($studio, $cs);
        if ($order && $this->release($order, 'failed') && $order->user) {
            $this->messages->email($order->user, new StudioMessage($studio, 'Your payment didn’t go through',
                ['Your payment for a series didn’t go through, so you haven’t been signed up or charged.'],
                $order->items()->get()->mapWithKeys(fn ($i) => ['Series' => $i->description])->all(),
                ['You can sign up again and pay with a card or another payment method, if there’s still room.'], route('studio.schedule', $studio), 'See the schedule', 'checkout_failed'),
                "checkout_failed:order:{$order->id}");
        }

        return $order;
    }

    /** Its Checkout Session expired unpaid: let the spot go. */
    public function expired(Studio $studio, array $cs): ?Order
    {
        $order = $this->orderFor($studio, $cs);
        if ($order && ($cs['status'] ?? '') === 'expired') {
            $this->release($order, 'expired');
        }

        return $order;
    }

    /** The member came back from Checkout without paying: close the session, then let the spot go. */
    public function cancel(Order $order): void
    {
        if ($order->status !== 'pending') {
            return;
        }
        if ($order->stripe_checkout_session_id) {
            try {
                $cs = $this->stripe->expireCheckoutSession($order->studio, $order->stripe_checkout_session_id);
            } catch (StripeException) {
                try {
                    $cs = $this->stripe->retrieveCheckoutSession($order->studio, $order->stripe_checkout_session_id);
                } catch (StripeException) {
                    return; // the sweep settles it
                }
            }
            if (($cs['status'] ?? '') !== 'expired') {
                return; // it completed: the webhook signs them up
            }
        }
        $this->release($order, 'expired');
    }

    /** The order won't be paid: it's released, and so is the spot its enrollment held. */
    public function release(Order $order, string $status): bool
    {
        if (! $this->orders->release($order, $status)) {
            return false;
        }
        if ($enrollment = $order->items()->first()?->seriesEnrollment) {
            $this->releaseEnrollment($enrollment);
        }

        return true;
    }

    /** Hourly: settle sign-ups whose Checkout should have finished, by asking Stripe (as the shop does). */
    public function sweep(): int
    {
        $settled = 0;
        Order::with('studio')->where('status', 'pending')->where('channel', 'online')
            ->whereHas('items', fn ($q) => $q->where('kind', 'series'))
            ->where('created_at', '<', now()->subMinutes(self::HOLD_MINUTES + 10))
            ->eachById(function (Order $order) use (&$settled) {
                if (! $order->stripe_checkout_session_id) {
                    $settled += (int) $this->release($order, 'failed');

                    return;
                }
                try {
                    $cs = $this->stripe->retrieveCheckoutSession($order->studio, $order->stripe_checkout_session_id, ['payment_intent']);
                } catch (StripeException $e) {
                    report($e);

                    return;
                }
                $complete = ($cs['status'] ?? '') === 'complete';
                $settled += (int) match (true) {
                    $complete && ($cs['payment_status'] ?? '') === 'paid' => $this->fulfil($order->studio, $cs)?->status === 'paid',
                    $complete && in_array($cs['payment_intent']['status'] ?? null, ['requires_payment_method', 'canceled'], true) => $this->failed($order->studio, $cs)?->status === 'failed',
                    ($cs['status'] ?? '') === 'expired',
                    ($cs['status'] ?? '') === 'open' && ($cs['expires_at'] ?? PHP_INT_MAX) < now()->getTimestamp() => $this->release($order, 'expired'),
                    default => false,
                };
            });

        return $settled;
    }

    /* ------------------------------------------------------------------ Helpers */

    private function createEnrollment(Series $series, Attendee $attendee, array $quote, string $source): SeriesEnrollment
    {
        return SeriesEnrollment::create([
            'studio_id' => $series->studio_id,
            'series_id' => $series->id,
            'attendee_id' => $attendee->id,
            'user_id' => $attendee->user_id,
            'status' => 'pending',
            'classes' => $quote['left'],
            'price_cents' => $quote['price_cents'],
            'source' => $source,
        ]);
    }

    /** The order line: "Silks Level 1 (Jan 6 – Feb 24) · Leo", noting a late start. */
    private function item(SeriesEnrollment $enrollment, array $quote): array
    {
        $series = $enrollment->series;
        $late = $quote['left'] < $quote['total'] ? " · {$quote['left']} of {$quote['total']} classes" : '';

        return [
            'kind' => 'series',
            'series_enrollment_id' => $enrollment->id,
            'description' => "{$series->name} ({$series->datesLabel()}) · {$enrollment->attendee->first_name}{$late}",
            'quantity' => 1,
            'unit_price_cents' => $quote['price_cents'],
            'total_cents' => $quote['price_cents'],
        ];
    }

    private function releaseEnrollment(SeriesEnrollment $enrollment): void
    {
        SeriesEnrollment::whereKey($enrollment->id)->where('status', 'pending')->update(['status' => 'released']);
    }

    private function orderOf(SeriesEnrollment $enrollment): ?Order
    {
        return Order::whereHas('items', fn ($q) => $q->where('series_enrollment_id', $enrollment->id))->first();
    }

    /** The series order a Checkout Session is for, if it's this studio's. */
    private function orderFor(Studio $studio, array $cs): ?Order
    {
        $meta = $cs['metadata'] ?? [];
        if (($meta['loft_kind'] ?? null) !== 'series') {
            return null;
        }

        return Order::where('studio_id', $studio->id)->find((int) ($meta['loft_order_id'] ?? 0));
    }

    /** "You're signed up": the series, its days and time, the first class, and how make-ups work. */
    private function confirm(SeriesEnrollment $enrollment, int $booked, int $waitlisted): void
    {
        if (! $enrollment->user) {
            return;
        }
        $series = $enrollment->series;
        $studio = $series->studio;
        $first = $enrollment->bookings()->with('classSession')->get()->sortBy('classSession.starts_at')->first()?->classSession;
        $hours = (int) $studio->setting('late_cancel_hours');
        $details = array_filter([
            'Series' => $series->name,
            'Who' => $enrollment->attendee->name,
            'Classes' => $booked.($waitlisted ? " (and the waitlist for {$waitlisted} that ".($waitlisted === 1 ? 'was' : 'were').' full)' : ''),
            'First class' => $first ? $studio->local($first->starts_at)->format('l, F j \a\t g:i A') : null,
            'Paid' => $enrollment->price_cents > 0 ? Money::format($enrollment->price_cents, $studio->currency) : null,
        ]);
        $after = $series->makeups
            ? ["Can’t make a class? Cancel it from your account at least {$hours} hours before, and you’ll get a make-up class to use within two weeks of the series ending."]
            : ['Can’t make a class? Cancel it from your account so someone else can have the spot.'];
        $this->messages->email($enrollment->user, new StudioMessage($studio, 'You’re signed up for '.$series->name.'!',
            ["{$enrollment->attendee->first_name} is booked into every class in the series."], $details, $after,
            route('studio.account', $studio), 'View your classes', 'series_signup'), "series_signup:enrollment:{$enrollment->id}");
    }
}
