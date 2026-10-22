<?php
/**
 * Audience Shortcode
 *
 * Renders the audience booking calendar via [ffc_audience] shortcode.
 * Displays a monthly calendar grid with available slots and booking capabilities.
 *
 * Usage:
 * [ffc_audience]                          - Shows all calendars user has access to
 * [ffc_audience schedule_id="1"]          - Shows specific calendar
 * [ffc_audience environment_id="1"]       - Shows specific environment
 *
 * @since 4.5.0
 * @package FreeFormCertificate\Audience
 */

declare(strict_types=1);

namespace FreeFormCertificate\Audience;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the audience booking calendar via [ffc_audience] shortcode.
 *
 * @since 4.5.0
 *
 * @phpstan-import-type AudienceRow from AudienceReader
 * @phpstan-import-type ScheduleRow from AudienceScheduleRepository
 */
class AudienceShortcode {

	/**
	 * Register shortcode
	 *
	 * @return void
	 */
	public static function init(): void {
		add_shortcode( 'ffc_audience', array( __CLASS__, 'render' ) );
	}

	/**
	 * Render the shortcode
	 *
	 * @param array<string, mixed>|string $atts Shortcode attributes.
	 * @return string HTML output
	 */
	public static function render( $atts = array() ): string {
		// Ensure $atts is an array.
		if ( ! is_array( $atts ) ) {
			$atts = array();
		}

		// Parse attributes.
		$atts = shortcode_atts(
			array(
				'schedule_id'    => 0,
				'environment_id' => 0,
				'view'           => 'month', // month, week.
			),
			$atts,
			'ffc_audience'
		);

		// Always enqueue CSS so styles are consistent regardless of login state.
		self::enqueue_styles();

		$is_logged_in = is_user_logged_in();
		$has_bypass   = AudienceScheduleRepository::user_has_bypass_capability();
		$specific_id  = absint( $atts['schedule_id'] );

		// Handle non-logged-in users.
		if ( ! $is_logged_in && ! $has_bypass ) {
			// Check if specific schedule requested.
			if ( $specific_id > 0 ) {
				$schedule = AudienceScheduleRepository::get_by_id( $specific_id );
				if ( ! $schedule || 'active' !== $schedule->status ) {
					return '';
				}
				if ( 'private' === $schedule->visibility ) {
					return self::render_private_visibility_message( $schedule->name );
				}
				// Public schedule: show read-only calendar.
				$schedules = array( $schedule );
			} else {
				// Get only public active schedules.
				$schedules = AudienceScheduleRepository::get_all(
					array(
						'status'     => 'active',
						'visibility' => 'public',
					)
				);
				if ( empty( $schedules ) ) {
					return self::render_private_visibility_message();
				}
			}

			// Enqueue assets for read-only view.
			self::enqueue_assets( $schedules );

			$scheduling_message = get_option(
				'ffc_aud_scheduling_message',
				__( 'To book on this calendar you need to be logged in. <a href="%login_url%">Log in</a> to continue.', 'ffcertificate' )
			);
			$scheduling_message = str_replace( '%login_url%', wp_login_url( get_permalink() ? get_permalink() : '' ), $scheduling_message );

			$config = array(
				'scheduleId'          => $specific_id,
				'environmentId'       => absint( $atts['environment_id'] ),
				'view'                => sanitize_text_field( $atts['view'] ),
				'schedules'           => array_map(
					function ( $s ) {
						return array(
							'id'                     => (int) $s->id,
							'name'                   => $s->name,
							'environmentLabel'       => AudienceScheduleRepository::get_environment_label( $s, true ),
							'environmentLabelPlural' => AudienceScheduleRepository::get_environment_label( $s ),
							'environments'           => self::get_schedule_environments( (int) $s->id ),
							'futureDaysLimit'        => isset( $s->future_days_limit ) ? (int) $s->future_days_limit : null,
							'bookingLabelSingular'   => $s->booking_label_singular ?? null,
							'bookingLabelPlural'     => $s->booking_label_plural ?? null,
						);
					},
					$schedules
				),
				'showEventList'       => self::should_show_event_list( $schedules ),
				'eventListPosition'   => self::get_event_list_position( $schedules ),
				'audienceBadgeFormat' => self::get_audience_badge_format( $schedules ),
				'canBook'             => false,
				'readOnly'            => true,
				'schedulingMessage'   => $scheduling_message,
				'audiences'           => self::get_public_audiences(),
			);

			return self::render_calendar_html( $schedules, $config, $atts, false );
		}

		$user_id = get_current_user_id();

		// Logged-in users see user-specific content (permissions, booking
		// abilities) — prevent page cache from serving another user's view.
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		nocache_headers();
		do_action( 'litespeed_control_set_nocache', 'FFC audience calendar with user-specific content' );

		// Get schedules user can access.
		$schedules = self::get_user_schedules( $user_id, $specific_id );

		if ( empty( $schedules ) ) {
			return self::render_no_access();
		}

		// Enqueue JS and localization (only for logged-in users who have access).
		self::enqueue_assets( $schedules );

		// Admin bypass notice.
		$bypass_notice = '';
		if ( $has_bypass ) {
			$private_schedules = array_filter(
				$schedules,
				function ( $s ) {
					return 'private' === $s->visibility;
				}
			);
			if ( ! empty( $private_schedules ) ) {
				$bypass_notice = \FreeFormCertificate\Core\Notice::render(
					'info',
					'unlock',
					'<span class="ffc-badge ffc-badge-private">' . esc_html__( 'Private', 'ffcertificate' ) . '</span> '
					. esc_html__( 'You are viewing this calendar as an administrator (bypass active).', 'ffcertificate' ),
					'',
					array(
						'role' => 'note',
						'html' => true,
						'hook' => 'admin-bypass',
					)
				);
			}
		}

		// Build configuration for JavaScript.
		$config = array(
			'scheduleId'          => $specific_id,
			'environmentId'       => absint( $atts['environment_id'] ),
			'view'                => sanitize_text_field( $atts['view'] ),
			'schedules'           => array_map(
				function ( $s ) use ( $user_id ) {
					return array(
						// What the booking form may offer (#1859); the server
						// decides again on every booking.
						'canBypass'              => AudienceScheduleRepository::user_has_bypass( (int) $s->id, $user_id ),
						'canOverrideConflicts'   => AudienceScheduleRepository::user_can_override_conflicts( (int) $s->id, $user_id ),
						'id'                     => (int) $s->id,
						'name'                   => $s->name,
						'environmentLabel'       => AudienceScheduleRepository::get_environment_label( $s, true ),
						'environmentLabelPlural' => AudienceScheduleRepository::get_environment_label( $s ),
						'environments'           => self::get_schedule_environments( (int) $s->id ),
						'futureDaysLimit'        => isset( $s->future_days_limit ) ? (int) $s->future_days_limit : null,
						'bookingLabelSingular'   => $s->booking_label_singular ?? null,
						'bookingLabelPlural'     => $s->booking_label_plural ?? null,
					);
				},
				$schedules
			),
			'showEventList'       => self::should_show_event_list( $schedules ),
			'eventListPosition'   => self::get_event_list_position( $schedules ),
			'audienceBadgeFormat' => self::get_audience_badge_format( $schedules ),
			'canBook'             => self::can_user_book( $user_id, $schedules ),
			'audiences'           => self::get_user_audiences( $user_id ),
		);

		return $bypass_notice . self::render_calendar_html( $schedules, $config, $atts, true );
	}

