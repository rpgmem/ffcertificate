<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use FreeFormCertificate\Tests\Support\BatchedExportSourceContractTestCase;
use FreeFormCertificate\Admin\ActivityLogExportSource;

/**
 * Tests for ActivityLogExportSource: the six-column layout + per-row formatting,
 * the user-name cache, the filter/count/keyset-page delegation to ActivityLogQuery,
 * and the per-phase authorization gates. The job lifecycle it plugs into is tested
 * in BatchedCsvExportTest. Migrated from the former synchronous
 * AdminActivityLogPage::handle_csv_export (issue #772).
 *
 * Every test runs in the shared process. The count()/fetch_page() tests run
 * the real ActivityLogQuery over a `$wpdb` double and assert on the
 * statements it prepared and the rows it hands back.
 *
 * @covers \FreeFormCertificate\Admin\ActivityLogExportSource
 */
class ActivityLogExportSourceTest extends BatchedExportSourceContractTestCase {


	/** @var ActivityLogExportSource */
	private $source;

	/**
	 * Every prepared statement, in order: [ sql, args ].
	 *
	 * @var list<array{0: string, 1: array<int, mixed>}>
	 */
	private array $prepared = array();

	/** @var mixed The `$wpdb` global before the test. */
	private $previous_wpdb;

