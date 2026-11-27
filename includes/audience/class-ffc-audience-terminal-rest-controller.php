<?php
/**
 * Audience Terminal REST Controller
 *
 * The routes that pair, list and end check-in terminals, and that hand an
 * operator their unlock code (#2744).
 *
 * @package FreeFormCertificate\Audience
 * @since 6.50.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Audience;

use FreeFormCertificate\Core\Capabilities;
use FreeFormCertificate\Core\ClientIpResolver;
use FreeFormCertificate\Core\DateFormatter;
use FreeFormCertificate\Core\IpAddress;
use FreeFormCertificate\Core\TerminalTargetKey;
use FreeFormCertificate\Frontend\CertificateFormTarget;
use FreeFormCertificate\Security\GeofenceLocationRegistry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `ffc/v1/terminal/*`.
 *
 * - `POST setup/events`, `POST setup` — an unpaired terminal is set up on
 *   the terminal itself, by event or by place (#2780, #2900). They run
 *   without a login on purpose: the terminal never holds a person's
 *   account. What guards them is the operator code.
 * - `GET terminals` — the operator's terminals; an administrator sees all.
 * - `DELETE terminals/{id}` — end one.
 * - `PUT terminals/{id}` — change the events it holds (#2746).
 * - `POST operator-code` — an operator's unlock code for a paired terminal.
 *
 * @phpstan-import-type TerminalRow from AudienceTerminalReader
 */
class AudienceTerminalRestController {

	/**
	 * REST namespace.
	 */
	public const NAMESPACE = 'ffc/v1';

	/**
	 * Route base.
	 */
	public const REST_BASE = 'terminal';

	/**
	 * The header a terminal sends its token in.
	 */
	public const TOKEN_HEADER = 'X-FFC-Terminal';

	/**
	 * The header a terminal sends the count of readings it holds unsent in
	 * (#2748).
	 */
	public const QUEUE_HEADER = 'X-FFC-Terminal-Queue';