	/**
	 * Render the calendar HTML (shared between logged-in and public views)
	 *
	 * @since 4.7.0
	 * @param array<int, object>   $schedules Array of schedule objects.
	 * @phpstan-param list<ScheduleRow> $schedules
	 * @param array<string, mixed> $config JS configuration.
	 * @param array<string, mixed> $atts Shortcode attributes.
	 * @param bool                 $show_booking_modal Whether to show the booking modal.
	 * @return string HTML output
	 */
	private static function render_calendar_html( array $schedules, array $config, array $atts, bool $show_booking_modal ): string {
		$show_event_list     = ! empty( $config['showEventList'] );
		$event_list_position = $config['eventListPosition'] ?? 'side';
		// The wrapper is a plugin root (#1832): the event-list panel is the
		// calendar's sibling, so without the root class here no rule reaches it.
		$wrapper_class = 'ffc-shortcode ffc-calendar-wrapper';
		if ( $show_event_list ) {
			$wrapper_class .= ' ffc-has-event-list ffc-event-list-' . esc_attr( $event_list_position );
		}

		ob_start();
		$config_json = wp_json_encode( $config );
		?>
		<div class="<?php echo esc_attr( $wrapper_class ); ?>">
		<div class="ffc-shortcode ffc-audience-calendar" id="ffc-audience-calendar" data-config="<?php echo esc_attr( $config_json ? $config_json : '' ); ?>">
			<!-- Header -->
			<div class="ffc-calendar-header">
				<div class="ffc-calendar-nav">
					<button type="button" class="ffc-nav-btn ffc-prev-month" aria-label="<?php esc_attr_e( 'Previous month', 'ffcertificate' ); ?>">
						&lsaquo;
					</button>
					<h2 class="ffc-current-month"></h2>
					<button type="button" class="ffc-nav-btn ffc-next-month" aria-label="<?php esc_attr_e( 'Next month', 'ffcertificate' ); ?>">
						&rsaquo;
					</button>
					<button type="button" class="ffc-nav-btn ffc-today-btn">
						<?php esc_html_e( 'Today', 'ffcertificate' ); ?>
					</button>
				</div>

				<div class="ffc-calendar-filters">
					<?php if ( count( $schedules ) > 1 ) : ?>
						<select class="ffc-schedule-select" id="ffc-schedule-select">
							<option value=""><?php esc_html_e( 'All Calendars', 'ffcertificate' ); ?></option>
							<?php foreach ( $schedules as $schedule ) : ?>
								<option value="<?php echo esc_attr( $schedule->id ); ?>" <?php selected( absint( $atts['schedule_id'] ), $schedule->id ); ?>>
									<?php echo esc_html( $schedule->name ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					<?php endif; ?>

					<?php
					$first_env_label_plural   = ! empty( $schedules )
						? AudienceScheduleRepository::get_environment_label( $schedules[0] )
						: AudienceScheduleRepository::get_environment_label();
					$first_env_label_singular = ! empty( $schedules )
						? AudienceScheduleRepository::get_environment_label( $schedules[0], true )
						: AudienceScheduleRepository::get_environment_label( null, true );
					?>
					<select class="ffc-environment-select" id="ffc-environment-select" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: environment label (singular) */ __( 'Filter by %s', 'ffcertificate' ), $first_env_label_singular ) ); ?>">
						<option value="">
							<?php
							/* translators: %s: environment label (plural) */
							printf( esc_html__( 'All %s', 'ffcertificate' ), esc_html( $first_env_label_plural ) );
							?>
						</option>
						<!-- Populated by JavaScript -->
					</select>
				</div>
			</div>

