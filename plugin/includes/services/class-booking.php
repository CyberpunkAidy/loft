<?php
namespace Loft\Services;

use Loft\DB;
use Loft\Settings;
use Loft\Repos\Attendees;
use Loft\Repos\Bookings;
use Loft\Repos\Entitlements;
use Loft\Repos\Plans;
use Loft\Repos\Sessions;
use Loft\Repos\Waivers;

defined( 'ABSPATH' ) || exit;

/**
 * Booking business rules. All guards live here; REST and admin call into this.
 */
class Booking {

	/**
	 * @return array|\WP_Error  booking (API shape) or error with code: not_found|forbidden|waiver|closed|duplicate|needs_plan|cancelled
	 */
	public static function book( int $user_id, int $session_id, int $attendee_id, string $source = 'web', ?int $force_entitlement_id = null, bool $skip_entitlement = false ) {
		$session = Sessions::find( $session_id );
		if ( ! $session ) {
			return new \WP_Error( 'not_found', __( 'That class could not be found.', 'loft-studio' ), [ 'status' => 404 ] );
		}
		$attendee = Attendees::find( $attendee_id );
		if ( ! $attendee || (int) $attendee->account_user_id !== $user_id ) {
			return new \WP_Error( 'forbidden', __( 'That attendee is not on your account.', 'loft-studio' ), [ 'status' => 403 ] );
		}
		if ( $session->status !== 'scheduled' ) {
			return new \WP_Error( 'cancelled', __( 'This class has been cancelled.', 'loft-studio' ), [ 'status' => 409 ] );
		}
		if ( $session->starts_at <= DB::now() ) {
			return new \WP_Error( 'closed', __( 'This class has already started.', 'loft-studio' ), [ 'status' => 409 ] );
		}
		$closes = (int) Settings::get( 'booking_closes_minutes_before', 0 );
		if ( $closes > 0 && strtotime( $session->starts_at ) - $closes * 60 < time() ) {
			return new \WP_Error( 'closed', __( 'Booking for this class has closed.', 'loft-studio' ), [ 'status' => 409 ] );
		}
		$opens = (int) Settings::get( 'booking_opens_days_before', 30 );
		if ( $source === 'web' && $opens > 0 && strtotime( $session->starts_at ) - $opens * DAY_IN_SECONDS > time() ) {
			return new \WP_Error( 'closed', sprintf( __( 'Booking opens %d days before class.', 'loft-studio' ), $opens ), [ 'status' => 409 ] );
		}
		if ( Bookings::existing( $session_id, $attendee_id ) ) {
			return new \WP_Error( 'duplicate', __( 'Already booked for this class.', 'loft-studio' ), [ 'status' => 409 ] );
		}
		if ( $source === 'web' && ! Waivers::is_signed( $attendee_id ) ) {
			return new \WP_Error( 'waiver', __( 'Please sign the waiver for this attendee first.', 'loft-studio' ), [ 'status' => 428, 'attendee_id' => $attendee_id ] );
		}
		$age_err = self::check_age( $session, $attendee );
		if ( $age_err ) {
			return $age_err;
		}

		$entitlement = null;
		if ( ! $skip_entitlement ) {
			$entitlement = $force_entitlement_id ? Entitlements::find( $force_entitlement_id ) : Entitlements::usable_for( $user_id, $session );
			if ( ! $entitlement ) {
				$plans = array_map( [ Plans::class, 'to_api' ], Plans::eligible_for_program( (int) $session->program_id ) );
				return new \WP_Error( 'needs_plan', __( 'You need an active membership or class credits to book this class.', 'loft-studio' ), [ 'status' => 402, 'plans' => $plans, 'session_id' => $session_id ] );
			}
		}

		$is_full = Bookings::booked_count( $session_id ) >= (int) $session->capacity;
		$data    = [
			'session_id'      => $session_id,
			'attendee_id'     => $attendee_id,
			'account_user_id' => $user_id,
			'entitlement_id'  => $entitlement ? (int) $entitlement->id : null,
			'source'          => $source,
			'status'          => $is_full ? 'waitlisted' : 'booked',
			'position'        => $is_full ? Bookings::next_waitlist_position( $session_id ) : null,
			'credit_consumed' => 0,
		];
		if ( ! $is_full && $entitlement && $entitlement->kind === 'credits' ) {
			Entitlements::consume_credit( (int) $entitlement->id );
			$data['credit_consumed'] = 1;
		}
		$id      = Bookings::create( $data );
		$booking = Bookings::find( $id );

		do_action( 'loft_booking_created', $booking, $is_full );
		return Bookings::to_api( $booking );
	}

	private static function check_age( object $session, object $attendee ) {
		$meta = \Loft\PostTypes::get_program_meta( (int) $session->program_id );
		$age  = Attendees::age( $attendee );
		if ( $age === null ) {
			return null;
		}
		if ( $meta['min_age'] !== '' && $age < (int) $meta['min_age'] ) {
			return new \WP_Error( 'age', sprintf( __( 'This class is for ages %d and up.', 'loft-studio' ), (int) $meta['min_age'] ), [ 'status' => 409 ] );
		}
		if ( $meta['max_age'] !== '' && $age > (int) $meta['max_age'] ) {
			return new \WP_Error( 'age', sprintf( __( 'This class is for ages up to %d.', 'loft-studio' ), (int) $meta['max_age'] ), [ 'status' => 409 ] );
		}
		return null;
	}

	public static function is_late( string $starts_at_utc ): bool {
		$hours = (int) Settings::get( 'late_cancel_hours', 12 );
		return strtotime( $starts_at_utc ) - $hours * HOUR_IN_SECONDS < time();
	}

