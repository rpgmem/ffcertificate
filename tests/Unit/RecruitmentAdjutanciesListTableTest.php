<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Recruitment\RecruitmentAdjutanciesListTable;

/**
 * Smoke tests for the Adjutancies list table.
 *
 * @covers \FreeFormCertificate\Recruitment\RecruitmentAdjutanciesListTable
 * @covers \FreeFormCertificate\Recruitment\AbstractRecruitmentListTable
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class RecruitmentAdjutanciesListTableTest extends TestCase {

    use MockeryPHPUnitIntegration;

    private RecruitmentAdjutanciesListTable $table;

    /** @var \Mockery\MockInterface */
    private $noticeAdjRepoMock;

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

        // Real stub for the admin page (PAGE_SLUG constant — Mockery aliases
        // can't expose class constants).
        if ( ! class_exists( '\FreeFormCertificate\Recruitment\RecruitmentAdminPage', false ) ) {
            eval(
                'namespace FreeFormCertificate\Recruitment;'
                . ' class RecruitmentAdminPage { public const PAGE_SLUG = "ffc-recruitment"; }'
            );
        }
        // Real stub for the reader (DEFAULT_COLOR constant + get_all()).
        if ( ! class_exists( '\FreeFormCertificate\Recruitment\RecruitmentAdjutancyReader', false ) ) {
            eval(
                'namespace FreeFormCertificate\Recruitment;'
                . ' class RecruitmentAdjutancyReader {'
                . ' public const DEFAULT_COLOR = "#cccccc";'
                . ' public static $rows = array();'
                . ' public static function get_all() { return self::$rows; } }'
            );
        }
        \FreeFormCertificate\Recruitment\RecruitmentAdjutancyReader::$rows = array();

        $this->noticeAdjRepoMock = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentNoticeAdjutancyRepository' );
        $this->noticeAdjRepoMock->shouldReceive( 'get_notice_ids_for_adjutancy' )->andReturn( array() )->byDefault();

        $this->table = new RecruitmentAdjutanciesListTable();
    }

    protected function tearDown(): void {
        unset( $_REQUEST['s'], $_REQUEST['orderby'], $_REQUEST['order'], $_REQUEST['action'], $_REQUEST['action2'], $_REQUEST['adjutancy_ids'] );
        Monkey\tearDown();
        parent::tearDown();
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
        $this->noticeAdjRepoMock->shouldReceive( 'get_notice_ids_for_adjutancy' )
            ->with( 5 )
            ->andReturn( array( 1, 2, 3 ) );

        $out = $this->call_protected( 'column_usage', array( array( 'id' => 5 ) ) );

        $this->assertSame( '3', $out );
    }

    public function test_column_usage_returns_zero_when_unused(): void {
        $this->noticeAdjRepoMock->shouldReceive( 'get_notice_ids_for_adjutancy' )->andReturn( array() );

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

        $this->assertStringContainsString( '#cccccc', $out );
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
        \FreeFormCertificate\Recruitment\RecruitmentAdjutancyReader::$rows = array(
            $this->adj_obj( 1, 'alpha', 'Alpha' ),
            $this->adj_obj( 2, 'beta', 'Beta' ),
        );

        $this->table->prepare_items();

        $items = $this->get_items();
        $this->assertCount( 2, $items );
    }

    public function test_prepare_items_applies_search(): void {
        $_REQUEST['s'] = 'beta';
        \FreeFormCertificate\Recruitment\RecruitmentAdjutancyReader::$rows = array(
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
        \FreeFormCertificate\Recruitment\RecruitmentAdjutancyReader::$rows = array(
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
        Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentDeleteService' )
            ->shouldReceive( 'delete_adjutancy' )->never();

        $this->call_protected( 'process_bulk_action' );
        $this->assertTrue( true );
    }

    public function test_process_bulk_action_blocked_without_capability(): void {
        $_REQUEST['action'] = 'bulk-delete';

        Mockery::mock( 'alias:FreeFormCertificate\Core\Capabilities' )
            ->shouldReceive( 'current_user_can_admin_or' )->andReturn( false );
        Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentDeleteService' )
            ->shouldReceive( 'delete_adjutancy' )->never();

        $this->call_protected( 'process_bulk_action' );
        $this->assertTrue( true );
    }

    public function test_process_bulk_action_deletes_when_capable(): void {
        $_REQUEST['action']        = 'bulk-delete';
        $_REQUEST['adjutancy_ids'] = array( '5', '6' );

        Functions\when( 'check_admin_referer' )->justReturn( true );
        Mockery::mock( 'alias:FreeFormCertificate\Core\Capabilities' )
            ->shouldReceive( 'current_user_can_admin_or' )->andReturn( true );

        $deleted = array();
        Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentDeleteService' )
            ->shouldReceive( 'delete_adjutancy' )->andReturnUsing(
                function ( $id ) use ( &$deleted ) {
                    $deleted[] = $id;
                    return true;
                }
            );

        $this->call_protected( 'process_bulk_action' );

        $this->assertSame( array( 5, 6 ), $deleted );
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
        $this->assertSame( '#cccccc', $out[0]['color'] );
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
