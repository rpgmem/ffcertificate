<?php
/**
 * User Summary REST Controller
 *
 * Handles:
 *   GET /user/summary – Dashboard summary (certificates count, next appointment, etc.)
 *
 * @package FreeFormCertificate\API
 * @since 4.12.7  Extracted from UserDataRestController
 */

declare(strict_types=1);

namespace FreeFormCertificate\API;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST API controller for user summary endpoints.
 */
class UserSummaryRestController {

	use UserContextTrait;
	use \FreeFormCertificate\Core\DatabaseHelperTrait;

	/**
	 * API namespace
	 *
	 * @var non-falsy-string
	 */
	private string $namespace;

	/**
	 * Constructor.
	 *
	 * @param non-falsy-string $namespace Namespace.
	 */
	public function __construct( string $namespace ) {
		$this->namespace = $namespace;
	}

	/**
	 * Register routes
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/user/summary',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_user_summary' ),
				'permission_callback' => array( $this, 'rest_permission' ),
			)
		);
	}

	/**
	 * GET /user/summary
	 *
	 * Returns dashboard summary: total certificates, next appointment, upcoming group events.
	 *
	 * @since 4.9.8
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_user_summary( $request ) {
		try {
			$ctx     = $this->resolve_user_context( $request );
			$user_id = $ctx['user_id'];

			global $wpdb;

			$summary = array(
				'total_certificates'      => 0,
				'next_appointment'        => null,
				'upcoming_group_events'   => 0,
				'pending_reregistrations' => 0,
			);

			// Count certificates.
			if ( $this->user_has_capability( 'ffc_view_own_certificates', $user_id, $ctx['is_view_as'] ) ) {
				$table = \FreeFormCertificate\Repositories\SubmissionRepository::get_submissions_table();
				// Counted as the certificate list lists them (#1744).
				$hidden = \FreeFormCertificate\Core\SubmissionStatus::NOT_A_CERTIFICATE;
				// Without the history capability the list keeps one certificate
				// per form, so the card counts forms (#2246).
				$counted = $this->user_has_capability( 'ffc_view_own_certificate_history', $user_id, $ctx['is_view_as'] )
					? 'COUNT(*)'
					: 'COUNT(DISTINCT form_id)';
				// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- The IN list is `%s` repeated once per hidden status, so the sniff cannot count it.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Counts one user's rows in the plugin's own submissions table for the REST summary; the response is built once per request.
				$summary['total_certificates'] = (int) $wpdb->get_var(
					$wpdb->prepare(
						// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- `$counted` is one of the two literals above, never request data.
						'SELECT ' . $counted . ' FROM %i WHERE user_id = %d AND status NOT IN (' . implode( ', ', array_fill( 0, count( $hidden ), '%s' ) ) . ')',
						$table,
						$user_id,
						...$hidden
					)
				);
				// phpcs:enable WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
			}

			// Next appointment.
			if ( $this->user_has_capability( 'ffc_view_own_appointments', $user_id, $ctx['is_view_as'] ) ) {
				$apt_repo = new \FreeFormCertificate\Repositories\AppointmentRepository();
				$next     = $apt_repo->findNextUpcomingForUser( $user_id );

				if ( $next ) {
					// Category B wall-clock values: rendered as stored, never
					// shifted by the site timezone (#2246).
					$date_formatted = \FreeFormCertificate\Core\DateFormatter::format_wallclock_date( (string) $next['appointment_date'] );
					$time_formatted = ! empty( $next['start_time'] )
						? \FreeFormCertificate\Core\DateFormatter::format_wallclock_time( (string) $next['start_time'] )
						: '';

					$calendar      = ( new \FreeFormCertificate\Repositories\CalendarRepository() )->findById( (int) ( $next['calendar_id'] ?? 0 ) );
					$calendar_name = is_array( $calendar ) ? (string) ( $calendar['title'] ?? '' ) : '';

					$summary['next_appointment'] = array(
						'date'  => '' !== $date_formatted ? $date_formatted : (string) $next['appointment_date'],
						'time'  => $time_formatted,
						'title' => $calendar_name,
					);
				}
			}

			// Upcoming group events.
			if ( $this->user_has_capability( 'ffc_view_own_audience_bookings', $user_id, $ctx['is_view_as'] ) ) {
				$bookings_table = $wpdb->prefix . 'ffc_audience_bookings';

				// Schema guard stays in the controller; the service trusts
				// the schema. The CURDATE() filter the inline query used
				// is expressed here as a `start_date` of today (Y-m-d) so
				// the service stays portable.
				if ( self::table_exists( $bookings_table ) ) {
					$summary['upcoming_group_events'] = \FreeFormCertificate\Audience\AudienceQueryService::count_user_bookings(
						$user_id,
						array(
							'start_date'     => current_time( 'Y-m-d' ),
							'exclude_status' => 'cancelled',
						)
					);
				}
			}

			// Pending reregistrations.
			if ( class_exists( '\FreeFormCertificate\Reregistration\ReregistrationFrontend' ) ) {
				$rereg_items                        = \FreeFormCertificate\Reregistration\ReregistrationFrontend::get_user_reregistrations( $user_id );
				$summary['pending_reregistrations'] = count(
					array_filter(
						$rereg_items,
						function ( $r ) {
							return $r['can_submit'];
						}
					)
				);
			}

			return rest_ensure_response( $summary );

		} catch ( \Exception $e ) {
			// A failure is an error, never a row of zeros that reads as "you
			// have nothing" (#2246).
			return new \WP_Error( 'summary_error', __( 'Error loading the summary', 'ffcertificate' ), array( 'status' => 500 ) );
		}
	}
}
