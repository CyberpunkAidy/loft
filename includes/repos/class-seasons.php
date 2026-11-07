<?php
namespace Loft\Repos;

use Loft\DB;

defined( 'ABSPATH' ) || exit;

class Seasons {

	public static function all(): array {
		global $wpdb;
		return $wpdb->get_results( 'SELECT * FROM ' . DB::table( 'seasons' ) . ' ORDER BY starts_on DESC' ) ?: []; // phpcs:ignore
	}

	public static function find( int $id ): ?object {
		return DB::find( 'seasons', $id );
	}

	public static function current(): ?object {
		global $wpdb;
		$row = $wpdb->get_row( 'SELECT * FROM ' . DB::table( 'seasons' ) . ' WHERE is_current = 1 ORDER BY starts_on DESC LIMIT 1' ); // phpcs:ignore
		return $row ?: null;
	}

	public static function create( string $name, string $starts_on, string $ends_on, bool $current = false ): int {
		if ( $current ) {
			DB::update( 'seasons', [ 'is_current' => 0 ], [ 'is_current' => 1 ] );
		}
		return DB::insert( 'seasons', [
			'name'       => $name,
			'starts_on'  => $starts_on,
			'ends_on'    => $ends_on,
			'is_current' => $current ? 1 : 0,
			'created_at' => DB::now(),
		] );
	}

	public static function update( int $id, array $data ): void {
		if ( ! empty( $data['is_current'] ) ) {
			DB::update( 'seasons', [ 'is_current' => 0 ], [ 'is_current' => 1 ] );
		}
		DB::update( 'seasons', $data, [ 'id' => $id ] );
	}

	public static function delete( int $id ): void {
		global $wpdb;
		$wpdb->delete( DB::table( 'seasons' ), [ 'id' => $id ] );
	}
}
