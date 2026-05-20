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
		$wpdb         = Mockery::mock( 'wpdb' );
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
}
