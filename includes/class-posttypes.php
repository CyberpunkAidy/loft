<?php
namespace Loft;

defined( 'ABSPATH' ) || exit;

/**
 * Programs (classes & events) and Waivers as custom post types.
 */
class PostTypes {

	const PROGRAM = 'loft_program';
	const WAIVER  = 'loft_waiver';

	public static function init(): void {
		add_action( 'init', [ __CLASS__, 'register' ] );
		add_action( 'add_meta_boxes', [ __CLASS__, 'meta_boxes' ] );
		add_action( 'save_post_' . self::PROGRAM, [ __CLASS__, 'save_program' ], 10, 2 );
		add_action( 'save_post_' . self::WAIVER, [ __CLASS__, 'save_waiver' ], 10, 2 );
	}

	public static function register(): void {
		register_post_type( self::PROGRAM, [
			'labels'       => [
				'name'          => __( 'Programs', 'loft-studio' ),
				'singular_name' => __( 'Program', 'loft-studio' ),
				'add_new_item'  => __( 'Add Program', 'loft-studio' ),
				'edit_item'     => __( 'Edit Program', 'loft-studio' ),
				'menu_name'     => __( 'Programs', 'loft-studio' ),
			],
			'public'       => true,
			'has_archive'  => false,
			'rewrite'      => [ 'slug' => 'programs' ],
			'show_in_menu' => 'loft-studio',
			'show_in_rest' => true,
			'supports'     => [ 'title', 'editor', 'thumbnail', 'excerpt' ],
			'capability_type' => 'post',
		] );

		register_taxonomy( 'loft_age_group', self::PROGRAM, [
			'label'        => __( 'Age Groups', 'loft-studio' ),
			'hierarchical' => false,
			'show_in_rest' => true,
			'rewrite'      => [ 'slug' => 'age-group' ],
		] );
		register_taxonomy( 'loft_level', self::PROGRAM, [
			'label'        => __( 'Levels', 'loft-studio' ),
			'hierarchical' => false,
			'show_in_rest' => true,
			'rewrite'      => [ 'slug' => 'level' ],
		] );
		register_taxonomy( 'loft_tag', self::PROGRAM, [
			'label'        => __( 'Plan Tags', 'loft-studio' ),
			'description'  => __( 'Used to scope which plans cover which programs.', 'loft-studio' ),
			'hierarchical' => false,
			'show_in_rest' => true,
			'rewrite'      => false,
		] );

		register_post_type( self::WAIVER, [
			'labels'       => [
				'name'          => __( 'Waivers', 'loft-studio' ),
				'singular_name' => __( 'Waiver', 'loft-studio' ),
				'add_new_item'  => __( 'Add Waiver', 'loft-studio' ),
				'menu_name'     => __( 'Waivers', 'loft-studio' ),
			],
			'public'       => false,
			'show_ui'      => true,
			'show_in_menu' => 'loft-studio',
			'supports'     => [ 'title', 'editor', 'revisions' ],
			'capability_type' => 'post',
		] );

		// Seed default terms once.
		if ( ! get_option( 'loft_terms_seeded' ) ) {
			foreach ( [ 'Kids', 'Teens', 'Adults' ] as $t ) {
				wp_insert_term( $t, 'loft_age_group' );
			}
			foreach ( [ 'Recreational', 'Competitive', 'Technique', 'Open Gym' ] as $t ) {
				wp_insert_term( $t, 'loft_level' );
			}
			foreach ( [ 'recreational', 'competitive', 'events' ] as $t ) {
				wp_insert_term( $t, 'loft_tag' );
			}
			update_option( 'loft_terms_seeded', 1 );
		}
	}

	/* ---------------------------------------------------------------- Program meta */

