<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * The History tab of Date Messages offers the way to the daily schedule,
 * which lives on Settings → Scheduled Tasks, and draws it only for who can
 * open that screen.
 *
 * The date goes through the real DateFormatter, at its default formats in
 * UTC (#2117).
 *
 * @coversNothing
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
		foreach ( array( '__', 'esc_html', 'esc_attr', 'esc_url' ) as $fn ) {
			Functions\when( $fn )->returnArg();
		}
		Functions\when( 'esc_html_e' )->alias( static function ( $text ) { echo $text; } );
		Functions\when( 'admin_url' )->alias( static fn( $path = '' ) => 'https://example.org/wp-admin/' . $path );
		Functions\when( 'current_user_can' )->alias( fn( $cap ) => in_array( $cap, $this->caps, true ) );
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'wp_timezone' )->alias( static fn() => new \DateTimeZone( 'UTC' ) );
		Functions\when( 'wp_date' )->alias(
			static fn( $format, $ts = null, $tz = null ) => ( new \DateTimeImmutable( '@' . (int) $ts ) )->setTimezone( $tz ?? new \DateTimeZone( 'UTC' ) )->format( $format )
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Render the template with no runs.
	 *
	 * @param int|false $next_run   Next daily run.
	 * @param bool      $show_tests Whether test sends are listed.
	 */
	private function render( $next_run, bool $show_tests = true ): string {
		Functions\when( 'remove_query_arg' )->justReturn( 'admin.php?page=ffc-date-messages&tab=history' );
		Functions\when( 'add_query_arg' )->alias( static fn( $key, $value, $url ) => $url . '&' . $key . '=' . $value );
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

		$this->assertStringContainsString( 'Next daily run: 11/10/2026 07:00.', $html );
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

	public function test_the_filter_link_hides_tests_and_brings_them_back(): void {
		$shown  = $this->render( false );
		$hidden = $this->render( false, false );

		$this->assertStringContainsString( 'tab=history&tests=hide', $shown );
		$this->assertStringContainsString( 'Hide test sends', $shown );
		$this->assertStringContainsString( 'Show test sends', $hidden );
		$this->assertStringNotContainsString( 'tests=hide', $hidden );
	}

	/**
	 * Render one run.
	 *
	 * @param array<string, mixed>     $run          The run row.
	 * @param array<int, list<string>> $failed_names Masked names by run, as the page prepares them.
	 */
	private function render_run( array $run, array $failed_names ): string {
		Functions\when( 'remove_query_arg' )->justReturn( 'admin.php?page=ffc-date-messages&tab=history' );
		Functions\when( 'add_query_arg' )->alias( static fn( $key, $value, $url ) => $url . '&' . $key . '=' . $value );
		Functions\when( 'number_format_i18n' )->alias( static fn( $n ) => (string) $n );
		Functions\when( 'paginate_links' )->justReturn( '' );
		Functions\when( 'wp_kses_post' )->returnArg();
		$next_run   = false;
		$show_tests = true;
		$history    = array( $run );
		$total      = 1;
		$paged      = 1;
		$rule_names = array( 3 => 'Birthdays' );
		ob_start();
		include dirname( __DIR__, 2 ) . '/templates/admin/date-messages/history.php';
		return (string) ob_get_clean();
	}

	public function test_a_run_says_who_it_failed_and_how_many_had_it_already(): void {
		// History could not say who failed, nor count an earlier delivery (#2692).
		$run = array(
			'id'           => 7,
			'rule_id'      => 3,
			'trigger_kind' => 'cron',
			'target_from'  => '2026-10-10',
			'target_to'    => '2026-10-10',
			'started_at'   => 1791702000,
			'finished_at'  => 1791702100,
			'sent'         => 4,
			'failed'       => 2,
			'already_sent' => 5,
		);

		$html = $this->render_run( $run, array( 7 => array( 'Ana S****', 'Bia L****' ) ) );

		$this->assertStringContainsString( 'Already sent before', $html );
		$this->assertMatchesRegularExpression( '#<td>\s*5\s*</td>#', $html, 'The counter is drawn in its column.' );
		$this->assertStringContainsString( '<summary>Who</summary>', $html );
		$this->assertStringContainsString( '<li>Ana S****</li>', $html );
		$this->assertStringContainsString( '<li>Bia L****</li>', $html );
	}

	public function test_without_names_the_failed_cell_is_the_count_alone(): void {
		// A viewer without the personal-data capability gets no list.
		$run = array(
			'id'          => 7,
			'rule_id'     => 3,
			'target_from' => '2026-10-10',
			'target_to'   => '2026-10-10',
			'failed'      => 2,
		);

		$html = $this->render_run( $run, array() );

		$this->assertStringNotContainsString( '<summary>Who</summary>', $html );
	}
}
