<?php
namespace Loft;

defined( 'ABSPATH' ) || exit;

/**
 * Shortcodes render a mount point; assets/app.js hydrates it.
 */
class Shortcodes {

	public static function init(): void {
		add_shortcode( 'loft_schedule', [ __CLASS__, 'schedule' ] );
		add_shortcode( 'loft_account', [ __CLASS__, 'account' ] );
		add_shortcode( 'loft_plans', [ __CLASS__, 'plans' ] );
		add_shortcode( 'loft_waiver', [ __CLASS__, 'waiver' ] );
		add_shortcode( 'loft_checkin', [ __CLASS__, 'checkin' ] );
		add_shortcode( 'loft_register', [ __CLASS__, 'register' ] );
		add_action( 'admin_post_nopriv_loft_register', [ __CLASS__, 'handle_register' ] );
		add_action( 'admin_post_loft_register', [ __CLASS__, 'handle_register' ] );
	}

	private static function mount( string $view, array $atts ): string {
		Assets::enqueue_front();
		$data = esc_attr( wp_json_encode( $atts ) );
		return '<div class="loft-app" data-loft-view="' . esc_attr( $view ) . '" data-loft-atts="' . $data . '"><div class="loft-loading">' . esc_html__( 'Loading…', 'loft-studio' ) . '</div></div>';
	}

	public static function schedule( $atts ): string {
		$atts = shortcode_atts( [ 'group' => '', 'level' => '', 'tag' => '', 'weeks' => 2, 'program_id' => 0, 'filters' => 'yes' ], $atts, 'loft_schedule' );
		return self::mount( 'schedule', $atts );
	}

	public static function account( $atts ): string {
		if ( ! is_user_logged_in() ) {
			return self::login_prompt();
		}
		return self::mount( 'account', shortcode_atts( [ 'tab' => 'upcoming' ], $atts, 'loft_account' ) );
	}

	public static function plans( $atts ): string {
		return self::mount( 'plans', shortcode_atts( [ 'kind' => '' ], $atts, 'loft_plans' ) );
	}

	public static function waiver( $atts ): string {
		if ( ! is_user_logged_in() ) {
			return self::login_prompt();
		}
		return self::mount( 'waiver', shortcode_atts( [ 'attendee' => 0 ], $atts, 'loft_waiver' ) );
	}

	public static function checkin( $atts ): string {
		if ( ! current_user_can( 'loft_manage_attendance' ) && ! current_user_can( 'loft_manage_studio' ) ) {
			return '<p>' . esc_html__( 'Staff only.', 'loft-studio' ) . '</p>';
		}
		return self::mount( 'checkin', shortcode_atts( [], $atts, 'loft_checkin' ) );
	}

	private static function login_prompt(): string {
		Assets::enqueue_front();
		$login = wp_login_url( get_permalink() );
		$reg   = wp_registration_url();
		return '<div class="loft-app loft-card loft-login-prompt"><h3>' . esc_html__( 'Sign in to continue', 'loft-studio' ) . '</h3><p>' . esc_html__( 'Your classes, family members, waivers and billing live in one place.', 'loft-studio' ) . '</p><p><a class="loft-btn" href="' . esc_url( $login ) . '">' . esc_html__( 'Sign in', 'loft-studio' ) . '</a> <a class="loft-btn loft-btn-secondary" href="' . esc_url( $reg ) . '">' . esc_html__( 'Create an account', 'loft-studio' ) . '</a></p></div>';
	}

	/* ---------------------------------------------------------- Registration */

