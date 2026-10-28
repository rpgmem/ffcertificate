<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Admin\SubmissionsBulkActionsAjaxEndpoint;

/**
 * Tests for the Submissions bulk-actions AJAX endpoint introduced in 6.5.9.
 *
 * The dispatch tests run the real SubmissionHandler, its lifecycle service,
 * SubmissionRepository and its writer, down to a `$wpdb` double that renders
 * each prepared statement and records it, so a test asserts the SQL the
 * action produced. The activity log is switched off in `ffc_settings`, so
 * ActivityLog::log() returns before it buffers anything.
 *
 * @covers \FreeFormCertificate\Admin\SubmissionsBulkActionsAjaxEndpoint
 */
class SubmissionsBulkActionsAjaxEndpointTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var list<string> Every statement the double's query() ran, rendered. */
	private array $queries = array();

	/** @var int|false What the double's query() answers: rows affected, or false for a database error. */
	private $affected = 0;

	/** @var mixed The `$wpdb` global an earlier test left, restored afterwards. */
	private $wpdb_backup;

	/** @var bool Whether `$wpdb` was set before this test. */
	private bool $had_wpdb = false;

	/** @var array<string, mixed> The `$_POST` an earlier test left, restored afterwards. */
	private array $post_backup = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->post_backup = $_POST ?? array();
		$this->had_wpdb    = array_key_exists( 'wpdb', $GLOBALS );
		$this->wpdb_backup = $this->had_wpdb ? $GLOBALS['wpdb'] : null;
		$this->queries     = array();
		$this->affected    = 0;

		Functions\when( 'get_option' )->alias(
			static function ( $key, $default = false ) {
				// The activity log stays off, so the bulk methods log nothing.
				return 'ffc_settings' === $key ? array( 'enable_activity_log' => 0 ) : $default;
			}
		);
		Functions\when( 'wp_cache_flush' )->justReturn( true );
		Functions\when( 'delete_transient' )->justReturn( true );

		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			static function ( $sql, ...$args ) {
				$values = ( 1 === count( $args ) && is_array( $args[0] ) ) ? $args[0] : $args;
				foreach ( $values as $v ) {
					$sql = (string) preg_replace( '/%[ids]/', (string) $v, (string) $sql, 1 );
				}
				return $sql;
			}
		);
		$wpdb->shouldReceive( 'query' )->andReturnUsing(
			function ( $sql ) {
				$this->queries[] = $sql;
				return $this->affected;
			}
		);
		$GLOBALS['wpdb'] = $wpdb;

		Functions\when( '__' )->returnArg();
		Functions\when( '_n' )->alias( function ( $s, $p, $c ) {
			return 1 === $c ? $s : $p;
		} );
		Functions\when( 'sanitize_key' )->alias( function ( $key ) {
			return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
		} );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'absint' )->alias( function ( $v ) { return (int) abs( (int) $v ); } );
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'check_ajax_referer' )->justReturn( true );

		Functions\when( 'wp_send_json_error' )->alias( function ( $data = null ) {
			$msg = is_array( $data ) && isset( $data['message'] ) ? $data['message'] : 'error';
			throw new \RuntimeException( 'json_error: ' . $msg );
		} );
		Functions\when( 'wp_send_json_success' )->alias( function ( $data = null ) {
			$payload = wp_json_encode( $data );
			throw new \RuntimeException( 'json_success: ' . $payload );
		} );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
	}

	protected function tearDown(): void {
		$_POST = $this->post_backup;
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
		Functions\when( 'current_user_can' )->justReturn( false );

		$_POST = array( 'nonce' => 'x', 'action_name' => 'trash', 'ids' => array( 1 ) );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'do not have permission' );
		SubmissionsBulkActionsAjaxEndpoint::handle();
	}

	public function test_rejects_unknown_action(): void {
		Functions\when( 'current_user_can' )->justReturn( true );

		$_POST = array( 'nonce' => 'x', 'action_name' => 'mystery', 'ids' => array( 1 ) );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Unknown action' );
		SubmissionsBulkActionsAjaxEndpoint::handle();
	}

	public function test_rejects_empty_ids(): void {
		Functions\when( 'current_user_can' )->justReturn( true );

		$_POST = array( 'nonce' => 'x', 'action_name' => 'trash', 'ids' => array() );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'No submissions selected' );
		SubmissionsBulkActionsAjaxEndpoint::handle();
	}

	public function test_filters_non_positive_ids_and_rejects_when_nothing_left(): void {
		Functions\when( 'current_user_can' )->justReturn( true );

		// Non-numeric strings and 0 collapse to 0 under absint, then
		// the >0 filter drops them. absint(-3) is 3 (positive) — by
		// design — so the test deliberately omits negative literals.
		$_POST = array( 'nonce' => 'x', 'action_name' => 'trash', 'ids' => array( 0, 'x', '0', '' ) );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'No submissions selected' );
		SubmissionsBulkActionsAjaxEndpoint::handle();
	}

	// ==================================================================
	// Dispatch — each action reaches the matching bulk statement
	// ==================================================================

	public function test_trash_dispatches_to_bulk_trash_submissions(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		$this->affected = 3;

		$_POST = array( 'nonce' => 'x', 'action_name' => 'trash', 'ids' => array( 1, 2, 3 ) );

		$message = $this->handle_and_capture();

		$this->assertSame(
			array( 'UPDATE wp_ffc_submissions SET status = trash WHERE id IN (1, 2, 3)' ),
			$this->queries,
			'trash must run one bulk status UPDATE over exactly the posted ids.'
		);
		$this->assertStringStartsWith( 'json_success', $message );
		$this->assertStringContainsString( '"action":"trash"', $message );
		$this->assertStringContainsString( '"count":3', $message );
		$this->assertStringContainsString( 'submissions moved to trash', $message );
	}

	public function test_restore_dispatches_to_bulk_restore_submissions(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		$this->affected = 1;

		$_POST = array( 'nonce' => 'x', 'action_name' => 'restore', 'ids' => array( 42 ) );

		$message = $this->handle_and_capture();

		$this->assertSame(
			array( 'UPDATE wp_ffc_submissions SET status = publish WHERE id IN (42)' ),
			$this->queries,
			'restore must run one bulk status UPDATE back to publish.'
		);
		$this->assertStringContainsString( '"action":"restore"', $message );
		$this->assertStringContainsString( 'submission restored', $message );
	}

	public function test_delete_dispatches_to_bulk_delete_submissions(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		$this->affected = 2;

		$_POST = array( 'nonce' => 'x', 'action_name' => 'delete', 'ids' => array( 5, 7 ) );

		$message = $this->handle_and_capture();

		$this->assertSame(
			array( 'DELETE FROM wp_ffc_submissions WHERE id IN (5, 7)' ),
			$this->queries,
			'delete must run one bulk DELETE over exactly the posted ids.'
		);
		$this->assertStringContainsString( '"action":"delete"', $message );
		$this->assertStringContainsString( 'permanently deleted', $message );
	}

	public function test_handler_failure_returns_500_error(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		// $wpdb->query() returns false on a database error.
		$this->affected = false;

		$_POST = array( 'nonce' => 'x', 'action_name' => 'trash', 'ids' => array( 9 ) );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'bulk operation failed' );
		SubmissionsBulkActionsAjaxEndpoint::handle();
	}

	/**
	 * Run the endpoint and return the message of the JSON exception it ends with.
	 *
	 * @return string
	 */
	private function handle_and_capture(): string {
		try {
			SubmissionsBulkActionsAjaxEndpoint::handle();
		} catch ( \RuntimeException $e ) {
			return $e->getMessage();
		}
		$this->fail( 'The endpoint must end with a JSON response.' );
	}

	public function test_action_map_exposes_all_three_actions(): void {
		$map = SubmissionsBulkActionsAjaxEndpoint::action_map();
		$this->assertSame( 'bulk_trash_submissions',   $map['trash'] );
		$this->assertSame( 'bulk_restore_submissions', $map['restore'] );
		$this->assertSame( 'bulk_delete_submissions',  $map['delete'] );
		// move_to_form is intentionally absent — has its own flow.
		$this->assertArrayNotHasKey( 'move_to_form', $map );
	}
}