	/**
	 * Cancel a booking. Admin cancels are never "late".
	 */
	public static function cancel( int $booking_id, ?int $user_id = null, bool $by_admin = false ) {
		$b = Bookings::find( $booking_id );
		if ( ! $b ) {
			return new \WP_Error( 'not_found', __( 'Booking not found.', 'loft-studio' ), [ 'status' => 404 ] );
		}
		if ( ! $by_admin && (int) $b->account_user_id !== (int) $user_id ) {
			return new \WP_Error( 'forbidden', __( 'Not your booking.', 'loft-studio' ), [ 'status' => 403 ] );
		}
		if ( ! in_array( $b->status, [ 'booked', 'waitlisted' ], true ) ) {
			return new \WP_Error( 'invalid', __( 'This booking can no longer be cancelled.', 'loft-studio' ), [ 'status' => 409 ] );
		}
		$was_booked = $b->status === 'booked';
		$late       = ! $by_admin && $was_booked && self::is_late( $b->starts_at );
		$keep       = $late && ! Settings::get( 'late_cancel_keeps_credit' );

		Bookings::update( $booking_id, [
			'status'       => $late ? 'late_cancelled' : 'cancelled',
			'cancelled_at' => DB::now(),
		] );
		if ( $b->credit_consumed && $b->entitlement_id && ! $keep ) {
			Entitlements::refund_credit( (int) $b->entitlement_id );
			Bookings::update( $booking_id, [ 'credit_consumed' => 0 ] );
		}
		do_action( 'loft_booking_cancelled', Bookings::find( $booking_id ), $late );

		if ( $was_booked ) {
			self::promote_waitlist( (int) $b->session_id );
		}
		return [ 'status' => $late ? 'late_cancelled' : 'cancelled', 'credit_refunded' => ! $keep && (bool) $b->credit_consumed ];
	}

	/**
	 * Promote the first waitlisted booking into a freed seat.
	 */
	public static function promote_waitlist( int $session_id ): void {
		if ( ! Settings::get( 'waitlist_auto_promote', 1 ) ) {
			return;
		}
		$session = Sessions::find( $session_id );
		if ( ! $session || $session->status !== 'scheduled' || $session->starts_at <= DB::now() ) {
			return;
		}
		while ( Bookings::booked_count( $session_id ) < (int) $session->capacity ) {
			$next = Bookings::first_waitlisted( $session_id );
			if ( ! $next ) {
				return;
			}
			$entitlement = $next->entitlement_id ? Entitlements::find( (int) $next->entitlement_id ) : null;
			// Re-validate credits: if their pack ran out meanwhile, try to find another, else drop them from the list.
			if ( $entitlement && $entitlement->kind === 'credits' && (int) $entitlement->credits_remaining < 1 ) {
				$entitlement = Entitlements::usable_for( (int) $next->account_user_id, $session );
				if ( ! $entitlement ) {
					Bookings::update( (int) $next->id, [ 'status' => 'cancelled', 'cancelled_at' => DB::now() ] );
					continue;
				}
			}
			$update = [ 'status' => 'booked', 'position' => null, 'source' => 'waitlist_promote' ];
			if ( $entitlement ) {
				$update['entitlement_id'] = (int) $entitlement->id;
				if ( $entitlement->kind === 'credits' ) {
					Entitlements::consume_credit( (int) $entitlement->id );
					$update['credit_consumed'] = 1;
				}
			}
			Bookings::update( (int) $next->id, $update );
			do_action( 'loft_waitlist_promoted', Bookings::find( (int) $next->id ) );
		}
	}

	/**
	 * Mark attendance. $status: attended|no_show|booked (undo).
	 */
	public static function mark( int $booking_id, string $status ): bool {
		$b = Bookings::find( $booking_id );
		if ( ! $b || ! in_array( $status, [ 'attended', 'no_show', 'booked' ], true ) ) {
			return false;
		}
		$update = [ 'status' => $status, 'attended_at' => $status === 'attended' ? DB::now() : null ];
		// No-show policy: credit packs are consumed at booking time. If the studio is lenient
		// (no_show_consumes_credit = 0), hand the credit back on a no-show; take it again if undone.
		if ( ! Settings::get( 'no_show_consumes_credit', 1 ) && $b->entitlement_id ) {
			if ( $status === 'no_show' && $b->credit_consumed ) {
				Entitlements::refund_credit( (int) $b->entitlement_id );
				$update['credit_consumed'] = 0;
			} elseif ( $status !== 'no_show' && $b->status === 'no_show' && ! $b->credit_consumed ) {
				$e = Entitlements::find( (int) $b->entitlement_id );
				if ( $e && $e->kind === 'credits' ) {
					Entitlements::consume_credit( (int) $e->id );
					$update['credit_consumed'] = 1;
				}
			}
		}
		Bookings::update( $booking_id, $update );
		return true;
	}

	/**
	 * Cancel a whole session (admin). Cancels bookings, refunds credits, emails members.
	 */
	public static function cancel_session( int $session_id ): int {
		$n = 0;
		Sessions::update( $session_id, [ 'status' => 'cancelled' ] );
		foreach ( Bookings::for_session( $session_id ) as $b ) {
			if ( ! in_array( $b->status, [ 'booked', 'waitlisted' ], true ) ) {
				continue;
			}
			Bookings::update( (int) $b->id, [ 'status' => 'cancelled', 'cancelled_at' => DB::now() ] );
			if ( $b->credit_consumed && $b->entitlement_id ) {
				Entitlements::refund_credit( (int) $b->entitlement_id );
			}
			do_action( 'loft_session_cancelled_booking', Bookings::find( (int) $b->id ) );
			$n++;
		}
		return $n;
	}
}
