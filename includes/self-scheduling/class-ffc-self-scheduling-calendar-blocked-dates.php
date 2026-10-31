<?php
/**
 * Calendar Blocked Dates
 *
 * The Blocked dates tab of the personal-calendar editor (#2244). The table
 * was read on every slot and never written: the repository could create a
 * block and nothing called it, so a calendar could not close one of its own
 * days. The tab lists the calendar's blocks, adds a full day (or a run of
 * days) or a time range on one date, and deletes one.
 *
 * Adding a block that reaches open appointments asks first: the request
 * answers with how many, the editor confirms, and the second request,
 * carrying that count, creates the block and cancels each appointment the
 * way the admin Cancel does, e-mail included when the calendar sends one.
 * A count that moved in between is refused, the shape the cleanup box uses.
 *
 * @package FreeFormCertificate\SelfScheduling
 * @since   6.43.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\SelfScheduling;

use FreeFormCertificate\Admin\AdminUI;
use FreeFormCertificate\Core\DateFormatter;
use FreeFormCertificate\Core\RequestInput;
use FreeFormCertificate\Repositories\AppointmentRepository;
use FreeFormCertificate\Repositories\BlockedDateRepository;
use FreeFormCertificate\Repositories\CalendarRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Per-calendar blocked dates: the editor tab and its two AJAX actions.
 */
class CalendarBlockedDates {

	/**
	 * Nonce action of both requests.
	 */
	public const NONCE = 'ffc_calendar_blocks';

	/**
	 * The longest run of days one full-day block may cover.
	 */
	public const MAX_DAYS = 366;

	/**
	 * Blocked-date repository.
	 *
	 * @var BlockedDateRepository
	 */
	private $blocks;

	/**
	 * Appointment repository.
	 *
	 * @var AppointmentRepository
	 */
	private $appointments;

	/**
	 * Calendar repository.
	 *
	 * @var CalendarRepository
	 */
	private $calendars;

	/**
	 * Admin actions (the cancel the list's Cancel runs).
	 *
	 * @var AppointmentAdminActions|null
	 */
	private $actions;

	/**
	 * Constructor.
	 *
	 * @param BlockedDateRepository|null   $blocks       Blocked dates.
	 * @param AppointmentRepository|null   $appointments Appointments.
	 * @param CalendarRepository|null      $calendars    Calendars.
	 * @param AppointmentAdminActions|null $actions      Cancel; built on first use when null.
	 */
	public function __construct( $blocks = null, $appointments = null, $calendars = null, $actions = null ) {
		$this->blocks       = $blocks ?? new BlockedDateRepository();
		$this->appointments = $appointments ?? new AppointmentRepository();
		$this->calendars    = $calendars ?? new CalendarRepository();
		$this->actions      = $actions;

		add_action( 'wp_ajax_ffc_calendar_block_add', array( $this, 'handle_add' ) );
		add_action( 'wp_ajax_ffc_calendar_block_delete', array( $this, 'handle_delete' ) );
	}

	/**
	 * Render the tab: the calendar's blocks and the fields that add one.
	 *
	 * The fields sit inside the post form but carry no `name` the save
	 * handler reads and no `required`, so saving the calendar ignores them;
	 * the editor script posts them on its own.
	 *
	 * @param int $post_id Calendar post id.
	 * @return void
	 */
	public static function render( int $post_id ): void {
		$calendar = ( new CalendarRepository() )->findByPostId( $post_id );
		if ( ! is_array( $calendar ) ) {
			echo '<p class="description">' . esc_html__( 'Save the calendar first; its blocked dates can be added once it exists.', 'ffcertificate' ) . '</p>';
			return;
		}

		$blocks = array_values(
			array_filter(
				( new BlockedDateRepository() )->findByCalendar( (int) $calendar['id'] ),
				static fn( array $b ): bool => in_array( (string) ( $b['block_type'] ?? '' ), array( 'full_day', 'time_range' ), true )
			)
		);
		usort( $blocks, static fn( array $a, array $b ): int => strcmp( (string) $a['start_date'] . (string) ( $a['start_time'] ?? '' ), (string) $b['start_date'] . (string) ( $b['start_time'] ?? '' ) ) );

		include FFC_PLUGIN_DIR . 'templates/admin/scheduling/blocked-dates-tab.php';
	}