	public static function program_fields(): array {
		return [
			'type'          => [ 'label' => __( 'Type', 'loft-studio' ), 'type' => 'select', 'options' => [ 'class' => 'Recurring class', 'event' => 'One-off event / workshop' ] ],
			'season_id'     => [ 'label' => __( 'Season', 'loft-studio' ), 'type' => 'season' ],
			'weekday'       => [ 'label' => __( 'Weekday', 'loft-studio' ), 'type' => 'select', 'options' => [ '' => '—', 1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 0 => 'Sunday' ] ],
			'start_time'    => [ 'label' => __( 'Start time', 'loft-studio' ), 'type' => 'time' ],
			'duration_min'  => [ 'label' => __( 'Duration (minutes)', 'loft-studio' ), 'type' => 'number', 'default' => 60 ],
			'event_date'    => [ 'label' => __( 'Event date (one-off only)', 'loft-studio' ), 'type' => 'date' ],
			'capacity'      => [ 'label' => __( 'Capacity', 'loft-studio' ), 'type' => 'number', 'default' => 10 ],
			'dropin_price'  => [ 'label' => __( 'Drop-in / event price (USD)', 'loft-studio' ), 'type' => 'money' ],
			'instructor_id' => [ 'label' => __( 'Instructor', 'loft-studio' ), 'type' => 'user' ],
			'min_age'       => [ 'label' => __( 'Min age', 'loft-studio' ), 'type' => 'number' ],
			'max_age'       => [ 'label' => __( 'Max age', 'loft-studio' ), 'type' => 'number' ],
			'members_only'  => [ 'label' => __( 'Members only (no drop-in)', 'loft-studio' ), 'type' => 'checkbox' ],
		];
	}

	public static function meta_boxes(): void {
		add_meta_box( 'loft_program_details', __( 'Schedule & Pricing', 'loft-studio' ), [ __CLASS__, 'render_program_box' ], self::PROGRAM, 'normal', 'high' );
		add_meta_box( 'loft_program_sessions', __( 'Sessions', 'loft-studio' ), [ __CLASS__, 'render_sessions_box' ], self::PROGRAM, 'normal', 'default' );
		add_meta_box( 'loft_waiver_version', __( 'Version', 'loft-studio' ), [ __CLASS__, 'render_waiver_box' ], self::WAIVER, 'side', 'high' );
	}

	public static function get_program_meta( int $post_id ): array {
		$out = [];
		foreach ( self::program_fields() as $key => $f ) {
			$v = get_post_meta( $post_id, '_loft_' . $key, true );
			$out[ $key ] = ( $v === '' && isset( $f['default'] ) ) ? $f['default'] : $v;
		}
		return $out;
	}

	public static function render_program_box( \WP_Post $post ): void {
		wp_nonce_field( 'loft_program_save', 'loft_program_nonce' );
		$meta = self::get_program_meta( $post->ID );
		echo '<table class="form-table loft-admin-table">';
		foreach ( self::program_fields() as $key => $f ) {
			$val  = $meta[ $key ];
			$name = 'loft[' . $key . ']';
			echo '<tr><th><label for="loft_' . esc_attr( $key ) . '">' . esc_html( $f['label'] ) . '</label></th><td>';
			switch ( $f['type'] ) {
				case 'select':
					echo '<select name="' . esc_attr( $name ) . '" id="loft_' . esc_attr( $key ) . '">';
					foreach ( $f['options'] as $ov => $ol ) {
						echo '<option value="' . esc_attr( (string) $ov ) . '"' . selected( (string) $val, (string) $ov, false ) . '>' . esc_html( $ol ) . '</option>';
					}
					echo '</select>';
					break;
				case 'season':
					echo '<select name="' . esc_attr( $name ) . '"><option value="">—</option>';
					foreach ( Repos\Seasons::all() as $s ) {
						echo '<option value="' . (int) $s->id . '"' . selected( (int) $val, (int) $s->id, false ) . '>' . esc_html( $s->name ) . '</option>';
					}
					echo '</select>';
					break;
				case 'user':
					wp_dropdown_users( [ 'name' => $name, 'selected' => (int) $val, 'show_option_none' => '—', 'role__in' => [ 'administrator', 'loft_instructor' ] ] );
					break;
				case 'checkbox':
					echo '<input type="checkbox" name="' . esc_attr( $name ) . '" value="1"' . checked( $val, 1, false ) . '>';
					break;
				case 'money':
					echo '<input type="number" step="0.01" min="0" name="' . esc_attr( $name ) . '" value="' . esc_attr( $val ) . '" class="small-text">';
					break;
				default:
					echo '<input type="' . esc_attr( $f['type'] ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $val ) . '" class="regular-text">';
			}
			echo '</td></tr>';
		}
		echo '</table>';
		echo '<p class="description">' . esc_html__( 'Assign Plan Tags (right sidebar) so plans know which programs they cover. Age Group and Level drive the schedule filters.', 'loft-studio' ) . '</p>';
	}

