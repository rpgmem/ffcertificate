<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Admin\CPT;

/**
 * Tests for CPT: constructor hook registration, register_form_cpt,
 * add_duplicate_link, handle_form_duplication, and translate_views.
 *
 * @covers \FreeFormCertificate\Admin\CPT
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class CptTest extends TestCase {

    use MockeryPHPUnitIntegration;

    /** @var Mockery\MockInterface Alias mock for Utils */
    private $utils_mock;
    private $caps_mock;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        // Common WP function stubs
        Functions\when( '__' )->returnArg();
        Functions\when( 'esc_html__' )->returnArg();
        Functions\when( 'esc_html' )->returnArg();
        Functions\when( 'esc_attr' )->returnArg();
        Functions\when( 'esc_attr__' )->returnArg();
        Functions\when( 'esc_html_e' )->alias( function ( $t ) { echo $t; } );
        Functions\when( 'esc_attr_e' )->alias( function ( $t ) { echo $t; } );
        Functions\when( '_x' )->returnArg();
        Functions\when( 'add_action' )->justReturn( true );
        Functions\when( 'add_filter' )->justReturn( true );
        Functions\when( 'esc_url' )->returnArg();

        // Utils alias mock
        $this->utils_mock = Mockery::mock( 'alias:\FreeFormCertificate\Core\Utils' );
        $this->caps_mock  = Mockery::mock( 'alias:\FreeFormCertificate\Core\Capabilities' );
        $this->caps_mock->shouldReceive( 'current_user_can_manage' )->andReturn( true )->byDefault();
        $this->utils_mock->shouldReceive( 'debug_log' )->byDefault();
        $this->utils_mock->shouldReceive( 'get_user_ip' )->andReturn( '127.0.0.1' )->byDefault();
        $this->utils_mock->shouldReceive( 'truncate' )->andReturnUsing( function ( $str ) {
            return $str;
        } )->byDefault();
    }

    protected function tearDown(): void {
        unset( $_GET['post'] );
        Monkey\tearDown();
        parent::tearDown();
    }

    // ==================================================================
    // Constructor
    // ==================================================================

    public function test_constructor_registers_init_action(): void {
        $registered = array();
        Functions\when( 'add_action' )->alias( function ( $hook, $callback ) use ( &$registered ) {
            $registered[] = $hook;
        } );
        Functions\when( 'add_filter' )->alias( function ( $hook, $callback ) use ( &$registered ) {
            $registered[] = $hook;
        } );

        new CPT();

        $this->assertContains( 'init', $registered );
    }

    public function test_constructor_registers_post_row_actions_filter(): void {
        $registered = array();
        Functions\when( 'add_action' )->alias( function ( $hook, $callback ) use ( &$registered ) {
            $registered[] = $hook;
        } );
        Functions\when( 'add_filter' )->alias( function ( $hook, $callback ) use ( &$registered ) {
            $registered[] = $hook;
        } );

        new CPT();

        $this->assertContains( 'post_row_actions', $registered );
    }

    public function test_constructor_registers_duplicate_action(): void {
        $registered = array();
        Functions\when( 'add_action' )->alias( function ( $hook, $callback ) use ( &$registered ) {
            $registered[] = $hook;
        } );
        Functions\when( 'add_filter' )->alias( function ( $hook, $callback ) use ( &$registered ) {
            $registered[] = $hook;
        } );

        new CPT();

        $this->assertContains( 'admin_action_ffc_duplicate_form', $registered );
        // Submit-box "Duplicate" link mirrors the row action.
        $this->assertContains( 'post_submitbox_misc_actions', $registered );
    }

    public function test_constructor_registers_views_filter(): void {
        $registered = array();
        Functions\when( 'add_action' )->alias( function ( $hook, $callback ) use ( &$registered ) {
            $registered[] = $hook;
        } );
        Functions\when( 'add_filter' )->alias( function ( $hook, $callback ) use ( &$registered ) {
            $registered[] = $hook;
        } );

        new CPT();

        $this->assertContains( 'views_edit-ffc_form', $registered );
    }

    // ==================================================================
    // register_form_cpt()
    // ==================================================================

    public function test_register_form_cpt_calls_register_post_type(): void {
        $captured_type = null;
        $captured_args = null;

        Functions\expect( 'register_post_type' )
            ->once()
            ->andReturnUsing( function ( $type, $args ) use ( &$captured_type, &$captured_args ) {
                $captured_type = $type;
                $captured_args = $args;
            } );

        $cpt = new CPT();
        $cpt->register_form_cpt();

        $this->assertSame( 'ffc_form', $captured_type );
        $this->assertFalse( $captured_args['public'] );
        $this->assertTrue( $captured_args['show_ui'] );
        $this->assertTrue( $captured_args['show_in_menu'] );
        $this->assertSame( 'dashicons-feedback', $captured_args['menu_icon'] );
        $this->assertSame( array( 'title' ), $captured_args['supports'] );
        $this->assertFalse( $captured_args['has_archive'] );
        $this->assertSame( 'post', $captured_args['capability_type'] );
    }

    // ==================================================================
    // add_duplicate_link()
    // ==================================================================

    public function test_add_duplicate_link_returns_unmodified_actions_for_non_ffc_post(): void {
        $post = (object) array(
            'ID'        => 1,
            'post_type' => 'post',
        );

        $actions = array( 'edit' => '<a href="#">Edit</a>' );

        $cpt = new CPT();
        $result = $cpt->add_duplicate_link( $actions, $post );

        $this->assertSame( $actions, $result );
        $this->assertArrayNotHasKey( 'duplicate', $result );
    }

    public function test_add_duplicate_link_returns_unmodified_actions_when_user_cannot_manage(): void {
        $this->caps_mock->shouldReceive( 'current_user_can_manage' )->andReturn( false );

        $post = (object) array(
            'ID'        => 1,
            'post_type' => 'ffc_form',
        );

        $actions = array( 'edit' => '<a href="#">Edit</a>' );

        $cpt = new CPT();
        $result = $cpt->add_duplicate_link( $actions, $post );

        $this->assertSame( $actions, $result );
        $this->assertArrayNotHasKey( 'duplicate', $result );
    }

    public function test_add_duplicate_link_adds_duplicate_action_for_ffc_form(): void {
        Functions\when( 'wp_nonce_url' )->returnArg();
        Functions\when( 'admin_url' )->returnArg();

        $post = (object) array(
            'ID'        => 42,
            'post_type' => 'ffc_form',
        );

        $actions = array( 'edit' => '<a href="#">Edit</a>' );

        $cpt = new CPT();
        $result = $cpt->add_duplicate_link( $actions, $post );

        $this->assertArrayHasKey( 'duplicate', $result );
        $this->assertStringContainsString( 'Duplicate', $result['duplicate'] );
        $this->assertStringContainsString( '<a href=', $result['duplicate'] );
    }

    // ==================================================================
    // render_duplicate_submitbox_link()
    // ==================================================================

    /** Build a WP_Post stub with the given attributes for the submitbox tests. */
    private function make_post( string $post_type, string $post_status = 'publish', int $id = 42 ): \WP_Post {
        $post              = new \WP_Post();
        $post->ID          = $id;
        $post->post_type   = $post_type;
        $post->post_status = $post_status;
        return $post;
    }

    public function test_render_duplicate_submitbox_link_outputs_nothing_for_non_ffc_post(): void {
        ob_start();
        ( new CPT() )->render_duplicate_submitbox_link( $this->make_post( 'post' ) );
        $this->assertSame( '', ob_get_clean() );
    }

    public function test_render_duplicate_submitbox_link_outputs_nothing_when_user_cannot_manage(): void {
        $this->caps_mock->shouldReceive( 'current_user_can_manage' )->andReturn( false );

        ob_start();
        ( new CPT() )->render_duplicate_submitbox_link( $this->make_post( 'ffc_form' ) );
        $this->assertSame( '', ob_get_clean() );
    }

    public function test_render_duplicate_submitbox_link_outputs_nothing_for_auto_draft(): void {
        // Nothing meaningful to copy yet — mirror the row-action contract.
        ob_start();
        ( new CPT() )->render_duplicate_submitbox_link( $this->make_post( 'ffc_form', 'auto-draft' ) );
        $this->assertSame( '', ob_get_clean() );
    }

    public function test_render_duplicate_submitbox_link_renders_nonce_link_for_saved_ffc_form(): void {
        Functions\when( 'wp_nonce_url' )->returnArg();
        Functions\when( 'admin_url' )->returnArg();

        ob_start();
        ( new CPT() )->render_duplicate_submitbox_link( $this->make_post( 'ffc_form', 'publish' ) );
        $html = (string) ob_get_clean();

        $this->assertStringContainsString( 'misc-pub-section', $html );
        $this->assertStringContainsString( 'ffc-duplicate-action', $html );
        $this->assertStringContainsString( 'action=ffc_duplicate_form&post=42', $html );
        $this->assertStringContainsString( 'Duplicate this form', $html );
    }

    // ==================================================================
    // handle_form_duplication()
    // ==================================================================

    public function test_handle_form_duplication_dies_when_user_cannot_manage(): void {
        $this->caps_mock->shouldReceive( 'current_user_can_manage' )->andReturn( false );

        Functions\when( 'wp_die' )->alias( function ( $message ) {
            throw new \RuntimeException( 'wp_die: ' . $message );
        } );
        Functions\when( 'get_current_user_id' )->justReturn( 1 );

        $cpt = new CPT();

        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessageMatches( '/permission/' );

        $cpt->handle_form_duplication();
    }

    public function test_handle_form_duplication_dies_for_invalid_post(): void {
        $_GET['post'] = '999';

        Functions\when( 'wp_die' )->alias( function ( $message ) {
            throw new \RuntimeException( 'wp_die: ' . $message );
        } );
        Functions\when( 'absint' )->alias( function ( $v ) {
            return abs( (int) $v );
        } );
        Functions\when( 'wp_unslash' )->returnArg();
        Functions\when( 'check_admin_referer' )->justReturn( true );
        Functions\when( 'get_post' )->justReturn( null );
        Functions\when( 'get_current_user_id' )->justReturn( 1 );

        $cpt = new CPT();

        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessageMatches( '/Invalid post/' );

        $cpt->handle_form_duplication();
    }

    public function test_handle_form_duplication_copies_all_per_form_metas(): void {
        // 6.6.10 regression lock — the duplicator's copy list must include
        // EVERY per-form meta key that FormEditorSaveHandler persists,
        // minus the explicitly-excluded counters / hashes / audit logs
        // documented in the source comment.
        //
        // Pre-6.6.10 the four CSV-download sub-feature toggles below were
        // missing, so a duplicate forgot whether the admin had turned
        // off the download / preview / start-early features or turned ON
        // the extend-end feature.
        $_GET['post'] = '10';

        Functions\when( 'absint' )->alias( function ( $v ) {
            return abs( (int) $v );
        } );
        Functions\when( 'wp_unslash' )->returnArg();
        Functions\when( 'check_admin_referer' )->justReturn( true );
        Functions\when( 'get_current_user_id' )->justReturn( 1 );
        // Real production flow calls `exit;` after wp_safe_redirect. Throw
        // a sentinel exception from the stub to abort the call and let us
        // assert on the side effects captured in $written_meta below.
        Functions\when( 'wp_safe_redirect' )->alias( function ( $url ) {
            throw new \RuntimeException( 'redirect:' . $url );
        } );
        Functions\when( 'admin_url' )->alias( function ( $path = '' ) {
            return 'https://example.com/wp-admin/' . ltrim( $path, '/' );
        } );

        $source_post = (object) array(
            'ID'         => 10,
            'post_type'  => 'ffc_form',
            'post_title' => 'Original',
        );
        Functions\when( 'get_post' )->justReturn( $source_post );

        // Source has every per-form meta set to a recognisable value.
        // The duplicator iterates a hard-coded $config_metas list; empty
        // values are skipped per the source code's
        //   if ( '' === $value || array() === $value || null === $value ) continue;
        // guard, so we give every key a non-empty value below.
        $source_meta = array(
            '_ffc_form_fields'                     => array( 'field-a' ),
            '_ffc_form_config'                     => array( 'k' => 'v' ),
            '_ffc_form_bg'                         => 'https://example.com/bg.png',
            '_ffc_geofence_config'                 => array( 'enabled' => '1' ),
            '_ffc_csv_public_enabled'              => '1',
            '_ffc_csv_public_download_enabled'     => '0',
            '_ffc_csv_public_preview_enabled'      => '0',
            '_ffc_csv_public_start_early_enabled'  => '0',
            '_ffc_csv_public_extend_end_enabled'   => '1',
            '_ffc_csv_public_limit'                => 99,
            '_ffc_csv_public_cpf_mode'             => 'audit',
            '_ffc_csv_public_cpf_whitelist'        => "12345678901\n98765432100",
            '_ffc_device_limit_enabled'            => '1',
            '_ffc_device_limit_max'                => '3',
            '_ffc_device_match_threshold'          => '0.7',
            '_ffc_device_limit_message'            => 'Bloqueado',
            // Excluded by design — must NOT appear on the duplicate.
            '_ffc_csv_public_hash'                 => 'OLD-HASH',
            '_ffc_csv_public_count'                => 42,
        );

        Functions\when( 'get_post_meta' )->alias( function ( $post_id, $key, $single = false ) use ( $source_meta ) {
            return $source_meta[ $key ] ?? '';
        } );

        Functions\when( 'wp_insert_post' )->justReturn( 999 );

        $written_meta = array();
        Functions\when( 'update_post_meta' )->alias( function ( $post_id, $key, $value ) use ( &$written_meta ) {
            $written_meta[ $key ] = $value;
            return true;
        } );

        $cpt = new CPT();

        try {
            $cpt->handle_form_duplication();
        } catch ( \Throwable $e ) {
            // The real flow calls exit; in tests admin_url + wp_safe_redirect
            // are stubbed to no-op so control returns normally. Re-raise
            // anything we did not anticipate.
            if ( false === strpos( $e->getMessage(), 'redirect' ) ) {
                throw $e;
            }
        }

        // All expected metas copied verbatim.
        $expected_copied = array(
            '_ffc_form_fields',
            '_ffc_form_config',
            '_ffc_form_bg',
            '_ffc_geofence_config',
            '_ffc_csv_public_enabled',
            '_ffc_csv_public_download_enabled',
            '_ffc_csv_public_preview_enabled',
            '_ffc_csv_public_start_early_enabled',
            '_ffc_csv_public_extend_end_enabled',
            '_ffc_csv_public_limit',
            '_ffc_csv_public_cpf_mode',
            '_ffc_csv_public_cpf_whitelist',
            '_ffc_device_limit_enabled',
            '_ffc_device_limit_max',
            '_ffc_device_match_threshold',
            '_ffc_device_limit_message',
        );
        foreach ( $expected_copied as $key ) {
            $this->assertArrayHasKey( $key, $written_meta, "Duplicator should copy {$key}" );
            $this->assertSame( $source_meta[ $key ], $written_meta[ $key ], "Duplicate should carry original {$key} value" );
        }

        // Explicit security exclusions — hash and counter NEVER leak.
        $this->assertArrayNotHasKey( '_ffc_csv_public_hash', $written_meta, 'Hash must NOT be copied (security)' );
        $this->assertArrayNotHasKey( '_ffc_csv_public_count', $written_meta, 'Counter must NOT be copied (fresh start)' );
    }

    public function test_handle_form_duplication_dies_for_wrong_post_type(): void {
        $_GET['post'] = '10';

        Functions\when( 'wp_die' )->alias( function ( $message ) {
            throw new \RuntimeException( 'wp_die: ' . $message );
        } );
        Functions\when( 'absint' )->alias( function ( $v ) {
            return abs( (int) $v );
        } );
        Functions\when( 'wp_unslash' )->returnArg();
        Functions\when( 'check_admin_referer' )->justReturn( true );
        Functions\when( 'get_current_user_id' )->justReturn( 1 );

        $wrong_type_post = (object) array(
            'ID'         => 10,
            'post_type'  => 'page',
            'post_title' => 'Some Page',
        );
        Functions\when( 'get_post' )->justReturn( $wrong_type_post );

        $cpt = new CPT();

        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessageMatches( '/Invalid post/' );

        $cpt->handle_form_duplication();
    }

    // ==================================================================
    // handle_form_duplication() — meta-copy list (#366 Sprint 2 regression)
    // ==================================================================

    public function test_handle_form_duplication_copies_geofence_config_meta(): void {
        // The schedule-exception sub-keys added in #366 Sprint 2 live
        // INSIDE `_ffc_geofence_config`. If a future refactor were to
        // drop that meta from the duplication list, the new sub-keys
        // would silently fail to propagate — this test locks the
        // contract by reading the source of `handle_form_duplication`
        // and asserting the meta name is present.
        $source = file_get_contents(
            __DIR__ . '/../../includes/admin/class-ffc-cpt.php'
        );
        $this->assertNotFalse( $source, 'class-ffc-cpt.php must be readable' );

        // Pull the `$config_metas = array( ... );` block out of the
        // duplication handler and assert it includes our key. Using a
        // regex on the whole file keeps the assertion robust against
        // line-number drift while still being a real structural check.
        $this->assertMatchesRegularExpression(
            '/\$config_metas\s*=\s*array\(.*?_ffc_geofence_config.*?\);/s',
            $source,
            'handle_form_duplication must keep "_ffc_geofence_config" in the duplicated-metas list'
        );
    }

    // ==================================================================
    // translate_views()
    // ==================================================================

    public function test_translate_views_replaces_labels_in_known_views(): void {
        $views = array(
            'all'     => '<a href="edit.php" class="current">All <span class="count">(5)</span></a>',
            'publish' => '<a href="edit.php?status=publish">Published <span class="count">(3)</span></a>',
            'draft'   => '<a href="edit.php?status=draft">Draft <span class="count">(2)</span></a>',
        );

        $cpt = new CPT();
        $result = $cpt->translate_views( $views );

        // Since __ returns arg 1, the label text in the map for 'all' is 'All'
        // The regex replacement should place the mapped label before the <span
        $this->assertStringContainsString( 'All', $result['all'] );
        $this->assertStringContainsString( 'Published', $result['publish'] );
        $this->assertStringContainsString( 'Draft', $result['draft'] );
        $this->assertStringContainsString( '<span', $result['all'] );
    }

    public function test_translate_views_preserves_unknown_views(): void {
        $views = array(
            'custom_view' => '<a href="edit.php?status=custom">Custom <span class="count">(1)</span></a>',
        );

        $cpt = new CPT();
        $result = $cpt->translate_views( $views );

        // Unknown keys should not be altered
        $this->assertSame( $views['custom_view'], $result['custom_view'] );
    }

    public function test_translate_views_returns_empty_array_for_empty_input(): void {
        $cpt = new CPT();
        $result = $cpt->translate_views( array() );

        $this->assertSame( array(), $result );
    }
}