			<!-- Calendar Grid -->
			<div class="ffc-calendar-grid">
				<!-- Day headers -->
				<div class="ffc-calendar-weekdays">
					<div class="ffc-weekday"><?php esc_html_e( 'Sun', 'ffcertificate' ); ?></div>
					<div class="ffc-weekday"><?php esc_html_e( 'Mon', 'ffcertificate' ); ?></div>
					<div class="ffc-weekday"><?php esc_html_e( 'Tue', 'ffcertificate' ); ?></div>
					<div class="ffc-weekday"><?php esc_html_e( 'Wed', 'ffcertificate' ); ?></div>
					<div class="ffc-weekday"><?php esc_html_e( 'Thu', 'ffcertificate' ); ?></div>
					<div class="ffc-weekday"><?php esc_html_e( 'Fri', 'ffcertificate' ); ?></div>
					<div class="ffc-weekday"><?php esc_html_e( 'Sat', 'ffcertificate' ); ?></div>
				</div>

				<!-- Calendar days (populated by JavaScript) -->
				<div class="ffc-calendar-days" id="ffc-calendar-days">
					<div class="ffc-loading">
						<?php esc_html_e( 'Loading calendar...', 'ffcertificate' ); ?>
					</div>
				</div>
			</div>

			<!-- Legend -->
			<div class="ffc-calendar-legend">
				<span class="ffc-legend-item"><span class="ffc-legend-dot ffc-available"></span> <?php esc_html_e( 'Available', 'ffcertificate' ); ?></span>
				<span class="ffc-legend-item"><span class="ffc-legend-dot ffc-booked"></span> <?php esc_html_e( 'Booked', 'ffcertificate' ); ?></span>
				<span class="ffc-legend-item"><span class="ffc-legend-dot ffc-holiday"></span> <?php esc_html_e( 'Holiday', 'ffcertificate' ); ?></span>
				<span class="ffc-legend-item"><span class="ffc-legend-dot ffc-closed"></span> <?php esc_html_e( 'Closed', 'ffcertificate' ); ?></span>
			</div>
		</div>

		<?php if ( $show_event_list ) : ?>
		<!-- Event List Panel -->
		<div class="ffc-event-list-panel" id="ffc-event-list-panel">
			<div class="ffc-event-list-header">
				<h3><?php esc_html_e( 'Events', 'ffcertificate' ); ?></h3>
			</div>
			<div class="ffc-event-list-content" id="ffc-event-list-content">
				<div class="ffc-loading"><?php esc_html_e( 'Loading...', 'ffcertificate' ); ?></div>
			</div>
		</div>
		<?php endif; ?>
		</div><!-- .ffc-calendar-wrapper -->

		<?php if ( $show_booking_modal ) : ?>
		<!-- Booking Modal -->
		<div class="ffc-shortcode ffc-modal" id="ffc-booking-modal" role="dialog" aria-modal="true" aria-labelledby="ffc-booking-modal-title" style="display: none;">
			<div class="ffc-modal-backdrop"></div>
			<div class="ffc-modal-content">
				<div class="ffc-modal-header">
					<h3 id="ffc-booking-modal-title"><?php esc_html_e( 'New Booking', 'ffcertificate' ); ?></h3>
					<?php
					\FreeFormCertificate\Core\Button::render(
						array(
							'label'     => __( 'Close', 'ffcertificate' ),
							'category'  => 'neutral',
							'icon'      => 'cross',
							'icon_only' => true,
							'class'     => 'ffc-modal-close',
						)
					);
					?>
				</div>
				<div class="ffc-modal-body">
					<form id="ffc-booking-form">
						<input type="hidden" name="booking_date" id="booking-date">

