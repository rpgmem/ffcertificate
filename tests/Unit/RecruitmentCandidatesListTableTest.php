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
        Functions\when( 'esc_attr__' )->returnArg();
        Functions\when( 'esc_html' )->returnArg();
        Functions\when( 'esc_attr' )->returnArg();
        Functions\when( 'esc_url' )->returnArg();
        Functions\when( 'sanitize_text_field' )->returnArg();
        Functions\when( 'sanitize_email' )->returnArg();
        Functions\when( 'sanitize_key' )->alias( fn( $v ) => strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $v ) ) );
        Functions\when( 'wp_unslash' )->returnArg();
        Functions\when( 'absint' )->alias( fn( $v ) => abs( (int) $v ) );
        Functions\when( 'add_query_arg' )->alias( fn( $args, $url = '' ) => $url . '?' . http_build_query( (array) $args ) );
        Functions\when( 'admin_url' )->alias( fn( $p = '' ) => 'https://example.com/wp-admin/' . $p );
        Functions\when( 'wp_nonce_url' )->alias( fn( $url, $action = -1 ) => $url . '&_wpnonce=test' );
        Functions\when( 'wp_json_encode' )->alias( fn( $v ) => json_encode( $v ) );
        Functions\when( 'get_userdata' )->justReturn( false );
        Functions\when( 'get_edit_user_link' )->justReturn( '/wp-admin/user-edit.php?user_id=1' );

        // Real stub for the admin page (PAGE_SLUG constant).
        if ( ! class_exists( '\FreeFormCertificate\Recruitment\RecruitmentAdminPage', false ) ) {
            eval(
                'namespace FreeFormCertificate\Recruitment;'
                . ' class RecruitmentAdminPage { public const PAGE_SLUG = "ffc-recruitment"; }'
            );
        }
        // Reader stub — drives prepare_items() + resolve_id_constraint().
        if ( ! class_exists( '\FreeFormCertificate\Recruitment\RecruitmentCandidateReader', false ) ) {
            eval(
                'namespace FreeFormCertificate\Recruitment;'
                . ' class RecruitmentCandidateReader {'
                . ' public static $count = 0;'
                . ' public static $rows = array();'
                . ' public static $cpf_row = null;'
                . ' public static $rf_row = null;'
                . ' public static $email_ids = array();'
                . ' public static function count_paginated_filtered( $s, $c, $a, $st ) { return self::$count; }'
                . ' public static function get_paginated_filtered( $s, $c, $a, $st, $pp, $off ) { return self::$rows; }'
                . ' public static function get_by_cpf_hash( $h ) { return self::$cpf_row; }'
                . ' public static function get_by_rf_hash( $h ) { return self::$rf_row; }'
                . ' public static function get_ids_by_email_hash( $h ) { return self::$email_ids; } }'
            );
        }
        \FreeFormCertificate\Recruitment\RecruitmentCandidateReader::$count     = 0;
        \FreeFormCertificate\Recruitment\RecruitmentCandidateReader::$rows      = array();
        \FreeFormCertificate\Recruitment\RecruitmentCandidateReader::$cpf_row   = null;
        \FreeFormCertificate\Recruitment\RecruitmentCandidateReader::$rf_row    = null;
        \FreeFormCertificate\Recruitment\RecruitmentCandidateReader::$email_ids = array();

        $this->table = new RecruitmentCandidatesListTable();
    }

    protected function tearDown(): void {
        unset(
            $_REQUEST['cpf'], $_REQUEST['rf'], $_REQUEST['email'], $_REQUEST['s'],
            $_REQUEST['adjutancy_id'], $_REQUEST['status']
        );
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

    // ------------------------------------------------------------------
    // column_name() — row actions (Edit / Delete)
    // ------------------------------------------------------------------

    public function test_column_name_renders_edit_delete_row_actions(): void {
        $out = $this->call_protected( 'column_name', array( array( 'id' => 11, 'name' => 'Jane Doe' ) ) );

        $this->assertStringContainsString( 'Jane Doe', $out );
        $this->assertStringContainsString( 'action=edit-candidate', $out );
        $this->assertStringContainsString( 'action=delete-candidate', $out );
        $this->assertStringContainsString( 'submitdelete', $out );
    }

    // ------------------------------------------------------------------
    // prepare_items()
    // ------------------------------------------------------------------

    private function get_items(): array {
        $ref = new \ReflectionProperty( $this->table, 'items' );
        $ref->setAccessible( true );
        return (array) $ref->getValue( $this->table );
    }

    private function candidate_obj( int $id, string $name, $user_id = null ): object {
        return (object) array(
            'id'         => $id,
            'name'       => $name,
            'user_id'    => $user_id,
            'phone'      => '11999990000',
            'created_at' => '2026-03-15 09:30:00',
        );
    }

    public function test_prepare_items_maps_reader_rows_into_items(): void {
        Functions\when( '_prime_user_caches' )->justReturn( null );
        \FreeFormCertificate\Recruitment\RecruitmentCandidateReader::$count = 2;
        \FreeFormCertificate\Recruitment\RecruitmentCandidateReader::$rows  = array(
            $this->candidate_obj( 1, 'Alpha', 7 ),
            $this->candidate_obj( 2, 'Beta', null ),
        );

        $this->table->prepare_items();

        $items = $this->get_items();
        $this->assertCount( 2, $items );
        $this->assertSame( 'Alpha', $items[0]['name'] );
        $this->assertSame( 7, $items[0]['user_id'] );
        $this->assertNull( $items[1]['user_id'] );
    }

    public function test_prepare_items_empty_when_id_constraint_resolves_empty(): void {
        // CPF typed but no matching candidate → constraint = empty array.
        $_REQUEST['cpf'] = '123.456.789-00';

        Mockery::mock( 'alias:FreeFormCertificate\Core\DataSanitizer' )
            ->shouldReceive( 'normalize_cpf_rf' )->andReturn( '12345678900' );
        Mockery::mock( 'alias:FreeFormCertificate\Core\Encryption' )
            ->shouldReceive( 'hash' )->andReturn( 'abc' );
        // cpf_row stays null → resolve returns empty array → 0 rows.
        \FreeFormCertificate\Recruitment\RecruitmentCandidateReader::$count = 0;
        \FreeFormCertificate\Recruitment\RecruitmentCandidateReader::$rows  = array();

        $this->table->prepare_items();

        $this->assertSame( array(), $this->get_items() );
    }

    // ------------------------------------------------------------------
    // resolve_id_constraint() / resolve_cpf_or_rf_to_ids() (static private)
    // ------------------------------------------------------------------

    private function call_static_private( string $method, array $args ) {
        $ref = new \ReflectionMethod( RecruitmentCandidatesListTable::class, $method );
        $ref->setAccessible( true );
        return $ref->invokeArgs( null, $args );
    }

    public function test_resolve_id_constraint_null_when_all_empty(): void {
        $out = $this->call_static_private( 'resolve_id_constraint', array( '', '', '' ) );
        $this->assertNull( $out );
    }

    public function test_resolve_id_constraint_intersects_cpf_and_email(): void {
        Mockery::mock( 'alias:FreeFormCertificate\Core\DataSanitizer' )
            ->shouldReceive( 'normalize_cpf_rf' )->andReturn( '12345678900' );
        Mockery::mock( 'alias:FreeFormCertificate\Core\Encryption' )
            ->shouldReceive( 'hash' )->andReturn( 'hashval' );

        // CPF resolves to candidate id 5; email resolves to ids [5, 9] → ∩ = [5].
        \FreeFormCertificate\Recruitment\RecruitmentCandidateReader::$cpf_row   = (object) array( 'id' => 5 );
        \FreeFormCertificate\Recruitment\RecruitmentCandidateReader::$email_ids = array( 5, 9 );

        $out = $this->call_static_private( 'resolve_id_constraint', array( '123', '', 'a@b.com' ) );
        $this->assertSame( array( 5 ), $out );
    }

    public function test_resolve_id_constraint_empty_when_intersection_disjoint(): void {
        Mockery::mock( 'alias:FreeFormCertificate\Core\DataSanitizer' )
            ->shouldReceive( 'normalize_cpf_rf' )->andReturn( '12345678900' );
        Mockery::mock( 'alias:FreeFormCertificate\Core\Encryption' )
            ->shouldReceive( 'hash' )->andReturn( 'hashval' );

        \FreeFormCertificate\Recruitment\RecruitmentCandidateReader::$cpf_row   = (object) array( 'id' => 5 );
        \FreeFormCertificate\Recruitment\RecruitmentCandidateReader::$email_ids = array( 9 );

        $out = $this->call_static_private( 'resolve_id_constraint', array( '123', '', 'a@b.com' ) );
        $this->assertSame( array(), $out );
    }

    public function test_resolve_cpf_or_rf_to_ids_empty_for_blank_digits(): void {
        Mockery::mock( 'alias:FreeFormCertificate\Core\DataSanitizer' )
            ->shouldReceive( 'normalize_cpf_rf' )->andReturn( '' );

        $out = $this->call_static_private( 'resolve_cpf_or_rf_to_ids', array( 'abc', 'cpf' ) );
        $this->assertSame( array(), $out );
    }

    public function test_resolve_cpf_or_rf_to_ids_returns_id_for_rf_match(): void {
        Mockery::mock( 'alias:FreeFormCertificate\Core\DataSanitizer' )
            ->shouldReceive( 'normalize_cpf_rf' )->andReturn( '999' );
        Mockery::mock( 'alias:FreeFormCertificate\Core\Encryption' )
            ->shouldReceive( 'hash' )->andReturn( 'h' );
        \FreeFormCertificate\Recruitment\RecruitmentCandidateReader::$rf_row = (object) array( 'id' => 42 );

        $out = $this->call_static_private( 'resolve_cpf_or_rf_to_ids', array( '999', 'rf' ) );
        $this->assertSame( array( 42 ), $out );
    }

    // ------------------------------------------------------------------
    // convert_row() (static private)
    // ------------------------------------------------------------------

    public function test_convert_row_normalizes_nulls(): void {
        $row = (object) array(
            'id'         => '3',
            'name'       => 'Carol',
            'user_id'    => null,
            'phone'      => null,
            'created_at' => '2026-03-15 09:30:00',
        );
        $out = $this->call_static_private( 'convert_row', array( $row ) );

        $this->assertSame( 3, $out['id'] );
        $this->assertNull( $out['user_id'] );
        $this->assertSame( '', $out['phone'] );
    }

    // ------------------------------------------------------------------
    // extra_tablenav()
    // ------------------------------------------------------------------

    public function test_extra_tablenav_returns_early_for_bottom(): void {
        ob_start();
        $this->call_protected( 'extra_tablenav', array( 'bottom' ) );
        $out = ob_get_clean();

        $this->assertSame( '', $out );
    }

    public function test_extra_tablenav_top_renders_filters_and_adjutancy_options(): void {
        // Adjutancy reader stub (separate class from the candidate reader).
        if ( ! class_exists( '\FreeFormCertificate\Recruitment\RecruitmentAdjutancyReader', false ) ) {
            eval(
                'namespace FreeFormCertificate\Recruitment;'
                . ' class RecruitmentAdjutancyReader {'
                . ' public static $rows = array();'
                . ' public static function get_all() { return self::$rows; } }'
            );
        }
        \FreeFormCertificate\Recruitment\RecruitmentAdjutancyReader::$rows = array(
            (object) array( 'id' => 4, 'name' => 'Adjutancy Four' ),
        );

        ob_start();
        $this->call_protected( 'extra_tablenav', array( 'top' ) );
        $out = ob_get_clean();

        $this->assertStringContainsString( 'name="cpf"', $out );
        $this->assertStringContainsString( 'name="rf"', $out );
        $this->assertStringContainsString( 'name="email"', $out );
        $this->assertStringContainsString( 'Adjutancy Four', $out );
        $this->assertStringContainsString( 'name="status"', $out );
    }
}
