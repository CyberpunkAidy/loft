<?php
namespace Loft\Rest;

use Loft\DB;
use Loft\Settings;
use Loft\Repos\Attendees;
use Loft\Repos\Bookings;
use Loft\Repos\Entitlements;
use Loft\Repos\Plans;
use Loft\Repos\Sessions;
use Loft\Repos\Waivers;
use Loft\Services\Booking;
use Loft\Stripe\Client;

defined( 'ABSPATH' ) || exit;

class Router {

	const NS = 'loft/v1';

	public static function init(): void {
		add_action( 'rest_api_init', [ __CLASS__, 'routes' ] );
	}

	// Public: WordPress calls these as REST permission callbacks from outside the class.
	public static function member(): bool {
		return is_user_logged_in();
	}

	public static function staff(): bool {
		return current_user_can( 'loft_manage_attendance' ) || current_user_can( 'loft_manage_studio' );
	}

	public static function routes(): void {
		$r = function ( string $route, $methods, callable $cb, ?callable $perm = null, array $args = [] ) {
			register_rest_route( self::NS, $route, [
				'methods'             => $methods,
				'callback'            => $cb,
				'permission_callback' => $perm ?: '__return_true',
				'args'                => $args,
			] );
		};

		// Public
		$r( '/schedule', 'GET', [ __CLASS__, 'schedule' ] );
		$r( '/plans', 'GET', [ __CLASS__, 'plans' ] );
		$r( '/config', 'GET', [ __CLASS__, 'config' ] );

		// Member
		$r( '/me', 'GET', [ __CLASS__, 'me' ], [ __CLASS__, 'member' ] );
		$r( '/attendees', 'POST', [ __CLASS__, 'create_attendee' ], [ __CLASS__, 'member' ] );
		$r( '/attendees/(?P<id>\d+)', 'PATCH', [ __CLASS__, 'update_attendee' ], [ __CLASS__, 'member' ] );
		$r( '/waivers/(?P<attendee_id>\d+)/sign', 'POST', [ __CLASS__, 'sign_waiver' ], [ __CLASS__, 'member' ] );
		$r( '/bookings', 'POST', [ __CLASS__, 'create_booking' ], [ __CLASS__, 'member' ] );
		$r( '/bookings/(?P<id>\d+)', 'DELETE', [ __CLASS__, 'cancel_booking' ], [ __CLASS__, 'member' ] );
		$r( '/checkout', 'POST', [ __CLASS__, 'checkout' ], [ __CLASS__, 'member' ] );
		$r( '/portal', 'POST', [ __CLASS__, 'portal' ], [ __CLASS__, 'member' ] );

		// Staff
		$r( '/admin/sessions', 'GET', [ __CLASS__, 'admin_sessions' ], [ __CLASS__, 'staff' ] );
		$r( '/admin/roster', 'GET', [ __CLASS__, 'admin_roster' ], [ __CLASS__, 'staff' ] );
		$r( '/admin/attendance', 'POST', [ __CLASS__, 'admin_attendance' ], [ __CLASS__, 'staff' ] );
	}

	/* ------------------------------------------------------------ Public */

	public static function config(): array {
		return [
			'studio_name'       => Settings::get( 'studio_name' ),
			'timezone'          => wp_timezone_string(),
			'currency'          => Settings::get( 'currency' ),
			'late_cancel_hours' => (int) Settings::get( 'late_cancel_hours' ),
			'account_url'       => Settings::page_url( 'account' ),
			'schedule_url'      => Settings::page_url( 'schedule' ),
			'waiver_url'        => Settings::page_url( 'waiver' ),
			'plans_url'         => Settings::page_url( 'plans' ),
			'login_url'         => wp_login_url( Settings::page_url( 'schedule' ) ),
			'register_url'      => wp_registration_url(),
			'logged_in'         => is_user_logged_in(),
			'waiver_title'      => ( $w = Waivers::current() ) ? $w->post_title : null,
		];
	}

