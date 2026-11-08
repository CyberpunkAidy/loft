<?php
namespace Loft\Repos;

use Loft\DB;

defined( 'ABSPATH' ) || exit;

class Bookings {

	private static function base_sql(): string {
		global $wpdb;
		return 'SELECT b.*, s.starts_at, s.ends_at, s.program_id, s.capacity, s.status AS session_status, p.post_title AS program_title,
				a.first_name, a.last_name
			FROM ' . DB::table( 'bookings' ) . ' b
			JOIN ' . DB::table( 'sessions' ) . ' s ON s.id = b.session_id
			LEFT JOIN ' . $wpdb->posts . ' p ON p.ID = s.program_id
			LEFT JOIN ' . DB::table( 'attendees' ) . ' a ON a.id = b.attendee_id';
	}

	public static function find( int $id ): ?object {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( self::base_sql() . ' WHERE b.id = %d', $id ) ); // phpcs:ignore
		return $row ?: null;
	}

	public static function for_user( int $user_id, bool $upcoming_only = true ): array {
		global $wpdb;
		$sql    = self::base_sql() . ' WHERE b.account_user_id = %d';
		$params = [ $user_id ];
		if ( $upcoming_only ) {
			$sql     .= " AND s.ends_at >= %s AND b.status IN ('booked','waitlisted')";
			$params[] = DB::now();
		}
		$sql .= ' ORDER BY s.starts_at ASC LIMIT 200';
		return $wpdb->get_results( $wpdb->prepare( $sql, ...$params ) ) ?: []; // phpcs:ignore
	}

	public static function for_session( int $session_id ): array {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( self::base_sql() . " WHERE b.session_id = %d AND b.status <> 'cancelled' ORDER BY FIELD(b.status,'booked','attended','no_show','waitlisted','late_cancelled'), b.position ASC, b.booked_at ASC", $session_id ) ) ?: []; // phpcs:ignore
	}

	public static function existing( int $session_id, int $attendee_id ): ?object {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . DB::table( 'bookings' ) . " WHERE session_id = %d AND attendee_id = %d AND status IN ('booked','waitlisted') LIMIT 1", $session_id, $attendee_id ) ); // phpcs:ignore
		return $row ?: null;
	}

	public static function booked_count( int $session_id ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . DB::table( 'bookings' ) . " WHERE session_id = %d AND status IN ('booked','attended','no_show')", $session_id ) ); // phpcs:ignore
	}

	public static function next_waitlist_position( int $session_id ): int {
		global $wpdb;
		return 1 + (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(MAX(position),0) FROM ' . DB::table( 'bookings' ) . " WHERE session_id = %d AND status = 'waitlisted'", $session_id ) ); // phpcs:ignore
	}

	public static function first_waitlisted( int $session_id ): ?object {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . DB::table( 'bookings' ) . " WHERE session_id = %d AND status = 'waitlisted' ORDER BY position ASC, booked_at ASC LIMIT 1", $session_id ) ); // phpcs:ignore
		return $row ?: null;
	}

	public static function create( array $data ): int {
		$data['booked_at'] = DB::now();
		return DB::insert( 'bookings', $data );
	}

	public static function update( int $id, array $data ): void {
		DB::update( 'bookings', $data, [ 'id' => $id ] );
	}

	/** Bookings for sessions starting within [$from, $to) UTC, used for reminders. */
	public static function starting_between( string $from, string $to ): array {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( self::base_sql() . " WHERE b.status = 'booked' AND s.status = 'scheduled' AND s.starts_at >= %s AND s.starts_at < %s", $from, $to ) ) ?: []; // phpcs:ignore
	}

	public static function to_api( object $b ): array {
		$start = DB::utc_to_local( $b->starts_at );
		$end   = DB::utc_to_local( $b->ends_at );
		return [
			'id'             => (int) $b->id,
			'session_id'     => (int) $b->session_id,
			'program_id'     => (int) $b->program_id,
			'program_title'  => $b->program_title,
			'attendee_id'    => (int) $b->attendee_id,
			'attendee_name'  => trim( ( $b->first_name ?? '' ) . ' ' . ( $b->last_name ?? '' ) ),
			'status'         => $b->status,
			'position'       => $b->position !== null ? (int) $b->position : null,
			'starts_at'      => $start->format( DATE_ATOM ),
			'ends_at'        => $end->format( DATE_ATOM ),
			'date_label'     => $start->format( 'D, M j' ),
			'time_label'     => $start->format( 'g:i' ) . ' – ' . $end->format( 'g:i A' ),
			'session_status' => $b->session_status,
			'can_cancel'     => in_array( $b->status, [ 'booked', 'waitlisted' ], true ) && $b->starts_at > DB::now(),
			'is_late'        => \Loft\Services\Booking::is_late( $b->starts_at ),
		];
	}
}
