<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Recruitment\RecruitmentCandidatesListTable;

/**
 * Smoke tests for the Candidates list table.
 *
 * @covers \FreeFormCertificate\Recruitment\RecruitmentCandidatesListTable
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class RecruitmentCandidatesListTableTest extends TestCase {

    use MockeryPHPUnitIntegration;

    private RecruitmentCandidatesListTable $table;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        Functions\when( '__' )->returnArg();
        Functions\when( 'esc_html__' )->returnArg();
        Functions\when( 'esc_html' )->returnArg();
        Functions\when( 'esc_attr' )->returnArg();
        Functions\when( 'esc_url' )->returnArg();
        Functions\when( 'get_userdata' )->justReturn( false );
        Functions\when( 'get_edit_user_link' )->justReturn( '/wp-admin/user-edit.php?user_id=1' );

        $this->table = new RecruitmentCandidatesListTable();
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
            array( 'cb', 'name', 'user_id', 'phone', 'created_at' ),
            array_keys( $cols )
        );
    }

    public function test_get_sortable_columns_only_includes_name(): void {
        $sortable = $this->call_protected( 'get_sortable_columns' );

        $this->assertSame( array( 'name' ), array_keys( $sortable ) );
    }

    public function test_get_bulk_actions_returns_empty_until_delete_service_lands(): void {
        $actions = $this->call_protected( 'get_bulk_actions' );

        $this->assertSame( array(), $actions );
    }

    public function test_column_cb_emits_checkbox_named_candidate_ids(): void {
        $html = $this->call_protected( 'column_cb', array( array( 'id' => 77 ) ) );

        $this->assertStringContainsString( 'name="candidate_ids[]"', $html );
        $this->assertStringContainsString( 'value="77"', $html );
    }

    public function test_column_phone_em_dash_when_phone_empty(): void {
        $out = $this->call_protected( 'column_phone', array( array( 'phone' => '' ) ) );

        $this->assertSame( '—', $out );
    }

    public function test_column_phone_emits_escaped_value_when_present(): void {
        $out = $this->call_protected( 'column_phone', array( array( 'phone' => '+55 11 99999-0000' ) ) );

        $this->assertSame( '+55 11 99999-0000', $out );
    }

    public function test_column_user_id_em_dash_when_zero(): void {
        $out = $this->call_protected( 'column_user_id', array( array( 'user_id' => 0 ) ) );

        $this->assertSame( '—', $out );
    }

    public function test_column_user_id_shows_id_in_code_tags_when_user_missing(): void {
        Functions\when( 'get_userdata' )->justReturn( false );
        $out = $this->call_protected( 'column_user_id', array( array( 'user_id' => 42 ) ) );

        $this->assertStringContainsString( '<code>', $out );
        $this->assertStringContainsString( '#42', $out );
    }

    public function test_column_user_id_links_to_user_edit_when_user_exists(): void {
        $user_mock            = Mockery::mock( 'WP_User' );
        $user_mock->user_login = 'jane';
        Functions\when( 'get_userdata' )->justReturn( $user_mock );

        $out = $this->call_protected( 'column_user_id', array( array( 'user_id' => 7 ) ) );

        $this->assertStringContainsString( 'jane', $out );
        $this->assertStringContainsString( '<a href=', $out );
    }

    public function test_column_default_escapes_value(): void {
        $out = $this->call_protected( 'column_default', array( array( 'name' => 'Jane' ), 'name' ) );
        $this->assertSame( 'Jane', $out );
    }

    public function test_column_default_returns_empty_string_for_missing_column(): void {
        $out = $this->call_protected( 'column_default', array( array(), 'missing' ) );
        $this->assertSame( '', $out );
    }

    public function test_column_created_at_returns_timestamp_string(): void {
        $out = $this->call_protected( 'column_created_at', array( array( 'created_at' => '2026-03-15 09:30:00' ) ) );
        $this->assertSame( '2026-03-15 09:30:00', $out );
    }
}
