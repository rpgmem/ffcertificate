<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * The History tab of Date Messages offers the way to the daily schedule,
 * which lives on Settings → Scheduled Tasks, and draws it only for who can
 * open that screen.
 *
 * @coversNothing
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class DateMessagesHistoryTemplateTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/**
	 * Capabilities the current user holds.
	 *
	 * @var array<int, string>
	 */
	private array $caps = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		if ( ! defined( 'ABSPATH' ) ) {
			define( 'ABSPATH', '/tmp/' );
		}
		foreach ( array( '__', 'esc_html', 'esc_attr', 'esc_url' ) as $fn ) {
			Functions\when( $fn )->returnArg();
		}
		Functions\when( 'esc_html_e' )->alias( static function ( $text ) { echo $text; } );
		Functions\when( 'admin_url' )->alias( static fn( $path = '' ) => 'https://example.org/wp-admin/' . $path );
		Functions\when( 'current_user_can' )->alias( fn( $cap ) => in_array( $cap, $this->caps, true ) );
		Mockery::mock( 'alias:FreeFormCertificate\Core\DateFormatter' )->shouldReceive( 'format_datetime' )->andReturn( '10/10/2026 07:00' );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Render the template with no runs.
	 *
	 * @param int|false $next_run Next daily run.
	 */
	private function render( $next_run ): string {
		$history    = array();
		$total      = 0;
		$paged      = 1;
		$rule_names = array();
		ob_start();
		include dirname( __DIR__, 2 ) . '/templates/admin/date-messages/history.php';
		return (string) ob_get_clean();
	}

	public function test_names_the_next_run_and_links_to_scheduled_tasks(): void {
		$this->caps = array( 'ffc_view_settings' );

		$html = $this->render( 1791702000 );

		$this->assertStringContainsString( 'Next daily run: 10/10/2026 07:00.', $html );
		$this->assertStringContainsString( 'href="https://example.org/wp-admin/admin.php?page=ffc-settings&tab=scheduled_tasks"', $html );
		$this->assertStringContainsString( 'Open Scheduled Tasks', $html );
	}

	public function test_says_when_the_daily_run_is_not_scheduled(): void {
		$this->caps = array( 'manage_options' );

		$html = $this->render( false );

		$this->assertStringContainsString( 'The daily run is not scheduled.', $html );
		$this->assertStringContainsString( 'Open Scheduled Tasks', $html, 'an administrator holds every capability' );
	}

	public function test_hides_the_link_from_who_cannot_open_settings(): void {
		$this->caps = array( 'ffc_view_date_messages' );

		$html = $this->render( 1791702000 );

		$this->assertStringContainsString( 'Next daily run', $html, 'the fact is still shown' );
		$this->assertStringNotContainsString( 'scheduled_tasks', $html );
	}
}
