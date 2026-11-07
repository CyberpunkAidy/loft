<?php
namespace Loft;

defined( 'ABSPATH' ) || exit;

/**
 * Creates tables, roles and default options on activation.
 */
class Installer {

	public static function activate(): void {
		self::create_tables();
		self::create_roles();
		self::default_options();
		if ( ! wp_next_scheduled( 'loft_hourly' ) ) {
			wp_schedule_event( time() + 300, 'hourly', 'loft_hourly' );
		}
		update_option( 'loft_db_version', LOFT_DB_VERSION );
		// Flush rewrite rules for the CPTs.
		PostTypes::register();
		flush_rewrite_rules();
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook( 'loft_hourly' );
		flush_rewrite_rules();
	}

	/**
	 * Run on plugins_loaded when the stored DB version is behind.
	 */
	public static function maybe_upgrade(): void {
		if ( get_option( 'loft_db_version' ) !== LOFT_DB_VERSION ) {
			self::create_tables();
			update_option( 'loft_db_version', LOFT_DB_VERSION );
		}
	}

	public static function create_tables(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$p       = $wpdb->prefix . 'loft_';
		$charset = $wpdb->get_charset_collate();

		$sql = [];

		$sql[] = "CREATE TABLE {$p}seasons (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(190) NOT NULL,
			starts_on DATE NOT NULL,
			ends_on DATE NOT NULL,
			is_current TINYINT(1) NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY is_current (is_current)
		) $charset;";

		$sql[] = "CREATE TABLE {$p}sessions (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			program_id BIGINT UNSIGNED NOT NULL,
			season_id BIGINT UNSIGNED NULL,
			starts_at DATETIME NOT NULL,
			ends_at DATETIME NOT NULL,
			capacity SMALLINT UNSIGNED NOT NULL DEFAULT 10,
			status VARCHAR(20) NOT NULL DEFAULT 'scheduled',
			instructor_id BIGINT UNSIGNED NULL,
			price_cents INT UNSIGNED NULL,
			notes TEXT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY program_id (program_id),
			KEY starts_at (starts_at),
			KEY status (status)
		) $charset;";

		$sql[] = "CREATE TABLE {$p}attendees (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			account_user_id BIGINT UNSIGNED NOT NULL,
			first_name VARCHAR(100) NOT NULL,
			last_name VARCHAR(100) NOT NULL,
			dob DATE NULL,
			is_self TINYINT(1) NOT NULL DEFAULT 0,
			medical_notes TEXT NULL,
			emergency_contact VARCHAR(190) NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'active',
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY account_user_id (account_user_id)
		) $charset;";

		$sql[] = "CREATE TABLE {$p}plans (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(190) NOT NULL,
			description TEXT NULL,
			kind VARCHAR(20) NOT NULL,
			price_cents INT UNSIGNED NOT NULL DEFAULT 0,
			currency VARCHAR(3) NOT NULL DEFAULT 'usd',
			billing_interval VARCHAR(10) NULL,
			credits SMALLINT UNSIGNED NULL,
			credit_validity_days SMALLINT UNSIGNED NULL,
			weekly_limit SMALLINT UNSIGNED NULL,
			season_id BIGINT UNSIGNED NULL,
			registration_fee_cents INT UNSIGNED NOT NULL DEFAULT 0,
			stripe_product_id VARCHAR(64) NULL,
			stripe_price_id VARCHAR(64) NULL,
			stripe_fee_price_id VARCHAR(64) NULL,
			program_scope TEXT NULL,
			active TINYINT(1) NOT NULL DEFAULT 1,
			sort SMALLINT NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY kind (kind),
			KEY active (active)
		) $charset;";

		$sql[] = "CREATE TABLE {$p}entitlements (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			account_user_id BIGINT UNSIGNED NOT NULL,
			plan_id BIGINT UNSIGNED NOT NULL,
			kind VARCHAR(20) NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'active',
			stripe_subscription_id VARCHAR(64) NULL,
			stripe_customer_id VARCHAR(64) NULL,
			stripe_checkout_session_id VARCHAR(90) NULL,
			credits_total SMALLINT UNSIGNED NULL,
			credits_remaining SMALLINT NULL,
			expires_at DATETIME NULL,
			current_period_end DATETIME NULL,
			program_scope TEXT NULL,
			weekly_limit SMALLINT UNSIGNED NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY account_user_id (account_user_id),
			KEY status (status),
			KEY stripe_subscription_id (stripe_subscription_id),
			KEY stripe_checkout_session_id (stripe_checkout_session_id)
		) $charset;";

		$sql[] = "CREATE TABLE {$p}bookings (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			session_id BIGINT UNSIGNED NOT NULL,
			attendee_id BIGINT UNSIGNED NOT NULL,
			account_user_id BIGINT UNSIGNED NOT NULL,
			entitlement_id BIGINT UNSIGNED NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'booked',
			position SMALLINT UNSIGNED NULL,
			credit_consumed TINYINT(1) NOT NULL DEFAULT 0,
			source VARCHAR(20) NOT NULL DEFAULT 'web',
			booked_at DATETIME NOT NULL,
			cancelled_at DATETIME NULL,
			attended_at DATETIME NULL,
			PRIMARY KEY  (id),
			KEY session_id (session_id),
			KEY attendee_id (attendee_id),
			KEY account_user_id (account_user_id),
			KEY status (status)
		) $charset;";

		$sql[] = "CREATE TABLE {$p}waiver_signatures (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			waiver_post_id BIGINT UNSIGNED NOT NULL,
			waiver_version SMALLINT UNSIGNED NOT NULL,
			attendee_id BIGINT UNSIGNED NOT NULL,
			signed_by_user_id BIGINT UNSIGNED NOT NULL,
			signed_name VARCHAR(190) NOT NULL,
			signature_file VARCHAR(255) NULL,
			ip VARCHAR(45) NULL,
			user_agent VARCHAR(255) NULL,
			signed_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY attendee_id (attendee_id),
			KEY waiver (waiver_post_id, waiver_version)
		) $charset;";

		$sql[] = "CREATE TABLE {$p}stripe_events (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			event_id VARCHAR(64) NOT NULL,
			type VARCHAR(80) NOT NULL,
			received_at DATETIME NOT NULL,
			processed_at DATETIME NULL,
			error TEXT NULL,
			payload LONGTEXT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY event_id (event_id)
		) $charset;";

		$sql[] = "CREATE TABLE {$p}notifications (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			kind VARCHAR(40) NOT NULL,
			ref_id BIGINT UNSIGNED NOT NULL,
			sent_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY kind_ref (kind, ref_id)
		) $charset;";

		foreach ( $sql as $statement ) {
			dbDelta( $statement );
		}
	}

	public static function create_roles(): void {
		add_role( 'loft_member', __( 'Studio Member', 'loft-studio' ), [ 'read' => true ] );
		add_role( 'loft_instructor', __( 'Studio Instructor', 'loft-studio' ), [
			'read'                   => true,
			'loft_manage_attendance' => true,
		] );
		$admin = get_role( 'administrator' );
		if ( $admin ) {
			$admin->add_cap( 'loft_manage_studio' );
			$admin->add_cap( 'loft_manage_attendance' );
		}
	}

	public static function default_options(): void {
		$defaults = Settings::defaults();
		$existing = get_option( Settings::OPTION, [] );
		update_option( Settings::OPTION, array_merge( $defaults, is_array( $existing ) ? $existing : [] ) );
	}
}
