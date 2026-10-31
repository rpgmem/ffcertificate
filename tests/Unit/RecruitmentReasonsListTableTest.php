<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Recruitment\RecruitmentReasonReader;
use FreeFormCertificate\Recruitment\RecruitmentReasonsListTable;
use FreeFormCertificate\Tests\Support\CallLog;

/**
 * Smoke tests for the Reasons list table: column declarations, sortable +
 * bulk-action contracts, the column renderers, prepare_items() and the
 * gated bulk delete.
 *
 * The collaborators are the real ones — RecruitmentReasonReader,
 * RecruitmentReasonWriter and Capabilities — over a `$wpdb` double that
 * answers each query by its SQL text and bound id and records every delete.
 * An unexpected query throws, which fails the test.
 *
 * @covers \FreeFormCertificate\Recruitment\RecruitmentReasonsListTable
 * @covers \FreeFormCertificate\Recruitment\AbstractRecruitmentListTable
 */
class RecruitmentReasonsListTableTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private RecruitmentReasonsListTable $table;

	/** @var bool Whether `$wpdb` was set before this test. */
	private bool $had_wpdb = false;

	/** @var mixed The `$wpdb` global an earlier test left, restored afterwards. */
	private $wpdb_backup;

	/** @var list<object> Rows the reason table holds. */
	private array $rows = array();

	/** @var array<int, int> Classification rows referencing each reason id. */
	private array $references = array();

	/**
	 * Prepared statements, keyed by the token prepare() returned.
	 *
	 * @var array<string, array{0: string, 1: array<int, mixed>}>
	 */
	private array $prepared = array();

	/** @var list<string> Every query the double answered, as its SQL text. */
	private array $queries = array();

	/**
	 * Every `$wpdb->delete()`, as [table, where].
	 *
	 * @var list<array{0: string, 1: array<string, mixed>}>
	 */
	private array $deletes = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		// A kept row is reported over the table (#2245).
		Functions\when( '_n' )->alias( static fn( $single, $plural, $n ) => 1 === $n ? $single : $plural );

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_attr__' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'sanitize_key' )->alias( fn( $v ) => strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $v ) ) );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'absint' )->alias( fn( $v ) => abs( (int) $v ) );
		Functions\when( 'add_query_arg' )->alias( fn( $args, $url = '' ) => $url . '?' . http_build_query( (array) $args ) );
		Functions\when( 'admin_url' )->alias( fn( $p = '' ) => 'https://example.com/wp-admin/' . $p );
		Functions\when( 'wp_nonce_url' )->alias( fn( $url, $action = -1 ) => $url . '&_wpnonce=test' );
		Functions\when( 'wp_json_encode' )->alias( fn( $v ) => json_encode( $v ) );
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( 'check_admin_referer' )->justReturn( true );

		$this->rows       = array();
		$this->references = array();
		$this->prepared   = array();
		$this->queries    = array();
		$this->deletes    = array();

		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			function ( $sql, ...$args ) {
				$token                    = 'prepared#' . count( $this->prepared );
				$this->prepared[ $token ] = array( (string) $sql, $args );
				return $token;
			}
		);
		$wpdb->shouldReceive( 'get_results' )->andReturnUsing( fn( $query ) => $this->answer( 'get_results', $query ) );
		$wpdb->shouldReceive( 'get_var' )->andReturnUsing( fn( $query ) => $this->answer( 'get_var', $query ) );
		$wpdb->shouldReceive( 'delete' )->andReturnUsing(
			function ( $table, $where ) {
				$this->deletes[] = array( $table, $where );
				return 1;
			}
		);

		$this->had_wpdb    = array_key_exists( 'wpdb', $GLOBALS );
		$this->wpdb_backup = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb']   = $wpdb;

		$this->table = new RecruitmentReasonsListTable();
	}

	protected function tearDown(): void {
		unset( $_REQUEST['s'], $_REQUEST['orderby'], $_REQUEST['order'], $_REQUEST['action'], $_REQUEST['action2'], $_REQUEST['reason_ids'] );
		if ( $this->had_wpdb ) {
			$GLOBALS['wpdb'] = $this->wpdb_backup;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Answer one read by the SQL text and the values bound to it.
	 *
	 * @param string $method The `$wpdb` method that ran the query.
	 * @param string $query  The token prepare() returned.
	 * @return mixed
	 */
	private function answer( string $method, string $query ) {
		if ( ! isset( $this->prepared[ $query ] ) ) {
			throw new \RuntimeException( "Unprepared {$method}: {$query}" );
		}
		list( $sql, $args ) = $this->prepared[ $query ];
		$this->queries[]    = $sql;
		$table              = (string) ( $args[0] ?? '' );
		$id                 = (int) ( $args[1] ?? 0 );

		if ( 'get_results' === $method && 'SELECT * FROM %i ORDER BY label ASC' === $sql && 'wp_ffc_recruitment_reason' === $table ) {
			return $this->rows;
		}
		if ( 'get_var' === $method && 'SELECT COUNT(*) FROM %i WHERE preview_reason_id = %d' === $sql && 'wp_ffc_recruitment_classification' === $table ) {
			return (string) ( $this->references[ $id ] ?? 0 );
		}
		throw new \RuntimeException( "Unexpected {$method}: {$sql} " . json_encode( $args ) );
	}

	/**
	 * The ids the double deleted from the reason table, in order.
	 *
	 * @return list<int>
	 */
	private function deleted_reason_ids(): array {
		$ids = array();
		foreach ( $this->deletes as $delete ) {
			$this->assertSame( 'wp_ffc_recruitment_reason', $delete[0] );
			$ids[] = (int) $delete[1]['id'];
		}
		return $ids;
	}

	/**
	 * Call a protected method on the list table via reflection.
	 *
	 * @param array<int, mixed> $args
	 * @return mixed
	 */
	private function call_protected( string $method, array $args = array() ) {
		$ref = new \ReflectionMethod( $this->table, $method );
		$ref->setAccessible( true );
		return $ref->invokeArgs( $this->table, $args );
	}

	public function test_get_columns_declares_expected_keys(): void {
		$cols = $this->table->get_columns();

		$this->assertSame(
			array( 'cb', 'slug', 'label', 'color', 'applies_to', 'usage', 'created_at' ),
			array_keys( $cols )
		);
	}

	public function test_get_columns_provides_checkbox_html_for_cb_column(): void {
		$cols = $this->table->get_columns();
		$this->assertStringContainsString( 'type="checkbox"', $cols['cb'] );
	}

	public function test_get_sortable_columns_returns_label_slug_created_at(): void {
		$sortable = $this->call_protected( 'get_sortable_columns' );

		$this->assertArrayHasKey( 'slug', $sortable );
		$this->assertArrayHasKey( 'label', $sortable );
		$this->assertArrayHasKey( 'created_at', $sortable );
		// created_at default-desc convention.
		$this->assertTrue( $sortable['created_at'][1] );
	}

	public function test_get_bulk_actions_returns_only_delete(): void {
		$actions = $this->call_protected( 'get_bulk_actions' );
		$this->assertSame( array( 'bulk-delete' ), array_keys( $actions ) );
	}

	public function test_column_cb_emits_checkbox_with_row_id(): void {
		$html = $this->call_protected( 'column_cb', array( array( 'id' => 42 ) ) );

		$this->assertStringContainsString( 'name="reason_ids[]"', $html );
		$this->assertStringContainsString( 'value="42"', $html );
	}

	public function test_column_default_escapes_arbitrary_value(): void {
		// esc_html stub is identity — assert pass-through.
		$out = $this->call_protected( 'column_default', array( array( 'label' => 'Plain Text' ), 'label' ) );
		$this->assertSame( 'Plain Text', $out );
	}

	public function test_column_default_returns_empty_string_for_missing_column(): void {
		$out = $this->call_protected( 'column_default', array( array(), 'nonexistent' ) );
		$this->assertSame( '', $out );
	}

	public function test_column_applies_to_renders_all_pill_when_stored_value_empty(): void {
		$out = $this->call_protected( 'column_applies_to', array( array( 'applies_to' => '' ) ) );
		$this->assertStringContainsString( 'All preview statuses', $out );
	}

	public function test_column_applies_to_renders_pills_for_each_decoded_value(): void {
		$out = $this->call_protected( 'column_applies_to', array( array( 'applies_to' => 'denied,granted' ) ) );

		$this->assertStringContainsString( 'Denied', $out );
		$this->assertStringContainsString( 'Granted', $out );
	}

	public function test_column_usage_delegates_to_repository_count(): void {
		$this->references = array(
			7 => 5,
			8 => 1,
		);

		$out = $this->call_protected( 'column_usage', array( array( 'id' => 7 ) ) );

		$this->assertSame( '5', $out );
	}

	public function test_column_created_at_returns_escaped_timestamp_string(): void {
		$out = $this->call_protected( 'column_created_at', array( array( 'created_at' => '2026-05-18 10:00:00' ) ) );
		$this->assertSame( '2026-05-18 10:00:00', $out );
	}

	// ------------------------------------------------------------------
	// GAP I — read-only viewer (can_edit = false)
	// ------------------------------------------------------------------

	public function test_get_bulk_actions_empty_for_read_only_viewer(): void {
		$table = new RecruitmentReasonsListTable( false );
		$ref   = new \ReflectionMethod( $table, 'get_bulk_actions' );
		$ref->setAccessible( true );
		$this->assertSame( array(), $ref->invoke( $table ) );
	}

	public function test_column_color_renders_static_swatch_for_read_only_viewer(): void {
		$table = new RecruitmentReasonsListTable( false );
		$ref   = new \ReflectionMethod( $table, 'column_color' );
		$ref->setAccessible( true );
		$out = $ref->invokeArgs( $table, array( array( 'id' => 3, 'color' => '#abcdef' ) ) );

		// No editable input; just a swatch + hex code.
		$this->assertStringNotContainsString( '<input', $out );
		$this->assertStringContainsString( '#abcdef', $out );
	}

	public function test_column_color_renders_picker_for_editor(): void {
		// Default constructor (can_edit = true) keeps the inline picker.
		$out = $this->call_protected( 'column_color', array( array( 'id' => 3, 'color' => '#abcdef' ) ) );
		$this->assertStringContainsString( 'type="color"', $out );
		$this->assertStringContainsString( 'data-ffc-color-endpoint="reasons"', $out );
	}

	public function test_column_color_falls_back_to_default_color_for_editor(): void {
		$out = $this->call_protected( 'column_color', array( array( 'id' => 3 ) ) );
		$this->assertStringContainsString( RecruitmentReasonReader::DEFAULT_COLOR, $out );
	}

	// ------------------------------------------------------------------
	// column_slug() — editor (row actions) vs read-only (plain code)
	// ------------------------------------------------------------------

	public function test_column_slug_editor_renders_edit_delete_actions(): void {
		$out = $this->call_protected( 'column_slug', array( array( 'id' => 9, 'slug' => 'no-show' ) ) );

		$this->assertStringContainsString( 'no-show', $out );
		$this->assertStringContainsString( 'action=edit-reason', $out );
		$this->assertStringContainsString( 'action=delete-reason', $out );
		$this->assertStringContainsString( 'submitdelete', $out );
	}

	public function test_column_slug_read_only_is_plain_code_no_actions(): void {
		$table = new RecruitmentReasonsListTable( false );
		$ref   = new \ReflectionMethod( $table, 'column_slug' );
		$ref->setAccessible( true );
		$out = $ref->invokeArgs( $table, array( array( 'id' => 9, 'slug' => 'no-show' ) ) );

		$this->assertStringContainsString( 'no-show', $out );
		$this->assertStringContainsString( '<code>', $out );
		$this->assertStringNotContainsString( 'submitdelete', $out );
		$this->assertStringNotContainsString( 'row-actions', $out );
	}

	// ------------------------------------------------------------------
	// prepare_items()
	// ------------------------------------------------------------------

	private function reason_obj( int $id, string $slug, string $label ): object {
		return (object) array(
			'id'         => $id,
			'slug'       => $slug,
			'label'      => $label,
			'color'      => '#abcdef',
			'applies_to' => '',
			'created_at' => '2026-01-0' . $id . ' 09:00:00',
		);
	}

	private function get_items(): array {
		$ref = new \ReflectionProperty( $this->table, 'items' );
		$ref->setAccessible( true );
		return (array) $ref->getValue( $this->table );
	}

	public function test_prepare_items_populates_items(): void {
		$this->rows = array(
			$this->reason_obj( 1, 'alpha', 'Alpha' ),
			$this->reason_obj( 2, 'beta', 'Beta' ),
		);

		$this->table->prepare_items();

		$this->assertCount( 2, $this->get_items() );
		$this->assertSame( array( 'SELECT * FROM %i ORDER BY label ASC' ), $this->queries );
	}

	public function test_prepare_items_applies_search_and_sort(): void {
		$_REQUEST['orderby'] = 'slug';
		$_REQUEST['order']   = 'asc';
		$this->rows          = array(
			$this->reason_obj( 1, 'gamma', 'Gamma label' ),
			$this->reason_obj( 2, 'alpha', 'Alpha label' ),
		);

		$this->table->prepare_items();

		$items = $this->get_items();
		$this->assertSame( array( 'alpha', 'gamma' ), array_column( $items, 'slug' ) );
	}

	// ------------------------------------------------------------------
	// process_bulk_action() — GAP I gating + reference block + delete
	// ------------------------------------------------------------------

	public function test_process_bulk_action_returns_early_without_action(): void {
		$_REQUEST['reason_ids'] = array( '6' );
		$caps                   = CallLog::record( 'current_user_can', true );

		$this->call_protected( 'process_bulk_action' );

		$this->assertSame( 0, $caps->count(), 'No action must not even consult the capability.' );
		$this->assertSame( array(), $this->queries );
		$this->assertSame( array(), $this->deletes );
	}

	public function test_process_bulk_action_blocked_without_manage_cap(): void {
		$_REQUEST['action']     = 'bulk-delete';
		$_REQUEST['reason_ids'] = array( '6' );

		$caps    = CallLog::record( 'current_user_can', false );
		$referer = CallLog::record( 'check_admin_referer', true );

		$this->call_protected( 'process_bulk_action' );

		$this->assertSame( 2, $caps->count(), 'Both manage_options and the reasons-manage cap are refused.' );
		$this->assertSame( 0, $referer->count() );
		$this->assertSame( array(), $this->queries );
		$this->assertSame( array(), $this->deletes );
	}

	public function test_process_bulk_action_skips_referenced_reasons(): void {
		$_REQUEST['action']     = 'bulk-delete';
		$_REQUEST['reason_ids'] = array( '5', '6' );

		Functions\when( 'current_user_can' )->alias( static fn( $cap ) => 'ffc_manage_recruitment_reasons' === $cap );

		// Reason 5 has references → skipped; reason 6 is free → deleted.
		$this->references = array(
			5 => 2,
			6 => 0,
		);

		$notices = array();
		Functions\when( 'wp_admin_notice' )->alias(
			static function ( $message ) use ( &$notices ) {
				$notices[] = $message;
			}
		);

		$this->call_protected( 'process_bulk_action' );

		$this->assertSame( array( 6 ), $this->deleted_reason_ids() );
		$this->assertCount( 1, $notices );
		$this->assertStringContainsString( '1 reason was not deleted: a classification still uses it.', $notices[0], 'The screen says why (#2245).' );
	}

	// ------------------------------------------------------------------
	// convert_rows() + sort_rows() — static private helpers
	// ------------------------------------------------------------------

	private function call_static_private( string $method, array $args ) {
		$ref = new \ReflectionMethod( RecruitmentReasonsListTable::class, $method );
		$ref->setAccessible( true );
		return $ref->invokeArgs( null, $args );
	}

	public function test_convert_rows_defaults_color_and_applies_to(): void {
		$rows = array(
			(object) array( 'id' => '1', 'slug' => 's', 'label' => 'L', 'created_at' => '2026-01-01' ),
		);
		$out = $this->call_static_private( 'convert_rows', array( $rows ) );

		$this->assertSame( 1, $out[0]['id'] );
		$this->assertSame( RecruitmentReasonReader::DEFAULT_COLOR, $out[0]['color'] );
		$this->assertSame( '', $out[0]['applies_to'] );
	}

	public function test_sort_rows_falls_back_to_created_at(): void {
		$rows = array(
			array( 'slug' => 'b', 'label' => 'B', 'created_at' => '2026-01-02' ),
			array( 'slug' => 'a', 'label' => 'A', 'created_at' => '2026-01-01' ),
		);
		$out = $this->call_protected( 'sort_rows', array( $rows, 'bogus', 'asc' ) );

		$this->assertSame( array( '2026-01-01', '2026-01-02' ), array_column( $out, 'created_at' ) );
	}
}