	/**
	 * Register the routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		// Setting a terminal up on the terminal itself (#2780), the only way
		// a terminal is paired (#2900): the operator code is the guard.
		register_rest_route(
			self::NAMESPACE,
			'/' . self::REST_BASE . '/setup/events',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'setup_events' ),
				'permission_callback' => array( $this, 'check_pair_permission' ),
				'args'                => array(
					'code' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => array( self::class, 'sanitize_reading' ),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/' . self::REST_BASE . '/setup',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'setup' ),
				'permission_callback' => array( $this, 'check_pair_permission' ),
				'args'                => array(
					'code'     => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => array( self::class, 'sanitize_reading' ),
					),
					'device'   => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => array( AudiencePresenceRestController::class, 'sanitize_b64' ),
					),
					'bookings' => array(
						'type'              => 'array',
						'required'          => false,
						'items'             => array( 'type' => array( 'integer', 'string' ) ),
						'sanitize_callback' => array( self::class, 'sanitize_targets' ),
					),
					// A GPS location the terminal follows instead (#2900).
					'location' => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'label'    => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'auto_end' => array(
						'type'     => 'integer',
						'required' => false,
						'minimum'  => 1,
						'maximum'  => AudienceCheckinSettings::AUTO_END_HOURS_MAX,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/' . self::REST_BASE . '/bookings',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'list_bookings' ),
				'permission_callback' => array( $this, 'check_manage_permission' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/' . self::REST_BASE . '/terminals',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'list_terminals' ),
				'permission_callback' => array( $this, 'check_manage_permission' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/' . self::REST_BASE . '/terminals/(?P<id>\d+)',
			array(
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'revoke' ),
					'permission_callback' => array( $this, 'check_manage_permission' ),
					'args'                => array(
						'id' => array(
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
					),
				),
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_terminal' ),
					'permission_callback' => array( $this, 'check_manage_permission' ),
					'args'                => array(
						'id'       => array(
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
						'bookings' => array(
							'type'              => 'array',
							'required'          => true,
							'items'             => array( 'type' => array( 'integer', 'string' ) ),
							'sanitize_callback' => array( self::class, 'sanitize_targets' ),
						),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/' . self::REST_BASE . '/operator-code',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'operator_code' ),
				'permission_callback' => array( $this, 'check_operator_permission' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/' . self::REST_BASE . '/readings',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'reading' ),
				'permission_callback' => array( $this, 'check_terminal_permission' ),
				'args'                => array(
					'code'        => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => array( self::class, 'sanitize_reading' ),
					),
					// The code a certificate form's ticket asks for (#2891),
					// typed at the terminal after the QR.
					'ticket_code' => array(
						'type'              => 'string',
						'required'          => false,
						'default'           => '',
						'sanitize_callback' => array( self::class, 'sanitize_ticket_code' ),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/' . self::REST_BASE . '/sync',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'sync' ),
				'permission_callback' => array( $this, 'check_terminal_permission' ),
				'args'                => array(
					'readings' => array(
						'type'              => 'array',
						'required'          => true,
						'sanitize_callback' => array( self::class, 'sanitize_queue' ),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/' . self::REST_BASE . '/unlock',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'unlock' ),
				'permission_callback' => array( $this, 'check_terminal_permission' ),
				'args'                => array(
					'code' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => array( self::class, 'sanitize_reading' ),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/' . self::REST_BASE . '/self',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'schedule' ),
					'permission_callback' => array( $this, 'check_terminal_permission' ),
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'end_self' ),
					'permission_callback' => array( $this, 'check_terminal_permission' ),
					'args'                => array(
						'code' => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => array( self::class, 'sanitize_reading' ),
						),
					),
				),
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_self' ),
					'permission_callback' => array( $this, 'check_terminal_permission' ),
					'args'                => array(
						'code'     => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => array( self::class, 'sanitize_reading' ),
						),
						'bookings' => array(
							'type'              => 'array',
							'default'           => array(),
							'items'             => array( 'type' => array( 'integer', 'string' ) ),
							'sanitize_callback' => array( self::class, 'sanitize_targets' ),
						),
						// A GPS location the terminal follows from now on (#2900), instead of chosen events.
						'location' => array(
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/' . self::REST_BASE . '/self/events',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'self_events' ),
				'permission_callback' => array( $this, 'check_terminal_permission' ),
				'args'                => array(
					'code' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => array( self::class, 'sanitize_reading' ),
					),
				),
			)
		);
	}

	/**
	 * Who may pair, list and end terminals: a logged-in holder of
	 * {@see AudienceTerminalPairing::CAP}, carrying the REST nonce.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return bool
	 */
	public function check_manage_permission( \WP_REST_Request $request ): bool {
		return self::has_nonce( $request ) && Capabilities::current_user_can_admin_or( AudienceTerminalPairing::CAP );
	}

	/**
	 * Who may ask for an operator code: whoever runs a check-in.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return bool
	 */
	public function check_operator_permission( \WP_REST_Request $request ): bool {
		return self::has_nonce( $request ) && Capabilities::current_user_can_admin_or( 'ffc_checkin_audiences' );
	}

	/**
	 * What a paired terminal calls with its own token: the reading, the
	 * operator unlock and ending itself. The token is the whole guard; it
	 * names the terminal and the events it may check in.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return bool
	 */
	public function check_terminal_permission( \WP_REST_Request $request ): bool {
		return null !== self::terminal_of( $request );
	}

	/**
	 * Setup is open to an anonymous caller: the terminal has no account. The
	 * operator code is the guard, checked in {@see self::setup_events()} and
	 * {@see self::setup()}.
	 *
	 * @return bool
	 */
	public function check_pair_permission(): bool {
		return true;
	}

	/**
	 * The auto-end the operator chose (#2781), or null for the Check-in settings.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return int|null
	 */
	private static function auto_end_of( \WP_REST_Request $request ): ?int {
		$hours = $request->get_param( 'auto_end' );
		return is_numeric( $hours ) ? AudienceTerminalPairing::clean_auto_end( (int) $hours ) : null;
	}

