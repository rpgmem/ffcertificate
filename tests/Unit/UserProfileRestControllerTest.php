<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\API\UserProfileRestController;
use FreeFormCertificate\Core\Encryption;
use FreeFormCertificate\Tests\Support\ActivityLogBuffer;
use FreeFormCertificate\Tests\Support\Identifiers;

/**
 * Tests for UserProfileRestController: route registration, permission checks,
 * and endpoint callback error/success paths for profile, password, and privacy endpoints.
 *
 * Runs the real UserService, UserManager, UserProfileService and its
 * repository, UserIdentifiersQueryService, AudienceReader, CapabilityManager,
 * PiiAccessPolicy, RateLimiter, ActivityLog, DocumentFormatter and
 * Encryption (with the bootstrap's key constants) over a `$wpdb` double. The
 * double answers from the stored profile row, the identifier rows and the
 * audience rows a test sets, records every profile-table write, and records
 * any other query as unexpected, which assertPostConditions() fails on (the
 * endpoints catch every exception, a failed assertion included). User meta
 * and the rate limiter's transients live in the test; an audited reveal is
 * read from ActivityLog's write buffer.
 *
 * @covers \FreeFormCertificate\API\UserProfileRestController
 */
class UserProfileRestControllerTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var array Captured route registrations */
	private array $registered_routes = [];

	/**
	 * Every prepared statement, in order: [ sql, args ].
	 *
	 * @var list<array{0: string, 1: array<int, mixed>}>
	 */
	private array $prepared = array();

	/** @var list<string> Tables `SHOW TABLES LIKE` reports as present. */
	private array $tables = array();

	/** @var array<string, mixed>|null The stored `ffc_user_profiles` row. */
	private ?array $profile_row = null;

	/** @var list<array<string, mixed>>|\Exception The submission identifier rows, or what reading them throws. */
	private $identifier_rows = array();

	/** @var list<array<string, mixed>> The audience rows the badge query answers. */
	private array $audience_rows = array();

	/** @var int|false|\Exception What a profile-table write answers, or throws. */
	private $profile_write_result = 1;

	/**
	 * Every profile-table write: [ operation, data, where ].
	 *
	 * @var list<array{0: string, 1: array<string, mixed>, 2: array<string, mixed>}>
	 */
	private array $profile_writes = array();

	/** @var array<string, mixed> User meta of the profile's user, by key. */
	private array $meta = array();

	/**
	 * Every user-meta write: [ operation, key, value ].
	 *
	 * @var list<array{0: string, 1: string, 2: mixed}>
	 */
	private array $meta_writes = array();

	/** @var bool Whether the per-user rate limit is already used up. */
	private bool $user_limit_reached = false;

	/** @var list<string> Queries the double did not expect. */
	private array $unexpected = array();

	/** @var mixed The `$wpdb` global an earlier test left, restored afterwards. */
	private $wpdb_backup;

	/** @var bool Whether `$wpdb` was set before this test. */
	private bool $had_wpdb = false;

	/**
	 * A CPF in the `xxx.xxx.xxx-xx` mask a visitor types.
	 *
	 * @param string $cpf Eleven digits.
	 */
	private static function masked( string $cpf ): string {
		return substr( $cpf, 0, 3 ) . '.' . substr( $cpf, 3, 3 ) . '.' . substr( $cpf, 6, 3 ) . '-' . substr( $cpf, 9 );
	}

	/**
	 * An RF in the `xxx.xxx-x` mask.
	 *
	 * @param string $rf Seven digits.
	 */
	private static function masked_rf( string $rf ): string {
		return substr( $rf, 0, 3 ) . '.' . substr( $rf, 3, 3 ) . '-' . substr( $rf, 6 );
	}

	/**
	 * A CPF as the profile shows it: the first three and last two digits.
	 *
	 * @param string $cpf Eleven digits.
	 */
	private static function hidden( string $cpf ): string {
		return substr( $cpf, 0, 3 ) . '.***.***-' . substr( $cpf, 9 );
	}

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->registered_routes    = [];
		$this->prepared             = array();
		$this->tables               = array( 'wp_ffc_user_profiles' );
		$this->profile_row          = array(
			'id'           => '1',
			'user_id'      => '5',
			'display_name' => 'John Doe',
			'phone'        => '(11) 99999-0000',
			'department'   => 'Engineering',
			'organization' => 'Acme',
			'notes'        => '',
			'preferences'  => '{}',
			'created_at'   => '2024-01-15 08:00:00',
			'updated_at'   => '2024-01-15 08:00:00',
		);
		$this->identifier_rows      = array( $this->identifier_row( Identifiers::CPF_A, null ) );
		$this->audience_rows        = array();
		$this->profile_write_result = 1;
		$this->profile_writes       = array();
		$this->meta                 = array();
		$this->meta_writes          = array();
		$this->user_limit_reached   = false;
		$this->unexpected           = array();

		ActivityLogBuffer::reset();
		ActivityLogBuffer::stub_functions();

		Functions\when( 'register_rest_route' )->alias( function( $namespace, $route, $args ) {
			$this->registered_routes[] = array(
				'namespace' => $namespace,
				'route'     => $route,
				'args'      => $args,
			);
		});

		Functions\when( '__' )->returnArg();
		Functions\when( '_n' )->alias( static fn( $single, $plural, $n ) => 1 === (int) $n ? $single : $plural );
		Functions\when( 'absint' )->alias( function( $val ) { return abs( intval( $val ) ); } );
		Functions\when( 'sanitize_text_field' )->alias( function( $str ) { return trim( strip_tags( (string) $str ) ); } );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'rest_ensure_response' )->alias( function( $data ) { return $data; } );
		Functions\when( 'is_wp_error' )->alias( function( $thing ) { return $thing instanceof \WP_Error; } );
		Functions\when( 'get_current_user_id' )->justReturn( 0 );
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( 'user_can' )->justReturn( false );
		// The activity log is on, so an audited reveal reaches the buffer;
		// every debug area is off.
		Functions\when( 'get_option' )->alias(
			static fn( $key, $default = false ) => 'ffc_settings' === $key ? ActivityLogBuffer::ENABLED_SETTINGS : $default
		);
		// The profile masks every e-mail it returns (#1897).
		Functions\when( 'is_email' )->alias( static fn( $v ) => is_string( $v ) && 1 === substr_count( $v, '@' ) );
		Functions\when( 'date_i18n' )->alias( function( $format, $timestamp = false ) {
			return date( $format, $timestamp ?: time() );
		});
		Functions\when( 'wp_date' )->alias( function( $format, $timestamp = null, $tz = null ) {
			return date( $format, $timestamp ?? time() );
		});
		Functions\when( 'wp_timezone' )->alias( function() {
			return new \DateTimeZone( 'UTC' );
		});
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		Functions\when( 'wp_cache_flush' )->justReturn( true );
		Functions\when( 'get_user_meta' )->alias( fn( $uid, $key = '', $single = false ) => $this->meta[ $key ] ?? '' );
		Functions\when( 'update_user_meta' )->alias(
			function ( $uid, $key, $value ) {
				$this->meta_writes[] = array( 'update', (string) $key, $value );
				return true;
			}
		);
		Functions\when( 'delete_user_meta' )->alias(
			function ( $uid, $key ) {
				$this->meta_writes[] = array( 'delete', (string) $key, null );
				return true;
			}
		);
		Functions\when( 'wp_update_user' )->alias( static fn( $data ) => (int) ( $data['ID'] ?? 0 ) );
		// The per-user rate limit's counters (RateLimiter::check_user_limit()).
		Functions\when( 'get_transient' )->alias(
			fn( $key ) => $this->user_limit_reached && 0 === strpos( (string) $key, 'ffc_rate_user_' ) ? 99 : false
		);
		Functions\when( 'set_transient' )->justReturn( true );

		$this->install_wpdb();
	}

	/**
	 * A `$wpdb` double answering the profile, identifier and audience reads.
	 */
	private function install_wpdb(): void {
		$wpdb             = Mockery::mock( 'wpdb' );
		$wpdb->prefix     = 'wp_';
		$wpdb->last_error = '';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			function ( $sql, ...$args ) {
				$this->prepared[] = array( (string) $sql, $args );
				return 'Q' . ( count( $this->prepared ) - 1 );
			}
		);
		$wpdb->shouldReceive( 'get_var' )->andReturnUsing(
			function ( $query ) {
				[ $sql, $args ] = $this->statement( $query );
				if ( 'SHOW TABLES LIKE %s' === $sql ) {
					return in_array( $args[0], $this->tables, true ) ? $args[0] : null;
				}
				if ( 'SELECT id FROM %i WHERE user_id = %d' === $sql && 'wp_ffc_user_profiles' === $args[0] ) {
					return null !== $this->profile_row ? (string) $this->profile_row['id'] : null;
				}
				$this->unexpected[] = 'get_var(): ' . $sql;
				return null;
			}
		);
		$wpdb->shouldReceive( 'get_row' )->andReturnUsing(
			function ( $query ) {
				[ $sql, $args ] = $this->statement( $query );
				if ( 'SELECT * FROM %i WHERE user_id = %d' === $sql && 'wp_ffc_user_profiles' === $args[0] ) {
					return $this->profile_row;
				}
				$this->unexpected[] = 'get_row(): ' . $sql;
				return null;
			}
		);
		$wpdb->shouldReceive( 'get_results' )->andReturnUsing(
			function ( $query ) {
				[ $sql ] = $this->statement( $query );
				if ( 0 === strpos( $sql, 'SELECT cpf_encrypted, rf_encrypted FROM %i' ) ) {
					if ( $this->identifier_rows instanceof \Exception ) {
						throw $this->identifier_rows;
					}
					return $this->identifier_rows;
				}
				if ( false !== strpos( $sql, 'SELECT a.name, a.color' ) ) {
					return $this->audience_rows;
				}
				$this->unexpected[] = 'get_results(): ' . $sql;
				return array();
			}
		);
		$wpdb->shouldReceive( 'get_col' )->andReturnUsing(
			function ( $query ) {
				[ $sql ] = $this->statement( $query );
				if ( false !== strpos( $sql, 'SELECT email_encrypted FROM %i' ) || false !== strpos( $sql, 'SELECT data FROM %i' ) ) {
					// No submission carries an e-mail or a name: both fall back to the account.
					return array();
				}
				$this->unexpected[] = 'get_col(): ' . $sql;
				return array();
			}
		);
		$wpdb->shouldReceive( 'update' )->andReturnUsing(
			function ( $table, $data, $where ) {
				return $this->profile_write( 'update', (string) $table, (array) $data, (array) $where );
			}
		);
		$wpdb->shouldReceive( 'insert' )->andReturnUsing(
			function ( $table, $data ) {
				return $this->profile_write( 'insert', (string) $table, (array) $data, array() );
			}
		);

		$this->had_wpdb    = array_key_exists( 'wpdb', $GLOBALS );
		$this->wpdb_backup = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb']   = $wpdb;
	}

	/**
	 * Record a write to the profile table and answer it.
	 *
	 * @param string               $operation update or insert.
	 * @param string               $table     Table written.
	 * @param array<string, mixed> $data      Columns written.
	 * @param array<string, mixed> $where     Where clause of an update.
	 * @return int|false
	 */
	private function profile_write( string $operation, string $table, array $data, array $where ) {
		if ( 'wp_ffc_user_profiles' !== $table ) {
			$this->unexpected[] = $operation . '(): ' . $table;
			return false;
		}
		$this->profile_writes[] = array( $operation, $data, $where );
		if ( $this->profile_write_result instanceof \Exception ) {
			throw $this->profile_write_result;
		}
		return $this->profile_write_result;
	}

	/**
	 * The prepared statement behind a token the double handed out.
	 *
	 * @param mixed $query Token returned by prepare().
	 * @return array{0: string, 1: array<int, mixed>}
	 */
	private function statement( $query ): array {
		$query = (string) $query;
		if ( 1 === preg_match( '/^Q(\d+)$/', $query, $m ) ) {
			return $this->prepared[ (int) $m[1] ];
		}
		return array( $query, array() );
	}

	/**
	 * A submission identifier row, encrypted as the handler stores it.
	 *
	 * @param string|null $cpf CPF digits, or null.
	 * @param string|null $rf  RF digits, or null.
	 * @return array<string, mixed>
	 */
	private function identifier_row( ?string $cpf, ?string $rf ): array {
		return array(
			'cpf_encrypted' => null !== $cpf ? Encryption::encrypt( $cpf ) : null,
			'rf_encrypted'  => null !== $rf ? Encryption::encrypt( $rf ) : null,
		);
	}

	/**
	 * Store the user's birth date the way UserProfileService writes it.
	 *
	 * @param string $date ISO date.
	 */
	private function store_birth_date( string $date ): void {
		$this->meta['ffc_user_birth_date'] = Encryption::encrypt( $date );
	}

	/**
	 * The user ids the identifier query was asked about.
	 *
	 * @return list<mixed>
	 */
	private function identifier_reads(): array {
		$ids = array();
		foreach ( $this->prepared as [ $sql, $args ] ) {
			if ( 0 === strpos( $sql, 'SELECT cpf_encrypted, rf_encrypted FROM %i' ) ) {
				$ids[] = $args[1];
			}
		}
		return $ids;
	}

	/**
	 * The audited reveals in ActivityLog's buffer: [ action, level, context, user_id, submission_id ].
	 *
	 * @return list<array{0: string, 1: string, 2: mixed, 3: int, 4: int}>
	 */
	private function reveal_audits(): array {
		$out      = array();
		$contexts = ActivityLogBuffer::contexts();
		foreach ( ActivityLogBuffer::entries() as $i => $row ) {
			if ( 'user_profile_pii_revealed' === $row['action'] ) {
				$out[] = array( $row['action'], $row['level'], $contexts[ $i ]['context'], (int) $row['user_id'], (int) $row['submission_id'] );
			}
		}
		return $out;
	}

	protected function assertPostConditions(): void {
		$this->assertSame( array(), $this->unexpected, 'The controller ran a query the test did not expect.' );
		parent::assertPostConditions();
	}

	protected function tearDown(): void {
		ActivityLogBuffer::reset();
		if ( $this->had_wpdb ) {
			$GLOBALS['wpdb'] = $this->wpdb_backup;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Helper: create a mock WP_REST_Request with given params.
	 */
	private function make_request( array $params = [] ): object {
		$request = Mockery::mock( 'WP_REST_Request' );
		$request->shouldReceive( 'get_param' )->andReturnUsing( function( $key ) use ( $params ) {
			return $params[ $key ] ?? null;
		});
		return $request;
	}

	/**
	 * Helper: create a WP_User stub with standard properties.
	 */
	private function make_user( int $id = 5 ): \WP_User {
		$user = new \WP_User( $id );
		$user->user_email      = 'john@example.com';
		$user->user_pass       = 'hashed_password';
		$user->display_name    = 'John Doe';
		$user->user_registered = '2024-01-15 08:00:00';
		$user->roles           = array( 'subscriber' );
		return $user;
	}

	// ------------------------------------------------------------------
	// Route registration
	// ------------------------------------------------------------------

	public function test_register_routes_creates_four_endpoints(): void {
		$ctrl = new UserProfileRestController( 'ffc/v1' );
		$ctrl->register_routes();

		// Profile, reveal (#1863), password, privacy request.
		$this->assertCount( 4, $this->registered_routes );
	}

	public function test_profile_route_registered(): void {
		$ctrl = new UserProfileRestController( 'ffc/v1' );
		$ctrl->register_routes();

		$paths = array_column( $this->registered_routes, 'route' );
		$this->assertContains( '/user/profile', $paths );
	}

	public function test_change_password_route_registered(): void {
		$ctrl = new UserProfileRestController( 'ffc/v1' );
		$ctrl->register_routes();

		$paths = array_column( $this->registered_routes, 'route' );
		$this->assertContains( '/user/change-password', $paths );
	}

	public function test_privacy_request_route_registered(): void {
		$ctrl = new UserProfileRestController( 'ffc/v1' );
		$ctrl->register_routes();

		$paths = array_column( $this->registered_routes, 'route' );
		$this->assertContains( '/user/privacy-request', $paths );
	}

	public function test_all_routes_require_authentication(): void {
		$ctrl = new UserProfileRestController( 'ffc/v1' );
		$ctrl->register_routes();

		foreach ( $this->registered_routes as $route ) {
			$route_path = $route['route'];

			// Profile route has sub-arrays (GET + PUT)
			if ( isset( $route['args'][0] ) ) {
				foreach ( $route['args'] as $endpoint ) {
					$this->assertSame(
						'rest_permission',
						$endpoint['permission_callback'][1],
						"Route {$route_path} should require authentication"
					);
				}
			} else {
				$this->assertSame(
					'rest_permission',
					$route['args']['permission_callback'][1],
					"Route {$route_path} should require authentication"
				);
			}
		}
	}

	public function test_profile_route_supports_get_and_put(): void {
		$ctrl = new UserProfileRestController( 'ffc/v1' );
		$ctrl->register_routes();

		$profile = null;
		foreach ( $this->registered_routes as $route ) {
			if ( $route['route'] === '/user/profile' ) {
				$profile = $route;
				break;
			}
		}

		$this->assertNotNull( $profile, 'Profile route should exist' );
		$this->assertIsArray( $profile['args'] );
		$this->assertArrayHasKey( 0, $profile['args'] );
		$this->assertArrayHasKey( 1, $profile['args'] );
	}

	// ------------------------------------------------------------------
	// get_user_profile — error paths
	// ------------------------------------------------------------------

	public function test_get_user_profile_returns_error_when_not_logged_in(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 0 );
		Functions\when( 'current_user_can' )->justReturn( false );

		$ctrl    = new UserProfileRestController( 'ffc/v1' );
		$request = $this->make_request();
		$result  = $ctrl->get_user_profile( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'not_logged_in', $result->get_error_code() );
	}

	public function test_get_user_profile_returns_error_when_user_not_found(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( 'get_user_by' )->justReturn( false );
		// UserService::get_full_profile uses get_userdata internally —
		// returning null short-circuits to the same "user not found" path.
		Functions\when( 'get_userdata' )->justReturn( false );

		$ctrl    = new UserProfileRestController( 'ffc/v1' );
		$request = $this->make_request();
		$result  = $ctrl->get_user_profile( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'user_not_found', $result->get_error_code() );
	}

	public function test_get_user_profile_returns_profile_data_on_success(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'current_user_can' )->justReturn( false );

		$user = $this->make_user( 5 );
		Functions\when( 'get_user_by' )->justReturn( $user );
		Functions\when( 'get_userdata' )->justReturn( $user );

		$ctrl    = new UserProfileRestController( 'ffc/v1' );
		$request = $this->make_request();
		$result  = $ctrl->get_user_profile( $request );

		$this->assertIsArray( $result );
		$this->assertSame( 5, $result['user_id'] );
		$this->assertSame( 'John Doe', $result['display_name'] );
		$this->assertSame( 'j***@example.com', $result['email'] );
		$this->assertSame( '(**) *****-**00', $result['phone'] );
		$this->assertSame( 'Engineering', $result['department'] );
		$this->assertSame( 'Acme', $result['organization'] );
		$this->assertSame( array( 'subscriber' ), $result['roles'] );
		$this->assertArrayHasKey( 'member_since', $result );
		$this->assertSame( array(), $result['audience_groups'], 'No audience table: no groups.' );
	}

	// ------------------------------------------------------------------
	// View as user (#1863)
	// ------------------------------------------------------------------

	/**
	 * An administrator (uid 1) viewing user 5's dashboard, whose birth date is stored.
	 *
	 * @return void
	 */
	private function view_as_user_five(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		// The view-as gate: an administrator who may edit the account (#2246).
		Functions\when( 'current_user_can' )->alias( static fn( $cap ) => in_array( $cap, array( 'manage_options', 'edit_user' ), true ) );
		Functions\when( 'user_can' )->alias( static fn( $uid, $cap ) => 1 === $uid && 'manage_options' === $cap );
		$user = $this->make_user( 5 );
		Functions\when( 'get_user_by' )->justReturn( $user );
		Functions\when( 'get_userdata' )->justReturn( $user );
		$this->store_birth_date( '1990-05-20' );
	}

	public function test_view_as_masks_the_users_email_phone_and_birth_date(): void {
		$this->view_as_user_five();

		$result = ( new UserProfileRestController( 'ffc/v1' ) )->get_user_profile( $this->make_request( array( 'viewAsUserId' => 5 ) ) );

		$this->assertSame( 5, $result['user_id'] );
		$this->assertSame( 'j***@example.com', $result['email'] );
		$this->assertSame( array( 'j***@example.com' ), $result['emails'] );
		$this->assertSame( '(**) *****-**00', $result['phone'] );
		$this->assertSame( '', $result['birth_date'] );
		$this->assertSame( '20/05/****', $result['birth_date_display'] );
		$this->assertTrue( $result['pii_masked'] );
		$this->assertTrue( $result['can_reveal'] );
		$this->assertStringNotContainsString( 'john@example.com', (string) json_encode( $result ) );
	}

	public function test_the_owner_sees_their_own_profile_masked_with_reveal(): void {
		// #1897: the owner's own data is masked on screen too, and theirs to reveal.
		$this->stub_profile_reload();
		$this->store_birth_date( '1990-05-20' );

		$result = ( new UserProfileRestController( 'ffc/v1' ) )->get_user_profile( $this->make_request() );

		$this->assertSame( 'j***@example.com', $result['email'] );
		$this->assertSame( array( 'j***@example.com' ), $result['emails'] );
		$this->assertSame( '(**) *****-**00', $result['phone'] );
		$this->assertSame( '', $result['birth_date'] );
		$this->assertSame( '20/05/****', $result['birth_date_display'] );
		$this->assertSame( array( self::hidden( Identifiers::CPF_A ) ), $result['cpfs_masked'] );
		$this->assertTrue( $result['pii_masked'] );
		$this->assertTrue( $result['can_reveal'] );
		$encoded = (string) json_encode( $result );
		$this->assertStringNotContainsString( 'john@example.com', $encoded );
		$this->assertStringNotContainsString( '99999-0000', $encoded );
		$this->assertStringNotContainsString( '1990', $encoded );
		$this->assertStringNotContainsString( Identifiers::CPF_A, $encoded );
	}

	public function test_reveal_serves_one_field_in_view_as_and_audits_it(): void {
		$this->view_as_user_five();

		$result = ( new UserProfileRestController( 'ffc/v1' ) )->reveal_profile_field( $this->make_request( array( 'viewAsUserId' => 5, 'field' => 'phone' ) ) );

		$this->assertSame( array( 'field' => 'phone', 'value' => '(11) 99999-0000' ), $result );
		$this->assertSame(
			array(
				array(
					'user_profile_pii_revealed',
					'info',
					array( 'field_key' => 'phone', 'view_as' => true, 'subject_user_id' => 5 ),
					1,
					0,
				),
			),
			$this->reveal_audits()
		);
	}

	public function test_reveal_returns_every_linked_email_and_the_birth_date_display(): void {
		$this->view_as_user_five();
		$ctrl = new UserProfileRestController( 'ffc/v1' );

		$email = $ctrl->reveal_profile_field( $this->make_request( array( 'viewAsUserId' => 5, 'field' => 'email' ) ) );
		$birth = $ctrl->reveal_profile_field( $this->make_request( array( 'viewAsUserId' => 5, 'field' => 'birth_date' ) ) );

		$this->assertSame( 'john@example.com', $email['value'] );
		$this->assertSame( array( 'john@example.com' ), $email['values'] );
		$this->assertSame( '1990-05-20', $birth['value'] );
		$this->assertNotSame( '', $birth['display'] );
		$this->assertCount( 2, $this->reveal_audits(), 'Each reveal is audited.' );
	}

	public function test_the_owner_reveals_their_own_field_without_an_audit_row(): void {
		$this->stub_profile_reload();
		$this->store_birth_date( '1990-05-20' );
		$ctrl = new UserProfileRestController( 'ffc/v1' );

		$phone = $ctrl->reveal_profile_field( $this->make_request( array( 'field' => 'phone' ) ) );
		$email = $ctrl->reveal_profile_field( $this->make_request( array( 'field' => 'email' ) ) );
		$birth = $ctrl->reveal_profile_field( $this->make_request( array( 'field' => 'birth_date' ) ) );

		$this->assertSame( array( 'field' => 'phone', 'value' => '(11) 99999-0000' ), $phone );
		$this->assertSame( array( 'john@example.com' ), $email['values'] );
		$this->assertSame( '1990-05-20', $birth['value'] );
		$this->assertSame( array(), $this->reveal_audits() );
	}

	public function test_reveal_refuses_a_non_admin_asking_for_someone_else(): void {
		// viewAsUserId is honoured only for manage_options: anyone else who
		// names another account through the owner path is refused, and
		// nothing of either account is read (#1897).
		Functions\when( 'get_current_user_id' )->justReturn( 9 );
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\expect( 'get_userdata' )->never();
		Functions\expect( 'get_user_by' )->never();

		$result = ( new UserProfileRestController( 'ffc/v1' ) )->reveal_profile_field( $this->make_request( array( 'viewAsUserId' => 5, 'field' => 'email' ) ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ffc_reveal_denied', $result->get_error_code() );
		$this->assertSame( array( 'status' => 403 ), $result->get_error_data() );
		$this->assertSame( array(), $this->prepared, 'No query ran.' );
		$this->assertSame( array(), $this->reveal_audits() );
	}

	public function test_reveal_refuses_a_visitor_who_is_not_logged_in(): void {
		$result = ( new UserProfileRestController( 'ffc/v1' ) )->reveal_profile_field( $this->make_request( array( 'field' => 'phone' ) ) );

		$this->assertSame( 'not_logged_in', $result->get_error_code() );
		$this->assertSame( array(), $this->reveal_audits() );
	}

	public function test_reveal_refuses_an_unsupported_field(): void {
		$this->view_as_user_five();

		$result = ( new UserProfileRestController( 'ffc/v1' ) )->reveal_profile_field( $this->make_request( array( 'viewAsUserId' => 5, 'field' => 'cpf' ) ) );

		$this->assertSame( 'ffc_reveal_unsupported', $result->get_error_code() );
		$this->assertSame( array(), $this->reveal_audits() );
	}

	public function test_reveal_serves_the_formatted_cpf_rf_list_and_audits_it(): void {
		$this->view_as_user_five();
		$this->identifier_rows = array(
			$this->identifier_row( Identifiers::CPF_A, null ),
			$this->identifier_row( null, Identifiers::RF_A ),
		);

		$result = ( new UserProfileRestController( 'ffc/v1' ) )->reveal_profile_field( $this->make_request( array( 'viewAsUserId' => 5, 'field' => 'cpf_rf' ) ) );

		$this->assertSame(
			array(
				'field'  => 'cpf_rf',
				'values' => array( self::masked( Identifiers::CPF_A ), self::masked_rf( Identifiers::RF_A ) ),
				'value'  => self::masked( Identifiers::CPF_A ),
			),
			$result
		);
		$this->assertSame( array( 5 ), $this->identifier_reads(), 'The identifiers read are the viewed user\'s.' );
		$this->assertSame(
			array(
				array(
					'user_profile_pii_revealed',
					'info',
					array( 'field_key' => 'cpf_rf', 'view_as' => true, 'subject_user_id' => 5 ),
					1,
					0,
				),
			),
			$this->reveal_audits()
		);
	}

	public function test_reveal_of_cpf_rf_with_no_identifier_returns_an_empty_value(): void {
		$this->view_as_user_five();
		$this->identifier_rows = array();

		$result = ( new UserProfileRestController( 'ffc/v1' ) )->reveal_profile_field( $this->make_request( array( 'viewAsUserId' => 5, 'field' => 'cpf_rf' ) ) );

		$this->assertSame( array(), $result['values'] );
		$this->assertSame( '', $result['value'] );
		$this->assertSame( array( 5 ), $this->identifier_reads() );
		$this->assertCount( 1, $this->reveal_audits() );
	}

	public function test_the_owner_reveals_their_own_cpf_rf_without_an_audit_row(): void {
		$this->stub_profile_reload();

		$result = ( new UserProfileRestController( 'ffc/v1' ) )->reveal_profile_field( $this->make_request( array( 'field' => 'cpf_rf' ) ) );

		$this->assertSame( self::masked( Identifiers::CPF_A ), $result['value'] );
		$this->assertSame( array( 5 ), $this->identifier_reads() );
		$this->assertSame( array(), $this->reveal_audits() );
	}

	public function test_reveal_of_cpf_rf_refuses_a_viewer_the_policy_denies(): void {
		// The view-as gate passed, but the reveal policy, which reads the
		// viewer's own capabilities, says masked.
		$this->view_as_user_five();
		Functions\when( 'user_can' )->justReturn( false );

		$result = ( new UserProfileRestController( 'ffc/v1' ) )->reveal_profile_field( $this->make_request( array( 'viewAsUserId' => 5, 'field' => 'cpf_rf' ) ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ffc_reveal_denied', $result->get_error_code() );
		$this->assertSame( array(), $this->identifier_reads(), 'No identifier is read.' );
		$this->assertSame( array(), $this->reveal_audits() );
	}

	public function test_view_as_save_never_writes_a_masked_phone_back(): void {
		$this->view_as_user_five();

		( new UserProfileRestController( 'ffc/v1' ) )->update_user_profile(
			$this->make_request(
				array(
					'viewAsUserId' => 5,
					'phone'        => '(**) *****-**00',
					'department'   => 'Ops',
				)
			)
		);

		$this->assertSame(
			array( array( 'update', array( 'department' => 'Ops' ), array( 'user_id' => 5 ) ) ),
			$this->profile_writes
		);
	}

	public function test_owner_save_never_writes_a_masked_phone_back(): void {
		$this->stub_profile_reload();

		( new UserProfileRestController( 'ffc/v1' ) )->update_user_profile(
			$this->make_request(
				array(
					'phone'      => '(**) *****-**00',
					'department' => 'Ops',
				)
			)
		);

		$this->assertSame(
			array( array( 'update', array( 'department' => 'Ops' ), array( 'user_id' => 5 ) ) ),
			$this->profile_writes
		);
	}

	// ------------------------------------------------------------------
	// update_user_profile — error paths
	// ------------------------------------------------------------------

	public function test_update_user_profile_returns_error_when_not_logged_in(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 0 );
		Functions\when( 'current_user_can' )->justReturn( false );

		$ctrl    = new UserProfileRestController( 'ffc/v1' );
		$request = $this->make_request();
		$result  = $ctrl->update_user_profile( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'not_logged_in', $result->get_error_code() );
	}

	public function test_update_user_profile_returns_error_when_no_data(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'current_user_can' )->justReturn( false );

		// Request with no profile fields
		$ctrl    = new UserProfileRestController( 'ffc/v1' );
		$request = $this->make_request();
		$result  = $ctrl->update_user_profile( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'no_data', $result->get_error_code() );
		$this->assertSame( array(), $this->profile_writes );
	}

	public function test_update_user_profile_returns_error_when_update_fails(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'current_user_can' )->justReturn( false );
		// The profile table refuses the write.
		$this->profile_write_result = false;

		$ctrl    = new UserProfileRestController( 'ffc/v1' );
		$request = $this->make_request( array( 'display_name' => 'Jane Doe' ) );
		$result  = $ctrl->update_user_profile( $request );

		$this->assertCount( 1, $this->profile_writes, 'The write was attempted.' );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'update_failed', $result->get_error_code() );
	}

	public function test_update_user_profile_succeeds_with_valid_data(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'current_user_can' )->justReturn( false );

		$user = $this->make_user( 5 );
		Functions\when( 'get_user_by' )->justReturn( $user );
		// update_user_profile() re-invokes get_user_profile() to return
		// the freshly-saved row; the inner call hits UserService which
		// uses get_userdata.
		Functions\when( 'get_userdata' )->justReturn( $user );

		$ctrl    = new UserProfileRestController( 'ffc/v1' );
		$request = $this->make_request( array( 'display_name' => 'Jane Doe' ) );
		$result  = $ctrl->update_user_profile( $request );

		$this->assertSame(
			array( array( 'update', array( 'display_name' => 'Jane Doe' ), array( 'user_id' => 5 ) ) ),
			$this->profile_writes
		);
		// On success, it calls get_user_profile which returns the profile array
		$this->assertIsArray( $result );
		$this->assertSame( 5, $result['user_id'] );
	}

	// ------------------------------------------------------------------
	// birth_date (#1538)
	// ------------------------------------------------------------------

	/**
	 * Stub what the success path of update_user_profile() re-reads.
	 */
	private function stub_profile_reload(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'current_user_can' )->justReturn( false );
		$user = $this->make_user( 5 );
		Functions\when( 'get_user_by' )->justReturn( $user );
		Functions\when( 'get_userdata' )->justReturn( $user );
	}

	/**
	 * The birth-date meta writes: [ operation, key, plaintext ].
	 *
	 * @return list<array{0: string, 1: string, 2: mixed}>
	 */
	private function birth_date_writes(): array {
		$out = array();
		foreach ( $this->meta_writes as [ $op, $key, $value ] ) {
			if ( 'ffc_user_birth_date' === $key ) {
				$out[] = array( $op, $key, 'update' === $op ? Encryption::decrypt( (string) $value ) : null );
			}
		}
		return $out;
	}

	public function test_get_user_profile_masks_a_stored_birth_date(): void {
		$this->stub_profile_reload();
		$this->store_birth_date( '1990-05-20' );

		$result = ( new UserProfileRestController( 'ffc/v1' ) )->get_user_profile( $this->make_request() );

		$this->assertIsArray( $result );
		$this->assertSame( '', $result['birth_date'], 'The date is revealed on request, never sent masked or in clear (#1897).' );
		$this->assertSame( '20/05/****', $result['birth_date_display'], 'A stored date must reach the read-only view as a mask.' );
	}

	public function test_get_user_profile_returns_an_empty_birth_date_when_none_is_stored(): void {
		$this->stub_profile_reload();

		$result = ( new UserProfileRestController( 'ffc/v1' ) )->get_user_profile( $this->make_request() );

		$this->assertSame( '', $result['birth_date'] );
		$this->assertSame( '', $result['birth_date_display'] );
	}

	public function test_update_user_profile_stores_a_valid_birth_date_alone(): void {
		$this->stub_profile_reload();

		$result = ( new UserProfileRestController( 'ffc/v1' ) )->update_user_profile(
			$this->make_request( array( 'birth_date' => '1990-05-20' ) )
		);

		$this->assertSame( array(), $this->profile_writes, 'No profile-table column is written.' );
		$this->assertSame( array( array( 'update', 'ffc_user_birth_date', '1990-05-20' ) ), $this->birth_date_writes() );
		$this->assertIsArray( $result, 'A birth date on its own is a profile update, not "no data".' );
	}

	public function test_update_user_profile_clears_the_birth_date_on_an_empty_string(): void {
		$this->stub_profile_reload();
		$this->store_birth_date( '1990-05-20' );

		$result = ( new UserProfileRestController( 'ffc/v1' ) )->update_user_profile(
			$this->make_request( array( 'birth_date' => '' ) )
		);

		$this->assertSame( array( array( 'delete', 'ffc_user_birth_date', null ) ), $this->birth_date_writes() );
		$this->assertIsArray( $result );
	}

	/**
	 * @dataProvider implausible_birth_dates
	 */
	public function test_update_user_profile_refuses_an_implausible_birth_date( string $value ): void {
		$this->stub_profile_reload();

		$result = ( new UserProfileRestController( 'ffc/v1' ) )->update_user_profile(
			$this->make_request(
				array(
					'birth_date' => $value,
					'phone'      => '123',
				)
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_birth_date', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
		$this->assertSame( array(), $this->profile_writes, 'Nothing is written to the profile.' );
		$this->assertSame( array(), $this->meta_writes, 'Nothing is written to user meta.' );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public function implausible_birth_dates(): array {
		return array(
			'not a date'      => array( 'yesterday' ),
			'impossible day'  => array( '1990-02-30' ),
			'in the future'   => array( '2999-01-01' ),
			'too young'       => array( gmdate( 'Y-m-d', strtotime( '-5 years' ) ) ),
			'implausibly old' => array( '1800-01-01' ),
		);
	}

	// ------------------------------------------------------------------
	// change_password — error paths
	// ------------------------------------------------------------------

	public function test_change_password_returns_error_when_not_logged_in(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 0 );
		Functions\when( 'current_user_can' )->justReturn( false );

		$ctrl    = new UserProfileRestController( 'ffc/v1' );
		$request = $this->make_request();
		$result  = $ctrl->change_password( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'not_logged_in', $result->get_error_code() );
	}

	public function test_change_password_returns_error_when_new_password_empty(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'current_user_can' )->justReturn( false );

		$ctrl    = new UserProfileRestController( 'ffc/v1' );
		$request = $this->make_request( array( 'new_password' => '' ) );
		$result  = $ctrl->change_password( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'missing_fields', $result->get_error_code() );
	}

	public function test_change_password_returns_error_when_password_too_short(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'current_user_can' )->justReturn( false );

		$ctrl    = new UserProfileRestController( 'ffc/v1' );
		$request = $this->make_request( array( 'new_password' => 'short' ) );
		$result  = $ctrl->change_password( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'password_too_short', $result->get_error_code() );
	}

	public function test_change_password_returns_error_when_current_password_wrong(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'current_user_can' )->justReturn( false );

		$user = $this->make_user( 5 );
		Functions\when( 'get_user_by' )->justReturn( $user );
		Functions\when( 'wp_check_password' )->justReturn( false );

		$ctrl    = new UserProfileRestController( 'ffc/v1' );
		$request = $this->make_request( array(
			'current_password' => 'wrongpassword',
			'new_password'     => 'newpassword123',
		) );
		$result  = $ctrl->change_password( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'wrong_password', $result->get_error_code() );
	}

	public function test_change_password_succeeds_with_correct_credentials(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'current_user_can' )->justReturn( false );

		$user = $this->make_user( 5 );
		Functions\when( 'get_user_by' )->justReturn( $user );
		Functions\when( 'wp_check_password' )->justReturn( true );
		Functions\when( 'wp_set_password' )->justReturn( null );
		Functions\when( 'wp_set_current_user' )->justReturn( null );
		Functions\when( 'wp_set_auth_cookie' )->justReturn( null );

		$ctrl    = new UserProfileRestController( 'ffc/v1' );
		$request = $this->make_request( array(
			'current_password' => 'correctpassword',
			'new_password'     => 'newpassword123',
		) );
		$result  = $ctrl->change_password( $request );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
	}

	/**
	 * #2246 — no password changes while viewing as another account, for
	 * anyone: the route refuses before it reads a field or writes anything.
	 *
	 * @dataProvider view_as_targets
	 */
	public function test_change_password_is_refused_in_view_as( string $target ): void {
		$this->view_as_user_five();
		$set = \FreeFormCertificate\Tests\Support\CallLog::record( 'wp_set_password' );

		$result = ( new UserProfileRestController( 'ffc/v1' ) )->change_password(
			$this->make_request(
				array(
					'viewAsUserId'     => $target,
					'current_password' => '',
					'new_password'     => 'newpassword123',
				)
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ffc_view_as_password', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );
		$this->assertSame( 0, $set->count(), 'No password is written.' );
	}

	/**
	 * A valid target, and one the gate refuses: the second must not fall
	 * through to changing the administrator's own password.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function view_as_targets(): array {
		return array(
			'allowed target' => array( '5' ),
			'refused target' => array( '999' ),
		);
	}

	public function test_change_password_always_checks_the_current_password(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_user_by' )->justReturn( $this->make_user( 1 ) );
		$set = \FreeFormCertificate\Tests\Support\CallLog::record( 'wp_set_password' );

		$result = ( new UserProfileRestController( 'ffc/v1' ) )->change_password(
			$this->make_request( array( 'new_password' => 'newpassword123' ) )
		);

		$this->assertSame( 'missing_fields', $result->get_error_code(), 'An administrator is asked for their own password too.' );
		$this->assertSame( 0, $set->count() );
	}

	// ------------------------------------------------------------------
	// create_privacy_request — error paths
	// ------------------------------------------------------------------

	public function test_create_privacy_request_returns_error_when_not_logged_in(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 0 );
		Functions\when( 'current_user_can' )->justReturn( false );

		$ctrl    = new UserProfileRestController( 'ffc/v1' );
		$request = $this->make_request();
		$result  = $ctrl->create_privacy_request( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'not_logged_in', $result->get_error_code() );
	}

	public function test_create_privacy_request_returns_error_for_invalid_type(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'current_user_can' )->justReturn( false );

		$ctrl    = new UserProfileRestController( 'ffc/v1' );
		$request = $this->make_request( array( 'type' => 'invalid_type' ) );
		$result  = $ctrl->create_privacy_request( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_type', $result->get_error_code() );
	}

	public function test_create_privacy_request_succeeds_with_export_type(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'current_user_can' )->justReturn( false );

		$user = $this->make_user( 5 );
		Functions\when( 'get_user_by' )->justReturn( $user );
		Functions\when( 'wp_create_user_request' )->justReturn( 42 );
		// The confirmation e-mail is what makes WordPress act on the request
		// (#2246): it goes out once, for the request just created.
		Functions\expect( 'wp_send_user_request' )->once()->with( 42 )->andReturn( true );

		$ctrl    = new UserProfileRestController( 'ffc/v1' );
		$request = $this->make_request( array( 'type' => 'export_personal_data' ) );
		$result  = $ctrl->create_privacy_request( $request );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertStringContainsString( 'Check your e-mail to confirm', $result['message'] );
	}

	public function test_create_privacy_request_reports_a_confirmation_e_mail_that_did_not_go_out(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( 'get_user_by' )->justReturn( $this->make_user( 5 ) );
		Functions\when( 'wp_create_user_request' )->justReturn( 42 );
		Functions\when( 'wp_send_user_request' )->justReturn( new \WP_Error( 'privacy_email_error', 'Unable to send' ) );

		$result = ( new UserProfileRestController( 'ffc/v1' ) )->create_privacy_request(
			$this->make_request( array( 'type' => 'remove_personal_data' ) )
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'privacy_request_email_failed', $result->get_error_code() );
		$this->assertSame( 500, $result->get_error_data()['status'] );
	}

	public function test_create_privacy_request_sends_no_e_mail_when_the_request_was_refused(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'get_user_by' )->justReturn( $this->make_user( 5 ) );
		Functions\when( 'wp_create_user_request' )->justReturn( new \WP_Error( 'dup', 'already pending' ) );
		Functions\expect( 'wp_send_user_request' )->never();

		$result = ( new UserProfileRestController( 'ffc/v1' ) )->create_privacy_request(
			$this->make_request( array( 'type' => 'export_personal_data' ) )
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	// ==================================================================
	// Additional error branches + catch blocks (#910)
	// ==================================================================

	public function test_get_user_profile_includes_audience_groups(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'get_user_by' )->justReturn( $this->make_user( 5 ) );
		Functions\when( 'get_userdata' )->justReturn( $this->make_user( 5 ) );
		// The audience membership table exists, and the user belongs to three groups.
		$this->tables[]      = 'wp_ffc_audience_members';
		$this->audience_rows = array(
			array(
				'name'  => 'Group A',
				'color' => '#FFEB3B',
			),
			array(
				'name'  => 'Group B',
				'color' => '#1d2327',
			),
			array(
				'name'  => 'Group C',
				'color' => null,
			),
		);

		$result = ( new UserProfileRestController( 'ffc/v1' ) )->get_user_profile( $this->make_request() );

		$this->assertIsArray( $result );
		// The tag's text colour is computed server-side over the group's own
		// colour (#1826): dark over a pale yellow, light over a near-black, and
		// a group with no valid colour falls back to the neutral badge ground.
		$this->assertSame(
			array(
				array(
					'name'       => 'Group A',
					'color'      => '#ffeb3b',
					'text_color' => '#000000',
				),
				array(
					'name'       => 'Group B',
					'color'      => '#1d2327',
					'text_color' => '#ffffff',
				),
				array(
					'name'       => 'Group C',
					'color'      => '#e9ecef',
					'text_color' => '#000000',
				),
			),
			$result['audience_groups']
		);
	}

	public function test_get_user_profile_wraps_exception(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'get_user_by' )->justReturn( $this->make_user( 5 ) );
		Functions\when( 'get_userdata' )->justReturn( $this->make_user( 5 ) );
		// Reading the user's identifiers fails.
		$this->identifier_rows = new \RuntimeException( 'boom' );

		$result = ( new UserProfileRestController( 'ffc/v1' ) )->get_user_profile( $this->make_request() );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'get_profile_error', $result->get_error_code() );
	}

	public function test_update_user_profile_stores_the_posted_notification_preferences(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'get_user_by' )->justReturn( $this->make_user( 5 ) );
		Functions\when( 'get_userdata' )->justReturn( $this->make_user( 5 ) );

		$result = ( new UserProfileRestController( 'ffc/v1' ) )->update_user_profile(
			$this->make_request( array( 'preferences' => array( 'notify_date_messages' => false ) ) )
		);

		$this->assertIsArray( $result );
		$this->assertSame(
			array( array( 'update', array( 'preferences' => '{"notify_date_messages":false}' ), array( 'user_id' => 5 ) ) ),
			$this->profile_writes,
			'The posted preferences reach the profile, merged over the stored ones.'
		);
	}

	public function test_update_user_profile_wraps_exception(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		// The profile table write throws.
		$this->profile_write_result = new \RuntimeException( 'boom' );

		$result = ( new UserProfileRestController( 'ffc/v1' ) )->update_user_profile(
			$this->make_request( array( 'display_name' => 'X' ) )
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'update_profile_error', $result->get_error_code() );
	}

	public function test_change_password_rate_limited(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		$this->user_limit_reached = true;

		$result = ( new UserProfileRestController( 'ffc/v1' ) )->change_password(
			$this->make_request( array( 'new_password' => 'longenough1' ) )
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'rate_limited', $result->get_error_code() );
	}

	public function test_change_password_user_not_found(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'get_user_by' )->justReturn( false );

		$result = ( new UserProfileRestController( 'ffc/v1' ) )->change_password(
			$this->make_request( array( 'new_password' => 'longenough1' ) )
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'user_not_found', $result->get_error_code() );
	}

	public function test_change_password_requires_current_password(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'get_user_by' )->justReturn( $this->make_user( 5 ) );

		// Valid new password but no current password (and not view-as).
		$result = ( new UserProfileRestController( 'ffc/v1' ) )->change_password(
			$this->make_request( array( 'new_password' => 'longenough1' ) )
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'missing_fields', $result->get_error_code() );
	}

	public function test_change_password_wraps_exception(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'get_user_by' )->alias( static function () {
			throw new \RuntimeException( 'boom' );
		} );

		$result = ( new UserProfileRestController( 'ffc/v1' ) )->change_password(
			$this->make_request( array( 'new_password' => 'longenough1', 'current_password' => 'x' ) )
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'password_error', $result->get_error_code() );
	}

	public function test_create_privacy_request_rate_limited(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		$this->user_limit_reached = true;

		$result = ( new UserProfileRestController( 'ffc/v1' ) )->create_privacy_request(
			$this->make_request( array( 'type' => 'export_personal_data' ) )
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'rate_limited', $result->get_error_code() );
	}

	public function test_create_privacy_request_user_not_found(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'get_user_by' )->justReturn( false );

		$result = ( new UserProfileRestController( 'ffc/v1' ) )->create_privacy_request(
			$this->make_request( array( 'type' => 'export_personal_data' ) )
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'user_not_found', $result->get_error_code() );
	}

	public function test_create_privacy_request_bubbles_wp_error(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'get_user_by' )->justReturn( $this->make_user( 5 ) );
		Functions\when( 'wp_create_user_request' )->justReturn( new \WP_Error( 'dup', 'already pending' ) );

		$result = ( new UserProfileRestController( 'ffc/v1' ) )->create_privacy_request(
			$this->make_request( array( 'type' => 'remove_personal_data' ) )
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'privacy_request_error', $result->get_error_code() );
	}

	public function test_create_privacy_request_adds_admin_hint_for_admin(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'current_user_can' )->justReturn( true ); // admin → hint appended.
		Functions\when( 'get_user_by' )->justReturn( $this->make_user( 5 ) );
		Functions\when( 'wp_create_user_request' )->justReturn( 42 );
		Functions\when( 'wp_send_user_request' )->justReturn( true );

		$result = ( new UserProfileRestController( 'ffc/v1' ) )->create_privacy_request(
			$this->make_request( array( 'type' => 'export_personal_data' ) )
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertStringContainsString( 'Tools', $result['message'] );
	}

	public function test_create_privacy_request_wraps_exception(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'get_user_by' )->justReturn( $this->make_user( 5 ) );
		Functions\when( 'wp_create_user_request' )->alias( static function () {
			throw new \RuntimeException( 'boom' );
		} );

		$result = ( new UserProfileRestController( 'ffc/v1' ) )->create_privacy_request(
			$this->make_request( array( 'type' => 'export_personal_data' ) )
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'privacy_error', $result->get_error_code() );
	}
}
