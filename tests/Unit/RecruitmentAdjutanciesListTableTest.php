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

        Functions\when( '__' )->returnArg();
        Functions\when( 'esc_html__' )->returnArg();
        Functions\when( 'esc_html' )->returnArg();
        Functions\when( 'esc_attr' )->returnArg();

        $this->noticeAdjRepoMock = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentNoticeAdjutancyRepository' );
        $this->noticeAdjRepoMock->shouldReceive( 'get_notice_ids_for_adjutancy' )->andReturn( array() )->byDefault();

        $this->table = new RecruitmentAdjutanciesListTable();
    }

    protected function tearDown(): void {
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
}
