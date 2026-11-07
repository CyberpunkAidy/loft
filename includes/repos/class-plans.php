<?php
namespace Loft\Repos;

use Loft\DB;

defined( 'ABSPATH' ) || exit;

class Plans {

	const KINDS = [ 'subscription', 'season', 'pack', 'dropin' ];

	public static function find( int $id ): ?object {
		return DB::find( 'plans', $id );
	}

	public static function all( bool $active_only = false ): array {
		global $wpdb;
		$sql = 'SELECT * FROM ' . DB::table( 'plans' );
		if ( $active_only ) {
			$sql .= ' WHERE active = 1';
		}
		$sql .= ' ORDER BY sort ASC, id ASC';
		return $wpdb->get_results( $sql ) ?: []; // phpcs:ignore
	}

	public static function create( array $data ): int {
		$data['created_at'] = DB::now();
		return DB::insert( 'plans', self::clean( $data ) );
	}

	public static function update( int $id, array $data ): void {
		DB::update( 'plans', self::clean( $data ), [ 'id' => $id ] );
	}

	public static function delete( int $id ): void {
		global $wpdb;
		$wpdb->delete( DB::table( 'plans' ), [ 'id' => $id ] );
	}

	private static function clean( array $d ): array {
		$out = [];
		foreach ( $d as $k => $v ) {
			switch ( $k ) {
				case 'name':
				case 'stripe_product_id':
				case 'stripe_price_id':
				case 'stripe_fee_price_id':
				case 'billing_interval':
				case 'currency':
					$out[ $k ] = $v === null ? null : sanitize_text_field( (string) $v );
					break;
				case 'description':
					$out[ $k ] = sanitize_textarea_field( (string) $v );
					break;
				case 'kind':
					$out[ $k ] = in_array( $v, self::KINDS, true ) ? $v : 'pack';
					break;
				case 'program_scope':
					$out[ $k ] = is_array( $v ) ? wp_json_encode( array_values( array_map( 'sanitize_title', $v ) ) ) : ( $v === 'all' || $v === '' ? 'all' : wp_json_encode( array_map( 'sanitize_title', explode( ',', (string) $v ) ) ) );
					break;
				case 'active':
					$out[ $k ] = $v ? 1 : 0;
					break;
				default:
					$out[ $k ] = ( $v === '' || $v === null ) ? null : (int) $v;
			}
		}
		if ( isset( $out['kind'] ) ) {
			if ( $out['kind'] === 'dropin' ) {
				$out['credits'] = 1;
			}
			if ( in_array( $out['kind'], [ 'subscription', 'season' ], true ) ) {
				$out['billing_interval'] = 'month';
			} else {
				$out['billing_interval'] = null;
			}
		}
		return $out;
	}

	/** Program tag slugs the plan covers, or 'all'. */
	public static function scope( object $plan ) {
		if ( ! $plan->program_scope || $plan->program_scope === 'all' ) {
			return 'all';
		}
		return DB::json_decode( $plan->program_scope, [] );
	}

	/** Does a scope cover a program (by its loft_tag slugs)? */
	public static function scope_covers( $scope, int $program_id ): bool {
		if ( $scope === 'all' || $scope === null ) {
			return true;
		}
		$terms = get_the_terms( $program_id, 'loft_tag' );
		if ( ! is_array( $terms ) ) {
			return false;
		}
		foreach ( $terms as $t ) {
			if ( in_array( $t->slug, (array) $scope, true ) ) {
				return true;
			}
		}
		return false;
	}

	public static function eligible_for_program( int $program_id ): array {
		return array_values( array_filter( self::all( true ), fn( $p ) => self::scope_covers( self::scope( $p ), $program_id ) ) );
	}

	public static function to_api( object $p ): array {
		$season = $p->season_id ? Seasons::find( (int) $p->season_id ) : null;
		return [
			'id'                    => (int) $p->id,
			'name'                  => $p->name,
			'description'           => $p->description,
			'kind'                  => $p->kind,
			'price_cents'           => (int) $p->price_cents,
			'price_label'           => self::money( (int) $p->price_cents, $p->currency ),
			'currency'              => $p->currency,
			'interval'              => $p->billing_interval,
			'credits'               => $p->credits !== null ? (int) $p->credits : null,
			'credit_validity_days'  => $p->credit_validity_days !== null ? (int) $p->credit_validity_days : null,
			'weekly_limit'          => $p->weekly_limit !== null ? (int) $p->weekly_limit : null,
			'registration_fee_cents'=> (int) $p->registration_fee_cents,
			'season'                => $season ? [ 'id' => (int) $season->id, 'name' => $season->name, 'ends_on' => $season->ends_on ] : null,
			'scope'                 => self::scope( $p ),
			'active'                => (bool) $p->active,
			'stripe_ready'          => ! empty( $p->stripe_price_id ),
		];
	}

	public static function money( int $cents, string $currency = 'usd' ): string {
		$symbols = [ 'usd' => '$', 'cad' => 'CA$', 'eur' => '€', 'gbp' => '£', 'aud' => 'A$' ];
		$sym     = $symbols[ strtolower( $currency ) ] ?? strtoupper( $currency ) . ' ';
		return $sym . number_format( $cents / 100, 2 );
	}
}
