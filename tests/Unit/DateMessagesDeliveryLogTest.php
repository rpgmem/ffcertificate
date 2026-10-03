<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use FreeFormCertificate\DateMessages\DeliveryLog;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * The log is the deduplication (#1538).
 *
 * @covers \FreeFormCertificate\DateMessages\DeliveryLog
 */
class DateMessagesDeliveryLogTest extends TestCase {

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
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @dataProvider affected
	 *
	 * @param int|false $affected Rows the INSERT IGNORE reported.
	 * @param bool      $claimed  Expected claim.
	 */
	public function test_a_claim_is_won_only_when_the_row_went_in( $affected, bool $claimed ): void {
		$this->wpdb->shouldReceive( 'prepare' )->once()->andReturnUsing(
			static function ( $sql ) {
				return str_starts_with( $sql, 'INSERT IGNORE INTO %i' ) ? 'SQL' : 'WRONG';
			}
		);
		$this->wpdb->shouldReceive( 'query' )->once()->with( 'SQL' )->andReturn( $affected );

		$this->assertSame( $claimed, DeliveryLog::claim( 7, 3, 9, '2026-10-10' ) );
	}

	/**
	 * @return array<string, array{0: int|false, 1: bool}>
	 */
	public function affected(): array {
		return array(
			'inserted'       => array( 1, true ),
			'already logged' => array( 0, false ),
			'query failed'   => array( false, false ),
		);
	}

	public function test_bump_refuses_a_column_that_is_not_a_counter(): void {
		$this->wpdb->shouldReceive( 'prepare' )->never();
		$this->wpdb->shouldReceive( 'query' )->never();

		DeliveryLog::bump( 7, 'started_at', 5 );
		DeliveryLog::bump( 7, 'sent', 0 );
	}

	public function test_delivered_among_reads_one_query_for_the_batch(): void {
		$this->wpdb->shouldReceive( 'prepare' )->once()->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_col' )->once()->with( 'SQL' )->andReturn( array( '4', 'x', '9' ) );

		$this->assertSame( array( 4 => true, 9 => true ), DeliveryLog::delivered_among( 3, '2026-10-10', array( 4, 5, 9 ) ) );
	}
}
