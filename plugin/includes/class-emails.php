<?php
namespace Loft;

use Loft\Repos\Bookings;

defined( 'ABSPATH' ) || exit;

/**
 * Transactional emails. All subjects/bodies are filterable: loft_email_{key}.
 * SMS: fires do_action('loft_send_sms', $user_id, $message) for an adapter plugin (v2).
 */
class Emails {

	public static function init(): void {
		add_action( 'loft_booking_created', [ __CLASS__, 'booking_created' ], 10, 2 );
		add_action( 'loft_booking_cancelled', [ __CLASS__, 'booking_cancelled' ], 10, 2 );
		add_action( 'loft_waitlist_promoted', [ __CLASS__, 'waitlist_promoted' ] );
		add_action( 'loft_session_cancelled_booking', [ __CLASS__, 'session_cancelled' ] );
		add_action( 'loft_entitlement_granted', [ __CLASS__, 'entitlement_granted' ], 10, 3 );
		add_action( 'loft_payment_failed', [ __CLASS__, 'payment_failed' ] );
		add_action( 'loft_member_registered', [ __CLASS__, 'welcome' ] );
	}

	private static function send( int $user_id, string $key, string $subject, string $body, array $ctx = [] ): void {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return;
		}
		$subject = apply_filters( 'loft_email_subject_' . $key, $subject, $ctx, $user );
		$body    = apply_filters( 'loft_email_body_' . $key, $body, $ctx, $user );
		$studio  = Settings::get( 'studio_name' );
		$html    = self::wrap( $body, $studio );
		$headers = [
			'Content-Type: text/html; charset=UTF-8',
			'From: ' . Settings::get( 'email_from_name' ) . ' <' . Settings::get( 'email_from_address' ) . '>',
		];
		wp_mail( $user->user_email, '[' . $studio . '] ' . $subject, $html, $headers );
		do_action( 'loft_send_sms', $user_id, wp_strip_all_tags( $subject ), $key, $ctx );
	}

	private static function wrap( string $body, string $studio ): string {
		$accent  = esc_attr( Settings::get( 'accent_color', '#c2410c' ) );
		$account = esc_url( Settings::page_url( 'account' ) );
		return '<!doctype html><html><body style="margin:0;background:#f6f5f4;font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#1c1917">'
			. '<div style="max-width:560px;margin:24px auto;background:#fff;border-radius:12px;overflow:hidden;border:1px solid #e7e5e4">'
			. '<div style="background:' . $accent . ';color:#fff;padding:16px 24px;font-weight:700;font-size:18px">' . esc_html( $studio ) . '</div>'
			. '<div style="padding:24px;font-size:15px;line-height:1.55">' . $body . '</div>'
			. '<div style="padding:12px 24px 20px;font-size:12px;color:#78716c">Manage your classes and billing in <a href="' . $account . '" style="color:' . $accent . '">your account</a>.</div>'
			. '</div></body></html>';
	}

	private static function line( object $b ): string {
		$a = Bookings::to_api( $b );
		return '<strong>' . esc_html( $a['program_title'] ) . '</strong><br>' . esc_html( $a['date_label'] . ' · ' . $a['time_label'] ) . '<br>Attendee: ' . esc_html( $a['attendee_name'] );
	}

	public static function booking_created( object $b, bool $waitlisted ): void {
		if ( $waitlisted ) {
			self::send( (int) $b->account_user_id, 'waitlisted', 'You\'re on the waitlist', '<p>That class is full, so we\'ve added you to the waitlist (position #' . (int) $b->position . '). We\'ll email you the moment a spot opens.</p><p>' . self::line( $b ) . '</p>', [ 'booking' => $b ] );
		} else {
			$hours = (int) Settings::get( 'late_cancel_hours', 12 );
			self::send( (int) $b->account_user_id, 'booked', 'You\'re booked!', '<p>See you in the air.</p><p>' . self::line( $b ) . '</p><p style="color:#78716c;font-size:13px">Need to cancel? Do it from your account at least ' . $hours . ' hours before class to keep your credit.</p>', [ 'booking' => $b ] );
		}
	}

	public static function booking_cancelled( object $b, bool $late ): void {
		$msg = $late ? '<p>Your booking was cancelled inside the late-cancellation window.</p>' : '<p>Your booking has been cancelled.</p>';
		self::send( (int) $b->account_user_id, 'cancelled', 'Booking cancelled', $msg . '<p>' . self::line( $b ) . '</p>', [ 'booking' => $b, 'late' => $late ] );
	}

	public static function waitlist_promoted( object $b ): void {
		self::send( (int) $b->account_user_id, 'promoted', 'A spot opened up — you\'re in!', '<p>Good news: a spot opened and you\'ve been moved off the waitlist.</p><p>' . self::line( $b ) . '</p><p>If you can no longer make it, please cancel from your account so someone else can have the spot.</p>', [ 'booking' => $b ] );
	}

	public static function session_cancelled( object $b ): void {
		self::send( (int) $b->account_user_id, 'session_cancelled', 'Class cancelled', '<p>We\'re sorry — this class has been cancelled by the studio. Any credit used has been returned to your account.</p><p>' . self::line( $b ) . '</p>', [ 'booking' => $b ] );
	}

	public static function entitlement_granted( object $e, object $plan, int $user_id ): void {
		$what = $e->kind === 'credits' ? (int) $e->credits_total . ' class credits' : 'your ' . esc_html( $plan->name );
		self::send( $user_id, 'purchase', 'Thanks for your purchase', '<p>You now have ' . $what . '. Head to the schedule to book your classes.</p>', [ 'entitlement' => $e, 'plan' => $plan ] );
	}

	public static function payment_failed( object $e ): void {
		self::send( (int) $e->account_user_id, 'payment_failed', 'Payment failed — action needed', '<p>We couldn\'t process your latest membership payment. Please update your card from the <a href="' . esc_url( Settings::page_url( 'account' ) ) . '#billing">billing tab</a> of your account. Bookings are paused until payment goes through.</p>', [ 'entitlement' => $e ] );
	}

	public static function welcome( int $user_id ): void {
		self::send( $user_id, 'welcome', 'Welcome!', '<p>Your account is ready. Next steps:</p><ol><li>Add family members who\'ll be attending (or yourself).</li><li>Sign the waiver for each attendee.</li><li>Pick a plan and book your first class.</li></ol>', [] );
	}

	public static function reminder( object $b ): void {
		self::send( (int) $b->account_user_id, 'reminder', 'Reminder: class coming up', '<p>Just a reminder about your upcoming class.</p><p>' . self::line( $b ) . '</p>', [ 'booking' => $b ] );
	}

	public static function credits_expiring( object $e ): void {
		self::send( (int) $e->account_user_id, 'credits_expiring', 'Your class credits expire soon', '<p>You have ' . (int) $e->credits_remaining . ' credits expiring on ' . esc_html( DB::utc_to_local( $e->expires_at )->format( 'M j' ) ) . '. Book a class to use them!</p>', [ 'entitlement' => $e ] );
	}
}