	/**
	 * Label of a block's dates and times, for the list and the reason.
	 *
	 * @param array<string, mixed> $block Block row.
	 * @return string
	 */
	public static function describe( array $block ): string {
		$from = (string) ( $block['start_date'] ?? '' );
		$to   = (string) ( $block['end_date'] ?? '' );
		$text = DateFormatter::format_wallclock_date( $from );
		if ( 'time_range' === ( $block['block_type'] ?? '' ) ) {
			return $text . ' ' . DateFormatter::format_wallclock_time( (string) $block['start_time'] ) . '–' . DateFormatter::format_wallclock_time( (string) $block['end_time'] );
		}
		if ( '' !== $to && $to !== $from ) {
			$text .= ' – ' . DateFormatter::format_wallclock_date( $to );
		}
		return $text;
	}

	/**
	 * AJAX: add a block, asking first when it reaches open appointments.
	 *
	 * @return void
	 */
	public function handle_add(): void {
		$calendar = $this->authorize();
		$block    = self::parse_request();
		if ( is_wp_error( $block ) ) {
			wp_send_json_error( array( 'message' => $block->get_error_message() ), 400 );
		}

		$blocks = $this->blocks;
		if ( $blocks->isCovered( (int) $calendar['id'], $block['block_type'], $block['start_date'], $block['end_date'], $block['start_time'], $block['end_time'] ) ) {
			wp_send_json_error( array( 'message' => __( 'The calendar already blocks this date or time.', 'ffcertificate' ) ), 409 );
		}

		$affected = self::affected( $this->appointments, (int) $calendar['id'], $block );
		$count    = count( $affected );

		if ( $count > 0 && '1' !== RequestInput::get_post_string( 'confirm' ) ) {
			wp_send_json_success(
				array(
					'needs_confirm' => true,
					'count'         => $count,
					'notify'        => SelfSchedulingCleanupHandler::sends_cancellation_email( $calendar ),
					'title'         => (string) ( $calendar['title'] ?? '' ),
				)
			);
		}
		if ( $count > 0 && RequestInput::get_post_int( 'expected' ) !== $count ) {
			wp_send_json_error( array( 'message' => __( 'The number of appointments on this date changed since you confirmed. Review it and try again.', 'ffcertificate' ) ), 409 );
		}

		$created = 'time_range' === $block['block_type']
			? $blocks->createTimeRangeBlock( (int) $calendar['id'], $block['start_date'], (string) $block['start_time'], (string) $block['end_time'], $block['reason'] )
			: $blocks->createFullDayBlock( (int) $calendar['id'], $block['start_date'], $block['end_date'] === $block['start_date'] ? null : $block['end_date'], $block['reason'] );
		if ( ! $created ) {
			wp_send_json_error( array( 'message' => __( 'The block could not be saved.', 'ffcertificate' ) ), 500 );
		}

		// The block exists before anything is cancelled, so no booking can
		// take a freed slot in between.
		$cancelled = 0;
		$actions   = $this->actions ?? new AppointmentAdminActions( $this->appointments, $this->calendars );
		$reason    = self::cancellation_reason( $block );
		foreach ( $affected as $appointment ) {
			if ( true === $actions->cancel( (int) $appointment['id'], get_current_user_id(), $reason ) ) {
				++$cancelled;
			}
		}

		wp_send_json_success(
			array(
				'message' => 0 === $cancelled
					? __( 'Blocked.', 'ffcertificate' )
					/* translators: %d: number of appointments cancelled */
					: sprintf( _n( 'Blocked. %d appointment was cancelled.', 'Blocked. %d appointments were cancelled.', $cancelled, 'ffcertificate' ), $cancelled ),
			)
		);
	}

	/**
	 * AJAX: delete one of the calendar's blocks.
	 *
	 * @return void
	 */
	public function handle_delete(): void {
		$calendar = $this->authorize();
		if ( ! $this->blocks->deleteForCalendar( (int) $calendar['id'], RequestInput::get_post_int( 'block_id' ) ) ) {
			wp_send_json_error( array( 'message' => __( 'Block not found.', 'ffcertificate' ) ), 404 );
		}
		wp_send_json_success( array( 'message' => __( 'Block removed.', 'ffcertificate' ) ) );
	}

	/**
	 * The open appointments a block reaches (#2244): pending, confirmed or
	 * waitlisted on its days, and for a time range only those it overlaps.
	 *
	 * @param AppointmentRepository                                                                                                   $repo        Appointments.
	 * @param int                                                                                                                     $calendar_id Calendar.
	 * @param array{block_type: string, start_date: string, end_date: string, start_time: ?string, end_time: ?string, reason: string} $block       Parsed block.
	 * @return array<int, array<string, mixed>>
	 */
	public static function affected( AppointmentRepository $repo, int $calendar_id, array $block ): array {
		$rows = $repo->getAppointmentsByDateRange( $calendar_id, $block['start_date'], $block['end_date'], AppointmentHandler::CANCELLABLE_STATUSES );
		if ( 'time_range' !== $block['block_type'] ) {
			return $rows;
		}
		return array_values(
			array_filter(
				$rows,
				static fn( array $a ): bool => BlockedDateRepository::blocksMatch(
					array( $block ),
					(string) $a['appointment_date'],
					(string) $a['start_time'],
					isset( $a['end_time'] ) ? (string) $a['end_time'] : null
				)
			)
		);
	}