	/**
	 * What a terminal keeps once it is set up on the terminal itself.
	 *
	 * @param array $result What {@see AudienceTerminalPairing::pair()} returned.
	 * @param int   $now    Unix time.
	 * @phpstan-param array{token: string, terminal_id: int, label: string, bookings: list<int|string>, expires_at: int} $result
	 * @return \WP_REST_Response
	 */
	private static function paired_response( array $result, int $now ): \WP_REST_Response {
		$result['keys']          = AudiencePresenceKeys::public_keys( $now );
		$result['expires_label'] = DateFormatter::format_datetime( $result['expires_at'] );
		return new \WP_REST_Response( $result, 201, array( 'Cache-Control' => 'no-store' ) );
	}

	/**
	 * The events an unpaired terminal may be set up with, on an operator's
	 * code (#2780), and the GPS locations it may follow (#2900).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function setup_events( \WP_REST_Request $request ) {
		$operator = self::queue_operator_of( $request );
		if ( is_wp_error( $operator ) ) {
			return $operator;
		}
		$user = get_userdata( $operator['user_id'] );
		return new \WP_REST_Response(
			array(
				'bookings'  => self::offered_bookings( time() ),
				// The GPS locations and what each holds, for "By place" (#2900).
				'locations' => AudienceTerminalLocation::locations( time() ),
				'max'       => AudienceTerminalPairing::MAX_BOOKINGS,
				'name'      => $user instanceof \WP_User ? AudiencePresenceAttestation::display_name( $user ) : '',
				'expires'   => $operator['expires'],
			),
			200,
			array( 'Cache-Control' => 'no-store' )
		);
	}

	/**
	 * Set an unpaired terminal up on the terminal itself (#2780): the
	 * operator chooses the events there and the terminal pairs at once. A
	 * pairing code is still created and spent, so the record, the limits and
	 * the activity log are the app's, but it never leaves the server.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function setup( \WP_REST_Request $request ) {
		$operator = self::queue_operator_of( $request );
		if ( is_wp_error( $operator ) ) {
			return $operator;
		}
		$now    = time();
		$label  = $request->get_param( 'label' );
		$device = $request->get_param( 'device' );
		$place  = $request->get_param( 'location' );
		$code   = AudienceTerminalPairing::create_code( $operator['user_id'], self::sanitize_targets( $request->get_param( 'bookings' ) ), is_string( $label ) ? $label : '', $now, self::auto_end_of( $request ), is_string( $place ) ? sanitize_text_field( $place ) : '' );
		if ( is_wp_error( $code ) ) {
			return $code;
		}
		$result = AudienceTerminalPairing::pair( $code['code'], is_string( $device ) ? $device : '', IpAddress::fingerprint( ClientIpResolver::resolve() ), $now );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return self::paired_response( $result, $now );
	}

	/**
	 * The events a terminal may serve: active bookings that end within the
	 * next {@see AudienceTerminalPairing::DAYS_AHEAD} days, soonest first,
	 * with how many terminals already hold each.
	 *
	 * @return \WP_REST_Response
	 */
	public function list_bookings(): \WP_REST_Response {
		return new \WP_REST_Response(
			array(
				'bookings' => self::offered_bookings( time() ),
				'max'      => AudienceTerminalPairing::MAX_BOOKINGS,
			),
			200,
			array( 'Cache-Control' => 'no-store' )
		);
	}