						<div class="ffc-form-group">
							<label><?php esc_html_e( 'Date', 'ffcertificate' ); ?></label>
							<p class="ffc-booking-date-display"></p>
						</div>

						<div class="ffc-form-group">
							<label for="booking-environment-id">
								<?php echo esc_html( $first_env_label_singular ); ?> <span class="ffc-required" aria-hidden="true">*</span>
							</label>
							<select name="environment_id" id="booking-environment-id" required>
								<!-- Populated by JavaScript -->
							</select>
						</div>

						<div class="ffc-form-group">
							<label>
								<input type="checkbox" id="booking-all-day" name="is_all_day" value="1">
								<?php esc_html_e( 'All day event', 'ffcertificate' ); ?>
							</label>
						</div>

						<div class="ffc-form-group" id="booking-end-date-group">
							<label for="booking-end-date"><?php esc_html_e( 'End date (optional)', 'ffcertificate' ); ?></label>
							<input type="date" name="end_date" id="booking-end-date">
							<p class="description"><?php esc_html_e( 'Leave empty for a single day. A range books every day from the date above to this one.', 'ffcertificate' ); ?></p>
						</div>

						<div class="ffc-form-group" id="booking-range-options" hidden>
							<label for="booking-time-mode"><?php esc_html_e( 'Hours on each day', 'ffcertificate' ); ?></label>
							<select name="time_mode" id="booking-time-mode">
								<option value="daily"><?php esc_html_e( 'The same hours every day', 'ffcertificate' ); ?></option>
								<option value="span"><?php esc_html_e( 'From the start time on the first day to the end time on the last', 'ffcertificate' ); ?></option>
							</select>
							<label>
								<input type="checkbox" id="booking-skip-blocked" name="skip_blocked" value="1">
								<?php esc_html_e( 'Skip blocked days (holidays and global closures) instead of refusing the booking', 'ffcertificate' ); ?>
							</label>
						</div>

						<div class="ffc-form-row" id="booking-time-row">
							<div class="ffc-form-group">
								<label for="booking-start-time"><?php esc_html_e( 'Start Time', 'ffcertificate' ); ?> <span class="ffc-required" aria-hidden="true">*</span></label>
								<input type="time" name="start_time" id="booking-start-time" required>
							</div>
							<div class="ffc-form-group">
								<label for="booking-end-time"><?php esc_html_e( 'End Time', 'ffcertificate' ); ?> <span class="ffc-required" aria-hidden="true">*</span></label>
								<input type="time" name="end_time" id="booking-end-time" required>
							</div>
						</div>

						<div class="ffc-form-group">
							<label for="booking-type"><?php esc_html_e( 'Booking Type', 'ffcertificate' ); ?> <span class="ffc-required" aria-hidden="true">*</span></label>
							<select name="booking_type" id="booking-type" required>
								<option value="audience"><?php esc_html_e( 'Audience Groups', 'ffcertificate' ); ?></option>
								<option value="individual"><?php esc_html_e( 'Individual Users', 'ffcertificate' ); ?></option>
							</select>
						</div>

						<div class="ffc-form-group" id="audience-select-group">
							<label for="booking-audiences"><?php esc_html_e( 'Select Audiences', 'ffcertificate' ); ?> <span class="ffc-required" aria-hidden="true">*</span></label>
							<select name="audience_ids[]" id="booking-audiences" multiple class="ffc-multiselect">
								<!-- Populated by JavaScript -->
							</select>
						</div>

						<div class="ffc-form-group" id="user-select-group" style="display: none;">
							<label for="booking-users"><?php esc_html_e( 'Select Users', 'ffcertificate' ); ?> <span class="ffc-required" aria-hidden="true">*</span></label>
							<input type="text" id="booking-user-search" placeholder="<?php esc_attr_e( 'Search users...', 'ffcertificate' ); ?>">
							<div id="booking-user-results" class="ffc-user-results"></div>
							<div id="booking-selected-users" class="ffc-selected-users"></div>
							<input type="hidden" name="user_ids" id="booking-user-ids">
						</div>

