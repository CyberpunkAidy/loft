<?php
namespace Loft\Repos;

use Loft\DB;
use Loft\Settings;

defined( 'ABSPATH' ) || exit;

class Attendees {

	public static function find( int $id ): ?object {
		return DB::find( 'attendees', $id );
	}

	public static function for_user( int $user_id, bool $include_archived = false ): array {
		global $wpdb;
		$sql = 'SELECT * FROM ' . DB::table( 'attendees' ) . ' WHERE account_user_id = %d';
		if ( ! $include_archived ) {
			$sql .= " AND status = 'active'";
		}
		$sql .= ' ORDER BY is_self DESC, first_name ASC';
		return $wpdb->get_results( $wpdb->prepare( $sql, $user_id ) ) ?: []; // phpcs:ignore
	}

	public static function belongs_to( int $attendee_id, int $user_id ): bool {
		$a = self::find( $attendee_id );
		return $a && (int) $a->account_user_id === $user_id;
	}

	public static function create( int $user_id, array $data ): int {
		return DB::insert( 'attendees', [
			'account_user_id'   => $user_id,
			'first_name'        => sanitize_text_field( $data['first_name'] ?? '' ),
			'last_name'         => sanitize_text_field( $data['last_name'] ?? '' ),
			'dob'               => self::clean_date( $data['dob'] ?? '' ),
			'is_self'           => ! empty( $data['is_self'] ) ? 1 : 0,
			'medical_notes'     => sanitize_textarea_field( $data['medical_notes'] ?? '' ),
			'emergency_contact' => sanitize_text_field( $data['emergency_contact'] ?? '' ),
			'status'            => 'active',
			'created_at'        => DB::now(),
		] );
	}

	public static function update( int $id, array $data ): void {
		$clean = [];
		foreach ( [ 'first_name', 'last_name', 'emergency_contact' ] as $k ) {
			if ( array_key_exists( $k, $data ) ) {
				$clean[ $k ] = sanitize_text_field( (string) $data[ $k ] );
			}
		}
		if ( array_key_exists( 'medical_notes', $data ) ) {
			$clean['medical_notes'] = sanitize_textarea_field( (string) $data['medical_notes'] );
		}
		if ( array_key_exists( 'dob', $data ) ) {
			$clean['dob'] = self::clean_date( (string) $data['dob'] );
		}
		if ( array_key_exists( 'status', $data ) && in_array( $data['status'], [ 'active', 'archived' ], true ) ) {
			$clean['status'] = $data['status'];
		}
		if ( $clean ) {
			DB::update( 'attendees', $clean, [ 'id' => $id ] );
		}
	}

	private static function clean_date( string $d ): ?string {
		if ( ! $d ) {
			return null;
		}
		$ts = strtotime( $d );
		return $ts ? gmdate( 'Y-m-d', $ts ) : null;
	}

	public static function age( object $a ): ?int {
		if ( ! $a->dob ) {
			return null;
		}
		return (int) ( new \DateTime( $a->dob ) )->diff( new \DateTime( 'today' ) )->y;
	}

	public static function is_minor( object $a ): bool {
		$age = self::age( $a );
		return $age !== null && $age < (int) Settings::get( 'minor_age', 18 );
	}

	public static function to_api( object $a ): array {
		return [
			'id'                => (int) $a->id,
			'first_name'        => $a->first_name,
			'last_name'         => $a->last_name,
			'name'              => trim( $a->first_name . ' ' . $a->last_name ),
			'dob'               => $a->dob,
			'age'               => self::age( $a ),
			'is_self'           => (bool) $a->is_self,
			'is_minor'          => self::is_minor( $a ),
			'medical_notes'     => $a->medical_notes,
			'emergency_contact' => $a->emergency_contact,
			'status'            => $a->status,
			'waiver'            => Waivers::status_for_attendee( (int) $a->id ),
		];
	}
}
