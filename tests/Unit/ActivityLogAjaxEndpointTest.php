<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Admin\ActivityLogAjaxEndpoint;

/**
 * Tests for the Activity Log fetch endpoint introduced in 6.5.8.
 *
 * The real collaborators run: Capabilities (over a stubbed
 * `current_user_can`), SettingsReader (over a stubbed `get_option`),
 * AdminActivityLogPage's query builder and renderers, and ActivityLogQuery
 * over a `$wpdb` double that substitutes the bound values into the SQL,
 * records every statement it answered and fails the test on any other.
 *
 * @covers \FreeFormCertificate\Admin\ActivityLogAjaxEndpoint
 */
class ActivityLogAjaxEndpointTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var list<string> Capabilities the current user holds. */
	private array $caps = array();

	/** @var array<string, mixed> The stored `ffc_settings` option. */
	private array $settings = array();

	/** @var list<array<string, mixed>> Rows the page query answers. */
	private array $rows = array();

	/** @var int What the count query answers. */
	private int $total = 0;

	/** @var list<string> Every statement the double answered, values bound. */
	private array $ran = array();

	/** @var mixed The `$wpdb` global an earlier test left, restored afterwards. */
	private $wpdb_backup;

	/** @var bool Whether `$wpdb` was set before this test. */
	private bool $had_wpdb = false;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->caps     = array();
		$this->settings = array();
		$this->rows     = array();
		$this->total    = 0;
		$this->ran      = array();

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'wp_kses_post' )->returnArg();
		Functions\when( '_n' )->alias( static fn( $single, $plural, $n ) => 1 === (int) $n ? $single : $plural );
		Functions\when( 'number_format_i18n' )->alias( static fn( $n ) => (string) $n );
		Functions\when( 'add_query_arg' )->alias( static fn( $key, $value ) => '?' . $key . '=' . $value );
		Functions\when( 'paginate_links' )->alias(
			static fn( $args ) => '<a class="page-numbers">' . $args['current'] . '/' . $args['total'] . '</a>'
		);
		Functions\when( 'sanitize_key' )->alias(
			function ( $key ) {
				return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
			}
		);
		Functions\when( 'sanitize_text_field' )->alias(
			function ( $s ) {
				return trim( strip_tags( (string) $s ) );
			}
		);
		Functions\when( 'absint' )->alias(
			function ( $v ) {
				return (int) abs( (int) $v );
			}
		);
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'wp_parse_args' )->alias( static fn( $args, $defaults ) => array_merge( $defaults, $args ) );
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'check_ajax_referer' )->justReturn( true );
		// A logged-out viewer: the address stays masked and no Reveal is drawn.
		Functions\when( 'get_current_user_id' )->justReturn( 0 );
		Functions\when( 'current_user_can' )->alias( fn( $cap ) => in_array( $cap, $this->caps, true ) );
		Functions\when( 'get_option' )->alias(
			fn( $key, $default = false ) => 'ffc_settings' === $key ? $this->settings : $default
		);
		Functions\when( 'wp_timezone' )->alias( static fn() => new \DateTimeZone( 'UTC' ) );
		Functions\when( 'wp_date' )->alias(
			static fn( $format, $ts = null, $tz = null ) => ( new \DateTimeImmutable( '@' . (int) $ts ) )->setTimezone( $tz ?? new \DateTimeZone( 'UTC' ) )->format( $format )
		);

		Functions\when( 'wp_send_json_error' )->alias(
			function ( $data = null ) {
				$msg = is_array( $data ) && isset( $data['message'] ) ? $data['message'] : 'error';
				throw new \RuntimeException( 'json_error: ' . $msg );
			}
		);
		Functions\when( 'wp_send_json_success' )->alias(
			function ( $data = null ) {
				throw new \RuntimeException( 'json_success: ' . json_encode( $data ) );
			}
		);

		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'esc_like' )->andReturnUsing( static fn( $text ) => addcslashes( (string) $text, '_%\\' ) );
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			static function ( $sql, ...$args ) {
				$flat = 1 === count( $args ) && is_array( $args[0] ) ? $args[0] : $args;
				return (string) preg_replace_callback(
					'/%[sdi]/',
					static function ( $m ) use ( &$flat ) {
						$value = array_shift( $flat );
						if ( '%d' === $m[0] ) {
							return (string) (int) $value;
						}
						return '%i' === $m[0] ? '`' . $value . '`' : "'" . $value . "'";
					},
					$sql
				);
			}
		);
		$wpdb->shouldReceive( 'get_results' )->andReturnUsing(
			function ( $sql ) {
				$this->ran[] = $sql;
				if ( 0 === strpos( $sql, 'SELECT * FROM `wp_ffc_activity_log` WHERE ' ) ) {
					return $this->rows;
				}
				$this->fail( 'Unexpected get_results(): ' . $sql );
			}
		);
		$wpdb->shouldReceive( 'get_var' )->andReturnUsing(
			function ( $sql ) {
				$this->ran[] = $sql;
				if ( 0 === strpos( $sql, 'SELECT COUNT(*) FROM `wp_ffc_activity_log` WHERE ' ) ) {
					return (string) $this->total;
				}
				$this->fail( 'Unexpected get_var(): ' . $sql );
			}
		);

		$this->had_wpdb    = array_key_exists( 'wpdb', $GLOBALS );
		$this->wpdb_backup = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb']   = $wpdb;
	}

	protected function tearDown(): void {
		$_POST = array();
		if ( $this->had_wpdb ) {
			$GLOBALS['wpdb'] = $this->wpdb_backup;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		Monkey\tearDown();
		parent::tearDown();
	}

	// ==================================================================
	// Guards
	// ==================================================================

	public function test_rejects_when_user_lacks_capability(): void {
		$this->settings = array( 'enable_activity_log' => 1 );

		try {
			ActivityLogAjaxEndpoint::handle();
			$this->fail( 'Expected wp_send_json_error to short-circuit' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'do not have permission', $e->getMessage() );
		}
		$this->assertSame( array(), $this->ran, 'A refused caller reaches no query.' );
	}

	public function test_rejects_when_activity_log_disabled(): void {
		$this->caps = array( 'ffc_view_activity_log' );
		// The stored settings carry no enable_activity_log.

		try {
			ActivityLogAjaxEndpoint::handle();
			$this->fail( 'Expected wp_send_json_error to short-circuit' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'currently disabled', $e->getMessage() );
		}
		$this->assertSame( array(), $this->ran, 'A disabled log reaches no query.' );
	}

	// ==================================================================
	// Happy path
	// ==================================================================

	public function test_returns_rendered_table_html_pagination_html_and_counts(): void {
		$this->caps     = array( 'ffc_view_activity_log' );
		$this->settings = array(
			'enable_activity_log' => 1,
			'date_format'         => 'd/m/Y',
			'time_format'         => 'H:i',
		);
		$this->rows     = array(
			array(
				'id'         => '9',
				'created_at' => '2026-05-14 10:00:00',
				'level'      => 'info',
				'action'     => 'submission_created',
				'user_id'    => '0',
				'user_ip'    => '127.0.0.1',
				'context'    => '',
			),
		);
		$this->total    = 123;

		$_POST = array(
			'nonce'      => 'x',
			'level'      => 'info',
			'log_action' => '',
			'search'     => '',
			'paged'      => '1',
		);

		try {
			ActivityLogAjaxEndpoint::handle();
			$this->fail( 'Expected wp_send_json_success to short-circuit' );
		} catch ( \RuntimeException $e ) {
			$msg = $e->getMessage();
			$this->assertStringStartsWith( 'json_success: ', $msg );
			$payload = json_decode( substr( $msg, strlen( 'json_success: ' ) ), true );
		}

		$this->assertSame(
			array(
				"SELECT * FROM `wp_ffc_activity_log` WHERE 1=1 AND level = 'info' ORDER BY created_at DESC LIMIT 0, 50",
				"SELECT COUNT(*) FROM `wp_ffc_activity_log` WHERE 1=1 AND level = 'info'",
			),
			$this->ran,
			'The posted filter and page reach both queries.'
		);
		$this->assertStringContainsString( '14/05/2026', $payload['table_html'] );
		$this->assertStringContainsString( 'Submission Created', $payload['table_html'] );
		$this->assertStringContainsString( '127.0.***.***', $payload['table_html'], 'The address is masked.' );
		$this->assertStringContainsString( '<div class="tablenav bottom">', $payload['pagination_html'] );
		$this->assertStringContainsString( '<a class="page-numbers">1/3</a>', $payload['pagination_html'] );
		$this->assertStringContainsString( '123 logs', $payload['pagination_html'] );
		$this->assertSame( 123, $payload['total_logs'] );
		$this->assertSame( 3, $payload['total_pages'] );
		$this->assertSame( 1, $payload['current_page'] );
		$this->assertFalse( $payload['is_empty'] );
	}

	public function test_empty_result_sets_is_empty_true(): void {
		$this->caps     = array( 'manage_options' );
		$this->settings = array( 'enable_activity_log' => 1 );

		$_POST = array( 'nonce' => 'x' );

		try {
			ActivityLogAjaxEndpoint::handle();
			$this->fail( 'Expected wp_send_json_success to short-circuit' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( '"is_empty":true', $e->getMessage() );
			$this->assertStringContainsString( '"total_logs":0', $e->getMessage() );
			$this->assertStringContainsString( '"table_html":""', $e->getMessage() );
			$this->assertStringContainsString( '"pagination_html":""', $e->getMessage() );
		}
	}
}
