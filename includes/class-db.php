<?php
namespace Loft;

defined( 'ABSPATH' ) || exit;

/**
 * Small helpers shared by the repositories.
 */
class DB {

	public static function table( string $name ): string {
		global $wpdb;
		return $wpdb->prefix . 'loft_' . $name;
	}

	/** Current UTC time in MySQL format. */
	public static function now(): string {
		return gmdate( 'Y-m-d H:i:s' );
	}

	/** Convert a local (site timezone) date/time string to UTC MySQL datetime. */
	public static function local_to_utc( string $local ): string {
		$dt = new \DateTime( $local, wp_timezone() );
		$dt->setTimezone( new \DateTimeZone( 'UTC' ) );
		return $dt->format( 'Y-m-d H:i:s' );
	}

	/** Convert UTC MySQL datetime to a DateTime in the site timezone. */
	public static function utc_to_local( string $utc ): \DateTime {
		$dt = new \DateTime( $utc, new \DateTimeZone( 'UTC' ) );
		return $dt->setTimezone( wp_timezone() );
	}

	public static function insert( string $table, array $data ): int {
		global $wpdb;
		$wpdb->insert( self::table( $table ), $data );
		return (int) $wpdb->insert_id;
	}

	public static function update( string $table, array $data, array $where ): int {
		global $wpdb;
		return (int) $wpdb->update( self::table( $table ), $data, $where );
	}

	public static function find( string $table, int $id ): ?object {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table( $table ) . ' WHERE id = %d', $id ) ); // phpcs:ignore
		return $row ?: null;
	}

	public static function json_decode( $value, $default = [] ) {
		if ( is_array( $value ) ) {
			return $value;
		}
		if ( ! is_string( $value ) || $value === '' ) {
			return $default;
		}
		$decoded = json_decode( $value, true );
		return is_array( $decoded ) ? $decoded : $default;
	}
}
