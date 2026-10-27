<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Security\RateLimitStats;

/**
 * Tests for RateLimitStats: aggregate read-only queries over ffc_rate_limit_logs.
 */
class RateLimitStatsTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		global $wpdb;
		$wpdb         = Mockery::mock( 'wpdb' )->makePartial();
		$wpdb->prefix = 'wp_';
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_get_stats_returns_expected_keys(): void {
		global $wpdb;
		$wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );
		$wpdb->shouldReceive( 'get_var' )->andReturn( '42', '300' );
		$wpdb->shouldReceive( 'get_results' )->andReturn(
			array( array( 'type' => 'ip', 'count' => 10 ) ),
			array( array( 'identifier' => '203.0.113.1', 'count' => 7 ) )
		);

		$stats = RateLimitStats::get_stats();

		$this->assertIsArray( $stats );
		$this->assertSame(
			array( 'today', 'month', 'by_type', 'top_ips' ),
			array_keys( $stats )
		);
	}

	public function test_get_stats_propagates_today_and_month_counts(): void {
		global $wpdb;
		$wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );
		$wpdb->shouldReceive( 'get_var' )->andReturn( '5', '120' );
		$wpdb->shouldReceive( 'get_results' )->andReturn( array(), array() );

		$stats = RateLimitStats::get_stats();

		$this->assertSame( '5', $stats['today'] );
		$this->assertSame( '120', $stats['month'] );
	}

	public function test_get_stats_propagates_by_type_and_top_ips_rows(): void {
		global $wpdb;
		$by_type = array(
			array( 'type' => 'ip', 'count' => 8 ),
			array( 'type' => 'email', 'count' => 3 ),
		);
		$fingerprint = \FreeFormCertificate\Core\IpAddress::fingerprint( '198.51.100.9' );
		$top_ips     = array(
			// A row written before #2109: the address is the identifier.
			array( 'identifier' => '203.0.113.1', 'count' => '6' ),
			// A current row: a fingerprint, with the address encrypted beside it.
			array( 'identifier' => $fingerprint, 'count' => '4', 'ip_encrypted' => \FreeFormCertificate\Core\Encryption::encrypt( '198.51.100.9' ) ),
			// A current row whose address could not be encrypted.
			array( 'identifier' => $fingerprint . 'x', 'count' => '1', 'ip_encrypted' => null ),
		);

		$wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );
		$wpdb->shouldReceive( 'get_var' )->andReturn( '0', '0' );
		$wpdb->shouldReceive( 'get_results' )->andReturn( $by_type, $top_ips );

		$stats = RateLimitStats::get_stats();

		$this->assertSame( $by_type, $stats['by_type'] );
		$this->assertSame(
			array(
				array( 'identifier' => '203.0.113.1', 'count' => 6, 'label' => '203.0.***.***' ),
				array( 'identifier' => $fingerprint, 'count' => 4, 'label' => '198.51.***.***' ),
				array( 'identifier' => $fingerprint . 'x', 'count' => 1, 'label' => substr( $fingerprint, 0, 12 ) ),
			),
			$stats['top_ips'],
			'every address is masked; a row without one shows the start of its fingerprint'
		);
	}

	public function test_get_stats_uses_prefixed_logs_table_in_every_query(): void {
		global $wpdb;
		$tables = array();
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing( function ( $sql, ...$args ) use ( &$tables ) {
			// The %i placeholder receives the table name as the first arg.
			$tables[] = $args[0];
			return 'SQL';
		} );
		$wpdb->shouldReceive( 'get_var' )->andReturn( '0' );
		$wpdb->shouldReceive( 'get_results' )->andReturn( array() );

		RateLimitStats::get_stats();

		$this->assertCount( 4, $tables, 'expected one prepare() per stat query' );
		foreach ( $tables as $table ) {
			$this->assertSame( 'wp_ffc_rate_limit_logs', $table );
		}
	}

	public function test_get_stats_handles_empty_table_gracefully(): void {
		global $wpdb;
		$wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );
		$wpdb->shouldReceive( 'get_var' )->andReturn( null );
		$wpdb->shouldReceive( 'get_results' )->andReturn( array() );

		$stats = RateLimitStats::get_stats();

		$this->assertNull( $stats['today'] );
		$this->assertNull( $stats['month'] );
		$this->assertSame( array(), $stats['by_type'] );
		$this->assertSame( array(), $stats['top_ips'] );
	}
}
