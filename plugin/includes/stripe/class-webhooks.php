<?php
namespace Loft\Stripe;

use Loft\DB;
use Loft\Settings;
use Loft\Repos\Entitlements;
use Loft\Repos\Plans;
use Loft\Services\Booking;

defined( 'ABSPATH' ) || exit;

/**
 * Stripe webhook endpoint: POST /wp-json/loft/v1/stripe/webhook
 */
class Webhooks {

	public static function init(): void {
		add_action( 'rest_api_init', function () {
			register_rest_route( 'loft/v1', '/stripe/webhook', [
				'methods'             => 'POST',
				'callback'            => [ __CLASS__, 'handle' ],
				'permission_callback' => '__return_true',
			] );
		} );
	}

	public static function handle( \WP_REST_Request $req ) {
		$payload = $req->get_body();
		$sig     = $req->get_header( 'stripe-signature' ) ?: '';
		$secret  = Settings::stripe_webhook_secret();

		if ( ! $secret || ! Client::verify_signature( $payload, $sig, $secret ) ) {
			return new \WP_REST_Response( [ 'error' => 'invalid signature' ], 400 );
		}
		$event = json_decode( $payload, true );
		if ( ! is_array( $event ) || empty( $event['id'] ) || empty( $event['type'] ) ) {
			return new \WP_REST_Response( [ 'error' => 'bad payload' ], 400 );
		}

		// Idempotency: ignore events we've already processed.
		global $wpdb;
		$inserted = $wpdb->query( $wpdb->prepare(
			'INSERT IGNORE INTO ' . DB::table( 'stripe_events' ) . ' (event_id, type, received_at, payload) VALUES (%s, %s, %s, %s)',
			$event['id'], $event['type'], DB::now(), $payload
		) ); // phpcs:ignore
		if ( ! $inserted ) {
			return new \WP_REST_Response( [ 'received' => true, 'duplicate' => true ], 200 );
		}

		try {
			self::dispatch( $event );
			DB::update( 'stripe_events', [ 'processed_at' => DB::now() ], [ 'event_id' => $event['id'] ] );
		} catch ( \Throwable $e ) {
			DB::update( 'stripe_events', [ 'error' => $e->getMessage() ], [ 'event_id' => $event['id'] ] );
			// Return 200 so Stripe doesn't hammer us; admin can inspect the log.
			return new \WP_REST_Response( [ 'received' => true, 'error' => $e->getMessage() ], 200 );
		}
		return new \WP_REST_Response( [ 'received' => true ], 200 );
	}

	private static function dispatch( array $event ): void {
		$obj = $event['data']['object'] ?? [];
		switch ( $event['type'] ) {
			case 'checkout.session.completed':
				self::checkout_completed( $obj );
				break;
			case 'invoice.paid':
				self::invoice_paid( $obj );
				break;
			case 'invoice.payment_failed':
				self::invoice_failed( $obj );
				break;
			case 'customer.subscription.updated':
				self::subscription_updated( $obj );
				break;
			case 'customer.subscription.deleted':
				self::subscription_deleted( $obj );
				break;
		}
		do_action( 'loft_stripe_event', $event );
	}

	private static function checkout_completed( array $cs ): void {
		if ( ( $cs['payment_status'] ?? '' ) !== 'paid' && ( $cs['mode'] ?? '' ) !== 'subscription' ) {
			return; // e.g. delayed payment methods; invoice.paid will arrive later for subscriptions.
		}
		if ( Entitlements::by_checkout_session( $cs['id'] ) ) {
			return;
		}
		$plan_id = (int) ( $cs['metadata']['loft_plan_id'] ?? 0 );
		$user_id = (int) ( $cs['metadata']['wp_user_id'] ?? 0 );
		$plan    = $plan_id ? Plans::find( $plan_id ) : null;
		if ( ! $plan || ! $user_id ) {
			throw new \RuntimeException( 'checkout.session.completed missing plan/user metadata' );
		}
		$stripe = [
			'customer_id'         => $cs['customer'] ?? null,
			'checkout_session_id' => $cs['id'],
			'subscription_id'     => $cs['subscription'] ?? null,
		];
		if ( ! empty( $cs['subscription'] ) ) {
			$sub = Client::get( 'subscriptions/' . $cs['subscription'] );
			if ( ! is_wp_error( $sub ) && ! empty( $sub['current_period_end'] ) ) {
				$stripe['current_period_end'] = gmdate( 'Y-m-d H:i:s', (int) $sub['current_period_end'] );
			}
		}
		$ent_id = Entitlements::grant_from_plan( $user_id, $plan, $stripe );
		do_action( 'loft_entitlement_granted', Entitlements::find( $ent_id ), $plan, $user_id );

		// If the purchase started from a "Book" button, complete the booking now.
		$session_id  = (int) ( $cs['metadata']['session_id'] ?? 0 );
		$attendee_id = (int) ( $cs['metadata']['attendee_id'] ?? 0 );
		if ( $session_id && $attendee_id ) {
			Booking::book( $user_id, $session_id, $attendee_id, 'web', $ent_id );
		}
	}