						<div class="ffc-form-group">
							<label for="booking-description"><?php esc_html_e( 'Description', 'ffcertificate' ); ?> <span class="ffc-required" aria-hidden="true">*</span></label>
							<textarea name="description" id="booking-description" rows="3" required minlength="15" maxlength="300"
										placeholder="<?php esc_attr_e( 'Describe the purpose of this booking (15-300 characters)', 'ffcertificate' ); ?>"></textarea>
							<div class="ffc-field-foot">
								<span class="ffc-field-counter" aria-hidden="true" data-ffc-counter-for="booking-description"><span id="desc-char-count">0</span>/300</span>
							</div>
						</div>

						<!-- Soft conflict warning: ffc-audience-booking-form.js builds the
							notice (#1842) and moves the acknowledgement into it. -->
						<div id="ffc-conflict-warning" style="display: none;">
							<label class="ffc-conflict-acknowledge">
								<input type="checkbox" id="ffc-conflict-acknowledge">
								<?php esc_html_e( 'I am aware of the conflicts and want to proceed.', 'ffcertificate' ); ?>
							</label>
						</div>

						<!-- Hard conflict error: the script builds the notice here. -->
						<div id="ffc-conflict-error" style="display: none;"></div>
					</form>
				</div>
				<div class="ffc-modal-footer">
					<?php
					\FreeFormCertificate\Core\Button::render(
						array(
							'label'    => __( 'Cancel', 'ffcertificate' ),
							'category' => 'neutral',
							'icon'     => 'cross',
							'class'    => 'ffc-modal-cancel',
						)
					);
					\FreeFormCertificate\Core\Button::render(
						array(
							'label'    => __( 'Check Conflicts', 'ffcertificate' ),
							'category' => 'primary',
							'icon'     => 'search',
							'id'       => 'ffc-check-conflicts-btn',
						)
					);
					?>
					<button type="button" class="ffc-btn ffc-btn-create ffc-icon-plus" id="ffc-create-booking-btn" style="display: none;">
						<?php esc_html_e( 'Create Booking', 'ffcertificate' ); ?>
					</button>
				</div>
			</div>
		</div>
		<?php endif; ?>

		<!-- Day Detail Modal -->
		<div class="ffc-shortcode ffc-modal" id="ffc-day-modal" role="dialog" aria-modal="true" aria-labelledby="ffc-day-modal-title" style="display: none;">
			<div class="ffc-modal-backdrop"></div>
			<div class="ffc-modal-content ffc-modal-lg">
				<div class="ffc-modal-header">
					<h3 class="ffc-day-modal-title" id="ffc-day-modal-title"></h3>
					<?php
					\FreeFormCertificate\Core\Button::render(
						array(
							'label'     => __( 'Close', 'ffcertificate' ),
							'category'  => 'neutral',
							'icon'      => 'cross',
							'icon_only' => true,
							'class'     => 'ffc-modal-close',
						)
					);
					?>
				</div>
				<div class="ffc-modal-body">
					<div class="ffc-day-filter">
						<label>
							<input type="checkbox" id="ffc-show-cancelled">
							<?php esc_html_e( 'Show cancelled bookings', 'ffcertificate' ); ?>
						</label>
					</div>
					<div class="ffc-day-bookings" id="ffc-day-bookings">
						<div class="ffc-loading"><?php esc_html_e( 'Loading bookings...', 'ffcertificate' ); ?></div>
					</div>
				</div>
				<div class="ffc-modal-footer">
					<?php
					\FreeFormCertificate\Core\Button::render(
						array(
							'label'    => __( 'Close', 'ffcertificate' ),
							'category' => 'neutral',
							'icon'     => 'cross',
							'class'    => 'ffc-modal-cancel',
						)
					);
					if ( $show_booking_modal ) {
						\FreeFormCertificate\Core\Button::render(
							array(
								'label'    => __( 'New Booking', 'ffcertificate' ),
								'category' => 'create',
								'icon'     => 'plus',
								'id'       => 'ffc-new-booking-btn',
							)
						);
					}
					?>
				</div>
			</div>
		</div>
		<?php

		$output = ob_get_clean();
		return is_string( $output ) ? $output : '';
	}

