<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Audience\AudienceBookingReader;

/**
 * Tests for the AudienceBookingReader queries that reach across modules:
 * who a booking affects, which members already have an overlapping booking,
 * and which audiences already have a booking that day — including the
 * isolated-schedule scope and the exclusion of the booking being edited.
 *
 * AudienceReader and AudienceEnvironmentRepository are aliased, so each test
 * runs in its own process.
 *
 * @covers \FreeFormCertificate\Audience\AudienceBookingReader
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class AudienceBookingReaderConflictsTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var Mockery\MockInterface */
	private $wpdb;

	/** @var Mockery\MockInterface */
	private $audiences;

	/** @var list<array<int, mixed>> Every prepare() call, as its argument list. */
	private array $prepared = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		global $wpdb;
		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$this->wpdb   = $wpdb;

		$this->prepared = array();
		$prepared       = &$this->prepared;
		$this->wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			static function ( ...$args ) use ( &$prepared ) {
				$flat       = 2 === count( $args ) && is_array( $args[1] ) ? array_merge( array( $args[0] ), $args[1] ) : $args;
				$prepared[] = $flat;
				return 'Q' . ( count( $prepared ) - 1 ) . ':' . $flat[0];
			}
		);

		$this->audiences = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceReader' );
		$this->audiences->shouldReceive( 'get_table_name' )->andReturn( 'wp_ffc_audiences' );
		$this->audiences->shouldReceive( 'get_members_table_name' )->andReturn( 'wp_ffc_audience_members' );
		Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceEnvironmentRepository' )
			->shouldReceive( 'get_table_name' )->andReturn( 'wp_ffc_audience_environments' );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * The prepare() call whose SQL contains $needle.
	 *
	 * @return array<int, mixed>
	 */
	private function preparedWith( string $needle ): array {
		foreach ( $this->prepared as $call ) {
			if ( false !== strpos( (string) $call[0], $needle ) ) {
				return $call;
			}
		}
		$this->fail( "No prepared query contains {$needle}" );
	}

	// ==================================================================
	// get_all_affected_users()
	// ==================================================================

	public function test_get_all_affected_users_merges_direct_users_and_audience_members_once(): void {
		$this->wpdb->shouldReceive( 'get_col' )->once()->andReturn( array( '1', '2' ) );
		$this->wpdb->shouldReceive( 'get_results' )->once()->andReturn(
			array( (object) array( 'id' => '5' ), (object) array( 'id' => '6' ) )
		);
		$this->audiences->shouldReceive( 'get_members' )->with( 5, true )->andReturn( array( 2, 3 ) );
		$this->audiences->shouldReceive( 'get_members' )->with( 6, true )->andReturn( array( 3, 4 ) );

		$users = AudienceBookingReader::get_all_affected_users( 40 );

		$this->assertSame( array( 1, 2, 3, 4 ), array_values( $users ) );
		$this->assertSame( 40, $this->preparedWith( 'SELECT user_id' )[2] );
	}

	// ==================================================================
	// get_user_conflicts()
	// ==================================================================

	public function test_get_user_conflicts_with_nobody_affected_queries_nothing(): void {
		$this->audiences->shouldReceive( 'get_members' )->with( 9, true )->andReturn( array() );
		$this->wpdb->shouldNotReceive( 'get_results' );

		$result = AudienceBookingReader::get_user_conflicts( '2026-05-04', '09:00', '10:00', array( 9 ), array() );

		$this->assertSame( array( 'bookings' => array(), 'affected_users' => array() ), $result );
	}

	public function test_get_user_conflicts_reports_only_the_overlapping_members(): void {
		$this->audiences->shouldReceive( 'get_members' )->with( 9, true )->andReturn( array( 20, 21 ) );
		// Conflict query, then get_booking_audiences() for the conflicting booking.
		$this->wpdb->shouldReceive( 'get_results' )->andReturnUsing(
			static function ( $sql ) {
				if ( false !== strpos( $sql, 'SELECT DISTINCT b.*' ) ) {
					return array( (object) array( 'id' => '77' ) );
				}
				return array();
			}
		);
		$this->wpdb->shouldReceive( 'get_col' )->andReturn( array( '21', '99' ) );

		$result = AudienceBookingReader::get_user_conflicts( '2026-05-04', '09:00', '10:00', array( 9 ), array( 20 ) );

		$this->assertSame( '77', $result['bookings'][0]->id );
		$this->assertSame( array( 21 ), array_values( $result['affected_users'] ), 'Only a member of both bookings is affected.' );

		$query = $this->preparedWith( 'SELECT DISTINCT b.*' );
		$this->assertStringNotContainsString( 'INNER JOIN %i env', $query[0], 'No scope, no environment join.' );
		$this->assertSame(
			array( 'wp_ffc_audience_bookings', 'wp_ffc_audience_booking_audiences', 'wp_ffc_audience_members', 'wp_ffc_audience_booking_users', '2026-05-04' ),
			array_slice( $query, 1, 5 )
		);
		$this->assertSame( array( 20, 21, 20, 21 ), array_slice( $query, 12 ), 'The user list fills both IN() clauses.' );
	}

	public function test_get_user_conflicts_scoped_to_a_schedule_excludes_the_edited_booking(): void {
		$this->wpdb->shouldReceive( 'get_results' )->once()->andReturn( null );

		$result = AudienceBookingReader::get_user_conflicts( '2026-05-04', '09:00', '10:00', array(), array( 30 ), 15, 3 );

		$this->assertSame( array( 'bookings' => array(), 'affected_users' => array() ), $result, 'A failed query reads as no conflict.' );
		$this->assertSame( array( 'AND b.id != %d', 15 ), $this->preparedWith( 'AND b.id != %d' ) );
		$query = $this->preparedWith( 'SELECT DISTINCT b.*' );
		$this->assertStringContainsString( 'INNER JOIN %i env ON b.environment_id = env.id', $query[0] );
		$this->assertStringContainsString( 'AND env.schedule_id = %d', $query[0] );
		$this->assertStringContainsString( 'Q0:AND b.id != %d', $query[0], 'The prepared exclusion is interpolated.' );
		$this->assertSame( 'wp_ffc_audience_environments', $query[5] );
		$this->assertSame( 3, $query[ count( $query ) - 1 ], 'The schedule id binds last.' );
	}

	// ==================================================================
	// get_audience_same_day_bookings()
	// ==================================================================

	public function test_get_audience_same_day_bookings_scoped_to_a_schedule(): void {
		$this->wpdb->shouldReceive( 'get_results' )->once()->andReturn(
			array( (object) array( 'id' => '8', 'audience_name' => 'Teachers' ) )
		);

		$rows = AudienceBookingReader::get_audience_same_day_bookings( '2026-05-04', array( 4, 5 ), 15, 3 );

		$this->assertSame( 'Teachers', $rows[0]->audience_name );
		$query = $this->preparedWith( 'a.name AS audience_name' );
		$this->assertStringContainsString( 'INNER JOIN %i env ON b.environment_id = env.id', $query[0] );
		$this->assertStringContainsString( 'ba.audience_id IN (%d,%d)', $query[0] );
		$this->assertSame(
			array( 'wp_ffc_audience_bookings', 'wp_ffc_audience_booking_audiences', 'wp_ffc_audiences', 'wp_ffc_audience_environments', '2026-05-04', 4, 5, 3 ),
			array_slice( $query, 1 )
		);
	}

	public function test_get_audience_same_day_bookings_returns_empty_on_a_failed_query(): void {
		$this->wpdb->shouldReceive( 'get_results' )->once()->andReturn( null );

		$this->assertSame( array(), AudienceBookingReader::get_audience_same_day_bookings( '2026-05-04', array( 4 ) ) );
	}
}
