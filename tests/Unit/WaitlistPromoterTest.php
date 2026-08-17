<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\SelfScheduling\WaitlistPromoter;

/**
 * Tests for WaitlistPromoter: FIFO promotion when a booked spot frees up (#941 phase 2).
 *
 * @covers \FreeFormCertificate\SelfScheduling\WaitlistPromoter
 */
class WaitlistPromoterTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private $appointmentRepo;
	private $calendarRepo;
	private WaitlistPromoter $promoter;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		class_exists( '\\FreeFormCertificate\\SelfScheduling\\WaitlistPromoter' );
		class_exists( '\\FreeFormCertificate\\SelfScheduling\\CustomSlots' );

		Functions\when( '__' )->returnArg();
		// Emails enabled by default (ffc_settings has no disable flag).
		Functions\when( 'get_option' )->justReturn( array() );

		$this->appointmentRepo = Mockery::mock( 'FreeFormCertificate\Repositories\AppointmentRepository' );
		$this->calendarRepo    = Mockery::mock( 'FreeFormCertificate\Repositories\CalendarRepository' );

		$this->promoter = new WaitlistPromoter( $this->appointmentRepo, $this->calendarRepo );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Build a calendar row with waitlist enabled.
	 *
	 * @param array<string, mixed> $overrides Field overrides.
	 * @return array<string, mixed>
	 */
	private function calendar( array $overrides = array() ): array {
		return array_merge(
			array(
				'id'                        => 1,
				'schedule_type'             => 'regular',
				'waitlist_enabled'          => 1,
				'max_appointments_per_slot' => 2,
				'requires_approval'         => 0,
				'custom_slots'              => '',
				'email_config'              => wp_json_encode( array( 'send_user_confirmation' => 1 ) ),
			),
			$overrides
		);
	}

	// ------------------------------------------------------------------
	// on_appointment_cancelled — guards
	// ------------------------------------------------------------------

	public function test_non_array_appointment_is_ignored(): void {
		$this->calendarRepo->shouldNotReceive( 'findById' );
		$this->promoter->on_appointment_cancelled( 5, null );
		$this->assertTrue( true );
	}

	public function test_cancelled_waitlist_row_does_not_promote(): void {
		// A user leaving the queue opens no active spot.
		$this->calendarRepo->shouldNotReceive( 'findById' );
		$this->promoter->on_appointment_cancelled(
			5,
			array(
				'status'           => 'waitlist',
				'calendar_id'      => 1,
				'appointment_date' => '2026-09-03',
				'start_time'       => '08:00:00',
			)
		);
		$this->assertTrue( true );
	}

	public function test_cancelled_active_spot_triggers_promotion(): void {
		Functions\when( 'do_action' )->justReturn( null );

		$this->calendarRepo->shouldReceive( 'findById' )->with( 1 )->andReturn( $this->calendar() );
		$this->appointmentRepo->shouldReceive( 'begin_transaction' )->once();
		$this->appointmentRepo->shouldReceive( 'isSlotAvailable' )->andReturn( true );
		$this->appointmentRepo->shouldReceive( 'findOldestWaitlisted' )->andReturn( array( 'id' => 42 ) );
		$this->appointmentRepo->shouldReceive( 'promoteFromWaitlist' )->with( 42, false )->once()->andReturn( 1 );
		$this->appointmentRepo->shouldReceive( 'commit' )->once();
		$this->appointmentRepo->shouldReceive( 'findById' )->with( 42 )->andReturn( array( 'id' => 42, 'status' => 'confirmed' ) );

		$this->promoter->on_appointment_cancelled(
			5,
			array(
				'status'           => 'confirmed',
				'calendar_id'      => 1,
				'appointment_date' => '2026-09-03',
				'start_time'       => '08:00:00',
			)
		);
		$this->assertTrue( true );
	}

	// ------------------------------------------------------------------
	// promote()
	// ------------------------------------------------------------------

	public function test_promote_returns_null_when_waitlist_disabled(): void {
		$this->calendarRepo->shouldReceive( 'findById' )->andReturn( $this->calendar( array( 'waitlist_enabled' => 0 ) ) );
		$this->assertNull( $this->promoter->promote( 1, '2026-09-03', '08:00:00' ) );
	}

	public function test_promote_confirms_when_no_approval_required_and_emails(): void {
		$fired = array();
		Functions\when( 'do_action' )->alias(
			static function ( $hook ) use ( &$fired ) {
				$fired[] = $hook;
			}
		);

		$this->calendarRepo->shouldReceive( 'findById' )->andReturn( $this->calendar() );
		$this->appointmentRepo->shouldReceive( 'begin_transaction' )->once();
		$this->appointmentRepo->shouldReceive( 'isSlotAvailable' )->with( 1, '2026-09-03', '08:00:00', 2, true )->andReturn( true );
		$this->appointmentRepo->shouldReceive( 'findOldestWaitlisted' )->with( 1, '2026-09-03', '08:00:00', true )->andReturn( array( 'id' => 7 ) );
		$this->appointmentRepo->shouldReceive( 'promoteFromWaitlist' )->with( 7, false )->once()->andReturn( 1 );
		$this->appointmentRepo->shouldReceive( 'commit' )->once();
		$this->appointmentRepo->shouldReceive( 'findById' )->with( 7 )->andReturn( array( 'id' => 7, 'status' => 'confirmed' ) );

		$result = $this->promoter->promote( 1, '2026-09-03', '08:00:00' );

		$this->assertSame( 7, $result );
		$this->assertContains( 'ffcertificate_self_scheduling_appointment_promoted_email', $fired );
	}

	public function test_promote_keeps_pending_when_approval_required(): void {
		Functions\when( 'do_action' )->justReturn( null );

		$this->calendarRepo->shouldReceive( 'findById' )->andReturn( $this->calendar( array( 'requires_approval' => 1 ) ) );
		$this->appointmentRepo->shouldReceive( 'begin_transaction' )->once();
		$this->appointmentRepo->shouldReceive( 'isSlotAvailable' )->andReturn( true );
		$this->appointmentRepo->shouldReceive( 'findOldestWaitlisted' )->andReturn( array( 'id' => 9 ) );
		$this->appointmentRepo->shouldReceive( 'promoteFromWaitlist' )->with( 9, true )->once()->andReturn( 1 );
		$this->appointmentRepo->shouldReceive( 'commit' )->once();
		$this->appointmentRepo->shouldReceive( 'findById' )->with( 9 )->andReturn( array( 'id' => 9, 'status' => 'pending' ) );

		$this->assertSame( 9, $this->promoter->promote( 1, '2026-09-03', '08:00:00' ) );
	}

	public function test_promote_returns_null_when_slot_still_full(): void {
		$this->calendarRepo->shouldReceive( 'findById' )->andReturn( $this->calendar() );
		$this->appointmentRepo->shouldReceive( 'begin_transaction' )->once();
		$this->appointmentRepo->shouldReceive( 'isSlotAvailable' )->andReturn( false );
		$this->appointmentRepo->shouldReceive( 'findOldestWaitlisted' )->never();
		$this->appointmentRepo->shouldReceive( 'promoteFromWaitlist' )->never();
		$this->appointmentRepo->shouldReceive( 'commit' )->once();

		$this->assertNull( $this->promoter->promote( 1, '2026-09-03', '08:00:00' ) );
	}

	public function test_promote_returns_null_when_queue_empty(): void {
		$this->calendarRepo->shouldReceive( 'findById' )->andReturn( $this->calendar() );
		$this->appointmentRepo->shouldReceive( 'begin_transaction' )->once();
		$this->appointmentRepo->shouldReceive( 'isSlotAvailable' )->andReturn( true );
		$this->appointmentRepo->shouldReceive( 'findOldestWaitlisted' )->andReturn( null );
		$this->appointmentRepo->shouldReceive( 'promoteFromWaitlist' )->never();
		$this->appointmentRepo->shouldReceive( 'commit' )->once();

		$this->assertNull( $this->promoter->promote( 1, '2026-09-03', '08:00:00' ) );
	}

	public function test_promote_custom_mode_uses_block_capacity(): void {
		Functions\when( 'do_action' )->justReturn( null );

		$calendar = $this->calendar(
			array(
				'schedule_type' => 'custom',
				'custom_slots'  => wp_json_encode(
					array(
						array( 'date' => '2026-09-03', 'start' => '08:00', 'end' => '13:00', 'capacity' => 40, 'label' => '' ),
					)
				),
			)
		);
		$this->calendarRepo->shouldReceive( 'findById' )->andReturn( $calendar );
		$this->appointmentRepo->shouldReceive( 'begin_transaction' )->once();
		// Capacity 40 comes from the block, not max_appointments_per_slot (2).
		$this->appointmentRepo->shouldReceive( 'isSlotAvailable' )->with( 1, '2026-09-03', '08:00:00', 40, true )->andReturn( true );
		$this->appointmentRepo->shouldReceive( 'findOldestWaitlisted' )->andReturn( array( 'id' => 11 ) );
		$this->appointmentRepo->shouldReceive( 'promoteFromWaitlist' )->with( 11, false )->once()->andReturn( 1 );
		$this->appointmentRepo->shouldReceive( 'commit' )->once();
		$this->appointmentRepo->shouldReceive( 'findById' )->with( 11 )->andReturn( array( 'id' => 11, 'status' => 'confirmed' ) );

		$this->assertSame( 11, $this->promoter->promote( 1, '2026-09-03', '08:00:00' ) );
	}

	public function test_promote_custom_mode_returns_null_when_block_removed(): void {
		$calendar = $this->calendar(
			array(
				'schedule_type' => 'custom',
				'custom_slots'  => wp_json_encode( array() ),
			)
		);
		$this->calendarRepo->shouldReceive( 'findById' )->andReturn( $calendar );
		$this->appointmentRepo->shouldReceive( 'begin_transaction' )->never();

		$this->assertNull( $this->promoter->promote( 1, '2026-09-03', '08:00:00' ) );
	}
}
