<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FreeFormCertificate\DateMessages\AnnouncementSource;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * Who an announcement reads: every account, or one page of audience members (#1996).
 *
 * @covers \FreeFormCertificate\DateMessages\AnnouncementSource
 */
class DateMessagesAnnouncementSourceTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * A `wpdb` double that records what was bound and answers with the rows.
	 *
	 * @param array<int, array<string, mixed>> $rows  Rows to answer.
	 * @param mixed                            $bound Receives `array( sql, args )`.
	 * @return void
	 */
	private function wpdb( array $rows, &$bound ): void {
		global $wpdb;
		$wpdb        = Mockery::mock( 'wpdb' );
		$wpdb->users = 'wp_users';
		$wpdb->shouldReceive( 'prepare' )->once()->andReturnUsing(
			static function ( $sql, ...$args ) use ( &$bound ) {
				$bound = array( $sql, 1 === count( $args ) && is_array( $args[0] ) ? $args[0] : $args );
				return 'SQL';
			}
		);
		$wpdb->shouldReceive( 'get_results' )->once()->with( 'SQL', ARRAY_A )->andReturn( $rows );
	}

	public function test_due_pages_every_account_by_keyset(): void {
		$bound = null;
		$this->wpdb( array( array( 'user_id' => '4', 'email' => 'a@b.c', 'name' => 'Ana' ) ), $bound );

		$rows = ( new AnnouncementSource() )->due( new \DateTimeImmutable( '2026-10-22' ), 3, 200 );

		$this->assertSame( array( 3, 200 ), $bound[1] );
		$this->assertStringContainsString( 'WHERE ID > %d ORDER BY ID ASC LIMIT %d', $bound[0] );
		$this->assertSame( array( array( 'user_id' => 4, 'email' => 'a@b.c', 'name' => 'Ana' ) ), $rows );
	}

	public function test_due_among_reads_only_the_ids_given_and_maps_rows(): void {
		$bound = null;
		$this->wpdb(
			array(
				array( 'user_id' => '7', 'email' => 'a@b.c', 'name' => 'Ana' ),
				array( 'user_id' => 'x', 'email' => 'skip', 'name' => 'skip' ),
				array( 'user_id' => '50', 'email' => null, 'name' => 'Bia' ),
			),
			$bound
		);

		$rows = ( new AnnouncementSource() )->due_among( new \DateTimeImmutable( '2026-10-22' ), array( 7, 50, 7, 0, -3 ) );

		$this->assertSame( array( 7, 50 ), $bound[1], 'Duplicates and non-ids are dropped before binding.' );
		$this->assertStringContainsString( 'WHERE ID IN (%d, %d) ORDER BY ID ASC', $bound[0] );
		$this->assertStringNotContainsString( 'LIMIT', $bound[0], 'The page is the ids: no second limit.' );
		$this->assertSame(
			array(
				array( 'user_id' => 7, 'email' => 'a@b.c', 'name' => 'Ana' ),
				array( 'user_id' => 50, 'email' => '', 'name' => 'Bia' ),
			),
			$rows
		);
	}

	public function test_due_among_with_no_ids_never_queries(): void {
		global $wpdb;
		$wpdb = Mockery::mock( 'wpdb' );
		$wpdb->shouldNotReceive( 'prepare' );
		$wpdb->shouldNotReceive( 'get_results' );

		$this->assertSame( array(), ( new AnnouncementSource() )->due_among( new \DateTimeImmutable( '2026-10-22' ), array( 0 ) ) );
	}
}
