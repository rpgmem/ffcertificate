<?php
/**
 * Self-Scheduling Editor
 *
 * Handles the advanced UI for Calendar Builder, including metaboxes and configuration.
 *
 * v4.12.16: Extracted SelfSchedulingCleanupHandler (AJAX + cleanup metabox)
 *           and SelfSchedulingSaveHandler (save_post handler) for SRP compliance.
 *
 * @package FreeFormCertificate\SelfScheduling
 * @since 4.1.0
 * @version 4.12.16
 */

declare(strict_types=1);

namespace FreeFormCertificate\SelfScheduling;

use FreeFormCertificate\Admin\AdminUI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Self Scheduling Editor.
 */
class SelfSchedulingEditor {

	/**
	 * Cleanup handler.
	 *
	 * @var SelfSchedulingCleanupHandler
	 */
	private $cleanup_handler;

	/**
	 * Constructor
	 */
	public function __construct() {
		add_action( 'add_meta_boxes', array( $this, 'add_custom_metaboxes' ), 20 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );

		// Delegated handlers.
		new SelfSchedulingSaveHandler();
		$this->cleanup_handler = new SelfSchedulingCleanupHandler();
	}

	/**
	 * Enqueue scripts and styles for calendar editor
	 *
	 * @param string $hook Hook name.
	 * @return void
	 */
	public function enqueue_scripts( string $hook ): void {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || 'ffc_self_scheduling' !== $screen->post_type ) {
			return;
		}

		$s = \FreeFormCertificate\Core\AssetHelper::asset_suffix();

		// `ffc-core` supplies `FFC.setRequiredWithin()`, which the editor
		// uses to carry `required` with the four blocks it shows and hides
		// (#1117). Without it the editor still works — the sync is guarded
		// — but a hidden required field would block the save.
		\FreeFormCertificate\Core\AssetRegistry::enqueue_scripts( 'ffc-core' );
		wp_enqueue_script(
			'ffc-calendar-editor',
			FFC_PLUGIN_URL . "assets/js/ffc-calendar-editor{$s}.js",
			array( 'jquery', 'jquery-ui-sortable', 'ffc-core' ),
			FFC_VERSION,
			true
		);

		// "Restore Default Text" for the Confirmation Email Body editor (#662).
		\FreeFormCertificate\Core\AssetRegistry::enqueue_scripts( 'ffc-email-restore-default' );
		wp_localize_script(
			'ffc-email-restore-default',
			'ffcEmailRestoreDefaults',
			array(
				'selfsched_confirmation_body' => array(
					'body'    => AppointmentEmailHandler::default_confirmation_body(),
					'confirm' => __( 'Replace the current message with the default template? Your changes will be lost.', 'ffcertificate' ),
				),
			)
		);

		wp_enqueue_style(
			'ffc-common',
			FFC_PLUGIN_URL . "assets/css/ffc-common{$s}.css",
			array(),
			FFC_VERSION
		);

		// The vertical tabs the settings are split into (#1761) and the
		// collapsible `.ffc-section` the Emails tab still holds (#1629), both
		// components of this sheet. The tab script is the shared one: it
		// switches panels, draws the state dots, and opens the tab holding a
		// field the browser rejects; the sections script does the same for a
		// closed section and keeps its chips live.
		wp_enqueue_style(
			'ffc-admin-components',
			FFC_PLUGIN_URL . "assets/css/ffc-admin-components{$s}.css",
			array( 'ffc-common' ),
			FFC_VERSION
		);
		AdminUI::enqueue_vertical_tabs();
		\FreeFormCertificate\Core\AssetRegistry::enqueue_scripts( 'ffc-admin-sections' );

		wp_enqueue_style(
			'ffc-calendar-editor',
			FFC_PLUGIN_URL . "assets/css/ffc-calendar-editor{$s}.css",
			array( 'ffc-common', 'ffc-admin-components' ),
			FFC_VERSION
		);