	/**
	 * The events a terminal may serve, as the pairing screen and the
	 * Terminals screen list them (#2748).
	 *
	 * @param int $now Unix time.
	 * Certificate forms with the terminal on join the list (#2900), so the
	 * same choice reaches both kinds of item; like the events, only what is
	 * open today or later, within the same days, ever shows.
	 *
	 * @return list<array{id: int|string, kind: string, description: string, place: string, place_id: int, location_ids: list<string>, when: string, starts: int, ends: int, ends_time: string, terminals: int, full: bool}>
	 */
	public static function offered_bookings( int $now ): array {
		$today  = wp_date( 'Y-m-d', $now );
		$last   = wp_date( 'Y-m-d', $now + AudienceTerminalPairing::DAYS_AHEAD * DAY_IN_SECONDS );
		$rows   = AudienceBookingReader::get_all(
			array(
				'start_date' => is_string( $today ) ? $today : '',
				'end_date'   => is_string( $last ) ? $last : '',
				'status'     => 'active',
				'limit'      => 100,
			)
		);
		$limit  = AudienceTerminalPairing::limits()['booking'];
		$events = array();
		foreach ( $rows as $booking ) {
			if ( ! AudienceCheckinPolicy::terminal_allowed( $booking ) ) {
				continue;
			}
			$id  = (int) $booking->id;
			$end = AudienceTerminalPairing::booking_end( $id );
			if ( null === $end || $end < $now ) {
				continue;
			}
			$held     = AudienceTerminalReader::count_active_for_target( $id, $now );
			$events[] = array(
				'id'           => $id,
				'kind'         => 'event',
				'description'  => (string) $booking->description,
				'place'        => (string) ( $booking->environment_name ?? '' ),
				'place_id'     => (int) ( $booking->environment_id ?? 0 ),
				'location_ids' => self::environment_location( (int) ( $booking->environment_id ?? 0 ) ),
				'when'         => self::when( $booking ),
				'starts'       => self::starts( $booking ),
				'ends'         => $end,
				'ends_time'    => DateFormatter::format_time( $end ),
				'terminals'    => $held,
				'full'         => $held >= $limit,
			);
		}
		foreach ( CertificateFormTarget::offered( $now, AudienceTerminalPairing::DAYS_AHEAD ) as $form ) {
			$key      = TerminalTargetKey::of( CertificateFormTarget::TYPE, $form['id'] );
			$held     = AudienceTerminalReader::count_active_for_target( $key, $now );
			$names    = array_map( static fn( array $location ): string => (string) ( $location['name'] ?? '' ), GeofenceLocationRegistry::get_by_ids( $form['location_ids'] ) );
			$events[] = array(
				'id'           => $key,
				'kind'         => 'form',
				'description'  => $form['title'],
				'place'        => implode( ', ', array_filter( $names ) ),
				'place_id'     => 0,
				'location_ids' => $form['location_ids'],
				/* translators: 1: day, 2: opening time, 3: closing time */
				'when'         => sprintf( __( '%1$s, %2$s–%3$s', 'ffcertificate' ), DateFormatter::format_date( $form['opens'] ), DateFormatter::format_time( $form['opens'] ), DateFormatter::format_time( $form['closes'] ) ),
				'starts'       => $form['opens'],
				'ends'         => $form['closes'],
				'ends_time'    => DateFormatter::format_time( $form['closes'] ),
				'terminals'    => $held,
				'full'         => $held >= $limit,
			);
		}
		usort( $events, static fn( array $a, array $b ): int => $a['starts'] <=> $b['starts'] );
		return $events;
	}

	/**
	 * The GPS location an environment is linked to (#2900), as a list.
	 *
	 * @param int $environment_id Environment id.
	 * @return list<string>
	 */
	private static function environment_location( int $environment_id ): array {
		$environment = $environment_id > 0 ? AudienceEnvironmentRepository::get_by_id( $environment_id ) : null;
		$location    = null === $environment ? '' : (string) ( $environment->location_id ?? '' );
		return '' === $location ? array() : array( $location );
	}

	/**
	 * When a booking starts, as a moment, for ordering the list.
	 *
	 * @param object $booking Booking row.
	 * @return int Unix time; 0 when the row carries no usable date.
	 */
	private static function starts( object $booking ): int {
		$day  = is_string( $booking->booking_date ?? null ) ? $booking->booking_date : '';
		$time = is_string( $booking->start_time ?? null ) && '' !== $booking->start_time ? $booking->start_time : '00:00:00';
		try {
			return ( new \DateTimeImmutable( $day . ' ' . $time, wp_timezone() ) )->getTimestamp();
		} catch ( \Exception $e ) {
			unset( $e );
			return 0;
		}
	}

