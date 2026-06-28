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
 * @covers \FreeFormCertificate\Recruitment\RecruitmentNoticesListTable
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class RecruitmentNoticesListTableTest extends TestCase {

    use MockeryPHPUnitIntegration;

    private RecruitmentNoticesListTable $table;

    /** @var \Mockery\MockInterface */
    private $noticeAdjRepoMock;

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

        $this->noticeAdjRepoMock = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentNoticeAdjutancyRepository' );
        $this->noticeAdjRepoMock->shouldReceive( 'get_adjutancy_ids_for_notice' )->andReturn( array() )->byDefault();

        // RecruitmentAdminPage carries both a PAGE_SLUG class constant and the
        // notice_status_badge() static helper. Mockery aliases can't expose
        // class constants, so define a lightweight real stub class instead
        // (process isolation means it can't collide with the real autoload).
        if ( ! class_exists( '\FreeFormCertificate\Recruitment\RecruitmentAdminPage', false ) ) {
            eval(
                'namespace FreeFormCertificate\Recruitment;'
                . ' class RecruitmentAdminPage {'
                . ' public const PAGE_SLUG = "ffc-recruitment";'
                . ' public static function notice_status_badge( $status ) {'
                . ' return "<span class=\"ffc-status ffc-status-{$status}\">{$status}</span>"; } }'
            );
        }

        $this->table = new RecruitmentNoticesListTable();
    }

    protected function tearDown(): void {
        unset( $_REQUEST['s'], $_REQUEST['orderby'], $_REQUEST['order'], $_REQUEST['action'], $_REQUEST['action2'], $_REQUEST['notice_ids'] );
        Monkey\tearDown();
        parent::tearDown();
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
        $out = $this->call_protected( 'column_status', array( array( 'status' => 'open' ) ) );

        $this->assertStringContainsString( 'ffc-status-open', $out );
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
        $this->noticeAdjRepoMock->shouldReceive( 'get_adjutancy_ids_for_notice' )->andReturn( array() );

        $out = $this->call_protected( 'column_adjutancies', array( array( 'id' => 1 ) ) );

        $this->assertStringContainsString( '(none)', $out );
        $this->assertStringContainsString( '<em>', $out );
    }

    public function test_column_adjutancies_renders_count_when_attached(): void {
        $this->noticeAdjRepoMock->shouldReceive( 'get_adjutancy_ids_for_notice' )->andReturn( array( 1, 2, 3, 4 ) );

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
        $out = $this->call_static_private( 'sort_rows', array( $this->rows_for_sort(), 'code', 'asc' ) );
        $this->assertSame( array( 'A', 'B', 'C' ), array_column( $out, 'code' ) );
    }

    public function test_sort_rows_descending_by_code(): void {
        $out = $this->call_static_private( 'sort_rows', array( $this->rows_for_sort(), 'code', 'desc' ) );
        $this->assertSame( array( 'C', 'B', 'A' ), array_column( $out, 'code' ) );
    }

    public function test_sort_rows_falls_back_to_created_at_for_disallowed_orderby(): void {
        // 'status' IS allowed; 'id' is NOT → falls back to created_at asc.
        $out = $this->call_static_private( 'sort_rows', array( $this->rows_for_sort(), 'id', 'asc' ) );
        $this->assertSame(
            array( '2026-01-01', '2026-01-02', '2026-01-03' ),
            array_column( $out, 'created_at' )
        );
    }

    public function test_sort_rows_allows_status_orderby(): void {
        $out = $this->call_static_private( 'sort_rows', array( $this->rows_for_sort(), 'status', 'asc' ) );
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
        Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentNoticeReader' )
            ->shouldReceive( 'get_all' )->andReturn( $rows );
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
        // current_action() returns false → method returns early; no writer call needed.
        Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentNoticeWriter' )
            ->shouldReceive( 'delete' )->never();

        $this->call_protected( 'process_bulk_action' );
        $this->assertTrue( true );
    }

    public function test_process_bulk_action_deletes_selected_ids(): void {
        $_REQUEST['action']     = 'bulk-delete';
        $_REQUEST['notice_ids'] = array( '3', '4' );

        Functions\when( 'check_admin_referer' )->justReturn( true );

        $deleted = array();
        Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentNoticeWriter' )
            ->shouldReceive( 'delete' )->andReturnUsing(
                function ( $id ) use ( &$deleted ) {
                    $deleted[] = $id;
                    return true;
                }
            );

        $this->call_protected( 'process_bulk_action' );

        $this->assertSame( array( 3, 4 ), $deleted );
    }
}
