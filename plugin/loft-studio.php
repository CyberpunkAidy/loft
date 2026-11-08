<?php
/**
 * Plugin Name:       Loft Studio
 * Plugin URI:        https://goldseamtech.com/loft
 * Description:       Class scheduling, memberships, family accounts, waivers and Stripe billing for dance, aerial, gymnastics and fitness studios.
 * Version:           0.1.1
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Goldseam Tech LLC
 * Author URI:        https://goldseamtech.com
 * License:           GPL-2.0-or-later
 * Text Domain:       loft-studio
 */

defined( 'ABSPATH' ) || exit;

define( 'LOFT_VERSION', '0.1.1' );
define( 'LOFT_DB_VERSION', '1' );
define( 'LOFT_FILE', __FILE__ );
define( 'LOFT_DIR', plugin_dir_path( __FILE__ ) );
define( 'LOFT_URL', plugin_dir_url( __FILE__ ) );

// Minimal PSR-4 style autoloader for the Loft\ namespace.
spl_autoload_register( function ( $class ) {
	if ( strpos( $class, 'Loft\\' ) !== 0 ) {
		return;
	}
	$relative = substr( $class, 5 );
	$parts    = explode( '\\', $relative );
	$file     = array_pop( $parts );
	$dir      = strtolower( implode( '/', $parts ) );
	$path     = LOFT_DIR . 'includes/' . ( $dir ? $dir . '/' : '' ) . 'class-' . strtolower( str_replace( '_', '-', $file ) ) . '.php';
	if ( file_exists( $path ) ) {
		require_once $path;
	}
} );

register_activation_hook( __FILE__, [ 'Loft\\Installer', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'Loft\\Installer', 'deactivate' ] );

add_action( 'plugins_loaded', function () {
	Loft\Plugin::instance()->boot();
} );

/**
 * Convenience accessor.
 */
function loft(): Loft\Plugin {
	return Loft\Plugin::instance();
}
