<?php
namespace Loft\Stripe;

use Loft\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Minimal Stripe REST client over wp_remote_request. No SDK dependency.
 */
class Client {

	const API = 'https://api.stripe.com/v1/';
	const API_VERSION = '2024-06-20';

	public static function configured(): bool {
		return Settings::stripe_secret() !== '';
	}

	/**
	 * @return array|\WP_Error decoded JSON
	 */
	public static function request( string $method, string $path, array $params = [], ?string $idempotency_key = null ) {
		$secret = Settings::stripe_secret();
		if ( ! $secret ) {
			return new \WP_Error( 'stripe_not_configured', __( 'Stripe is not configured.', 'loft-studio' ) );
		}
		$headers = [
			'Authorization'  => 'Bearer ' . $secret,
			'Stripe-Version' => self::API_VERSION,
			'Content-Type'   => 'application/x-www-form-urlencoded',
		];
		if ( $idempotency_key ) {
			$headers['Idempotency-Key'] = $idempotency_key;
		}
		$args = [
			'method'  => $method,
			'timeout' => 30,
			'headers' => $headers,
		];
		$url = self::API . ltrim( $path, '/' );
		if ( $method === 'GET' ) {
			if ( $params ) {
				$url .= '?' . http_build_query( $params );
			}
		} else {
			$args['body'] = http_build_query( $params );
		}
		$res = wp_remote_request( $url, $args );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$code = wp_remote_retrieve_response_code( $res );
		$body = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( $code >= 400 ) {
			$msg = $body['error']['message'] ?? ( 'Stripe error ' . $code );
			return new \WP_Error( 'stripe_error', $msg, [ 'status' => $code, 'stripe' => $body['error'] ?? null ] );
		}
		return is_array( $body ) ? $body : [];
	}

	public static function get( string $path, array $params = [] ) {
		return self::request( 'GET', $path, $params );
	}

	public static function post( string $path, array $params = [], ?string $idem = null ) {
		return self::request( 'POST', $path, $params, $idem );
	}

	/* ------------------------------------------------------------- Customers */

