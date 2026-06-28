<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Recruitment\RecruitmentReasonsListTable;

/**
 * Smoke tests for the Reasons list table: column declarations, sortable +
 * bulk-action contracts, and the deterministic column renderers
 * (column_cb, column_default, column_applies_to "all" branch). Heavy
 * methods (column_slug with row_actions, prepare_items with DB) require
 * the full WordPress admin runtime and are out of scope.
 *
 * @covers \FreeFormCertificate\Recruitment\RecruitmentReasonsListTable
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class RecruitmentReasonsListTableTest extends TestCase {

    use MockeryPHPUnitIntegration;

    private RecruitmentReasonsListTable $table;

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

        // Real stub for the admin page (PAGE_SLUG constant).
        if ( ! class_exists( '\FreeFormCertificate\Recruitment\RecruitmentAdminPage', false ) ) {
            eval(
                'namespace FreeFormCertificate\Recruitment;'
                . ' class RecruitmentAdminPage { public const PAGE_SLUG = "ffc-recruitment"; }'
            );
        }
        // Real stub for the reader: DEFAULT_COLOR constant + get_all(), with
        // count_references / decode_applies_to driven by static closures the
        // individual tests can override.
        if ( ! class_exists( '\FreeFormCertificate\Recruitment\RecruitmentReasonReader', false ) ) {
            eval(
                'namespace FreeFormCertificate\Recruitment;'
                . ' class RecruitmentReasonReader {'
                . ' public const DEFAULT_COLOR = "#cccccc";'
                . ' public static $rows = array();'
                . ' public static $references = array();'
                . ' public static function get_all() { return self::$rows; }'
                . ' public static function count_references( $id ) { return self::$references[ $id ] ?? 0; }'
                . ' public static function decode_applies_to( $stored ) {'
                . ' return "" === $stored'
                . ' ? array( "denied", "granted", "appeal_denied", "appeal_granted" )'
                . ' : explode( ",", $stored ); } }'
            );
        }
        \FreeFormCertificate\Recruitment\RecruitmentReasonReader::$rows       = array();
        \FreeFormCertificate\Recruitment\RecruitmentReasonReader::$references = array();

        $this->table = new RecruitmentReasonsListTable();
    }

    protected function tearDown(): void {
        unset( $_REQUEST['s'], $_REQUEST['orderby'], $_REQUEST['order'], $_REQUEST['action'], $_REQUEST['action2'], $_REQUEST['reason_ids'] );
        Monkey\tearDown();
        parent::tearDown();
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
        \FreeFormCertificate\Recruitment\RecruitmentReasonReader::$references = array( 7 => 5 );

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
        $this->assertStringContainsString( '#cccccc', $out );
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
        \FreeFormCertificate\Recruitment\RecruitmentReasonReader::$rows = array(
            $this->reason_obj( 1, 'alpha', 'Alpha' ),
            $this->reason_obj( 2, 'beta', 'Beta' ),
        );

        $this->table->prepare_items();

        $this->assertCount( 2, $this->get_items() );
    }

    public function test_prepare_items_applies_search_and_sort(): void {
        $_REQUEST['orderby'] = 'slug';
        $_REQUEST['order']   = 'asc';
        \FreeFormCertificate\Recruitment\RecruitmentReasonReader::$rows = array(
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
        Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentReasonWriter' )
            ->shouldReceive( 'delete' )->never();

        $this->call_protected( 'process_bulk_action' );
        $this->assertTrue( true );
    }

    public function test_process_bulk_action_blocked_without_manage_cap(): void {
        $_REQUEST['action'] = 'bulk-delete';

        Mockery::mock( 'alias:FreeFormCertificate\Core\Capabilities' )
            ->shouldReceive( 'current_user_can_admin_or' )->andReturn( false );
        Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentReasonWriter' )
            ->shouldReceive( 'delete' )->never();

        $this->call_protected( 'process_bulk_action' );
        $this->assertTrue( true );
    }

    public function test_process_bulk_action_skips_referenced_reasons(): void {
        $_REQUEST['action']     = 'bulk-delete';
        $_REQUEST['reason_ids'] = array( '5', '6' );

        Functions\when( 'check_admin_referer' )->justReturn( true );
        Mockery::mock( 'alias:FreeFormCertificate\Core\Capabilities' )
            ->shouldReceive( 'current_user_can_admin_or' )->andReturn( true );

        // Reason 5 has references → skipped; reason 6 is free → deleted.
        \FreeFormCertificate\Recruitment\RecruitmentReasonReader::$references = array( 5 => 2, 6 => 0 );

        $deleted = array();
        Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentReasonWriter' )
            ->shouldReceive( 'delete' )->andReturnUsing(
                function ( $id ) use ( &$deleted ) {
                    $deleted[] = $id;
                    return true;
                }
            );

        $this->call_protected( 'process_bulk_action' );

        $this->assertSame( array( 6 ), $deleted );
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
        $this->assertSame( '#cccccc', $out[0]['color'] );
        $this->assertSame( '', $out[0]['applies_to'] );
    }

    public function test_sort_rows_falls_back_to_created_at(): void {
        $rows = array(
            array( 'slug' => 'b', 'label' => 'B', 'created_at' => '2026-01-02' ),
            array( 'slug' => 'a', 'label' => 'A', 'created_at' => '2026-01-01' ),
        );
        $out = $this->call_static_private( 'sort_rows', array( $rows, 'bogus', 'asc' ) );

        $this->assertSame( array( '2026-01-01', '2026-01-02' ), array_column( $out, 'created_at' ) );
    }
}