	/**
	 * A booking's days and hours, in the site's formats.
	 *
	 * @param object $booking Booking row.
	 * @return string
	 */
	private static function when( object $booking ): string {
		$first = (string) ( $booking->booking_date ?? '' );
		$last  = is_string( $booking->end_date ?? null ) && '' !== $booking->end_date ? $booking->end_date : $first;
		// The days and hours are wall-clock values (CLAUDE.md "Date / time
		// storage convention"): read through the site's timezone they moved
		// a day and three hours back on a UTC-3 site (#2836).
		$days = $first === $last
			? DateFormatter::format_wallclock_date( $first )
			/* translators: 1: first day, 2: last day */
			: sprintf( __( '%1$s to %2$s', 'ffcertificate' ), DateFormatter::format_wallclock_date( $first ), DateFormatter::format_wallclock_date( $last ) );
		/* translators: 1: day or days, 2: start time, 3: end time */
		return sprintf( __( '%1$s, %2$s–%3$s', 'ffcertificate' ), $days, DateFormatter::format_wallclock_time( (string) ( $booking->start_time ?? '' ) ), DateFormatter::format_wallclock_time( (string) ( $booking->end_time ?? '' ) ) );
	}

	/**
	 * The operator's active terminals; an administrator sees every one.
	 *
	 * @return \WP_REST_Response
	 */
	public function list_terminals(): \WP_REST_Response {
		$now   = time();
		$all   = current_user_can( 'manage_options' );
		$rows  = AudienceTerminalReader::active_terminals( $now, $all ? null : get_current_user_id() );
		$items = array_map( array( self::class, 'present' ), $rows );
		return new \WP_REST_Response(
			array(
				'terminals' => $items,
				'all'       => $all,
				'limits'    => AudienceTerminalPairing::limits(),
			),
			200,
			array( 'Cache-Control' => 'no-store' )
		);
	}

