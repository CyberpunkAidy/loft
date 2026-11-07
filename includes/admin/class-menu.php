<?php
namespace Loft\Admin;

use Loft\Repos\Bookings;
use Loft\Repos\Entitlements;
use Loft\Repos\Plans;
use Loft\Repos\Seasons;
use Loft\Repos\Sessions;
use Loft\Services\Booking;
use Loft\Settings;
use Loft\Stripe\Client;

defined( 'ABSPATH' ) || exit;

class Menu {

	public static function init(): void {
		add_action( 'admin_menu', [ __CLASS__, 'menu' ] );
		add_action( 'admin_post_loft_generate_sessions', [ __CLASS__, 'generate_sessions' ] );
		add_action( 'admin_post_loft_save_season', [ __CLASS__, 'save_season' ] );
		add_action( 'admin_post_loft_delete_season', [ __CLASS__, 'delete_season' ] );
		add_action( 'admin_post_loft_save_plan', [ __CLASS__, 'save_plan' ] );
		add_action( 'admin_post_loft_sync_plan', [ __CLASS__, 'sync_plan' ] );
		add_action( 'admin_post_loft_delete_plan', [ __CLASS__, 'delete_plan' ] );
		add_action( 'admin_post_loft_session_action', [ __CLASS__, 'session_action' ] );
		add_action( 'admin_post_loft_save_settings', [ __CLASS__, 'save_settings' ] );
		add_action( 'admin_post_loft_comp', [ __CLASS__, 'comp' ] );
		add_action( 'admin_post_loft_admin_book', [ __CLASS__, 'admin_book' ] );
		add_action( 'admin_post_loft_admin_cancel_booking', [ __CLASS__, 'admin_cancel_booking' ] );
		add_action( 'admin_notices', [ __CLASS__, 'notices' ] );
	}

	public static function menu(): void {
		add_menu_page( 'Loft Studio', 'Loft Studio', 'loft_manage_studio', 'loft-studio', [ __CLASS__, 'page_dashboard' ], 'dashicons-universal-access-alt', 30 );
		add_submenu_page( 'loft-studio', 'Dashboard', 'Dashboard', 'loft_manage_studio', 'loft-studio', [ __CLASS__, 'page_dashboard' ] );
		add_submenu_page( 'loft-studio', 'Sessions', 'Sessions', 'loft_manage_studio', 'loft-sessions', [ __CLASS__, 'page_sessions' ] );
		add_submenu_page( 'loft-studio', 'Seasons', 'Seasons', 'loft_manage_studio', 'loft-seasons', [ __CLASS__, 'page_seasons' ] );
		add_submenu_page( 'loft-studio', 'Plans & Pricing', 'Plans & Pricing', 'loft_manage_studio', 'loft-plans', [ __CLASS__, 'page_plans' ] );
		add_submenu_page( 'loft-studio', 'Members', 'Members', 'loft_manage_studio', 'loft-members', [ __CLASS__, 'page_members' ] );
		add_submenu_page( 'loft-studio', 'Settings', 'Settings', 'loft_manage_studio', 'loft-settings', [ __CLASS__, 'page_settings' ] );
	}

	private static function check( string $nonce_action ): void {
		if ( ! current_user_can( 'loft_manage_studio' ) ) {
			wp_die( 'Not allowed' );
		}
		check_admin_referer( $nonce_action );
	}

	private static function back( string $page, array $args = [] ): void {
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php?page=' . $page ) ) );
		exit;
	}

	public static function notices(): void {
		if ( ! empty( $_GET['loft_msg'] ) ) { // phpcs:ignore
			$type = ! empty( $_GET['loft_err'] ) ? 'error' : 'success'; // phpcs:ignore
			echo '<div class="notice notice-' . esc_attr( $type ) . ' is-dismissible"><p>' . esc_html( sanitize_text_field( wp_unslash( $_GET['loft_msg'] ) ) ) . '</p></div>'; // phpcs:ignore
		}
	}

	/* ------------------------------------------------------------ Dashboard */