	public static function render_sessions_box( \WP_Post $post ): void {
		$sessions = Repos\Sessions::for_program( $post->ID, 200 );
		$tz       = wp_timezone();
		echo '<p>';
		printf(
			'<a class="button button-primary" href="%s">%s</a> ',
			esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=loft_generate_sessions&program=' . $post->ID ), 'loft_generate_' . $post->ID ) ),
			esc_html__( 'Generate sessions from schedule', 'loft-studio' )
		);
		printf(
			'<a class="button" href="%s">%s</a>',
			esc_url( admin_url( 'admin.php?page=loft-sessions&program=' . $post->ID ) ),
			esc_html__( 'Manage sessions', 'loft-studio' )
		);
		echo '</p>';
		if ( ! $sessions ) {
			echo '<p>' . esc_html__( 'No sessions yet. Save the program, then generate sessions.', 'loft-studio' ) . '</p>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr><th>Date</th><th>Time</th><th>Booked / Cap</th><th>Status</th></tr></thead><tbody>';
		foreach ( array_slice( $sessions, 0, 40 ) as $s ) {
			$start = ( new \DateTime( $s->starts_at, new \DateTimeZone( 'UTC' ) ) )->setTimezone( $tz );
			$end   = ( new \DateTime( $s->ends_at, new \DateTimeZone( 'UTC' ) ) )->setTimezone( $tz );
			printf(
				'<tr><td>%s</td><td>%s – %s</td><td>%d / %d</td><td>%s</td></tr>',
				esc_html( $start->format( 'D M j, Y' ) ),
				esc_html( $start->format( 'g:i A' ) ),
				esc_html( $end->format( 'g:i A' ) ),
				(int) $s->booked_count,
				(int) $s->capacity,
				esc_html( $s->status )
			);
		}
		echo '</tbody></table>';
		if ( count( $sessions ) > 40 ) {
			echo '<p class="description">' . esc_html( sprintf( __( 'Showing 40 of %d.', 'loft-studio' ), count( $sessions ) ) ) . '</p>';
		}
	}

	public static function save_program( int $post_id, \WP_Post $post ): void {
		if ( ! isset( $_POST['loft_program_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['loft_program_nonce'] ) ), 'loft_program_save' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$in = isset( $_POST['loft'] ) && is_array( $_POST['loft'] ) ? wp_unslash( $_POST['loft'] ) : []; // phpcs:ignore
		foreach ( self::program_fields() as $key => $f ) {
			$raw = $in[ $key ] ?? '';
			switch ( $f['type'] ) {
				case 'checkbox':
					$val = $raw ? 1 : 0;
					break;
				case 'number':
				case 'user':
				case 'season':
					$val = $raw === '' ? '' : (int) $raw;
					break;
				case 'money':
					$val = $raw === '' ? '' : round( (float) $raw, 2 );
					break;
				default:
					$val = sanitize_text_field( (string) $raw );
			}
			update_post_meta( $post_id, '_loft_' . $key, $val );
		}
	}

	/* ---------------------------------------------------------------- Waiver meta */

	public static function render_waiver_box( \WP_Post $post ): void {
		wp_nonce_field( 'loft_waiver_save', 'loft_waiver_nonce' );
		$version = (int) get_post_meta( $post->ID, '_loft_version', true ) ?: 1;
		$current = (int) get_option( 'loft_current_waiver' ) === $post->ID;
		echo '<p><strong>' . esc_html__( 'Version', 'loft-studio' ) . ':</strong> ' . (int) $version . '</p>';
		echo '<p><label><input type="checkbox" name="loft_bump_version" value="1"> ' . esc_html__( 'Publish as new version (everyone must re-sign)', 'loft-studio' ) . '</label></p>';
		echo '<p><label><input type="checkbox" name="loft_make_current" value="1"' . checked( $current, true, false ) . '> ' . esc_html__( 'This is the active waiver', 'loft-studio' ) . '</label></p>';
	}

	public static function save_waiver( int $post_id, \WP_Post $post ): void {
		if ( ! isset( $_POST['loft_waiver_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['loft_waiver_nonce'] ) ), 'loft_waiver_save' ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$version = (int) get_post_meta( $post_id, '_loft_version', true ) ?: 1;
		if ( ! empty( $_POST['loft_bump_version'] ) ) {
			$version++;
		}
		update_post_meta( $post_id, '_loft_version', $version );
		if ( ! empty( $_POST['loft_make_current'] ) ) {
			update_option( 'loft_current_waiver', $post_id );
		} elseif ( (int) get_option( 'loft_current_waiver' ) === $post_id ) {
			delete_option( 'loft_current_waiver' );
		}
	}
}
