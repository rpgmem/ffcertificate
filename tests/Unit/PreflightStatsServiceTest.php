<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Admin\PreflightStatsService;

/**
 * 6.6.4 follow-up (#361 Sprint 3) — PreflightStatsService aggregates
 * ActivityLog `preflight_blocked` rows into per-form + per-reason
 * counts. Pins:
 *   - Empty result when no rows
 *   - Filter by form_id (other forms' rows are ignored)
 *   - All three reasons counted (cookies / gps_denied / gps_prompt)
 *   - Unknown reasons silently dropped (don't crash on bad data)
 *
 * The rows come from the real ActivityLogQuery, over a `$wpdb` double that
 * answers the activity-log SELECT with the rows a test stores and records
 * every query it ran; any other query fails the test as unexpected.
 *
 * @covers \FreeFormCertificate\Admin\PreflightStatsService
 */
class PreflightStatsServiceTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var list<array<string, mixed>> Raw activity-log rows the SELECT answers. */
	private array $rows = array();

	/** @var list<string> Every SQL string the double's get_results() ran. */
	private array $ran = array();

	/** @var mixed The `$wpdb` global an earlier test left, restored afterwards. */
	private $wpdb_backup;

	/** @var bool Whether `$wpdb` was set before this test. */
	private bool $had_wpdb = false;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		class_exists( '\\FreeFormCertificate\\Admin\\PreflightStatsService' );
		Functions\when( '__' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'sanitize_key' )->returnArg();
		Functions\when( 'absint' )->alias( static fn( $v ) => abs( (int) $v ) );
		Functions\when( 'wp_parse_args' )->alias( static fn( $args, $defaults ) => array_merge( $defaults, $args ) );

		// Stubbed HERE, not inherited from whatever ran before: `get_form_stats()`
		// now caches in a transient (#1234), and CLAUDE.md records that a
		// function taught to Patchwork by another test stays taught for the
		// process -- so the branch has to be CHOSEN, not inherited. The default
		// is "empty cache", which is the path these tests measure.
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );

		$this->had_wpdb    = array_key_exists( 'wpdb', $GLOBALS );
		$this->wpdb_backup = $this->had_wpdb ? $GLOBALS['wpdb'] : null;

		$this->rows = array();
		$this->ran  = array();
		$rows       = &$this->rows;
		$ran        = &$this->ran;

		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			static function ( string $query, ...$args ): string {
				if ( 1 === count( $args ) && is_array( $args[0] ) ) {
					$args = $args[0];
				}
				$i = 0;
				return (string) preg_replace_callback(
					'/%[sdi]/',
					static function ( array $m ) use ( &$i, $args ): string {
						$value = $args[ $i++ ] ?? '';
						if ( '%d' === $m[0] ) {
							return (string) (int) $value;
						}
						return '%i' === $m[0] ? '`' . $value . '`' : "'" . $value . "'";
					},
					$query
				);
			}
		);
		$wpdb->shouldReceive( 'get_results' )->andReturnUsing(
			function ( string $sql ) use ( &$rows, &$ran ): array {
				$ran[] = $sql;
				$this->assertStringContainsString( 'FROM `wp_ffc_activity_log`', $sql, 'Only the activity log is read.' );
				$this->assertStringContainsString( "action = 'preflight_blocked'", $sql, 'Only pre-flight rows are read.' );
				return $rows;
			}
		);
		$GLOBALS['wpdb'] = $wpdb;
	}

	protected function tearDown(): void {
		if ( $this->had_wpdb ) {
			$GLOBALS['wpdb'] = $this->wpdb_backup;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * A cache hit does not touch the log (#1234).
	 *
	 * This is the assertion that carries the fix: the uncached read pulls up to 5,000
	 * rows into memory and does one `json_decode` per row, on every render of the
	 * form editor's sidebar. If the transient does not short-circuit, the cache
	 * exists in the code and not in the behaviour.
	 *
	 * The empty query record is what pins this -- comparing the returned value
	 * would not be enough, because the uncached read would return the same
	 * numbers.
	 */
	public function test_a_cache_hit_never_queries_the_activity_log(): void {
		$cached = array(
			'cookies'    => 3,
			'gps_denied' => 2,
			'gps_prompt' => 1,
			'total'      => 6,
		);
		Functions\when( 'get_transient' )->justReturn( $cached );

		$this->stub_activity_log_rows( array( array( 'context' => '{"form_id":7,"reason":"cookies"}' ) ) );

		$stats = PreflightStatsService::get_form_stats( 7 );
		$this->assertSame( array(), $this->ran, 'A cache hit must not query the activity log.' );
		$this->assertSame( 3, $stats['cookies'] );
		$this->assertSame( 6, $stats['total'] );
		// An entry cached before a reason was added (#2030) reads it as 0.
		$this->assertSame( 0, $stats['browser_inapp'] );
	}

	/**
	 * The key carries the form AND the window in days.
	 *
	 * Caching by form alone would return the 30-day count to whoever
	 * asked for 7 -- a wrong number served confidently, which is worse than the
	 * cost the cache avoids.
	 */
	public function test_the_cache_key_carries_both_form_and_window(): void {
		$seen = array();
		Functions\when( 'get_transient' )->alias(
			function ( $key ) use ( &$seen ) {
				$seen[] = (string) $key;
				return false;
			}
		);

		$this->stub_activity_log_rows( array() );

		PreflightStatsService::get_form_stats( 7, 30 );
		PreflightStatsService::get_form_stats( 7, 7 );

		$this->assertCount( 2, $seen );
		$this->assertNotSame( $seen[0], $seen[1], 'Two windows shared one key -- the 7-day read would serve the 30-day count.' );
		$this->assertStringContainsString( '7', $seen[0] );
		$this->assertCount( 2, $this->ran, 'Each uncached window reads the log once.' );
	}

	/**
	 * Helper: store the raw activity-log rows the `$wpdb` double answers
	 * the real ActivityLogQuery::get_activities() SELECT with.
	 *
	 * @param list<array<string, mixed>> $rows Rows as the table holds them.
	 */
	private function stub_activity_log_rows( array $rows ): void {
		$this->rows = $rows;
	}

	public function test_returns_zero_counts_when_no_rows(): void {
		$this->stub_activity_log_rows( array() );

		$stats = PreflightStatsService::get_form_stats( 42 );

		$this->assertSame(
			array(
				'cookies'                => 0,
				'gps_denied'             => 0,
				'gps_prompt'             => 0,
				'secure'                 => 0,
				'browser_inapp'          => 0,
				'browser_inapp_continue' => 0,
				'browser_features'       => 0,
				'browser_boot'           => 0,
				'total'                  => 0,
			),
			$stats
		);
	}

	public function test_returns_zero_counts_for_invalid_form_id(): void {
		// Short-circuits before the query.
		$stats = PreflightStatsService::get_form_stats( 0 );
		$this->assertSame( 0, $stats['total'] );
		$this->assertSame( array(), $this->ran );
	}

	public function test_counts_one_per_reason_for_matching_form(): void {
		$this->stub_activity_log_rows( array(
			array( 'context' => '{"form_id":42,"reason":"cookies"}' ),
			array( 'context' => '{"form_id":42,"reason":"cookies"}' ),
			array( 'context' => '{"form_id":42,"reason":"gps_denied"}' ),
			array( 'context' => '{"form_id":42,"reason":"gps_prompt"}' ),
			array( 'context' => '{"form_id":42,"reason":"gps_prompt"}' ),
			array( 'context' => '{"form_id":42,"reason":"gps_prompt"}' ),
		) );

		$stats = PreflightStatsService::get_form_stats( 42 );

		$this->assertSame( 2, $stats['cookies'] );
		$this->assertSame( 1, $stats['gps_denied'] );
		$this->assertSame( 3, $stats['gps_prompt'] );
		$this->assertSame( 6, $stats['total'] );
	}

	public function test_ignores_rows_for_other_forms(): void {
		$this->stub_activity_log_rows( array(
			array( 'context' => '{"form_id":42,"reason":"cookies"}' ),
			array( 'context' => '{"form_id":99,"reason":"cookies"}' ), // wrong form
			array( 'context' => '{"form_id":99,"reason":"gps_denied"}' ), // wrong form
		) );

		$stats = PreflightStatsService::get_form_stats( 42 );

		$this->assertSame( 1, $stats['cookies'] );
		$this->assertSame( 0, $stats['gps_denied'] );
		$this->assertSame( 1, $stats['total'] );
	}

	public function test_silently_drops_unknown_reason_and_malformed_context(): void {
		$this->stub_activity_log_rows( array(
			array( 'context' => '{"form_id":42,"reason":"cookies"}' ),
			array( 'context' => '{"form_id":42,"reason":"made_up"}' ),     // unknown reason
			array( 'context' => 'not even json' ),                          // malformed
			// The read path hands the service a decoded array, which is the
			// array shape the service accepts beside a JSON string.
			array( 'context' => '{"form_id":42,"reason":"gps_denied"}' ),
			array( /* no context key */ ),
		) );

		$stats = PreflightStatsService::get_form_stats( 42 );

		$this->assertSame( 1, $stats['cookies'] );
		$this->assertSame( 1, $stats['gps_denied'] );
		$this->assertSame( 0, $stats['gps_prompt'] );
		$this->assertSame( 2, $stats['total'] );
	}

	/**
	 * Helper: stub the escaping / i18n / url helpers render_metabox()
	 * calls so the markup branch executes without WP loaded.
	 */
	private function stub_render_helpers(): void {
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html_e' )->alias( static function ( $text ) {
			echo $text;
		} );
		Functions\when( 'esc_attr_e' )->alias( static function ( $text ) {
			echo $text;
		} );
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'admin_url' )->alias( static function ( $path = '' ) {
			return 'http://example.com/wp-admin/' . $path;
		} );
	}

	public function test_render_metabox_empty_state_when_no_friction(): void {
		$this->stub_activity_log_rows( array() );
		$this->stub_render_helpers();

		ob_start();
		PreflightStatsService::render_metabox( 42 );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'class="description"', $html );
		$this->assertStringContainsString( 'No pre-flight or rate-limit friction', $html );
		// The badges list must NOT render in the empty branch.
		$this->assertStringNotContainsString( 'ffc-preflight-stats-badges', $html );
	}

	public function test_render_metabox_renders_badges_with_counts(): void {
		$this->stub_activity_log_rows( array(
			array( 'context' => '{"form_id":42,"reason":"cookies"}' ),
			array( 'context' => '{"form_id":42,"reason":"cookies"}' ),
			array( 'context' => '{"form_id":42,"reason":"gps_denied"}' ),
			array( 'context' => '{"form_id":42,"reason":"gps_prompt"}' ),
		) );
		$this->stub_render_helpers();

		ob_start();
		PreflightStatsService::render_metabox( 42 );
		$html = (string) ob_get_clean();

		// The badges list renders with the three reason badges.
		$this->assertStringContainsString( 'ffc-preflight-stats-badges', $html );
		$this->assertStringContainsString( 'ffc-preflight-badge-cookies', $html );
		$this->assertStringContainsString( 'ffc-preflight-badge-gps-denied', $html );
		$this->assertStringContainsString( 'ffc-preflight-badge-gps-prompt', $html );

		// Each count is echoed inside a <strong> tag.
		$this->assertStringContainsString( '<strong>2</strong>', $html );
		$this->assertStringContainsString( '<strong>1</strong>', $html );

		// The drill-down link points at the filtered Activity Log.
		$this->assertStringContainsString(
			'admin.php?page=ffc-settings&tab=activity_log&log_action=preflight_blocked',
			$html
		);

		// The empty-state copy must NOT appear when there is friction.
		$this->assertStringNotContainsString( 'No pre-flight or rate-limit friction', $html );
	}

	/**
	 * The reasons #2030 added are counted like the first three, and their
	 * badges show only for a form where they happened.
	 */
	public function test_counts_and_shows_the_browser_and_https_reasons_only_when_they_happened(): void {
		$this->stub_activity_log_rows( array(
			array( 'context' => '{"form_id":42,"reason":"browser_inapp"}' ),
			array( 'context' => '{"form_id":42,"reason":"browser_inapp"}' ),
			array( 'context' => '{"form_id":42,"reason":"browser_inapp_continue"}' ),
			array( 'context' => '{"form_id":42,"reason":"secure"}' ),
		) );
		$this->stub_render_helpers();

		$stats = PreflightStatsService::get_form_stats( 42 );
		$this->assertSame( 2, $stats['browser_inapp'] );
		$this->assertSame( 1, $stats['browser_inapp_continue'] );
		$this->assertSame( 1, $stats['secure'] );
		$this->assertSame( 0, $stats['browser_features'] );
		$this->assertSame( 4, $stats['total'] );

		ob_start();
		PreflightStatsService::render_metabox( 42 );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'ffc-preflight-badge-browser-inapp"', $html );
		$this->assertStringContainsString( 'ffc-preflight-badge-browser-inapp-continue', $html );
		$this->assertStringContainsString( 'ffc-preflight-badge-secure', $html );
		$this->assertStringNotContainsString( 'ffc-preflight-badge-browser-features', $html );
		$this->assertStringNotContainsString( 'ffc-preflight-badge-browser-boot', $html );
		// The first three always show, at zero here.
		$this->assertStringContainsString( 'ffc-preflight-badge-cookies', $html );
	}
}
