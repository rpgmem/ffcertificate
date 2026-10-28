<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Recruitment\RecruitmentAdjutanciesListTable;
use FreeFormCertificate\Recruitment\RecruitmentAdjutancyReader;
use FreeFormCertificate\Tests\Support\CallLog;

/**
 * Smoke tests for the Adjutancies list table.
 *
 * The collaborators are the real ones — RecruitmentAdjutancyReader,
 * RecruitmentNoticeAdjutancyRepository, RecruitmentDeleteService (with the
 * real classification repository, writer and activity logger behind it) and
 * Capabilities — over a `$wpdb` double that answers each query by its SQL
 * text and bound id and records every delete. An unexpected query throws,
 * which fails the test.
 *
 * @covers \FreeFormCertificate\Recruitment\RecruitmentAdjutanciesListTable
 * @covers \FreeFormCertificate\Recruitment\AbstractRecruitmentListTable
 */
class RecruitmentAdjutanciesListTableTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private RecruitmentAdjutanciesListTable $table;

	/** @var bool Whether `$wpdb` was set before this test. */
	private bool $had_wpdb = false;

	/** @var mixed The `$wpdb` global an earlier test left, restored afterwards. */
	private $wpdb_backup;

	/** @var list<object> Rows the adjutancy table holds. */
	private array $rows = array();

	/** @var array<int, list<int>> Notice ids attached to each adjutancy id. */
	private array $notice_links = array();

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
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		// An empty `ffc_settings` leaves the activity log off, so the delete
		// path's logger returns before touching the database.
		Functions\when( 'get_option' )->alias( static fn( $key, $default = false ) => 'ffc_settings' === $key ? array() : $default );
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( 'check_admin_referer' )->justReturn( true );

		$this->rows         = array();
		$this->notice_links = array();
		$this->prepared     = array();
		$this->queries      = array();
		$this->deletes      = array();

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
		$wpdb->shouldReceive( 'get_row' )->andReturnUsing( fn( $query ) => $this->answer( 'get_row', $query ) );
		$wpdb->shouldReceive( 'get_col' )->andReturnUsing( fn( $query ) => $this->answer( 'get_col', $query ) );
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

		$this->table = new RecruitmentAdjutanciesListTable();
	}

	protected function tearDown(): void {
		unset( $_REQUEST['s'], $_REQUEST['orderby'], $_REQUEST['order'], $_REQUEST['action'], $_REQUEST['action2'], $_REQUEST['adjutancy_ids'] );
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

		if ( 'get_results' === $method && 'SELECT * FROM %i ORDER BY name ASC' === $sql && 'wp_ffc_recruitment_adjutancy' === $table ) {
			return $this->rows;
		}
		if ( 'get_row' === $method && 'SELECT * FROM %i WHERE id = %d' === $sql && 'wp_ffc_recruitment_adjutancy' === $table ) {
			foreach ( $this->rows as $row ) {
				if ( (int) $row->id === $id ) {
					return $row;
				}
			}
			return null;
		}
		if ( 'get_col' === $method && 'SELECT notice_id FROM %i WHERE adjutancy_id = %d' === $sql && 'wp_ffc_recruitment_notice_adjutancy' === $table ) {
			return array_map( 'strval', $this->notice_links[ $id ] ?? array() );
		}
		if ( 'get_var' === $method && 'SELECT COUNT(*) FROM %i WHERE adjutancy_id = %d' === $sql && 'wp_ffc_recruitment_classification' === $table ) {
			return '0';
		}
		throw new \RuntimeException( "Unexpected {$method}: {$sql} " . json_encode( $args ) );
	}

	/**
	 * The ids the double deleted from the adjutancy table, in order.
	 *
	 * @return list<int>
	 */
	private function deleted_adjutancy_ids(): array {
		$ids = array();
		foreach ( $this->deletes as $delete ) {
			$this->assertSame( 'wp_ffc_recruitment_adjutancy', $delete[0] );
			$ids[] = (int) $delete[1]['id'];
		}
		return $ids;
	}

	private function call_protected( string $method, array $args = array() ) {
		$ref = new \ReflectionMethod( $this->table, $method );
		$ref->setAccessible( true );
		return $ref->invokeArgs( $this->table, $args );
	}

	public function test_get_columns_declares_expected_keys(): void {
		$cols = $this->table->get_columns();

		$this->assertSame(
			array( 'cb', 'slug', 'name', 'color', 'usage', 'created_at' ),
			array_keys( $cols )
		);
	}

	public function test_get_sortable_columns_includes_slug_name_created_at(): void {
		$sortable = $this->call_protected( 'get_sortable_columns' );

		$this->assertArrayHasKey( 'slug', $sortable );
		$this->assertArrayHasKey( 'name', $sortable );
		$this->assertArrayHasKey( 'created_at', $sortable );
		// created_at defaults to DESC.
		$this->assertTrue( $sortable['created_at'][1] );
	}

	public function test_get_bulk_actions_returns_only_delete(): void {
		$actions = $this->call_protected( 'get_bulk_actions' );
		$this->assertSame( array( 'bulk-delete' ), array_keys( $actions ) );
	}

	public function test_column_cb_emits_checkbox_named_adjutancy_ids(): void {
		$html = $this->call_protected( 'column_cb', array( array( 'id' => 99 ) ) );

		$this->assertStringContainsString( 'name="adjutancy_ids[]"', $html );
		$this->assertStringContainsString( 'value="99"', $html );
	}

	public function test_column_default_returns_escaped_value_for_known_column(): void {
		$out = $this->call_protected( 'column_default', array( array( 'name' => 'Adjutant Alpha' ), 'name' ) );
		$this->assertSame( 'Adjutant Alpha', $out );
	}

	public function test_column_default_returns_empty_string_for_missing_column(): void {
		$out = $this->call_protected( 'column_default', array( array(), 'missing' ) );
		$this->assertSame( '', $out );
	}

	public function test_column_usage_returns_count_of_referenced_notices(): void {
		$this->notice_links = array(
			5 => array( 1, 2, 3 ),
			6 => array( 4 ),
		);

		$out = $this->call_protected( 'column_usage', array( array( 'id' => 5 ) ) );

		$this->assertSame( '3', $out );
	}

	public function test_column_usage_returns_zero_when_unused(): void {
		$this->notice_links = array( 6 => array( 4 ) );

		$out = $this->call_protected( 'column_usage', array( array( 'id' => 5 ) ) );

		$this->assertSame( '0', $out );
	}

	public function test_column_created_at_returns_timestamp_string(): void {
		$out = $this->call_protected( 'column_created_at', array( array( 'created_at' => '2026-01-01 12:00:00' ) ) );
		$this->assertSame( '2026-01-01 12:00:00', $out );
	}

	// ------------------------------------------------------------------
	// column_slug() — row actions
	// ------------------------------------------------------------------

	public function test_column_slug_renders_edit_delete_row_actions(): void {
		$out = $this->call_protected( 'column_slug', array( array( 'id' => 8, 'slug' => 'alpha-adj' ) ) );

		$this->assertStringContainsString( 'alpha-adj', $out );
		$this->assertStringContainsString( 'action=edit-adjutancy', $out );
		$this->assertStringContainsString( 'action=delete-adjutancy', $out );
		$this->assertStringContainsString( 'submitdelete', $out );
	}

	// ------------------------------------------------------------------
	// column_color() — inline picker
	// ------------------------------------------------------------------

	public function test_column_color_renders_picker_with_value(): void {
		$out = $this->call_protected( 'column_color', array( array( 'id' => 3, 'color' => '#ff0000' ) ) );

		$this->assertStringContainsString( 'type="color"', $out );
		$this->assertStringContainsString( '#ff0000', $out );
		$this->assertStringContainsString( 'data-ffc-color-endpoint="adjutancies"', $out );
	}

	public function test_column_color_falls_back_to_default_color(): void {
		$out = $this->call_protected( 'column_color', array( array( 'id' => 3 ) ) );

		$this->assertStringContainsString( RecruitmentAdjutancyReader::DEFAULT_COLOR, $out );
	}

	// ------------------------------------------------------------------
	// prepare_items() — convert → search → sort → paginate
	// ------------------------------------------------------------------

	private function adj_obj( int $id, string $slug, string $name ): object {
		return (object) array(
			'id'         => $id,
			'slug'       => $slug,
			'name'       => $name,
			'color'      => '#abcdef',
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
			$this->adj_obj( 1, 'alpha', 'Alpha' ),
			$this->adj_obj( 2, 'beta', 'Beta' ),
		);

		$this->table->prepare_items();

		$items = $this->get_items();
		$this->assertCount( 2, $items );
		$this->assertSame( array( 'SELECT * FROM %i ORDER BY name ASC' ), $this->queries );
	}

	public function test_prepare_items_applies_search(): void {
		$_REQUEST['s'] = 'beta';
		$this->rows    = array(
			$this->adj_obj( 1, 'alpha', 'Alpha' ),
			$this->adj_obj( 2, 'beta', 'Beta' ),
		);

		$this->table->prepare_items();

		$items = $this->get_items();
		$this->assertCount( 1, $items );
		$this->assertSame( 'beta', $items[0]['slug'] );
	}

	public function test_prepare_items_applies_sort_asc_by_slug(): void {
		$_REQUEST['orderby'] = 'slug';
		$_REQUEST['order']   = 'asc';
		$this->rows          = array(
			$this->adj_obj( 2, 'beta', 'Beta' ),
			$this->adj_obj( 1, 'alpha', 'Alpha' ),
		);

		$this->table->prepare_items();

		$items = $this->get_items();
		$this->assertSame( array( 'alpha', 'beta' ), array_column( $items, 'slug' ) );
	}

	// ------------------------------------------------------------------
	// process_bulk_action()
	// ------------------------------------------------------------------

	public function test_process_bulk_action_returns_early_without_action(): void {
		$this->rows = array( $this->adj_obj( 5, 'alpha', 'Alpha' ) );
		$caps       = CallLog::record( 'current_user_can', true );

		$this->call_protected( 'process_bulk_action' );

		$this->assertSame( 0, $caps->count(), 'No action must not even consult the capability.' );
		$this->assertSame( array(), $this->queries );
		$this->assertSame( array(), $this->deletes );
	}

	public function test_process_bulk_action_blocked_without_capability(): void {
		$_REQUEST['action']        = 'bulk-delete';
		$_REQUEST['adjutancy_ids'] = array( '5' );
		$this->rows                = array( $this->adj_obj( 5, 'alpha', 'Alpha' ) );

		$caps    = CallLog::record( 'current_user_can', false );
		$referer = CallLog::record( 'check_admin_referer', true );

		$this->call_protected( 'process_bulk_action' );

		$this->assertSame( 2, $caps->count(), 'Both manage_options and the delete cap are refused.' );
		$this->assertSame( 0, $referer->count() );
		$this->assertSame( array(), $this->queries );
		$this->assertSame( array(), $this->deletes );
	}

	public function test_process_bulk_action_deletes_when_capable(): void {
		$_REQUEST['action']        = 'bulk-delete';
		$_REQUEST['adjutancy_ids'] = array( '5', '6' );

		$this->rows                = array(
			$this->adj_obj( 5, 'alpha', 'Alpha' ),
			$this->adj_obj( 6, 'beta', 'Beta' ),
		);
		Functions\when( 'current_user_can' )->alias( static fn( $cap ) => 'ffc_delete_recruitment' === $cap );

		$this->call_protected( 'process_bulk_action' );

		$this->assertSame( array( 5, 6 ), $this->deleted_adjutancy_ids() );
	}

	// ------------------------------------------------------------------
	// convert_rows() + sort_rows() — static private helpers
	// ------------------------------------------------------------------

	private function call_static_private( string $method, array $args ) {
		$ref = new \ReflectionMethod( RecruitmentAdjutanciesListTable::class, $method );
		$ref->setAccessible( true );
		return $ref->invokeArgs( null, $args );
	}

	public function test_convert_rows_uses_default_color_when_missing(): void {
		$rows = array(
			(object) array( 'id' => '1', 'slug' => 's', 'name' => 'N', 'created_at' => '2026-01-01' ),
		);
		$out = $this->call_static_private( 'convert_rows', array( $rows ) );

		$this->assertSame( 1, $out[0]['id'] );
		$this->assertSame( RecruitmentAdjutancyReader::DEFAULT_COLOR, $out[0]['color'] );
	}

	public function test_sort_rows_falls_back_to_created_at_for_bad_orderby(): void {
		$rows = array(
			array( 'slug' => 'b', 'name' => 'B', 'created_at' => '2026-01-02' ),
			array( 'slug' => 'a', 'name' => 'A', 'created_at' => '2026-01-01' ),
		);
		$out = $this->call_protected( 'sort_rows', array( $rows, 'bogus', 'asc' ) );

		$this->assertSame( array( '2026-01-01', '2026-01-02' ), array_column( $out, 'created_at' ) );
	}
}
