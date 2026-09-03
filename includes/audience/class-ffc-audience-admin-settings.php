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
				'icon'  => 'admin-generic',
			),
			'self-scheduling' => array(
				'label' => __( 'Self-Scheduling', 'ffcertificate' ),
				'icon'  => 'calendar-alt',
			),
			'audience'        => array(
				'label' => __( 'Audience', 'ffcertificate' ),
				'icon'  => 'groups',
			),
			'import'          => array(
				'label' => __( 'Import & Export', 'ffcertificate' ),
				'icon'  => 'database-import',
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
		<div class="wrap ffc-settings-wrap">
			<h1><?php esc_html_e( 'Scheduling Settings', 'ffcertificate' ); ?></h1>

			<div class="ffc-settings-tabs" data-ffc-settings-tabs>
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
								<span class="ffc-settings-tabs__icon dashicons dashicons-<?php echo esc_attr( $tab['icon'] ); ?>" aria-hidden="true"></span>
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
			update_option( 'ffc_ss_visibility_message', wp_kses_post( wp_unslash( $_POST['ffc_ss_visibility_message'] ?? '' ) ) );
			update_option( 'ffc_ss_scheduling_message', wp_kses_post( wp_unslash( $_POST['ffc_ss_scheduling_message'] ?? '' ) ) );

			add_settings_error( 'ffc_audience', 'ffc_message', __( 'Self-scheduling visibility settings saved.', 'ffcertificate' ), 'success' );
		}

		// Save Self-Scheduling business hours restriction messages.
		if ( isset( $_POST['ffc_action'] ) && 'save_ss_business_hours_settings' === $_POST['ffc_action'] ) {
			if ( ! isset( $_POST['ffc_ss_business_hours_nonce'] ) ||
				! wp_verify_nonce( RequestInput::get_post_string( 'ffc_ss_business_hours_nonce' ), 'ffc_ss_business_hours_settings' ) ) {
				return;
			}

			update_option( 'ffc_ss_business_hours_viewing_message', wp_kses_post( wp_unslash( $_POST['ffc_ss_business_hours_viewing_message'] ?? '' ) ) );
			update_option( 'ffc_ss_business_hours_booking_message', wp_kses_post( wp_unslash( $_POST['ffc_ss_business_hours_booking_message'] ?? '' ) ) );

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
			update_option( 'ffc_aud_visibility_message', wp_kses_post( wp_unslash( $_POST['ffc_aud_visibility_message'] ?? '' ) ) );
			update_option( 'ffc_aud_scheduling_message', wp_kses_post( wp_unslash( $_POST['ffc_aud_scheduling_message'] ?? '' ) ) );

            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above.
			$ma_color = ColorValidator::normalize(
				isset( $_POST['ffc_aud_multiple_audiences_color'] ) ? wp_unslash( $_POST['ffc_aud_multiple_audiences_color'] ) : '',
				''
			);
			update_option( 'ffc_aud_multiple_audiences_color', $ma_color );

			add_settings_error( 'ffc_audience', 'ffc_message', __( 'Audience visibility settings saved.', 'ffcertificate' ), 'success' );
		}
	}

	/**
	 * Handle global holiday add/delete actions
	 *
	 * @return void
	 */
	public function handle_global_holiday_actions(): void {
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

			if ( ! empty( $date ) ) {
				$holidays = get_option( 'ffc_global_holidays', array() );

				// Avoid duplicates.
				$exists = false;
				foreach ( $holidays as $h ) {
					if ( $h['date'] === $date ) {
						$exists = true;
						break;
					}
				}

				if ( ! $exists ) {
					$holidays[] = array(
						'date'        => $date,
						'description' => $description,
					);
					update_option( 'ffc_global_holidays', $holidays );
				}
			}

			wp_safe_redirect( admin_url( 'admin.php?page=' . $this->menu_slug . '-settings&tab=general&message=holiday_added' ) );
			exit;
		}

		// Delete global holiday (GET).
		if ( isset( $_GET['ffc_action'] ) && 'delete_global_holiday' === $_GET['ffc_action'] ) {
			$index = isset( $_GET['holiday_index'] ) ? absint( $_GET['holiday_index'] ) : -1;

			if ( ! isset( $_GET['ffc_global_holiday_nonce'] ) ||
				! wp_verify_nonce( RequestInput::get_get_string( 'ffc_global_holiday_nonce' ), 'delete_global_holiday_' . $index ) ) {
				return;
			}

			if ( ! \FreeFormCertificate\Core\Capabilities::current_user_can_admin_or( 'ffc_manage_audiences' ) ) {
				return;
			}

			$holidays = get_option( 'ffc_global_holidays', array() );
			if ( isset( $holidays[ $index ] ) ) {
				array_splice( $holidays, $index, 1 );
				update_option( 'ffc_global_holidays', $holidays );
			}

			wp_safe_redirect( admin_url( 'admin.php?page=' . $this->menu_slug . '-settings&tab=general&message=holiday_deleted' ) );
			exit;
		}
	}
}