	/**
	 * The reason a block's cancellations carry: the block's own, or its dates.
	 *
	 * @param array{block_type: string, start_date: string, end_date: string, start_time: ?string, end_time: ?string, reason: string} $block Parsed block.
	 * @return string
	 */
	private static function cancellation_reason( array $block ): string {
		$label = '' !== $block['reason'] ? $block['reason'] : self::describe( $block );
		/* translators: %s: the block's reason, or its date and time when it has none */
		return sprintf( __( 'The calendar was closed: %s', 'ffcertificate' ), $label );
	}

	/**
	 * Read and check the block the request describes.
	 *
	 * @return array{block_type: string, start_date: string, end_date: string, start_time: ?string, end_time: ?string, reason: string}|\WP_Error
	 */
	public static function parse_request() {
		$type  = 'time_range' === RequestInput::get_post_string( 'block_type' ) ? 'time_range' : 'full_day';
		$start = RequestInput::get_post_string( 'start_date' );
		if ( ! DateFormatter::is_valid_date( $start ) ) {
			return new \WP_Error( 'invalid_date', __( 'Choose a valid date.', 'ffcertificate' ) );
		}

		$reason = mb_substr( sanitize_text_field( RequestInput::get_post_string( 'reason' ) ), 0, 255 );

		if ( 'time_range' === $type ) {
			$from = SlotGrid::normalize( RequestInput::get_post_string( 'start_time' ) );
			$to   = SlotGrid::normalize( RequestInput::get_post_string( 'end_time' ) );
			if ( null === $from || null === $to || $from >= $to ) {
				return new \WP_Error( 'invalid_time', __( 'Choose a start time before the end time.', 'ffcertificate' ) );
			}
			return array(
				'block_type' => $type,
				'start_date' => $start,
				'end_date'   => $start,
				'start_time' => $from,
				'end_time'   => $to,
				'reason'     => $reason,
			);
		}

		$end = RequestInput::get_post_string( 'end_date' );
		$end = '' === $end ? $start : $end;
		if ( ! DateFormatter::is_valid_date( $end ) || $end < $start ) {
			return new \WP_Error( 'invalid_range', __( 'The last day must be a valid date on or after the first.', 'ffcertificate' ) );
		}
		$days = (int) ( new \DateTimeImmutable( $start ) )->diff( new \DateTimeImmutable( $end ) )->days + 1;
		if ( $days > self::MAX_DAYS ) {
			/* translators: %d: maximum number of days */
			return new \WP_Error( 'range_too_long', sprintf( __( 'One block covers at most %d days.', 'ffcertificate' ), self::MAX_DAYS ) );
		}

		return array(
			'block_type' => $type,
			'start_date' => $start,
			'end_date'   => $end,
			'start_time' => null,
			'end_time'   => null,
			'reason'     => $reason,
		);
	}

	/**
	 * Check the nonce and the right to edit the calendar; answer the
	 * calendar row or stop the request.
	 *
	 * @return array<string, mixed>
	 */
	private function authorize(): array {
		if ( ! check_ajax_referer( self::NONCE, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed. Reload the page and try again.', 'ffcertificate' ) ), 403 );
		}
		$post_id = RequestInput::get_post_int( 'post_id' );
		if ( $post_id <= 0 || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to perform this action.', 'ffcertificate' ) ), 403 );
		}
		$calendar = $this->calendars->findByPostId( $post_id );
		if ( ! is_array( $calendar ) ) {
			wp_send_json_error( array( 'message' => __( 'Calendar not found.', 'ffcertificate' ) ), 404 );
		}
		return $calendar;
	}

	/**
	 * Nonce for the editor script.
	 *
	 * @return string
	 */
	public static function nonce(): string {
		return wp_create_nonce( self::NONCE );
	}

	/**
	 * Render the type control (full day or time range).
	 *
	 * @return void
	 */
	public static function render_type_control(): void {
		AdminUI::render_segmented(
			array(
				'name'    => 'ffc_block_type',
				'id'      => 'ffc-block-type',
				'legend'  => __( 'Block', 'ffcertificate' ),
				'options' => array(
					'full_day'   => __( 'Whole days', 'ffcertificate' ),
					'time_range' => __( 'Time range', 'ffcertificate' ),
				),
				'value'   => 'full_day',
				'size'    => 'sm',
			)
		);
	}
}
