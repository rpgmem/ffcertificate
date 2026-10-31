<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\API\AppointmentRestController;
use FreeFormCertificate\Core\ActivityLog;
use FreeFormCertificate\Core\Encryption;
use FreeFormCertificate\Core\IpAddress;
use FreeFormCertificate\Core\SensitiveFieldRegistry;
use FreeFormCertificate\Security\RateLimitChecker;
use FreeFormCertificate\Tests\Support\Identifiers;

/**
 * Tests for AppointmentRestController: route registration, create_appointment,
 * get_appointment, cancel_appointment, and check_appointment_access endpoints.
 *
 * Everything below the controller is real: CalendarRepository,
 * AppointmentRepository (with its reader and writer), AppointmentHandler and
 * its validator, RateLimiter down to RateLimitChecker, IpLimiter,
 * RateCounter and RateLimitRepository, RequestInput and ClientIpResolver,
 * Encryption (with the bootstrap's key constants), PiiAccessPolicy,
 * DocumentFormatter, ActivityLog (read through its write buffer) and Debug.
 * Storage is a `$wpdb` double that holds the calendar and appointment rows
 * and answers the SQL by table, plus a transient map for the rate counters.
 * The controller catches every \Exception, so a query the double does not
 * expect is recorded and failed after the test rather than thrown where the
 * controller would swallow it.
 *
 * A booking runs the real handler end to end: the validator, the
 * transaction, the writer's encryption and the insert the double stores, so
 * a test that books reads back the row the handler really wrote (#2155).
 *
 * A real route parameter arrives as a string. The controller reads every
 * `id` through absint() before it reaches the `int`-typed `findById()` and
 * `cancel_appointment()`, where a string is a TypeError under strict types —
 * an \Error its catch does not reach (#2155, the defect #2146 fixed in the
 * calendar controller). The `*_string_id` tests pin that.
 *
 * @covers \FreeFormCertificate\API\AppointmentRestController
 */
class AppointmentRestControllerTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private const CALENDARS    = 'wp_ffc_self_scheduling_calendars';
	private const APPOINTMENTS = 'wp_ffc_self_scheduling_appointments';
	private const BLOCKED_DATES = 'wp_ffc_self_scheduling_blocked_dates';
	private const PROFILES     = 'wp_ffc_user_profiles';
	private const CLIENT_IP    = '8.8.4.4';

	/** The id the double gives the first appointment it stores. */
	private const FIRST_NEW_ID = 101;

	/** The account the identity index files the CPF under, when a test stores one. */
	private const PERSON_ID = 77;

	/** The id wp_create_user() gives the first account it creates. */
	private const NEW_ACCOUNT_ID = 900;

	/** @var array Captured route registrations */
	private array $registered_routes = [];

	/** @var array<int, array<string, mixed>> Calendar rows the database holds, by id. */
	private array $calendars = array();

	/** @var array<int, array<string, mixed>> Appointment rows the database holds, by id. */
	private array $appointments = array();

	/** @var array<string, mixed> The stored `ffc_settings` option. */
	private array $settings = array();

	/** @var array<string, mixed> Transients, by key. */
	private array $transients = array();

	/** @var int|null The calendar read (1-based) that throws, or null. */
	private ?int $calendar_read_throws_at = null;

	/** @var bool Whether a read of the appointments table throws. */
	private bool $appointment_read_throws = false;

	/** @var int Calendar reads so far. */
	private int $calendar_reads = 0;

	/** @var list<int> Appointment ids read, in order. */
	private array $appointment_reads = array();

	/** @var list<array{0: string, 1: array<string, mixed>}> Every insert, as [table, data]. */
	private array $inserts = array();

	/** @var list<array<string, mixed>> Every row inserted into the appointments table. */
	private array $appointment_inserts = array();

	/** @var list<string> Transaction statements, in order. */
	private array $transaction = array();

	/** @var list<array{0: array<string, mixed>, 1: array<string, mixed>}> Every appointment update, as [data, where]. */
	private array $updates = array();

	/** @var list<string> Queries the double was not written to answer. */
	private array $unexpected = array();

	/** @var array<int, array<string, mixed>> `ffc_user_profiles` rows (the identity index), by user id. */
	private array $profiles = array();

	/** @var list<array{0: string, 1: array<string, mixed>}> Every write to the identity index, as [insert|update, data]. */
	private array $profile_writes = array();

	/** @var array<string, int> WordPress accounts, by e-mail. */
	private array $accounts_by_email = array();

	/** @var list<array{0: string, 1: string}> Accounts wp_create_user() made, as [username, email]. */
	private array $created_accounts = array();

	/** @var list<string> The record-adoption UPDATEs the user linking ran. */
	private array $adoptions = array();

	/** @var bool Whether `$wpdb` was set before this test. */
	private bool $had_wpdb = false;

	/** @var mixed The `$wpdb` global an earlier test left, restored afterwards. */
	private $wpdb_backup;

	/** @var array<string, mixed> `$_SERVER` as an earlier test left it. */
	private array $server_backup = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->registered_routes       = [];
		$this->calendars               = array();
		$this->appointments            = array();
		$this->settings                = array();
		$this->transients              = array();
		$this->calendar_read_throws_at = null;
		$this->appointment_read_throws = false;
		$this->calendar_reads          = 0;
		$this->appointment_reads       = array();
		$this->inserts                 = array();
		$this->appointment_inserts     = array();
		$this->transaction             = array();
		$this->updates                 = array();
		$this->unexpected              = array();
		$this->profiles                = array();
		$this->profile_writes          = array();
		$this->accounts_by_email       = array();
		$this->created_accounts        = array();
		$this->adoptions               = array();

		// Capture register_rest_route calls
		Functions\when( 'register_rest_route' )->alias( function( $namespace, $route, $args ) {
			$this->registered_routes[] = array(
				'namespace' => $namespace,
				'route'     => $route,
				'args'      => $args,
			);
		});

		Functions\when( '__' )->returnArg();
		Functions\when( 'rest_ensure_response' )->alias( function( $data ) { return $data; } );
		Functions\when( 'is_wp_error' )->alias( function( $thing ) { return $thing instanceof \WP_Error; } );
		Functions\when( 'is_user_logged_in' )->justReturn( false );
		Functions\when( 'get_current_user_id' )->justReturn( 0 );
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( 'user_can' )->justReturn( false );
		Functions\when( 'is_email' )->alias( function( $email ) { return (bool) filter_var( $email, FILTER_VALIDATE_EMAIL ); } );
		// As WordPress does it: a non-scalar becomes '', script/style blocks
		// go with their content, the remaining tags are stripped.
		Functions\when( 'sanitize_text_field' )->alias(
			static function ( $value ) {
				if ( ! is_scalar( $value ) ) {
					return '';
				}
				$value = (string) preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', (string) $value );
				return trim( strip_tags( $value ) );
			}
		);
		Functions\when( 'sanitize_email' )->returnArg();
		Functions\when( 'sanitize_textarea_field' )->returnArg();
		Functions\when( 'sanitize_key' )->alias( static fn ( $key ) => strtolower( (string) preg_replace( '/[^a-z0-9_\-]/i', '', (string) $key ) ) );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'wp_json_encode' )->alias( static fn ( $data ) => json_encode( $data ) );
		Functions\when( 'absint' )->alias( static fn ( $value ) => abs( (int) $value ) );
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'get_option' )->alias(
			function ( $name, $default_value = false ) {
				if ( 'ffc_settings' === $name ) {
					// Debug areas, the activity log and the e-mail kill-switch.
					return $this->settings;
				}
				// The rate-limit settings (their defaults apply) and anything else.
				return $default_value;
			}
		);
		Functions\when( 'wp_parse_args' )->alias(
			static fn ( $args, $defaults ) => array_merge( $defaults, is_array( $args ) ? $args : array() )
		);
		Functions\when( 'get_transient' )->alias( fn ( $key ) => $this->transients[ $key ] ?? false );
		Functions\when( 'set_transient' )->alias(
			function ( $key, $value ) {
				$this->transients[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'current_time' )->justReturn( '2026-04-01 12:00:00' );
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		Functions\when( 'wp_cache_flush' )->justReturn( true );
		// clear_cache() with no key asks the backend first (#2241); without
		// group flushing it falls back to wp_cache_flush(), stubbed above.
		Functions\when( 'wp_cache_supports' )->justReturn( false );
		Functions\when( 'wp_timezone' )->alias( static fn () => new \DateTimeZone( 'UTC' ) );

		// RateLimitChecker memoises the settings it read first; ActivityLog
		// keeps a static write buffer (its shutdown hook marked as registered,
		// so logging does not reach add_action()).
		$this->reset_statics();

		// ClientIpResolver's legacy chain reads the forwarding headers first.
		$this->server_backup = $_SERVER ?? array();
		foreach ( array( 'HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED', 'HTTP_X_CLUSTER_CLIENT_IP', 'HTTP_FORWARDED_FOR', 'HTTP_FORWARDED', 'HTTP_USER_AGENT' ) as $header ) {
			unset( $_SERVER[ $header ] );
		}
		$_SERVER['REMOTE_ADDR'] = self::CLIENT_IP;

		$this->had_wpdb    = array_key_exists( 'wpdb', $GLOBALS );
		$this->wpdb_backup = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb']   = $this->make_wpdb();
	}

	protected function tearDown(): void {
		if ( $this->had_wpdb ) {
			$GLOBALS['wpdb'] = $this->wpdb_backup;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		$_SERVER = $this->server_backup;
		$this->reset_statics();
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * The controller turns any exception into `ffc_internal_error`, so an
	 * unexpected query is checked here, outside its try block.
	 */
	protected function assertPostConditions(): void {
		$this->assertSame( array(), $this->unexpected, 'Every query the code ran was one the double answers.' );
	}

	/**
	 * Clear the static state the real classes keep between calls.
	 */
	private function reset_statics(): void {
		$ref = new \ReflectionProperty( RateLimitChecker::class, 'settings_cache' );
		$ref->setAccessible( true );
		$ref->setValue( null, null );

		$log = new \ReflectionClass( ActivityLog::class );
		foreach ( array( 'write_buffer' => array(), 'shutdown_registered' => true ) as $name => $value ) {
			$prop = $log->getProperty( $name );
			$prop->setAccessible( true );
			$prop->setValue( null, $value );
		}
	}

	/**
	 * Rows the activity log buffered during the test.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function logged(): array {
		$prop = ( new \ReflectionClass( ActivityLog::class ) )->getProperty( 'write_buffer' );
		$prop->setAccessible( true );
		return (array) $prop->getValue();
	}

	/**
	 * A `$wpdb` double that renders prepare() into plain SQL and answers by table.
	 */
	private function make_wpdb(): object {
		$wpdb             = Mockery::mock( 'wpdb' );
		$wpdb->prefix     = 'wp_';
		$wpdb->last_error = '';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			static function ( $sql, ...$args ) {
				if ( 1 === count( $args ) && is_array( $args[0] ) ) {
					$args = $args[0];
				}
				$i = 0;
				return (string) preg_replace_callback(
					'/%[ids]/',
					static function ( $m ) use ( &$i, $args ) {
						$v = $args[ $i++ ] ?? '';
						if ( '%i' === $m[0] ) {
							return '`' . $v . '`';
						}
						if ( '%d' === $m[0] ) {
							return (string) (int) $v;
						}
						return "'" . addslashes( (string) $v ) . "'";
					},
					(string) $sql
				);
			}
		);
		$wpdb->shouldReceive( 'get_row' )->andReturnUsing(
			function ( $sql ) {
				if ( preg_match( '/^SELECT \* FROM `' . self::CALENDARS . '` WHERE id = (\d+)$/', $sql, $m ) ) {
					++$this->calendar_reads;
					if ( $this->calendar_read_throws_at === $this->calendar_reads ) {
						throw new \RuntimeException( 'boom' );
					}
					return $this->calendars[ (int) $m[1] ] ?? null;
				}
				if ( preg_match( '/^SELECT \* FROM `' . self::APPOINTMENTS . '` WHERE id = (\d+)$/', $sql, $m ) ) {
					$this->appointment_reads[] = (int) $m[1];
					if ( $this->appointment_read_throws ) {
						throw new \RuntimeException( 'boom' );
					}
					return $this->appointments[ (int) $m[1] ] ?? null;
				}
				if ( 0 === strpos( $sql, 'SELECT id,count FROM `wp_ffc_rate_limits`' ) ) {
					return null; // No counter row for this window yet.
				}
				if ( preg_match( '/^SELECT \* FROM `' . self::PROFILES . '` WHERE user_id = (\d+)$/', $sql, $m ) ) {
					return $this->profiles[ (int) $m[1] ] ?? null;
				}
				$this->unexpected[] = 'get_row: ' . $sql;
				return null;
			}
		);
		$wpdb->shouldReceive( 'get_var' )->andReturnUsing(
			function ( $sql ) {
				if ( 0 === strpos( $sql, 'SELECT count FROM `wp_ffc_rate_limits`' ) ) {
					return null; // The day counter is empty.
				}
				// An established install: the identity index and the
				// appointments table both exist.
				if ( preg_match( "/^SHOW TABLES LIKE '(" . self::PROFILES . '|' . self::APPOINTMENTS . ")'$/", $sql, $m ) ) {
					return $m[1];
				}
				// The user linking asks which account carries the identifier:
				// the identity index answers from the rows the test stored,
				// and no certificate submission carries it.
				if ( preg_match( "/^SELECT user_id FROM `(" . self::PROFILES . "|wp_ffc_submissions)` WHERE \((.+)\) AND user_id IS NOT NULL LIMIT 1$/", $sql, $m ) ) {
					if ( self::PROFILES !== $m[1] ) {
						return null;
					}
					preg_match_all( "/(cpf_hash|rf_hash) = '([^']*)'/", $m[2], $pairs, PREG_SET_ORDER );
					foreach ( $this->profiles as $user_id => $profile ) {
						foreach ( $pairs as $pair ) {
							if ( ( $profile[ $pair[1] ] ?? null ) === $pair[2] ) {
								return (string) $user_id;
							}
						}
					}
					return null;
				}
				if ( preg_match( '/^SELECT id FROM `' . self::PROFILES . '` WHERE user_id = (\d+)$/', $sql, $m ) ) {
					return isset( $this->profiles[ (int) $m[1] ] ) ? (string) $this->profiles[ (int) $m[1] ]['id'] : null;
				}
				if ( "SHOW TABLES LIKE 'wp_ffc_custom_fields'" === $sql ) {
					// The activity log asks which custom fields are sensitive: none exist.
					return null;
				}
				// The validator's capacity check: the active bookings of one slot.
				if ( preg_match( "/^SELECT COUNT\(\*\) FROM `" . self::APPOINTMENTS . "`\s+WHERE calendar_id = (\d+)\s+AND appointment_date = '([^']*)'\s+AND start_time = '([^']*)'\s+AND status IN \('confirmed', 'pending'\) FOR UPDATE$/", $sql, $m ) ) {
					$taken = 0;
					foreach ( $this->appointments as $row ) {
						if ( (int) $row['calendar_id'] === (int) $m[1] && $row['appointment_date'] === $m[2] && $row['start_time'] === $m[3] && in_array( $row['status'], array( 'confirmed', 'pending' ), true ) ) {
							++$taken;
						}
					}
					return (string) $taken;
				}
				// The writer's validation code is unique across the three tables that carry one.
				if ( preg_match( "/^SELECT id FROM `wp_ffc_(submissions|reregistration_submissions)` WHERE auth_code = '[A-Z0-9]{12}' LIMIT 1$/", $sql )
					|| preg_match( "/^SELECT id FROM `" . self::APPOINTMENTS . "` WHERE validation_code = '[A-Z0-9]{12}' LIMIT 1$/", $sql ) ) {
					return null;
				}
				$this->unexpected[] = 'get_var: ' . $sql;
				return null;
			}
		);
		$wpdb->shouldReceive( 'get_results' )->andReturnUsing(
			function ( $sql ) {
				if ( preg_match( '/^SELECT \* FROM `' . self::BLOCKED_DATES . '`\s+WHERE \(calendar_id = \d+ OR calendar_id IS NULL\)/', $sql ) ) {
					return array(); // No date is blocked.
				}
				$this->unexpected[] = 'get_results: ' . $sql;
				return array();
			}
		);
		$wpdb->shouldReceive( 'query' )->andReturnUsing(
			function ( $sql ) {
				if ( in_array( $sql, array( 'START TRANSACTION', 'COMMIT', 'ROLLBACK' ), true ) ) {
					$this->transaction[] = $sql;
					return 1;
				}
				// The linking adopts the person's unlinked records: none here.
				if ( preg_match( '/^UPDATE `(wp_ffc_submissions|' . self::APPOINTMENTS . ')` SET user_id = \d+ WHERE \(?(cpf_hash|rf_hash) = /', $sql ) ) {
					$this->adoptions[] = $sql;
					return 0;
				}
				$this->unexpected[] = 'query: ' . $sql;
				return false;
			}
		);
		$wpdb->insert_id = 0;
		$wpdb->shouldReceive( 'insert' )->andReturnUsing(
			function ( $table, $data ) use ( $wpdb ) {
				if ( 'wp_ffc_rate_limits' === $table ) {
					$this->inserts[] = array( $table, $data );
					return 1;
				}
				if ( self::APPOINTMENTS === $table ) {
					// Stored as the database would hold it, so the handler's
					// read-back after the commit finds the new row.
					$this->appointment_inserts[]    = $data;
					$wpdb->insert_id                = self::FIRST_NEW_ID + count( $this->appointment_inserts ) - 1;
					$this->appointments[ $wpdb->insert_id ] = array( 'id' => (string) $wpdb->insert_id ) + $data;
					return 1;
				}
				if ( self::PROFILES === $table ) {
					$this->profile_writes[]               = array( 'insert', $data );
					$wpdb->insert_id                      = 500 + count( $this->profile_writes );
					$this->profiles[ (int) $data['user_id'] ] = array( 'id' => (string) $wpdb->insert_id ) + $data;
					return 1;
				}
				$this->unexpected[] = 'insert: ' . $table;
				return false;
			}
		);
		$wpdb->shouldReceive( 'update' )->andReturnUsing(
			function ( $table, $data, $where ) {
				if ( self::APPOINTMENTS === $table ) {
					$this->updates[] = array( $data, $where );
					return 1;
				}
				if ( self::PROFILES === $table ) {
					$this->profile_writes[]                       = array( 'update', $data );
					$this->profiles[ (int) $where['user_id'] ] = $data + $this->profiles[ (int) $where['user_id'] ];
					return 1;
				}
				$this->unexpected[] = 'update: ' . $table;
				return false;
			}
		);
		return $wpdb;
	}

	/**
	 * Store a calendar row under its id, with string columns as wpdb returns them.
	 *
	 * @param array<string, mixed> $over Columns to override.
	 */
	private function store_calendar( array $over = array() ): void {
		$row = array_map(
			'strval',
			array_merge(
				array(
					'id'                                => '1',
					'title'                             => 'Calendar',
					'status'                            => 'active',
					'scheduling_visibility'             => 'public',
					'requires_approval'                 => '0',
					'allow_cancellation'                => '1',
					'cancellation_min_hours'            => '0',
					'email_config'                      => '{}',
					// A regular calendar with no booking rule beyond one per slot.
					'schedule_type'                     => 'regular',
					'slot_duration'                     => '30',
					// Every day 08:00-18:00, so the 09:00 the tests book is a
					// slot of the grid the validator accepts (#2244).
					'working_hours'                     => '[{"day":0,"start":"08:00","end":"18:00"},{"day":1,"start":"08:00","end":"18:00"},{"day":2,"start":"08:00","end":"18:00"},{"day":3,"start":"08:00","end":"18:00"},{"day":4,"start":"08:00","end":"18:00"},{"day":5,"start":"08:00","end":"18:00"},{"day":6,"start":"08:00","end":"18:00"}]',
					'advance_booking_min'               => '0',
					'advance_booking_max'               => '0',
					'max_appointments_per_slot'         => '1',
					'slots_per_day'                     => '0',
					'minimum_interval_between_bookings' => '0',
					'restrict_booking_to_hours'         => '0',
					'waitlist_enabled'                  => '0',
				),
				$over
			)
		);
		$this->calendars[ (int) $row['id'] ] = $row;
	}

	/**
	 * Store an appointment row under its id, its email and phone encrypted as
	 * the writer stores them.
	 *
	 * @param array<string, mixed> $row Appointment columns, with plaintext `email` and `phone`.
	 */
	private function store_appointment( array $row ): void {
		foreach ( array( 'email', 'phone' ) as $field ) {
			if ( isset( $row[ $field ] ) ) {
				$row[ $field . '_encrypted' ] = Encryption::encrypt( (string) $row[ $field ] );
				$row[ $field ]                = null;
			}
		}
		$this->appointments[ (int) $row['id'] ] = $row;
	}

	/**
	 * Hold exactly these capabilities.
	 *
	 * @param list<string> $caps Capabilities.
	 */
	private function holdCaps( array $caps ): void {
		Functions\when( 'current_user_can' )->alias( static fn( $cap ) => in_array( $cap, $caps, true ) );
	}

	/**
	 * Helper: create a mock WP_REST_Request with given params.
	 */
	private function make_request( array $params = [], array $json_params = [] ): object {
		$req = Mockery::mock( 'WP_REST_Request' );
		$req->shouldReceive( 'get_param' )->andReturnUsing( function( $key ) use ( $params ) {
			return $params[ $key ] ?? null;
		});
		$req->shouldReceive( 'get_json_params' )->andReturn( $json_params );
		return $req;
	}

	/**
	 * The transient key the per-hour IP counter lives under.
	 */
	private function ip_hour_key(): string {
		return 'ffc_rate_ip_' . md5( IpAddress::fingerprint( self::CLIENT_IP ) ) . '_hour';
	}

	// ------------------------------------------------------------------
	// Route registration
	// ------------------------------------------------------------------

	public function test_register_routes_creates_three_endpoints(): void {
		$ctrl = new AppointmentRestController( 'ffc/v1' );
		$ctrl->register_routes();

		$this->assertCount( 3, $this->registered_routes );
	}

	public function test_create_appointment_route_requires_a_capability(): void {
		$ctrl = new AppointmentRestController( 'ffc/v1' );
		$ctrl->register_routes();

		$route = $this->registered_routes[0];
		$this->assertStringContainsString( 'appointments', $route['route'] );
		$this->assertSame( array( $ctrl, 'check_create_permission' ), $route['args']['permission_callback'] );
	}

	public function test_anonymous_caller_is_refused_by_the_create_permission(): void {
		Functions\when( 'current_user_can' )->justReturn( false );

		$this->assertFalse( ( new AppointmentRestController( 'ffc/v1' ) )->check_create_permission() );
	}

	public function test_create_permission_admits_the_appointments_manager_only(): void {
		Functions\when( 'current_user_can' )->alias( static fn( $cap ) => 'ffc_manage_appointments' === $cap );
		$this->assertTrue( ( new AppointmentRestController( 'ffc/v1' ) )->check_create_permission() );

		// The self-scoped booking cap is not enough to book in another's name.
		Functions\when( 'current_user_can' )->alias( static fn( $cap ) => 'ffc_book_own_appointments' === $cap );
		$this->assertFalse( ( new AppointmentRestController( 'ffc/v1' ) )->check_create_permission() );
	}

	public function test_create_appointment_records_the_rate_limit_attempt(): void {
		// Inactive, so the real handler refuses right after the attempt is recorded.
		$this->store_calendar( array( 'id' => 3, 'status' => 'inactive' ) );

		( new AppointmentRestController( 'ffc/v1' ) )->create_appointment(
			$this->make_request( array( 'id' => 3 ), $this->valid_booking() )
		);

		$this->assertSame( 1, $this->transients[ $this->ip_hour_key() ] ?? null, 'The hour counter of the caller\'s address went up by one.' );
		$this->assertCount( 1, $this->inserts, 'Exactly one attempt reached the counter table.' );
		$this->assertSame( 'ip', $this->inserts[0][1]['type'] );
		$this->assertSame( IpAddress::fingerprint( self::CLIENT_IP ), $this->inserts[0][1]['identifier'] );
		$this->assertSame( 'day', $this->inserts[0][1]['window_type'] );
	}

	public function test_create_appointment_sanitizes_custom_data_like_the_booking_form(): void {
		$this->sign_in_as_operator( 5 );
		$this->store_calendar( array( 'id' => 3 ) );
		$raw = array( 'school' => '<script>x</script>EMEF', 'nested' => array( 'a' => 'b' ) );

		$booking                = $this->valid_booking();
		$booking['custom_data'] = $raw;
		$result                 = ( new AppointmentRestController( 'ffc/v1' ) )->create_appointment(
			$this->make_request( array( 'id' => 3 ), $booking )
		);

		$this->assertIsArray( $result, 'The booking went through the real handler.' );
		$this->assertTrue( $result['success'] );
		$this->assertCount( 1, $this->appointment_inserts );
		$row = $this->appointment_inserts[0];
		$this->assertArrayNotHasKey( 'custom_data', $row, 'Custom data is stored only encrypted.' );
		$this->assertSame(
			array( 'school' => 'EMEF', 'nested' => '' ),
			json_decode( (string) Encryption::decrypt( (string) $row['custom_data_encrypted'] ), true ),
			'The stored custom data is the sanitised one.'
		);
	}

	public function test_get_and_cancel_appointment_routes_require_access_check(): void {
		$ctrl = new AppointmentRestController( 'ffc/v1' );
		$ctrl->register_routes();

		// GET /appointments/{id}
		$route_get = $this->registered_routes[1];
		$this->assertSame( array( $ctrl, 'check_appointment_access' ), $route_get['args']['permission_callback'] );

		// DELETE /appointments/{id}
		$route_delete = $this->registered_routes[2];
		$this->assertSame( array( $ctrl, 'check_appointment_access' ), $route_delete['args']['permission_callback'] );
	}

	// ------------------------------------------------------------------
	// create_appointment()
	// ------------------------------------------------------------------

	public function test_create_appointment_returns_error_when_params_empty(): void {
		$ctrl = new AppointmentRestController( 'ffc/v1' );
		$request = $this->make_request( array( 'id' => 1 ), [] );
		$result = $ctrl->create_appointment( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'no_data', $result->get_error_code() );
	}

	public function test_create_appointment_returns_error_when_required_fields_missing(): void {
		$ctrl = new AppointmentRestController( 'ffc/v1' );
		$request = $this->make_request(
			array( 'id' => 1 ),
			array( 'date' => '2026-04-01', 'time' => '09:00' )
		);
		$result = $ctrl->create_appointment( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'missing_field', $result->get_error_code() );
	}

	public function test_create_appointment_returns_error_for_invalid_email(): void {
		$ctrl = new AppointmentRestController( 'ffc/v1' );
		$request = $this->make_request(
			array( 'id' => 1 ),
			array(
				'date'   => '2026-04-01',
				'time'   => '09:00',
				'name'   => 'John Doe',
				'email'  => 'not-an-email',
				'cpf_rf' => Identifiers::CPF_A,
			)
		);
		$result = $ctrl->create_appointment( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_email', $result->get_error_code() );
	}

	public function test_create_appointment_returns_error_when_calendar_not_found(): void {
		$ctrl = new AppointmentRestController( 'ffc/v1' );
		$request = $this->make_request(
			array( 'id' => 1 ),
			array(
				'date'   => '2026-04-01',
				'time'   => '09:00',
				'name'   => 'John Doe',
				'email'  => 'john@example.com',
				'cpf_rf' => Identifiers::CPF_A,
			)
		);
		$result = $ctrl->create_appointment( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'calendar_not_found', $result->get_error_code() );
		$this->assertSame( 1, $this->calendar_reads, 'Calendar 1 was looked up.' );
	}

	public function test_create_appointment_returns_error_for_private_scheduling_anon(): void {
		$this->store_calendar( array( 'id' => 1, 'scheduling_visibility' => 'private' ) );

		$ctrl = new AppointmentRestController( 'ffc/v1' );
		$request = $this->make_request(
			array( 'id' => 1 ),
			array(
				'date'   => '2026-04-01',
				'time'   => '09:00',
				'name'   => 'John Doe',
				'email'  => 'john@example.com',
				'cpf_rf' => Identifiers::CPF_A,
			)
		);
		$result = $ctrl->create_appointment( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'scheduling_private', $result->get_error_code() );
	}

	public function test_create_appointment_success_returns_data(): void {
		// A site administrator: the scheduling bypass, with no rule broken.
		$this->allow_booking();
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		$this->holdCaps( array( 'manage_options' ) );
		$this->store_calendar( array( 'id' => 1 ) );

		$ctrl    = new AppointmentRestController( 'ffc/v1' );
		$request = $this->make_request(
			array( 'id' => 1 ),
			array(
				'date'    => $this->future_date(),
				'time'    => '09:00',
				'name'    => 'John Doe',
				'email'   => 'john@example.com',
				'cpf_rf'  => Identifiers::CPF_A,
				'consent' => 1,
			)
		);
		$result = $ctrl->create_appointment( $request );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( self::FIRST_NEW_ID, $result['appointment_id'] );
		$this->assertFalse( $result['requires_approval'], 'The calendar does not require approval.' );
		$this->assertSame( array( 'START TRANSACTION', 'COMMIT' ), $this->transaction );

		$this->assertCount( 1, $this->appointment_inserts );
		$row = $this->appointment_inserts[0];
		$this->assertSame( 1, $row['calendar_id'] );
		$this->assertSame( $this->future_date(), $row['appointment_date'] );
		$this->assertSame( '09:00:00', $row['start_time'], 'The start is stored normalised (#2244).' );
		$this->assertSame( '09:30:00', $row['end_time'], 'The end follows the 30-minute slot.' );
		$this->assertSame( 'confirmed', $row['status'] );
		$this->assertSame( 1, $row['consent_given'] );
		// The CPF is stored only as ciphertext and hash, never in the clear.
		$this->assertSame( Identifiers::CPF_A, Encryption::decrypt( (string) $row['cpf_encrypted'] ) );
		$this->assertSame( Encryption::hash( Identifiers::CPF_A ), $row['cpf_hash'] );
		$this->assertSame( 'john@example.com', Encryption::decrypt( (string) $row['email_encrypted'] ) );
		foreach ( array( 'cpf_rf', 'cpf', 'rf', 'email' ) as $plain ) {
			$this->assertArrayNotHasKey( $plain, $row, "No plaintext `{$plain}` column is written." );
		}
	}

	public function test_create_appointment_refuses_a_body_without_cpf_rf(): void {
		$this->store_calendar( array( 'id' => 3 ) );
		$booking = $this->valid_booking();
		unset( $booking['cpf_rf'] );

		$result = ( new AppointmentRestController( 'ffc/v1' ) )->create_appointment(
			$this->make_request( array( 'id' => 3 ), $booking )
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'missing_field', $result->get_error_code() );
		$this->assertStringContainsString( 'cpf_rf', $result->get_error_message() );
		$this->assertSame( 0, $this->calendar_reads, 'The body is refused before any lookup.' );
		$this->assertSame( array(), $this->appointment_inserts );
	}

	public function test_create_appointment_accepts_a_string_calendar_id(): void {
		$this->sign_in_as_operator( 5 );
		$this->store_calendar( array( 'id' => 3 ) );

		// A route parameter arrives as a string.
		$result = ( new AppointmentRestController( 'ffc/v1' ) )->create_appointment(
			$this->make_request( array( 'id' => '3' ), $this->valid_booking() )
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 2, $this->calendar_reads, 'The controller and the handler both read calendar 3.' );
		$this->assertSame( 3, $this->appointment_inserts[0]['calendar_id'], 'The handler received the id as an int.' );
	}

	public function test_routes_sanitize_the_id_to_an_int(): void {
		( new AppointmentRestController( 'ffc/v1' ) )->register_routes();

		$this->assertCount( 3, $this->registered_routes );
		foreach ( $this->registered_routes as $route ) {
			$this->assertSame( 'absint', $route['args']['args']['id']['sanitize_callback'], $route['route'] );
		}
	}

	// ------------------------------------------------------------------
	// get_appointment()
	// ------------------------------------------------------------------

	public function test_get_appointment_returns_error_when_not_found(): void {
		$ctrl = new AppointmentRestController( 'ffc/v1' );
		$request = $this->make_request( array( 'id' => 99 ) );
		$result = $ctrl->get_appointment( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'appointment_not_found', $result->get_error_code() );
		$this->assertSame( array( 99 ), $this->appointment_reads );
	}

	public function test_get_appointment_returns_formatted_data_on_success(): void {
		// `$wpdb` returns every column as a string, so the stored calendar id
		// arrives as '1' and must be cast before the typed calendar lookup (#2155).
		$this->store_appointment( array(
			'id'               => 10,
			'calendar_id'      => '1',
			'appointment_date' => '2026-04-01',
			'start_time'       => '09:00',
			'end_time'         => '09:30',
			'status'           => 'confirmed',
			'name'             => 'Jane Doe',
			'email'            => 'jane@example.com',
			'phone'            => '11999998888',
			'user_notes'       => 'Test note',
			'created_at'       => '2026-03-01 10:00:00',
		) );
		$this->store_calendar( array( 'id' => 1, 'title' => 'Test Calendar' ) );

		$ctrl = new AppointmentRestController( 'ffc/v1' );
		$request = $this->make_request( array( 'id' => 10 ) );
		$result = $ctrl->get_appointment( $request );

		$this->assertIsArray( $result );
		$this->assertSame( 10, $result['id'] );
		$this->assertSame( 1, $result['calendar_id'] );
		$this->assertSame( 'Test Calendar', $result['calendar_title'] );
		$this->assertSame( 'confirmed', $result['status'] );
		// A caller who is not the booker gets the address masked (#1863).
		$this->assertSame( 'j***@example.com', $result['email'] );
		$this->assertSame( '*********88', $result['phone'] );
	}

	/**
	 * A booking owned by user 42.
	 *
	 * @return array<string, mixed>
	 */
	private function owned_appointment(): array {
		return array(
			'id'               => 10,
			'calendar_id'      => 1,
			'user_id'          => 42,
			'appointment_date' => '2026-04-01',
			'start_time'       => '09:00',
			'end_time'         => '09:30',
			'status'           => 'confirmed',
			'name'             => 'Jane Doe',
			'email'            => 'jane@example.com',
			'phone'            => '11999998888',
			'created_at'       => '2026-03-01 10:00:00',
		);
	}

	public function test_get_appointment_gives_the_booker_their_own_email_and_phone(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 42 );
		Functions\when( 'user_can' )->justReturn( false );
		$this->store_appointment( $this->owned_appointment() );
		$this->store_calendar( array( 'id' => 1, 'title' => 'Cal' ) );

		$result = ( new AppointmentRestController( 'ffc/v1' ) )->get_appointment( $this->make_request( array( 'id' => 10 ) ) );

		$this->assertSame( 'jane@example.com', $result['email'] );
		$this->assertSame( '11999998888', $result['phone'] );
	}

	public function test_get_appointment_reveals_to_a_pii_holder_only_on_request_and_audits(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'user_can' )->alias( static fn( $uid, $cap ) => 'ffc_view_appointments_pii' === $cap );
		$this->settings = array( 'enable_activity_log' => 1 );
		$this->store_appointment( $this->owned_appointment() );
		$this->store_calendar( array( 'id' => 1, 'title' => 'Cal' ) );
		$ctrl = new AppointmentRestController( 'ffc/v1' );

		$masked = $ctrl->get_appointment( $this->make_request( array( 'id' => 10 ) ) );
		$this->assertSame( 'j***@example.com', $masked['email'] );
		$this->assertSame( '*********88', $masked['phone'] );

		$clear = $ctrl->get_appointment( $this->make_request( array( 'id' => 10, 'reveal' => true ) ) );
		$this->assertSame( 'jane@example.com', $clear['email'] );
		$this->assertSame( '11999998888', $clear['phone'] );

		$logged = $this->logged();
		$this->assertCount( 1, $logged, 'Only the revealing read is audited.' );
		$this->assertSame( 'appointment_pii_revealed', $logged[0]['action'] );
		$this->assertSame( ActivityLog::LEVEL_INFO, $logged[0]['level'] );
		$this->assertSame( '{"field_key":"rest_api"}', $logged[0]['context'] );
		$this->assertSame( 5, $logged[0]['user_id'] );
		$this->assertSame( 10, $logged[0]['submission_id'] );
	}

	// ------------------------------------------------------------------
	// cancel_appointment()
	// ------------------------------------------------------------------

	public function test_cancel_appointment_propagates_wp_error(): void {
		// An anonymous caller with no token may not cancel: the handler refuses.
		$this->store_appointment( array( 'id' => 5, 'confirmation_token' => 'tok' ) + $this->owned_appointment() );
		$this->store_calendar( array( 'id' => 1 ) );

		$ctrl = new AppointmentRestController( 'ffc/v1' );
		$request = $this->make_request( array( 'id' => 5 ), [] );
		$result = $ctrl->cancel_appointment( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'unauthorized', $result->get_error_code() );
		$this->assertSame( array( 5 ), $this->appointment_reads, 'Appointment 5 was the one looked up.' );
		$this->assertSame( array(), $this->updates, 'A refused cancellation writes nothing.' );
	}

	public function test_cancel_appointment_returns_success(): void {
		// A holder of the scheduling bypass cancels any booking.
		Functions\when( 'get_current_user_id' )->justReturn( 7 );
		$this->holdCaps( array( 'ffc_bypass_appointments' ) );
		$this->store_appointment( array( 'id' => 5 ) + $this->owned_appointment() );
		$this->store_calendar( array( 'id' => 1 ) );

		$ctrl = new AppointmentRestController( 'ffc/v1' );
		$request = $this->make_request(
			array( 'id' => 5 ),
			array( 'reason' => 'Scheduling conflict' )
		);
		$result = $ctrl->cancel_appointment( $request );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertCount( 1, $this->updates );
		list( $data, $where ) = $this->updates[0];
		$this->assertSame( array( 'id' => 5 ), $where );
		$this->assertSame( 'cancelled', $data['status'] );
		$this->assertSame( 'Scheduling conflict', $data['cancellation_reason'] );
		$this->assertSame( 7, $data['cancelled_by'] );
	}

	// ------------------------------------------------------------------
	// check_appointment_access()
	// ------------------------------------------------------------------

	public function test_check_appointment_access_bypass_returns_true(): void {
		$this->holdCaps( array( 'ffc_bypass_appointments' ) );

		$ctrl = new AppointmentRestController( 'ffc/v1' );
		$request = $this->make_request( array( 'id' => 1 ) );

		$this->assertTrue( $ctrl->check_appointment_access( $request ) );
		$this->assertSame( array(), $this->appointment_reads, 'The bypass answers before any appointment is read.' );
	}

	public function test_check_appointment_access_not_logged_in_returns_false(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( false );

		$ctrl = new AppointmentRestController( 'ffc/v1' );
		$request = $this->make_request( array( 'id' => 1 ) );

		$this->assertFalse( $ctrl->check_appointment_access( $request ) );
	}

	public function test_check_appointment_access_own_appointment_returns_true(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 42 );
		// String columns, as wpdb returns them: the controller casts the owner.
		$this->appointments[10] = array( 'id' => '10', 'user_id' => '42' );

		$ctrl = new AppointmentRestController( 'ffc/v1' );
		$request = $this->make_request( array( 'id' => 10 ) );

		$this->assertTrue( $ctrl->check_appointment_access( $request ) );
		$this->assertSame( array( 10 ), $this->appointment_reads );
	}

	public function test_check_appointment_access_other_appointment_returns_false(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 42 );
		$this->appointments[10] = array( 'id' => '10', 'user_id' => '99' );

		$ctrl = new AppointmentRestController( 'ffc/v1' );
		$request = $this->make_request( array( 'id' => 10 ) );

		$this->assertFalse( $ctrl->check_appointment_access( $request ) );
		$this->assertSame( array( 10 ), $this->appointment_reads );
	}

	// ------------------------------------------------------------------
	// create_appointment — rate limit / logged-in / error / catch (#910)
	// ------------------------------------------------------------------

	/** @return array<string, mixed> */
	private function valid_booking(): array {
		return array(
			'date'    => $this->future_date(),
			'time'    => '10:00',
			'name'    => 'Ana',
			'email'   => 'ana@example.com',
			'cpf_rf'  => Identifiers::CPF_A,
			'consent' => 1,
		);
	}

	/**
	 * A date thirty days ahead: the validator refuses a past one, and the
	 * calendar sets no advance window, so the margin needs no second reading.
	 */
	private function future_date(): string {
		return gmdate( 'Y-m-d', time() + 30 * 86400 );
	}

	/**
	 * Stub what a booking reaches past the database: the randomness of the
	 * validation code and the receipt link's base URL.
	 */
	private function allow_booking(): void {
		Functions\when( 'wp_rand' )->alias( static fn ( $min = 0, $max = 0 ) => random_int( (int) $min, (int) $max ) );
		Functions\when( 'home_url' )->alias( static fn ( $path = '' ) => 'https://example.test' . $path );
		$this->stub_accounts();
	}

	/**
	 * Stub the WordPress user API the real UserCreator reaches when the
	 * handler links the booking to the person (#2161).
	 *
	 * Accounts live in `$accounts_by_email`; wp_create_user() adds one. No
	 * account has a user object to read (get_userdata() answers false), so
	 * the capability grants stop at their own guard: they are
	 * CapabilityManager's to test, not this controller's. The account
	 * notification is switched off through its own setting, which is the
	 * branch that returns before any mail is composed.
	 */
	private function stub_accounts(): void {
		$this->settings['send_wp_user_email_appointment'] = 0;
		Functions\when( 'get_user_by' )->alias(
			function ( $field, $value ) {
				if ( 'email' !== $field || ! isset( $this->accounts_by_email[ $value ] ) ) {
					return false;
				}
				$user               = new \WP_User( $this->accounts_by_email[ $value ] );
				$user->user_email   = $value;
				$user->user_login   = 'existing';
				$user->display_name = 'Existing Person';
				return $user;
			}
		);
		Functions\when( 'get_userdata' )->justReturn( false );
		Functions\when( 'username_exists' )->justReturn( false );
		Functions\when( 'remove_accents' )->returnArg();
		Functions\when( 'sanitize_user' )->alias( static fn ( $name ) => strtolower( (string) preg_replace( '/[^A-Za-z0-9 _.@-]/', '', (string) $name ) ) );
		Functions\when( 'wp_generate_password' )->justReturn( 'generated-password' );
		Functions\when( 'wp_create_user' )->alias(
			function ( $username, $password, $email ) {
				$this->created_accounts[]          = array( (string) $username, (string) $email );
				$user_id                           = self::NEW_ACCOUNT_ID + count( $this->created_accounts ) - 1;
				$this->accounts_by_email[ $email ] = $user_id;
				return $user_id;
			}
		);
		Functions\when( 'wp_update_user' )->alias( static fn ( $data ) => (int) $data['ID'] );
		Functions\when( 'update_user_meta' )->justReturn( true );
	}

	/**
	 * Sign in as the caller the create route admits: an appointments operator
	 * (the Operator and Manager tiers), who holds `ffc_manage_appointments`
	 * and NOT `ffc_book_own_appointments` — a booking made through this
	 * route belongs to the person, not to the operator (#2161).
	 *
	 * @param int $user_id User ID.
	 */
	private function sign_in_as_operator( int $user_id ): void {
		$this->allow_booking();
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( $user_id );
		$this->holdCaps( array( 'ffc_manage_appointments' ) );
	}

	/**
	 * The `appointment_booked_on_behalf` rows the activity log buffered.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function on_behalf_log(): array {
		return array_values(
			array_filter(
				$this->logged(),
				static fn ( array $row ) => 'appointment_booked_on_behalf' === $row['action']
			)
		);
	}

	public function test_create_appointment_rate_limited(): void {
		$this->store_calendar( array( 'id' => 3 ) );
		// The caller's address is already at the default hourly cap.
		$this->transients[ $this->ip_hour_key() ] = 5;

		$result = ( new AppointmentRestController( 'ffc/v1' ) )->create_appointment(
			$this->make_request( array( 'id' => 3 ), $this->valid_booking() )
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'rate_limit_exceeded', $result->get_error_code() );
		$this->assertSame( array(), $this->inserts, 'A refused request records no attempt.' );
	}

	public function test_an_operator_without_book_own_books_on_behalf_of_a_person(): void {
		// The Operator and Manager tiers hold ffc_manage_appointments and not
		// ffc_book_own_appointments; before #2161 the validator asked for the
		// second and refused them with `capability_denied`.
		$this->sign_in_as_operator( 5 );
		$this->store_calendar( array( 'id' => 3 ) );

		$result = ( new AppointmentRestController( 'ffc/v1' ) )->create_appointment(
			$this->make_request( array( 'id' => 3 ), $this->valid_booking() )
		);

		$this->assertIsArray( $result, 'The operator was refused instead of booking.' );
		$this->assertTrue( $result['success'] );
		$this->assertSame( self::FIRST_NEW_ID, $result['appointment_id'] );
		$this->assertCount( 1, $this->appointment_inserts );
	}

	/**
	 * The account an appointment was linked to after its insert (#2244), or null.
	 */
	private function linked_user( int $appointment_id ): ?int {
		foreach ( $this->updates as $update ) {
			if ( ( $update[1]['id'] ?? null ) === $appointment_id && isset( $update[0]['user_id'] ) ) {
				return (int) $update[0]['user_id'];
			}
		}
		return null;
	}

	public function test_create_appointment_files_the_booking_under_the_persons_existing_account(): void {
		$this->sign_in_as_operator( 5 );
		$this->settings['enable_activity_log'] = 1;
		$this->store_calendar( array( 'id' => 3 ) );
		// The identity index already knows the person behind this CPF.
		$this->profiles[ self::PERSON_ID ] = array(
			'id'       => '9',
			'user_id'  => (string) self::PERSON_ID,
			'cpf_hash' => SensitiveFieldRegistry::hash_identifier( 'cpf', Identifiers::CPF_A ),
			'rf_hash'  => null,
		);

		$result = ( new AppointmentRestController( 'ffc/v1' ) )->create_appointment(
			$this->make_request( array( 'id' => 3 ), $this->valid_booking() )
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertCount( 1, $this->appointment_inserts );
		$row = $this->appointment_inserts[0];
		$this->assertArrayNotHasKey( 'user_id', $row, 'The row is inserted before any account is touched (#2244).' );
		$this->assertSame( self::PERSON_ID, $this->linked_user( self::FIRST_NEW_ID ), 'The booking belongs to the account the CPF resolves to.' );
		$this->assertArrayNotHasKey( 'booked_by', $row, 'Who booked it is not a column.' );
		$this->assertSame( array(), $this->created_accounts, 'A known person gets no second account.' );
		$this->assertSame( array(), $this->profile_writes, 'The index already held the CPF, so nothing was rewritten.' );
		$this->assertNotSame( array(), $this->adoptions, 'The real linking ran and looked for the person\'s unlinked records.' );

		$logged = $this->on_behalf_log();
		$this->assertCount( 1, $logged, 'The booking on behalf is recorded once.' );
		$this->assertSame( ActivityLog::LEVEL_INFO, $logged[0]['level'] );
		$this->assertSame( 5, $logged[0]['user_id'], 'The operator is the actor of the log line.' );
		$this->assertSame(
			array(
				'appointment_id'   => self::FIRST_NEW_ID,
				'calendar_id'      => 3,
				'appointment_date' => $this->future_date(),
				'start_time'       => '10:00:00',
				'booked_for'       => self::PERSON_ID,
			),
			json_decode( (string) $logged[0]['context'], true )
		);
	}

	public function test_create_appointment_creates_an_account_for_a_person_nobody_knows(): void {
		$this->sign_in_as_operator( 5 );
		$this->settings['enable_activity_log'] = 1;
		$this->store_calendar( array( 'id' => 3 ) );

		$result = ( new AppointmentRestController( 'ffc/v1' ) )->create_appointment(
			$this->make_request( array( 'id' => 3 ), $this->valid_booking() )
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( array( array( 'ana', 'ana@example.com' ) ), $this->created_accounts, 'One account, for the e-mail the operator sent.' );
		$row = $this->appointment_inserts[0];
		$this->assertSame( self::NEW_ACCOUNT_ID, $this->linked_user( self::FIRST_NEW_ID ), 'The booking belongs to the new account.' );
		$this->assertArrayNotHasKey( 'booked_by', $row, 'Who booked it is not a column.' );
		$this->assertSame(
			SensitiveFieldRegistry::hash_identifier( 'cpf', Identifiers::CPF_A ),
			$this->profiles[ self::NEW_ACCOUNT_ID ]['cpf_hash'] ?? null,
			'The identity index learned the new account\'s CPF.'
		);

		$logged = $this->on_behalf_log();
		$this->assertCount( 1, $logged );
		$this->assertSame( 5, $logged[0]['user_id'], 'The operator is the actor of the log line.' );
		$context = json_decode( (string) $logged[0]['context'], true );
		$this->assertSame( self::NEW_ACCOUNT_ID, $context['booked_for'] ?? null );
		$this->assertSame( self::FIRST_NEW_ID, $context['appointment_id'] ?? null );
	}

	public function test_create_appointment_bubbles_handler_wp_error(): void {
		// The controller does not read the status; the handler refuses an inactive calendar.
		$this->store_calendar( array( 'id' => 3, 'status' => 'inactive' ) );

		$result = ( new AppointmentRestController( 'ffc/v1' ) )->create_appointment(
			$this->make_request( array( 'id' => 3 ), $this->valid_booking() )
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'calendar_inactive', $result->get_error_code() );
		$this->assertSame( 2, $this->calendar_reads, 'The handler read the calendar again on its own.' );
	}

	public function test_create_appointment_wraps_exception(): void {
		$this->store_calendar( array( 'id' => 3 ) );
		// The controller's read succeeds; the handler's own read fails.
		$this->calendar_read_throws_at = 2;

		$result = ( new AppointmentRestController( 'ffc/v1' ) )->create_appointment(
			$this->make_request( array( 'id' => 3 ), $this->valid_booking() )
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ffc_internal_error', $result->get_error_code() );
	}

	public function test_get_appointment_wraps_exception(): void {
		$this->appointment_read_throws = true;

		$result = ( new AppointmentRestController( 'ffc/v1' ) )->get_appointment(
			$this->make_request( array( 'id' => 5 ) )
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ffc_internal_error', $result->get_error_code() );
	}

	public function test_cancel_appointment_wraps_exception(): void {
		$this->appointment_read_throws = true;

		$result = ( new AppointmentRestController( 'ffc/v1' ) )->cancel_appointment(
			$this->make_request( array( 'id' => 5 ) )
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ffc_internal_error', $result->get_error_code() );
		$this->assertSame( array( 5 ), $this->appointment_reads, 'The handler read appointment 5 when the storage failed.' );
	}

	public function test_check_appointment_access_false_when_appointment_missing(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 5 );

		$this->assertFalse(
			( new AppointmentRestController( 'ffc/v1' ) )->check_appointment_access( $this->make_request( array( 'id' => 5 ) ) )
		);
		$this->assertSame( array( 5 ), $this->appointment_reads );
	}

	// ------------------------------------------------------------------
	// A route id arrives as a string (#2155)
	// ------------------------------------------------------------------

	public function test_get_appointment_accepts_a_string_id(): void {
		$this->store_appointment( array( 'id' => 10 ) + $this->owned_appointment() );
		$this->store_calendar( array( 'id' => 1, 'title' => 'Cal' ) );

		$result = ( new AppointmentRestController( 'ffc/v1' ) )->get_appointment( $this->make_request( array( 'id' => '10' ) ) );

		$this->assertIsArray( $result );
		$this->assertSame( 10, $result['id'] );
		$this->assertSame( array( 10 ), $this->appointment_reads, 'The repository was asked for appointment 10.' );
	}

	public function test_cancel_appointment_accepts_a_string_id(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 7 );
		$this->holdCaps( array( 'ffc_bypass_appointments' ) );
		$this->store_appointment( array( 'id' => 5 ) + $this->owned_appointment() );
		$this->store_calendar( array( 'id' => 1 ) );

		$result = ( new AppointmentRestController( 'ffc/v1' ) )->cancel_appointment( $this->make_request( array( 'id' => '5' ) ) );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( array( 5 ), $this->appointment_reads );
		$this->assertCount( 1, $this->updates );
		$this->assertSame( array( 'id' => 5 ), $this->updates[0][1], 'The handler cancelled appointment 5, as an int.' );
	}

	public function test_check_appointment_access_accepts_a_string_id(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 42 );
		$this->appointments[10] = array( 'id' => '10', 'user_id' => '42' );

		$result = ( new AppointmentRestController( 'ffc/v1' ) )->check_appointment_access( $this->make_request( array( 'id' => '10' ) ) );

		$this->assertTrue( $result );
		$this->assertSame( array( 10 ), $this->appointment_reads );
	}
}
