<?php
namespace Loft\Repos;

use Loft\DB;

defined( 'ABSPATH' ) || exit;

class Entitlements {

	public static function find( int $id ): ?object {
		return DB::find( 'entitlements', $id );
	}

	public static function for_user( int $user_id, bool $active_only = false ): array {
		global $wpdb;
		$sql = 'SELECT e.*, p.name AS plan_name, p.kind AS plan_kind FROM ' . DB::table( 'entitlements' ) . ' e LEFT JOIN ' . DB::table( 'plans' ) . ' p ON p.id = e.plan_id WHERE e.account_user_id = %d';
		if ( $active_only ) {
			$sql .= " AND e.status IN ('active','past_due')";
		}
		$sql .= ' ORDER BY e.created_at DESC';
		return $wpdb->get_results( $wpdb->prepare( $sql, $user_id ) ) ?: []; // phpcs:ignore
	}

	public static function by_subscription( string $sub_id ): ?object {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . DB::table( 'entitlements' ) . ' WHERE stripe_subscription_id = %s LIMIT 1', $sub_id ) ); // phpcs:ignore
		return $row ?: null;
	}

	public static function by_checkout_session( string $cs_id ): ?object {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . DB::table( 'entitlements' ) . ' WHERE stripe_checkout_session_id = %s LIMIT 1', $cs_id ) ); // phpcs:ignore
		return $row ?: null;
	}

	public static function create( array $data ): int {
		$data['created_at'] = DB::now();
		$data['updated_at'] = DB::now();
		return DB::insert( 'entitlements', $data );
	}

	public static function update( int $id, array $data ): void {
		$data['updated_at'] = DB::now();
		DB::update( 'entitlements', $data, [ 'id' => $id ] );
	}

	/**
	 * Grant an entitlement from a plan (called by webhooks or admin "comp").
	 */
	public static function grant_from_plan( int $user_id, object $plan, array $stripe = [] ): int {
		$is_sub = in_array( $plan->kind, [ 'subscription', 'season' ], true );
		$data   = [
			'account_user_id'            => $user_id,
			'plan_id'                    => (int) $plan->id,
			'kind'                       => $is_sub ? 'subscription' : 'credits',
			'status'                     => 'active',
			'stripe_subscription_id'     => $stripe['subscription_id'] ?? null,
			'stripe_customer_id'         => $stripe['customer_id'] ?? null,
			'stripe_checkout_session_id' => $stripe['checkout_session_id'] ?? null,
			'program_scope'              => $plan->program_scope ?: 'all',
			'weekly_limit'               => $plan->weekly_limit,
		];
		if ( $is_sub ) {
			$data['current_period_end'] = $stripe['current_period_end'] ?? null;
			if ( $plan->kind === 'season' && $plan->season_id ) {
				$season = Seasons::find( (int) $plan->season_id );
				if ( $season ) {
					$data['expires_at'] = $season->ends_on . ' 23:59:59';
				}
			}
		} else {
			$credits = (int) $plan->credits;
			$data['credits_total']     = $credits;
			$data['credits_remaining'] = $credits;
			$validity = $plan->credit_validity_days !== null ? (int) $plan->credit_validity_days : (int) \Loft\Settings::get( 'credit_validity_days', 90 );
			$data['expires_at'] = $validity > 0 ? gmdate( 'Y-m-d H:i:s', time() + $validity * DAY_IN_SECONDS ) : null;
		}
		return self::create( $data );
	}

	/**
	 * Count bookings on a subscription entitlement within the week (Mon–Sun, site tz) containing $starts_at_utc.
	 */
	public static function weekly_usage( int $entitlement_id, string $starts_at_utc ): int {
		global $wpdb;
		$local = DB::utc_to_local( $starts_at_utc );
		$monday = ( clone $local )->modify( 'monday this week' )->setTime( 0, 0 );
		$sunday = ( clone $monday )->modify( '+7 days' );
		$from   = ( clone $monday )->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
		$to     = ( clone $sunday )->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
		return (int) $wpdb->get_var( $wpdb->prepare(
			'SELECT COUNT(*) FROM ' . DB::table( 'bookings' ) . ' b JOIN ' . DB::table( 'sessions' ) . " s ON s.id = b.session_id
			 WHERE b.entitlement_id = %d AND b.status IN ('booked','attended','no_show','late_cancelled') AND s.starts_at >= %s AND s.starts_at < %s",
			$entitlement_id, $from, $to
		) ); // phpcs:ignore
	}

	/**
	 * Pick the best entitlement for a user to book a session, or null.
	 * Preference: active subscription in scope (within weekly limit) → credits in scope with soonest expiry.
	 */
	public static function usable_for( int $user_id, object $session ): ?object {
		$now  = DB::now();
		$subs = [];
		$creds = [];
		foreach ( self::for_user( $user_id, true ) as $e ) {
			if ( $e->status !== 'active' ) {
				continue; // past_due cannot book
			}
			if ( $e->expires_at && $e->expires_at < $now ) {
				continue;
			}
			$scope = ( ! $e->program_scope || $e->program_scope === 'all' ) ? 'all' : DB::json_decode( $e->program_scope, [] );
			if ( ! Plans::scope_covers( $scope, (int) $session->program_id ) ) {
				continue;
			}
			if ( $e->kind === 'subscription' ) {
				if ( $e->weekly_limit !== null && self::weekly_usage( (int) $e->id, $session->starts_at ) >= (int) $e->weekly_limit ) {
					continue;
				}
				$subs[] = $e;
			} elseif ( (int) $e->credits_remaining > 0 ) {
				$creds[] = $e;
			}
		}
		if ( $subs ) {
			return $subs[0];
		}
		usort( $creds, fn( $a, $b ) => strcmp( (string) $a->expires_at, (string) $b->expires_at ) );
		return $creds[0] ?? null;
	}

	public static function consume_credit( int $id ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . DB::table( 'entitlements' ) . ' SET credits_remaining = credits_remaining - 1, updated_at = %s WHERE id = %d AND credits_remaining > 0', DB::now(), $id ) ); // phpcs:ignore
	}

	public static function refund_credit( int $id ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . DB::table( 'entitlements' ) . ' SET credits_remaining = LEAST(credits_total, credits_remaining + 1), updated_at = %s WHERE id = %d', DB::now(), $id ) ); // phpcs:ignore
	}

	public static function to_api( object $e ): array {
		return [
			'id'                 => (int) $e->id,
			'plan_id'            => (int) $e->plan_id,
			'plan_name'          => $e->plan_name ?? null,
			'kind'               => $e->kind,
			'status'             => $e->status,
			'credits_total'      => $e->credits_total !== null ? (int) $e->credits_total : null,
			'credits_remaining'  => $e->credits_remaining !== null ? (int) $e->credits_remaining : null,
			'expires_at'         => $e->expires_at ? DB::utc_to_local( $e->expires_at )->format( DATE_ATOM ) : null,
			'current_period_end' => $e->current_period_end ? DB::utc_to_local( $e->current_period_end )->format( DATE_ATOM ) : null,
			'weekly_limit'       => $e->weekly_limit !== null ? (int) $e->weekly_limit : null,
			'has_subscription'   => ! empty( $e->stripe_subscription_id ),
		];
	}
}
