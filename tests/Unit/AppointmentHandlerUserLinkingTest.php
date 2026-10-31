<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Core\SensitiveFieldRegistry;
use FreeFormCertificate\SelfScheduling\AppointmentHandler;
use FreeFormCertificate\Tests\Support\Identifiers;

/**
 * Coverage for AppointmentHandler's user-linking branch and the
 * global-holiday short-circuit in get_available_slots().
 *
 * create_or_link_user() (the CPF/RF → WordPress-user resolution invoked from
 * process_appointment) runs for real here: DataSanitizer, SensitiveFieldRegistry,
 * Encryption (with the bootstrap's key constants), UserManager, UserCreator,
 * CapabilityManager, the appointment and profile repositories and Debug, down
 * to global WordPress stubs and a `$wpdb` double that answers the identity
 * lookups from the `ffc_user_profiles` rows a test stores and records every
 * lookup, adoption and account it saw. The global-holiday test runs the real
 * DateBlockingService over the `ffc_global_holidays` option.
 *
 * The handler's own repositories and validator are still injected doubles:
 * the transaction around the insert is not under test, only the payload it
 * receives.
 *
 * @covers \FreeFormCertificate\SelfScheduling\AppointmentHandler
 */
class AppointmentHandlerUserLinkingTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private const PROFILES     = 'wp_ffc_user_profiles';
	private const APPOINTMENTS = 'wp_ffc_self_scheduling_appointments';
	private const SUBMISSIONS  = 'wp_ffc_submissions';

	/** The account the identity index files an identifier under, when a test stores one. */
	private const PERSON_ID = 42;

	private AppointmentHandler $handler;
	private $calendarRepo;
	private $appointmentRepo;
	private $blockedDateRepo;
	private $validator;
	private \ReflectionClass $ref;

	/** @var array<string, mixed> Captured createAppointment() payload. */
	private array $captured = array();

	/** @var array<string, mixed> Stored options, by name. */
	private array $options = array();

	/** @var array<int, array<string, mixed>> `ffc_user_profiles` rows (the identity index), by user id. */
	private array $profiles = array();

	/** @var list<array{0: string, 1: string}> Identity lookups, as [table, WHERE clause]. */
	private array $lookups = array();

	/** @var bool Whether the identity lookup throws, as a database that went away would. */
	private bool $lookup_throws = false;

	/** @var list<string> Addresses get_user_by() was asked for. */
	private array $email_lookups = array();

	/** @var list<string> Addresses wp_create_user() was asked to register. */
	private array $created_accounts = array();

	/** @var mixed What wp_create_user() answers. */
	private $create_user_result = 900;

	/** @var list<string> Queries the double was not written to answer. */
	private array $unexpected = array();

	/** @var mixed The `$wpdb` global an earlier test left, restored afterwards. */
	private $wpdb_backup;

	/** @var bool Whether `$wpdb` was set before this test. */
	private bool $had_wpdb = false;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->captured           = array();
		$this->options            = array();
		$this->profiles           = array();
		$this->lookups            = array();
		$this->lookup_throws      = false;
		$this->email_lookups      = array();
		$this->created_accounts   = array();
		$this->create_user_result = 900;
		$this->unexpected         = array();

		$this->had_wpdb    = array_key_exists( 'wpdb', $GLOBALS );
		$this->wpdb_backup = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb']   = $this->make_wpdb();

		Functions\when( '__' )->returnArg();
		// Debug reads its area toggles from `ffc_settings`: none is on.
		Functions\when( 'get_option' )->alias(
			function ( $name, $default_value = false ) {
				return array_key_exists( $name, $this->options ) ? $this->options[ $name ] : $default_value;
			}
		);
		Functions\when( 'is_wp_error' )->alias( function ( $thing ) {
			return $thing instanceof \WP_Error;
		} );
		Functions\when( 'do_action' )->justReturn( null );
		Functions\when( 'apply_filters' )->alias( function () {
			$args = func_get_args();
			return $args[1] ?? null;
		} );
		Functions\when( 'get_current_user_id' )->justReturn( 0 );
		Functions\when( 'is_user_logged_in' )->justReturn( false );
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( 'user_can' )->justReturn( false );
		Functions\when( 'wp_timezone' )->alias( function () {
			return new \DateTimeZone( 'UTC' );
		} );
		Functions\when( 'wp_date' )->alias( function ( $format, $ts = null ) {
			return gmdate( $format, $ts ?? time() );
		} );
		Functions\when( 'home_url' )->alias( function ( $path = '' ) {
			return 'https://example.com' . $path;
		} );
		Functions\when( 'trailingslashit' )->alias( function ( $url ) {
			return rtrim( $url, '/' ) . '/';
		} );
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'current_time' )->justReturn( '2026-03-01 08:00:00' );
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		Functions\when( 'wp_cache_flush' )->justReturn( true );
		// clear_cache() with no key asks the backend first (#2241); without
		// group flushing it falls back to wp_cache_flush(), stubbed above.
		Functions\when( 'wp_cache_supports' )->justReturn( false );
		$this->stub_accounts();

		$this->handler = new AppointmentHandler();
		$this->ref     = new \ReflectionClass( AppointmentHandler::class );

		$this->calendarRepo    = Mockery::mock( 'FreeFormCertificate\Repositories\CalendarRepository' );
		$this->appointmentRepo = Mockery::mock( 'FreeFormCertificate\Repositories\AppointmentRepository' );
		$this->blockedDateRepo = Mockery::mock( 'FreeFormCertificate\Repositories\BlockedDateRepository' );
		$this->validator       = Mockery::mock( 'FreeFormCertificate\SelfScheduling\AppointmentValidator' );
		// Default: no waitlist diversion (#941 phase 2).
		$this->validator->shouldReceive( 'is_waitlist_requested' )->andReturn( false )->byDefault();
		$this->validator->shouldReceive( 'get_override_reasons' )->andReturn( array() )->byDefault();

		$this->setPrivate( 'calendar_repository', $this->calendarRepo );
		$this->setPrivate( 'appointment_repository', $this->appointmentRepo );
		$this->setPrivate( 'blocked_date_repository', $this->blockedDateRepo );
		$this->setPrivate( 'validator', $this->validator );
	}

	protected function tearDown(): void {
		if ( $this->had_wpdb ) {
			$GLOBALS['wpdb'] = $this->wpdb_backup;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * The linking catches every exception, an unexpected query's included, so
	 * an unanswered query is checked here, outside its try block.
	 */
	protected function assertPostConditions(): void {
		$this->assertSame( array(), $this->unexpected, 'Every query the code ran was one the double answers.' );
	}

	/**
	 * Stub the WordPress user API the real UserCreator reaches.
	 *
	 * No address has an account, and no account has a user object to read
	 * (get_userdata() answers false), so the capability grants stop at their
	 * own guard: they are CapabilityManager's to test, not this handler's.
	 */
	private function stub_accounts(): void {
		Functions\when( 'get_user_by' )->alias(
			function ( $field, $value ) {
				$this->email_lookups[] = (string) $value;
				return false;
			}
		);
		Functions\when( 'get_userdata' )->justReturn( false );
		Functions\when( 'username_exists' )->justReturn( false );
		Functions\when( 'remove_accents' )->returnArg();
		Functions\when( 'sanitize_user' )->alias( static fn ( $name ) => strtolower( (string) preg_replace( '/[^A-Za-z0-9 _.@-]/', '', (string) $name ) ) );
		Functions\when( 'wp_generate_password' )->justReturn( 'generated-password' );
		Functions\when( 'wp_create_user' )->alias(
			function ( $username, $password, $email ) {
				$this->created_accounts[] = (string) $email;
				return $this->create_user_result;
			}
		);
		Functions\when( 'wp_update_user' )->alias( static fn ( $data ) => (int) $data['ID'] );
		Functions\when( 'update_user_meta' )->justReturn( true );
	}

	/**
	 * A `$wpdb` double that renders prepare() into plain SQL and answers the
	 * identity lookups, the adoption UPDATEs and the identity-index reads.
	 */
	private function make_wpdb(): object {
		$wpdb             = Mockery::mock( 'wpdb' );
		$wpdb->prefix     = 'wp_';
		$wpdb->last_error = '';
		$wpdb->insert_id  = 0;
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
		$wpdb->shouldReceive( 'get_var' )->andReturnUsing(
			function ( $sql ) {
				// Which account carries the identifier: the identity index
				// answers from the rows the test stored, and no certificate
				// submission carries it.
				if ( preg_match( '/^SELECT user_id FROM `(' . self::PROFILES . '|' . self::SUBMISSIONS . ')` WHERE \((.+)\) AND user_id IS NOT NULL LIMIT 1$/', $sql, $m ) ) {
					$this->lookups[] = array( $m[1], $m[2] );
					if ( $this->lookup_throws ) {
						throw new \RuntimeException( 'db down' );
					}
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
				// An established install: both tables exist.
				if ( preg_match( "/^SHOW TABLES LIKE '(" . self::PROFILES . '|' . self::APPOINTMENTS . ")'$/", $sql, $m ) ) {
					return $m[1];
				}
				if ( preg_match( '/^SELECT id FROM `' . self::PROFILES . '` WHERE user_id = (\d+)$/', $sql, $m ) ) {
					return isset( $this->profiles[ (int) $m[1] ] ) ? (string) $this->profiles[ (int) $m[1] ]['id'] : null;
				}
				$this->unexpected[] = 'get_var: ' . $sql;
				return null;
			}
		);
		$wpdb->shouldReceive( 'get_row' )->andReturnUsing(
			function ( $sql ) {
				if ( preg_match( '/^SELECT \* FROM `' . self::PROFILES . '` WHERE user_id = (\d+)$/', $sql, $m ) ) {
					return $this->profiles[ (int) $m[1] ] ?? null;
				}
				$this->unexpected[] = 'get_row: ' . $sql;
				return null;
			}
		);
		$wpdb->shouldReceive( 'query' )->andReturnUsing(
			function ( $sql ) {
				// The linking adopts the person's unlinked records: none here.
				if ( preg_match( '/^UPDATE `(' . self::SUBMISSIONS . '|' . self::APPOINTMENTS . ')` SET user_id = \d+ WHERE \(?(cpf_hash|rf_hash) = /', $sql ) ) {
					return 0;
				}
				$this->unexpected[] = 'query: ' . $sql;
				return false;
			}
		);
		$wpdb->shouldReceive( 'insert' )->andReturnUsing(
			function ( $table, $data ) {
				$this->unexpected[] = 'insert: ' . $table;
				return false;
			}
		);
		$wpdb->shouldReceive( 'update' )->andReturnUsing(
			function ( $table ) {
				$this->unexpected[] = 'update: ' . $table;
				return false;
			}
		);
		return $wpdb;
	}

	/**
	 * File an identifier hash under an account in the identity index.
	 *
	 * @param string $column `cpf_hash` or `rf_hash`.
	 * @param string $hash   The stored hash.
	 */
	private function store_profile( string $column, string $hash ): void {
		$this->profiles[ self::PERSON_ID ] = array(
			'id'      => '7',
			'user_id' => (string) self::PERSON_ID,
			$column   => $hash,
		);
	}

	private function setPrivate( string $name, $value ): void {
		$prop = $this->ref->getProperty( $name );
		$prop->setAccessible( true );
		$prop->setValue( $this->handler, $value );
	}

	/** @param array<string, mixed> $overrides */
	private function makeCalendar( array $overrides = array() ): array {
		return array_merge( array(
			'id'                        => 1,
			'status'                    => 'active',
			'slot_duration'             => 30,
			'slot_interval'             => 0,
			'max_appointments_per_slot' => 1,
			'requires_approval'         => 0,
			'allow_cancellation'        => 1,
			'cancellation_min_hours'    => 0,
			'email_config'              => '{}',
			'working_hours'             => array(),
		), $overrides );
	}

	/** Wire the transactional happy path, capturing the createAppointment payload. */
	private function expect_successful_insert( int $new_id ): void {
		$this->calendarRepo->shouldReceive( 'findById' )->andReturn( $this->makeCalendar() );
		$this->appointmentRepo->shouldReceive( 'begin_transaction' )->once();
		$this->validator->shouldReceive( 'validate' )->andReturn( true );
		$this->appointmentRepo->shouldReceive( 'createAppointment' )->once()
			->with( Mockery::on( function ( $data ) {
				$this->captured = $data;
				return true;
			} ) )
			->andReturn( $new_id );
		$this->appointmentRepo->shouldReceive( 'commit' )->once();
		$this->appointmentRepo->shouldReceive( 'findById' )->with( $new_id )->andReturn( array(
			'id' => $new_id, 'confirmation_token' => 'tok' . $new_id,
		) );
	}

	/**
	 * A CPF in the `xxx.xxx.xxx-xx` mask a visitor types.
	 *
	 * @param string $cpf Eleven digits.
	 */
	private static function masked_cpf( string $cpf ): string {
		return substr( $cpf, 0, 3 ) . '.' . substr( $cpf, 3, 3 ) . '.' . substr( $cpf, 6, 3 ) . '-' . substr( $cpf, 9 );
	}

	/**
	 * An RF in the `xxx.xxx-x` mask a visitor types.
	 *
	 * @param string $rf Seven digits.
	 */
	private static function masked_rf( string $rf ): string {
		return substr( $rf, 0, 3 ) . '.' . substr( $rf, 3, 3 ) . '-' . substr( $rf, 6 );
	}

	/** @return array<string, mixed> */
	private function booking_data(): array {
		return array(
			'calendar_id'      => 1,
			'appointment_date' => '2026-03-01',
			'start_time'       => '09:00',
			'consent_given'    => '1',
			'cpf_rf'           => self::masked_cpf( Identifiers::CPF_A ),
			'email'            => 'user@example.test',
			'name'             => 'Test User',
			'user_id'          => 0,
		);
	}

	/**
	 * The hash goes in the column its KIND names (#1313 PR 7).
	 *
	 * This is the whole point of leaving the legacy single-hash entry point:
	 * there the column was chosen by a separate `$identifier_type` argument,
	 * and a caller could pass a type that disagreed with the value. In the dual
	 * entry point the POSITION is the type, so the lookup and the column the
	 * row is written to are decided by the same classifier. The real resolver
	 * turns the position into the column it queries, so the lookups it ran
	 * are where the position shows.
	 *
	 * Both directions are asserted, because a mutation that swaps the two
	 * arguments passes any test that only checks one.
	 *
	 * @dataProvider identifier_position_provider
	 * @param string $typed  What the visitor types in the single `cpf_rf` field.
	 * @param string $digits Its digits-only form.
	 * @param string $kind   The kind it classifies as: `cpf` or `rf`.
	 */
	public function test_the_hash_goes_in_the_argument_its_kind_names( string $typed, string $digits, string $kind ): void {
		$hash   = SensitiveFieldRegistry::hash_identifier( $kind, $digits );
		$column = $kind . '_hash';
		$other  = 'rf' === $kind ? 'cpf_hash' : 'rf_hash';
		$this->assertNotNull( $hash, 'The fixture must be hashable, or nothing about the lookup is measured.' );
		$this->store_profile( $column, (string) $hash );

		$this->expect_successful_insert( 320 );

		$data           = $this->booking_data();
		$data['cpf_rf'] = $typed;
		$this->handler->process_appointment( $data );

		$this->assertNotSame( array(), $this->lookups, 'The resolver was never called, so nothing about its arguments was measured.' );

		$where = $this->lookups[0][1];
		$this->assertSame( "{$column} = '{$hash}'", $where, "The hash of '{$typed}' is not in the argument its kind names, so the lookup reads the wrong column." );
		$this->assertStringNotContainsString( $other, $where, "The other identifier argument is not null for '{$typed}', so the resolver would search a column this value was never written to." );
		$this->assertSame( self::PERSON_ID, $this->captured['user_id'], 'The account the index files the identifier under was not linked.' );
	}

	/**
	 * @return array<string, array{0: string, 1: string, 2: string}>
	 */
	public static function identifier_position_provider(): array {
		return array(
			'a CPF goes in the first argument' => array( self::masked_cpf( Identifiers::CPF_A ), Identifiers::CPF_A, 'cpf' ),
			'an RF goes in the second'         => array( self::masked_rf( Identifiers::RF_A ), Identifiers::RF_A, 'rf' ),
		);
	}

	public function test_links_created_user_id_into_appointment(): void {
		$this->store_profile( 'cpf_hash', (string) SensitiveFieldRegistry::hash_identifier( 'cpf', Identifiers::CPF_A ) );

		$this->expect_successful_insert( 300 );

		$result = $this->handler->process_appointment( $this->booking_data() );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( self::PERSON_ID, $this->captured['user_id'] );
		$this->assertSame( array(), $this->created_accounts, 'A person the index already knows must not get a second account.' );
	}

	public function test_wp_error_from_user_manager_leaves_user_id_unset(): void {
		// Nobody carries the identifier or the address, and registering the
		// account fails: the resolver answers with that WP_Error.
		$this->create_user_result = new \WP_Error( 'existing_user_login', 'exists' );

		$this->expect_successful_insert( 301 );

		$this->handler->process_appointment( $this->booking_data() );

		$this->assertSame( array( 'user@example.test' ), $this->created_accounts, 'The resolver did not reach account creation, so its error was never produced.' );
		// The user link is not applied when resolution errors.
		$this->assertSame( 0, $this->captured['user_id'] );
	}

	public function test_user_manager_exception_is_swallowed(): void {
		$this->lookup_throws = true;

		$this->expect_successful_insert( 302 );

		$result = $this->handler->process_appointment( $this->booking_data() );

		$this->assertNotSame( array(), $this->lookups, 'The resolver never reached the lookup that throws.' );
		// Booking still completes; the exception is logged and swallowed.
		$this->assertTrue( $result['success'] );
		$this->assertSame( 0, $this->captured['user_id'] );
	}

	public function test_empty_normalized_cpf_rf_skips_user_linking(): void {
		$this->expect_successful_insert( 303 );

		// Typed, but not one digit in it: it normalises to the empty string.
		$data           = $this->booking_data();
		$data['cpf_rf'] = '...-';
		$this->handler->process_appointment( $data );

		// The resolver must never be reached.
		$this->assertSame( array(), $this->lookups, 'An identifier with no digits still reached the identity lookup.' );
		$this->assertSame( array(), $this->email_lookups, 'An identifier with no digits still reached the e-mail lookup.' );
		$this->assertSame( array(), $this->created_accounts );
		$this->assertSame( 0, $this->captured['user_id'] );
	}

	public function test_global_holiday_returns_no_slots(): void {
		$this->options['ffc_global_holidays'] = array(
			array(
				'date'   => '2026-12-25',
				'reason' => 'Christmas',
			),
		);

		$this->calendarRepo->shouldReceive( 'getWithWorkingHours' )->andReturn( $this->makeCalendar() );
		// The holiday answers first: the blocked-date store is never asked.
		$this->blockedDateRepo->shouldReceive( 'getBlocksForDate' )->never();

		$result = $this->handler->get_available_slots( 1, '2026-12-25' );

		$this->assertSame( array(), $result );
	}
}