	private static function invoice_paid( array $inv ): void {
		if ( empty( $inv['subscription'] ) ) {
			return;
		}
		$e = Entitlements::by_subscription( $inv['subscription'] );
		if ( ! $e ) {
			// Subscription created outside checkout flow (e.g. admin in Stripe dashboard) — try metadata.
			$sub = Client::get( 'subscriptions/' . $inv['subscription'] );
			if ( is_wp_error( $sub ) ) {
				return;
			}
			$plan_id = (int) ( $sub['metadata']['loft_plan_id'] ?? 0 );
			$user_id = (int) ( $sub['metadata']['wp_user_id'] ?? 0 );
			$plan    = $plan_id ? Plans::find( $plan_id ) : null;
			if ( ! $plan || ! $user_id ) {
				return;
			}
			Entitlements::grant_from_plan( $user_id, $plan, [
				'customer_id'        => $sub['customer'] ?? null,
				'subscription_id'    => $sub['id'],
				'current_period_end' => ! empty( $sub['current_period_end'] ) ? gmdate( 'Y-m-d H:i:s', (int) $sub['current_period_end'] ) : null,
			] );
			return;
		}
		$period_end = null;
		foreach ( $inv['lines']['data'] ?? [] as $line ) {
			if ( ! empty( $line['period']['end'] ) ) {
				$period_end = max( (int) $period_end, (int) $line['period']['end'] );
			}
		}
		Entitlements::update( (int) $e->id, [
			'status'             => 'active',
			'current_period_end' => $period_end ? gmdate( 'Y-m-d H:i:s', $period_end ) : $e->current_period_end,
		] );
	}

	private static function invoice_failed( array $inv ): void {
		if ( empty( $inv['subscription'] ) ) {
			return;
		}
		$e = Entitlements::by_subscription( $inv['subscription'] );
		if ( $e ) {
			Entitlements::update( (int) $e->id, [ 'status' => 'past_due' ] );
			do_action( 'loft_payment_failed', Entitlements::find( (int) $e->id ), $inv );
		}
	}

	private static function subscription_updated( array $sub ): void {
		$e = Entitlements::by_subscription( $sub['id'] );
		if ( ! $e ) {
			return;
		}
		$map = [
			'active'             => 'active',
			'trialing'           => 'active',
			'past_due'           => 'past_due',
			'unpaid'             => 'past_due',
			'canceled'           => 'cancelled',
			'incomplete_expired' => 'cancelled',
			'paused'             => 'cancelled',
		];
		$update = [ 'status' => $map[ $sub['status'] ?? 'active' ] ?? $e->status ];
		if ( ! empty( $sub['current_period_end'] ) ) {
			$update['current_period_end'] = gmdate( 'Y-m-d H:i:s', (int) $sub['current_period_end'] );
		}
		if ( ! empty( $sub['cancel_at_period_end'] ) && ! empty( $sub['current_period_end'] ) ) {
			$update['expires_at'] = gmdate( 'Y-m-d H:i:s', (int) $sub['current_period_end'] );
		}
		Entitlements::update( (int) $e->id, $update );
	}

	private static function subscription_deleted( array $sub ): void {
		$e = Entitlements::by_subscription( $sub['id'] );
		if ( $e ) {
			Entitlements::update( (int) $e->id, [ 'status' => 'cancelled', 'expires_at' => DB::now() ] );
			do_action( 'loft_subscription_cancelled', Entitlements::find( (int) $e->id ) );
		}
	}
}
