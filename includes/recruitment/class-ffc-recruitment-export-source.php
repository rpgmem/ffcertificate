<?php
/**
 * RecruitmentExportSource
 *
 * Batched {@see \FreeFormCertificate\Core\BatchedExportSourceInterface} for a
 * notice's definitive list and for its calls record (#2245). One source with
 * two kinds, `list` and `calls`, chosen at start and frozen in the job: the
 * two share the notice, the capability and every lookup, and differ only in
 * the rows they walk and the columns they write.
 *
 * Neither kind carries CPF, RF or e-mail, so nothing here needs the reveal
 * policy. Who made and who cancelled a call is written only when the user who
 * started the export may see it on screen (`ffc_view_recruitment_history`),
 * decided once at start.
 *
 * Gated by `ffc_export_recruitment` and a job nonce. The keyset is the row ID,
 * newest first, so the file does not follow the on-screen rank order.
 *
 * @package FreeFormCertificate\Recruitment
 * @since   6.44.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Recruitment;

use FreeFormCertificate\Core\BatchedExportSourceInterface;
use FreeFormCertificate\Core\Capabilities;
use FreeFormCertificate\Core\DateFormatter;
use FreeFormCertificate\Core\RequestInput;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A notice's definitive list or calls record, as a batched CSV source.
 */
class RecruitmentExportSource implements BatchedExportSourceInterface {

	/**
	 * Stable source type routed by the dispatcher / registry.
	 */
	public const TYPE = 'recruitment';

	/**
	 * Capability gating every phase.
	 */
	public const CAP = 'ffc_export_recruitment';

	/**
	 * Nonce action shared by start / batch / download.
	 */
	public const NONCE = 'ffc_recruitment_export';