	public static function register( $atts ): string {
		Assets::enqueue_front();
		if ( is_user_logged_in() ) {
			return '<div class="loft-app loft-card"><p>' . esc_html__( 'You are already signed in.', 'loft-studio' ) . ' <a href="' . esc_url( Settings::page_url( 'account' ) ) . '">' . esc_html__( 'Go to your account', 'loft-studio' ) . '</a></p></div>';
		}
		$atts     = shortcode_atts( [ 'redirect' => '' ], $atts, 'loft_register' );
		$redirect = $atts['redirect'] ?: ( isset( $_GET['redirect_to'] ) ? esc_url_raw( wp_unslash( $_GET['redirect_to'] ) ) : Settings::page_url( 'account' ) ); // phpcs:ignore
		$err      = isset( $_GET['loft_reg_error'] ) ? sanitize_text_field( wp_unslash( $_GET['loft_reg_error'] ) ) : ''; // phpcs:ignore
		ob_start();
		?>
		<div class="loft-app loft-card loft-register">
			<?php if ( $err ) : ?><div class="loft-alert loft-alert-error"><?php echo esc_html( $err ); ?></div><?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="loft-form">
				<input type="hidden" name="action" value="loft_register">
				<input type="hidden" name="redirect_to" value="<?php echo esc_attr( $redirect ); ?>">
				<?php wp_nonce_field( 'loft_register', 'loft_register_nonce' ); ?>
				<div class="loft-grid-2">
					<label><?php esc_html_e( 'First name', 'loft-studio' ); ?><input type="text" name="first_name" required></label>
					<label><?php esc_html_e( 'Last name', 'loft-studio' ); ?><input type="text" name="last_name" required></label>
				</div>
				<label><?php esc_html_e( 'Email', 'loft-studio' ); ?><input type="email" name="email" required></label>
				<label><?php esc_html_e( 'Mobile phone', 'loft-studio' ); ?><input type="tel" name="phone"></label>
				<label><?php esc_html_e( 'Password', 'loft-studio' ); ?><input type="password" name="password" minlength="8" required></label>
				<label class="loft-check"><input type="checkbox" name="is_student" value="1"> <?php esc_html_e( "I'll be taking classes myself", 'loft-studio' ); ?></label>
				<div class="loft-hp" aria-hidden="true"><input type="text" name="website" tabindex="-1" autocomplete="off"></div>
				<button class="loft-btn" type="submit"><?php esc_html_e( 'Create account', 'loft-studio' ); ?></button>
				<p class="loft-muted"><?php esc_html_e( 'Already have an account?', 'loft-studio' ); ?> <a href="<?php echo esc_url( wp_login_url( $redirect ) ); ?>"><?php esc_html_e( 'Sign in', 'loft-studio' ); ?></a></p>
			</form>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	public static function handle_register(): void {
		$back = wp_get_referer() ?: home_url( '/' );
		$fail = function ( string $msg ) use ( $back ) {
			wp_safe_redirect( add_query_arg( 'loft_reg_error', rawurlencode( $msg ), $back ) );
			exit;
		};
		if ( ! isset( $_POST['loft_register_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['loft_register_nonce'] ) ), 'loft_register' ) ) {
			$fail( __( 'Session expired, please try again.', 'loft-studio' ) );
		}
		if ( ! empty( $_POST['website'] ) ) {
			$fail( __( 'Something went wrong.', 'loft-studio' ) );
		}
		$email = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
		$first = sanitize_text_field( wp_unslash( $_POST['first_name'] ?? '' ) );
		$last  = sanitize_text_field( wp_unslash( $_POST['last_name'] ?? '' ) );
		$phone = sanitize_text_field( wp_unslash( $_POST['phone'] ?? '' ) );
		$pass  = (string) wp_unslash( $_POST['password'] ?? '' ); // phpcs:ignore
		if ( ! is_email( $email ) || ! $first || ! $last || strlen( $pass ) < 8 ) {
			$fail( __( 'Please fill in all required fields (password at least 8 characters).', 'loft-studio' ) );
		}
		if ( email_exists( $email ) ) {
			$fail( __( 'An account with that email already exists. Try signing in.', 'loft-studio' ) );
		}
		$user_id = wp_insert_user( [
			'user_login'   => $email,
			'user_email'   => $email,
			'user_pass'    => $pass,
			'first_name'   => $first,
			'last_name'    => $last,
			'display_name' => $first . ' ' . $last,
			'role'         => 'loft_member',
		] );
		if ( is_wp_error( $user_id ) ) {
			$fail( $user_id->get_error_message() );
		}
		update_user_meta( $user_id, 'loft_phone', $phone );
		if ( ! empty( $_POST['is_student'] ) ) {
			Repos\Attendees::create( $user_id, [ 'first_name' => $first, 'last_name' => $last, 'is_self' => 1 ] );
		}
		do_action( 'loft_member_registered', $user_id );
		wp_set_current_user( $user_id );
		wp_set_auth_cookie( $user_id, true );
		$redirect = isset( $_POST['redirect_to'] ) ? esc_url_raw( wp_unslash( $_POST['redirect_to'] ) ) : Settings::page_url( 'account' );
		wp_safe_redirect( $redirect ?: Settings::page_url( 'account' ) );
		exit;
	}
}
