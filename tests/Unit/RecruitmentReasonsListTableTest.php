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

    /** @var \Mockery\MockInterface */
    private $reasonRepoMock;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        Functions\when( '__' )->returnArg();
        Functions\when( 'esc_html__' )->returnArg();
        Functions\when( 'esc_html' )->returnArg();
        Functions\when( 'esc_attr' )->returnArg();
        Functions\when( 'esc_attr__' )->returnArg();

        $this->reasonRepoMock = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentReasonReader' );
        $this->reasonRepoMock->shouldReceive( 'count_references' )->andReturn( 0 )->byDefault();
        $this->reasonRepoMock->shouldReceive( 'decode_applies_to' )->andReturnUsing(
            fn( $stored ) => '' === $stored ? array( 'denied', 'granted', 'appeal_denied', 'appeal_granted' ) : explode( ',', $stored )
        );
        if ( ! defined( 'FreeFormCertificate\Recruitment\RecruitmentReasonReader::DEFAULT_COLOR' ) ) {
            // Add the constant via the alias mock's class definition.
            // (Mockery handles class-constant emulation on aliases.)
        }

        $this->table = new RecruitmentReasonsListTable();
    }

    protected function tearDown(): void {
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
        $this->reasonRepoMock->shouldReceive( 'count_references' )->with( 7 )->andReturn( 5 );

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
}