	/**
	 * The two kinds of export.
	 */
	private const KINDS = array( 'list', 'calls' );

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function type(): string {
		return self::TYPE;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return void
	 */
	public function authorize_start(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! Capabilities::current_user_can_admin_or( self::CAP ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to export recruitment data.', 'ffcertificate' ) ), 403 );
		}
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $job Job state.
	 * @return void
	 */
	public function authorize_batch( array $job ): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! Capabilities::current_user_can_admin_or( self::CAP ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to export recruitment data.', 'ffcertificate' ) ), 403 );
		}
		if ( (int) get_current_user_id() !== (int) ( $job['user_id'] ?? -1 ) ) {
			wp_send_json_error( array( 'message' => __( 'Session mismatch.', 'ffcertificate' ) ), 403 );
		}
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $job Job state.
	 * @return void
	 */
	public function authorize_download( array $job ): void {
		if ( ! wp_verify_nonce( RequestInput::get_get_string( 'nonce' ), self::NONCE ) ) {
			wp_die( esc_html__( 'Security check failed.', 'ffcertificate' ) );
		}
		if ( ! Capabilities::current_user_can_admin_or( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to export recruitment data.', 'ffcertificate' ) );
		}
		if ( (int) get_current_user_id() !== (int) ( $job['user_id'] ?? -1 ) ) {
			wp_die( esc_html__( 'Session mismatch.', 'ffcertificate' ) );
		}
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, mixed>
	 */
	public function job_owner_fields(): array {
		return array( 'user_id' => get_current_user_id() );
	}

	/**
	 * {@inheritDoc}
	 *
	 * A notice that does not exist exports nothing: its ID is kept, so the
	 * count reads zero rather than another notice's rows.
	 *
	 * @return array<string, mixed>
	 */
	public function sanitize_filters(): array {
		// The nonce is verified in authorize_start(); RequestInput wraps + sanitizes.
		$kind = sanitize_key( RequestInput::get_post_string( 'kind' ) );
		return array(
			'notice_id' => max( 0, RequestInput::get_post_int( 'notice_id' ) ),
			'kind'      => in_array( $kind, self::KINDS, true ) ? $kind : 'list',
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $filters Filters.
	 * @return int
	 */
	public function count( array $filters ): int {
		$notice_id = (int) ( $filters['notice_id'] ?? 0 );
		if ( $notice_id <= 0 ) {
			return 0;
		}
		return 'calls' === ( $filters['kind'] ?? '' )
			? RecruitmentCallReader::count_for_notice( $notice_id )
			: RecruitmentClassificationRepository::count_definitive_for_export( $notice_id );
	}

	/**
	 * {@inheritDoc}
	 *
	 * Freezes whether the file names who made and cancelled each call, and the
	 * notice code for the filename.
	 *
	 * @param array<string, mixed> $filters Filters.
	 * @return array<string, mixed>
	 */
	public function build_context( array $filters ): array {
		$notice = RecruitmentNoticeReader::get_by_id( (int) ( $filters['notice_id'] ?? 0 ) );
		return array(
			'callers' => RecruitmentNoticeEditPage::can_see_callers(),
			'code'    => null === $notice ? '' : (string) $notice->code,
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $filters Filters.
	 * @param array<string, mixed> $context Frozen context.
	 * @return array<int, string>
	 */
	public function header( array $filters, array $context ): array {
		$callers = ! empty( $context['callers'] );
		if ( 'calls' === ( $filters['kind'] ?? '' ) ) {
			$cols = array(
				__( 'Called on', 'ffcertificate' ),
				__( 'Candidate', 'ffcertificate' ),
				__( 'Adjutancy', 'ffcertificate' ),
				__( 'Rank', 'ffcertificate' ),
				__( 'Date to assume', 'ffcertificate' ),
				__( 'Time to assume', 'ffcertificate' ),
			);
			if ( $callers ) {
				$cols[] = __( 'Called by', 'ffcertificate' );
			}
			$cols[] = __( 'Out of order', 'ffcertificate' );
			$cols[] = __( 'Out-of-order justification', 'ffcertificate' );
			$cols[] = __( 'Call', 'ffcertificate' );
			$cols[] = __( 'Cancelled at', 'ffcertificate' );
			if ( $callers ) {
				$cols[] = __( 'Cancelled by', 'ffcertificate' );
			}
			$cols[] = __( 'Cancellation reason', 'ffcertificate' );
			return $cols;
		}
		$cols = array(
			__( 'Rank', 'ffcertificate' ),
			__( 'Candidate', 'ffcertificate' ),
			__( 'Adjutancy', 'ffcertificate' ),
			__( 'Subscription', 'ffcertificate' ),
			__( 'Score', 'ffcertificate' ),
			__( 'Status', 'ffcertificate' ),
			__( 'Date to assume', 'ffcertificate' ),
			__( 'Time to assume', 'ffcertificate' ),
			__( 'Called on', 'ffcertificate' ),
		);
		if ( $callers ) {
			$cols[] = __( 'Called by', 'ffcertificate' );
		}
		return $cols;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $filters Filters.
	 * @param array<string, mixed> $context Frozen context.
	 * @return string
	 */
	public function filename( array $filters, array $context ): string {
		$code = sanitize_file_name( (string) ( $context['code'] ?? '' ) );
		$kind = 'calls' === ( $filters['kind'] ?? '' ) ? 'calls' : 'definitive-list';
		return 'recruitment-' . ( '' !== $code ? $code . '-' : '' ) . $kind . '-' . gmdate( 'Y-m-d' ) . '.csv';
	}

	/**
	 * {@inheritDoc}
	 *
	 * Loads the page, then everything its rows name in one query each:
	 * candidates, adjutancies, the active calls (list kind) and the users.
	 *
	 * @param array<string, mixed> $filters Filters.
	 * @param array<string, mixed> $context Frozen context.
	 * @param int                  $cursor  Exclusive upper-bound id.
	 * @param int                  $size    Page size.
	 * @return array<int, array<string, mixed>>
	 */
	public function fetch_page( array $filters, array $context, int $cursor, int $size ): array {
		$notice_id = (int) ( $filters['notice_id'] ?? 0 );
		$calls     = 'calls' === ( $filters['kind'] ?? '' );
		$rows      = $calls
			? RecruitmentCallReader::page_for_notice( $notice_id, $cursor, $size )
			: RecruitmentClassificationRepository::definitive_page_for_export( $notice_id, $cursor, $size );
		if ( empty( $rows ) ) {
			return array();
		}

		$names = array();
		foreach ( RecruitmentCandidateReader::get_by_ids( array_map( static fn( $r ) => (int) ( $r['candidate_id'] ?? 0 ), $rows ) ) as $cand ) {
			$names[ (int) $cand->id ] = (string) ( $cand->name ?? '' );
		}

		$active = array();
		if ( ! $calls ) {
			$ids = array();
			foreach ( $rows as $r ) {
				if ( 'empty' !== (string) ( $r['status'] ?? '' ) ) {
					$ids[] = (int) $r['id'];
				}
			}
			foreach ( RecruitmentCallReader::get_history_for_classifications( $ids ) as $call ) {
				$cls = (int) $call->classification_id;
				if ( null === $call->cancelled_at && ! isset( $active[ $cls ] ) ) {
					$active[ $cls ] = (array) $call;
				}
			}
		}

		$users = array();
		if ( ! empty( $context['callers'] ) ) {
			$ids = array();
			foreach ( $calls ? $rows : $active as $r ) {
				$ids[] = (int) ( $r['created_by'] ?? 0 );
				$ids[] = (int) ( $r['cancelled_by'] ?? 0 );
			}
			$users = self::user_names( $ids );
		}

		foreach ( $rows as $i => $r ) {
			$adjutancy                = RecruitmentAdjutancyReader::get_by_id( (int) ( $r['adjutancy_id'] ?? 0 ) );
			$rows[ $i ]['_candidate'] = $names[ (int) ( $r['candidate_id'] ?? 0 ) ] ?? '';
			$rows[ $i ]['_adjutancy'] = null === $adjutancy ? '' : (string) $adjutancy->name;
			$rows[ $i ]['_users']     = $users;
			$rows[ $i ]['_kind']      = $calls ? 'calls' : 'list';
			if ( ! $calls ) {
				$rows[ $i ]['_call'] = $active[ (int) $r['id'] ] ?? null;
			}
		}
		return $rows;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $row Row.
	 * @return int
	 */
	public function cursor_of( array $row ): int {
		return (int) ( $row['id'] ?? 0 );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $row     Raw row, as fetch_page() left it.
	 * @param array<string, mixed> $context Frozen context.
	 * @return array<int, string>
	 */
	public function format_row( array $row, array $context ): array {
		$callers = ! empty( $context['callers'] );
		/**
		 * Names of the users the page's calls name.
		 *
		 * @var array<int, string> $users
		 */
		$users = is_array( $row['_users'] ?? null ) ? $row['_users'] : array();

		if ( 'calls' === ( $row['_kind'] ?? '' ) ) {
			return $this->format_call( $row, $callers, $users );
		}

		/**
		 * The row's active call, if it has one.
		 *
		 * @var array<string, mixed>|null $call
		 */
		$call = is_array( $row['_call'] ?? null ) ? $row['_call'] : null;
		$out  = array(
			(string) ( $row['rank'] ?? '' ),
			(string) ( $row['_candidate'] ?? '' ),
			(string) ( $row['_adjutancy'] ?? '' ),
			RecruitmentPcdHasher::row_is_pcd( (object) $row ) ? __( 'PCD', 'ffcertificate' ) : _x( 'General', 'recruitment subscription type', 'ffcertificate' ),
			(string) ( $row['score'] ?? '' ),
			RecruitmentAdminPage::classification_status_label( (string) ( $row['status'] ?? '' ) ),
			null === $call ? '' : DateFormatter::format_wallclock_date( (string) ( $call['date_to_assume'] ?? '' ) ),
			null === $call ? '' : DateFormatter::format_wallclock_time( (string) ( $call['time_to_assume'] ?? '' ) ),
			null === $call ? '' : DateFormatter::format_datetime( (int) ( $call['called_at'] ?? 0 ) ),
		);
		if ( $callers ) {
			$out[] = null === $call ? '' : self::user_label( $users, (int) ( $call['created_by'] ?? 0 ) );
		}
		return $out;
	}

	/**
	 * One line of the calls record.
	 *
	 * @param array<string, mixed> $row     Call row with its classification's columns.
	 * @param bool                 $callers Whether to name who made and cancelled it.
	 * @param array<int, string>   $users   Names by user ID.
	 * @return array<int, string>
	 */
	private function format_call( array $row, bool $callers, array $users ): array {
		$cancelled = null !== ( $row['cancelled_at'] ?? null ) && '' !== (string) $row['cancelled_at'];
		$out       = array(
			DateFormatter::format_datetime( (int) ( $row['called_at'] ?? 0 ) ),
			(string) ( $row['_candidate'] ?? '' ),
			(string) ( $row['_adjutancy'] ?? '' ),
			(string) ( $row['rank'] ?? '' ),
			DateFormatter::format_wallclock_date( (string) ( $row['date_to_assume'] ?? '' ) ),
			DateFormatter::format_wallclock_time( (string) ( $row['time_to_assume'] ?? '' ) ),
		);
		if ( $callers ) {
			$out[] = self::user_label( $users, (int) ( $row['created_by'] ?? 0 ) );
		}
		$out[] = '1' === (string) ( $row['out_of_order'] ?? '0' ) ? __( 'Yes', 'ffcertificate' ) : __( 'No', 'ffcertificate' );
		$out[] = (string) ( $row['out_of_order_reason'] ?? '' );
		$out[] = $cancelled ? __( 'Cancelled', 'ffcertificate' ) : __( 'Active', 'ffcertificate' );
		$out[] = $cancelled ? DateFormatter::format_datetime( (int) $row['cancelled_at'] ) : '';
		if ( $callers ) {
			$out[] = $cancelled ? self::user_label( $users, (int) ( $row['cancelled_by'] ?? 0 ) ) : '';
		}
		$out[] = (string) ( $row['cancellation_reason'] ?? '' );
		return $out;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string               $job_id Job id.
	 * @param array<string, mixed> $job    Job state.
	 * @return array<string, mixed>
	 */
	public function extra_start_response( string $job_id, array $job ): array {
		unset( $job_id, $job );
		return array();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string               $job_id Job id.
	 * @param array<string, mixed> $job    Final job state.
	 * @return void
	 */
	public function on_complete( string $job_id, array $job ): void {
		unset( $job_id, $job );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $job Job state.
	 * @return void
	 */
	public function on_before_download( array $job ): void {
		unset( $job );
	}

	/**
	 * Display names of the given users, by ID, in one query.
	 *
	 * @param array<int, int> $ids User IDs.
	 * @return array<int, string>
	 */
	private static function user_names( array $ids ): array {
		$ids = array_values( array_unique( array_filter( $ids ) ) );
		if ( empty( $ids ) ) {
			return array();
		}
		$out = array();
		foreach ( get_users(
			array(
				'include' => $ids,
				'fields'  => array( 'ID', 'display_name' ),
			)
		) as $user ) {
			if ( is_object( $user ) && isset( $user->ID ) ) {
				$out[ (int) $user->ID ] = (string) ( $user->display_name ?? '' );
			}
		}
		return $out;
	}

	/**
	 * A user's display name, or a placeholder for an account that no longer
	 * exists.
	 *
	 * @param array<int, string> $names   Names by ID.
	 * @param int                $user_id User ID.
	 * @return string
	 */
	private static function user_label( array $names, int $user_id ): string {
		if ( isset( $names[ $user_id ] ) && '' !== $names[ $user_id ] ) {
			return $names[ $user_id ];
		}
		return $user_id > 0
			/* translators: %d: user ID */
			? sprintf( __( 'Deleted user #%d', 'ffcertificate' ), $user_id )
			: '';
	}
}
