<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Recruitment\RecruitmentNoticesListTable;

/**
 * Smoke tests for the Notices list table.
 *
 * RecruitmentNoticeReader, RecruitmentNoticeWriter,
 * RecruitmentNoticeAdjutancyRepository and RecruitmentAdminPage are the real
 * ones, over a `$wpdb` double that answers the notice and notice-adjutancy
 * tables and records every delete, so the table runs in the shared process.
 *
 * @covers \FreeFormCertificate\Recruitment\RecruitmentNoticesListTable
 * @covers \FreeFormCertificate\Recruitment\AbstractRecruitmentListTable
 */
class RecruitmentNoticesListTableTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private RecruitmentNoticesListTable $table;

	/** @var bool Whether `$wpdb` was set before this test. */
	private bool $had_wpdb = false;

	/** @var mixed The `$wpdb` global an earlier test left, restored afterwards. */
	private $wpdb_backup;

	/** @var string SQL of the last prepare(). */
	private string $sql = '';

	/** @var array<int, mixed> Arguments of the last prepare(). */
	private array $bound = array();

	/** @var list<object> Rows the notice table holds. */
	private array $notices = array();

	/** @var array<int, list<int>> Adjutancy ids attached to each notice id. */
	private array $notice_adjutancies = array();

	/**
	 * Every `$wpdb->delete()`, as [table, where].
	 *
	 * @var list<array{0: string, 1: array<string, mixed>}>
	 */
	private array $deletes = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		// Preload the extracted base so pcov attributes its coverage.
		class_exists( '\FreeFormCertificate\Recruitment\AbstractRecruitmentListTable' );

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
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( 'check_admin_referer' )->justReturn( true );
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'wp_cache_delete' )->justReturn( true );

		$this->sql                = '';
		$this->bound              = array();
		$this->notices            = array();
		$this->notice_adjutancies = array();
		$this->deletes            = array();

		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			function ( $sql, ...$args ) {
				$this->sql   = (string) $sql;
				$this->bound = $args;
				return (string) $sql;
			}
		);
		// RecruitmentNoticeReader::get_all() with no status filter.
		$wpdb->shouldReceive( 'get_results' )->andReturnUsing(
			function () {
				$this->assertSame( 'SELECT * FROM %i ORDER BY created_at DESC', $this->sql, 'Unexpected get_results query.' );
				$this->assertSame( 'wp_ffc_recruitment_notice', $this->bound[0] );
				return $this->notices;
			}
		);
		// RecruitmentNoticeAdjutancyRepository::get_adjutancy_ids_for_notice().
		$wpdb->shouldReceive( 'get_col' )->andReturnUsing(
			function () {
				$this->assertSame( 'SELECT adjutancy_id FROM %i WHERE notice_id = %d', $this->sql, 'Unexpected get_col query.' );
				$this->assertSame( 'wp_ffc_recruitment_notice_adjutancy', $this->bound[0] );
				return array_map( 'strval', $this->notice_adjutancies[ (int) $this->bound[1] ] ?? array() );
			}
		);
		// RecruitmentNoticeWriter::delete().
		$wpdb->shouldReceive( 'delete' )->andReturnUsing(
			function ( $table, $where ) {
				$this->deletes[] = array( $table, $where );
				return 1;
			}
		);

		$this->had_wpdb    = array_key_exists( 'wpdb', $GLOBALS );
		$this->wpdb_backup = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb']   = $wpdb;

		$this->table = new RecruitmentNoticesListTable();
	}

	protected function tearDown(): void {
		unset( $_REQUEST['s'], $_REQUEST['orderby'], $_REQUEST['order'], $_REQUEST['action'], $_REQUEST['action2'], $_REQUEST['notice_ids'] );
		if ( $this->had_wpdb ) {
			$GLOBALS['wpdb'] = $this->wpdb_backup;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Ids the real writer asked the database to delete, in order.
	 *
	 * @return list<int>
	 */
	private function deleted_ids(): array {
		$ids = array();
		foreach ( $this->deletes as $delete ) {
			$this->assertSame( 'wp_ffc_recruitment_notice', $delete[0] );
			$ids[] = (int) $delete[1]['id'];
		}
		return $ids;
	}

	private function call_protected( string $method, array $args = array() ) {
		$ref = new \ReflectionMethod( $this->table, $method );
		$ref->setAccessible( true );
		return $ref->invokeArgs( $this->table, $args );
	}

	public function test_get_columns_declares_full_notice_column_set(): void {
		$cols = $this->table->get_columns();

		$this->assertSame(
			array( 'cb', 'code', 'name', 'status', 'reopened', 'adjutancies', 'created_at' ),
			array_keys( $cols )
		);
	}

	public function test_get_sortable_columns_covers_code_name_status_created_at(): void {
		$sortable = $this->call_protected( 'get_sortable_columns' );

		$this->assertSame(
			array( 'code', 'name', 'status', 'created_at' ),
			array_keys( $sortable )
		);
		$this->assertTrue( $sortable['created_at'][1] );
	}

	public function test_get_bulk_actions_returns_only_delete(): void {
		$actions = $this->call_protected( 'get_bulk_actions' );
		$this->assertSame( array( 'bulk-delete' ), array_keys( $actions ) );
	}

	public function test_column_cb_emits_checkbox_named_notice_ids(): void {
		$html = $this->call_protected( 'column_cb', array( array( 'id' => 12 ) ) );

		$this->assertStringContainsString( 'name="notice_ids[]"', $html );
		$this->assertStringContainsString( 'value="12"', $html );
	}

	public function test_column_status_delegates_to_admin_page_badge_helper(): void {
		$out = $this->call_protected( 'column_status', array( array( 'status' => 'definitive' ) ) );

		$this->assertSame( \FreeFormCertificate\Recruitment\RecruitmentAdminPage::notice_status_badge( 'definitive' ), $out );
		$this->assertStringContainsString( 'ffc-recruitment-status-definitive', $out );
		$this->assertStringContainsString( '>Definitive<', $out );
	}

	public function test_column_reopened_yes_when_was_reopened_flag_set(): void {
		$out = $this->call_protected( 'column_reopened', array( array( 'was_reopened' => '1' ) ) );

		$this->assertSame( 'Yes', $out );
	}

	public function test_column_reopened_em_dash_when_not_reopened(): void {
		$out = $this->call_protected( 'column_reopened', array( array( 'was_reopened' => '0' ) ) );

		$this->assertSame( '—', $out );
	}

	public function test_column_adjutancies_renders_none_pill_when_empty(): void {
		$this->notice_adjutancies = array( 2 => array( 8 ) );

		$out = $this->call_protected( 'column_adjutancies', array( array( 'id' => 1 ) ) );

		$this->assertStringContainsString( '(none)', $out );
		$this->assertStringContainsString( '<em>', $out );
	}

	public function test_column_adjutancies_renders_count_when_attached(): void {
		$this->notice_adjutancies = array( 1 => array( 1, 2, 3, 4 ) );

		$out = $this->call_protected( 'column_adjutancies', array( array( 'id' => 1 ) ) );

		$this->assertSame( '4', $out );
	}

	public function test_column_default_returns_value_for_known_column(): void {
		$out = $this->call_protected( 'column_default', array( array( 'name' => 'Concurso 2026' ), 'name' ) );
		$this->assertSame( 'Concurso 2026', $out );
	}

	public function test_column_default_returns_empty_string_for_missing_column(): void {
		$out = $this->call_protected( 'column_default', array( array(), 'missing' ) );
		$this->assertSame( '', $out );
	}

	public function test_column_created_at_echoes_raw_value(): void {
		$out = $this->call_protected( 'column_created_at', array( array( 'created_at' => '2026-05-01 10:00:00' ) ) );
		$this->assertSame( '2026-05-01 10:00:00', $out );
	}

	// ------------------------------------------------------------------
	// convert_rows() — stdClass → array shape (static private)
	// ------------------------------------------------------------------

	private function call_static_private( string $method, array $args ) {
		$ref = new \ReflectionMethod( RecruitmentNoticesListTable::class, $method );
		$ref->setAccessible( true );
		return $ref->invokeArgs( null, $args );
	}

	public function test_convert_rows_maps_object_fields_into_array_shape(): void {
		$rows = array(
			(object) array(
				'id'           => '7',
				'code'         => 'EDITAL-7',
				'name'         => 'Concurso',
				'status'       => 'draft',
				'was_reopened' => '1',
				'created_at'   => '2026-05-01 10:00:00',
			),
		);

		$out = $this->call_static_private( 'convert_rows', array( $rows ) );

		$this->assertSame( 7, $out[0]['id'] );
		$this->assertSame( 'EDITAL-7', $out[0]['code'] );
		$this->assertSame( 'Concurso', $out[0]['name'] );
		$this->assertSame( 'draft', $out[0]['status'] );
		$this->assertSame( '1', $out[0]['was_reopened'] );
		$this->assertSame( '2026-05-01 10:00:00', $out[0]['created_at'] );
	}

	public function test_convert_rows_returns_empty_for_empty_input(): void {
		$this->assertSame( array(), $this->call_static_private( 'convert_rows', array( array() ) ) );
	}

	// ------------------------------------------------------------------
	// sort_rows() — in-memory natural-case sort (static private)
	// ------------------------------------------------------------------

	private function rows_for_sort(): array {
		return array(
			array( 'code' => 'B', 'name' => 'beta', 'status' => 'draft', 'created_at' => '2026-01-02' ),
			array( 'code' => 'A', 'name' => 'alpha', 'status' => 'closed', 'created_at' => '2026-01-01' ),
			array( 'code' => 'C', 'name' => 'gamma', 'status' => 'definitive', 'created_at' => '2026-01-03' ),
		);
	}

	public function test_sort_rows_ascending_by_code(): void {
		$out = $this->call_protected( 'sort_rows', array( $this->rows_for_sort(), 'code', 'asc' ) );
		$this->assertSame( array( 'A', 'B', 'C' ), array_column( $out, 'code' ) );
	}

	public function test_sort_rows_descending_by_code(): void {
		$out = $this->call_protected( 'sort_rows', array( $this->rows_for_sort(), 'code', 'desc' ) );
		$this->assertSame( array( 'C', 'B', 'A' ), array_column( $out, 'code' ) );
	}

	public function test_sort_rows_falls_back_to_created_at_for_disallowed_orderby(): void {
		// 'status' IS allowed; 'id' is NOT → falls back to created_at asc.
		$out = $this->call_protected( 'sort_rows', array( $this->rows_for_sort(), 'id', 'asc' ) );
		$this->assertSame(
			array( '2026-01-01', '2026-01-02', '2026-01-03' ),
			array_column( $out, 'created_at' )
		);
	}

	public function test_sort_rows_allows_status_orderby(): void {
		$out = $this->call_protected( 'sort_rows', array( $this->rows_for_sort(), 'status', 'asc' ) );
		$this->assertSame(
			array( 'closed', 'definitive', 'draft' ),
			array_column( $out, 'status' )
		);
	}

	// ------------------------------------------------------------------
	// column_code() — slug column with row actions (Edit / Delete)
	// ------------------------------------------------------------------

	public function test_column_code_renders_edit_and_delete_row_actions(): void {
		$out = $this->call_protected( 'column_code', array( array( 'id' => 5, 'code' => 'EDITAL-5' ) ) );

		$this->assertStringContainsString( 'EDITAL-5', $out );
		$this->assertStringContainsString( 'action=edit-notice', $out );
		$this->assertStringContainsString( 'action=delete-notice', $out );
		$this->assertStringContainsString( 'submitdelete', $out );
		$this->assertStringContainsString( 'row-actions', $out );
	}

	// ------------------------------------------------------------------
	// prepare_items() — full dataset → search → sort → paginate
	// ------------------------------------------------------------------

	private function seed_reader( array $rows ): void {
		$this->notices = $rows;
	}

	private function notice_obj( int $id, string $code, string $name, string $status = 'draft' ): object {
		return (object) array(
			'id'           => $id,
			'code'         => $code,
			'name'         => $name,
			'status'       => $status,
			'was_reopened' => '0',
			'created_at'   => '2026-01-0' . $id . ' 09:00:00',
		);
	}

	public function test_prepare_items_populates_items_and_pagination(): void {
		$this->seed_reader(
			array(
				$this->notice_obj( 1, 'A', 'Alpha' ),
				$this->notice_obj( 2, 'B', 'Beta' ),
			)
		);

		$this->table->prepare_items();

		$items = $this->call_protected_get_items();
		$this->assertCount( 2, $items );
		// Default sort is created_at DESC → notice id 2 (later created_at) first.
		$this->assertSame( 'B', $items[0]['code'] );
	}

	public function test_prepare_items_applies_search_filter(): void {
		$_REQUEST['s'] = 'beta';
		$this->seed_reader(
			array(
				$this->notice_obj( 1, 'A', 'Alpha' ),
				$this->notice_obj( 2, 'B', 'Beta' ),
			)
		);

		$this->table->prepare_items();

		$items = $this->call_protected_get_items();
		$this->assertCount( 1, $items );
		$this->assertSame( 'Beta', $items[0]['name'] );
	}

	public function test_prepare_items_applies_sort_order(): void {
		$_REQUEST['orderby'] = 'code';
		$_REQUEST['order']   = 'asc';
		$this->seed_reader(
			array(
				$this->notice_obj( 2, 'B', 'Beta' ),
				$this->notice_obj( 1, 'A', 'Alpha' ),
			)
		);

		$this->table->prepare_items();

		$items = $this->call_protected_get_items();
		$this->assertSame( array( 'A', 'B' ), array_column( $items, 'code' ) );
	}

	private function call_protected_get_items(): array {
		$ref = new \ReflectionProperty( $this->table, 'items' );
		$ref->setAccessible( true );
		return (array) $ref->getValue( $this->table );
	}

	// ------------------------------------------------------------------
	// process_bulk_action()
	// ------------------------------------------------------------------

	public function test_process_bulk_action_no_op_without_bulk_delete(): void {
		// current_action() returns false → method returns early; nothing is deleted.
		Functions\when( 'current_user_can' )->justReturn( true );
		$_REQUEST['notice_ids'] = array( '3', '4' );

		$this->call_protected( 'process_bulk_action' );

		$this->assertSame( array(), $this->deletes );
	}

	public function test_process_bulk_action_deletes_selected_ids(): void {
		$_REQUEST['action']     = 'bulk-delete';
		$_REQUEST['notice_ids'] = array( '3', '4' );

		// Bulk delete now requires the destructive cap (matches Adjutancies/Reasons).
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'check_admin_referer' )->justReturn( true );

		$this->call_protected( 'process_bulk_action' );

		$this->assertSame( array( 3, 4 ), $this->deleted_ids() );
	}

	public function test_process_bulk_action_denied_without_delete_cap(): void {
		// #739 escape closed: nonce alone no longer authorizes bulk delete —
		// `ffc_delete_recruitment` (or manage_options) is required.
		$_REQUEST['action']     = 'bulk-delete';
		$_REQUEST['notice_ids'] = array( '3', '4' );

		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( 'check_admin_referer' )->justReturn( true );

		$this->call_protected( 'process_bulk_action' );

		$this->assertSame( array(), $this->deletes );
	}
}
