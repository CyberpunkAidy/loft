<?php
namespace Loft\Repos;

use Loft\DB;
use Loft\PostTypes;

defined( 'ABSPATH' ) || exit;

class Sessions {

	/** Base select with booked/waitlisted counts and program info joined. */
	private static function base_sql(): string {
		global $wpdb;
		$s = DB::table( 'sessions' );
		$b = DB::table( 'bookings' );
		return "SELECT s.*, p.post_title AS program_title,
			(SELECT COUNT(*) FROM {$b} b WHERE b.session_id = s.id AND b.status IN ('booked','attended','no_show')) AS booked_count,
			(SELECT COUNT(*) FROM {$b} b WHERE b.session_id = s.id AND b.status = 'waitlisted') AS waitlist_count
			FROM {$s} s
			LEFT JOIN {$wpdb->posts} p ON p.ID = s.program_id";
	}

	public static function find( int $id ): ?object {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( self::base_sql() . ' WHERE s.id = %d', $id ) ); // phpcs:ignore
		return $row ?: null;
	}

	public static function for_program( int $program_id, int $limit = 100 ): array {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( self::base_sql() . ' WHERE s.program_id = %d ORDER BY s.starts_at ASC LIMIT %d', $program_id, $limit ) ) ?: []; // phpcs:ignore
	}

	/**
	 * Sessions in a UTC window, optionally filtered by taxonomy term slugs.
	 */
	public static function between( string $from_utc, string $to_utc, array $filters = [] ): array {
		global $wpdb;
		$where  = [ 's.starts_at >= %s', 's.starts_at < %s', "p.post_status = 'publish'" ];
		$params = [ $from_utc, $to_utc ];

		if ( empty( $filters['include_cancelled'] ) ) {
			$where[] = "s.status = 'scheduled'";
		}
		foreach ( [ 'group' => 'loft_age_group', 'level' => 'loft_level', 'tag' => 'loft_tag' ] as $k => $tax ) {
			if ( ! empty( $filters[ $k ] ) ) {
				$ids = get_objects_in_term( self::term_ids( $tax, (array) $filters[ $k ] ), $tax );
				if ( is_wp_error( $ids ) || ! $ids ) {
					return [];
				}
				$where[] = 's.program_id IN (' . implode( ',', array_map( 'intval', $ids ) ) . ')';
			}
		}
		if ( ! empty( $filters['program_id'] ) ) {
			$where[]  = 's.program_id = %d';
			$params[] = (int) $filters['program_id'];
		}
		$sql = self::base_sql() . ' WHERE ' . implode( ' AND ', $where ) . ' ORDER BY s.starts_at ASC LIMIT 500';
		return $wpdb->get_results( $wpdb->prepare( $sql, ...$params ) ) ?: []; // phpcs:ignore
	}

	private static function term_ids( string $tax, array $slugs ): array {
		$ids = [];
		foreach ( $slugs as $slug ) {
			$t = get_term_by( 'slug', sanitize_title( $slug ), $tax );
			if ( $t ) {
				$ids[] = (int) $t->term_id;
			}
		}
		return $ids ?: [ 0 ];
	}

	public static function create( array $data ): int {
		$data['created_at'] = DB::now();
		return DB::insert( 'sessions', $data );
	}

	public static function update( int $id, array $data ): void {
		DB::update( 'sessions', $data, [ 'id' => $id ] );
	}

	public static function delete( int $id ): void {
		global $wpdb;
		$wpdb->delete( DB::table( 'sessions' ), [ 'id' => $id ] );
	}

	/**
	 * Generate sessions for a program from its weekday/time/season (or event_date for one-offs).
	 * Skips dates that already have a session. Returns number created.
	 */
	public static function generate_for_program( int $program_id ): int {
		global $wpdb;
		$meta = PostTypes::get_program_meta( $program_id );
		$tz   = wp_timezone();
		$created = 0;

		$existing = $wpdb->get_col( $wpdb->prepare( 'SELECT starts_at FROM ' . DB::table( 'sessions' ) . ' WHERE program_id = %d', $program_id ) ); // phpcs:ignore
		$existing = array_flip( $existing ?: [] );

		$duration = max( 15, (int) $meta['duration_min'] );
		$capacity = max( 1, (int) $meta['capacity'] );
		$time     = $meta['start_time'] ?: '17:00';

		$dates = [];
		if ( $meta['type'] === 'event' ) {
			if ( $meta['event_date'] ) {
				$dates[] = $meta['event_date'];
			}
		} else {
			$season = $meta['season_id'] ? Seasons::find( (int) $meta['season_id'] ) : Seasons::current();
			if ( ! $season || $meta['weekday'] === '' ) {
				return 0;
			}
			$cursor = new \DateTime( $season->starts_on, $tz );
			$end    = new \DateTime( $season->ends_on, $tz );
			$wd     = (int) $meta['weekday'];
			while ( $cursor <= $end ) {
				if ( (int) $cursor->format( 'w' ) === $wd ) {
					$dates[] = $cursor->format( 'Y-m-d' );
				}
				$cursor->modify( '+1 day' );
			}
		}

		foreach ( $dates as $date ) {
			$start = new \DateTime( $date . ' ' . $time, $tz );
			$end   = ( clone $start )->modify( '+' . $duration . ' minutes' );
			$start_utc = ( clone $start )->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
			if ( isset( $existing[ $start_utc ] ) ) {
				continue;
			}
			self::create( [
				'program_id'    => $program_id,
				'season_id'     => $meta['season_id'] ?: null,
				'starts_at'     => $start_utc,
				'ends_at'       => ( clone $end )->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ),
				'capacity'      => $capacity,
				'status'        => 'scheduled',
				'instructor_id' => $meta['instructor_id'] ?: null,
				'price_cents'   => $meta['dropin_price'] !== '' ? (int) round( (float) $meta['dropin_price'] * 100 ) : null,
			] );
			$created++;
		}
		return $created;
	}

	/** Shape a session row for the REST API. */
	public static function to_api( object $s ): array {
		$start = DB::utc_to_local( $s->starts_at );
		$end   = DB::utc_to_local( $s->ends_at );
		$program = get_post( (int) $s->program_id );
		$terms = function ( $tax ) use ( $s ) {
			$t = get_the_terms( (int) $s->program_id, $tax );
			return is_array( $t ) ? array_values( array_map( fn( $x ) => [ 'slug' => $x->slug, 'name' => $x->name ], $t ) ) : [];
		};
		$instructor = $s->instructor_id ? get_userdata( (int) $s->instructor_id ) : null;
		$meta = PostTypes::get_program_meta( (int) $s->program_id );
		$spots = max( 0, (int) $s->capacity - (int) $s->booked_count );
		return [
			'id'             => (int) $s->id,
			'program_id'     => (int) $s->program_id,
			'program_title'  => $s->program_title,
			'program_url'    => $program ? get_permalink( $program ) : null,
			'program_type'   => $meta['type'] ?: 'class',
			'excerpt'        => $program ? wp_strip_all_tags( get_the_excerpt( $program ) ) : '',
			'image'          => $program ? get_the_post_thumbnail_url( $program, 'medium' ) : null,
			'starts_at'      => $start->format( DATE_ATOM ),
			'ends_at'        => $end->format( DATE_ATOM ),
			'date_label'     => $start->format( 'D, M j' ),
			'time_label'     => $start->format( 'g:i' ) . ' – ' . $end->format( 'g:i A' ),
			'weekday'        => $start->format( 'l' ),
			'capacity'       => (int) $s->capacity,
			'booked'         => (int) $s->booked_count,
			'waitlist'       => (int) $s->waitlist_count,
			'spots_left'     => $spots,
			'is_full'        => $spots === 0,
			'status'         => $s->status,
			'price_cents'    => $s->price_cents !== null ? (int) $s->price_cents : null,
			'members_only'   => (bool) $meta['members_only'],
			'min_age'        => $meta['min_age'] !== '' ? (int) $meta['min_age'] : null,
			'max_age'        => $meta['max_age'] !== '' ? (int) $meta['max_age'] : null,
			'instructor'     => $instructor ? $instructor->display_name : null,
			'age_groups'     => $terms( 'loft_age_group' ),
			'levels'         => $terms( 'loft_level' ),
			'tags'           => array_map( fn( $t ) => $t['slug'], $terms( 'loft_tag' ) ),
			'notes'          => $s->notes,
		];
	}
}
