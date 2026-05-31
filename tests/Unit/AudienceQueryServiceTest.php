<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Audience\AudienceQueryService;

/**
 * Tests for AudienceQueryService — the cross-table aggregator
 * introduced in #343 group B.
 *
 * @covers \FreeFormCertificate\Audience\AudienceQueryService
 */
class AudienceQueryServiceTest extends TestCase {

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

		$this->wpdb->shouldReceive( 'prepare' )->andReturnUsing( fn ( $sql ) => $sql )->byDefault();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// ------------------------------------------------------------------
	// count_user_self_join_memberships()
	// ------------------------------------------------------------------

	public function test_count_user_self_join_memberships_returns_int(): void {
		$this->wpdb->shouldReceive( 'get_var' )->once()->andReturn( '2' );

		$this->assertSame( 2, AudienceQueryService::count_user_self_join_memberships( 42 ) );
	}

	public function test_count_user_self_join_memberships_returns_zero_on_null(): void {
		$this->wpdb->shouldReceive( 'get_var' )->once()->andReturn( null );

		$this->assertSame( 0, AudienceQueryService::count_user_self_join_memberships( 42 ) );
	}

	public function test_count_user_self_join_memberships_short_circuits_on_invalid_user(): void {
		$this->wpdb->shouldNotReceive( 'get_var' );

		$this->assertSame( 0, AudienceQueryService::count_user_self_join_memberships( 0 ) );
		$this->assertSame( 0, AudienceQueryService::count_user_self_join_memberships( -1 ) );
	}

	// ------------------------------------------------------------------
	// find_user_joinable_audiences()
	// ------------------------------------------------------------------

	public function test_find_user_joinable_audiences_returns_normalized_rows(): void {
		$this->wpdb->shouldReceive( 'get_results' )->once()->andReturn(
			array(
				array(
					'id'        => '5',
					'name'      => 'Pais',
					'color'     => '#fff',
					'parent_id' => null,
					'is_member' => '0',
				),
				array(
					'id'        => '7',
					'name'      => 'Filho A',
					'color'     => '#aaa',
					'parent_id' => '5',
					'is_member' => '1',
				),
			)
		);

		$out = AudienceQueryService::find_user_joinable_audiences( 42 );

		$this->assertCount( 2, $out );
		$this->assertSame( 5, $out[0]['id'] );
		$this->assertNull( $out[0]['parent_id'] );
		$this->assertFalse( $out[0]['is_member'] );
		$this->assertSame( 5, $out[1]['parent_id'] );
		$this->assertTrue( $out[1]['is_member'] );
	}

	public function test_find_user_joinable_audiences_returns_empty_when_no_rows(): void {
		$this->wpdb->shouldReceive( 'get_results' )->once()->andReturn( array() );

		$this->assertSame( array(), AudienceQueryService::find_user_joinable_audiences( 42 ) );
	}

	public function test_find_user_joinable_audiences_short_circuits_on_invalid_user(): void {
		$this->wpdb->shouldNotReceive( 'get_results' );

		$this->assertSame( array(), AudienceQueryService::find_user_joinable_audiences( 0 ) );
	}

	// ------------------------------------------------------------------
	// find_user_bookings()
	// ------------------------------------------------------------------

	public function test_find_user_bookings_returns_empty_when_no_rows(): void {
		$this->wpdb->shouldReceive( 'get_results' )->once()->andReturn( array() );

		$this->assertSame( array(), AudienceQueryService::find_user_bookings( 42 ) );
	}

	public function test_find_user_bookings_nests_audiences_per_booking(): void {
		// First get_results: the bookings JOIN. Second: the batch
		// audiences lookup keyed by booking_id.
		$this->wpdb->shouldReceive( 'get_results' )
			->andReturn(
				array(
					array( 'id' => '11', 'booking_date' => '2026-06-01' ),
					array( 'id' => '12', 'booking_date' => '2026-06-02' ),
				),
				array(
					array( 'booking_id' => '11', 'name' => 'VIP', 'color' => '#ff0' ),
					array( 'booking_id' => '12', 'name' => 'New', 'color' => '#0f0' ),
					array( 'booking_id' => '12', 'name' => 'Pro', 'color' => '#00f' ),
				)
			);

		$out = AudienceQueryService::find_user_bookings( 42 );

		$this->assertCount( 2, $out );
		$this->assertCount( 1, $out[0]['audiences'] );
		$this->assertSame( 'VIP', $out[0]['audiences'][0]['name'] );
		$this->assertCount( 2, $out[1]['audiences'] );
	}

	public function test_find_user_bookings_passes_filter_clauses(): void {
		$captured_sql = null;
		$this->wpdb->shouldReceive( 'prepare' )
			->andReturnUsing(
				function ( $sql ) use ( &$captured_sql ) {
					if ( null === $captured_sql ) {
						$captured_sql = $sql;
					}
					return $sql;
				}
			);
		$this->wpdb->shouldReceive( 'get_results' )->andReturn( array() );

		AudienceQueryService::find_user_bookings(
			42,
			array(
				'start_date'     => '2026-06-01',
				'exclude_status' => 'cancelled',
			)
		);

		$this->assertNotNull( $captured_sql );
		$this->assertStringContainsString( 'b.booking_date >= %s', (string) $captured_sql );
		$this->assertStringContainsString( 'b.status != %s', (string) $captured_sql );
	}

	public function test_find_user_bookings_short_circuits_on_invalid_user(): void {
		$this->wpdb->shouldNotReceive( 'get_results' );

		$this->assertSame( array(), AudienceQueryService::find_user_bookings( 0 ) );
	}

	// ------------------------------------------------------------------
	// count_user_bookings()
	// ------------------------------------------------------------------

	public function test_count_user_bookings_returns_int(): void {
		$this->wpdb->shouldReceive( 'get_var' )->once()->andReturn( '7' );

		$this->assertSame( 7, AudienceQueryService::count_user_bookings( 42 ) );
	}

	public function test_count_user_bookings_returns_zero_on_null(): void {
		$this->wpdb->shouldReceive( 'get_var' )->once()->andReturn( null );

		$this->assertSame( 0, AudienceQueryService::count_user_bookings( 42 ) );
	}

	public function test_count_user_bookings_short_circuits_on_invalid_user(): void {
		$this->wpdb->shouldNotReceive( 'get_var' );

		$this->assertSame( 0, AudienceQueryService::count_user_bookings( 0 ) );
	}
}
