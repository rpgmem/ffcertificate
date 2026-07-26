<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Admin\ActivityLogExportSource;

/**
 * Tests for ActivityLogExportSource: the six-column layout + per-row formatting,
 * the user-name cache, the filter/count/keyset-page delegation to ActivityLogQuery,
 * and the per-phase authorization gates. The job lifecycle it plugs into is tested
 * in BatchedCsvExportTest. Migrated from the former synchronous
 * AdminActivityLogPage::handle_csv_export (issue #772).
 *
 * Process isolation is used so the count()/fetch_page() delegation tests can
 * alias-mock the static ActivityLogQuery without the alias leaking across the
 * suite.
 *
 * @covers \FreeFormCertificate\Admin\ActivityLogExportSource
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class ActivityLogExportSourceTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var ActivityLogExportSource */
	private $source;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
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

	protected function tearDown(): void {
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

		$result = $this->source->format_row( $this->sample_row(), array() );

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

	public function test_count_delegates_to_query(): void {
		Mockery::mock( 'alias:\FreeFormCertificate\Core\ActivityLogQuery' )
			->shouldReceive( 'count_activities' )->once()
			->with( array( 'level' => 'error' ) )->andReturn( 12 );

		$this->assertSame( 12, $this->source->count( array( 'level' => 'error' ) ) );
	}

	public function test_fetch_page_delegates_keyset_to_query(): void {
		$rows = array( array( 'id' => 5 ), array( 'id' => 4 ) );
		Mockery::mock( 'alias:\FreeFormCertificate\Core\ActivityLogQuery' )
			->shouldReceive( 'find_by_cursor' )->once()
			->with( array( 'level' => 'error' ), 10, 50 )->andReturn( $rows );

		$page = $this->source->fetch_page( array( 'level' => 'error' ), array(), 10, 50 );
		$this->assertSame( $rows, $page );
	}

	public function test_cursor_of_reads_id(): void {
		$this->assertSame( 4, $this->source->cursor_of( array( 'id' => 4 ) ) );
		$this->assertSame( 0, $this->source->cursor_of( array() ) );
	}

	public function test_build_context_is_empty(): void {
		$this->assertSame( array(), $this->source->build_context( array( 'level' => 'error' ) ) );
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

	private function stub_terminators(): void {
		Functions\when( 'wp_send_json_error' )->alias(
			static function () {
				throw new \RuntimeException( 'json_error' );
			}
		);
		Functions\when( 'wp_die' )->alias(
			static function () {
				throw new \RuntimeException( 'wp_die' );
			}
		);
	}

	public function test_authorize_start_rejects_without_capability(): void {
		$this->stub_terminators();
		Functions\when( 'check_ajax_referer' )->justReturn( true );
		// Capabilities::current_user_can_admin_or() delegates to current_user_can().
		Functions\when( 'current_user_can' )->justReturn( false );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'json_error' );
		$this->source->authorize_start();
	}

	public function test_authorize_batch_rejects_on_user_mismatch(): void {
		$this->stub_terminators();
		Functions\when( 'check_ajax_referer' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 1 );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'json_error' );
		$this->source->authorize_batch( array( 'user_id' => 99 ) );
	}

	public function test_authorize_download_rejects_on_bad_nonce(): void {
		$this->stub_terminators();
		Functions\when( 'wp_verify_nonce' )->justReturn( false );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'wp_die' );
		$this->source->authorize_download( array( 'user_id' => 1 ) );
	}

	public function test_authorize_download_rejects_on_user_mismatch(): void {
		$this->stub_terminators();
		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 1 );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'wp_die' );
		$this->source->authorize_download( array( 'user_id' => 99 ) );
	}

	public function test_job_owner_fields_returns_user_id(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 42 );
		$this->assertSame( array( 'user_id' => 42 ), $this->source->job_owner_fields() );
	}
}
