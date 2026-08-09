<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\SelfScheduling\AppointmentHandler;

/**
 * Isolated coverage for AppointmentHandler's collaborator-heavy branches:
 * create_or_link_user() (the CPF/RF → WordPress-user resolution invoked from
 * process_appointment) and the global-holiday short-circuit in
 * get_available_slots().
 *
 * These drive statics on DataSanitizer / Encryption / UserManager / Debug /
 * DateBlockingService, so they alias-mock those classes and run in separate
 * processes to keep the aliases from leaking into the sibling AppointmentHandler
 * tests.
 *
 * @covers \FreeFormCertificate\SelfScheduling\AppointmentHandler
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class AppointmentHandlerUserLinkingTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private AppointmentHandler $handler;
	private $calendarRepo;
	private $appointmentRepo;
	private $blockedDateRepo;
	private $validator;
	private \ReflectionClass $ref;

	/** @var array<string, mixed> Captured createAppointment() payload. */
	private array $captured = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		global $wpdb;
		$wpdb             = Mockery::mock( 'wpdb' )->makePartial();
		$wpdb->prefix     = 'wp_';
		$wpdb->last_error = '';
		$wpdb->shouldReceive( 'prepare' )->andReturn( 'Q' )->byDefault();
		$wpdb->shouldReceive( 'get_row' )->andReturn( null )->byDefault();
		$wpdb->shouldReceive( 'get_var' )->andReturn( null )->byDefault();
		$wpdb->shouldReceive( 'get_results' )->andReturn( array() )->byDefault();
		$wpdb->shouldReceive( 'query' )->andReturn( true )->byDefault();

		Functions\when( '__' )->returnArg();
		Functions\when( 'get_option' )->justReturn( array() );
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

		// Real CapabilityManager for its CONTEXT_APPOINTMENT constant (referenced
		// in create_or_link_user); Utils for the class_exists() debug gate.
		class_exists( '\FreeFormCertificate\UserDashboard\CapabilityManager' );
		class_exists( '\FreeFormCertificate\Core\Utils' );

		// Debug is a no-op sink here (its output is not under test).
		Mockery::mock( 'alias:FreeFormCertificate\Core\Debug' )
			->shouldReceive( 'log_self_scheduling' )->andReturnNull()->byDefault();

		$this->handler = new AppointmentHandler();
		$this->ref     = new \ReflectionClass( AppointmentHandler::class );

		$this->calendarRepo    = Mockery::mock( 'FreeFormCertificate\Repositories\CalendarRepository' );
		$this->appointmentRepo = Mockery::mock( 'FreeFormCertificate\Repositories\AppointmentRepository' );
		$this->blockedDateRepo = Mockery::mock( 'FreeFormCertificate\Repositories\BlockedDateRepository' );
		$this->validator       = Mockery::mock( 'FreeFormCertificate\SelfScheduling\AppointmentValidator' );

		$this->setPrivate( 'calendar_repository', $this->calendarRepo );
		$this->setPrivate( 'appointment_repository', $this->appointmentRepo );
		$this->setPrivate( 'blocked_date_repository', $this->blockedDateRepo );
		$this->setPrivate( 'validator', $this->validator );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
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

	/** @return array<string, mixed> */
	private function booking_data(): array {
		return array(
			'calendar_id'      => 1,
			'appointment_date' => '2026-03-01',
			'start_time'       => '09:00',
			'consent_given'    => '1',
			'cpf_rf'           => '123.456.789-01',
			'email'            => 'user@example.test',
			'name'             => 'Test User',
			'user_id'          => 0,
		);
	}

	public function test_links_created_user_id_into_appointment(): void {
		Mockery::mock( 'alias:FreeFormCertificate\Core\DataSanitizer' )
			->shouldReceive( 'normalize_cpf_rf' )->andReturn( '12345678901' );
		Mockery::mock( 'alias:FreeFormCertificate\Core\Encryption' )
			->shouldReceive( 'hash' )->andReturn( 'hashed-cpf' );
		Mockery::mock( 'alias:FreeFormCertificate\UserDashboard\UserManager' )
			->shouldReceive( 'get_or_create_user' )->andReturn( 42 );

		$this->expect_successful_insert( 300 );

		$result = $this->handler->process_appointment( $this->booking_data() );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 42, $this->captured['user_id'] );
	}

	public function test_wp_error_from_user_manager_leaves_user_id_unset(): void {
		Mockery::mock( 'alias:FreeFormCertificate\Core\DataSanitizer' )
			->shouldReceive( 'normalize_cpf_rf' )->andReturn( '12345678901' );
		Mockery::mock( 'alias:FreeFormCertificate\Core\Encryption' )
			->shouldReceive( 'hash' )->andReturn( 'hashed-cpf' );
		Mockery::mock( 'alias:FreeFormCertificate\UserDashboard\UserManager' )
			->shouldReceive( 'get_or_create_user' )->andReturn( new \WP_Error( 'dup', 'exists' ) );

		$this->expect_successful_insert( 301 );

		$this->handler->process_appointment( $this->booking_data() );

		// The user link is not applied when resolution errors.
		$this->assertSame( 0, $this->captured['user_id'] );
	}

	public function test_user_manager_exception_is_swallowed(): void {
		Mockery::mock( 'alias:FreeFormCertificate\Core\DataSanitizer' )
			->shouldReceive( 'normalize_cpf_rf' )->andReturn( '12345678901' );
		Mockery::mock( 'alias:FreeFormCertificate\Core\Encryption' )
			->shouldReceive( 'hash' )->andReturn( 'hashed-cpf' );
		Mockery::mock( 'alias:FreeFormCertificate\UserDashboard\UserManager' )
			->shouldReceive( 'get_or_create_user' )->andThrow( new \RuntimeException( 'db down' ) );

		$this->expect_successful_insert( 302 );

		$result = $this->handler->process_appointment( $this->booking_data() );

		// Booking still completes; the exception is logged and swallowed.
		$this->assertTrue( $result['success'] );
		$this->assertSame( 0, $this->captured['user_id'] );
	}

	public function test_empty_normalized_cpf_rf_skips_user_linking(): void {
		Mockery::mock( 'alias:FreeFormCertificate\Core\DataSanitizer' )
			->shouldReceive( 'normalize_cpf_rf' )->andReturn( '' );
		// Encryption / UserManager must never be reached.
		Mockery::mock( 'alias:FreeFormCertificate\Core\Encryption' )
			->shouldReceive( 'hash' )->never();

		$this->expect_successful_insert( 303 );

		$this->handler->process_appointment( $this->booking_data() );

		$this->assertSame( 0, $this->captured['user_id'] );
	}

	public function test_global_holiday_returns_no_slots(): void {
		Mockery::mock( 'alias:FreeFormCertificate\Scheduling\DateBlockingService' )
			->shouldReceive( 'is_global_holiday' )->andReturn( true );

		$this->calendarRepo->shouldReceive( 'getWithWorkingHours' )->andReturn( $this->makeCalendar() );

		$result = $this->handler->get_available_slots( 1, '2026-12-25' );

		$this->assertSame( array(), $result );
	}
}
