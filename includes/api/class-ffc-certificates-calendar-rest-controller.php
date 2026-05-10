<?php
/**
 * Certificates Calendar REST Controller
 *
 * Powers the Certificates Dashboard calendar view.
 *
 *   GET /ffc/v1/certificates/calendar?year=YYYY&month=MM
 *
 * Returns every form whose effective calendar date (GeoFence date_start, or
 * the post publication date as a fallback) falls within the requested month.
 *
 * @package FreeFormCertificate\API
 * @since 6.4.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\API;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST controller for the certificates dashboard calendar.
 */
class CertificatesCalendarRestController {

	private const POST_TYPE  = 'ffc_form';
	private const STATUSES   = array( 'publish', 'future', 'private' );
	private const META_KEY   = '_ffc_geofence_config';
	private const CAPABILITY = 'edit_others_posts';

	/**
	 * API namespace.
	 *
	 * @var string
	 */
	private string $namespace;

	/**
	 * @param string $namespace API namespace (e.g. ffc/v1).
	 */
	public function __construct( string $namespace ) {
		$this->namespace = $namespace;
	}

	/**
	 * Register routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/certificates/calendar',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_calendar' ),
				'permission_callback' => array( $this, 'permission_check' ),
				'args'                => array(
					'year'  => array(
						'required'          => true,
						'sanitize_callback' => 'absint',
						'validate_callback' => array( $this, 'validate_year' ),
					),
					'month' => array(
						'required'          => true,
						'sanitize_callback' => 'absint',
						'validate_callback' => array( $this, 'validate_month' ),
					),
				),
			)
		);
	}

	/**
	 * Capability check for the endpoint.
	 *
	 * @return bool
	 */
	public function permission_check(): bool {
		return current_user_can( self::CAPABILITY );
	}

	/**
	 * @param mixed $value Year value.
	 */
	public function validate_year( $value ): bool {
		$year = (int) $value;
		return $year >= 1970 && $year <= 2100;
	}

	/**
	 * @param mixed $value Month value.
	 */
	public function validate_month( $value ): bool {
		$month = (int) $value;
		return $month >= 1 && $month <= 12;
	}

	/**
	 * Build the calendar payload for the requested month.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response
	 */
	public function get_calendar( \WP_REST_Request $request ): \WP_REST_Response {
		$year  = (int) $request->get_param( 'year' );
		$month = (int) $request->get_param( 'month' );

		$month_start = sprintf( '%04d-%02d-01', $year, $month );
		$month_end   = gmdate( 'Y-m-t', (int) strtotime( $month_start ) );

		$query = new \WP_Query(
			array(
				'post_type'              => self::POST_TYPE,
				'post_status'            => self::STATUSES,
				'posts_per_page'         => -1,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
			)
		);

		$ids = $query->posts;
		if ( empty( $ids ) ) {
			return new \WP_REST_Response( array(), 200 );
		}

		// Prime the meta cache so the per-post get_post_meta calls below stay in memory.
		update_meta_cache( 'post', $ids );

		$events = array();
		foreach ( $ids as $id ) {
			$id   = (int) $id;
			$post = get_post( $id );
			if ( ! $post ) {
				continue;
			}

			$entry = $this->build_entry_for_post( $post, $month_start, $month_end );
			if ( null !== $entry ) {
				$events[] = $entry;
			}
		}

		return new \WP_REST_Response( $events, 200 );
	}

	/**
	 * Resolve the effective date for a form and decide if it belongs in the month.
	 *
	 * @param object $post        The form post (WP_Post or stdClass with the
	 *                            standard WP post fields). Typed as `object`
	 *                            so unit tests can pass a stdClass without
	 *                            stubbing WP_Post.
	 * @param string $month_start First day of the requested month (Y-m-d).
	 * @param string $month_end   Last day of the requested month (Y-m-d).
	 * @return array{id:int,title:string,date:string,source:string,edit_url:string,status:string}|null
	 */
	private function build_entry_for_post( object $post, string $month_start, string $month_end ): ?array {
		$resolved = $this->resolve_date( $post );
		if ( null === $resolved ) {
			return null;
		}

		[ $date, $source ] = $resolved;

		if ( $date < $month_start || $date > $month_end ) {
			return null;
		}

		return array(
			'id'       => (int) $post->ID,
			'title'    => html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ),
			'date'     => $date,
			'source'   => $source,
			'edit_url' => (string) get_edit_post_link( $post->ID, 'raw' ),
			'status'   => (string) $post->post_status,
		);
	}

	/**
	 * Resolve [date, source] for a form, falling back from geofence to post_date.
	 *
	 * @param object $post Form post (WP_Post-shaped object).
	 * @return array{0:string,1:string}|null
	 */
	private function resolve_date( object $post ): ?array {
		$config = get_post_meta( $post->ID, self::META_KEY, true );
		if ( is_array( $config ) ) {
			$start = isset( $config['date_start'] ) ? trim( (string) $config['date_start'] ) : '';
			if ( '' !== $start && $this->is_valid_date( $start ) ) {
				return array( $start, 'geofence' );
			}
		}

		$post_date = (string) $post->post_date;
		if ( '' === $post_date ) {
			return null;
		}

		$fallback = substr( $post_date, 0, 10 );
		if ( ! $this->is_valid_date( $fallback ) ) {
			return null;
		}

		return array( $fallback, 'post_date' );
	}

	/**
	 * @param string $date Date string in Y-m-d.
	 */
	private function is_valid_date( string $date ): bool {
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return false;
		}
		$parts = explode( '-', $date );
		return checkdate( (int) $parts[1], (int) $parts[2], (int) $parts[0] );
	}
}
