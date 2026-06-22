<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Repositories\AppointmentRepository;

/**
 * Tests for the AppointmentRepository methods added in the issue
 * #340 centralization sweep. Each method replaces a raw wpdb call
 * that used to live in admin / cleanup / REST / user-creator code.
 *
 * @covers \FreeFormCertificate\Repositories\AppointmentRepository
 * @covers \FreeFormCertificate\Repositories\AppointmentReader
 * @covers \FreeFormCertificate\Repositories\AppointmentWriter
 */
class AppointmentRepositoryCentralizationTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var Mockery\MockInterface */
	private $wpdb;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		global $wpdb;
		$wpdb         = Mockery::mock( 'wpdb' )->makePartial();
		$wpdb->prefix = 'wp_';
		$this->wpdb   = $wpdb;

		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		Functions\when( 'wp_cache_flush' )->justReturn( true );
		Functions\when( 'wp_cache_flush_group' )->justReturn( true );

		$this->wpdb->shouldReceive( 'prepare' )->andReturnUsing( fn ( $sql ) => $sql )->byDefault();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function repo(): AppointmentRepository {
		return new AppointmentRepository();
	}

	// ------------------------------------------------------------------
	// countAllByUserGrouped()
	// ------------------------------------------------------------------

	public function test_count_all_by_user_grouped_returns_user_id_to_count_map(): void {
		$this->wpdb->shouldReceive( 'get_results' )->once()->andReturn(
			array(
				array( 'user_id' => '42', 'c' => '3' ),
				array( 'user_id' => '7', 'c' => '1' ),
			)
		);

		$out = $this->repo()->countAllByUserGrouped( 'cancelled' );

		$this->assertSame( array( 42 => 3, 7 => 1 ), $out );
	}

	public function test_count_all_by_user_grouped_returns_empty_when_no_rows(): void {
		$this->wpdb->shouldReceive( 'get_results' )->once()->andReturn( array() );

		$this->assertSame( array(), $this->repo()->countAllByUserGrouped() );
	}

	// ------------------------------------------------------------------
	// deleteByCalendar / Before / After / AndStatus
	// ------------------------------------------------------------------

	public function test_delete_by_calendar_short_circuits_on_invalid_id(): void {
		$this->wpdb->shouldNotReceive( 'query' );
		$this->assertSame( 0, $this->repo()->deleteByCalendar( 0 ) );
	}

	public function test_delete_by_calendar_returns_affected_rows(): void {
		$this->wpdb->shouldReceive( 'query' )->once()->andReturn( 10 );
		$this->assertSame( 10, $this->repo()->deleteByCalendar( 5 ) );
	}

	public function test_delete_by_calendar_before_validates_inputs(): void {
		$this->wpdb->shouldNotReceive( 'query' );
		$this->assertSame( 0, $this->repo()->deleteByCalendarBefore( 0, '2026-01-01' ) );
		$this->assertSame( 0, $this->repo()->deleteByCalendarBefore( 5, '' ) );
	}

	public function test_delete_by_calendar_before_returns_rows(): void {
		$this->wpdb->shouldReceive( 'query' )->once()->andReturn( 3 );
		$this->assertSame( 3, $this->repo()->deleteByCalendarBefore( 5, '2026-01-01' ) );
	}

	public function test_delete_by_calendar_after_returns_rows(): void {
		$this->wpdb->shouldReceive( 'query' )->once()->andReturn( 7 );
		$this->assertSame( 7, $this->repo()->deleteByCalendarAfter( 5, '2026-01-01' ) );
	}

	public function test_delete_by_calendar_and_status_returns_rows(): void {
		$this->wpdb->shouldReceive( 'query' )->once()->andReturn( 2 );
		$this->assertSame( 2, $this->repo()->deleteByCalendarAndStatus( 5, 'cancelled' ) );
	}

	// ------------------------------------------------------------------
	// countByCalendarBefore / After
	// ------------------------------------------------------------------

	public function test_count_by_calendar_before_returns_int(): void {
		$this->wpdb->shouldReceive( 'get_var' )->once()->andReturn( '12' );
		$this->assertSame( 12, $this->repo()->countByCalendarBefore( 5, '2026-01-01' ) );
	}

	public function test_count_by_calendar_after_returns_int(): void {
		$this->wpdb->shouldReceive( 'get_var' )->once()->andReturn( '4' );
		$this->assertSame( 4, $this->repo()->countByCalendarAfter( 5, '2026-01-01' ) );
	}

	public function test_count_by_calendar_short_circuits_on_invalid_inputs(): void {
		$this->wpdb->shouldNotReceive( 'get_var' );
		$this->assertSame( 0, $this->repo()->countByCalendarBefore( 0, '2026-01-01' ) );
		$this->assertSame( 0, $this->repo()->countByCalendarAfter( 5, '' ) );
	}

	// ------------------------------------------------------------------
	// findByCalendarAfterWithStatus()
	// ------------------------------------------------------------------

	public function test_find_by_calendar_after_with_status_returns_rows(): void {
		$rows = array( array( 'id' => '1', 'appointment_date' => '2026-06-01' ) );
		$this->wpdb->shouldReceive( 'get_results' )->once()->andReturn( $rows );

		$this->assertSame( $rows, $this->repo()->findByCalendarAfterWithStatus( 5, '2026-01-01' ) );
	}

	public function test_find_by_calendar_after_with_status_short_circuits_on_empty_statuses(): void {
		$this->wpdb->shouldNotReceive( 'get_results' );
		$this->assertSame( array(), $this->repo()->findByCalendarAfterWithStatus( 5, '2026-01-01', array() ) );
	}

	// ------------------------------------------------------------------
	// findNextUpcomingForUser()
	// ------------------------------------------------------------------

	public function test_find_next_upcoming_returns_row_when_found(): void {
		$row = array( 'id' => '1', 'appointment_date' => '2026-06-15', 'start_time' => '10:30:00', 'calendar_id' => '42' );
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( $row );

		$this->assertSame( $row, $this->repo()->findNextUpcomingForUser( 5 ) );
	}

	public function test_find_next_upcoming_returns_null_when_none(): void {
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( null );
		$this->assertNull( $this->repo()->findNextUpcomingForUser( 5 ) );
	}

	public function test_find_next_upcoming_short_circuits_on_invalid_inputs(): void {
		$this->wpdb->shouldNotReceive( 'get_row' );
		$this->assertNull( $this->repo()->findNextUpcomingForUser( 0 ) );
		$this->assertNull( $this->repo()->findNextUpcomingForUser( 5, array() ) );
	}

	// ------------------------------------------------------------------
	// linkByIdentifierHash()
	// ------------------------------------------------------------------

	public function test_link_by_identifier_hash_returns_affected_rows(): void {
		$this->wpdb->shouldReceive( 'query' )->once()->andReturn( 4 );
		$this->assertSame( 4, $this->repo()->linkByIdentifierHash( 5, 'cpf_hash', 'abc' ) );
	}

	public function test_link_by_identifier_hash_rejects_unknown_column(): void {
		$this->wpdb->shouldNotReceive( 'query' );
		$this->assertSame( 0, $this->repo()->linkByIdentifierHash( 5, 'wrong_column', 'abc' ) );
	}

	public function test_link_by_identifier_hash_rejects_invalid_inputs(): void {
		$this->wpdb->shouldNotReceive( 'query' );
		$this->assertSame( 0, $this->repo()->linkByIdentifierHash( 0, 'cpf_hash', 'abc' ) );
		$this->assertSame( 0, $this->repo()->linkByIdentifierHash( 5, 'cpf_hash', '' ) );
	}

	// ------------------------------------------------------------------
	// sql_user_appointment_count_subquery() — issue #343 group C
	// ------------------------------------------------------------------

	public function test_sql_user_appointment_count_subquery_returns_self_contained_select(): void {
		$sql = $this->repo()->sql_user_appointment_count_subquery();

		$this->assertStringStartsWith( '(SELECT ', $sql );
		$this->assertStringEndsWith( ')', $sql );
		$this->assertStringContainsString( 'wp_ffc_self_scheduling_appointments', $sql );
		$this->assertStringContainsString( 'GROUP BY user_id', $sql );
		$this->assertStringContainsString( "status != 'cancelled'", $sql );
	}
}
