<?php
/**
 * Self-Scheduling Editor — Appointment Cleanup AJAX + Metabox
 *
 * Extracted from SelfSchedulingEditor (Sprint 15 refactoring).
 * Handles the AJAX endpoint for the bulk cleanup of a calendar's
 * appointments and renders the sidebar cleanup metabox with the counts.
 *
 * Since #1872 the cleanup is gated on its own capability,
 * `ffc_purge_appointments`, held by the Bookings Administrator tier only;
 * "Future" cancels and notifies instead of deleting; and every run writes
 * one activity-log row naming the records it reached.
 *
 * @package FreeFormCertificate\SelfScheduling
 * @since 4.12.16
 */

declare(strict_types=1);

namespace FreeFormCertificate\SelfScheduling;

use FreeFormCertificate\Core\ActivityLog;
use FreeFormCertificate\Core\Capabilities;
use FreeFormCertificate\Core\RequestInput;
use FreeFormCertificate\Repositories\AppointmentRepository;
use FreeFormCertificate\Repositories\CalendarRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handler for self scheduling cleanup operations.
 */
class SelfSchedulingCleanupHandler {

	/**
	 * The capability the bulk cleanup asks for (#1872).
	 *
	 * Narrower than `ffc_delete_appointments`, which a Bookings Manager holds
	 * to delete one appointment from the list.
	 */
	public const PURGE_CAP = 'ffc_purge_appointments';

	/**
	 * The four criteria the box offers, in the order it draws them.
	 */
	public const ACTIONS = array( 'cancelled', 'old', 'future', 'all' );

	/**
	 * Statuses a future appointment still holds a place in.
	 */
	private const ACTIVE_STATUSES = array( 'pending', 'confirmed', 'waitlist' );

	/**
	 * Register the AJAX hook and the class that marks the box as a danger zone.
	 */
	public function __construct() {
		add_action( 'wp_ajax_ffc_cleanup_appointments', array( $this, 'handle_cleanup_appointments' ) );
		add_filter( 'postbox_classes_ffc_self_scheduling_ffc_self_scheduling_cleanup', array( $this, 'add_danger_box_class' ) );
	}

	/**
	 * Whether the current user may run the cleanup.
	 *
	 * @return bool
	 */
	public static function can_purge(): bool {
		return Capabilities::current_user_can_admin_or( self::PURGE_CAP );
	}

	/**
	 * Mark the metabox as a danger zone for whoever can act in it.
	 *
	 * A user without the capability keeps the plain counts-only box.
	 *
	 * @param mixed $classes Classes WordPress puts on the postbox.
	 * @return array<int, string>
	 */
	public function add_danger_box_class( $classes ): array {
		$classes = is_array( $classes ) ? array_values( array_map( 'strval', $classes ) ) : array();
		if ( self::can_purge() ) {
			$classes[] = 'ffc-cleanup-danger-box';
		}
		return $classes;
	}

	/**
	 * Handle appointment cleanup AJAX request
	 *
	 * @return void
	 */
	public function handle_cleanup_appointments(): void {
		if ( ! wp_verify_nonce( RequestInput::get_post_string( 'nonce' ), 'ffc_cleanup_appointments_nonce' ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Security check failed', 'ffcertificate' ),
				)
			);
		}

		if ( ! self::can_purge() ) {
			wp_send_json_error(
				array(
					'message' => __( 'You do not have permission to perform this action', 'ffcertificate' ),
				)
			);
		}

		$calendar_id    = absint( RequestInput::get_post_string( 'calendar_id' ) );
		$cleanup_action = RequestInput::get_post_string( 'cleanup_action' );
		$expected       = RequestInput::get_post_string( 'expected' );

		if ( ! $calendar_id || ! $cleanup_action ) {
			wp_send_json_error(
				array(
					'message' => __( 'Invalid parameters', 'ffcertificate' ),
				)
			);
		}

