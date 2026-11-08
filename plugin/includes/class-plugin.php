<?php
namespace Loft;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin bootstrap / service locator.
 */
final class Plugin {

	private static ?Plugin $instance = null;

	public static function instance(): Plugin {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function boot(): void {
		Installer::maybe_upgrade();

		PostTypes::init();
		Shortcodes::init();
		Assets::init();
		Rest\Router::init();
		Stripe\Webhooks::init();
		Cron::init();
		Emails::init();

		if ( is_admin() ) {
			Admin\Menu::init();
		}

		add_action( 'init', function () {
			load_plugin_textdomain( 'loft-studio', false, dirname( plugin_basename( LOFT_FILE ) ) . '/languages' );
		} );
	}
}