	/**
	 * End a terminal.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function revoke( \WP_REST_Request $request ) {
		$id     = absint( $request->get_param( 'id' ) );
		$result = AudienceTerminalPairing::revoke( $id, get_current_user_id(), current_user_can( 'manage_options' ), time() );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new \WP_REST_Response( array( 'revoked' => $id ), 200 );
	}

	/**
	 * Change the events a terminal holds.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update_terminal( \WP_REST_Request $request ) {
		$result = AudienceTerminalPairing::update_bookings(
			absint( $request->get_param( 'id' ) ),
			self::sanitize_targets( $request->get_param( 'bookings' ) ),
			get_current_user_id(),
			current_user_can( 'manage_options' ),
			time()
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new \WP_REST_Response( self::present( $result ), 200, array( 'Cache-Control' => 'no-store' ) );
	}

	/**
	 * What the calling terminal holds now, and when each event takes
	 * readings (#2746). The terminal asks for this on its sync timer, so an
	 * event added or removed elsewhere reaches it there.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function schedule( \WP_REST_Request $request ) {
		$terminal = self::terminal_of( $request );
		if ( null === $terminal ) {
			return self::not_paired();
		}
		$now = time();
		// A terminal paired to a location takes what the location holds
		// now (#2900): an item created after the pairing joins here.
		$terminal = AudienceTerminalLocation::follow( $terminal, $now );
		return new \WP_REST_Response(
			array(
				'label'         => $terminal['label'],
				'location'      => $terminal['location_id'],
				// Its name, for the operator menu: a terminal that follows a place has no queue to edit.
				'location_name' => self::location_name( $terminal['location_id'] ),
				'bookings'      => $terminal['targets'],
				'expires_at'    => $terminal['expires_at'],
				'expires_label' => DateFormatter::format_datetime( $terminal['expires_at'] ),
				'now'           => $now,
				'schedule'      => AudienceTerminalSchedule::plan( $terminal, $now ),
			),
			200,
			array( 'Cache-Control' => 'no-store' )
		);
	}

	/**
	 * An operator's unlock code.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function operator_code() {
		$code = AudienceOperatorCode::issue( get_current_user_id(), time() );
		if ( null === $code ) {
			return new \WP_Error( 'ffc_operator_code', __( 'The site could not sign an operator code. Try again.', 'ffcertificate' ), array( 'status' => 500 ) );
		}
		return new \WP_REST_Response( $code, 200, array( 'Cache-Control' => 'no-store' ) );
	}

	/**
	 * Read a presence code.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function reading( \WP_REST_Request $request ) {
		$terminal = self::terminal_of( $request );
		if ( null === $terminal ) {
			return self::not_paired();
		}
		$code   = $request->get_param( 'code' );
		$ticket = $request->get_param( 'ticket_code' );
		return new \WP_REST_Response( AudienceTerminalReading::read( $terminal, is_string( $code ) ? $code : '', time(), is_string( $ticket ) ? $ticket : '' ), 200, array( 'Cache-Control' => 'no-store' ) );
	}

	/**
	 * Send the readings the terminal queued offline.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function sync( \WP_REST_Request $request ) {
		// A revoked terminal may still send what it read before the
		// revocation (#2802); the answer tells it that it ended.
		$terminal = self::terminal_of( $request, true );
		if ( null === $terminal ) {
			return self::not_paired();
		}
		$readings = self::sanitize_queue( $request->get_param( 'readings' ) );
		return new \WP_REST_Response(
			array(
				'results' => (object) AudienceTerminalReading::sync( $terminal, $readings, time() ),
				'revoked' => null !== $terminal['revoked_at'],
			),
			200,
			array( 'Cache-Control' => 'no-store' )
		);
	}

	/**
	 * Unlock the terminal's operator menu with an operator code.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function unlock( \WP_REST_Request $request ) {
		$operator = self::operator_of( $request );
		if ( is_wp_error( $operator ) ) {
			return $operator;
		}
		$user = get_userdata( $operator['user_id'] );
		return new \WP_REST_Response(
			array(
				'name'     => $user instanceof \WP_User ? AudiencePresenceAttestation::display_name( $user ) : '',
				'expires'  => $operator['expires'],
				'may_edit' => self::may_edit_queue( $operator['user_id'] ),
			),
			200,
			array( 'Cache-Control' => 'no-store' )
		);
	}

	/**
	 * End the calling terminal, on an operator's code. The ending is
	 * attributed to that operator, as when they end it from the app.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function end_self( \WP_REST_Request $request ) {
		$terminal = self::terminal_of( $request );
		if ( null === $terminal ) {
			return self::not_paired();
		}
		$operator = self::operator_of( $request );
		if ( is_wp_error( $operator ) ) {
			return $operator;
		}
		$result = AudienceTerminalPairing::revoke( $terminal['id'], $operator['user_id'], true, time() );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new \WP_REST_Response( array( 'revoked' => $terminal['id'] ), 200 );
	}

	/**
	 * Whether an operator may change a terminal's events from the terminal
	 * itself (#2780): whoever may pair terminals, as in the app.
	 *
	 * @param int $user_id Operator.
	 * @return bool
	 */
	public static function may_edit_queue( int $user_id ): bool {
		return user_can( $user_id, AudienceTerminalPairing::CAP ) || user_can( $user_id, 'manage_options' );
	}

	/**
	 * The operator a request's code belongs to, when they may also change
	 * the terminal's events.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array{user_id: int, expires: int}|\WP_Error
	 */
	private static function queue_operator_of( \WP_REST_Request $request ) {
		$operator = self::operator_of( $request );
		if ( is_wp_error( $operator ) ) {
			return $operator;
		}
		if ( ! self::may_edit_queue( $operator['user_id'] ) ) {
			return new \WP_Error( 'ffc_operator_queue_not_allowed', __( 'This operator may not change the terminal\'s events.', 'ffcertificate' ), array( 'status' => 403 ) );
		}
		return $operator;
	}