	/** Whether `$wpdb` was set before the test. */
	private bool $had_wpdb = false;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->prepared      = array();
		$this->had_wpdb      = array_key_exists( 'wpdb', $GLOBALS );
		$this->previous_wpdb = $GLOBALS['wpdb'] ?? null;
		Functions\when( 'wp_parse_args' )->alias( static fn( $args, $defaults = array() ) => array_merge( $defaults, $args ) );
		class_exists( '\\FreeFormCertificate\\Admin\\ActivityLogExportSource' );

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		// RequestInput::get_post_string / get_get_string read the superglobals
		// through these unqualified core helpers (global fallback resolution).
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'sanitize_key' )->alias( static fn( $v ) => strtolower( (string) $v ) );

		$this->source = new ActivityLogExportSource();
	}


	/**
	 * The source this file builds, for the contract the base asserts.
	 *
	 * @return object
	 */
	protected function export_source() {
		return $this->source;
	}

	protected function tearDown(): void {
		if ( $this->had_wpdb ) {
			$GLOBALS['wpdb'] = $this->previous_wpdb;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		Monkey\tearDown();
		unset( $_POST['level'], $_POST['log_action'], $_POST['s'] );
		parent::tearDown();
	}

	/** Invoke a private/protected method on the source. */
	private function invoke( string $method, array $args = array() ) {
		$ref = new \ReflectionMethod( ActivityLogExportSource::class, $method );
		$ref->setAccessible( true );
		return $ref->invokeArgs( $this->source, $args );
	}

	private function sample_row(): array {
		return array(
			'id'         => 7,
			'created_at' => '2026-01-15 10:30:00',
			'level'      => 'error',
			'action'     => 'submission_created',
			'user_id'    => 3,
			'user_ip'    => '203.0.113.5',
			'context'    => array( 'form_id' => 42 ),
		);
	}

	// ==================================================================
	// type() / header()
	// ==================================================================

	public function test_type_is_activity_log(): void {
		$this->assertSame( 'activity_log', $this->source->type() );
	}

	public function test_header_has_six_columns(): void {
		$header = $this->source->header( array(), array() );
		$this->assertCount( 6, $header );
		$this->assertSame( 'Date/Time', $header[0] );
		$this->assertSame( 'Level', $header[1] );
		$this->assertSame( 'Context', $header[5] );
	}

	// ==================================================================
	// format_row()
	// ==================================================================

	public function test_format_row_maps_columns_in_order(): void {
		Functions\when( 'get_userdata' )->justReturn(
			(object) array(
				'display_name' => 'Jane Doe',
				'user_login'   => 'jane',
			)
		);
		Functions\when( 'wp_json_encode' )->alias( static fn( $v ) => json_encode( $v ) );

		$result = $this->source->format_row( $this->sample_row(), array( 'pii' => true ) );

		$this->assertSame( '2026-01-15 10:30:00', $result[0] );
		$this->assertSame( 'ERROR', $result[1] );
		$this->assertSame( 'Submission Created', $result[2] );
		$this->assertSame( 'Jane Doe (jane)', $result[3] );
		$this->assertSame( '203.0.113.5', $result[4] );
		$this->assertStringContainsString( '"form_id":42', $result[5] );
	}

	public function test_format_row_anonymous_user(): void {
		$row               = $this->sample_row();
		$row['user_id']    = 0;
		$row['context']    = '';
		$result            = $this->source->format_row( $row, array() );
		$this->assertSame( 'System / Anonymous', $result[3] );
		$this->assertSame( '', $result[5] );
	}

	public function test_format_row_deleted_user_shows_id(): void {
		Functions\when( 'get_userdata' )->justReturn( false );
		$row            = $this->sample_row();
		$row['user_id'] = 99;
		$row['context'] = '';
		$result         = $this->source->format_row( $row, array() );
		$this->assertSame( 'User #99', $result[3] );
	}

	public function test_format_row_string_context_passthrough(): void {
		Functions\when( 'get_userdata' )->justReturn( false );
		$row            = $this->sample_row();
		$row['user_id'] = 0;
		$row['context'] = 'plain string context';
		$result         = $this->source->format_row( $row, array() );
		$this->assertSame( 'plain string context', $result[5] );
	}

	public function test_user_display_is_cached(): void {
		$calls = 0;
		Functions\when( 'get_userdata' )->alias(
			static function () use ( &$calls ) {
				++$calls;
				return (object) array(
					'display_name' => 'Bob',
					'user_login'   => 'bob',
				);
			}
		);
		$this->assertSame( 'Bob (bob)', $this->invoke( 'user_display', array( 5 ) ) );
		$this->assertSame( 'Bob (bob)', $this->invoke( 'user_display', array( 5 ) ) );
		$this->assertSame( 1, $calls, 'get_userdata called once for the same id' );
	}

	// ==================================================================
	// sanitize_filters() / count() / fetch_page() / cursor_of()
	// ==================================================================

	public function test_sanitize_filters_reads_post(): void {
		$_POST['level']      = 'Error';
		$_POST['log_action'] = 'submission_created';
		$_POST['s']          = 'needle';

		$filters = $this->source->sanitize_filters();
		$this->assertSame( 'error', $filters['level'] );
		$this->assertSame( 'submission_created', $filters['action'] );
		$this->assertSame( 'needle', $filters['search'] );
	}

	/**
	 * Install a `$wpdb` double over the real ActivityLogQuery: every prepare()
	 * is recorded and answered with its index, and `$answer` receives the
	 * method and the statement behind each read. Any other call fails.
	 *
	 * @param callable $answer fn( string $method, string $sql, array $args ): mixed.
	 */
	private function install_wpdb( callable $answer ): void {
		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			function ( $sql, ...$args ) {
				if ( 1 === count( $args ) && is_array( $args[0] ) ) {
					$args = $args[0];
				}
				$this->prepared[] = array( (string) $sql, array_values( $args ) );
				return 'Q' . ( count( $this->prepared ) - 1 );
			}
		);
		foreach ( array( 'get_var', 'get_results' ) as $method ) {
			$wpdb->shouldReceive( $method )->andReturnUsing(
				function ( $query ) use ( $method, $answer ) {
					[ $sql, $args ] = $this->prepared[ (int) substr( (string) $query, 1 ) ];
					return $answer( $method, $sql, $args );
				}
			);
		}
		$GLOBALS['wpdb'] = $wpdb;
	}

	public function test_count_delegates_to_query(): void {
		$this->install_wpdb(
			function ( string $method, string $sql, array $args ) {
				$this->assertSame( 'get_var', $method );
				$this->assertSame( 'SELECT COUNT(*) FROM %i WHERE 1=1 AND Q0', $sql );
				$this->assertSame( array( 'wp_ffc_activity_log' ), $args );
				return '12';
			}
		);

		$this->assertSame( 12, $this->source->count( array( 'level' => 'error' ) ) );
		$this->assertSame( array( 'level = %s', array( 'error' ) ), $this->prepared[0], 'The level filter reaches the query.' );
		$this->assertCount( 2, $this->prepared, 'One filter and one count statement.' );
	}

	public function test_fetch_page_delegates_keyset_to_query(): void {
		$this->install_wpdb(
			function ( string $method, string $sql, array $args ) {
				$this->assertSame( 'get_results', $method );
				$this->assertSame( 'SELECT * FROM %i WHERE 1=1 AND Q0 AND id < %d ORDER BY id DESC LIMIT %d', $sql );
				$this->assertSame( array( 'wp_ffc_activity_log', 10, 50 ), $args, 'The cursor and the page size reach the query.' );
				return array(
					array(
						'id'      => '5',
						'context' => '{"form_id":42}',
						'user_ip' => '203.0.113.5',
					),
					array(
						'id'      => '4',
						'context' => null,
						'user_ip' => null,
					),
				);
			}
		);

		$page = $this->source->fetch_page( array( 'level' => 'error' ), array(), 10, 50 );

		$this->assertSame( array( 'level = %s', array( 'error' ) ), $this->prepared[0], 'The level filter reaches the query.' );
		$this->assertSame(
			array(
				array(
					'id'      => '5',
					'context' => array( 'form_id' => 42 ),
					'user_ip' => '203.0.113.5',
				),
				array(
					'id'      => '4',
					'context' => array(),
					'user_ip' => null,
				),
			),
			$page,
			'The page is the stored rows, newest first, with each context decoded.'
		);
	}

	public function test_build_context_freezes_whether_addresses_go_out_in_clear(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 3 );
		Functions\when( 'user_can' )->justReturn( false );
		$this->assertSame( array( 'pii' => false ), $this->source->build_context( array( 'level' => 'error' ) ) );

		Functions\when( 'user_can' )->alias( static fn( $uid, $cap ) => 'ffc_view_activity_log_pii' === $cap );
		$this->assertSame( array( 'pii' => true ), $this->source->build_context( array( 'level' => 'error' ) ), 'the reveal cap exports in clear (#2109)' );
	}

	public function test_a_masked_export_masks_every_address(): void {
		Functions\when( 'get_userdata' )->justReturn( false );
		Functions\when( 'wp_json_encode' )->alias( static fn( $v ) => json_encode( $v ) );
		$row            = $this->sample_row();
		$row['context'] = array(
			'triggered_by_ip' => '198.51.100.7',
			'form_id'         => 42,
		);

		$result = $this->source->format_row( $row, array( 'pii' => false ) );

		$this->assertSame( '203.0.***.***', $result[4] );
		$this->assertStringContainsString( '198.51.***.***', $result[5] );
		$this->assertStringNotContainsString( '198.51.100.7', $result[5] );
		$this->assertStringContainsString( '"form_id":42', $result[5] );
	}

	public function test_filename_is_dated(): void {
		// gmdate() is an internal function Patchwork can't redefine, so assert
		// the shape (ffc-activity-log-YYYY-MM-DD.csv) rather than a fixed date.
		$this->assertMatchesRegularExpression(
			'/^ffc-activity-log-\d{4}-\d{2}-\d{2}\.csv$/',
			$this->source->filename( array(), array() )
		);
	}

	// ==================================================================
	// authorize_start() / authorize_batch() / authorize_download()
	// ==================================================================

}