	public static function ensure_customer( int $user_id ) {
		$existing = get_user_meta( $user_id, 'loft_stripe_customer_id_' . Settings::get( 'stripe_mode' ), true );
		if ( $existing ) {
			return $existing;
		}
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return new \WP_Error( 'no_user', 'User not found' );
		}
		$res = self::post( 'customers', [
			'email'    => $user->user_email,
			'name'     => trim( $user->first_name . ' ' . $user->last_name ) ?: $user->display_name,
			'metadata' => [ 'wp_user_id' => $user_id, 'site' => home_url() ],
		], 'loft-cust-' . $user_id . '-' . wp_hash( home_url() ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		update_user_meta( $user_id, 'loft_stripe_customer_id_' . Settings::get( 'stripe_mode' ), $res['id'] );
		return $res['id'];
	}

	/* ------------------------------------------------------------- Products / prices for plans */

	/**
	 * Create (or reuse) the Stripe Product + Price for a plan. Returns updated plan fields or WP_Error.
	 */
	public static function sync_plan( object $plan ) {
		$out = [];
		if ( empty( $plan->stripe_product_id ) ) {
			$prod = self::post( 'products', [
				'name'     => $plan->name,
				'metadata' => [ 'loft_plan_id' => $plan->id, 'site' => home_url() ],
			] );
			if ( is_wp_error( $prod ) ) {
				return $prod;
			}
			$plan->stripe_product_id = $prod['id'];
			$out['stripe_product_id'] = $prod['id'];
		}
		if ( empty( $plan->stripe_price_id ) ) {
			$params = [
				'product'     => $plan->stripe_product_id,
				'unit_amount' => (int) $plan->price_cents,
				'currency'    => $plan->currency ?: 'usd',
				'metadata'    => [ 'loft_plan_id' => $plan->id ],
			];
			if ( in_array( $plan->kind, [ 'subscription', 'season' ], true ) ) {
				$params['recurring'] = [ 'interval' => 'month' ];
			}
			$price = self::post( 'prices', $params );
			if ( is_wp_error( $price ) ) {
				return $price;
			}
			$out['stripe_price_id'] = $price['id'];
		}
		if ( $plan->kind === 'season' && (int) $plan->registration_fee_cents > 0 && empty( $plan->stripe_fee_price_id ) ) {
			$fee = self::post( 'prices', [
				'product'     => $plan->stripe_product_id,
				'unit_amount' => (int) $plan->registration_fee_cents,
				'currency'    => $plan->currency ?: 'usd',
				'nickname'    => 'Registration fee',
				'metadata'    => [ 'loft_plan_id' => $plan->id, 'fee' => 1 ],
			] );
			if ( is_wp_error( $fee ) ) {
				return $fee;
			}
			$out['stripe_fee_price_id'] = $fee['id'];
		}
		return $out;
	}

	/* ------------------------------------------------------------- Checkout */

	public static function create_checkout( int $user_id, object $plan, array $opts = [] ) {
		$customer = self::ensure_customer( $user_id );
		if ( is_wp_error( $customer ) ) {
			return $customer;
		}
		if ( empty( $plan->stripe_price_id ) ) {
			return new \WP_Error( 'plan_not_synced', __( 'This plan is not connected to Stripe yet.', 'loft-studio' ) );
		}
		$is_sub   = in_array( $plan->kind, [ 'subscription', 'season' ], true );
		$account  = Settings::page_url( 'account' );
		$success  = add_query_arg( [ 'loft_checkout' => 'success', 'plan' => $plan->id ], $account );
		$cancel   = add_query_arg( [ 'loft_checkout' => 'cancel' ], $account );
		if ( ! empty( $opts['return_to'] ) ) {
			$success = add_query_arg( 'loft_checkout', 'success', $opts['return_to'] );
			$cancel  = add_query_arg( 'loft_checkout', 'cancel', $opts['return_to'] );
		}
		$params = [
			'customer'    => $customer,
			'mode'        => $is_sub ? 'subscription' : 'payment',
			'success_url' => $success,
			'cancel_url'  => $cancel,
			'line_items'  => [ [ 'price' => $plan->stripe_price_id, 'quantity' => 1 ] ],
			'metadata'    => [
				'loft_plan_id' => $plan->id,
				'wp_user_id'   => $user_id,
				'session_id'   => (int) ( $opts['session_id'] ?? 0 ),
				'attendee_id'  => (int) ( $opts['attendee_id'] ?? 0 ),
			],
			'allow_promotion_codes' => 'true',
		];
		if ( $is_sub ) {
			$params['subscription_data'] = [ 'metadata' => [ 'loft_plan_id' => $plan->id, 'wp_user_id' => $user_id ] ];
			if ( $plan->kind === 'season' && $plan->season_id ) {
				$season = \Loft\Repos\Seasons::find( (int) $plan->season_id );
				if ( $season ) {
					$end = new \DateTime( $season->ends_on . ' 23:59:59', wp_timezone() );
					$params['subscription_data']['cancel_at'] = $end->getTimestamp();
				}
				if ( ! empty( $plan->stripe_fee_price_id ) ) {
					$params['line_items'][] = [ 'price' => $plan->stripe_fee_price_id, 'quantity' => 1 ];
				}
			}
		} else {
			$params['payment_intent_data'] = [ 'metadata' => [ 'loft_plan_id' => $plan->id, 'wp_user_id' => $user_id ] ];
			$params['invoice_creation'] = [ 'enabled' => 'true' ];
		}
		return self::post( 'checkout/sessions', $params );
	}

	public static function create_portal( int $user_id ) {
		$customer = self::ensure_customer( $user_id );
		if ( is_wp_error( $customer ) ) {
			return $customer;
		}
		return self::post( 'billing_portal/sessions', [
			'customer'   => $customer,
			'return_url' => Settings::page_url( 'account' ),
		] );
	}

	/* ------------------------------------------------------------- Webhook signature */

	/**
	 * Verify Stripe-Signature header. Returns true/false.
	 */
	public static function verify_signature( string $payload, string $sig_header, string $secret, int $tolerance = 300 ): bool {
		$timestamp = null;
		$sigs      = [];
		foreach ( explode( ',', $sig_header ) as $part ) {
			$kv = explode( '=', trim( $part ), 2 );
			if ( count( $kv ) !== 2 ) {
				continue;
			}
			if ( $kv[0] === 't' ) {
				$timestamp = (int) $kv[1];
			} elseif ( $kv[0] === 'v1' ) {
				$sigs[] = $kv[1];
			}
		}
		if ( ! $timestamp || ! $sigs ) {
			return false;
		}
		if ( abs( time() - $timestamp ) > $tolerance ) {
			return false;
		}
		$expected = hash_hmac( 'sha256', $timestamp . '.' . $payload, $secret );
		foreach ( $sigs as $s ) {
			if ( hash_equals( $expected, $s ) ) {
				return true;
			}
		}
		return false;
	}
}
