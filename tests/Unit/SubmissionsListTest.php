<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Admin\SubmissionsList;

/**
 * @covers \FreeFormCertificate\Admin\SubmissionsList
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class SubmissionsListTest extends TestCase {

    use MockeryPHPUnitIntegration;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        class_exists( '\FreeFormCertificate\Admin\SubmissionsList' );

        Functions\when( '__' )->returnArg();
        Functions\when( 'esc_html__' )->returnArg();
        Functions\when( 'esc_attr__' )->returnArg();
        Functions\when( 'esc_attr_e' )->alias( function ( $text ) { echo $text; } );
        Functions\when( 'esc_html' )->returnArg();
        Functions\when( 'esc_attr' )->returnArg();
        Functions\when( 'esc_url' )->returnArg();
        Functions\when( 'esc_html_e' )->alias( function ( $text ) { echo $text; } );
        Functions\when( 'absint' )->alias( function ( $v ) { return abs( (int) $v ); } );
        Functions\when( 'sanitize_text_field' )->returnArg();
        Functions\when( 'sanitize_key' )->returnArg();
        Functions\when( 'wp_unslash' )->returnArg();
        Functions\when( 'get_posts' )->justReturn( array() );
        Functions\when( 'admin_url' )->alias( fn( $p = '' ) => 'https://example.com/wp-admin/' . $p );
        Functions\when( 'add_query_arg' )->alias( fn( $args, $url = '' ) => ( is_string( $url ) ? $url : '/' ) . '?' . http_build_query( is_array( $args ) ? $args : array() ) );
        Functions\when( 'remove_query_arg' )->justReturn( '/' );
        Functions\when( 'wp_nonce_url' )->alias( fn( $url, $action = -1 ) => $url . '&_wpnonce=test' );
        Functions\when( 'wp_create_nonce' )->justReturn( 'test_nonce' );
        Functions\when( 'get_option' )->justReturn( 'Y-m-d H:i' );

        if ( ! defined( 'ABSPATH' ) ) {
            define( 'ABSPATH', '/tmp/' );
        }

        // SubmissionsList extends WP_List_Table which needs $wpdb.
        global $wpdb;
        $wpdb         = Mockery::mock( 'wpdb' )->makePartial();
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive( 'prepare' )->andReturnUsing( function () { return func_get_arg( 0 ); } )->byDefault();
        $wpdb->shouldReceive( 'get_results' )->andReturn( array() )->byDefault();
        $wpdb->shouldReceive( 'get_var' )->andReturn( '0' )->byDefault();
        $wpdb->shouldReceive( 'get_row' )->andReturn( null )->byDefault();
    }

    protected function tearDown(): void {
        unset(
            $_GET['status'], $_GET['filter_form_id'], $_GET['orderby'], $_GET['order'],
            $_REQUEST['s']
        );
        Monkey\tearDown();
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Collaborator stubs (alias mocks) + helpers
    // ------------------------------------------------------------------

    /**
     * Alias-mock the static collaborator classes used across column/render paths.
     */
    private function stubStaticCollaborators( bool $can_manage = true, bool $can_edit = true, string $pii_tier = 'unmasked' ): void {
        Mockery::getConfiguration()->setConstantsMap(
            array(
                'FreeFormCertificate\Core\PiiAccessPolicy' => array(
                    'TIER_UNMASKED' => 'unmasked',
                    'TIER_REVEAL'   => 'reveal',
                    'TIER_MASKED'   => 'masked',
                ),
            )
        );
        Mockery::mock( 'alias:FreeFormCertificate\Core\PiiAccessPolicy' )
            ->shouldReceive( 'resolve' )->andReturn( $pii_tier );

        Mockery::mock( 'alias:FreeFormCertificate\Core\DocumentFormatter' )
            ->shouldReceive( 'mask_email' )->andReturnUsing( fn( $e ) => 'MASKED:' . (string) $e );

        Mockery::mock( 'alias:FreeFormCertificate\Core\Utils' )
            ->shouldReceive( 'truncate' )
            ->andReturnUsing( fn( $text, $len = 100, $suffix = '...' ) => (string) $text );

        Mockery::mock( 'alias:FreeFormCertificate\Core\DateFormatter' )
            ->shouldReceive( 'format_datetime' )
            ->andReturnUsing( fn( $ts ) => 'DATE:' . (string) $ts );

        Mockery::mock( 'alias:FreeFormCertificate\Core\Capabilities' )
            ->shouldReceive( 'current_user_can_admin_or' )
            ->andReturnUsing(
                function ( $cap ) use ( $can_manage, $can_edit ) {
                    if ( 'ffc_manage_certificates' === $cap ) {
                        return $can_manage;
                    }
                    if ( 'ffc_edit_certificates' === $cap ) {
                        return $can_edit;
                    }
                    return false;
                }
            );

        Mockery::mock( 'alias:FreeFormCertificate\Core\RequestInput' )
            ->shouldReceive( 'get_get_string' )
            ->andReturnUsing( fn( $key, $default = '' ) => isset( $_GET[ $key ] ) ? (string) $_GET[ $key ] : $default );

        Mockery::mock( 'alias:FreeFormCertificate\Generators\MagicLinkHelper' )
            ->shouldReceive( 'generate_magic_link' )->andReturnUsing( fn( $token ) => 'https://magic/' . $token )
            ->shouldReceive( 'get_submission_magic_link' )->andReturnUsing( fn( $id, $h ) => $id > 0 ? 'https://magic/byid/' . $id : '' );
    }

    private function makeList( $handler = null ): SubmissionsList {
        if ( null === $handler ) {
            $handler = Mockery::mock( 'FreeFormCertificate\Submissions\SubmissionHandler' );
        }
        return new SubmissionsList( $handler );
    }

    /** Replace the private repository property with a controllable mock. */
    private function injectRepository( SubmissionsList $list, $repo ): void {
        $ref = new \ReflectionProperty( $list, 'repository' );
        $ref->setAccessible( true );
        $ref->setValue( $list, $repo );
    }

    private function call_protected( SubmissionsList $list, string $method, array $args = array() ) {
        $ref = new \ReflectionMethod( $list, $method );
        $ref->setAccessible( true );
        return $ref->invokeArgs( $list, $args );
    }

    private function get_prop( SubmissionsList $list, string $prop ) {
        $ref = new \ReflectionProperty( $list, $prop );
        $ref->setAccessible( true );
        return $ref->getValue( $list );
    }

    private function set_prop( SubmissionsList $list, string $prop, $value ): void {
        $ref = new \ReflectionProperty( $list, $prop );
        $ref->setAccessible( true );
        $ref->setValue( $list, $value );
    }

    // ==================================================================
    // Constructor / columns / sortable
    // ==================================================================

    public function test_constructor_creates_instance(): void {
        $this->assertInstanceOf( SubmissionsList::class, $this->makeList() );
    }

    public function test_get_columns_returns_expected_keys(): void {
        $cols = $this->makeList()->get_columns();
        $this->assertSame(
            array( 'cb', 'id', 'form', 'email', 'data', 'status', 'submission_date', 'actions' ),
            array_keys( $cols )
        );
    }

    public function test_get_sortable_columns(): void {
        $list     = $this->makeList();
        $sortable = $this->call_protected( $list, 'get_sortable_columns' );
        $this->assertSame( array( 'id', 'form', 'submission_date' ), array_keys( $sortable ) );
        $this->assertSame( array( 'id', true ), $sortable['id'] );
    }

    // ==================================================================
    // column_cb / column_default basics
    // ==================================================================

    public function test_column_cb_emits_checkbox(): void {
        $list = $this->makeList();
        $html = $this->call_protected( $list, 'column_cb', array( array( 'id' => 9 ) ) );
        $this->assertStringContainsString( 'name="submission[]"', $html );
        $this->assertStringContainsString( 'value="9"', $html );
    }

    public function test_column_default_id(): void {
        $this->stubStaticCollaborators();
        $list = $this->makeList();
        $out  = $this->call_protected( $list, 'column_default', array( array( 'id' => 12 ), 'id' ) );
        $this->assertSame( '12', $out );
    }

    public function test_column_default_email_unmasked_tier_shows_plaintext(): void {
        $this->stubStaticCollaborators();
        $list = $this->makeList();
        $out  = $this->call_protected( $list, 'column_default', array( array( 'email' => 'a@b.com' ), 'email' ) );
        $this->assertSame( 'a@b.com', $out );
    }

    public function test_column_default_email_masked_tier_masks(): void {
        $this->stubStaticCollaborators( true, true, 'masked' );
        $list = $this->makeList();
        $out  = $this->call_protected( $list, 'column_default', array( array( 'email' => 'a@b.com' ), 'email' ) );
        $this->assertSame( 'MASKED:a@b.com', $out );
    }

    public function test_column_default_email_empty_returns_empty(): void {
        $this->stubStaticCollaborators();
        $list = $this->makeList();
        $out  = $this->call_protected( $list, 'column_default', array( array( 'email' => '' ), 'email' ) );
        $this->assertSame( '', $out );
    }

    public function test_column_default_unknown_returns_empty(): void {
        $this->stubStaticCollaborators();
        $list = $this->makeList();
        $out  = $this->call_protected( $list, 'column_default', array( array(), 'nope' ) );
        $this->assertSame( '', $out );
    }

    public function test_column_default_submission_date_uses_formatter(): void {
        $this->stubStaticCollaborators();
        $list = $this->makeList();
        $out  = $this->call_protected( $list, 'column_default', array( array( 'submission_date' => 123 ), 'submission_date' ) );
        $this->assertSame( 'DATE:123', $out );
    }

    public function test_column_default_form_with_cached_title(): void {
        $this->stubStaticCollaborators();
        $list = $this->makeList();
        $this->set_prop( $list, 'form_titles_cache', array( 5 => 'My Form' ) );
        $out = $this->call_protected( $list, 'column_default', array( array( 'form_id' => 5 ), 'form' ) );
        $this->assertStringContainsString( 'My Form', $out );
    }

    public function test_column_default_form_deleted_when_no_title(): void {
        $this->stubStaticCollaborators();
        $list = $this->makeList();
        $out  = $this->call_protected( $list, 'column_default', array( array( 'form_id' => 99 ), 'form' ) );
        $this->assertSame( '(Deleted)', $out );
    }

    // ==================================================================
    // format_data_preview (via column_default 'data')
    // ==================================================================

    public function test_format_data_preview_null_returns_mandatory(): void {
        $this->stubStaticCollaborators();
        $list = $this->makeList();
        $out  = $this->call_protected( $list, 'column_default', array( array( 'data' => null ), 'data' ) );
        $this->assertStringContainsString( 'Only mandatory fields', $out );
    }

    public function test_format_data_preview_literal_null_string(): void {
        $this->stubStaticCollaborators();
        $list = $this->makeList();
        $out  = $this->call_protected( $list, 'column_default', array( array( 'data' => 'null' ), 'data' ) );
        $this->assertStringContainsString( 'Only mandatory fields', $out );
    }

    public function test_format_data_preview_skips_sensitive_and_limits_to_three(): void {
        $this->stubStaticCollaborators();
        $list = $this->makeList();
        $json = json_encode(
            array(
                'email' => 'skip@me.com', // skipped
                'cpf'   => '123',          // skipped
                'name'  => 'Carol',
                'city'  => 'SP',
                'role'  => 'Teacher',
                'extra' => 'overflow',     // beyond 3 → dropped
            )
        );
        $out = $this->call_protected( $list, 'column_default', array( array( 'data' => $json ), 'data' ) );
        $this->assertStringContainsString( 'ffc-data-preview', $out );
        $this->assertStringContainsString( 'Carol', $out );
        $this->assertStringNotContainsString( 'skip@me.com', $out );
        $this->assertStringNotContainsString( 'overflow', $out );
    }

    public function test_format_data_preview_array_value_imploded(): void {
        $this->stubStaticCollaborators();
        $list = $this->makeList();
        $json = json_encode( array( 'tags' => array( 'a', 'b' ) ) );
        $out  = $this->call_protected( $list, 'column_default', array( array( 'data' => $json ), 'data' ) );
        $this->assertStringContainsString( 'a, b', $out );
    }

    public function test_format_data_preview_only_skipped_fields_returns_mandatory(): void {
        $this->stubStaticCollaborators();
        $list = $this->makeList();
        $json = json_encode( array( 'email' => 'x@y.com', 'cpf' => '1' ) );
        $out  = $this->call_protected( $list, 'column_default', array( array( 'data' => $json ), 'data' ) );
        $this->assertStringContainsString( 'Only mandatory fields', $out );
    }

    public function test_format_data_preview_invalid_json_returns_mandatory(): void {
        $this->stubStaticCollaborators();
        Functions\when( 'wp_unslash' )->returnArg();
        $list = $this->makeList();
        $out  = $this->call_protected( $list, 'column_default', array( array( 'data' => 'not-json{' ), 'data' ) );
        $this->assertStringContainsString( 'Only mandatory fields', $out );
    }

    // ==================================================================
    // render_status_badge (via column_default 'status')
    // ==================================================================

    public function test_status_badge_publish(): void {
        $this->stubStaticCollaborators();
        $list = $this->makeList();
        $out  = $this->call_protected( $list, 'column_default', array( array( 'status' => 'publish' ), 'status' ) );
        $this->assertStringContainsString( 'ffc-badge-success', $out );
        $this->assertStringContainsString( 'Published', $out );
    }

    public function test_status_badge_publish_with_quiz_score(): void {
        $this->stubStaticCollaborators();
        $list = $this->makeList();
        $json = json_encode( array( '_quiz_percent' => 85 ) );
        $out  = $this->call_protected( $list, 'column_default', array( array( 'status' => 'publish', 'data' => $json ), 'status' ) );
        $this->assertStringContainsString( '85%', $out );
    }

    public function test_status_badge_trash(): void {
        $this->stubStaticCollaborators();
        $list = $this->makeList();
        $out  = $this->call_protected( $list, 'column_default', array( array( 'status' => 'trash' ), 'status' ) );
        $this->assertStringContainsString( 'ffc-badge-muted', $out );
    }

    public function test_status_badge_quiz_in_progress(): void {
        $this->stubStaticCollaborators();
        $list = $this->makeList();
        $out  = $this->call_protected( $list, 'column_default', array( array( 'status' => 'quiz_in_progress' ), 'status' ) );
        $this->assertStringContainsString( 'ffc-badge-warning', $out );
    }

    public function test_status_badge_quiz_failed(): void {
        $this->stubStaticCollaborators();
        $list = $this->makeList();
        $out  = $this->call_protected( $list, 'column_default', array( array( 'status' => 'quiz_failed' ), 'status' ) );
        $this->assertStringContainsString( 'ffc-badge-danger', $out );
    }

    public function test_status_badge_unknown_default(): void {
        $this->stubStaticCollaborators();
        $list = $this->makeList();
        $out  = $this->call_protected( $list, 'column_default', array( array( 'status' => 'weird' ), 'status' ) );
        $this->assertStringContainsString( 'ffc-badge', $out );
        $this->assertStringContainsString( 'weird', $out );
    }

    // ==================================================================
    // render_actions / render_pdf_button (via column_default 'actions')
    // ==================================================================

    public function test_actions_manage_publish_shows_edit_pdf_trash(): void {
        $this->stubStaticCollaborators( true, true );
        $list = $this->makeList();
        $item = array( 'id' => 3, 'status' => 'publish', 'magic_token' => 'TOK' );
        $out  = $this->call_protected( $list, 'column_default', array( $item, 'actions' ) );
        $this->assertStringContainsString( 'Edit', $out );
        $this->assertStringContainsString( 'PDF', $out );
        $this->assertStringContainsString( 'Trash', $out );
        $this->assertStringNotContainsString( 'Restore', $out );
    }

    public function test_actions_manage_trash_shows_restore_delete(): void {
        $this->stubStaticCollaborators( true, true );
        $list = $this->makeList();
        $item = array( 'id' => 4, 'status' => 'trash', 'magic_token' => 'TOK' );
        $out  = $this->call_protected( $list, 'column_default', array( $item, 'actions' ) );
        $this->assertStringContainsString( 'Restore', $out );
        $this->assertStringContainsString( 'Delete', $out );
        $this->assertStringNotContainsString( '>Trash<', $out );
    }

    public function test_actions_view_only_no_edit_no_write(): void {
        // Neither manage nor edit → only PDF button.
        $this->stubStaticCollaborators( false, false );
        $list = $this->makeList();
        $item = array( 'id' => 5, 'status' => 'publish', 'magic_token' => 'TOK' );
        $out  = $this->call_protected( $list, 'column_default', array( $item, 'actions' ) );
        $this->assertStringContainsString( 'PDF', $out );
        $this->assertStringNotContainsString( 'Edit', $out );
        $this->assertStringNotContainsString( 'Trash', $out );
        $this->assertStringNotContainsString( 'Restore', $out );
    }

    public function test_actions_edit_only_shows_edit_pdf_no_write(): void {
        // edit but not manage → Edit + PDF, no trash/restore.
        $this->stubStaticCollaborators( false, true );
        $list = $this->makeList();
        $item = array( 'id' => 6, 'status' => 'publish', 'magic_token' => 'TOK' );
        $out  = $this->call_protected( $list, 'column_default', array( $item, 'actions' ) );
        $this->assertStringContainsString( 'Edit', $out );
        $this->assertStringContainsString( 'PDF', $out );
        $this->assertStringNotContainsString( 'Trash', $out );
    }

    public function test_pdf_button_fallback_to_handler_when_no_token(): void {
        $this->stubStaticCollaborators( false, false );
        $list = $this->makeList();
        $item = array( 'id' => 7, 'status' => 'publish' ); // no magic_token
        $out  = $this->call_protected( $list, 'render_pdf_button', array( $item ) );
        $this->assertStringContainsString( 'PDF', $out );
        $this->assertStringContainsString( 'byid/7', $out );
    }

    public function test_pdf_button_no_token_message_when_link_empty(): void {
        $this->stubStaticCollaborators( false, false );
        $list = $this->makeList();
        $item = array( 'id' => 0, 'status' => 'publish' ); // id 0 → empty link
        $out  = $this->call_protected( $list, 'render_pdf_button', array( $item ) );
        $this->assertStringContainsString( 'No token', $out );
        $this->assertStringContainsString( 'ffc-no-token', $out );
    }

    // ==================================================================
    // get_bulk_actions (capability gate + status branches)
    // ==================================================================

    public function test_bulk_actions_empty_without_manage(): void {
        $this->stubStaticCollaborators( false, false );
        $list = $this->makeList();
        $this->assertSame( array(), $this->call_protected( $list, 'get_bulk_actions' ) );
    }

    public function test_bulk_actions_publish_default(): void {
        $this->stubStaticCollaborators( true, true );
        $list = $this->makeList();
        $actions = $this->call_protected( $list, 'get_bulk_actions' );
        $this->assertArrayHasKey( 'bulk_trash', $actions );
        $this->assertArrayNotHasKey( 'bulk_restore', $actions );
    }

    public function test_bulk_actions_trash_status(): void {
        $this->stubStaticCollaborators( true, true );
        $_GET['status'] = 'trash';
        $list = $this->makeList();
        $actions = $this->call_protected( $list, 'get_bulk_actions' );
        $this->assertArrayHasKey( 'bulk_restore', $actions );
        $this->assertArrayHasKey( 'bulk_delete', $actions );
    }

    public function test_bulk_actions_move_to_form_when_single_form_filter_string(): void {
        $this->stubStaticCollaborators( true, true );
        $_GET['filter_form_id'] = '15';
        $list = $this->makeList();
        $actions = $this->call_protected( $list, 'get_bulk_actions' );
        $this->assertArrayHasKey( 'move_to_form', $actions );
    }

    public function test_bulk_actions_move_to_form_when_single_form_filter_array(): void {
        $this->stubStaticCollaborators( true, true );
        $_GET['filter_form_id'] = array( '22' );
        $list = $this->makeList();
        $actions = $this->call_protected( $list, 'get_bulk_actions' );
        $this->assertArrayHasKey( 'move_to_form', $actions );
    }

    public function test_bulk_actions_no_move_when_multiple_forms(): void {
        $this->stubStaticCollaborators( true, true );
        $_GET['filter_form_id'] = array( '1', '2' );
        $list = $this->makeList();
        $actions = $this->call_protected( $list, 'get_bulk_actions' );
        $this->assertArrayNotHasKey( 'move_to_form', $actions );
    }

    // ==================================================================
    // process_bulk_action (intentionally empty)
    // ==================================================================

    public function test_process_bulk_action_is_noop(): void {
        $list = $this->makeList();
        $this->assertNull( $list->process_bulk_action() );
    }

    // ==================================================================
    // prepare_items
    // ==================================================================

    public function test_prepare_items_populates_items_and_pagination(): void {
        $this->stubStaticCollaborators( true, true );

        $handler = Mockery::mock( 'FreeFormCertificate\Submissions\SubmissionHandler' );
        $handler->shouldReceive( 'decrypt_submission_data' )
            ->andReturnUsing( fn( $item ) => $item );

        $list = $this->makeList( $handler );

        $repo = Mockery::mock( 'FreeFormCertificate\Repositories\SubmissionRepository' );
        $repo->shouldReceive( 'findPaginated' )->once()->andReturn(
            array(
                'items' => array(
                    array( 'id' => 1, 'form_id' => 10, 'email' => 'a@b.com' ),
                    array( 'id' => 2, 'form_id' => 0, 'email' => 'c@d.com' ),
                ),
                'total' => 2,
                'pages' => 1,
            )
        );
        $this->injectRepository( $list, $repo );

        $list->prepare_items();

        $items = $this->get_prop( $list, 'items' );
        $this->assertCount( 2, $items );
        $this->assertSame( 1, $items[0]['id'] );
    }

    public function test_prepare_items_empty_result(): void {
        $this->stubStaticCollaborators( true, true );

        $handler = Mockery::mock( 'FreeFormCertificate\Submissions\SubmissionHandler' );
        $list    = $this->makeList( $handler );

        $repo = Mockery::mock( 'FreeFormCertificate\Repositories\SubmissionRepository' );
        $repo->shouldReceive( 'findPaginated' )->once()->andReturn(
            array( 'items' => array(), 'total' => 0, 'pages' => 0 )
        );
        $this->injectRepository( $list, $repo );

        $list->prepare_items();

        $this->assertSame( array(), $this->get_prop( $list, 'items' ) );
    }

    public function test_prepare_items_with_filters_from_request(): void {
        $this->stubStaticCollaborators( true, true );
        $_GET['status']         = 'trash';
        $_GET['orderby']        = 'form_id';
        $_GET['order']          = 'asc';
        $_GET['filter_form_id'] = array( '3', '4' );
        $_REQUEST['s']          = 'search-term';

        $handler = Mockery::mock( 'FreeFormCertificate\Submissions\SubmissionHandler' );
        $handler->shouldReceive( 'decrypt_submission_data' )->andReturnUsing( fn( $i ) => $i );
        $list = $this->makeList( $handler );

        $repo = Mockery::mock( 'FreeFormCertificate\Repositories\SubmissionRepository' );
        $repo->shouldReceive( 'findPaginated' )
            ->once()
            ->with(
                Mockery::on(
                    function ( $args ) {
                        return 'trash' === $args['status']
                            && 'search-term' === $args['search']
                            && 'form_id' === $args['orderby']
                            && 'ASC' === $args['order']
                            && array( 3, 4 ) === $args['form_ids'];
                    }
                )
            )
            ->andReturn( array( 'items' => array(), 'total' => 0, 'pages' => 0 ) );
        $this->injectRepository( $list, $repo );

        $list->prepare_items();
        $this->assertTrue( true );
    }

    public function test_prepare_items_single_form_filter_string(): void {
        $this->stubStaticCollaborators( true, true );
        $_GET['filter_form_id'] = '7';

        $handler = Mockery::mock( 'FreeFormCertificate\Submissions\SubmissionHandler' );
        $handler->shouldReceive( 'decrypt_submission_data' )->andReturnUsing( fn( $i ) => $i );
        $list = $this->makeList( $handler );

        $repo = Mockery::mock( 'FreeFormCertificate\Repositories\SubmissionRepository' );
        $repo->shouldReceive( 'findPaginated' )
            ->once()
            ->with(
                Mockery::on( fn( $args ) => array( 7 ) === $args['form_ids'] )
            )
            ->andReturn( array( 'items' => array(), 'total' => 0, 'pages' => 0 ) );
        $this->injectRepository( $list, $repo );

        $list->prepare_items();
        $this->assertTrue( true );
    }

    // ==================================================================
    // preload_form_titles
    // ==================================================================

    public function test_preload_form_titles_empty_items_returns_early(): void {
        $list = $this->makeList();
        $this->set_prop( $list, 'items', array() );
        $this->call_protected( $list, 'preload_form_titles' );
        $this->assertSame( array(), $this->get_prop( $list, 'form_titles_cache' ) );
    }

    public function test_preload_form_titles_no_form_ids_returns_early(): void {
        $list = $this->makeList();
        $this->set_prop( $list, 'items', array( array( 'form_id' => 0 ) ) );
        $this->call_protected( $list, 'preload_form_titles' );
        $this->assertSame( array(), $this->get_prop( $list, 'form_titles_cache' ) );
    }

    public function test_preload_form_titles_caches_post_titles(): void {
        $post1 = (object) array( 'ID' => 10, 'post_title' => 'Form Ten' );
        $post2 = (object) array( 'ID' => 11, 'post_title' => 'Form Eleven' );
        Functions\when( 'get_posts' )->justReturn( array( $post1, $post2 ) );

        $list = $this->makeList();
        $this->set_prop( $list, 'items', array(
            array( 'form_id' => 10 ),
            array( 'form_id' => 11 ),
            array( 'form_id' => 10 ),
        ) );
        $this->call_protected( $list, 'preload_form_titles' );

        $cache = $this->get_prop( $list, 'form_titles_cache' );
        $this->assertSame( 'Form Ten', $cache[10] );
        $this->assertSame( 'Form Eleven', $cache[11] );
    }

    // ==================================================================
    // get_views
    // ==================================================================

    public function test_get_views_renders_all_tabs_with_counts(): void {
        $list = $this->makeList();

        $repo = Mockery::mock( 'FreeFormCertificate\Repositories\SubmissionRepository' );
        $repo->shouldReceive( 'countByStatus' )->once()->andReturn(
            array(
                'publish'          => 5,
                'trash'            => 2,
                'quiz_in_progress' => 1,
                'quiz_failed'      => 3,
            )
        );
        $this->injectRepository( $list, $repo );

        $views = $this->call_protected( $list, 'get_views' );
        $this->assertArrayHasKey( 'all', $views );
        $this->assertArrayHasKey( 'trash', $views );
        $this->assertStringContainsString( '(5)', $views['all'] );
        $this->assertStringContainsString( 'current', $views['all'] ); // default status=publish
    }

    public function test_get_views_marks_trash_current(): void {
        $_GET['status'] = 'trash';
        $list = $this->makeList();

        $repo = Mockery::mock( 'FreeFormCertificate\Repositories\SubmissionRepository' );
        $repo->shouldReceive( 'countByStatus' )->andReturn(
            array( 'publish' => 0, 'trash' => 0, 'quiz_in_progress' => 0, 'quiz_failed' => 0 )
        );
        $this->injectRepository( $list, $repo );

        $views = $this->call_protected( $list, 'get_views' );
        $this->assertStringContainsString( 'current', $views['trash'] );
    }

    // ==================================================================
    // no_items
    // ==================================================================

    public function test_no_items_outputs_message(): void {
        $list = $this->makeList();
        ob_start();
        $list->no_items();
        $out = ob_get_clean();
        $this->assertNotEmpty( $out );
    }

    // ==================================================================
    // extra_tablenav
    // ==================================================================

    public function test_extra_tablenav_bottom_returns_early(): void {
        $list = $this->makeList();
        ob_start();
        $this->call_protected( $list, 'extra_tablenav', array( 'bottom' ) );
        $this->assertSame( '', ob_get_clean() );
    }

    public function test_extra_tablenav_no_forms_returns_early(): void {
        Functions\when( 'get_posts' )->justReturn( array() );
        $list = $this->makeList();
        ob_start();
        $this->call_protected( $list, 'extra_tablenav', array( 'top' ) );
        $this->assertSame( '', ob_get_clean() );
    }

    public function test_extra_tablenav_renders_filter_overlay(): void {
        $form1 = (object) array( 'ID' => 10, 'post_title' => 'Form Ten' );
        $form2 = (object) array( 'ID' => 11, 'post_title' => 'Form Eleven' );
        Functions\when( 'get_posts' )->justReturn( array( $form1, $form2 ) );

        $list = $this->makeList();
        ob_start();
        $this->call_protected( $list, 'extra_tablenav', array( 'top' ) );
        $out = ob_get_clean();

        $this->assertStringContainsString( 'ffc-filter-overlay', $out );
        $this->assertStringContainsString( 'name="filter_form_id[]"', $out );
        $this->assertStringContainsString( 'Form Ten', $out );
        $this->assertStringContainsString( 'Filter', $out );
    }

    public function test_extra_tablenav_with_selected_filter_shows_clear_and_checked(): void {
        $form1 = (object) array( 'ID' => 10, 'post_title' => 'Form Ten' );
        Functions\when( 'get_posts' )->justReturn( array( $form1 ) );
        $_GET['filter_form_id'] = array( '10' );

        $list = $this->makeList();
        ob_start();
        $this->call_protected( $list, 'extra_tablenav', array( 'top' ) );
        $out = ob_get_clean();

        $this->assertStringContainsString( 'Clear Filter', $out );
        $this->assertStringContainsString( 'checked', $out );
        $this->assertStringContainsString( 'Filter (1)', $out );
    }

    public function test_extra_tablenav_selected_filter_string_form(): void {
        $form1 = (object) array( 'ID' => 10, 'post_title' => 'Form Ten' );
        Functions\when( 'get_posts' )->justReturn( array( $form1 ) );
        $_GET['filter_form_id'] = '10';

        $list = $this->makeList();
        ob_start();
        $this->call_protected( $list, 'extra_tablenav', array( 'top' ) );
        $out = ob_get_clean();

        $this->assertStringContainsString( 'Clear Filter', $out );
    }
}
