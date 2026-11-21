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
		// A single site: every account is a member (#2692 restricts a network).
		Functions\when( 'is_multisite' )->justReturn( false );
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

	public function test_on_a_network_both_reads_keep_to_this_sites_members(): void {
		// An announcement with no audience, or an audience member who is not
		// on this site, must not reach the rest of the network (#2692).
		Functions\when( 'is_multisite' )->justReturn( true );
		foreach ( array( 'due', 'due_among' ) as $method ) {
			$bound = null;
			$this->wpdb( array(), $bound );
			global $wpdb;
			$wpdb->usermeta = 'wp_usermeta';
			$wpdb->shouldReceive( 'get_blog_prefix' )->andReturn( 'wp_3_' );

			$source = new AnnouncementSource();
			'due' === $method
				? $source->due( new \DateTimeImmutable( '2026-10-22' ), 3, 200 )
				: $source->due_among( new \DateTimeImmutable( '2026-10-22' ), array( 7 ) );

			$this->assertStringContainsString( 'FROM wp_users INNER JOIN wp_usermeta ffc_site ON ffc_site.user_id = ID AND ffc_site.meta_key = %s WHERE', $bound[0], $method );
			$this->assertSame( 'wp_3_capabilities', $bound[1][0], $method );
		}
	}

	public function test_due_among_with_no_ids_never_queries(): void {
		global $wpdb;
		$wpdb = Mockery::mock( 'wpdb' );
		$wpdb->shouldNotReceive( 'prepare' );
		$wpdb->shouldNotReceive( 'get_results' );

		$this->assertSame( array(), ( new AnnouncementSource() )->due_among( new \DateTimeImmutable( '2026-10-22' ), array( 0 ) ) );
	}
}