	/**
	 * Determine if event list should be shown based on schedules
	 *
	 * @since 4.8.0
	 * @param array<int, object> $schedules Array of schedule objects.
	 * @phpstan-param list<ScheduleRow> $schedules
	 * @return bool
	 */
	private static function should_show_event_list( array $schedules ): bool {
		foreach ( $schedules as $s ) {
			if ( ! empty( $s->show_event_list ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Get event list position from schedules (first one that has it enabled)
	 *
	 * @since 4.8.0
	 * @param array<int, object> $schedules Array of schedule objects.
	 * @phpstan-param list<ScheduleRow> $schedules
	 * @return string 'side' or 'below'
	 */
	private static function get_event_list_position( array $schedules ): string {
		foreach ( $schedules as $s ) {
			if ( ! empty( $s->show_event_list ) && ! empty( $s->event_list_position ) ) {
				return $s->event_list_position;
			}
		}
		return 'side';
	}

	/**
	 * Get audience badge format from schedules (first one with a value)
	 *
	 * @since 4.9.0
	 * @param array<int, object> $schedules Array of schedule objects.
	 * @phpstan-param list<ScheduleRow> $schedules
	 * @return string 'name' or 'parent_name'
	 */
	private static function get_audience_badge_format( array $schedules ): string {
		foreach ( $schedules as $s ) {
			if ( ! empty( $s->audience_badge_format ) ) {
				return $s->audience_badge_format;
			}
		}
		return 'name';
	}

	/**
	 * Render private visibility message based on settings
	 *
	 * @since 4.7.0
	 * @param string|null $schedule_name Optional schedule name for title display.
	 * @return string HTML output
	 */
	private static function render_private_visibility_message( ?string $schedule_name = null ): string {
		$display_mode = get_option( 'ffc_aud_private_display_mode', 'show_message' );

		if ( 'hide' === $display_mode ) {
			return '';
		}

		$message = get_option(
			'ffc_aud_visibility_message',
			__( 'To view this calendar you need to be logged in. <a href="%login_url%">Log in</a> to continue.', 'ffcertificate' )
		);
		$message = str_replace( '%login_url%', wp_login_url( get_permalink() ? get_permalink() : '' ), $message );

		$output = '<div class="ffc-visibility-restricted">';

		if ( 'show_title_message' === $display_mode && $schedule_name ) {
			$output .= '<h3 class="ffc-calendar-title">' . esc_html( $schedule_name ) . '</h3>';
		}

		$output .= \FreeFormCertificate\Core\Notice::render( 'warning', 'login', $message, '', array( 'html' => true ) );
		$output .= '</div>';

		return $output;
	}

	/**
	 * Render no access message
	 *
	 * @return string HTML output
	 */
	private static function render_no_access(): string {
		return \FreeFormCertificate\Core\Notice::render(
			'info',
			'lock',
			__( 'You do not have access to any calendars.', 'ffcertificate' ),
			'',
			array(
				'role' => 'note',
				'hook' => 'audience-no-access',
			)
		);
	}

	/**
	 * Get schedules user can access
	 *
	 * @param int $user_id User ID.
	 * @param int $specific_id Specific schedule ID (0 for all).
	 * @return list<ScheduleRow>
	 */
	private static function get_user_schedules( int $user_id, int $specific_id = 0 ): array {
		// Admin and audience-bypass holders can access all.
		if ( AudienceScheduleRepository::user_has_bypass_capability( $user_id ) ) {
			if ( $specific_id > 0 ) {
				$schedule = AudienceScheduleRepository::get_by_id( $specific_id );
				return $schedule ? array( $schedule ) : array();
			}
			return AudienceScheduleRepository::get_all( array( 'status' => 'active' ) );
		}

		// Get schedules user has explicit access to.
		$schedules = AudienceScheduleRepository::get_by_user_access( $user_id );

		if ( $specific_id > 0 ) {
			$schedules = array_filter(
				$schedules,
				function ( $s ) use ( $specific_id ) {
					return (int) $s->id === $specific_id;
				}
			);
		}

		return array_values( $schedules );
	}

	/**
	 * Get environments for a schedule
	 *
	 * @param int $schedule_id Schedule ID.
	 * @return list<array{id: int, name: string, color: string}>
	 */
	private static function get_schedule_environments( int $schedule_id ): array {
		$environments = AudienceEnvironmentRepository::get_by_schedule( $schedule_id, 'active' );

		return array_map(
			function ( $e ) {
				return array(
					'id'    => (int) $e->id,
					'name'  => $e->name,
					'color' => $e->color ?? '#3788d8',
				);
			},
			$environments
		);
	}

	/**
	 * Check if user can book on any of the schedules
	 *
	 * @param int                $user_id User ID.
	 * @param array<int, object> $schedules Schedules.
	 * @phpstan-param list<ScheduleRow> $schedules
	 * @return bool
	 */
	private static function can_user_book( int $user_id, array $schedules ): bool {
		if ( user_can( $user_id, 'manage_options' ) ) {
			return true;
		}

		foreach ( $schedules as $schedule ) {
			if ( AudienceScheduleRepository::user_can_book( (int) $schedule->id, $user_id ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Get audiences user can book for
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function get_public_audiences(): array {
		$audiences = AudienceReader::get_hierarchical( 'active' );
		return array_map( array( __CLASS__, 'audience_to_array' ), $audiences );
	}

	/**
	 * Recursively convert an audience object (with children) to an array.
	 *
	 * @param object $audience Audience object with optional children.
	 * @phpstan-param AudienceRow $audience
	 * @return array<string, mixed>
	 */
	private static function audience_to_array( object $audience ): array {
		$item = array(
			'id'        => $audience->id,
			'name'      => $audience->name,
			'color'     => $audience->color,
			'parent_id' => $audience->parent_id ?? null,
		);
		if ( ! empty( $audience->children ) ) {
			$item['children'] = array_map( array( __CLASS__, 'audience_to_array' ), $audience->children );
		}
		return $item;
	}

	/**
	 * Get audiences the user belongs to or all audiences for admins
	 *
	 * @param int $user_id User ID.
	 * @return array<int, array<string, mixed>>
	 */
	private static function get_user_audiences( int $user_id ): array {
		// Admin can book any audience.
		if ( user_can( $user_id, 'manage_options' ) ) {
			$audiences = AudienceReader::get_hierarchical( 'active' );
		} else {
			// Regular users can only book for audiences they belong to.
			$audiences = AudienceReader::get_user_audiences( $user_id, true );
		}

		return array_map( array( __CLASS__, 'audience_to_array' ), $audiences );
	}

	/**
	 * Enqueue frontend assets
	 *
	 * @return void
	 */
	/**
	 * Enqueue CSS only (safe for all users)
	 */
	private static function enqueue_styles(): void {
		$s = \FreeFormCertificate\Core\AssetHelper::asset_suffix();
		// Without this script `.ffc-dark-mode` never reaches this page's <html>,
		// and the palette stays frozen in the light theme (#1126).
		\FreeFormCertificate\Core\AssetHelper::enqueue_dark_mode();

		\FreeFormCertificate\Core\AssetHelper::enqueue_common_style();
		\FreeFormCertificate\Core\AssetRegistry::enqueue_styles( 'ffc-audience-css' );
	}

	/**
	 * Enqueue JavaScript assets and localize script
	 *
	 * @param array<int, object> $schedules Array of schedule objects.
	 * @phpstan-param list<ScheduleRow> $schedules
	 * @return void
	 */
	private static function enqueue_assets( array $schedules = array() ): void {
		// CSS (in case enqueue_styles wasn't called yet).
		self::enqueue_styles();

		// JavaScript.
		$s = \FreeFormCertificate\Core\AssetHelper::asset_suffix();
		\FreeFormCertificate\Core\AssetRegistry::enqueue_scripts( 'ffc-audience' );

		// Calendar modules split out of the former monolith. Each depends on
		// 'ffc-audience' (the shared core that owns window.FFCAudience and the
		// normalized ffcAudience config) and extends the namespace.
		foreach (
			array(
				'ffc-audience-calendar',
				'ffc-audience-bookings',
				'ffc-audience-booking-form',
			) as $ffc_audience_module
		) {
			wp_enqueue_script(
				$ffc_audience_module,
				FFC_PLUGIN_URL . "assets/js/{$ffc_audience_module}{$s}.js",
				array( 'jquery', 'ffc-core', 'ffc-audience' ),
				FFC_VERSION,
				true
			);
		}

		// Dynamic fragments: refresh the wp_rest + ffc_search_users nonces
		// localised on `ffcAudience` so cached (per-user) pages don't keep
		// serving the nonce of whoever the cache entry was generated for.
		wp_enqueue_script( 'ffc-dynamic-fragments' );

		// Resolve custom booking labels from schedule config (first non-empty wins).
		$booking_singular = __( 'booking', 'ffcertificate' );
		$booking_plural   = __( 'bookings', 'ffcertificate' );
		foreach ( $schedules as $sch ) {
			if ( ! empty( $sch->booking_label_singular ) ) {
				$booking_singular = $sch->booking_label_singular;
			}
			if ( ! empty( $sch->booking_label_plural ) ) {
				$booking_plural = $sch->booking_label_plural;
			}
			if ( ! empty( $sch->booking_label_singular ) || ! empty( $sch->booking_label_plural ) ) {
				break;
			}
		}

		// Localize script.
		wp_localize_script(
			'ffc-audience',
			'ffcAudience',
			array(
				'ajaxUrl'                => admin_url( 'admin-ajax.php' ),
				'restUrl'                => rest_url( 'ffc/v1/audience/' ),
				'nonce'                  => wp_create_nonce( 'wp_rest' ),
				'searchUsersNonce'       => wp_create_nonce( 'ffc_search_users' ),
				'locale'                 => get_locale(),
				'dateFormat'             => \FreeFormCertificate\Core\DateFormatter::resolve_date_format(),
				'timeFormat'             => \FreeFormCertificate\Core\DateFormatter::resolve_time_format(),
				'firstDayOfWeek'         => (int) get_option( 'start_of_week', 0 ),
				'multipleAudiencesColor' => get_option( 'ffc_aud_multiple_audiences_color', '' ),
				'strings'                => array(
					'loading'                => __( 'Loading...', 'ffcertificate' ),
					'error'                  => __( 'An error occurred. Please try again.', 'ffcertificate' ),
					'noBookings'             => __( 'No bookings for this day.', 'ffcertificate' ),
					'noActiveBookings'       => __( 'No active bookings for this day.', 'ffcertificate' ),
					'bookingCreated'         => __( 'Booking created successfully!', 'ffcertificate' ),
					'bookingCancelled'       => __( 'Booking cancelled successfully.', 'ffcertificate' ),
					'confirmCancel'          => __( 'Are you sure you want to cancel this booking?', 'ffcertificate' ),
					'cancelConfirm'          => AudienceBookingRules::cancel_strings(),
					'cancelReason'           => __( 'Please provide a reason for cancellation:', 'ffcertificate' ),
					'invalidTime'            => __( 'End time must be after start time.', 'ffcertificate' ),
					'fillTimeFields'         => __( 'Please fill in the time fields.', 'ffcertificate' ),
					'selectAudience'         => __( 'Please select at least one audience.', 'ffcertificate' ),
					'selectUser'             => __( 'Please select at least one user.', 'ffcertificate' ),
					'descriptionRequired'    => __( 'Description is required (15-300 characters).', 'ffcertificate' ),
					'conflictWarning'        => __( 'Warning: Conflicts detected with existing bookings.', 'ffcertificate' ),
					'audienceSameDayWarning' => __( 'Warning: The following groups already have bookings on this day:', 'ffcertificate' ),
					'membersOverlapping'     => __( 'member(s) have overlapping bookings.', 'ffcertificate' ),
					'hardConflict'           => __( 'This time slot is already booked for this environment. You cannot create a booking at this time.', 'ffcertificate' ),
					'noConflicts'            => __( 'No conflicts found. You may proceed.', 'ffcertificate' ),
					'months'                 => array(
						__( 'January', 'ffcertificate' ),
						__( 'February', 'ffcertificate' ),
						__( 'March', 'ffcertificate' ),
						__( 'April', 'ffcertificate' ),
						__( 'May', 'ffcertificate' ),
						__( 'June', 'ffcertificate' ),
						__( 'July', 'ffcertificate' ),
						__( 'August', 'ffcertificate' ),
						__( 'September', 'ffcertificate' ),
						__( 'October', 'ffcertificate' ),
						__( 'November', 'ffcertificate' ),
						__( 'December', 'ffcertificate' ),
					),
					'holiday'                => __( 'Holiday', 'ffcertificate' ),
					'closed'                 => __( 'Closed', 'ffcertificate' ),
					'available'              => __( 'Available', 'ffcertificate' ),
					'booked'                 => __( 'Booked', 'ffcertificate' ),
					'cancel'                 => __( 'Cancel', 'ffcertificate' ),
					'cancelled'              => __( 'Cancelled', 'ffcertificate' ),
					'timeout'                => __( 'Request timed out. Please try again.', 'ffcertificate' ),
					'checkConflicts'         => __( 'Check Conflicts', 'ffcertificate' ),
					'booking'                => $booking_singular,
					'bookings'               => $booking_plural,
					'createBooking'          => __( 'Create Booking', 'ffcertificate' ),
					/* translators: %s: the dates of a booking range that another booking already takes. */
					'collidingDays'          => __( 'Already booked on: %s', 'ffcertificate' ),
					'newBooking'             => __( 'New Booking', 'ffcertificate' ),
					'environmentLabel'       => __( 'Environment', 'ffcertificate' ),
					'allEnvironments'        => __( 'All Environments', 'ffcertificate' ),
					'allDay'                 => __( 'All Day', 'ffcertificate' ),
					'events'                 => __( 'Events', 'ffcertificate' ),
					'noEvents'               => __( 'No events this month.', 'ffcertificate' ),
					'multipleAudiences'      => __( 'Multiple audiences', 'ffcertificate' ),
					// The "all spaces" option of the filter (#1859): "All" before
					// the calendar's own plural label.
					'all'                    => __( 'All', 'ffcertificate' ),
					/* translators: %s: environment label (plural) */
					'allOf'                  => __( 'All %s', 'ffcertificate' ),
					// Why a space cannot be booked on a date (#1859).
					'reasonClosed'           => __( 'Closed', 'ffcertificate' ),
					'reasonHoliday'          => __( 'Holiday', 'ffcertificate' ),
					'reasonOutsideHours'     => __( 'Outside hours', 'ffcertificate' ),
					'reasonPast'             => __( 'Past date', 'ffcertificate' ),
					'reasonBeyondWindow'     => __( 'Beyond the booking window', 'ffcertificate' ),
					'noOpenEnvironments'     => __( 'No space is open on this date.', 'ffcertificate' ),
					'override'               => \FreeFormCertificate\Core\OverrideWarning::strings(),
				),
			)
		);
	}
}
