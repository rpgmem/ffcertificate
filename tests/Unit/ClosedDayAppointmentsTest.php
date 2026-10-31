<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Repositories\AppointmentRepository;
use FreeFormCertificate\SelfScheduling\AppointmentAdminActions;
use FreeFormCertificate\SelfScheduling\AppointmentHandler;
use FreeFormCertificate\SelfScheduling\ClosedDayAppointments;

/**
 * The personal-calendar appointments a global holiday closes (#2244).
 *
 * @covers \FreeFormCertificate\SelfScheduling\ClosedDayAppointments
 */
class ClosedDayAppointmentsTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'get_current_user_id' )->justReturn( 7 );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_it_reads_the_open_appointments_of_the_date(): void {
		$repo = Mockery::mock( AppointmentRepository::class );
		$repo->shouldReceive( 'getAllOnDate' )->once()->with( '2032-05-20', AppointmentHandler::CANCELLABLE_STATUSES )->andReturn( array( array( 'id' => 4 ) ) );

		$this->assertSame( array( array( 'id' => 4 ) ), ( new ClosedDayAppointments( $repo ) )->on( '2032-05-20' ) );
	}

	public function test_an_invalid_date_reads_nothing(): void {
		$repo = Mockery::mock( AppointmentRepository::class );
		$repo->shouldNotReceive( 'getAllOnDate' );

		$this->assertSame( array(), ( new ClosedDayAppointments( $repo ) )->on( '2032-02-31' ) );
	}

	public function test_cancel_counts_only_the_rows_it_cancelled(): void {
		$actions = Mockery::mock( AppointmentAdminActions::class );
		$actions->shouldReceive( 'cancel' )->once()->with( 4, 7, 'Closed day: Xmas' )->andReturn( true );
		$actions->shouldReceive( 'cancel' )->once()->with( 5, 7, 'Closed day: Xmas' )->andReturn( new \WP_Error( 'status_changed', 'x' ) );

		$cancelled = ( new ClosedDayAppointments( Mockery::mock( AppointmentRepository::class ), $actions ) )
			->cancel( array( array( 'id' => 4 ), array( 'id' => '5' ) ), 'Closed day: Xmas' );

		$this->assertSame( 1, $cancelled );
	}
}
