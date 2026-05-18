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

    /** @var \Mockery\MockInterface */
    private $adminPageMock;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        Functions\when( '__' )->returnArg();
        Functions\when( 'esc_html__' )->returnArg();
        Functions\when( 'esc_html' )->returnArg();
        Functions\when( 'esc_attr' )->returnArg();

        $this->noticeAdjRepoMock = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentNoticeAdjutancyRepository' );
        $this->noticeAdjRepoMock->shouldReceive( 'get_adjutancy_ids_for_notice' )->andReturn( array() )->byDefault();

        $this->adminPageMock = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentAdminPage' );
        $this->adminPageMock->shouldReceive( 'notice_status_badge' )->andReturnUsing(
            fn( $status ) => "<span class=\"ffc-status ffc-status-{$status}\">{$status}</span>"
        );

        $this->table = new RecruitmentNoticesListTable();
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
}
