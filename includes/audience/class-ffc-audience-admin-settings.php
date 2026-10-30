<?php
/**
 * Audience Admin Settings
 *
 * Handles the settings page and global holiday management for the
 * unified scheduling system.
 *
 * @package FreeFormCertificate\Audience
 * @since 4.6.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Audience;

use FreeFormCertificate\Core\Utils;
use FreeFormCertificate\Core\LabelSorter;
use FreeFormCertificate\Core\RequestInput;

use FreeFormCertificate\Core\ColorValidator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Audience Admin Settings.
 */
class AudienceAdminSettings {

	/**
	 * Menu slug prefix
	 *
	 * @var string
	 */
	private string $menu_slug;

	/**
	 * Renderer for the embedded Import & Export tab.
	 *
	 * @var AudienceAdminImport
	 */
	private AudienceAdminImport $import;

	/**
	 * Constructor
	 *
	 * @param string              $menu_slug Menu slug prefix.
	 * @param AudienceAdminImport $import    Import & Export renderer, embedded
	 *                                       as the 4th tab.
	 */
	public function __construct( string $menu_slug, AudienceAdminImport $import ) {
		$this->menu_slug = $menu_slug;
		$this->import    = $import;
	}

	/**
	 * Render settings page
	 *
	 * @return void
	 */
	public function render_page(): void {
		$active_tab = RequestInput::get_get_string( 'tab', 'general' );

		// Base tabs; modules may contribute more via the filter below (e.g. the
		// appointment-receipt tab, #945). General pinned first; the rest read A→Z
		// by translated label via the shared LabelSorter.
		$base_tabs = array(
			'general'         => array(
				'label' => __( 'General', 'ffcertificate' ),
				'icon'  => 'ffc-icon-settings',
			),
			'self-scheduling' => array(
				'label' => __( 'Personal Calendars', 'ffcertificate' ),
				'icon'  => 'ffc-icon-calendar',
			),
			'audience'        => array(
				'label' => __( 'Audience Calendars', 'ffcertificate' ),
				'icon'  => 'ffc-icon-users',
			),
			'import'          => array(
				'label' => __( 'Import & Export', 'ffcertificate' ),
				'icon'  => 'ffc-icon-upload',
			),
		);

		/**
		 * Filters the Scheduling Settings tabs. A contributor adds
		 * `['<id>' => ['label' => …, 'icon' => …]]` and renders its panel on the
		 * `ffc_scheduling_settings_render_tab_<id>` action.
		 *
		 * @since 6.20.0
		 * @param array<string, array{label:string, icon:string}> $base_tabs Tab definitions.
		 */
		$base_tabs = apply_filters( 'ffc_scheduling_settings_tabs', $base_tabs );

		$tabs = LabelSorter::sort(
			$base_tabs,
			static function ( array $tab ): string {
				return (string) $tab['label'];
			},
			array( 'general' )
		);
		if ( ! isset( $tabs[ $active_tab ] ) ) {
			$active_tab = 'general';
		}
		?>
		<div class="wrap ffc-admin-page ffc-page-scheduling-settings ffc-settings-wrap">
			<h1><?php esc_html_e( 'Scheduling Settings', 'ffcertificate' ); ?></h1>

			<?php
			// The one place this screen prints its notices, for every tab (#1771):
			// the save handlers and `?message=` queue them under `ffc_audience`,
			// and a second call would print each one twice.
			\FreeFormCertificate\Core\AdminNotice::settings_errors( 'ffc_audience' );
			?>

			<div class="ffc-settings-tabs">
				<ul class="ffc-settings-tabs__nav" role="tablist" aria-orientation="vertical">
					<?php foreach ( $tabs as $tab_id => $tab ) : ?>
						<?php $is_active = ( $active_tab === $tab_id ); ?>
						<li class="ffc-settings-tabs__nav-item" role="presentation">
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . $this->menu_slug . '-settings&tab=' . $tab_id ) ); ?>"
								id="ffc-scheduling-tabnav-<?php echo esc_attr( $tab_id ); ?>"
								class="ffc-settings-tabs__tab<?php echo $is_active ? ' is-active' : ''; ?>"
								role="tab"
								aria-selected="<?php echo $is_active ? 'true' : 'false'; ?>"
								aria-controls="ffc-scheduling-tabpanel-<?php echo esc_attr( $tab_id ); ?>"
								tabindex="<?php echo $is_active ? '0' : '-1'; ?>">
								<span class="ffc-settings-tabs__icon <?php echo esc_attr( \FreeFormCertificate\Core\Icons::tab_class( (string) $tab['icon'] ) ); ?>" aria-hidden="true"></span>
								<span class="ffc-settings-tabs__label"><?php echo esc_html( $tab['label'] ); ?></span>
							</a>
						</li>
					<?php endforeach; ?>
				</ul>

				<div id="ffc-scheduling-tabpanel-<?php echo esc_attr( $active_tab ); ?>" class="ffc-settings-tabs__panel" role="tabpanel" aria-labelledby="ffc-scheduling-tabnav-<?php echo esc_attr( $active_tab ); ?>" tabindex="0">
					<?php
					switch ( $active_tab ) {
						case 'self-scheduling':
							$this->render_self_scheduling_tab();
							break;
						case 'audience':
							$this->render_audience_tab();
							break;
						case 'import':
							$this->import->render_content();
							break;
						case 'general':
							$this->render_general_tab();
							break;
						default:
							/**
							 * Render a filter-contributed extension tab's panel
							 * (e.g. the appointment-receipt tab, #945). The tab id
							 * was validated against $tabs above.
							 *
							 * @since 6.20.0
							 */
							do_action( "ffc_scheduling_settings_render_tab_{$active_tab}" );
							break;
					}
					?>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render General settings tab
	 *
	 * @return void
	 */
	private function render_general_tab(): void {
		$holidays = get_option( 'ffc_global_holidays', array() );
		// Sort by date ascending.
		usort(
			$holidays,
			function ( $a, $b ) {
				return strcmp( $a['date'], $b['date'] );
			}
		);

		$calendars_count       = wp_count_posts( 'ffc_self_scheduling' );
		$published             = isset( $calendars_count->publish ) ? $calendars_count->publish : 0;
		$audience_active_count = absint( AudienceScheduleRepository::count( array( 'status' => 'active' ) ) );

		include FFC_PLUGIN_DIR . 'templates/admin/audience/general-tab.php';
	}

	/**
	 * Render Self-Scheduling settings tab
	 *
	 * @return void
	 */
	private function render_self_scheduling_tab(): void {
		// Get current settings.
		$display_mode = get_option( 'ffc_ss_private_display_mode', 'show_message' );
		/* translators: %login_url% is replaced with the WordPress login page URL */
		$visibility_message = get_option( 'ffc_ss_visibility_message', __( 'To view this calendar you need to be logged in. <a href="%login_url%">Log in</a> to continue.', 'ffcertificate' ) );
		/* translators: %login_url% is replaced with the WordPress login page URL */
		$scheduling_message = get_option( 'ffc_ss_scheduling_message', __( 'To book on this calendar you need to be logged in. <a href="%login_url%">Log in</a> to continue.', 'ffcertificate' ) );
		/* translators: %hours% is replaced with today's working hours range */
		$bh_viewing_message = get_option( 'ffc_ss_business_hours_viewing_message', __( 'This calendar is available for viewing only during business hours (%hours%).', 'ffcertificate' ) );
		/* translators: %hours% is replaced with today's working hours range */
		$bh_booking_message = get_option( 'ffc_ss_business_hours_booking_message', __( 'Booking is available only during business hours (%hours%).', 'ffcertificate' ) );

		include FFC_PLUGIN_DIR . 'templates/admin/audience/self-scheduling-tab.php';
	}

	/**
	 * Render Audience settings tab
	 *
	 * @return void
	 */
	private function render_audience_tab(): void {
		// Get current settings.
		$display_mode             = get_option( 'ffc_aud_private_display_mode', 'show_message' );
		$visibility_message       = get_option( 'ffc_aud_visibility_message', __( 'To view this calendar you need to be logged in. <a href="%login_url%">Log in</a> to continue.', 'ffcertificate' ) );
		$scheduling_message       = get_option( 'ffc_aud_scheduling_message', __( 'To book on this calendar you need to be logged in. <a href="%login_url%">Log in</a> to continue.', 'ffcertificate' ) );
		$multiple_audiences_color = get_option( 'ffc_aud_multiple_audiences_color', '' );
		$max_self_join_groups     = AudienceSelfJoinLimit::get();

		include FFC_PLUGIN_DIR . 'templates/admin/audience/audience-tab.php';
	}

	/**
	 * Handle visibility settings save actions
	 *
	 * @since 4.7.0
	 * @return void
	 */
	public function handle_visibility_settings(): void {
		if ( ! \FreeFormCertificate\Core\Capabilities::current_user_can_admin_or( 'ffc_manage_audiences' ) ) {
			return;
		}

		// Save Self-Scheduling visibility settings.
		if ( isset( $_POST['ffc_action'] ) && 'save_ss_visibility_settings' === $_POST['ffc_action'] ) {
			if ( ! isset( $_POST['ffc_ss_visibility_nonce'] ) ||
				! wp_verify_nonce( RequestInput::get_post_string( 'ffc_ss_visibility_nonce' ), 'ffc_ss_visibility_settings' ) ) {
				return;
			}

			$display_mode = isset( $_POST['ffc_ss_private_display_mode'] )
				? sanitize_text_field( wp_unslash( $_POST['ffc_ss_private_display_mode'] ) ) : 'show_message';
			if ( ! in_array( $display_mode, array( 'show_message', 'show_title_message', 'hide' ), true ) ) {
				$display_mode = 'show_message';
			}

			update_option( 'ffc_ss_private_display_mode', $display_mode );
			update_option( 'ffc_ss_visibility_message', self::rich_text_post( 'ffc_ss_visibility_message' ) );
			update_option( 'ffc_ss_scheduling_message', self::rich_text_post( 'ffc_ss_scheduling_message' ) );

			add_settings_error( 'ffc_audience', 'ffc_message', __( 'Personal Calendars visibility settings saved.', 'ffcertificate' ), 'success' );
		}

		// Save Self-Scheduling business hours restriction messages.
		if ( isset( $_POST['ffc_action'] ) && 'save_ss_business_hours_settings' === $_POST['ffc_action'] ) {
			if ( ! isset( $_POST['ffc_ss_business_hours_nonce'] ) ||
				! wp_verify_nonce( RequestInput::get_post_string( 'ffc_ss_business_hours_nonce' ), 'ffc_ss_business_hours_settings' ) ) {
				return;
			}

			update_option( 'ffc_ss_business_hours_viewing_message', self::rich_text_post( 'ffc_ss_business_hours_viewing_message' ) );
			update_option( 'ffc_ss_business_hours_booking_message', self::rich_text_post( 'ffc_ss_business_hours_booking_message' ) );

			add_settings_error( 'ffc_audience', 'ffc_message', __( 'Business hours restriction messages saved.', 'ffcertificate' ), 'success' );
		}

		// Save Audience visibility settings.
		if ( isset( $_POST['ffc_action'] ) && 'save_aud_visibility_settings' === $_POST['ffc_action'] ) {
			if ( ! isset( $_POST['ffc_aud_visibility_nonce'] ) ||
				! wp_verify_nonce( RequestInput::get_post_string( 'ffc_aud_visibility_nonce' ), 'ffc_aud_visibility_settings' ) ) {
				return;
			}

			$display_mode = isset( $_POST['ffc_aud_private_display_mode'] )
				? sanitize_text_field( wp_unslash( $_POST['ffc_aud_private_display_mode'] ) ) : 'show_message';
			if ( ! in_array( $display_mode, array( 'show_message', 'show_title_message', 'hide' ), true ) ) {
				$display_mode = 'show_message';
			}

			update_option( 'ffc_aud_private_display_mode', $display_mode );
			update_option( 'ffc_aud_visibility_message', self::rich_text_post( 'ffc_aud_visibility_message' ) );
			update_option( 'ffc_aud_scheduling_message', self::rich_text_post( 'ffc_aud_scheduling_message' ) );

			$ma_color = ColorValidator::normalize(
				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Normalised by ColorValidator::normalize(), which returns the default for anything that is not a hex colour.
				isset( $_POST['ffc_aud_multiple_audiences_color'] ) ? wp_unslash( $_POST['ffc_aud_multiple_audiences_color'] ) : '',
				''
			);
			update_option( 'ffc_aud_multiple_audiences_color', $ma_color );

			// A cleared or non-numeric field is "not supplied", never a zero
			// limit: keep what is stored (#1916).
			$max_groups = RequestInput::get_post_string( AudienceSelfJoinLimit::OPTION, '' );
			if ( '' !== $max_groups && is_numeric( $max_groups ) ) {
				update_option( AudienceSelfJoinLimit::OPTION, AudienceSelfJoinLimit::clamp( $max_groups ) );
			}

			add_settings_error( 'ffc_audience', 'ffc_message', __( 'Audience visibility settings saved.', 'ffcertificate' ), 'success' );
		}
	}

	/**
	 * Add a global holiday (#2243).
	 *
	 * A date already in the list is refused with a message; the screen used
	 * to report it added. A date that holds active audience bookings on any
	 * calendar is not saved until the request confirms it: the General tab
	 * lists those bookings, and the confirmation saves the date and cancels
	 * each of them, telling their participants.
	 *
	 * @param string $date        Date (Y-m-d).
	 * @param string $description Description, may be empty.
	 * @param string $kind        Closed-date kind.
	 * @return string|null The message to redirect with, or null while the bookings wait for confirmation.
	 */
	private function add_global_holiday( string $date, string $description, string $kind ): ?string {
		if ( ! \FreeFormCertificate\Core\DateFormatter::is_valid_date( $date ) ) {
			return 'holiday_not_updated';
		}

		$holidays = get_option( 'ffc_global_holidays', array() );
		$holidays = is_array( $holidays ) ? $holidays : array();
		foreach ( $holidays as $holiday ) {
			if ( is_array( $holiday ) && ( $holiday['date'] ?? '' ) === $date ) {
				return 'holiday_exists';
			}
		}

		$bookings = AudienceClosedDay::bookings_on( $date );
		if ( ! empty( $bookings ) && ! AudienceClosedDay::confirmed() ) {
			AudienceClosedDay::hold(
				$date,
				$kind,
				$description,
				$bookings,
				array(
					'ffc_action'                 => 'add_global_holiday',
					'ffc_global_holiday_nonce'   => wp_create_nonce( 'ffc_global_holiday_action' ),
					'global_holiday_date'        => $date,
					'global_holiday_description' => $description,
					'global_holiday_kind'        => $kind,
				)
			);
			return null;
		}

		$holidays[] = array(
			'date'        => $date,
			'description' => $description,
			'kind'        => $kind,
		);
		update_option( 'ffc_global_holidays', $holidays );

		$cancelled = AudienceClosedDay::cancel_bookings( $bookings, $date, $description );

		return 0 === $cancelled ? 'holiday_added' : 'holiday_added_cancelled&cancelled=' . $cancelled;
	}

	/**
	 * Handle global holiday add/delete actions
	 *
	 * @return void
	 */
	public function handle_global_holiday_actions(): void {
		// Feedback for the redirects below. Scoped to this screen: the
		// calendars screen uses `holiday_deleted` for its own holidays (#1771).
		if ( RequestInput::get_get_string( 'page' ) === $this->menu_slug . '-settings' && isset( $_GET['message'] ) ) {
			$messages = array(
				'holiday_added'    => __( 'Holiday added successfully.', 'ffcertificate' ),
				'holiday_deleted'  => __( 'Holiday deleted successfully.', 'ffcertificate' ),
				'holiday_updated'  => __( 'Holiday updated successfully.', 'ffcertificate' ),
				'colors_saved'     => __( 'Calendar colors and labels saved.', 'ffcertificate' ),
				'appearance_reset' => __( 'Calendar colors and labels restored to their defaults.', 'ffcertificate' ),
			);
			$message  = RequestInput::get_get_string( 'message' );
			if ( isset( $messages[ $message ] ) ) {
				add_settings_error( 'ffc_audience', 'ffc_message', $messages[ $message ], 'success' );
			} elseif ( 'holiday_not_updated' === $message ) {
				add_settings_error( 'ffc_audience', 'ffc_message', __( 'The holiday was not changed: the date is invalid or another holiday already uses it.', 'ffcertificate' ), 'error' );
			} elseif ( 'holiday_exists' === $message ) {
				add_settings_error( 'ffc_audience', 'ffc_message', __( 'Not added: that date is already a global holiday.', 'ffcertificate' ), 'error' );
			} elseif ( 'holiday_added_cancelled' === $message ) {
				$cancelled = RequestInput::get_get_int( 'cancelled' );
				add_settings_error(
					'ffc_audience',
					'ffc_message',
					/* translators: %d: number of bookings cancelled */
					sprintf( _n( 'Holiday added. %d booking was cancelled and its participants were e-mailed.', 'Holiday added. %d bookings were cancelled and their participants were e-mailed.', $cancelled, 'ffcertificate' ), $cancelled ),
					'success'
				);
			}
		}

		// Calendar colours and labels (#2202, #2215), colours in the light theme only.
		if ( isset( $_POST['ffc_action'] ) && 'save_calendar_appearance' === $_POST['ffc_action'] ) {
			if ( ! isset( $_POST['ffc_calendar_appearance_nonce'] ) ||
				! wp_verify_nonce( RequestInput::get_post_string( 'ffc_calendar_appearance_nonce' ), 'ffc_calendar_appearance' ) ) {
				return;
			}

			if ( ! \FreeFormCertificate\Core\Capabilities::current_user_can_admin_or( 'ffc_manage_audiences' ) ) {
				return;
			}

			if ( RequestInput::has_post( 'calendar_appearance_reset' ) ) {
				\FreeFormCertificate\Scheduling\CalendarAppearance::reset();
				$message = 'appearance_reset';
			} else {
				\FreeFormCertificate\Scheduling\CalendarAppearance::save(
					RequestInput::get_post_array( 'calendar_color' ),
					RequestInput::get_post_array( 'calendar_legend' ),
					RequestInput::get_post_array( 'calendar_cell' ),
					RequestInput::has_post( 'calendar_show_closed_label' )
				);
				$message = 'colors_saved';
			}

			wp_safe_redirect( admin_url( 'admin.php?page=' . $this->menu_slug . '-settings&tab=general&message=' . $message ) );
			exit;
		}

		// Add global holiday (POST).
		if ( isset( $_POST['ffc_action'] ) && 'add_global_holiday' === $_POST['ffc_action'] ) {
			if ( ! isset( $_POST['ffc_global_holiday_nonce'] ) ||
				! wp_verify_nonce( RequestInput::get_post_string( 'ffc_global_holiday_nonce' ), 'ffc_global_holiday_action' ) ) {
				return;
			}

			if ( ! \FreeFormCertificate\Core\Capabilities::current_user_can_admin_or( 'ffc_manage_audiences' ) ) {
				return;
			}

			$date        = RequestInput::get_post_string( 'global_holiday_date' );
			$description = RequestInput::get_post_string( 'global_holiday_description' );
			$kind        = \FreeFormCertificate\Scheduling\ClosedDateKind::normalize( RequestInput::get_post_string( 'global_holiday_kind' ) );

			$message = $this->add_global_holiday( $date, $description, $kind );
			if ( null === $message ) {
				// Bookings on that date wait for confirmation: the tab lists them.
				return;
			}

			wp_safe_redirect( admin_url( 'admin.php?page=' . $this->menu_slug . '-settings&tab=general&message=' . $message ) );
			exit;
		}

		// Edit global holiday (POST, #2212). Named by its original date, which
		// is unique; the nonce is bound to it like the delete link's.
		if ( isset( $_POST['ffc_action'] ) && 'edit_global_holiday' === $_POST['ffc_action'] ) {
			$original = RequestInput::get_post_string( 'holiday_original_date' );
			if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $original ) ||
				! wp_verify_nonce( RequestInput::get_post_string( 'ffc_holiday_edit_nonce' ), 'edit_global_holiday_' . $original ) ) {
				return;
			}

			if ( ! \FreeFormCertificate\Core\Capabilities::current_user_can_admin_or( 'ffc_manage_audiences' ) ) {
				return;
			}

			$updated = self::update_global_holiday(
				$original,
				RequestInput::get_post_string( 'holiday_edit_date' ),
				RequestInput::get_post_string( 'holiday_edit_description' ),
				RequestInput::get_post_string( 'holiday_edit_kind' )
			);

			wp_safe_redirect( admin_url( 'admin.php?page=' . $this->menu_slug . '-settings&tab=general&message=' . ( $updated ? 'holiday_updated' : 'holiday_not_updated' ) ) );
			exit;
		}

		// Delete global holiday (GET).
		if ( isset( $_GET['ffc_action'] ) && 'delete_global_holiday' === $_GET['ffc_action'] ) {
			// The holiday is named by its date, which is unique: the screen
			// lists the holidays sorted and the option keeps them in the
			// order they were added, so a position named the wrong day (#2010).
			$date = RequestInput::get_get_string( 'holiday_date' );

			if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ||
				! isset( $_GET['ffc_global_holiday_nonce'] ) ||
				! wp_verify_nonce( RequestInput::get_get_string( 'ffc_global_holiday_nonce' ), 'delete_global_holiday_' . $date ) ) {
				return;
			}

			if ( ! \FreeFormCertificate\Core\Capabilities::current_user_can_admin_or( 'ffc_manage_audiences' ) ) {
				return;
			}

			$holidays = get_option( 'ffc_global_holidays', array() );
			$kept     = array_values(
				array_filter(
					is_array( $holidays ) ? $holidays : array(),
					static fn( $h ): bool => ! is_array( $h ) || ( $h['date'] ?? null ) !== $date
				)
			);
			if ( count( $kept ) !== count( (array) $holidays ) ) {
				update_option( 'ffc_global_holidays', $kept );
			}

			wp_safe_redirect( admin_url( 'admin.php?page=' . $this->menu_slug . '-settings&tab=general&message=holiday_deleted' ) );
			exit;
		}
	}

	/**
	 * Replace the global holiday stored under `$original` (#2212).
	 *
	 * Refuses an invalid date, a holiday that is not stored, and a new date
	 * another entry already holds: the date is what names an entry.
	 *
	 * @param string $original    The stored date (Y-m-d) of the entry to change.
	 * @param string $date        The new date (Y-m-d).
	 * @param string $description The new description.
	 * @param string $kind        A `ClosedDateKind` value; unknown reads as a holiday.
	 * @return bool True when the entry was found and written.
	 */
	private static function update_global_holiday( string $original, string $date, string $description, string $kind ): bool {
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return false;
		}

		$holidays = get_option( 'ffc_global_holidays', array() );
		$holidays = is_array( $holidays ) ? $holidays : array();
		$index    = null;
		foreach ( $holidays as $i => $h ) {
			$stored = is_array( $h ) ? ( $h['date'] ?? null ) : null;
			if ( $original === $stored ) {
				$index = $i;
			} elseif ( $date === $stored ) {
				return false;
			}
		}
		if ( null === $index ) {
			return false;
		}

		$holidays[ $index ] = array(
			'date'        => $date,
			'description' => $description,
			'kind'        => \FreeFormCertificate\Scheduling\ClosedDateKind::normalize( $kind ),
		);
		update_option( 'ffc_global_holidays', array_values( $holidays ) );

		return true;
	}

	/**
	 * Read a rich-text `$_POST` field as a `wp_kses_post()`-filtered string.
	 *
	 * These six option keys are read as strings by every consumer, so a
	 * non-scalar must never reach `update_option()`. `RequestInput` has no
	 * accessor for this shape: `get_post_string()` applies
	 * `sanitize_text_field()`, which strips the markup these messages are
	 * allowed to carry. It stays private here rather than moving to
	 * `RequestInput` because this class is its only consumer in the whole
	 * plugin — a shared helper with one caller is indirection that narrows
	 * nothing (the #1079 precedent). Extract it when a second one appears.
	 *
	 * Caller verifies the nonce before calling.
	 *
	 * @since 6.23.0
	 * @param string $key `$_POST` key.
	 * @return string Filtered HTML; empty string when absent or not a string.
	 */
	private static function rich_text_post( string $key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by the calling handler.
		if ( ! isset( $_POST[ $key ] ) ) {
			return '';
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified by the calling handler. This IS the sanitiser: type-checked below, then wp_kses_post().
		$raw = wp_unslash( $_POST[ $key ] );
		if ( ! is_string( $raw ) ) {
			return '';
		}
		return wp_kses_post( $raw );
	}
}
