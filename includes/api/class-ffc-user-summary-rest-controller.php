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
	 * @var string
	 */
	private string $namespace;

	/**
	 * Constructor.
	 *
	 * @param string $namespace Namespace.
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
				'permission_callback' => 'is_user_logged_in',
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
				$table = \FreeFormCertificate\Core\Utils::get_submissions_table();
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$summary['total_certificates'] = (int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(*) FROM %i WHERE user_id = %d AND status != 'trash'",
						$table,
						$user_id
					)
				);
			}

			// Next appointment.
			if ( $this->user_has_capability( 'ffc_view_own_appointments', $user_id, $ctx['is_view_as'] ) ) {
				$apt_repo = new \FreeFormCertificate\Repositories\AppointmentRepository();
				$next     = $apt_repo->findNextUpcomingForUser( $user_id );

				if ( $next ) {
					$timestamp      = strtotime( (string) $next['appointment_date'] );
					$time_formatted = '';
					if ( ! empty( $next['start_time'] ) ) {
						$time_ts        = strtotime( (string) $next['start_time'] );
						$time_formatted = ( false !== $time_ts ) ? \FreeFormCertificate\Core\DateFormatter::format_time( $time_ts ) : '';
					}

					$calendar      = ( new \FreeFormCertificate\Repositories\CalendarRepository() )->findById( (int) ( $next['calendar_id'] ?? 0 ) );
					$calendar_name = is_array( $calendar ) ? (string) ( $calendar['title'] ?? '' ) : '';

					$summary['next_appointment'] = array(
						'date'  => ( false !== $timestamp ) ? \FreeFormCertificate\Core\DateFormatter::format_date( $timestamp ) : (string) $next['appointment_date'],
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
			return rest_ensure_response(
				array(
					'total_certificates'      => 0,
					'next_appointment'        => null,
					'upcoming_group_events'   => 0,
					'pending_reregistrations' => 0,
				)
			);
		}
	}
}
