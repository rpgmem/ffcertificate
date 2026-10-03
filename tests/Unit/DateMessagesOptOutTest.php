<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use FreeFormCertificate\DateMessages\OptOut;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * Everyone receives date messages until they say otherwise (#1538).
 *
 * @covers \FreeFormCertificate\DateMessages\OptOut
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class DateMessagesOptOutTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_only_an_explicit_false_is_an_opt_out(): void {
		global $wpdb;
		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );
		$wpdb->shouldReceive( 'get_results' )->andReturn(
			array(
				array( 'user_id' => '1', 'preferences' => '{"notify_date_messages":false}' ),
				array( 'user_id' => '2', 'preferences' => '{"notify_date_messages":true}' ),
				array( 'user_id' => '3', 'preferences' => '{"notify_new_certificate":true}' ),
				array( 'user_id' => '4', 'preferences' => null ),
				array( 'user_id' => '5', 'preferences' => 'not json' ),
				array( 'user_id' => '6', 'preferences' => '{"notify_date_messages":0}' ),
			)
		);

		$this->assertSame( array( 1 => true ), OptOut::among( array( 1, 2, 3, 4, 5, 6 ) ) );
	}

	public function test_no_ids_means_no_query(): void {
		global $wpdb;
		$wpdb = Mockery::mock( 'wpdb' );
		$wpdb->shouldReceive( 'get_results' )->never();

		$this->assertSame( array(), OptOut::among( array( 0, -3 ) ) );
	}

	public function test_set_keeps_every_other_preference(): void {
		$manager = Mockery::mock( 'alias:FreeFormCertificate\UserDashboard\UserManager' );
		$manager->shouldReceive( 'get_profile' )->with( 9 )->andReturn( array( 'preferences' => '{"notify_new_certificate":true}' ) );
		$manager->shouldReceive( 'update_profile' )->once()->with(
			9,
			array(
				'preferences' => array(
					'notify_new_certificate' => true,
					'notify_date_messages'   => false,
				),
			)
		)->andReturn( true );

		$this->assertTrue( OptOut::set( 9, false ) );
	}
}
