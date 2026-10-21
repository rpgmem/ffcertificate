<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FreeFormCertificate\DateMessages\DateMessagesAdminPage;
use FreeFormCertificate\DateMessages\Rule;
use FreeFormCertificate\DateMessages\Runner;
use PHPUnit\Framework\TestCase;

/**
 * An announcement's send time, read from the editor and kept in step on save (#1949, PR B).
 *
 * @covers \FreeFormCertificate\DateMessages\DateMessagesAdminPage
 */
class DateMessagesAnnouncementScheduleTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();
		Functions\when( 'sanitize_text_field' )->alias( static fn( $v ) => trim( strip_tags( (string) $v ) ) );
		Functions\when( 'wp_timezone' )->justReturn( new \DateTimeZone( 'America/Sao_Paulo' ) );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'wp_kses_post' )->returnArg();
		Functions\when( 'wp_date' )->alias( static fn( string $format, int $ts ) => ( new \DateTimeImmutable( '@' . $ts ) )->setTimezone( new \DateTimeZone( 'America/Sao_Paulo' ) )->format( $format ) );
		Functions\when( 'get_current_user_id' )->justReturn( 7 );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @param array<string, mixed> $over Overrides.
	 */
	private function announcement( array $over = array() ): Rule {
		$rule = Rule::from_array(
			array_merge(
				array(
					'id'      => 5,
					'name'    => 'Closing',
					'kind'    => 'announcement',
					'subject' => 'Closed',
					'body'    => '<p>x</p>',
					'send_at' => time() + 3600,
				),
				$over
			)
		);
		self::assertInstanceOf( Rule::class, $rule );
		return $rule;
	}

	public function test_a_datetime_local_value_is_read_in_the_site_timezone(): void {
		$at = DateMessagesAdminPage::send_at_from_input( '2030-06-01T09:30' );

		$expected = ( new \DateTimeImmutable( '2030-06-01 09:30', new \DateTimeZone( 'America/Sao_Paulo' ) ) )->getTimestamp();
		self::assertSame( $expected, $at );
	}

	public function test_an_empty_or_unreadable_value_is_not_scheduled(): void {
		self::assertSame( 0, DateMessagesAdminPage::send_at_from_input( '' ) );
		self::assertSame( 0, DateMessagesAdminPage::send_at_from_input( 'next tuesday' ) );
	}

	public function test_a_stored_instant_round_trips_through_the_input_format(): void {
		$at = DateMessagesAdminPage::send_at_from_input( '2030-06-01T09:30' );

		self::assertSame( '2030-06-01T09:30', DateMessagesAdminPage::send_at_for_input( $at ) );
		self::assertSame( '', DateMessagesAdminPage::send_at_for_input( 0 ) );
	}

	public function test_an_unticked_force_box_means_respect_and_a_ticked_one_forces(): void {
		$base = array(
			'name'    => 'n',
			'kind'    => 'announcement',
			'subject' => 's',
			'body'    => 'b',
			'send_at' => '',
		);

		self::assertSame( '1', DateMessagesAdminPage::form_data( $base )['respect_opt_out'] );
		self::assertSame( '0', DateMessagesAdminPage::form_data( $base + array( 'force_opt_out' => '1' ) )['respect_opt_out'] );
	}

	public function test_the_form_carries_kind_and_instant_to_the_rule(): void {
		$data = DateMessagesAdminPage::form_data(
			array(
				'name'    => 'n',
				'kind'    => 'announcement',
				'subject' => 's',
				'body'    => 'b',
				'send_at' => '2030-06-01T09:30',
			)
		);

		self::assertSame( 'announcement', $data['kind'] );
		self::assertSame( DateMessagesAdminPage::send_at_from_input( '2030-06-01T09:30' ), $data['send_at'] );
	}

	public function test_an_unchanged_save_leaves_the_queued_event_alone(): void {
		$rule = $this->announcement();
		Functions\expect( 'wp_clear_scheduled_hook' )->never();
		Functions\expect( 'wp_schedule_single_event' )->never();

		DateMessagesAdminPage::sync_schedule( $rule, $rule );
		self::assertTrue( true );
	}

	public function test_a_future_instant_is_queued_once_and_replaces_the_old_one(): void {
		$previous = $this->announcement( array( 'send_at' => time() + 7200 ) );
		$stored   = $this->announcement( array( 'send_at' => time() + 3600 ) );

		Functions\expect( 'wp_clear_scheduled_hook' )->once()->with( Runner::ANNOUNCE_HOOK, array( 5 ) );
		Functions\expect( 'wp_schedule_single_event' )->once()->with( $stored->send_at, Runner::ANNOUNCE_HOOK, array( 5 ) )->andReturn( true );

		DateMessagesAdminPage::sync_schedule( $stored, $previous );
		self::assertTrue( true );
	}

	public function test_a_past_instant_queues_nothing_so_a_resave_cannot_send_it(): void {
		$previous = $this->announcement( array( 'send_at' => time() - 3600 ) );
		$stored   = $this->announcement( array( 'send_at' => time() - 1800 ) );

		Functions\expect( 'wp_clear_scheduled_hook' )->once();
		Functions\expect( 'wp_schedule_single_event' )->never();

		DateMessagesAdminPage::sync_schedule( $stored, $previous );
		self::assertTrue( true );
	}

	public function test_turning_the_send_time_off_clears_the_event(): void {
		$previous = $this->announcement( array( 'send_at' => time() + 3600 ) );
		$stored   = $this->announcement( array( 'send_at' => 0 ) );

		Functions\expect( 'wp_clear_scheduled_hook' )->once();
		Functions\expect( 'wp_schedule_single_event' )->never();

		DateMessagesAdminPage::sync_schedule( $stored, $previous );
		self::assertTrue( true );
	}
}