	public static function schedule( \WP_REST_Request $req ): array {
		$tz   = wp_timezone();
		$from = $req->get_param( 'from' ) ? new \DateTime( $req->get_param( 'from' ), $tz ) : new \DateTime( 'today', $tz );
		$to   = $req->get_param( 'to' ) ? new \DateTime( $req->get_param( 'to' ), $tz ) : ( clone $from )->modify( '+14 days' );
		$filters = [
			'group'      => $req->get_param( 'group' ) ? explode( ',', (string) $req->get_param( 'group' ) ) : [],
			'level'      => $req->get_param( 'level' ) ? explode( ',', (string) $req->get_param( 'level' ) ) : [],
			'tag'        => $req->get_param( 'tag' ) ? explode( ',', (string) $req->get_param( 'tag' ) ) : [],
			'program_id' => (int) $req->get_param( 'program_id' ),
		];
		$rows = Sessions::between(
			( clone $from )->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ),
			( clone $to )->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ),
			$filters
		);
		$sessions = array_map( [ Sessions::class, 'to_api' ], $rows );

		// Annotate with the current user's bookings so the UI can show "Booked".
		if ( is_user_logged_in() ) {
			$mine = [];
			foreach ( Bookings::for_user( get_current_user_id() ) as $b ) {
				$mine[ (int) $b->session_id ][] = [ 'booking_id' => (int) $b->id, 'attendee_id' => (int) $b->attendee_id, 'status' => $b->status, 'name' => trim( $b->first_name . ' ' . $b->last_name ) ];
			}
			foreach ( $sessions as &$s ) {
				$s['mine'] = $mine[ $s['id'] ] ?? [];
			}
		}
		return [
			'from'     => $from->format( 'Y-m-d' ),
			'to'       => $to->format( 'Y-m-d' ),
			'sessions' => $sessions,
			'filters'  => [
				'groups' => self::terms( 'loft_age_group' ),
				'levels' => self::terms( 'loft_level' ),
			],
		];
	}

	private static function terms( string $tax ): array {
		$t = get_terms( [ 'taxonomy' => $tax, 'hide_empty' => false ] );
		return is_array( $t ) ? array_values( array_map( fn( $x ) => [ 'slug' => $x->slug, 'name' => $x->name ], $t ) ) : [];
	}

	public static function plans(): array {
		return [ 'plans' => array_map( [ Plans::class, 'to_api' ], Plans::all( true ) ) ];
	}

	/* ------------------------------------------------------------ Member */

	public static function me(): array {
		$uid  = get_current_user_id();
		$user = wp_get_current_user();
		return [
			'user'         => [ 'id' => $uid, 'name' => $user->display_name, 'email' => $user->user_email, 'first_name' => $user->first_name ],
			'attendees'    => array_map( [ Attendees::class, 'to_api' ], Attendees::for_user( $uid ) ),
			'entitlements' => array_map( [ Entitlements::class, 'to_api' ], Entitlements::for_user( $uid, true ) ),
			'bookings'     => array_map( [ Bookings::class, 'to_api' ], Bookings::for_user( $uid ) ),
			'waiver'       => ( $w = Waivers::current() ) ? [ 'title' => $w->post_title, 'version' => Waivers::current_version(), 'html' => apply_filters( 'the_content', $w->post_content ) ] : null,
			'has_stripe'   => Client::configured(),
		];
	}

	public static function create_attendee( \WP_REST_Request $req ) {
		$p = $req->get_json_params() ?: $req->get_params();
		if ( empty( $p['first_name'] ) || empty( $p['last_name'] ) ) {
			return new \WP_Error( 'invalid', __( 'First and last name are required.', 'loft-studio' ), [ 'status' => 400 ] );
		}
		$id = Attendees::create( get_current_user_id(), $p );
		return Attendees::to_api( Attendees::find( $id ) );
	}

	public static function update_attendee( \WP_REST_Request $req ) {
		$id = (int) $req['id'];
		if ( ! Attendees::belongs_to( $id, get_current_user_id() ) ) {
			return new \WP_Error( 'forbidden', 'Not yours', [ 'status' => 403 ] );
		}
		Attendees::update( $id, $req->get_json_params() ?: $req->get_params() );
		return Attendees::to_api( Attendees::find( $id ) );
	}

	public static function sign_waiver( \WP_REST_Request $req ) {
		$attendee_id = (int) $req['attendee_id'];
		$uid         = get_current_user_id();
		if ( ! Attendees::belongs_to( $attendee_id, $uid ) ) {
			return new \WP_Error( 'forbidden', 'Not yours', [ 'status' => 403 ] );
		}
		$p    = $req->get_json_params() ?: $req->get_params();
		$name = trim( (string) ( $p['signed_name'] ?? '' ) );
		if ( strlen( $name ) < 3 || empty( $p['agree'] ) ) {
			return new \WP_Error( 'invalid', __( 'Type your full name and tick the agreement box.', 'loft-studio' ), [ 'status' => 400 ] );
		}
		$file = ! empty( $p['signature_png'] ) ? Waivers::store_signature_image( (string) $p['signature_png'], $attendee_id ) : null;
		$sid  = Waivers::sign( $attendee_id, $uid, $name, $file );
		if ( ! $sid ) {
			return new \WP_Error( 'no_waiver', __( 'No active waiver to sign.', 'loft-studio' ), [ 'status' => 409 ] );
		}
		return [ 'ok' => true, 'waiver' => Waivers::status_for_attendee( $attendee_id ) ];
	}

	public static function create_booking( \WP_REST_Request $req ) {
		$p = $req->get_json_params() ?: $req->get_params();
		$res = Booking::book( get_current_user_id(), (int) ( $p['session_id'] ?? 0 ), (int) ( $p['attendee_id'] ?? 0 ), 'web' );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return [ 'booking' => $res ];
	}

	public static function cancel_booking( \WP_REST_Request $req ) {
		return Booking::cancel( (int) $req['id'], get_current_user_id(), false );
	}

	public static function checkout( \WP_REST_Request $req ) {
		$p    = $req->get_json_params() ?: $req->get_params();
		$plan = Plans::find( (int) ( $p['plan_id'] ?? 0 ) );
		if ( ! $plan || ! $plan->active ) {
			return new \WP_Error( 'not_found', __( 'Plan not found.', 'loft-studio' ), [ 'status' => 404 ] );
		}
		$opts = [
			'session_id'  => (int) ( $p['session_id'] ?? 0 ),
			'attendee_id' => (int) ( $p['attendee_id'] ?? 0 ),
			'return_to'   => ! empty( $p['return_to'] ) ? esc_url_raw( (string) $p['return_to'] ) : null,
		];
		if ( $opts['attendee_id'] && ! Attendees::belongs_to( $opts['attendee_id'], get_current_user_id() ) ) {
			$opts['attendee_id'] = 0;
		}
		$cs = Client::create_checkout( get_current_user_id(), $plan, $opts );
		if ( is_wp_error( $cs ) ) {
			return $cs;
		}
		return [ 'url' => $cs['url'] ];
	}

	public static function portal() {
		$s = Client::create_portal( get_current_user_id() );
		return is_wp_error( $s ) ? $s : [ 'url' => $s['url'] ];
	}

	/* ------------------------------------------------------------ Staff */

	public static function admin_sessions( \WP_REST_Request $req ): array {
		$tz   = wp_timezone();
		$day  = $req->get_param( 'date' ) ? new \DateTime( $req->get_param( 'date' ), $tz ) : new \DateTime( 'today', $tz );
		$from = ( clone $day )->setTime( 0, 0 )->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
		$to   = ( clone $day )->modify( '+1 day' )->setTime( 0, 0 )->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
		return [ 'date' => $day->format( 'Y-m-d' ), 'sessions' => array_map( [ Sessions::class, 'to_api' ], Sessions::between( $from, $to, [ 'include_cancelled' => true ] ) ) ];
	}

	public static function admin_roster( \WP_REST_Request $req ) {
		$sid = (int) $req->get_param( 'session_id' );
		$s   = Sessions::find( $sid );
		if ( ! $s ) {
			return new \WP_Error( 'not_found', 'Session not found', [ 'status' => 404 ] );
		}
		$rows = [];
		foreach ( Bookings::for_session( $sid ) as $b ) {
			$a    = Attendees::find( (int) $b->attendee_id );
			$acct = get_userdata( (int) $b->account_user_id );
			$rows[] = array_merge( Bookings::to_api( $b ), [
				'account_name'  => $acct ? $acct->display_name : '',
				'account_email' => $acct ? $acct->user_email : '',
				'account_phone' => $acct ? get_user_meta( $acct->ID, 'loft_phone', true ) : '',
				'age'           => $a ? Attendees::age( $a ) : null,
				'medical_notes' => $a ? $a->medical_notes : '',
				'emergency'     => $a ? $a->emergency_contact : '',
				'waiver_ok'     => $a ? Waivers::is_signed( (int) $a->id ) : false,
			] );
		}
		return [ 'session' => Sessions::to_api( $s ), 'roster' => $rows ];
	}

	public static function admin_attendance( \WP_REST_Request $req ) {
		$p  = $req->get_json_params() ?: $req->get_params();
		$ok = Booking::mark( (int) ( $p['booking_id'] ?? 0 ), (string) ( $p['status'] ?? '' ) );
		return $ok ? [ 'ok' => true ] : new \WP_Error( 'invalid', 'Could not update', [ 'status' => 400 ] );
	}
}
