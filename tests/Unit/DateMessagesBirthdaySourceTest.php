<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FreeFormCertificate\DateMessages\BirthdaySource;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * Who has a birthday on a day (#1538).
 *
 * @covers \FreeFormCertificate\DateMessages\BirthdaySource
 */
class DateMessagesBirthdaySourceTest extends TestCase {

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
	 * @dataProvider days
	 *
	 * @param string             $target   Target day.
	 * @param array<int, string> $expected Month-days due.
	 */
	public function test_29_february_is_due_on_28_february_of_a_common_year_only( string $target, array $expected ): void {
		$this->assertSame( $expected, BirthdaySource::month_days_for( new \DateTimeImmutable( $target ) ) );
	}

	/**
	 * @return array<string, array{0: string, 1: array<int, string>}>
	 */
	public function days(): array {
		return array(
			'ordinary day'          => array( '2026-10-10', array( '10-10' ) ),
			'28 Feb, common year'   => array( '2027-02-28', array( '02-28', '02-29' ) ),
			'28 Feb, leap year'     => array( '2028-02-28', array( '02-28' ) ),
			'29 Feb, leap year'     => array( '2028-02-29', array( '02-29' ) ),
			'1 Mar, common year'    => array( '2027-03-01', array( '03-01' ) ),
		);
	}

	public function test_due_queries_the_month_day_mirror_by_keyset_and_maps_rows(): void {
		global $wpdb;
		$wpdb           = Mockery::mock( 'wpdb' );
		$wpdb->users    = 'wp_users';
		$wpdb->usermeta = 'wp_usermeta';

		$bound = null;
		$wpdb->shouldReceive( 'prepare' )->once()->andReturnUsing(
			static function ( $sql, $args ) use ( &$bound ) {
				$bound = array( $sql, $args );
				return 'SQL';
			}
		);
		$wpdb->shouldReceive( 'get_results' )->once()->with( 'SQL', ARRAY_A )->andReturn(
			array(
				array( 'user_id' => '12', 'email' => 'a@b.c', 'name' => 'Ana' ),
				array( 'user_id' => 'x', 'email' => 'skip', 'name' => 'skip' ),
				array( 'user_id' => '15', 'email' => null, 'name' => 'Bia' ),
			)
		);

		$rows = ( new BirthdaySource() )->due( new \DateTimeImmutable( '2027-02-28' ), 10, 200 );

		$this->assertSame( array( 'ffc_user_birth_md', '02-28', '02-29', 10, 200 ), $bound[1] );
		$this->assertStringContainsString( 'm.meta_value IN (%s, %s)', $bound[0] );
		$this->assertStringContainsString( 'ORDER BY u.ID ASC LIMIT %d', $bound[0] );
		$this->assertSame(
			array(
				array( 'user_id' => 12, 'email' => 'a@b.c', 'name' => 'Ana' ),
				array( 'user_id' => 15, 'email' => '', 'name' => 'Bia' ),
			),
			$rows
		);
	}
}
