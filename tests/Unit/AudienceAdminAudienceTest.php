<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Audience\AudienceAdminAudience;

/**
 * @covers \FreeFormCertificate\Audience\AudienceAdminAudience
 * @covers \FreeFormCertificate\Audience\AudienceAdminAudienceRenderer
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class AudienceAdminAudienceTest extends TestCase {

    use MockeryPHPUnitIntegration;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        // pcov does not record lines for files first autoloaded mid-test-method,
        // so the extracted renderer's coverage would attribute to nothing.
        // Preload the class here so pcov attributes its lines to this test.
        class_exists( '\\FreeFormCertificate\\Audience\\AudienceAdminAudienceRenderer' );

        Functions\when( '__' )->returnArg();
        Functions\when( 'esc_html__' )->returnArg();
        Functions\when( 'esc_html' )->returnArg();
        Functions\when( 'esc_attr' )->returnArg();
        Functions\when( 'esc_url' )->returnArg();
        Functions\when( 'esc_textarea' )->returnArg();
        Functions\when( 'esc_html_e' )->alias( function ( $text ) { echo $text; } );
        Functions\when( 'esc_attr_e' )->alias( function ( $text ) { echo $text; } );
        Functions\when( 'absint' )->alias( function ( $v ) { return abs( (int) $v ); } );
        Functions\when( 'sanitize_text_field' )->returnArg();
        Functions\when( 'wp_unslash' )->returnArg();
        Functions\when( 'FreeFormCertificate\Core\sanitize_text_field' )->returnArg();
        Functions\when( 'FreeFormCertificate\Core\wp_unslash' )->returnArg();
        Functions\when( 'admin_url' )->justReturn( 'https://example.com/wp-admin/' );
        Functions\when( 'current_user_can' )->justReturn( true );
        Functions\when( 'wp_cache_get' )->justReturn( false );
        Functions\when( 'wp_cache_set' )->justReturn( true );
        Functions\when( 'wp_parse_args' )->alias( function ( $args, $defaults = array() ) {
            return array_merge( $defaults, (array) $args );
        } );
        Functions\when( 'sanitize_sql_orderby' )->returnArg();
        Functions\when( 'sanitize_key' )->returnArg();

        if ( ! defined( 'ABSPATH' ) ) {
            define( 'ABSPATH', '/tmp/' );
        }

        global $wpdb;
        $wpdb = Mockery::mock( 'wpdb' )->makePartial();
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive( 'prepare' )->andReturnUsing( function () { return func_get_arg(0); } )->byDefault();
        $wpdb->shouldReceive( 'get_results' )->andReturn( array() )->byDefault();
        $wpdb->shouldReceive( 'get_var' )->andReturn( '0' )->byDefault();
        $wpdb->shouldReceive( 'get_row' )->andReturn( null )->byDefault();
    }

    protected function tearDown(): void {
        unset( $_GET['action'], $_GET['id'], $_GET['message'], $_GET['page'] );
        Monkey\tearDown();
        parent::tearDown();
    }

    // ==================================================================
    // Constructor
    // ==================================================================

    public function test_constructor_creates_instance(): void {
        $page = new AudienceAdminAudience( 'ffc-scheduling' );
        $this->assertInstanceOf( AudienceAdminAudience::class, $page );
    }

    // ==================================================================
    // handle_actions() — no permission
    // ==================================================================

    public function test_handle_actions_returns_early_without_permission(): void {
        Functions\when( 'current_user_can' )->justReturn( false );

        $page = new AudienceAdminAudience( 'ffc-scheduling' );
        $page->handle_actions();
        $this->assertTrue( true );
    }

    // ==================================================================
    // handle_actions() — with message
    // ==================================================================

    public function test_handle_actions_shows_feedback_message(): void {
        $_GET['message'] = 'created';
        $_GET['page'] = 'ffc-scheduling-audiences';

        Functions\when( 'add_settings_error' )->justReturn( true );

        $page = new AudienceAdminAudience( 'ffc-scheduling' );
        $page->handle_actions();
        $this->assertTrue( true );
    }

    // ==================================================================
    // render_page() — default list
    // ==================================================================

    public function test_render_page_renders_list_by_default(): void {
        Functions\when( 'settings_errors' )->justReturn( '' );
        Functions\when( 'wp_nonce_url' )->justReturn( '/' );
        Functions\when( 'wp_trim_words' )->returnArg();

        $page = new AudienceAdminAudience( 'ffc-scheduling' );
        ob_start();
        $page->render_page();
        $output = ob_get_clean();

        $this->assertStringContainsString( 'wrap', $output );
    }

    // ==================================================================
    // render_list() — hierarchical rows (recursive)
    // ==================================================================

    public function test_render_list_renders_hierarchical_rows(): void {
        Functions\when( 'settings_errors' )->justReturn( '' );
        Functions\when( 'wp_nonce_url' )->justReturn( '/' );

        $child  = (object) array(
            'id'       => 2,
            'name'     => 'Child Aud',
            'color'    => '#00ff00',
            'status'   => 'inactive',
            'children' => array(),
        );
        $parent = (object) array(
            'id'       => 1,
            'name'     => 'Parent Aud',
            'color'    => '#ff0000',
            'status'   => 'active',
            'children' => array( $child ),
        );

        $repo = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceRepository' );
        $repo->shouldReceive( 'get_hierarchical' )->andReturn( array( $parent ) );
        $repo->shouldReceive( 'get_member_count' )->andReturnUsing(
            static fn( $id, $recursive = false ) => $recursive ? 10 : 4
        );

        $page = new AudienceAdminAudience( 'ffc-scheduling' );
        ob_start();
        $page->render_page();
        $output = ob_get_clean();

        $this->assertStringContainsString( 'Parent Aud', $output );
        $this->assertStringContainsString( 'Child Aud', $output );
        // Parent has children: total (10) shown alongside direct (4).
        $this->assertStringContainsString( '(10)', $output );
        // Active parent shows Deactivate; inactive child shows Delete.
        $this->assertStringContainsString( 'Deactivate', $output );
        $this->assertStringContainsString( 'Delete', $output );
    }

    // ==================================================================
    // render_form()
    // ==================================================================

    public function test_render_form_edit_renders_custom_fields_section(): void {
        $_GET['action'] = 'edit';
        $_GET['id']     = '5';
        Functions\when( 'settings_errors' )->justReturn( '' );
        Functions\when( 'wp_nonce_field' )->justReturn( '' );
        Functions\when( 'selected' )->justReturn( '' );
        Functions\when( 'submit_button' )->justReturn( '' );
        Functions\when( 'current_user_can' )->justReturn( true ); // view + manage caps.

        $audience = (object) array(
            'id'              => 5,
            'name'            => 'Grp',
            'color'           => '#123456',
            'status'          => 'active',
            'parent_id'       => null,
            'allow_self_join' => 0,
        );

        $repo = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceRepository' );
        $repo->shouldReceive( 'get_by_id' )->with( 5 )->andReturn( $audience );
        $repo->shouldReceive( 'get_possible_parents' )->andReturn( array() );
        $repo->shouldReceive( 'get_children' )->with( 5 )->andReturn( array() );

        // Real CustomFieldRepository (for FIELD_TYPES const) returns no fields
        // via the mocked $wpdb (get_results -> []).
        $seeder = Mockery::mock( 'alias:FreeFormCertificate\Reregistration\ReregistrationStandardFieldsSeeder' );
        $seeder->shouldReceive( 'seed_for_audience' )->andReturn( 0 );
        $seeder->shouldReceive( 'get_group_labels' )->andReturn( array() );

        $page = new AudienceAdminAudience( 'ffc-scheduling' );
        ob_start();
        $page->render_page();
        $output = ob_get_clean();

        $this->assertStringContainsString( 'Reregistration Fields', $output );
        $this->assertStringContainsString( 'ffc-add-custom-field', $output );
        unset( $_GET['action'], $_GET['id'] );
    }

    public function test_custom_fields_section_read_only_without_manage(): void {
        // view cap present, manage cap absent -> read-only render path.
        Functions\when( 'current_user_can' )->alias(
            static fn( $cap ) => 'ffc_view_custom_fields' === $cap
        );

        $repo = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceRepository' );
        $repo->shouldReceive( 'get_children' )->with( 5 )->andReturn( array() );

        $seeder = Mockery::mock( 'alias:FreeFormCertificate\Reregistration\ReregistrationStandardFieldsSeeder' );
        $seeder->shouldReceive( 'seed_for_audience' )->andReturn( 0 );
        $seeder->shouldReceive( 'get_group_labels' )->andReturn( array() );

        ob_start();
        \FreeFormCertificate\Audience\AudienceAdminAudienceRenderer::render_custom_fields_section( 5 );
        $output = ob_get_clean();

        $this->assertStringContainsString( 'Read-only', $output );
    }

    public function test_render_custom_field_row_custom_select_field(): void {
        Functions\when( 'selected' )->justReturn( '' );
        Functions\when( 'wp_json_encode' )->alias( static fn( $v ) => json_encode( $v ) );

        $field = (object) array(
            'id'                => 12,
            'field_label'       => 'My Select',
            'field_type'        => 'select',
            'field_key'         => 'my_select',
            'field_group'       => 'extra',
            'field_profile_key' => '',
            'field_mask'        => '',
            'field_source'      => 'custom',
            'is_sensitive'      => 0,
            'is_required'       => 1,
            'is_active'         => 1,
            'field_options'     => json_encode( array( 'choices' => array( 'A', 'B' ), 'help_text' => 'pick one' ) ),
            'validation_rules'  => json_encode( array( 'format' => 'email' ) ),
        );

        ob_start();
        \FreeFormCertificate\Audience\AudienceAdminAudienceRenderer::render_custom_field_row( $field, array( 'text', 'select', 'dependent_select' ), array() );
        $output = ob_get_clean();

        $this->assertStringContainsString( 'My Select', $output );
        $this->assertStringContainsString( 'A', $output );
        // Custom field is deletable.
        $this->assertStringContainsString( 'ffc-field-delete', $output );
    }

    public function test_render_custom_field_row_standard_locked_field(): void {
        Functions\when( 'selected' )->justReturn( '' );
        Functions\when( 'wp_json_encode' )->alias( static fn( $v ) => json_encode( $v ) );

        $field = (object) array(
            'id'                => 3,
            'field_label'       => 'CPF',
            'field_type'        => 'text',
            'field_key'         => 'cpf',
            'field_group'       => '',
            'field_profile_key' => 'cpf',
            'field_mask'        => 'cpf',
            'field_source'      => 'standard',
            'is_sensitive'      => 1,
            'is_required'       => 1,
            'is_active'         => 1,
            'field_options'     => null,
            'validation_rules'  => null,
        );

        ob_start();
        \FreeFormCertificate\Audience\AudienceAdminAudienceRenderer::render_custom_field_row( $field, array( 'text' ), array() );
        $output = ob_get_clean();

        $this->assertStringContainsString( 'CPF', $output );
        $this->assertStringContainsString( 'disabled', $output );
        // Standard field has no delete button.
        $this->assertStringNotContainsString( 'ffc-field-delete', $output );
    }

    public function test_render_form_edit_with_breadcrumb_and_custom_fields(): void {
        $_GET['action'] = 'edit';
        $_GET['id']     = '5';
        Functions\when( 'settings_errors' )->justReturn( '' );
        Functions\when( 'wp_nonce_field' )->justReturn( '' );
        Functions\when( 'selected' )->justReturn( '' );
        Functions\when( 'submit_button' )->justReturn( '' );

        $audience = (object) array(
            'id'              => 5,
            'name'            => 'Sub Group',
            'color'           => '#123456',
            'status'          => 'active',
            'parent_id'       => 1,
            'allow_self_join' => 1,
        );

        $repo = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceRepository' );
        $repo->shouldReceive( 'get_by_id' )->with( 5 )->andReturn( $audience );
        $repo->shouldReceive( 'get_possible_parents' )->with( 5 )->andReturn(
            array( (object) array( 'id' => 1, 'name' => 'Top', 'depth' => 0 ) )
        );
        $repo->shouldReceive( 'get_ancestors' )->with( 5 )->andReturn(
            array( (object) array( 'id' => 1, 'name' => 'Top', 'color' => '#aaa' ) )
        );
        // render_custom_fields_section: no view/manage cap -> early return.
        Functions\when( 'current_user_can' )->justReturn( false );

        $page = new AudienceAdminAudience( 'ffc-scheduling' );
        ob_start();
        $page->render_page();
        $output = ob_get_clean();

        $this->assertStringContainsString( 'Sub Group', $output );
        $this->assertStringContainsString( 'ffc-breadcrumb', $output );
        $this->assertStringContainsString( 'Top', $output );
        unset( $_GET['action'], $_GET['id'] );
    }

    public function test_render_form_missing_audience_dies(): void {
        $_GET['action'] = 'edit';
        $_GET['id']     = '99';
        Functions\when( 'wp_die' )->alias( static function ( $m ) { throw new \RuntimeException( (string) $m ); } );

        $repo = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceRepository' );
        $repo->shouldReceive( 'get_by_id' )->with( 99 )->andReturn( null );

        $page = new AudienceAdminAudience( 'ffc-scheduling' );
        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( 'Audience not found' );
        // render_page() echoes the admin page wrapper before it detects the
        // missing record and calls wp_die(); capture and discard that output
        // so the markup doesn't leak into PHPUnit's stdout (the assertion is
        // on the wp_die exception, not the buffer).
        ob_start();
        try {
            $page->render_page();
        } finally {
            ob_end_clean();
            unset( $_GET['action'], $_GET['id'] );
        }
    }

    // ==================================================================
    // render_members()
    // ==================================================================

    public function test_render_members_lists_users(): void {
        $_GET['action'] = 'members';
        $_GET['id']     = '5';
        Functions\when( 'settings_errors' )->justReturn( '' );
        Functions\when( 'wp_nonce_field' )->justReturn( '' );
        Functions\when( 'wp_nonce_url' )->justReturn( '/' );
        Functions\when( 'submit_button' )->justReturn( '' );
        Functions\when( 'get_user_by' )->alias(
            static fn( $field, $id ) => (object) array( 'ID' => $id, 'display_name' => 'User' . $id, 'user_email' => $id . '@e.com' )
        );

        $repo = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceRepository' );
        $repo->shouldReceive( 'get_by_id' )->with( 5 )->andReturn( (object) array( 'id' => 5, 'name' => 'Grp' ) );
        $repo->shouldReceive( 'get_members' )->with( 5 )->andReturn( array( 7, 8 ) );

        $page = new AudienceAdminAudience( 'ffc-scheduling' );
        ob_start();
        $page->render_page();
        $output = ob_get_clean();

        $this->assertStringContainsString( 'User7', $output );
        $this->assertStringContainsString( '8@e.com', $output );
        unset( $_GET['action'], $_GET['id'] );
    }

    public function test_render_members_missing_audience_dies(): void {
        $_GET['action'] = 'members';
        $_GET['id']     = '99';
        Functions\when( 'wp_die' )->alias( static function ( $m ) { throw new \RuntimeException( (string) $m ); } );

        $repo = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceRepository' );
        $repo->shouldReceive( 'get_by_id' )->with( 99 )->andReturn( null );

        $page = new AudienceAdminAudience( 'ffc-scheduling' );
        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( 'Audience not found' );
        // render_page() echoes the admin page wrapper before it detects the
        // missing record and calls wp_die(); capture and discard that output
        // so the markup doesn't leak into PHPUnit's stdout (the assertion is
        // on the wp_die exception, not the buffer).
        ob_start();
        try {
            $page->render_page();
        } finally {
            ob_end_clean();
            unset( $_GET['action'], $_GET['id'] );
        }
    }

    // ==================================================================
    // handle_actions() — save
    // ==================================================================

    public function test_handle_actions_creates_audience_and_redirects(): void {
        Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
        Functions\when( 'admin_url' )->returnArg();
        Functions\when( 'wp_safe_redirect' )->alias( static function ( $u ) { throw new \RuntimeException( 'redirect:' . $u ); } );

        $_POST = array(
            'ffc_action'         => 'save_audience',
            'ffc_audience_nonce' => 'n',
            'audience_id'        => '0',
            'audience_name'      => 'Brand New',
            'audience_color'     => '#abcabc',
            'audience_parent'    => '',
            'audience_status'    => 'active',
            'audience_self_join' => '1',
        );

        $repo = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceRepository' );
        $repo->shouldReceive( 'create' )->once()->with(
            Mockery::on(
                static fn( $d ) => 'Brand New' === $d['name'] && null === $d['parent_id'] && 1 === $d['allow_self_join']
            )
        )->andReturn( 50 );
        $repo->shouldReceive( 'cascade_self_join' )->once()->with( 50, 1 )->andReturn( true );

        $page = new AudienceAdminAudience( 'ffc-scheduling' );
        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( 'message=created' );
        $page->handle_actions();
    }

    public function test_handle_actions_updates_audience_and_cascades(): void {
        Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
        Functions\when( 'add_settings_error' )->justReturn( true );

        $_POST = array(
            'ffc_action'         => 'save_audience',
            'ffc_audience_nonce' => 'n',
            'audience_id'        => '5',
            'audience_name'      => 'Edited',
            'audience_color'     => '#000000',
            'audience_parent'    => '',
            'audience_status'    => 'active',
            'audience_self_join' => '0',
        );

        $repo = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceRepository' );
        $repo->shouldReceive( 'update' )->once()->with( 5, Mockery::type( 'array' ) )->andReturn( true );
        $repo->shouldReceive( 'cascade_self_join' )->once()->with( 5, 0 )->andReturn( true );

        $page = new AudienceAdminAudience( 'ffc-scheduling' );
        $page->handle_actions();
        $this->assertTrue( true );
    }

    public function test_handle_actions_update_with_parent_skips_cascade(): void {
        Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
        Functions\when( 'add_settings_error' )->justReturn( true );

        $_POST = array(
            'ffc_action'         => 'save_audience',
            'ffc_audience_nonce' => 'n',
            'audience_id'        => '5',
            'audience_name'      => 'Child',
            'audience_color'     => '#000000',
            'audience_parent'    => '3',
            'audience_status'    => 'active',
        );

        $repo = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceRepository' );
        $repo->shouldReceive( 'update' )->once()->with( 5, Mockery::on( static fn( $d ) => 3 === $d['parent_id'] ) )->andReturn( true );
        $repo->shouldNotReceive( 'cascade_self_join' );

        $page = new AudienceAdminAudience( 'ffc-scheduling' );
        $page->handle_actions();
        $this->assertTrue( true );
    }

    public function test_handle_actions_save_aborts_on_bad_nonce(): void {
        Functions\when( 'wp_verify_nonce' )->justReturn( false );
        $_POST = array( 'ffc_action' => 'save_audience', 'ffc_audience_nonce' => 'bad' );

        $repo = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceRepository' );
        $repo->shouldNotReceive( 'create' );
        $repo->shouldNotReceive( 'update' );

        $page = new AudienceAdminAudience( 'ffc-scheduling' );
        $page->handle_actions();
        $this->assertTrue( true );
    }

    // ==================================================================
    // handle_actions() — add members
    // ==================================================================

    public function test_handle_actions_adds_members(): void {
        Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
        Functions\when( 'add_settings_error' )->justReturn( true );

        $_POST = array(
            'ffc_action'            => 'add_members',
            'ffc_add_members_nonce' => 'n',
            'audience_id'           => '5',
            'user_ids'              => '7,8,9',
        );

        $repo = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceRepository' );
        $repo->shouldReceive( 'bulk_add_members' )->once()->with( 5, array( 7, 8, 9 ) )->andReturn( 3 );

        $page = new AudienceAdminAudience( 'ffc-scheduling' );
        $page->handle_actions();
        $this->assertTrue( true );
    }

    public function test_handle_actions_add_members_bad_nonce_aborts(): void {
        Functions\when( 'wp_verify_nonce' )->justReturn( false );
        $_POST = array( 'ffc_action' => 'add_members', 'ffc_add_members_nonce' => 'bad' );

        $repo = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceRepository' );
        $repo->shouldNotReceive( 'bulk_add_members' );

        $page = new AudienceAdminAudience( 'ffc-scheduling' );
        $page->handle_actions();
        $this->assertTrue( true );
    }

    // ==================================================================
    // handle_actions() — remove member
    // ==================================================================

    public function test_handle_actions_removes_member_and_redirects(): void {
        $_GET = array( 'remove_user' => '7', 'id' => '5', '_wpnonce' => 'n' );
        Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
        Functions\when( 'admin_url' )->returnArg();
        Functions\when( 'wp_safe_redirect' )->alias( static function ( $u ) { throw new \RuntimeException( 'redirect:' . $u ); } );

        $repo = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceRepository' );
        $repo->shouldReceive( 'remove_member' )->once()->with( 5, 7 )->andReturn( true );

        $page = new AudienceAdminAudience( 'ffc-scheduling' );
        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( 'message=member_removed' );
        $page->handle_actions();
    }

    // ==================================================================
    // handle_actions() — deactivate / delete
    // ==================================================================

    public function test_handle_actions_deactivates_audience(): void {
        $_GET = array(
            'action'   => 'deactivate',
            'id'       => '5',
            'page'     => 'ffc-scheduling-audiences',
            '_wpnonce' => 'n',
        );
        Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
        Functions\when( 'admin_url' )->returnArg();
        Functions\when( 'wp_safe_redirect' )->alias( static function ( $u ) { throw new \RuntimeException( 'redirect:' . $u ); } );

        $repo = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceRepository' );
        $repo->shouldReceive( 'update' )->once()->with( 5, array( 'status' => 'inactive' ) )->andReturn( true );

        $page = new AudienceAdminAudience( 'ffc-scheduling' );
        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( 'message=deactivated' );
        $page->handle_actions();
    }

    public function test_handle_actions_deletes_inactive_audience(): void {
        $_GET = array(
            'action'   => 'delete',
            'id'       => '5',
            'page'     => 'ffc-scheduling-audiences',
            '_wpnonce' => 'n',
        );
        Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
        Functions\when( 'admin_url' )->returnArg();
        Functions\when( 'wp_safe_redirect' )->alias( static function ( $u ) { throw new \RuntimeException( 'redirect:' . $u ); } );

        $repo = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceRepository' );
        $repo->shouldReceive( 'get_by_id' )->with( 5 )->andReturn( (object) array( 'status' => 'inactive' ) );
        $repo->shouldReceive( 'delete' )->once()->with( 5 )->andReturn( true );

        $page = new AudienceAdminAudience( 'ffc-scheduling' );
        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( 'message=deleted' );
        $page->handle_actions();
    }

    public function test_handle_actions_delete_denied_without_cap(): void {
        $_GET = array(
            'action' => 'delete',
            'id'     => '5',
            'page'   => 'ffc-scheduling-audiences',
        );
        Functions\when( 'current_user_can' )->alias(
            static fn( $cap ) => 'ffc_manage_audiences' === $cap
        );
        Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
        Functions\when( 'wp_die' )->alias( static function ( $m ) { throw new \RuntimeException( (string) $m ); } );
        Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceRepository' );

        $page = new AudienceAdminAudience( 'ffc-scheduling' );
        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( 'permission to delete' );
        $page->handle_actions();
    }
}
