<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FreeFormCertificate\Core\ScheduledTasks;
use FreeFormCertificate\Settings\Tabs\TabScheduledTasks;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * The Scheduled Tasks settings tab (#1538): read-only, so what it renders is
 * the whole contract.
 *
 * @covers \FreeFormCertificate\Settings\Tabs\TabScheduledTasks
 */
class TabScheduledTasksTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/**
	 * Heartbeats the option holds.
	 *
	 * @var array<string, int>
	 */
	private array $beats = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->beats = array();

		foreach ( array( '__', 'esc_html__', 'esc_html', 'esc_attr', 'wp_kses_post' ) as $fn ) {
			Functions\when( $fn )->returnArg();
		}
		Functions\when( 'esc_html_e' )->echoArg();
		Functions\when( 'wp_kses' )->returnArg();
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'site_url' )->justReturn( 'https://example.org' );
		Functions\when( 'number_format_i18n' )->returnArg();
		Functions\when( 'selected' )->alias(
			static function ( $a, $b ) {
				echo (string) $a === (string) $b ? ' selected="selected"' : '';
			}
		);
		Functions\when( 'wp_timezone' )->alias(
			static function () {
				return new \DateTimeZone( 'UTC' );
			}
		);
		Functions\when( 'wp_date' )->alias(
			static function ( $format, $ts ) {
				return gmdate( 'Y-m-d H:i', (int) $ts );
			}
		);
		Functions\when( 'get_option' )->alias(
			function ( $name, $default = false ) {
				return ScheduledTasks::HEARTBEAT_OPTION === $name ? $this->beats : $default;
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Render the tab with every recurring hook scheduled and a given set of
	 * heartbeats, and one pending submission event.
	 *
	 * @return string
	 */
	private function render(): string {
		Functions\when( 'wp_next_scheduled' )->justReturn( time() + 600 );
		Functions\when( '_get_cron_array' )->justReturn(
			array( time() + 5 => array( 'ffc_process_submission_async' => array( 'k' => array() ) ) )
		);

		ob_start();
		( new TabScheduledTasks() )->render();
		return (string) ob_get_clean();
	}

	public function test_identity_and_read_only_cap(): void {
		$tab = new TabScheduledTasks();

		$this->assertSame( 'scheduled_tasks', $tab->get_id() );
		$this->assertSame( 'Scheduled Tasks', $tab->get_title() );
		$this->assertSame( 'ffc_view_settings', $tab->get_view_cap() );
	}

	public function test_lists_every_recurring_task_and_counts_queued_one_offs(): void {
		$html = $this->render();

		foreach ( ScheduledTasks::all() as $hook => $task ) {
			if ( ScheduledTasks::SINGLE !== $task['recurrence'] ) {
				$this->assertStringContainsString( '<code>' . $hook . '</code>', $html );
			}
		}
		$this->assertStringContainsString( 'Submission processing (one per submission):', $html );
		$this->assertMatchesRegularExpression( '#one per submission\):\s*<strong>1</strong>#', $html );
	}

	public function test_a_late_task_raises_the_warning_and_a_fresh_one_does_not(): void {
		$fresh = array();
		foreach ( array_keys( ScheduledTasks::all() ) as $hook ) {
			$fresh[ $hook ] = time() - 60;
		}

		$this->beats = $fresh;
		$this->assertStringNotContainsString( 'notice-warning', $this->render() );

		$this->beats = array_merge( $fresh, array( 'ffcertificate_daily_cleanup_hook' => time() - 30 * HOUR_IN_SECONDS ) );
		$html        = $this->render();
		$this->assertStringContainsString( 'notice-warning', $html );
		$this->assertStringContainsString( 'Late — has not run when expected', $html );
	}

	public function test_recommends_disable_wp_cron_while_it_is_not_set(): void {
		$this->assertFalse( defined( 'DISABLE_WP_CRON' ), 'This test reads the branch for an unset constant.' );

		$this->assertStringContainsString( "define( 'DISABLE_WP_CRON', true );", $this->render() );
	}

	public function test_the_initial_line_is_the_wp_cli_one_at_fifteen_minutes(): void {
		$html = $this->render();

		$expected = ScheduledTasks::crontab_line( 'wp_cli', 15, ABSPATH, 'https://example.org' );
		$this->assertStringContainsString( 'value="' . $expected . '"', $html );
		$this->assertStringContainsString( 'data-ffc-copy-target="#ffc-crontab-line"', $html, 'Copy reuses the shared handler.' );
	}

	public function test_crontab_lines_are_built_for_this_site(): void {
		$this->assertSame(
			ScheduledTasks::crontab_lines( ABSPATH, 'https://example.org' ),
			TabScheduledTasks::crontab_lines()
		);
	}

	/**
	 * @dataProvider states
	 */
	public function test_every_state_has_a_label( string $state ): void {
		$this->assertNotSame( $state, TabScheduledTasks::state_label( $state ) );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public function states(): array {
		return array(
			'ok'            => array( 'ok' ),
			'late'          => array( 'late' ),
			'never_run'     => array( 'never_run' ),
			'not_scheduled' => array( 'not_scheduled' ),
		);
	}
}
