<?php
namespace Loft;

defined( 'ABSPATH' ) || exit;

class Assets {

	private static bool $enqueued = false;

	public static function init(): void {
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'register' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'admin' ] );
	}

	public static function register(): void {
		wp_register_style( 'loft-app', LOFT_URL . 'assets/app.css', [], LOFT_VERSION );
		wp_register_script( 'loft-app', LOFT_URL . 'assets/app.js', [], LOFT_VERSION, true );
		$accent = Settings::get( 'accent_color', '#c2410c' );
		wp_add_inline_style( 'loft-app', ':root{--loft-accent:' . esc_attr( $accent ) . ';}' );
	}

	public static function enqueue_front(): void {
		if ( self::$enqueued ) {
			return;
		}
		self::$enqueued = true;
		wp_enqueue_style( 'loft-app' );
		wp_enqueue_script( 'loft-app' );
		wp_localize_script( 'loft-app', 'LOFT', [
			'rest'      => esc_url_raw( rest_url( 'loft/v1/' ) ),
			'nonce'     => wp_create_nonce( 'wp_rest' ),
			'loggedIn'  => is_user_logged_in(),
			'loginUrl'  => wp_login_url( get_permalink() ?: home_url() ),
			'accountUrl'=> Settings::page_url( 'account' ),
			'waiverUrl' => Settings::page_url( 'waiver' ),
			'plansUrl'  => Settings::page_url( 'plans' ),
			'i18n'      => [
				'book'       => __( 'Book', 'loft-studio' ),
				'waitlist'   => __( 'Join waitlist', 'loft-studio' ),
				'full'       => __( 'Full', 'loft-studio' ),
				'booked'     => __( 'Booked', 'loft-studio' ),
				'spotsLeft'  => __( '%d spots left', 'loft-studio' ),
				'oneSpot'    => __( '1 spot left', 'loft-studio' ),
				'cancel'     => __( 'Cancel', 'loft-studio' ),
				'signIn'     => __( 'Sign in to book', 'loft-studio' ),
			],
		] );
	}

	public static function admin( string $hook ): void {
		if ( strpos( $hook, 'loft' ) === false && get_current_screen()?->post_type !== PostTypes::PROGRAM ) {
			return;
		}
		wp_enqueue_style( 'loft-admin', LOFT_URL . 'assets/admin.css', [], LOFT_VERSION );
	}
}