	public static function page_dashboard(): void {
		global $wpdb;
		$p = \Loft\DB::table( '' );
		$tz = wp_timezone();
		$today_from = ( new \DateTime( 'today', $tz ) )->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
		$today_to   = ( new \DateTime( 'tomorrow', $tz ) )->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
		$today      = Sessions::between( $today_from, $today_to );
		$active_subs = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}entitlements WHERE kind='subscription' AND status='active'" ); // phpcs:ignore
		$past_due    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}entitlements WHERE status='past_due'" ); // phpcs:ignore
		$members     = count_users()['avail_roles']['loft_member'] ?? 0;
		$bookings_wk = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}bookings WHERE booked_at >= %s AND status <> 'cancelled'", gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS ) ) ); // phpcs:ignore
		$unprocessed = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}stripe_events WHERE error IS NOT NULL" ); // phpcs:ignore
		?>
		<div class="wrap loft-wrap">
			<h1>Loft Studio</h1>
			<?php if ( ! Client::configured() ) : ?>
				<div class="notice notice-warning"><p>Stripe isn't configured yet. <a href="<?php echo esc_url( admin_url( 'admin.php?page=loft-settings' ) ); ?>">Add your keys</a> to enable purchases.</p></div>
			<?php endif; ?>
			<?php if ( ! \Loft\Repos\Waivers::current() ) : ?>
				<div class="notice notice-warning"><p>No active waiver. <a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=loft_waiver' ) ); ?>">Create one</a> and tick "This is the active waiver".</p></div>
			<?php endif; ?>
			<div class="loft-stats">
				<div class="loft-stat"><span><?php echo (int) $members; ?></span>Members</div>
				<div class="loft-stat"><span><?php echo (int) $active_subs; ?></span>Active memberships</div>
				<div class="loft-stat<?php echo $past_due ? ' warn' : ''; ?>"><span><?php echo (int) $past_due; ?></span>Past due</div>
				<div class="loft-stat"><span><?php echo (int) $bookings_wk; ?></span>Bookings (7 days)</div>
				<?php if ( $unprocessed ) : ?><div class="loft-stat warn"><span><?php echo (int) $unprocessed; ?></span>Stripe events with errors</div><?php endif; ?>
			</div>
			<h2>Today</h2>
			<?php if ( ! $today ) : ?><p>No classes today.</p><?php else : ?>
			<table class="widefat striped"><thead><tr><th>Time</th><th>Program</th><th>Booked</th><th>Waitlist</th><th></th></tr></thead><tbody>
			<?php foreach ( $today as $s ) : $a = Sessions::to_api( $s ); ?>
				<tr><td><?php echo esc_html( $a['time_label'] ); ?></td><td><?php echo esc_html( $a['program_title'] ); ?></td><td><?php echo (int) $a['booked']; ?> / <?php echo (int) $a['capacity']; ?></td><td><?php echo (int) $a['waitlist']; ?></td>
				<td><a href="<?php echo esc_url( admin_url( 'admin.php?page=loft-sessions&session=' . $s->id ) ); ?>">Roster</a></td></tr>
			<?php endforeach; ?>
			</tbody></table>
			<?php endif; ?>
			<h2>Quick links</h2>
			<p>
				<a class="button" href="<?php echo esc_url( admin_url( 'post-new.php?post_type=loft_program' ) ); ?>">Add program</a>
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=loft-plans' ) ); ?>">Plans & pricing</a>
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=loft-seasons' ) ); ?>">Seasons</a>
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=loft-settings' ) ); ?>">Settings</a>
			</p>
			<p class="description">Shortcodes: <code>[loft_schedule]</code> <code>[loft_account]</code> <code>[loft_plans]</code> <code>[loft_waiver]</code> <code>[loft_register]</code> <code>[loft_checkin]</code></p>
		</div>
		<?php
	}

	/* ------------------------------------------------------------ Sessions */

	public static function page_sessions(): void {
		$session_id = (int) ( $_GET['session'] ?? 0 ); // phpcs:ignore
		if ( $session_id ) {
			self::roster_view( $session_id );
			return;
		}
		$tz      = wp_timezone();
		$program = (int) ( $_GET['program'] ?? 0 ); // phpcs:ignore
		$from    = isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( $_GET['from'] ) ) : 'today'; // phpcs:ignore
		$start   = new \DateTime( $from, $tz );
		$end     = ( clone $start )->modify( '+14 days' );
		$rows    = Sessions::between(
			( clone $start )->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ),
			( clone $end )->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ),
			[ 'include_cancelled' => true, 'program_id' => $program ]
		);
		?>
		<div class="wrap loft-wrap">
			<h1>Sessions <small><?php echo esc_html( $start->format( 'M j' ) . ' – ' . $end->format( 'M j, Y' ) ); ?></small></h1>
			<form method="get" class="loft-inline">
				<input type="hidden" name="page" value="loft-sessions">
				<input type="date" name="from" value="<?php echo esc_attr( $start->format( 'Y-m-d' ) ); ?>">
				<select name="program"><option value="0">All programs</option>
				<?php foreach ( get_posts( [ 'post_type' => \Loft\PostTypes::PROGRAM, 'numberposts' => -1, 'post_status' => 'any' ] ) as $pr ) : ?>
					<option value="<?php echo (int) $pr->ID; ?>" <?php selected( $program, $pr->ID ); ?>><?php echo esc_html( $pr->post_title ); ?></option>
				<?php endforeach; ?>
				</select>
				<button class="button">Filter</button>
				<a class="button" href="<?php echo esc_url( add_query_arg( 'from', ( clone $start )->modify( '-14 days' )->format( 'Y-m-d' ) ) ); ?>">‹ Prev</a>
				<a class="button" href="<?php echo esc_url( add_query_arg( 'from', $end->format( 'Y-m-d' ) ) ); ?>">Next ›</a>
			</form>
			<table class="widefat striped"><thead><tr><th>When</th><th>Program</th><th>Cap</th><th>Booked</th><th>Waitlist</th><th>Status</th><th>Actions</th></tr></thead><tbody>
			<?php if ( ! $rows ) : ?><tr><td colspan="7">No sessions. Open a Program and click "Generate sessions".</td></tr><?php endif; ?>
			<?php foreach ( $rows as $s ) : $a = Sessions::to_api( $s ); ?>
				<tr>
					<td><?php echo esc_html( $a['date_label'] . ' · ' . $a['time_label'] ); ?></td>
					<td><a href="<?php echo esc_url( get_edit_post_link( $s->program_id ) ); ?>"><?php echo esc_html( $a['program_title'] ); ?></a></td>
					<td>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="loft-inline">
							<?php wp_nonce_field( 'loft_session_' . $s->id ); ?>
							<input type="hidden" name="action" value="loft_session_action"><input type="hidden" name="do" value="capacity"><input type="hidden" name="session" value="<?php echo (int) $s->id; ?>">
							<input type="number" name="capacity" value="<?php echo (int) $s->capacity; ?>" min="1" style="width:60px"> <button class="button button-small">Save</button>
						</form>
					</td>
					<td><?php echo (int) $a['booked']; ?></td><td><?php echo (int) $a['waitlist']; ?></td>
					<td><?php echo esc_html( $s->status ); ?></td>
					<td>
						<a class="button button-small" href="<?php echo esc_url( admin_url( 'admin.php?page=loft-sessions&session=' . $s->id ) ); ?>">Roster</a>
						<?php if ( $s->status === 'scheduled' ) : ?>
							<a class="button button-small" onclick="return confirm('Cancel this session and notify everyone booked?')" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=loft_session_action&do=cancel&session=' . $s->id ), 'loft_session_' . $s->id ) ); ?>">Cancel</a>
						<?php elseif ( (int) $a['booked'] === 0 ) : ?>
							<a class="button button-small" onclick="return confirm('Delete this session permanently?')" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=loft_session_action&do=delete&session=' . $s->id ), 'loft_session_' . $s->id ) ); ?>">Delete</a>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody></table>
		</div>
		<?php
	}

	private static function roster_view( int $session_id ): void {
		$s = Sessions::find( $session_id );
		if ( ! $s ) {
			echo '<div class="wrap"><p>Session not found.</p></div>';
			return;
		}
		$a      = Sessions::to_api( $s );
		$roster = Bookings::for_session( $session_id );
		?>
		<div class="wrap loft-wrap">
			<h1><?php echo esc_html( $a['program_title'] ); ?> <small><?php echo esc_html( $a['date_label'] . ' · ' . $a['time_label'] ); ?></small></h1>
			<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=loft-sessions' ) ); ?>">← All sessions</a> · <?php echo (int) $a['booked']; ?>/<?php echo (int) $a['capacity']; ?> booked, <?php echo (int) $a['waitlist']; ?> waitlisted · Status: <?php echo esc_html( $s->status ); ?></p>
			<table class="widefat striped"><thead><tr><th>Attendee</th><th>Account</th><th>Status</th><th>Waiver</th><th>Notes</th><th></th></tr></thead><tbody>
			<?php foreach ( $roster as $b ) :
				$att  = \Loft\Repos\Attendees::find( (int) $b->attendee_id );
				$acct = get_userdata( (int) $b->account_user_id ); ?>
				<tr>
					<td><strong><?php echo esc_html( trim( $b->first_name . ' ' . $b->last_name ) ); ?></strong><?php if ( $att && \Loft\Repos\Attendees::age( $att ) !== null ) echo ' <span class="description">(' . (int) \Loft\Repos\Attendees::age( $att ) . ')</span>'; ?></td>
					<td><?php echo $acct ? esc_html( $acct->display_name ) . '<br><small>' . esc_html( $acct->user_email ) . ' ' . esc_html( get_user_meta( $acct->ID, 'loft_phone', true ) ) . '</small>' : '—'; ?></td>
					<td><?php echo esc_html( $b->status ); ?><?php echo $b->position ? ' #' . (int) $b->position : ''; ?></td>
					<td><?php echo $att && \Loft\Repos\Waivers::is_signed( (int) $att->id ) ? '✓' : '<span style="color:#b91c1c">missing</span>'; ?></td>
					<td><?php echo esc_html( $att->medical_notes ?? '' ); ?></td>
					<td><?php if ( in_array( $b->status, [ 'booked', 'waitlisted' ], true ) ) : ?>
						<a class="button button-small" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=loft_admin_cancel_booking&booking=' . $b->id . '&session=' . $session_id ), 'loft_cancel_' . $b->id ) ); ?>">Remove</a>
					<?php endif; ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody></table>

			<h2>Add attendee to this session</h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="loft-inline">
				<?php wp_nonce_field( 'loft_admin_book' ); ?>
				<input type="hidden" name="action" value="loft_admin_book"><input type="hidden" name="session" value="<?php echo (int) $session_id; ?>">
				<label>Attendee ID <input type="number" name="attendee_id" required style="width:90px"></label>
				<label><input type="checkbox" name="free" value="1"> Comp (don't use a credit)</label>
				<button class="button button-primary">Add</button>
				<span class="description">Find attendee IDs on the Members page.</span>
			</form>
		</div>
		<?php
	}

	public static function session_action(): void {
		$id = (int) ( $_REQUEST['session'] ?? 0 ); // phpcs:ignore
		self::check( 'loft_session_' . $id );
		$do = sanitize_text_field( wp_unslash( $_REQUEST['do'] ?? '' ) ); // phpcs:ignore
		switch ( $do ) {
			case 'cancel':
				$n = Booking::cancel_session( $id );
				self::back( 'loft-sessions', [ 'loft_msg' => "Session cancelled; $n bookings notified." ] );
			case 'delete':
				Sessions::delete( $id );
				self::back( 'loft-sessions', [ 'loft_msg' => 'Session deleted.' ] );
			case 'capacity':
				$cap = max( 1, (int) ( $_POST['capacity'] ?? 1 ) ); // phpcs:ignore
				Sessions::update( $id, [ 'capacity' => $cap ] );
				Booking::promote_waitlist( $id );
				self::back( 'loft-sessions', [ 'loft_msg' => 'Capacity updated.' ] );
		}
		self::back( 'loft-sessions' );
	}

	public static function generate_sessions(): void {
		$program = (int) ( $_GET['program'] ?? 0 ); // phpcs:ignore
		self::check( 'loft_generate_' . $program );
		$n = Sessions::generate_for_program( $program );
		wp_safe_redirect( add_query_arg( [ 'loft_msg' => "$n sessions generated." ], get_edit_post_link( $program, 'raw' ) ) );
		exit;
	}

	public static function admin_book(): void {
		self::check( 'loft_admin_book' );
		$session  = (int) ( $_POST['session'] ?? 0 ); // phpcs:ignore
		$attendee = \Loft\Repos\Attendees::find( (int) ( $_POST['attendee_id'] ?? 0 ) ); // phpcs:ignore
		if ( ! $attendee ) {
			self::back( 'loft-sessions', [ 'session' => $session, 'loft_msg' => 'Attendee not found.', 'loft_err' => 1 ] );
		}
		$res = Booking::book( (int) $attendee->account_user_id, $session, (int) $attendee->id, 'admin', null, ! empty( $_POST['free'] ) );
		if ( is_wp_error( $res ) ) {
			self::back( 'loft-sessions', [ 'session' => $session, 'loft_msg' => $res->get_error_message(), 'loft_err' => 1 ] );
		}
		self::back( 'loft-sessions', [ 'session' => $session, 'loft_msg' => 'Added.' ] );
	}

	public static function admin_cancel_booking(): void {
		$bid = (int) ( $_GET['booking'] ?? 0 ); // phpcs:ignore
		self::check( 'loft_cancel_' . $bid );
		Booking::cancel( $bid, null, true );
		self::back( 'loft-sessions', [ 'session' => (int) ( $_GET['session'] ?? 0 ), 'loft_msg' => 'Booking removed; credit returned if applicable.' ] ); // phpcs:ignore
	}

	/* ------------------------------------------------------------ Seasons */

	public static function page_seasons(): void {
		$seasons = Seasons::all();
		?>
		<div class="wrap loft-wrap">
			<h1>Seasons</h1>
			<table class="widefat striped"><thead><tr><th>Name</th><th>Starts</th><th>Ends</th><th>Current</th><th></th></tr></thead><tbody>
			<?php foreach ( $seasons as $s ) : ?>
				<tr><td><?php echo esc_html( $s->name ); ?></td><td><?php echo esc_html( $s->starts_on ); ?></td><td><?php echo esc_html( $s->ends_on ); ?></td><td><?php echo $s->is_current ? '✓' : ''; ?></td>
				<td><a onclick="return confirm('Delete season?')" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=loft_delete_season&id=' . $s->id ), 'loft_season' ) ); ?>">Delete</a></td></tr>
			<?php endforeach; ?>
			</tbody></table>
			<h2>Add season</h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="loft-inline">
				<?php wp_nonce_field( 'loft_season' ); ?><input type="hidden" name="action" value="loft_save_season">
				<input type="text" name="name" placeholder="2026–27 Season" required>
				<input type="date" name="starts_on" required> <input type="date" name="ends_on" required>
				<label><input type="checkbox" name="is_current" value="1" checked> Current</label>
				<button class="button button-primary">Save</button>
			</form>
		</div>
		<?php
	}

	public static function save_season(): void {
		self::check( 'loft_season' );
		Seasons::create( sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) ), sanitize_text_field( wp_unslash( $_POST['starts_on'] ?? '' ) ), sanitize_text_field( wp_unslash( $_POST['ends_on'] ?? '' ) ), ! empty( $_POST['is_current'] ) ); // phpcs:ignore
		self::back( 'loft-seasons', [ 'loft_msg' => 'Season saved.' ] );
	}

	public static function delete_season(): void {
		self::check( 'loft_season' );
		Seasons::delete( (int) ( $_GET['id'] ?? 0 ) ); // phpcs:ignore
		self::back( 'loft-seasons', [ 'loft_msg' => 'Season deleted.' ] );
	}

	/* ------------------------------------------------------------ Plans */

	public static function page_plans(): void {
		$edit  = isset( $_GET['edit'] ) ? Plans::find( (int) $_GET['edit'] ) : null; // phpcs:ignore
		$plans = Plans::all();
		$tags  = get_terms( [ 'taxonomy' => 'loft_tag', 'hide_empty' => false ] );
		$scope = $edit ? Plans::scope( $edit ) : 'all';
		?>
		<div class="wrap loft-wrap">
			<h1>Plans & Pricing</h1>
			<table class="widefat striped"><thead><tr><th>Name</th><th>Kind</th><th>Price</th><th>Details</th><th>Scope</th><th>Stripe</th><th>Active</th><th></th></tr></thead><tbody>
			<?php foreach ( $plans as $p ) : $sc = Plans::scope( $p ); ?>
				<tr>
					<td><strong><?php echo esc_html( $p->name ); ?></strong></td><td><?php echo esc_html( $p->kind ); ?></td>
					<td><?php echo esc_html( Plans::money( (int) $p->price_cents, $p->currency ) ); ?><?php echo $p->billing_interval ? '/mo' : ''; ?></td>
					<td><?php
						if ( $p->credits ) echo (int) $p->credits . ' credits';
						if ( $p->weekly_limit ) echo (int) $p->weekly_limit . '/week';
						if ( $p->registration_fee_cents ) echo ' +' . esc_html( Plans::money( (int) $p->registration_fee_cents ) ) . ' reg fee';
					?></td>
					<td><?php echo $sc === 'all' ? 'All programs' : esc_html( implode( ', ', (array) $sc ) ); ?></td>
					<td><?php echo $p->stripe_price_id ? '✓ ' . esc_html( $p->stripe_price_id ) : '<a class="button button-small" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=loft_sync_plan&id=' . $p->id ), 'loft_plan' ) ) . '">Create in Stripe</a>'; ?></td>
					<td><?php echo $p->active ? '✓' : '—'; ?></td>
					<td><a href="<?php echo esc_url( admin_url( 'admin.php?page=loft-plans&edit=' . $p->id ) ); ?>">Edit</a> · <a onclick="return confirm('Delete plan? Existing entitlements are kept.')" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=loft_delete_plan&id=' . $p->id ), 'loft_plan' ) ); ?>">Delete</a></td>
				</tr>
			<?php endforeach; ?>
			</tbody></table>

			<h2><?php echo $edit ? 'Edit plan' : 'Add plan'; ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'loft_plan' ); ?><input type="hidden" name="action" value="loft_save_plan"><input type="hidden" name="id" value="<?php echo (int) ( $edit->id ?? 0 ); ?>">
				<table class="form-table">
					<tr><th>Name</th><td><input class="regular-text" name="name" required value="<?php echo esc_attr( $edit->name ?? '' ); ?>"></td></tr>
					<tr><th>Kind</th><td><select name="kind" id="loft_kind">
						<?php foreach ( [ 'subscription' => 'Monthly membership (recurring)', 'season' => 'Seasonal tuition (monthly until season ends)', 'pack' => 'Class pack (credits)', 'dropin' => 'Single drop-in' ] as $k => $l ) : ?>
							<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $edit->kind ?? 'subscription', $k ); ?>><?php echo esc_html( $l ); ?></option>
						<?php endforeach; ?></select></td></tr>
					<tr><th>Price (USD)</th><td><input type="number" step="0.01" min="0" name="price" required value="<?php echo esc_attr( isset( $edit ) ? number_format( $edit->price_cents / 100, 2, '.', '' ) : '' ); ?>"> <span class="description">per month for memberships/tuition; total for packs/drop-ins</span></td></tr>
					<tr><th>Credits (packs)</th><td><input type="number" name="credits" min="1" value="<?php echo esc_attr( $edit->credits ?? '' ); ?>"> valid <input type="number" name="credit_validity_days" min="0" value="<?php echo esc_attr( $edit->credit_validity_days ?? '' ); ?>"> days (blank = default)</td></tr>
					<tr><th>Weekly limit (memberships)</th><td><input type="number" name="weekly_limit" min="1" value="<?php echo esc_attr( $edit->weekly_limit ?? '' ); ?>"> <span class="description">blank = unlimited</span></td></tr>
					<tr><th>Season (seasonal tuition)</th><td><select name="season_id"><option value="">—</option><?php foreach ( Seasons::all() as $s ) : ?><option value="<?php echo (int) $s->id; ?>" <?php selected( $edit->season_id ?? 0, $s->id ); ?>><?php echo esc_html( $s->name ); ?></option><?php endforeach; ?></select>
						registration fee $<input type="number" step="0.01" min="0" name="registration_fee" value="<?php echo esc_attr( isset( $edit ) ? number_format( $edit->registration_fee_cents / 100, 2, '.', '' ) : '' ); ?>" style="width:90px"></td></tr>
					<tr><th>Covers programs tagged</th><td>
						<label><input type="checkbox" name="scope_all" value="1" <?php checked( $scope === 'all' ); ?>> All programs</label><br>
						<?php foreach ( (array) $tags as $t ) : ?><label><input type="checkbox" name="scope[]" value="<?php echo esc_attr( $t->slug ); ?>" <?php checked( is_array( $scope ) && in_array( $t->slug, $scope, true ) ); ?>> <?php echo esc_html( $t->name ); ?></label> <?php endforeach; ?>
						<p class="description">Tags are set on each Program under "Plan Tags".</p></td></tr>
					<tr><th>Description</th><td><textarea name="description" class="large-text" rows="2"><?php echo esc_textarea( $edit->description ?? '' ); ?></textarea></td></tr>
					<tr><th>Active</th><td><label><input type="checkbox" name="active" value="1" <?php checked( $edit->active ?? 1 ); ?>> Visible to members</label> &nbsp; Sort <input type="number" name="sort" value="<?php echo (int) ( $edit->sort ?? 0 ); ?>" style="width:70px"></td></tr>
				</table>
				<p><button class="button button-primary">Save plan</button> <?php if ( $edit ) : ?><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=loft-plans' ) ); ?>">Cancel</a><?php endif; ?></p>
				<p class="description">After saving, click "Create in Stripe" to generate the product and price. Changing the price later creates a new Stripe price on next sync (clear the Stripe IDs by re-saving with a new price).</p>
			</form>
		</div>
		<?php
	}

	public static function save_plan(): void {
		self::check( 'loft_plan' );
		$id   = (int) ( $_POST['id'] ?? 0 ); // phpcs:ignore
		$in   = wp_unslash( $_POST ); // phpcs:ignore
		$data = [
			'name'                   => $in['name'] ?? '',
			'kind'                   => $in['kind'] ?? 'pack',
			'price_cents'            => (int) round( (float) ( $in['price'] ?? 0 ) * 100 ),
			'currency'               => Settings::get( 'currency', 'usd' ),
			'credits'                => $in['credits'] ?? null,
			'credit_validity_days'   => $in['credit_validity_days'] ?? null,
			'weekly_limit'           => $in['weekly_limit'] ?? null,
			'season_id'              => $in['season_id'] ?? null,
			'registration_fee_cents' => (int) round( (float) ( $in['registration_fee'] ?? 0 ) * 100 ),
			'program_scope'          => ! empty( $in['scope_all'] ) ? 'all' : ( $in['scope'] ?? [] ),
			'description'            => $in['description'] ?? '',
			'active'                 => ! empty( $in['active'] ),
			'sort'                   => $in['sort'] ?? 0,
		];
		if ( $id ) {
			$old = Plans::find( $id );
			if ( $old && ( (int) $old->price_cents !== $data['price_cents'] || $old->kind !== $data['kind'] ) ) {
				$data['stripe_price_id'] = null; // price changed → new Stripe price on next sync
			}
			if ( $old && (int) $old->registration_fee_cents !== $data['registration_fee_cents'] ) {
				$data['stripe_fee_price_id'] = null;
			}
			Plans::update( $id, $data );
		} else {
			$id = Plans::create( $data );
		}
		self::back( 'loft-plans', [ 'loft_msg' => 'Plan saved.' ] );
	}

	public static function sync_plan(): void {
		self::check( 'loft_plan' );
		$plan = Plans::find( (int) ( $_GET['id'] ?? 0 ) ); // phpcs:ignore
		if ( ! $plan ) {
			self::back( 'loft-plans', [ 'loft_msg' => 'Plan not found', 'loft_err' => 1 ] );
		}
		$res = Client::sync_plan( $plan );
		if ( is_wp_error( $res ) ) {
			self::back( 'loft-plans', [ 'loft_msg' => 'Stripe: ' . $res->get_error_message(), 'loft_err' => 1 ] );
		}
		if ( $res ) {
			Plans::update( (int) $plan->id, $res );
		}
		self::back( 'loft-plans', [ 'loft_msg' => 'Plan synced with Stripe.' ] );
	}

	public static function delete_plan(): void {
		self::check( 'loft_plan' );
		Plans::delete( (int) ( $_GET['id'] ?? 0 ) ); // phpcs:ignore
		self::back( 'loft-plans', [ 'loft_msg' => 'Plan deleted.' ] );
	}

	/* ------------------------------------------------------------ Members */

	public static function page_members(): void {
		$q     = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore
		$users = get_users( [ 'role__in' => [ 'loft_member' ], 'search' => $q ? '*' . $q . '*' : '', 'number' => 100, 'orderby' => 'registered', 'order' => 'DESC' ] );
		$view  = (int) ( $_GET['user'] ?? 0 ); // phpcs:ignore
		?>
		<div class="wrap loft-wrap">
			<h1>Members</h1>
			<?php if ( $view ) : $u = get_userdata( $view ); if ( $u ) : ?>
				<h2><?php echo esc_html( $u->display_name ); ?> <small><?php echo esc_html( $u->user_email ); ?> · <?php echo esc_html( get_user_meta( $u->ID, 'loft_phone', true ) ); ?></small></h2>
				<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=loft-members' ) ); ?>">← All members</a> · <a href="<?php echo esc_url( get_edit_user_link( $u->ID ) ); ?>">WP profile</a></p>
				<h3>Attendees</h3>
				<table class="widefat striped"><thead><tr><th>ID</th><th>Name</th><th>Age</th><th>Waiver</th><th>Notes</th></tr></thead><tbody>
				<?php foreach ( \Loft\Repos\Attendees::for_user( $u->ID, true ) as $a ) : ?>
					<tr><td><?php echo (int) $a->id; ?></td><td><?php echo esc_html( $a->first_name . ' ' . $a->last_name ); ?><?php echo $a->status === 'archived' ? ' (archived)' : ''; ?></td><td><?php echo esc_html( (string) \Loft\Repos\Attendees::age( $a ) ); ?></td><td><?php echo \Loft\Repos\Waivers::is_signed( (int) $a->id ) ? '✓' : '—'; ?></td><td><?php echo esc_html( $a->medical_notes ); ?></td></tr>
				<?php endforeach; ?>
				</tbody></table>
				<h3>Plans</h3>
				<table class="widefat striped"><thead><tr><th>Plan</th><th>Kind</th><th>Status</th><th>Credits</th><th>Period end / expires</th><th>Stripe</th></tr></thead><tbody>
				<?php foreach ( Entitlements::for_user( $u->ID ) as $e ) : ?>
					<tr><td><?php echo esc_html( $e->plan_name ); ?></td><td><?php echo esc_html( $e->kind ); ?></td><td><?php echo esc_html( $e->status ); ?></td><td><?php echo $e->credits_total !== null ? (int) $e->credits_remaining . '/' . (int) $e->credits_total : '—'; ?></td><td><?php echo esc_html( $e->current_period_end ?: $e->expires_at ?: '—' ); ?></td><td><?php echo esc_html( $e->stripe_subscription_id ?: '' ); ?></td></tr>
				<?php endforeach; ?>
				</tbody></table>
				<h3>Comp a plan (no charge)</h3>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="loft-inline">
					<?php wp_nonce_field( 'loft_comp' ); ?><input type="hidden" name="action" value="loft_comp"><input type="hidden" name="user" value="<?php echo (int) $u->ID; ?>">
					<select name="plan_id"><?php foreach ( Plans::all() as $p ) : ?><option value="<?php echo (int) $p->id; ?>"><?php echo esc_html( $p->name ); ?></option><?php endforeach; ?></select>
					<button class="button">Grant</button>
				</form>
				<h3>Upcoming bookings</h3>
				<ul><?php foreach ( Bookings::for_user( $u->ID ) as $b ) : $ba = Bookings::to_api( $b ); ?><li><?php echo esc_html( "{$ba['date_label']} {$ba['time_label']} — {$ba['program_title']} ({$ba['attendee_name']}, {$ba['status']})" ); ?></li><?php endforeach; ?></ul>
			<?php endif; else : ?>
				<form method="get" class="loft-inline"><input type="hidden" name="page" value="loft-members"><input type="search" name="s" value="<?php echo esc_attr( $q ); ?>" placeholder="Search name or email"><button class="button">Search</button></form>
				<table class="widefat striped"><thead><tr><th>Member</th><th>Email</th><th>Phone</th><th>Attendees</th><th>Active plans</th><th>Joined</th></tr></thead><tbody>
				<?php foreach ( $users as $u ) : ?>
					<tr><td><a href="<?php echo esc_url( admin_url( 'admin.php?page=loft-members&user=' . $u->ID ) ); ?>"><?php echo esc_html( $u->display_name ); ?></a></td><td><?php echo esc_html( $u->user_email ); ?></td><td><?php echo esc_html( get_user_meta( $u->ID, 'loft_phone', true ) ); ?></td>
					<td><?php echo count( \Loft\Repos\Attendees::for_user( $u->ID ) ); ?></td><td><?php echo count( Entitlements::for_user( $u->ID, true ) ); ?></td><td><?php echo esc_html( substr( $u->user_registered, 0, 10 ) ); ?></td></tr>
				<?php endforeach; ?>
				</tbody></table>
			<?php endif; ?>
		</div>
		<?php
	}

	public static function comp(): void {
		self::check( 'loft_comp' );
		$plan = Plans::find( (int) ( $_POST['plan_id'] ?? 0 ) ); // phpcs:ignore
		$user = (int) ( $_POST['user'] ?? 0 ); // phpcs:ignore
		if ( $plan && $user ) {
			Entitlements::grant_from_plan( $user, $plan );
		}
		self::back( 'loft-members', [ 'user' => $user, 'loft_msg' => 'Plan granted.' ] );
	}

	/* ------------------------------------------------------------ Settings */

	public static function page_settings(): void {
		$s = Settings::all();
		$pages = get_pages();
		$page_select = function ( $name ) use ( $s, $pages ) {
			echo '<select name="' . esc_attr( $name ) . '"><option value="0">— select page —</option>';
			foreach ( $pages as $p ) {
				echo '<option value="' . (int) $p->ID . '"' . selected( (int) $s[ $name ], $p->ID, false ) . '>' . esc_html( $p->post_title ) . '</option>';
			}
			echo '</select>';
		};
		$f = function ( $name, $type = 'text', $extra = '' ) use ( $s ) {
			echo '<input type="' . esc_attr( $type ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $s[ $name ] ) . '" class="regular-text" ' . $extra . '>';
		};
		?>
		<div class="wrap loft-wrap">
			<h1>Loft Studio Settings</h1>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'loft_settings' ); ?><input type="hidden" name="action" value="loft_save_settings">
				<h2>Studio</h2>
				<table class="form-table">
					<tr><th>Studio name</th><td><?php $f( 'studio_name' ); ?></td></tr>
					<tr><th>Accent color</th><td><?php $f( 'accent_color', 'color' ); ?></td></tr>
					<tr><th>Currency</th><td><?php $f( 'currency', 'text', 'style="width:80px"' ); ?> <span class="description">ISO code, e.g. usd</span></td></tr>
					<tr><th>Timezone</th><td><?php echo esc_html( wp_timezone_string() ); ?> <span class="description">(set in Settings → General)</span></td></tr>
				</table>
				<h2>Pages</h2>
				<table class="form-table">
					<tr><th>Schedule page <code>[loft_schedule]</code></th><td><?php $page_select( 'schedule_page_id' ); ?></td></tr>
					<tr><th>Account page <code>[loft_account]</code></th><td><?php $page_select( 'account_page_id' ); ?></td></tr>
					<tr><th>Plans page <code>[loft_plans]</code></th><td><?php $page_select( 'plans_page_id' ); ?></td></tr>
					<tr><th>Waiver page <code>[loft_waiver]</code></th><td><?php $page_select( 'waiver_page_id' ); ?></td></tr>
				</table>
				<h2>Booking policy</h2>
				<table class="form-table">
					<tr><th>Booking opens (days before)</th><td><?php $f( 'booking_opens_days_before', 'number' ); ?></td></tr>
					<tr><th>Booking closes (minutes before start)</th><td><?php $f( 'booking_closes_minutes_before', 'number' ); ?></td></tr>
					<tr><th>Late-cancel window (hours)</th><td><?php $f( 'late_cancel_hours', 'number' ); ?></td></tr>
					<tr><th>Late cancel keeps credit?</th><td><label><input type="checkbox" name="late_cancel_keeps_credit" value="1" <?php checked( $s['late_cancel_keeps_credit'] ); ?>> Yes, return the credit even on late cancels</label></td></tr>
					<tr><th>Waitlist auto-promote</th><td><label><input type="checkbox" name="waitlist_auto_promote" value="1" <?php checked( $s['waitlist_auto_promote'] ); ?>> Automatically move the next person in when a spot opens</label></td></tr>
					<tr><th>Default credit validity (days)</th><td><?php $f( 'credit_validity_days', 'number' ); ?></td></tr>
					<tr><th>Waiver valid for (days)</th><td><?php $f( 'waiver_validity_days', 'number' ); ?> <span class="description">0 = never expires (until a new version)</span></td></tr>
					<tr><th>Minor age threshold</th><td><?php $f( 'minor_age', 'number' ); ?></td></tr>
					<tr><th>Reminder email (hours before)</th><td><?php $f( 'reminder_hours_before', 'number' ); ?></td></tr>
				</table>
				<h2>Email</h2>
				<table class="form-table">
					<tr><th>From name</th><td><?php $f( 'email_from_name' ); ?></td></tr>
					<tr><th>From address</th><td><?php $f( 'email_from_address', 'email' ); ?></td></tr>
				</table>
				<h2>Stripe</h2>
				<table class="form-table">
					<tr><th>Mode</th><td><label><input type="radio" name="stripe_mode" value="test" <?php checked( $s['stripe_mode'], 'test' ); ?>> Test</label> &nbsp; <label><input type="radio" name="stripe_mode" value="live" <?php checked( $s['stripe_mode'], 'live' ); ?>> Live</label></td></tr>
					<tr><th>Test publishable key</th><td><?php $f( 'stripe_test_publishable' ); ?></td></tr>
					<tr><th>Test secret key</th><td><?php $f( 'stripe_test_secret', 'password' ); ?></td></tr>
					<tr><th>Test webhook signing secret</th><td><?php $f( 'stripe_test_webhook_secret', 'password' ); ?></td></tr>
					<tr><th>Live publishable key</th><td><?php $f( 'stripe_live_publishable' ); ?></td></tr>
					<tr><th>Live secret key</th><td><?php $f( 'stripe_live_secret', 'password' ); ?></td></tr>
					<tr><th>Live webhook signing secret</th><td><?php $f( 'stripe_live_webhook_secret', 'password' ); ?></td></tr>
					<tr><th>Webhook URL</th><td><code><?php echo esc_html( rest_url( 'loft/v1/stripe/webhook' ) ); ?></code><p class="description">In Stripe → Developers → Webhooks, add this URL with events: checkout.session.completed, invoice.paid, invoice.payment_failed, customer.subscription.updated, customer.subscription.deleted. Also enable the Customer Portal in Stripe settings.</p></td></tr>
				</table>
				<p><button class="button button-primary">Save settings</button></p>
			</form>
		</div>
		<?php
	}

	public static function save_settings(): void {
		self::check( 'loft_settings' );
		$in   = wp_unslash( $_POST ); // phpcs:ignore
		$keys = array_keys( Settings::defaults() );
		$out  = [];
		foreach ( $keys as $k ) {
			if ( in_array( $k, [ 'late_cancel_keeps_credit', 'waitlist_auto_promote', 'no_show_consumes_credit' ], true ) ) {
				$out[ $k ] = ! empty( $in[ $k ] ) ? 1 : 0;
			} elseif ( array_key_exists( $k, $in ) ) {
				$out[ $k ] = is_numeric( $in[ $k ] ) && ! str_contains( $k, 'stripe' ) && ! str_contains( $k, 'color' ) ? (int) $in[ $k ] : sanitize_text_field( (string) $in[ $k ] );
			}
		}
		Settings::update( $out );
		self::back( 'loft-settings', [ 'loft_msg' => 'Settings saved.' ] );
	}
}