	/**
	 * The events the calling terminal could take, for its operator menu
	 * (#2780), on an operator's code.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function self_events( \WP_REST_Request $request ) {
		$terminal = self::terminal_of( $request );
		if ( null === $terminal ) {
			return self::not_paired();
		}
		$operator = self::queue_operator_of( $request );
		if ( is_wp_error( $operator ) ) {
			return $operator;
		}
		return new \WP_REST_Response(
			array(
				'bookings'  => self::offered_bookings( time() ),
				// The GPS locations and what each holds, for "By place" (#2900).
				'locations' => AudienceTerminalLocation::locations( time() ),
				'max'       => AudienceTerminalPairing::MAX_BOOKINGS,
			),
			200,
			array( 'Cache-Control' => 'no-store' )
		);
	}

	/**
	 * Change the calling terminal's events from its operator menu (#2780),
	 * under the rules the app's Change events follows. Any operator who may
	 * pair terminals may do it at the terminal, as any operator may end it
	 * there; the change is logged in that operator's name.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update_self( \WP_REST_Request $request ) {
		$terminal = self::terminal_of( $request );
		if ( null === $terminal ) {
			return self::not_paired();
		}
		$operator = self::queue_operator_of( $request );
		if ( is_wp_error( $operator ) ) {
			return $operator;
		}
		$now    = time();
		$place  = $request->get_param( 'location' );
		$result = is_string( $place ) && '' !== $place
			? AudienceTerminalPairing::follow_location( $terminal['id'], $place, $operator['user_id'], $now )
			: AudienceTerminalPairing::update_bookings( $terminal['id'], self::sanitize_targets( $request->get_param( 'bookings' ) ), $operator['user_id'], true, $now );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new \WP_REST_Response(
			array(
				'label'         => $result['label'],
				'location'      => $result['location_id'],
				'location_name' => self::location_name( $result['location_id'] ),
				'bookings'      => $result['targets'],
				'expires_at'    => $result['expires_at'],
				'expires_label' => DateFormatter::format_datetime( $result['expires_at'] ),
				'now'           => $now,
				'schedule'      => AudienceTerminalSchedule::plan( $result, $now ),
			),
			200,
			array( 'Cache-Control' => 'no-store' )
		);
	}

	/**
	 * The operator a request's code belongs to, when they may run check-ins.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array{user_id: int, expires: int}|\WP_Error
	 */
	private static function operator_of( \WP_REST_Request $request ) {
		$code     = $request->get_param( 'code' );
		$operator = AudienceOperatorCode::verify( is_string( $code ) ? $code : '', time() );
		if ( is_wp_error( $operator ) ) {
			return new \WP_Error( $operator->get_error_code(), $operator->get_error_message(), array( 'status' => 403 ) );
		}
		if ( ! user_can( $operator['user_id'], 'ffc_checkin_audiences' ) && ! user_can( $operator['user_id'], 'manage_options' ) ) {
			return new \WP_Error( 'ffc_operator_not_allowed', __( 'This operator may no longer run check-ins.', 'ffcertificate' ), array( 'status' => 403 ) );
		}
		return $operator;
	}

	/**
	 * The answer for a token that stopped working between the permission
	 * check and the handler.
	 *
	 * @return \WP_Error
	 */
	private static function not_paired(): \WP_Error {
		return new \WP_Error( 'ffc_terminal_unpaired', __( 'This terminal is no longer paired. An operator pairs it again at the lock.', 'ffcertificate' ), array( 'status' => 401 ) );
	}

