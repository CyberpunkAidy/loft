<?php
namespace Loft;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin settings stored in a single option array.
 */
class Settings {

	const OPTION = 'loft_settings';

	private static ?array $cache = null;

	public static function defaults(): array {
		return [
			'studio_name'                => get_bloginfo( 'name' ),
			'currency'                   => 'usd',
			'stripe_mode'                => 'test',
			'stripe_test_publishable'    => '',
			'stripe_test_secret'         => '',
			'stripe_test_webhook_secret' => '',
			'stripe_live_publishable'    => '',
			'stripe_live_secret'         => '',
			'stripe_live_webhook_secret' => '',
			'late_cancel_hours'          => 12,
			'late_cancel_keeps_credit'   => 0, // 1 = member keeps credit on late cancel.
			'no_show_consumes_credit'    => 1,
			'booking_opens_days_before'  => 30,
			'booking_closes_minutes_before' => 0,
			'credit_validity_days'       => 90,
			'waiver_validity_days'       => 365,
			'reminder_hours_before'      => 24,
			'waitlist_auto_promote'      => 1,
			'email_from_name'            => get_bloginfo( 'name' ),
			'email_from_address'         => get_option( 'admin_email' ),
			'account_page_id'            => 0,
			'schedule_page_id'           => 0,
			'waiver_page_id'             => 0,
			'plans_page_id'              => 0,
			'minor_age'                  => 18,
			'accent_color'               => '#c2410c',
		];
	}

	public static function all(): array {
		if ( self::$cache === null ) {
			$stored      = get_option( self::OPTION, [] );
			self::$cache = array_merge( self::defaults(), is_array( $stored ) ? $stored : [] );
		}
		return self::$cache;
	}

	public static function get( string $key, $default = null ) {
		$all = self::all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : $default;
	}

	public static function update( array $values ): void {
		$merged = array_merge( self::all(), $values );
		update_option( self::OPTION, $merged );
		self::$cache = $merged;
	}

	public static function stripe_secret(): string {
		return self::get( 'stripe_mode' ) === 'live' ? (string) self::get( 'stripe_live_secret' ) : (string) self::get( 'stripe_test_secret' );
	}

	public static function stripe_publishable(): string {
		return self::get( 'stripe_mode' ) === 'live' ? (string) self::get( 'stripe_live_publishable' ) : (string) self::get( 'stripe_test_publishable' );
	}

	public static function stripe_webhook_secret(): string {
		return self::get( 'stripe_mode' ) === 'live' ? (string) self::get( 'stripe_live_webhook_secret' ) : (string) self::get( 'stripe_test_webhook_secret' );
	}

	public static function page_url( string $which ): string {
		$id = (int) self::get( $which . '_page_id' );
		return $id ? (string) get_permalink( $id ) : home_url( '/' );
	}
}