		wp_localize_script(
			'ffc-calendar-editor',
			'ffcSelfSchedulingEditor',
			array(
				'nonce'   => wp_create_nonce( 'ffc_self_scheduling_editor_nonce' ),
				'strings' => array(
					'confirmDelete'    => __( 'Are you sure you want to delete this?', 'ffcertificate' ),
					'addWorkingHour'   => __( 'Add Working Hours', 'ffcertificate' ),
					'lastWorkingHour'  => __( 'You must have at least one working hour configured.', 'ffcertificate' ),
					'remove'           => __( 'Remove', 'ffcertificate' ),
					'daysOfWeek'       => array(
						__( 'Sunday', 'ffcertificate' ),
						__( 'Monday', 'ffcertificate' ),
						__( 'Tuesday', 'ffcertificate' ),
						__( 'Wednesday', 'ffcertificate' ),
						__( 'Thursday', 'ffcertificate' ),
						__( 'Friday', 'ffcertificate' ),
						__( 'Saturday', 'ffcertificate' ),
					),
					'deleting'         => __( 'Deleting...', 'ffcertificate' ),
					'cancelling'       => __( 'Cancelling...', 'ffcertificate' ),
					'cleanup'          => self::cleanup_dialog_strings(),
					'errorDeleting'    => __( 'Error deleting appointments', 'ffcertificate' ),
					'errorServer'      => __( 'Error communicating with server', 'ffcertificate' ),
					'schedulingForced' => __( 'Forced to Private because Visibility is Private.', 'ffcertificate' ),
					'schedulingDesc'   => __( 'Public: anyone can book. Private: only logged-in users can book.', 'ffcertificate' ),
				),
			)
		);
	}

	/**
	 * Words of the cleanup confirmation dialog (#1872).
	 *
	 * One entry per action. `%1$d` is the count the action reaches and
	 * `%2$s` the calendar title; `type` is the typed-count prompt, present
	 * only on the two actions that ask for it.
	 *
	 * @return array<string, mixed>
	 */
	private static function cleanup_dialog_strings(): array {
		/* translators: %d: the number the person must type to confirm */
		$type = __( 'To confirm, type %d', 'ffcertificate' );

		return array(
			'cancel'    => __( 'Cancel', 'ffcertificate' ),
			'goBack'    => __( 'Go back', 'ffcertificate' ),
			'cancelled' => array(
				'title'   => __( 'Delete cancelled appointments', 'ffcertificate' ),
				/* translators: 1: number of appointments, 2: calendar title */
				'body'    => __( '%1$d cancelled appointment(s) of the calendar "%2$s" will be deleted for good.', 'ffcertificate' ),
				/* translators: %d: number of appointments */
				'confirm' => __( 'Delete %d appointment(s)', 'ffcertificate' ),
			),
			'old'       => array(
				'title'   => __( 'Delete past appointments', 'ffcertificate' ),
				/* translators: 1: number of appointments, 2: calendar title */
				'body'    => __( '%1$d past appointment(s) of the calendar "%2$s" will be deleted for good.', 'ffcertificate' ),
				/* translators: %d: number of appointments */
				'confirm' => __( 'Delete %d appointment(s)', 'ffcertificate' ),
			),
			'future'    => array(
				'title'         => __( 'Cancel future appointments', 'ffcertificate' ),
				/* translators: 1: number of appointments, 2: calendar title */
				'body'          => __( '%1$d appointment(s) booked from today on in the calendar "%2$s" will be cancelled, and each person booked will receive the calendar\'s cancellation e-mail. Nothing is deleted.', 'ffcertificate' ),
				/* translators: 1: number of appointments, 2: calendar title */
				'bodySilent'    => __( '%1$d appointment(s) booked from today on in the calendar "%2$s" will be cancelled. This calendar does not send cancellation e-mails, so nobody will be notified. Nothing is deleted.', 'ffcertificate' ),
				/* translators: %d: number of appointments */
				'confirm'       => __( 'Cancel %d and notify', 'ffcertificate' ),
				/* translators: %d: number of appointments */
				'confirmSilent' => __( 'Cancel %d appointment(s)', 'ffcertificate' ),
				'type'          => $type,
			),
			'all'       => array(
				'title'   => __( 'Delete all appointments', 'ffcertificate' ),
				/* translators: 1: number of appointments, 2: calendar title */
				'body'    => __( 'All %1$d appointment(s) of the calendar "%2$s" will be deleted for good: past, future and cancelled.', 'ffcertificate' ),
				/* translators: %d: number of appointments */
				'confirm' => __( 'Delete %d appointment(s)', 'ffcertificate' ),
				'type'    => $type,
			),
		);
	}

	/**
	 * Add custom metaboxes for calendar editor
	 *
	 * The normal column is one box with vertical tabs (#1761), in the form
	 * editor's pattern; the occupancy report is its last tab rather than a
	 * box of its own. The side cards stay as they were.
	 *
	 * @return void
	 */
	public function add_custom_metaboxes(): void {
		// Main configuration: every setting, one tab per subject.
		add_meta_box(
			'ffc_self_scheduling_box_config',
			__( 'Calendar Configuration', 'ffcertificate' ),
			array( $this, 'render_box_config' ),
			'ffc_self_scheduling',
			'normal',
			'high'
		);

		// Shortcode (sidebar).
		add_meta_box(
			'ffc_self_scheduling_shortcode',
			__( 'How to Use / Shortcode', 'ffcertificate' ),
			array( $this, 'render_shortcode_metabox' ),
			'ffc_self_scheduling',
			'side',
			'high'
		);

		// Cleanup appointments (sidebar) - Only show for existing calendars.
		$post_id = get_the_ID();
		if ( $post_id ) {
			add_meta_box(
				'ffc_self_scheduling_cleanup',
				__( 'Clean Up Appointments', 'ffcertificate' ),
				array( $this->cleanup_handler, 'render_cleanup_metabox' ),
				'ffc_self_scheduling',
				'side',
				'default'
			);
		}
	}

	/**
	 * Whether the calendar shows its occupancy report (#941 phase 3): a saved
	 * calendar whose stored mode is custom. The same condition that used to
	 * register the report as its own box, now deciding whether it is a tab.
	 *
	 * @param int $post_id Calendar post id.
	 * @return bool
	 */
	private function shows_occupancy( int $post_id ): bool {
		if ( $post_id <= 0 ) {
			return false;
		}
		$config = get_post_meta( $post_id, '_ffc_self_scheduling_config', true );
		return is_array( $config ) && 'custom' === ( $config['schedule_type'] ?? 'regular' );
	}

	/**
	 * The editor's tabs, in display order (#1761).
	 *
	 * The two tabs whose feature has an on/off switch draw a state dot that
	 * follows it, as their section chips did; the others are settings every
	 * calendar has.
	 *
	 * @param bool $occupancy Whether the occupancy tab exists.
	 * @return array<int, array{key: string, icon: string, label: string, title: string, masters: array<int, string>}>
	 */
	private static function tab_definitions( bool $occupancy ): array {
		$tabs = array(
			array(
				'key'     => 'calendar',
				'icon'    => 'ffc-icon-calendar',
				'label'   => __( 'Calendar', 'ffcertificate' ),
				'title'   => __( 'Calendar', 'ffcertificate' ),
				'masters' => array(),
			),
			array(
				'key'     => 'slots',
				'icon'    => 'ffc-icon-clock',
				'label'   => __( 'Slots & hours', 'ffcertificate' ),
				'title'   => __( 'Booking slots', 'ffcertificate' ),
				'masters' => array(),
			),
			array(
				'key'     => 'window',
				'icon'    => 'ffc-icon-history',
				'label'   => __( 'Booking window', 'ffcertificate' ),
				'title'   => __( 'Booking window', 'ffcertificate' ),
				'masters' => array(),
			),
			array(
				'key'     => 'cancellation',
				'icon'    => 'ffc-icon-cross',
				'label'   => __( 'Cancellation', 'ffcertificate' ),
				'title'   => __( 'Cancellation', 'ffcertificate' ),
				'masters' => array( 'allow_cancellation' ),
			),
			array(
				'key'     => 'approval',
				'icon'    => 'ffc-icon-user-check',
				'label'   => __( 'Approval & waitlist', 'ffcertificate' ),
				'title'   => __( 'Approval and waitlist', 'ffcertificate' ),
				'masters' => array(),
			),
			array(
				'key'     => 'access',
				'icon'    => 'ffc-icon-eye',
				'label'   => __( 'Who can see and book', 'ffcertificate' ),
				'title'   => __( 'Who can see and book', 'ffcertificate' ),
				'masters' => array(),
			),
			array(
				'key'     => 'bypass',
				'icon'    => 'ffc-icon-shield',
				'label'   => __( 'Administrator bypass', 'ffcertificate' ),
				'title'   => __( 'Administrator bypass', 'ffcertificate' ),
				'masters' => array( 'admin_bypass' ),
			),
			array(
				'key'     => 'emails',
				'icon'    => 'ffc-icon-email',
				'label'   => __( 'Emails', 'ffcertificate' ),
				'title'   => __( 'Email Notifications', 'ffcertificate' ),
				'masters' => array(),
			),
		);
		if ( $occupancy ) {
			$tabs[] = array(
				'key'     => 'occupancy',
				'icon'    => 'ffc-icon-chart',
				'label'   => __( 'Occupancy', 'ffcertificate' ),
				'title'   => __( 'Occupancy Report', 'ffcertificate' ),
				'masters' => array(),
			);
		}
		return $tabs;
	}

	/**
	 * Print a tab's one-line summary, the hint its collapsible section carried.
	 *
	 * @param string $hint Translated hint.
	 * @return void
	 */
	private static function render_tab_hint( string $hint ): void {
		echo '<p class="description">' . esc_html( $hint ) . '</p>';
	}

	/**
	 * Render the calendar configuration metabox: every setting, in tabs.
	 *
	 * The nonce the save handler checks is printed here, once, ahead of the
	 * tabs; every field below sits in a panel that stays in the DOM while
	 * hidden, so the save reads them all whichever tab is open.
	 *
	 * @param object $post Post object.
	 * @phpstan-param \WP_Post $post
	 * @return void
	 */
	public function render_box_config( object $post ): void {
		$config      = $this->config_with_defaults( (int) $post->ID );
		$mode_locked = $this->calendar_has_appointments( (int) $post->ID );

		wp_nonce_field( 'ffc_self_scheduling_config_nonce', 'ffc_self_scheduling_config_nonce' );

		AdminUI::render_vertical_tabs(
			self::tab_definitions( $this->shows_occupancy( (int) $post->ID ) ),
			function ( string $key ) use ( $post, $config, $mode_locked ): void {
				switch ( $key ) {
					case 'calendar':
						$this->render_tab_calendar( $config );
						break;
					case 'slots':
						$this->render_tab_slots( $config, $mode_locked );
						$this->render_working_hours( $post, $mode_locked );
						break;
					case 'window':
						$this->render_tab_window( $config );
						break;
					case 'cancellation':
						$this->render_tab_cancellation( $config );
						break;
					case 'approval':
						$this->render_tab_approval( $config );
						break;
					case 'access':
						$this->render_tab_access( $config );
						break;
					case 'bypass':
						$this->render_tab_bypass( $config );
						break;
					case 'emails':
						$this->render_tab_emails( $post );
						break;
					case 'occupancy':
						$this->render_box_occupancy( $post );
						break;
				}
			}
		);
	}

	/**
	 * The stored calendar configuration, every key filled with its default.
	 *
	 * @param int $post_id Calendar post id.
	 * @return array<string, mixed>
	 */
	private function config_with_defaults( int $post_id ): array {
		$config = get_post_meta( $post_id, '_ffc_self_scheduling_config', true );
		if ( ! is_array( $config ) ) {
			$config = array();
		}

		// Backward compatibility: calendars saved before the per-calendar admin
		// bypass toggle existed implicitly had the bypass on (hardcoded in
		// CalendarRepository::userHasSchedulingBypass). Preserve that by
		// treating a missing key as "enabled".
		$admin_bypass_default = array_key_exists( 'admin_bypass', $config ) ? (int) $config['admin_bypass'] : 1;

		$defaults = array(
			'description'                       => '',
			'schedule_type'                     => 'regular',
			'slot_duration'                     => 30,
			'slot_interval'                     => 0,
			'slots_per_day'                     => 0,
			'max_appointments_per_slot'         => 1,
			'status'                            => 'active',
			'advance_booking_min'               => 0,
			'advance_booking_max'               => 30,
			'allow_cancellation'                => 1,
			'cancellation_min_hours'            => 24,
			'minimum_interval_between_bookings' => 24,
			'requires_approval'                 => 0,
			'waitlist_enabled'                  => 0,
			'waitlist_capacity'                 => 0,
			'max_blocks_per_user'               => 0,
			'visibility'                        => 'public',
			'scheduling_visibility'             => 'public',
			'restrict_viewing_to_hours'         => 0,
			'restrict_booking_to_hours'         => 0,
			'admin_bypass'                      => $admin_bypass_default,
		);

		return array_merge( $defaults, $config );
	}

	/**
	 * Render the custom-mode occupancy report (#941 phase 3).
	 *
	 * Read-only table pairing each configured block with its live booked/capacity
	 * counts, occupancy percentage and waitlist length.
	 *
	 * @param object $post Post object.
	 * @phpstan-param \WP_Post $post
	 * @return void
	 */
	public function render_box_occupancy( object $post ): void {
		$blocks = CustomSlots::decode( get_post_meta( $post->ID, '_ffc_self_scheduling_custom_slots', true ) );
		if ( empty( $blocks ) ) {
			echo '<p>' . esc_html__( 'Add custom blocks to see the occupancy report.', 'ffcertificate' ) . '</p>';
			return;
		}

		// Map post → calendar row, then pull per-slot counts keyed by date|H:i.
		$counts        = array();
		$calendar_repo = new \FreeFormCertificate\Repositories\CalendarRepository();
		$calendar      = $calendar_repo->findByPostId( (int) $post->ID );
		if ( is_array( $calendar ) && ! empty( $calendar['id'] ) ) {
			$appt_repo = new \FreeFormCertificate\Repositories\AppointmentRepository();
			foreach ( $appt_repo->getOccupancyCounts( (int) $calendar['id'] ) as $row ) {
				$key            = $row['date'] . '|' . CustomSlots::hm( (string) $row['start'] );
				$counts[ $key ] = array(
					'booked'     => (int) $row['booked'],
					'waitlisted' => (int) $row['waitlisted'],
				);
			}
		}
		?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Date', 'ffcertificate' ); ?></th>
					<th><?php esc_html_e( 'Time', 'ffcertificate' ); ?></th>
					<th><?php esc_html_e( 'Label', 'ffcertificate' ); ?></th>
					<th><?php esc_html_e( 'Booked / Capacity', 'ffcertificate' ); ?></th>
					<th><?php esc_html_e( 'Occupancy', 'ffcertificate' ); ?></th>
					<th><?php esc_html_e( 'Waitlist', 'ffcertificate' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php
				foreach ( $blocks as $block ) {
					$start_hm = CustomSlots::hm( $block['start'] );
					$end_hm   = CustomSlots::hm( $block['end'] );
					$capacity = (int) $block['capacity'];
					$key      = $block['date'] . '|' . $start_hm;
					$booked   = $counts[ $key ]['booked'] ?? 0;
					$waiting  = $counts[ $key ]['waitlisted'] ?? 0;
					$pct      = $capacity > 0 ? (int) round( 100 * $booked / $capacity ) : 0;
					?>
					<tr>
						<td><?php echo esc_html( \FreeFormCertificate\Core\DateFormatter::format_wallclock_date( $block['date'] ) ); ?></td>
						<td><?php echo esc_html( $start_hm . '–' . $end_hm ); ?></td>
						<td><?php echo esc_html( '' !== $block['label'] ? $block['label'] : '—' ); ?></td>
						<td><?php echo esc_html( $booked . ' / ' . $capacity ); ?></td>
						<td><?php echo esc_html( $pct . '%' ); ?></td>
						<td><?php echo esc_html( (string) $waiting ); ?></td>
					</tr>
					<?php
				}
				?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Calendar tab: what the calendar is, and whether it takes bookings.
	 *
	 * @param array<string, mixed> $config Configuration with defaults.
	 * @return void
	 */
	private function render_tab_calendar( array $config ): void {
		self::render_tab_hint( __( 'What the calendar is, and whether it takes bookings.', 'ffcertificate' ) );
		?>
		<table class="form-table">
			<tr>
				<th><label for="calendar_description"><?php esc_html_e( 'Description', 'ffcertificate' ); ?></label></th>
				<td>
					<textarea id="calendar_description" name="ffc_self_scheduling_config[description]" rows="3" class="large-text"><?php echo esc_textarea( $config['description'] ); ?></textarea>
					<p class="description"><?php esc_html_e( 'Brief description of this calendar (optional)', 'ffcertificate' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="calendar_status"><?php esc_html_e( 'Status', 'ffcertificate' ); ?></label></th>
				<td>
					<select id="calendar_status" name="ffc_self_scheduling_config[status]">
						<option value="active" <?php selected( $config['status'], 'active' ); ?>><?php esc_html_e( 'Active', 'ffcertificate' ); ?></option>
						<option value="inactive" <?php selected( $config['status'], 'inactive' ); ?>><?php esc_html_e( 'Inactive', 'ffcertificate' ); ?></option>
						<option value="archived" <?php selected( $config['status'], 'archived' ); ?>><?php esc_html_e( 'Archived', 'ffcertificate' ); ?></option>
					</select>
					<p class="description"><?php esc_html_e( 'Calendar status (inactive = no new bookings allowed)', 'ffcertificate' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Slots & hours tab, first half: the scheduling mode and how each slot is
	 * sized. The mode is locked once the calendar has bookings (#941):
	 * switching would orphan existing appointments.
	 *
	 * @param array<string, mixed> $config      Configuration with defaults.
	 * @param bool                 $mode_locked Whether the calendar has bookings.
	 * @return void
	 */
	private function render_tab_slots( array $config, bool $mode_locked ): void {
		self::render_tab_hint( __( 'Weekly hours or exact blocks, and how each slot is sized.', 'ffcertificate' ) );
		?>
		<table class="form-table">
			<tr>
				<th><?php esc_html_e( 'Scheduling mode', 'ffcertificate' ); ?></th>
				<td>
					<label><input type="radio" name="ffc_self_scheduling_config[schedule_type]" value="regular" class="ffc-schedule-type-radio" <?php checked( $config['schedule_type'], 'regular' ); ?> <?php disabled( $mode_locked ); ?> /> <?php esc_html_e( 'Regular — weekly working hours', 'ffcertificate' ); ?></label><br />
					<label><input type="radio" name="ffc_self_scheduling_config[schedule_type]" value="custom" class="ffc-schedule-type-radio" <?php checked( $config['schedule_type'], 'custom' ); ?> <?php disabled( $mode_locked ); ?> /> <?php esc_html_e( 'Custom — specific date/time blocks, each with its own number of vacancies', 'ffcertificate' ); ?></label>
					<?php if ( $mode_locked ) : ?>
						<input type="hidden" name="ffc_self_scheduling_config[schedule_type]" value="<?php echo esc_attr( $config['schedule_type'] ); ?>" />
						<p class="description ffc-text-danger"><?php esc_html_e( 'This calendar already has bookings — the scheduling mode is locked.', 'ffcertificate' ); ?></p>
					<?php else : ?>
						<p class="description"><?php esc_html_e( 'Regular repeats weekly. Custom lets you list exact dates and times below, each with its own capacity.', 'ffcertificate' ); ?></p>
					<?php endif; ?>
				</td>
			</tr>
			<tr class="ffc-regular-only">
				<th><label for="slot_duration"><?php esc_html_e( 'Appointment Duration', 'ffcertificate' ); ?></label></th>
				<td>
					<input type="number" id="slot_duration" name="ffc_self_scheduling_config[slot_duration]" value="<?php echo esc_attr( $config['slot_duration'] ); ?>" min="5" max="480" step="5" required /> <?php esc_html_e( 'minutes', 'ffcertificate' ); ?>
					<p class="description"><?php esc_html_e( 'Duration of each appointment slot', 'ffcertificate' ); ?></p>
				</td>
			</tr>
			<tr class="ffc-regular-only">
				<th><label for="slot_interval"><?php esc_html_e( 'Break Between Appointments', 'ffcertificate' ); ?></label></th>
				<td>
					<input type="number" id="slot_interval" name="ffc_self_scheduling_config[slot_interval]" value="<?php echo esc_attr( $config['slot_interval'] ); ?>" min="0" max="120" step="5" required /> <?php esc_html_e( 'minutes', 'ffcertificate' ); ?>
					<p class="description"><?php esc_html_e( 'Buffer time between appointments (0 = no break)', 'ffcertificate' ); ?></p>
				</td>
			</tr>
			<tr class="ffc-regular-only">
				<th><label for="max_appointments_per_slot"><?php esc_html_e( 'Max Bookings Per Slot', 'ffcertificate' ); ?></label></th>
				<td>
					<input type="number" id="max_appointments_per_slot" name="ffc_self_scheduling_config[max_appointments_per_slot]" value="<?php echo esc_attr( $config['max_appointments_per_slot'] ); ?>" min="1" max="100" required />
					<p class="description"><?php esc_html_e( 'Maximum number of people per time slot (1 = exclusive)', 'ffcertificate' ); ?></p>
				</td>
			</tr>
			<tr class="ffc-regular-only">
				<th><label for="slots_per_day"><?php esc_html_e( 'Daily Booking Limit', 'ffcertificate' ); ?></label></th>
				<td>
					<input type="number" id="slots_per_day" name="ffc_self_scheduling_config[slots_per_day]" value="<?php echo esc_attr( $config['slots_per_day'] ); ?>" min="0" max="200" required />
					<p class="description"><?php esc_html_e( 'Maximum appointments per day (0 = unlimited)', 'ffcertificate' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Whether the calendar (by post id) already has at least one appointment.
	 *
	 * Used to lock the scheduling mode and block-structure edits (#941): once
	 * bookings exist, switching mode or removing/retiming a block would orphan
	 * them.
	 *
	 * @param int $post_id Calendar post id.
	 * @return bool
	 */
	private function calendar_has_appointments( int $post_id ): bool {
		if ( $post_id <= 0 ) {
			return false;
		}
		global $wpdb;
		$calendars    = $wpdb->prefix . 'ffc_self_scheduling_calendars';
		$appointments = $wpdb->prefix . 'ffc_self_scheduling_appointments';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Guard count joining the plugin's own calendar and appointment tables while the post is edited; a cached number would let a calendar be shrunk with live appointments in it.
		$count = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(a.id) FROM %i a INNER JOIN %i c ON c.id = a.calendar_id WHERE c.post_id = %d',
				$appointments,
				$calendars,
				$post_id
			)
		);
		return (int) $count > 0;
	}

	/**
	 * Slots & hours tab, second half: the weekly working hours (regular mode)
	 * or the custom blocks (custom mode). Once its own box, so it keeps that
	 * box's title as a heading.
	 *
	 * @param object $post        Post object.
	 * @param bool   $mode_locked Whether the calendar has bookings.
	 * @phpstan-param \WP_Post $post
	 * @return void
	 */
	private function render_working_hours( object $post, bool $mode_locked ): void {
		$working_hours = get_post_meta( $post->ID, '_ffc_self_scheduling_working_hours', true );
		if ( ! is_array( $working_hours ) ) {
			$working_hours = array(
				array(
					'day'   => 1,
					'start' => '09:00',
					'end'   => '17:00',
				), // Monday.
				array(
					'day'   => 2,
					'start' => '09:00',
					'end'   => '17:00',
				), // Tuesday.
				array(
					'day'   => 3,
					'start' => '09:00',
					'end'   => '17:00',
				), // Wednesday.
				array(
					'day'   => 4,
					'start' => '09:00',
					'end'   => '17:00',
				), // Thursday.
				array(
					'day'   => 5,
					'start' => '09:00',
					'end'   => '17:00',
				), // Friday.
			);
		}

		$days_of_week = array(
			0 => __( 'Sunday', 'ffcertificate' ),
			1 => __( 'Monday', 'ffcertificate' ),
			2 => __( 'Tuesday', 'ffcertificate' ),
			3 => __( 'Wednesday', 'ffcertificate' ),
			4 => __( 'Thursday', 'ffcertificate' ),
			5 => __( 'Friday', 'ffcertificate' ),
			6 => __( 'Saturday', 'ffcertificate' ),
		);

		// Custom blocks (#941).
		$custom_slots = get_post_meta( $post->ID, '_ffc_self_scheduling_custom_slots', true );
		if ( ! is_array( $custom_slots ) ) {
			$custom_slots = array();
		}

		?>
		<h3><?php esc_html_e( 'Working Hours & Availability', 'ffcertificate' ); ?></h3>
		<div id="ffc-working-hours-wrapper" class="ffc-regular-only">
			<p><?php esc_html_e( 'Define which days and times appointments can be booked:', 'ffcertificate' ); ?></p>

			<table class="widefat ffc-working-hours-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Day', 'ffcertificate' ); ?></th>
						<th><?php esc_html_e( 'Start Time', 'ffcertificate' ); ?></th>
						<th><?php esc_html_e( 'End Time', 'ffcertificate' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'ffcertificate' ); ?></th>
					</tr>
				</thead>
				<tbody id="ffc-working-hours-list">
					<?php foreach ( $working_hours as $index => $hours ) : ?>
						<tr>
							<td>
								<select name="ffc_self_scheduling_working_hours[<?php echo esc_attr( (string) $index ); ?>][day]" required>
									<?php foreach ( $days_of_week as $day_num => $day_name ) : ?>
										<option value="<?php echo esc_attr( (string) $day_num ); ?>" <?php selected( $hours['day'], $day_num ); ?>><?php echo esc_html( $day_name ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
							<td>
								<input type="time" name="ffc_self_scheduling_working_hours[<?php echo esc_attr( $index ); ?>][start]" value="<?php echo esc_attr( $hours['start'] ); ?>" required />
							</td>
							<td>
								<input type="time" name="ffc_self_scheduling_working_hours[<?php echo esc_attr( $index ); ?>][end]" value="<?php echo esc_attr( $hours['end'] ); ?>" required />
							</td>
							<td>
								<?php
								AdminUI::render_button(
									array(
										'label'    => __( 'Remove', 'ffcertificate' ),
										'category' => 'danger',
										'icon'     => 'delete',
										'class'    => 'ffc-remove-hour',
									)
								);
								?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<p>
				<?php
				AdminUI::render_button(
					array(
						'label'    => __( 'Add Working Hours', 'ffcertificate' ),
						'category' => 'create',
						'icon'     => 'plus',
						'id'       => 'ffc-add-working-hour',
					)
				);
				?>
			</p>
		</div>

		<div id="ffc-custom-slots-wrapper" class="ffc-custom-only">
			<p><?php esc_html_e( 'Define the exact date/time blocks and how many vacancies each has. Each block is one bookable session.', 'ffcertificate' ); ?></p>

			<table class="widefat ffc-custom-slots-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Date', 'ffcertificate' ); ?></th>
						<th><?php esc_html_e( 'Start', 'ffcertificate' ); ?></th>
						<th><?php esc_html_e( 'End', 'ffcertificate' ); ?></th>
						<th><?php esc_html_e( 'Vacancies', 'ffcertificate' ); ?></th>
						<th><?php esc_html_e( 'Label (optional)', 'ffcertificate' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'ffcertificate' ); ?></th>
					</tr>
				</thead>
				<tbody id="ffc-custom-slots-list">
					<?php foreach ( $custom_slots as $index => $block ) : ?>
						<tr>
							<td><input type="date" name="ffc_self_scheduling_custom_slots[<?php echo esc_attr( (string) $index ); ?>][date]" value="<?php echo esc_attr( $block['date'] ?? '' ); ?>" /></td>
							<td><input type="time" name="ffc_self_scheduling_custom_slots[<?php echo esc_attr( (string) $index ); ?>][start]" value="<?php echo esc_attr( $block['start'] ?? '' ); ?>" /></td>
							<td><input type="time" name="ffc_self_scheduling_custom_slots[<?php echo esc_attr( (string) $index ); ?>][end]" value="<?php echo esc_attr( $block['end'] ?? '' ); ?>" /></td>
							<td><input type="number" name="ffc_self_scheduling_custom_slots[<?php echo esc_attr( (string) $index ); ?>][capacity]" value="<?php echo esc_attr( (string) ( $block['capacity'] ?? 1 ) ); ?>" min="1" max="10000" required /></td>
							<td><input type="text" name="ffc_self_scheduling_custom_slots[<?php echo esc_attr( (string) $index ); ?>][label]" value="<?php echo esc_attr( $block['label'] ?? '' ); ?>" class="regular-text" /></td>
							<td>
								<?php
								AdminUI::render_button(
									array(
										'label'    => __( 'Remove', 'ffcertificate' ),
										'category' => 'danger',
										'icon'     => 'delete',
										'class'    => 'ffc-remove-slot',
									)
								);
								?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<p>
				<?php
				AdminUI::render_button(
					array(
						'label'    => __( 'Add Block', 'ffcertificate' ),
						'category' => 'create',
						'icon'     => 'plus',
						'id'       => 'ffc-add-custom-slot',
					)
				);
				?>
			</p>
			<?php if ( $mode_locked ) : ?>
				<p class="description ffc-text-danger"><?php esc_html_e( 'This calendar has bookings — blocks with existing bookings cannot be removed or retimed on save; you may still add blocks or raise capacity.', 'ffcertificate' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Booking window tab: how far ahead, and how often, a person may book.
	 *
	 * @param array<string, mixed> $config Configuration with defaults.
	 * @return void
	 */
	private function render_tab_window( array $config ): void {
		self::render_tab_hint( __( 'How far ahead, and how often, a person may book.', 'ffcertificate' ) );
		?>
		<table class="form-table">
			<tr>
				<th><label for="advance_booking_min"><?php esc_html_e( 'Minimum Advance Booking', 'ffcertificate' ); ?></label></th>
				<td>
					<input type="number" id="advance_booking_min" name="ffc_self_scheduling_config[advance_booking_min]" value="<?php echo esc_attr( $config['advance_booking_min'] ); ?>" min="0" max="720" required /> <?php esc_html_e( 'hours', 'ffcertificate' ); ?>
					<p class="description"><?php esc_html_e( 'Minimum time in advance required to book (0 = same day allowed)', 'ffcertificate' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="advance_booking_max"><?php esc_html_e( 'Maximum Advance Booking', 'ffcertificate' ); ?></label></th>
				<td>
					<input type="number" id="advance_booking_max" name="ffc_self_scheduling_config[advance_booking_max]" value="<?php echo esc_attr( $config['advance_booking_max'] ); ?>" min="1" max="365" required /> <?php esc_html_e( 'days', 'ffcertificate' ); ?>
					<p class="description"><?php esc_html_e( 'How far in advance can users book?', 'ffcertificate' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="minimum_interval_between_bookings"><?php esc_html_e( 'Minimum Interval Between Bookings', 'ffcertificate' ); ?></label></th>
				<td>
					<input type="number" id="minimum_interval_between_bookings" name="ffc_self_scheduling_config[minimum_interval_between_bookings]" value="<?php echo esc_attr( $config['minimum_interval_between_bookings'] ); ?>" min="0" max="720" required /> <?php esc_html_e( 'hours', 'ffcertificate' ); ?>
					<p class="description"><?php esc_html_e( 'Prevent users from booking another appointment within X hours of their last booking (0 = disabled, default: 24 hours)', 'ffcertificate' ); ?></p>
				</td>
			</tr>
			<tr class="ffc-custom-only">
				<th><label for="max_blocks_per_user"><?php esc_html_e( 'Blocks per User (Custom mode)', 'ffcertificate' ); ?></label></th>
				<td>
					<input type="number" id="max_blocks_per_user" name="ffc_self_scheduling_config[max_blocks_per_user]" value="<?php echo esc_attr( $config['max_blocks_per_user'] ); ?>" min="0" max="9999" required />
					<p class="description"><?php esc_html_e( 'Maximum number of blocks a single user may book in this custom calendar (0 = no limit). Waitlisted bookings count toward the limit.', 'ffcertificate' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Cancellation tab. Its nav dot follows `allow_cancellation`, as the
	 * section chip did.
	 *
	 * @param array<string, mixed> $config Configuration with defaults.
	 * @return void
	 */
	private function render_tab_cancellation( array $config ): void {
		self::render_tab_hint( __( 'Whether people can cancel their own bookings, and until when.', 'ffcertificate' ) );
		?>
		<table class="form-table">
			<tr>
				<th><label for="allow_cancellation"><?php esc_html_e( 'Allow User Cancellation', 'ffcertificate' ); ?></label></th>
				<td>
					<?php
					AdminUI::render_toggle(
						array(
							'id'      => 'allow_cancellation',
							'name'    => 'ffc_self_scheduling_config[allow_cancellation]',
							'checked' => (bool) $config['allow_cancellation'],
							'label'   => __( 'Users can cancel their own appointments', 'ffcertificate' ),
						)
					);
					?>
				</td>
			</tr>
			<tr class="ffc-cancellation-hours"<?php echo $config['allow_cancellation'] ? '' : ' style="display:none;"'; ?>>
				<th><label for="cancellation_min_hours"><?php esc_html_e( 'Cancellation Deadline', 'ffcertificate' ); ?></label></th>
				<td>
					<input type="number" id="cancellation_min_hours" name="ffc_self_scheduling_config[cancellation_min_hours]" value="<?php echo esc_attr( $config['cancellation_min_hours'] ); ?>" min="0" max="168" required /> <?php esc_html_e( 'hours before', 'ffcertificate' ); ?>
					<p class="description"><?php esc_html_e( 'Minimum notice required to cancel (e.g., 24 hours)', 'ffcertificate' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Approval & waitlist tab.
	 *
	 * @param array<string, mixed> $config Configuration with defaults.
	 * @return void
	 */
	private function render_tab_approval( array $config ): void {
		self::render_tab_hint( __( 'Manual review of each booking, and a queue for full slots.', 'ffcertificate' ) );
		?>
		<table class="form-table">
			<tr>
				<th><label for="requires_approval"><?php esc_html_e( 'Require Manual Approval', 'ffcertificate' ); ?></label></th>
				<td>
					<?php
					AdminUI::render_toggle(
						array(
							'id'      => 'requires_approval',
							'name'    => 'ffc_self_scheduling_config[requires_approval]',
							'checked' => (bool) $config['requires_approval'],
							'label'   => __( 'Admin must manually approve all bookings', 'ffcertificate' ),
						)
					);
					?>
				</td>
			</tr>
			<tr>
				<th><label for="waitlist_enabled"><?php esc_html_e( 'Enable Waitlist', 'ffcertificate' ); ?></label></th>
				<td>
					<?php
					AdminUI::render_toggle(
						array(
							'id'      => 'waitlist_enabled',
							'name'    => 'ffc_self_scheduling_config[waitlist_enabled]',
							'checked' => (bool) $config['waitlist_enabled'],
							'label'   => __( 'When a slot is full, let users join a waitlist instead of being turned away', 'ffcertificate' ),
						)
					);
					?>
					<p class="description"><?php esc_html_e( 'Applies to both scheduling modes. When a spot frees up (a cancellation or a rejected approval), the next person in line is promoted automatically.', 'ffcertificate' ); ?></p>
				</td>
			</tr>
			<tr class="ffc-waitlist-capacity"<?php echo $config['waitlist_enabled'] ? '' : ' style="display:none;"'; ?>>
				<th><label for="waitlist_capacity"><?php esc_html_e( 'Waitlist Capacity', 'ffcertificate' ); ?></label></th>
				<td>
					<input type="number" id="waitlist_capacity" name="ffc_self_scheduling_config[waitlist_capacity]" value="<?php echo esc_attr( $config['waitlist_capacity'] ); ?>" min="0" max="9999" required /> <?php esc_html_e( 'per slot', 'ffcertificate' ); ?>
					<p class="description"><?php esc_html_e( 'Maximum number of people who can wait per slot (0 = unlimited).', 'ffcertificate' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Who can see and book tab. A private calendar forces private scheduling;
	 * `ffc-calendar-editor.js` keeps that rule live.
	 *
	 * @param array<string, mixed> $config Configuration with defaults.
	 * @return void
	 */
	private function render_tab_access( array $config ): void {
		self::render_tab_hint( __( 'Visibility of the calendar, and the hours it can be seen or booked in.', 'ffcertificate' ) );
		?>
		<table class="form-table">
			<tr>
				<th><label for="ffc_visibility"><?php esc_html_e( 'Visibility', 'ffcertificate' ); ?></label></th>
				<td>
					<select id="ffc_visibility" name="ffc_self_scheduling_config[visibility]">
						<option value="public" <?php selected( $config['visibility'], 'public' ); ?>><?php esc_html_e( 'Public', 'ffcertificate' ); ?></option>
						<option value="private" <?php selected( $config['visibility'], 'private' ); ?>><?php esc_html_e( 'Private', 'ffcertificate' ); ?></option>
					</select>
					<p class="description"><?php esc_html_e( 'Public: visible to everyone. Private: only visible to logged-in users.', 'ffcertificate' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="ffc_scheduling_visibility"><?php esc_html_e( 'Scheduling', 'ffcertificate' ); ?></label></th>
				<td>
					<select id="ffc_scheduling_visibility" name="ffc_self_scheduling_config[scheduling_visibility]" <?php echo 'private' === $config['visibility'] ? 'disabled' : ''; ?>>
						<option value="public" <?php selected( $config['scheduling_visibility'], 'public' ); ?>><?php esc_html_e( 'Public', 'ffcertificate' ); ?></option>
						<option value="private" <?php selected( $config['scheduling_visibility'], 'private' ); ?>><?php esc_html_e( 'Private', 'ffcertificate' ); ?></option>
					</select>
					<?php if ( 'private' === $config['visibility'] ) : ?>
						<input type="hidden" name="ffc_self_scheduling_config[scheduling_visibility]" value="private" />
					<?php endif; ?>
					<p class="description" id="ffc-scheduling-desc">
						<?php if ( 'private' === $config['visibility'] ) : ?>
							<?php esc_html_e( 'Forced to Private because Visibility is Private.', 'ffcertificate' ); ?>
						<?php else : ?>
							<?php esc_html_e( 'Public: anyone can book. Private: only logged-in users can book.', 'ffcertificate' ); ?>
						<?php endif; ?>
					</p>
				</td>
			</tr>
			<tr>
				<th><label for="restrict_viewing_to_hours"><?php esc_html_e( 'Restrict Viewing to Business Hours', 'ffcertificate' ); ?></label></th>
				<td>
					<?php
					AdminUI::render_toggle(
						array(
							'id'      => 'restrict_viewing_to_hours',
							'name'    => 'ffc_self_scheduling_config[restrict_viewing_to_hours]',
							'checked' => (bool) $config['restrict_viewing_to_hours'],
							'label'   => __( 'Calendar can only be viewed during working hours', 'ffcertificate' ),
						)
					);
					?>
					<p class="description"><?php esc_html_e( 'When enabled, the calendar will only be visible during the configured working hours. Outside those hours, a message will be displayed instead.', 'ffcertificate' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="restrict_booking_to_hours"><?php esc_html_e( 'Restrict Booking to Business Hours', 'ffcertificate' ); ?></label></th>
				<td>
					<?php
					AdminUI::render_toggle(
						array(
							'id'      => 'restrict_booking_to_hours',
							'name'    => 'ffc_self_scheduling_config[restrict_booking_to_hours]',
							'checked' => (bool) $config['restrict_booking_to_hours'],
							'label'   => __( 'Bookings can only be made during working hours', 'ffcertificate' ),
						)
					);
					?>
					<p class="description"><?php esc_html_e( 'When enabled, users can view the calendar at any time but can only make bookings during the configured working hours.', 'ffcertificate' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Administrator bypass tab. Its nav dot follows `admin_bypass`, as the
	 * section chip did.
	 *
	 * @param array<string, mixed> $config Configuration with defaults.
	 * @return void
	 */
	private function render_tab_bypass( array $config ): void {
		self::render_tab_hint( __( 'Whether administrators are held to the rules above.', 'ffcertificate' ) );
		?>
		<table class="form-table">
			<tr>
				<th><label for="admin_bypass"><?php esc_html_e( 'Admin Bypass', 'ffcertificate' ); ?></label></th>
				<td>
					<?php
					AdminUI::render_toggle(
						array(
							'id'      => 'admin_bypass',
							'name'    => 'ffc_self_scheduling_config[admin_bypass]',
							'checked' => (bool) $config['admin_bypass'],
							'label'   => __( 'Allow administrators to bypass booking restrictions on this calendar', 'ffcertificate' ),
						)
					);
					?>
					<p class="description"><?php esc_html_e( 'When enabled, users with the "manage_options" capability or the "ffc_bypass_appointments" capability can book outside the advance-booking window, on blocked dates, outside working hours, and past the daily/interval limits. Slot capacity is always enforced. Disable this to apply all booking rules uniformly, including to administrators.', 'ffcertificate' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Emails tab. It holds three subjects, so they stay collapsible sections
	 * inside the tab -- the form editor's pattern -- with the chips that say
	 * whether the administrator notice and the confirmation are on.
	 *
	 * @param object $post Post object.
	 * @phpstan-param \WP_Post $post
	 * @return void
	 */
	private function render_tab_emails( object $post ): void {
		$email_config = get_post_meta( $post->ID, '_ffc_self_scheduling_email_config', true );
		if ( ! is_array( $email_config ) ) {
			$email_config = array();
		}

		$defaults = array(
			'send_user_confirmation'         => 0,
			'send_admin_notification'        => 0,
			'send_approval_notification'     => 0,
			'send_cancellation_notification' => 0,
			'send_reminder'                  => 0,
			'reminder_hours_before'          => 24,
			'admin_emails'                   => '',
			'user_confirmation_subject'      => __( 'Appointment Confirmation - {{calendar_title}}', 'ffcertificate' ),
			'user_confirmation_body'         => __( "Hello {{user_name}},\n\nYour appointment has been scheduled:\n\nCalendar: {{calendar_title}}\nDate: {{appointment_date}}\nTime: {{appointment_time}}\n\nThank you!", 'ffcertificate' ),
		);

		$email_config = array_merge( $defaults, $email_config );

		?>
		<?php \FreeFormCertificate\Core\EmailDisabledNotice::render(); ?>
		<div class="ffc-sections">
		<?php
		AdminUI::render_section_open(
			array(
				'title' => __( 'Which emails are sent', 'ffcertificate' ),
				'hint'  => __( 'Each message on its own switch, and when the reminder goes out.', 'ffcertificate' ),
				'icon'  => 'email',
				'open'  => true,
			)
		);
		?>
		<table class="form-table">
			<tr>
				<th><?php esc_html_e( 'Notifications', 'ffcertificate' ); ?></th>
				<td>
					<div class="ffc-control-toggle-stack">
						<?php
						AdminUI::render_toggle(
							array(
								'id'      => 'ffc_send_user_confirmation',
								'name'    => 'ffc_self_scheduling_email_config[send_user_confirmation]',
								'checked' => (bool) $email_config['send_user_confirmation'],
								'label'   => __( 'Send confirmation email to user', 'ffcertificate' ),
								'class'   => 'ffc-email-toggle',
							)
						);
						AdminUI::render_toggle(
							array(
								'id'      => 'ffc_send_admin_notification',
								'name'    => 'ffc_self_scheduling_email_config[send_admin_notification]',
								'checked' => (bool) $email_config['send_admin_notification'],
								'label'   => __( 'Send notification to admin on new booking', 'ffcertificate' ),
								'class'   => 'ffc-email-toggle',
							)
						);
						AdminUI::render_toggle(
							array(
								'id'      => 'ffc_send_approval_notification',
								'name'    => 'ffc_self_scheduling_email_config[send_approval_notification]',
								'checked' => (bool) $email_config['send_approval_notification'],
								'label'   => __( 'Send notification when booking is approved', 'ffcertificate' ),
								'class'   => 'ffc-email-toggle',
							)
						);
						AdminUI::render_toggle(
							array(
								'id'      => 'ffc_send_cancellation_notification',
								'name'    => 'ffc_self_scheduling_email_config[send_cancellation_notification]',
								'checked' => (bool) $email_config['send_cancellation_notification'],
								'label'   => __( 'Send notification when booking is cancelled', 'ffcertificate' ),
								'class'   => 'ffc-email-toggle',
							)
						);
						AdminUI::render_toggle(
							array(
								'id'      => 'ffc_send_reminder',
								'name'    => 'ffc_self_scheduling_email_config[send_reminder]',
								'checked' => (bool) $email_config['send_reminder'],
								'label'   => __( 'Send reminder before appointment', 'ffcertificate' ),
								'class'   => 'ffc-email-toggle',
							)
						);
						?>
					</div>
					<p class="description"><?php esc_html_e( 'Default: All notifications disabled', 'ffcertificate' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="reminder_hours_before"><?php esc_html_e( 'Reminder Timing', 'ffcertificate' ); ?></label></th>
				<td>
					<input type="number" id="reminder_hours_before" name="ffc_self_scheduling_email_config[reminder_hours_before]" value="<?php echo esc_attr( $email_config['reminder_hours_before'] ); ?>" min="1" max="168" required /> <?php esc_html_e( 'hours before appointment', 'ffcertificate' ); ?>
				</td>
			</tr>
		</table>
		<?php
		AdminUI::render_section_close();
		AdminUI::render_section_open(
			array(
				'title'  => __( 'Administrator recipients', 'ffcertificate' ),
				'hint'   => __( 'Who receives the new-booking notification.', 'ffcertificate' ),
				'icon'   => 'users',
				'master' => 'ffc_send_admin_notification',
				'on'     => (bool) $email_config['send_admin_notification'],
			)
		);
		?>
		<table class="form-table">
			<tr>
				<th><label for="admin_emails"><?php esc_html_e( 'Admin Email Addresses', 'ffcertificate' ); ?></label></th>
				<td>
					<input type="text" id="admin_emails" name="ffc_self_scheduling_email_config[admin_emails]" value="<?php echo esc_attr( $email_config['admin_emails'] ); ?>" class="large-text" placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>" />
					<p class="description"><?php esc_html_e( 'Comma-separated email addresses for admin notifications (leave empty to use site admin email)', 'ffcertificate' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
		AdminUI::render_section_close();
		AdminUI::render_section_open(
			array(
				'title'  => __( 'Confirmation message', 'ffcertificate' ),
				'hint'   => __( 'The subject and text of the email the person receives.', 'ffcertificate' ),
				'icon'   => 'edit',
				'master' => 'ffc_send_user_confirmation',
				'on'     => (bool) $email_config['send_user_confirmation'],
			)
		);
		?>
		<table class="form-table">
			<tr>
				<th><label for="user_confirmation_subject"><?php esc_html_e( 'Confirmation Email Subject', 'ffcertificate' ); ?></label></th>
				<td>
					<input type="text" id="user_confirmation_subject" name="ffc_self_scheduling_email_config[user_confirmation_subject]" value="<?php echo esc_attr( $email_config['user_confirmation_subject'] ); ?>" class="large-text" />
				</td>
			</tr>
			<tr>
				<th><label for="user_confirmation_body"><?php esc_html_e( 'Confirmation Email Body', 'ffcertificate' ); ?></label></th>
				<td>
					<?php
					wp_editor(
						(string) $email_config['user_confirmation_body'],
						'user_confirmation_body',
						array(
							'textarea_name' => 'ffc_self_scheduling_email_config[user_confirmation_body]',
							'textarea_rows' => 10,
							'media_buttons' => false,
							'teeny'         => true,
							'tinymce'       => array(
								'toolbar1' => 'bold,italic,underline,bullist,numlist,link,unlink,undo,redo',
								'toolbar2' => '',
							),
							'quicktags'     => array( 'buttons' => 'strong,em,link,ul,ol,li,close' ),
						)
					);
					?>
					<p class="description">
						<?php esc_html_e( 'The message content ("email body") — the shared Email Model chrome (header/footer) is added automatically. Available variables:', 'ffcertificate' ); ?>
						<code>{{user_name}}</code>,
						<code>{{user_email}}</code>,
						<code>{{calendar_title}}</code>,
						<code>{{appointment_date}}</code>,
						<code>{{appointment_time}}</code>,
						<code>{{status_message}}</code>,
						<code>{{status_label}}</code>,
						<code>{{user_notes_block}}</code>,
						<code>{{receipt_button}}</code>,
						<code>{{cancel_button}}</code>
					</p>
					<p class="description"><em><?php esc_html_e( 'Include {{receipt_button}} / {{cancel_button}} to show the receipt and cancel buttons (each empty when unavailable). Leave the whole body empty to use the global default from Settings → SMTP → Email texts.', 'ffcertificate' ); ?></em></p>
					<p>
						<?php
						AdminUI::render_button(
							array(
								'label'    => __( 'Restore Default Text', 'ffcertificate' ),
								'category' => 'caution',
								'icon'     => 'restore',
								'class'    => 'ffc-email-restore-default',
								'attrs'    => array(
									'data-editor'      => 'user_confirmation_body',
									'data-default-key' => 'selfsched_confirmation_body',
								),
							)
						);
						?>
					</p>
				</td>
			</tr>
		</table>
		<?php AdminUI::render_section_close(); ?>
		</div>
		<?php
	}

	/**
	 * Render shortcode metabox (sidebar)
	 *
	 * @param object $post Post object.
	 * @phpstan-param \WP_Post $post
	 * @return void
	 */
	public function render_shortcode_metabox( object $post ): void {
		?>
		<div class="ffc-shortcode-box">
			<p><strong><?php esc_html_e( 'Use this shortcode to display the calendar:', 'ffcertificate' ); ?></strong></p>

			<?php if ( 'publish' === $post->post_status ) : ?>
				<?php // A read-only input the shared copy button (`.ffc-copy-link`, in ffc-core.js) reads the .val() of (#1629). ?>
				<div class="ffc-calendar-shortcode-copy">
					<input type="text" readonly id="ffc-calendar-shortcode" value="<?php echo esc_attr( '[ffc_self_scheduling id="' . $post->ID . '"]' ); ?>" onclick="this.select();" class="ffc-shortcode-input code" />
					<?php
					AdminUI::render_button(
						array(
							'label' => __( 'Copy', 'ffcertificate' ),
							'icon'  => 'copy',
							'class' => 'ffc-copy-link',
							'attrs' => array( 'data-ffc-copy-target' => '#ffc-calendar-shortcode' ),
						)
					);
					?>
				</div>
				<?php
				// No "Preview Calendar" button (#1770): the link it printed was read by nothing,
				// and the calendar is a live booking form, so a preview would create real
				// appointments and send real e-mails. Place the shortcode on a draft page instead.
				?>
			<?php else : ?>
				<p class="description"><?php esc_html_e( 'Publish this calendar to generate the shortcode.', 'ffcertificate' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}
}