	/**
	 * The readings of a queue, each `{id, code, at}`: an id of the terminal's
	 * own (letters, digits, `-` and `_`, at most 40), the code as read and
	 * the moment it was read. Anything else is dropped, and at most
	 * {@see AudienceTerminalReading::SYNC_BATCH} are kept.
	 *
	 * @param mixed $value Raw value.
	 * @return list<array{id: string, code: string, at: int}>
	 */
	public static function sanitize_queue( $value ): array {
		$out = array();
		foreach ( is_array( $value ) ? $value : array() as $reading ) {
			if ( ! is_array( $reading ) || ! isset( $reading['id'], $reading['code'], $reading['at'] ) || ! is_numeric( $reading['at'] ) ) {
				continue;
			}
			$id   = is_string( $reading['id'] ) ? (string) preg_replace( '/[^A-Za-z0-9_-]/', '', $reading['id'] ) : '';
			$code = self::sanitize_reading( $reading['code'] );
			if ( '' === $id || strlen( $id ) > 40 || '' === $code ) {
				continue;
			}
			$out[] = array(
				'id'   => $id,
				'code' => $code,
				'at'   => (int) $reading['at'],
			);
			if ( count( $out ) >= AudienceTerminalReading::SYNC_BATCH ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * A ticket code as a certificate form stores it: letters, digits and
	 * hyphens, upper-cased, at most 40 characters.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function sanitize_ticket_code( $value ): string {
		return is_string( $value ) ? substr( (string) preg_replace( '/[^A-Z0-9-]/', '', strtoupper( trim( $value ) ) ), 0, 40 ) : '';
	}

	/**
	 * Keep the characters of a QR code read in alphanumeric mode, which is
	 * what both the presence code and the operator code are made of. Base45
	 * never ends in a space (the last character of a group is at most `W`),
	 * so trimming the reader's line end cannot cut a code.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function sanitize_reading( $value ): string {
		return is_string( $value ) ? substr( (string) preg_replace( '/[^0-9A-Z $%*+\-.\/:]/', '', strtoupper( trim( $value ) ) ), 0, 4096 ) : '';
	}

	/**
	 * The terminal a request's token belongs to.
	 *
	 * @param \WP_REST_Request $request       Request.
	 * @param bool             $to_send_queue Whether the request only sends the queue, which a revoked terminal may still do (#2802).
	 * @return TerminalRow|null
	 */
	public static function terminal_of( \WP_REST_Request $request, bool $to_send_queue = false ): ?array {
		$token  = (string) $request->get_header( self::TOKEN_HEADER );
		$queued = (string) $request->get_header( self::QUEUE_HEADER );
		return '' === $token ? null : AudienceTerminalPairing::authenticate( $token, time(), 1 === preg_match( '/^\d{1,6}$/', $queued ) ? (int) $queued : null, $to_send_queue );
	}

	/**
	 * What the list shows of a terminal: never its token hash or device hash.
	 *
	 * The `*_label` keys are the same instants in the site's formats, so the
	 * app never formats a date itself.
	 *
	 * @param array $terminal Terminal row.
	 * @phpstan-param TerminalRow $terminal
	 * @return array{id: int, label: string, bookings: list<int|string>, location: string, paired_by: int|null, paired_at: int, expires_at: int, last_seen_at: int|null, paired_label: string, expires_label: string, last_seen_label: string}
	 */
	public static function present( array $terminal ): array {
		return array(
			'id'              => $terminal['id'],
			'label'           => $terminal['label'],
			'bookings'        => $terminal['targets'],
			// The GPS location a terminal follows (#2900), by name; '' for one paired by event.
			'location'        => self::location_name( $terminal['location_id'] ),
			'paired_by'       => $terminal['paired_by'],
			'paired_at'       => $terminal['paired_at'],
			'expires_at'      => $terminal['expires_at'],
			'last_seen_at'    => $terminal['last_seen_at'],
			'paired_label'    => DateFormatter::format_datetime( $terminal['paired_at'] ),
			'expires_label'   => DateFormatter::format_datetime( $terminal['expires_at'] ),
			'last_seen_label' => null === $terminal['last_seen_at'] ? '' : DateFormatter::format_datetime( $terminal['last_seen_at'] ),
		);
	}

	/**
	 * A GPS location's name, or its id when it is no longer listed.
	 *
	 * @param string $location_id GPS location id, or ''.
	 * @return string
	 */
	public static function location_name( string $location_id ): string {
		if ( '' === $location_id ) {
			return '';
		}
		$found = GeofenceLocationRegistry::get_by_ids( array( $location_id ) );
		return array() === $found ? $location_id : (string) ( $found[0]['name'] ?? $location_id );
	}

	/**
	 * Target keys from a list (#2890): a booking id, or `type:id` for any
	 * other target; anything else is dropped.
	 *
	 * @param mixed $value Raw value.
	 * @return list<int|string>
	 */
	public static function sanitize_targets( $value ): array {
		return TerminalTargetKey::clean( is_array( $value ) ? $value : array() );
	}

	/**
	 * Whether a logged-in caller sent the REST nonce.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return bool
	 */
	private static function has_nonce( \WP_REST_Request $request ): bool {
		if ( ! is_user_logged_in() ) {
			return false;
		}
		$nonce = (string) $request->get_header( 'X-WP-Nonce' );
		return '' !== $nonce && false !== wp_verify_nonce( $nonce, 'wp_rest' );
	}
}