		if ( ! in_array( $cleanup_action, self::ACTIONS, true ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Invalid cleanup action', 'ffcertificate' ),
				)
			);
		}

		$calendar = ( new CalendarRepository() )->findById( $calendar_id );

		if ( ! $calendar ) {
			wp_send_json_error(
				array(
					'message' => __( 'Calendar not found', 'ffcertificate' ),
				)
			);
		}

		$today = current_time( 'Y-m-d' );
		$repo  = new AppointmentRepository();

		if ( 'future' === $cleanup_action ) {
			$this->cancel_future( $repo, $calendar, $today, $expected );
			return;
		}

		$ids = $repo->findIdsForCleanup( $calendar_id, $cleanup_action, $today );
		$this->refuse_when_count_moved( $expected, count( $ids ) );
		$deleted = $repo->deleteByIds( $ids );
		$this->log_run( $cleanup_action, $calendar, $ids );

		switch ( $cleanup_action ) {
			case 'old':
				/* translators: %d: number of deleted past appointments */
				$message = sprintf( __( 'Successfully deleted %d past appointment(s).', 'ffcertificate' ), $deleted );
				break;
			case 'cancelled':
				/* translators: %d: number of deleted cancelled appointments */
				$message = sprintf( __( 'Successfully deleted %d cancelled appointment(s).', 'ffcertificate' ), $deleted );
				break;
			default:
				/* translators: %d: number of deleted appointments */
				$message = sprintf( __( 'Successfully deleted %d appointment(s).', 'ffcertificate' ), $deleted );
		}

		wp_send_json_success(
			array(
				'message' => $message,
				'deleted' => $deleted,
			)
		);
	}

	/**
	 * The "Future" cleanup: cancel, notify, never delete.
	 *
	 * @param AppointmentRepository $repo     Appointment repository.
	 * @param array<string, mixed>  $calendar Calendar row.
	 * @param string                $today    The site's today (`Y-m-d`).
	 * @param string                $expected The count the person confirmed.
	 * @return void
	 */
	private function cancel_future( AppointmentRepository $repo, array $calendar, string $today, string $expected ): void {
		$rows = $repo->findByCalendarAfterWithStatus( (int) $calendar['id'], $today, self::ACTIVE_STATUSES );
		$this->refuse_when_count_moved( $expected, count( $rows ) );

		$notify = self::sends_cancellation_email( $calendar );
		$ids    = $this->cancel_rows( $repo, $rows, $calendar, $notify );
		$this->log_run( 'future', $calendar, $ids );

		if ( $notify ) {
			$message = sprintf(
				/* translators: %d: number of cancelled future appointments */
				__( 'Cancelled %d future appointment(s). The people booked were sent the calendar\'s cancellation e-mail.', 'ffcertificate' ),
				count( $ids )
			);
		} else {
			$message = sprintf(
				/* translators: %d: number of cancelled future appointments */
				__( 'Cancelled %d future appointment(s). This calendar does not send cancellation e-mails, so nobody was notified.', 'ffcertificate' ),
				count( $ids )
			);
		}

		wp_send_json_success(
			array(
				'message'   => $message,
				'cancelled' => count( $ids ),
			)
		);
	}

	/**
	 * Refuse a run whose confirmed count no longer matches the calendar.
	 *
	 * The dialog states how many records go and, for the two widest actions,
	 * makes the person type that number. A booking made or cancelled after
	 * the page loaded would make that number false, so the run stops and asks
	 * for a reload rather than acting on records nobody confirmed. A request
	 * that sends no count (an older script) is not checked.
	 *
	 * @param string $expected The count the person confirmed, as posted.
	 * @param int    $actual   The count the criterion reaches now.
	 * @return void
	 */
	private function refuse_when_count_moved( string $expected, int $actual ): void {
		if ( '' === $expected || (int) $expected === $actual ) {
			return;
		}
		wp_send_json_error(
			array(
				'message' => __( 'The number of appointments changed since this page was loaded. Reload the page and try again.', 'ffcertificate' ),
			)
		);
	}

	/**
	 * Cancel the given future appointments and notify each person booked.
	 *
	 * Each row is cancelled the way an operator cancels one appointment
	 * (`cancelled_by`, a reason), and the calendar's own cancellation e-mail
	 * goes out through the shared pipeline when the calendar turns it on; the
	 * global kill switch is enforced inside that pipeline. The per-appointment
	 * `ffcertificate_appointment_cancelled` action is NOT fired: it would
	 * promote a waitlisted booking into a slot this same run is cancelling,
	 * and the run writes one summary row to the activity log instead of one
	 * row per appointment.
	 *
	 * @param AppointmentRepository            $repo     Appointment repository.
	 * @param array<int, array<string, mixed>> $rows     Appointments to cancel.
	 * @param array<string, mixed>             $calendar Calendar row.
	 * @param bool                             $notify   Whether the calendar sends the cancellation e-mail.
	 * @return array<int, int> Ids actually cancelled.
	 */
	private function cancel_rows( AppointmentRepository $repo, array $rows, array $calendar, bool $notify ): array {
		$reason = __( 'Cancelled by the calendar administrator.', 'ffcertificate' );
		$actor  = get_current_user_id();
		$ids    = array();

		foreach ( $rows as $row ) {
			$id = isset( $row['id'] ) ? (int) $row['id'] : 0;
			if ( $id <= 0 || false === $repo->cancel( $id, $actor > 0 ? $actor : null, $reason ) ) {
				continue;
			}
			$ids[] = $id;

			if ( $notify ) {
				$row['status']              = 'cancelled';
				$row['cancelled_by']        = $actor;
				$row['cancellation_reason'] = $reason;
				do_action( 'ffcertificate_self_scheduling_appointment_cancelled_email', $row, $calendar );
			}
		}

		return $ids;
	}

	/**
	 * Whether the calendar sends its cancellation e-mail.
	 *
	 * @param array<string, mixed> $calendar Calendar row.
	 * @return bool
	 */
	public static function sends_cancellation_email( array $calendar ): bool {
		$config = $calendar['email_config'] ?? array();
		if ( is_string( $config ) ) {
			$config = json_decode( $config, true );
		}
		return is_array( $config ) && ! empty( $config['send_cancellation_notification'] );
	}

	/**
	 * Write the one activity-log row a cleanup run leaves.
	 *
	 * `appointment_bulk_cancelled` for "Future", which cancels, and
	 * `appointment_bulk_purged` for the three that delete. The keys stay
	 * literal at the call so the activity-log label guard can read them.
	 *
	 * @param string               $criterion The cleanup criterion.
	 * @param array<string, mixed> $calendar  Calendar row.
	 * @param array<int, int>      $ids       Records the run reached.
	 * @return void
	 */
	private function log_run( string $criterion, array $calendar, array $ids ): void {
		$context = array(
			'calendar_id'     => (int) ( $calendar['id'] ?? 0 ),
			'calendar_title'  => (string) ( $calendar['title'] ?? '' ),
			'criterion'       => $criterion,
			'count'           => count( $ids ),
			'appointment_ids' => array_values( $ids ),
		);

		if ( 'future' === $criterion ) {
			ActivityLog::log( 'appointment_bulk_cancelled', ActivityLog::LEVEL_WARNING, $context, get_current_user_id() );
			return;
		}
		ActivityLog::log( 'appointment_bulk_purged', ActivityLog::LEVEL_WARNING, $context, get_current_user_id() );
	}

	/**
	 * Render cleanup appointments metabox
	 *
	 * Everyone who can open the calendar sees the counts. A holder of
	 * `ffc_purge_appointments` sees the box as a danger zone with the four
	 * actions: delete the cancelled, delete the past, cancel the future (and
	 * notify), and delete everything.
	 *
	 * @param object $post Post object.
	 * @phpstan-param \WP_Post $post
	 * @return void
	 */
	public function render_cleanup_metabox( object $post ): void {
		$calendar = ( new CalendarRepository() )->findByPostId( $post->ID );

		if ( ! $calendar ) {
			echo '<p>' . esc_html__( 'Calendar not found in database. Save the calendar first.', 'ffcertificate' ) . '</p>';
			return;
		}

		$calendar_id      = (int) $calendar['id'];
		$appointment_repo = new AppointmentRepository();
		$today            = current_time( 'Y-m-d' );

		$count_all       = $appointment_repo->count( array( 'calendar_id' => $calendar_id ) );
		$count_old       = $appointment_repo->countByCalendarBefore( $calendar_id, $today );
		$count_future    = $appointment_repo->countByCalendarAfter( $calendar_id, $today );
		$count_cancelled = $appointment_repo->count(
			array(
				'calendar_id' => $calendar_id,
				'status'      => 'cancelled',
			)
		);

		$can_purge = self::can_purge() && $count_all > 0;
		?>
		<div class="ffc-cleanup-appointments<?php echo $can_purge ? ' ffc-cleanup-danger' : ''; ?>"
			data-calendar-title="<?php echo esc_attr( (string) ( $calendar['title'] ?? '' ) ); ?>">
			<?php if ( $can_purge ) : ?>
				<div class="ffc-notice ffc-notice-danger" role="note">
					<span class="ffc-notice-icon ffc-icon-warning" aria-hidden="true"></span>
					<div class="ffc-notice-body">
						<p class="ffc-notice-text"><?php esc_html_e( 'Deletions are permanent. Every cleanup is recorded in the activity log.', 'ffcertificate' ); ?></p>
					</div>
				</div>
			<?php else : ?>
				<p class="description">
					<?php esc_html_e( 'Permanently delete appointments from this calendar. This action cannot be undone.', 'ffcertificate' ); ?>
				</p>
			<?php endif; ?>

			<div class="ffc-cleanup-stats">
				<table class="widefat">
					<tr>
						<td><strong><?php esc_html_e( 'Total:', 'ffcertificate' ); ?></strong></td>
						<td><?php echo esc_html( (string) $count_all ); ?></td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'Past:', 'ffcertificate' ); ?></strong></td>
						<td><?php echo esc_html( (string) $count_old ); ?></td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'Future:', 'ffcertificate' ); ?></strong></td>
						<td><?php echo esc_html( (string) $count_future ); ?></td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'Cancelled:', 'ffcertificate' ); ?></strong></td>
						<td><?php echo esc_html( (string) $count_cancelled ); ?></td>
					</tr>
				</table>
			</div>

			<?php
			if ( $can_purge ) :
				$count_active = count( $appointment_repo->findIdsForCleanup( $calendar_id, 'future', $today ) );
				$this->render_actions( $calendar, $count_cancelled, $count_old, $count_active, $count_all );
				wp_nonce_field( 'ffc_cleanup_appointments_nonce', 'ffc_cleanup_appointments_nonce' );
			elseif ( 0 === $count_all ) :
				?>
				<p><?php esc_html_e( 'No appointments to clean up.', 'ffcertificate' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Draw the danger-zone actions.
	 *
	 * Each button carries the count the dialog states and posts back, so the
	 * server can refuse a run whose number moved since the page loaded.
	 *
	 * @param array<string, mixed> $calendar        Calendar row.
	 * @param int                  $count_cancelled Cancelled appointments.
	 * @param int                  $count_old       Past appointments.
	 * @param int                  $count_active    Future appointments still holding a place.
	 * @param int                  $count_all       Every appointment.
	 * @return void
	 */
	private function render_actions( array $calendar, int $count_cancelled, int $count_old, int $count_active, int $count_all ): void {
		$calendar_id = (string) (int) $calendar['id'];
		$notify      = self::sends_cancellation_email( $calendar );
		?>
		<div class="ffc-cleanup-actions">
			<?php if ( $count_cancelled > 0 ) : ?>
				<button type="button"
						class="button ffc-cleanup-btn ffc-cleanup-btn-danger"
						data-action="cancelled"
						data-count="<?php echo esc_attr( (string) $count_cancelled ); ?>"
						data-calendar-id="<?php echo esc_attr( $calendar_id ); ?>">
					<span class="ffc-icon-delete" aria-hidden="true"></span>
					<?php
					/* translators: %d: number of cancelled appointments */
					printf( esc_html__( 'Delete cancelled (%d)', 'ffcertificate' ), intval( $count_cancelled ) );
					?>
				</button>
			<?php endif; ?>

			<?php if ( $count_old > 0 ) : ?>
				<button type="button"
						class="button ffc-cleanup-btn ffc-cleanup-btn-danger"
						data-action="old"
						data-count="<?php echo esc_attr( (string) $count_old ); ?>"
						data-calendar-id="<?php echo esc_attr( $calendar_id ); ?>">
					<span class="ffc-icon-delete" aria-hidden="true"></span>
					<?php
					/* translators: %d: number of past appointments */
					printf( esc_html__( 'Delete past (%d)', 'ffcertificate' ), intval( $count_old ) );
					?>
				</button>
			<?php endif; ?>

			<?php if ( $count_active > 0 ) : ?>
				<button type="button"
						class="button ffc-cleanup-btn ffc-cleanup-btn-caution"
						data-action="future"
						data-count="<?php echo esc_attr( (string) $count_active ); ?>"
						data-notify="<?php echo $notify ? '1' : '0'; ?>"
						data-calendar-id="<?php echo esc_attr( $calendar_id ); ?>">
					<span class="ffc-icon-cross" aria-hidden="true"></span>
					<?php
					if ( $notify ) {
						/* translators: %d: number of future appointments still booked */
						printf( esc_html__( 'Cancel future and notify (%d)', 'ffcertificate' ), intval( $count_active ) );
					} else {
						/* translators: %d: number of future appointments still booked */
						printf( esc_html__( 'Cancel future (%d)', 'ffcertificate' ), intval( $count_active ) );
					}
					?>
				</button>
			<?php endif; ?>

			<hr class="ffc-cleanup-separator" />

			<button type="button"
					class="button ffc-cleanup-btn ffc-cleanup-btn-solid"
					data-action="all"
					data-count="<?php echo esc_attr( (string) $count_all ); ?>"
					data-calendar-id="<?php echo esc_attr( $calendar_id ); ?>">
				<span class="ffc-icon-delete" aria-hidden="true"></span>
				<?php
				/* translators: %d: total number of appointments */
				printf( esc_html__( 'Delete all (%d)', 'ffcertificate' ), intval( $count_all ) );
				?>
			</button>
		</div>
		<?php
	}
}
