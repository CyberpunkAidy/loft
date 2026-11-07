<?php
namespace Loft;

use Loft\Repos\Bookings;

defined( 'ABSPATH' ) || exit;

/**
 * Hourly housekeeping: reminders, credit expiry warnings, entitlement expiry.
 */
class Cron {

	public static function init(): void {
		add_action( 'loft_hourly', [ __CLASS__, 'run' ] );
	}

	private static function once( string $kind, int $ref_id ): bool {
		global $wpdb;
		$ok = $wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO ' . DB::table( 'notifications' ) . ' (kind, ref_id, sent_at) VALUES (%s, %d, %s)', $kind, $ref_id, DB::now() ) ); // phpcs:ignore
		return (bool) $ok;
	}

	public static function run(): void {
		self::reminders();
		self::expire_entitlements();
		self::credit_warnings();
	}

	private static function reminders(): void {
		$hours = (int) Settings::get( 'reminder_hours_before', 24 );
		if ( $hours <= 0 ) {
			return;
		}
		// Sessions starting between (now + hours - 1h) and (now + hours) — the hourly window.
		$from = gmdate( 'Y-m-d H:i:s', time() + $hours * HOUR_IN_SECONDS - HOUR_IN_SECONDS );
		$to   = gmdate( 'Y-m-d H:i:s', time() + $hours * HOUR_IN_SECONDS + 5 * MINUTE_IN_SECONDS );
		foreach ( Bookings::starting_between( $from, $to ) as $b ) {
			if ( self::once( 'reminder', (int) $b->id ) ) {
				Emails::reminder( $b );
			}
		}
	}

	private static function expire_entitlements(): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . DB::table( 'entitlements' ) . " SET status = 'expired', updated_at = %s WHERE status = 'active' AND expires_at IS NOT NULL AND expires_at < %s AND kind = 'credits'", DB::now(), DB::now() ) ); // phpcs:ignore
	}

	private static function credit_warnings(): void {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . DB::table( 'entitlements' ) . " WHERE kind = 'credits' AND status = 'active' AND credits_remaining > 0 AND expires_at BETWEEN %s AND %s", DB::now(), gmdate( 'Y-m-d H:i:s', time() + 7 * DAY_IN_SECONDS ) ) ); // phpcs:ignore
		foreach ( $rows ?: [] as $e ) {
			if ( self::once( 'credits_expiring', (int) $e->id ) ) {
				Emails::credits_expiring( $e );
			}
		}
	}
}
