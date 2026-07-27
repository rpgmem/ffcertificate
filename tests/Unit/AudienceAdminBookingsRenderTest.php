<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Audience\AudienceAdminBookings;

/**
 * Render tests for AudienceAdminBookings::render_page() that exercise the
 * populated-table path (export button, environment filter, and the per-row
 * loop with active/cancelled variants). These alias-mock the reader /
 * repository / capability collaborators, which pollute the global class table,
 * so the class runs each test in isolation. The empty-list smoke test lives in
 * the sibling AudienceAdminBookingsTest (real readers over a mocked $wpdb).
 *
 * @covers \FreeFormCertificate\Audience\AudienceAdminBookings
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class AudienceAdminBookingsRenderTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		class_exists( '\FreeFormCertificate\Audience\AudienceAdminBookings' );

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html_e' )->alias( static function ( $t ) { echo $t; } );
		Functions\when( 'esc_attr_e' )->alias( static function ( $t ) { echo $t; } );
		Functions\when( 'esc_attr__' )->returnArg();
		Functions\when( 'absint' )->alias( static fn( $v ) => abs( (int) $v ) );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'sanitize_key' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'admin_url' )->justReturn( 'https://example.com/wp-admin/' );
		Functions\when( 'selected' )->justReturn( '' );
		Functions\when( 'settings_errors' )->justReturn( '' );
		Functions\when( 'wp_trim_words' )->returnArg();

		// DateFormatter → verbatim, deterministic output.
		$df = Mockery::mock( 'alias:FreeFormCertificate\Core\DateFormatter' );
		$df->shouldReceive( 'format_wallclock_date' )->andReturnUsing( static fn( $v ) => (string) $v )->byDefault();
		$df->shouldReceive( 'format_wallclock_time' )->andReturnUsing( static fn( $v ) => (string) $v )->byDefault();
	}

	protected function tearDown(): void {
		unset( $_GET['schedule_id'], $_GET['environment_id'], $_GET['status'], $_GET['date_from'], $_GET['date_to'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	private function active_booking(): object {
		return (object) array(
			'id'               => 101,
			'created_by'       => 2,
			'booking_date'     => '2026-05-20',
			'start_time'       => '09:00:00',
			'end_time'         => '10:00:00',
			'environment_name' => 'Room A',
			'description'      => 'Weekly sync',
			'booking_type'     => 'audience',
			'status'           => 'active',
		);
	}

	private function cancelled_booking(): object {
		return (object) array(
			'id'               => 102,
			'created_by'       => 99, // not in the creators map → "Unknown"
			'booking_date'     => '2026-05-21',
			'start_time'       => '11:00:00',
			'end_time'         => '12:00:00',
			'environment_name' => 'Room B',
			'description'      => 'Cancelled thing',
			'booking_type'     => 'mystery', // unknown type → raw value
			'status'           => 'cancelled',
		);
	}

	private function mock_repositories( array $bookings, bool $with_environments ): void {
		$reader = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceBookingReader' );
		$reader->shouldReceive( 'get_all' )->andReturn( $bookings );

		$sched = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceScheduleRepository' );
		$sched->shouldReceive( 'get_all' )->andReturn(
			array( (object) array( 'id' => 5, 'name' => 'My Calendar' ) )
		);
		$sched->shouldReceive( 'get_environment_label' )->andReturn( 'Rooms' );

		$env = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceEnvironmentRepository' );
		$env->shouldReceive( 'get_by_schedule' )->andReturn(
			$with_environments ? array( (object) array( 'id' => 33, 'name' => 'Room A' ) ) : array()
		);
	}

	private function mock_export_cap( bool $granted ): void {
		$caps = Mockery::mock( 'alias:FreeFormCertificate\Core\Capabilities' );
		$caps->shouldReceive( 'current_user_can_admin_or' )->andReturn( $granted );
	}

	public function test_render_populated_table_with_export_button_and_env_filter(): void {
		$_GET['schedule_id'] = '5'; // drives the environment-filter branch
		$this->mock_repositories( array( $this->active_booking(), $this->cancelled_booking() ), true );
		$this->mock_export_cap( true );

		Functions\when( 'get_users' )->justReturn(
			array( (object) array( 'ID' => 2, 'display_name' => 'Ana Creator' ) )
		);

		$page = new AudienceAdminBookings( 'ffc-scheduling' );
		ob_start();
		$page->render_page();
		$output = ob_get_clean();

		// Export button (capability granted).
		$this->assertStringContainsString( 'id="ffc-bookings-export-btn"', $output );
		// Both rows rendered.
		$this->assertStringContainsString( 'data-booking-id="101"', $output );
		$this->assertStringContainsString( 'data-booking-id="102"', $output );
		// Creator resolved from the batch map; missing one falls back to Unknown.
		$this->assertStringContainsString( 'Ana Creator', $output );
		$this->assertStringContainsString( 'Unknown', $output );
		// Active row shows the Cancel link; cancelled row does not add a second one.
		$this->assertSame( 1, substr_count( $output, 'ffc-cancel-booking' ) );
		$this->assertSame( 2, substr_count( $output, 'ffc-view-booking' ) );
		// Type labels: known 'audience' mapped, unknown 'mystery' echoed raw.
		$this->assertStringContainsString( 'Audience', $output );
		$this->assertStringContainsString( 'mystery', $output );
		// Environment filter option rendered (schedule_id > 0).
		$this->assertStringContainsString( 'value="33"', $output );
		// Total line.
		$this->assertStringContainsString( 'Total:', $output );
	}

	public function test_render_hides_export_button_without_capability(): void {
		$this->mock_repositories( array( $this->active_booking() ), false );
		$this->mock_export_cap( false );

		Functions\when( 'get_users' )->justReturn(
			array( (object) array( 'ID' => 2, 'display_name' => 'Ana' ) )
		);

		$page = new AudienceAdminBookings( 'ffc-scheduling' );
		ob_start();
		$page->render_page();
		$output = ob_get_clean();

		$this->assertStringNotContainsString( 'id="ffc-bookings-export-btn"', $output );
		// Table still renders the row.
		$this->assertStringContainsString( 'data-booking-id="101"', $output );
	}
}
