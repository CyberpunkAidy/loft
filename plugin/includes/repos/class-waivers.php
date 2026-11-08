<?php
namespace Loft\Repos;

use Loft\DB;
use Loft\Settings;

defined( 'ABSPATH' ) || exit;

class Waivers {

	public static function current(): ?\WP_Post {
		$id = (int) get_option( 'loft_current_waiver' );
		if ( ! $id ) {
			return null;
		}
		$p = get_post( $id );
		return ( $p && $p->post_status === 'publish' ) ? $p : null;
	}

	public static function current_version(): int {
		$w = self::current();
		return $w ? max( 1, (int) get_post_meta( $w->ID, '_loft_version', true ) ) : 0;
	}

	/**
	 * @return array{required:bool, signed:bool, signed_at:?string, version:int, expires_at:?string}
	 */
	public static function status_for_attendee( int $attendee_id ): array {
		global $wpdb;
		$w = self::current();
		if ( ! $w ) {
			return [ 'required' => false, 'signed' => true, 'signed_at' => null, 'version' => 0, 'expires_at' => null ];
		}
		$version = self::current_version();
		$row     = $wpdb->get_row( $wpdb->prepare(
			'SELECT signed_at FROM ' . DB::table( 'waiver_signatures' ) . ' WHERE attendee_id = %d AND waiver_post_id = %d AND waiver_version = %d ORDER BY signed_at DESC LIMIT 1',
			$attendee_id, $w->ID, $version
		) ); // phpcs:ignore
		if ( ! $row ) {
			return [ 'required' => true, 'signed' => false, 'signed_at' => null, 'version' => $version, 'expires_at' => null ];
		}
		$validity = (int) Settings::get( 'waiver_validity_days', 365 );
		$expires  = $validity > 0 ? gmdate( 'Y-m-d H:i:s', strtotime( $row->signed_at . ' +' . $validity . ' days' ) ) : null;
		$valid    = ! $expires || $expires > DB::now();
		return [ 'required' => true, 'signed' => $valid, 'signed_at' => $row->signed_at, 'version' => $version, 'expires_at' => $expires ];
	}

	public static function is_signed( int $attendee_id ): bool {
		return self::status_for_attendee( $attendee_id )['signed'];
	}

	public static function sign( int $attendee_id, int $user_id, string $signed_name, ?string $signature_file = null ): int {
		$w = self::current();
		if ( ! $w ) {
			return 0;
		}
		return DB::insert( 'waiver_signatures', [
			'waiver_post_id'    => $w->ID,
			'waiver_version'    => self::current_version(),
			'attendee_id'       => $attendee_id,
			'signed_by_user_id' => $user_id,
			'signed_name'       => sanitize_text_field( $signed_name ),
			'signature_file'    => $signature_file,
			'ip'                => isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : null,
			'user_agent'        => isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 255 ) : null,
			'signed_at'         => DB::now(),
		] );
	}

	/**
	 * Store a base64 PNG drawn signature in uploads/loft-signatures (protected by .htaccess on Apache).
	 */
	public static function store_signature_image( string $data_url, int $attendee_id ): ?string {
		if ( ! preg_match( '#^data:image/png;base64,#', $data_url ) ) {
			return null;
		}
		$bin = base64_decode( substr( $data_url, strpos( $data_url, ',' ) + 1 ), true );
		if ( ! $bin || strlen( $bin ) > 512 * 1024 ) {
			return null;
		}
		$upload = wp_upload_dir();
		$dir    = trailingslashit( $upload['basedir'] ) . 'loft-signatures';
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
			file_put_contents( $dir . '/.htaccess', "Deny from all\n" ); // phpcs:ignore
			file_put_contents( $dir . '/index.html', '' ); // phpcs:ignore
		}
		$name = 'sig-' . $attendee_id . '-' . time() . '-' . wp_generate_password( 8, false ) . '.png';
		file_put_contents( $dir . '/' . $name, $bin ); // phpcs:ignore
		return 'loft-signatures/' . $name;
	}
}
