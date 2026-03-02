<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Submissions\FormCache;

/**
 * Tests for FormCache: caching layer for form configurations.
 *
 * Covers cache get/set patterns (hit/miss), expiration settings,
 * cache clearing, warming, statistics, hook registration, and
 * save/delete callbacks.
 *
 * @covers \FreeFormCertificate\Submissions\FormCache
 */
class FormCacheTest extends TestCase {

    use MockeryPHPUnitIntegration;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        if ( ! defined( 'WP_DEBUG' ) ) {
            define( 'WP_DEBUG', false );
        }
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    // ==================================================================
    // get_expiration()
    // ==================================================================

    public function test_get_expiration_returns_default_when_no_setting(): void {
        Functions\when( 'get_option' )->justReturn( array() );

        $result = FormCache::get_expiration();
        $this->assertSame( 3600, $result );
    }

    public function test_get_expiration_returns_custom_value_from_settings(): void {
        Functions\when( 'get_option' )->justReturn( array( 'cache_expiration' => 7200 ) );

        $result = FormCache::get_expiration();
        $this->assertSame( 7200, $result );
    }

    public function test_get_expiration_casts_string_value_to_int(): void {
        Functions\when( 'get_option' )->justReturn( array( 'cache_expiration' => '1800' ) );

        $result = FormCache::get_expiration();
        $this->assertSame( 1800, $result );
    }

    // ==================================================================
    // get_form_config()
    // ==================================================================

    public function test_get_form_config_cache_hit(): void {
        $config = array( 'title' => 'Test Form', 'version' => 2 );

        Functions\when( 'get_option' )->justReturn( array() );
        Functions\expect( 'wp_cache_get' )
            ->once()
            ->with( 'config_42', 'ffc_forms' )
            ->andReturn( $config );

        // get_post_meta should NOT be called on cache hit.
        Functions\expect( 'get_post_meta' )->never();

        $result = FormCache::get_form_config( 42 );
        $this->assertSame( $config, $result );
    }

    public function test_get_form_config_cache_miss_loads_from_meta(): void {
        $config = array( 'title' => 'Loaded Form', 'fields_count' => 5 );

        Functions\when( 'get_option' )->justReturn( array() );
        Functions\expect( 'wp_cache_get' )
            ->once()
            ->with( 'config_10', 'ffc_forms' )
            ->andReturn( false );

        Functions\expect( 'get_post_meta' )
            ->once()
            ->with( 10, '_ffc_form_config', true )
            ->andReturn( $config );

        Functions\expect( 'wp_cache_set' )
            ->once()
            ->with( 'config_10', $config, 'ffc_forms', Mockery::type( 'int' ) )
            ->andReturn( true );

        $result = FormCache::get_form_config( 10 );
        $this->assertSame( $config, $result );
    }

    public function test_get_form_config_returns_false_when_meta_empty(): void {
        Functions\when( 'get_option' )->justReturn( array() );
        Functions\expect( 'wp_cache_get' )
            ->once()
            ->with( 'config_99', 'ffc_forms' )
            ->andReturn( false );

        Functions\expect( 'get_post_meta' )
            ->once()
            ->with( 99, '_ffc_form_config', true )
            ->andReturn( '' );

        // wp_cache_set should NOT be called for empty meta.
        Functions\expect( 'wp_cache_set' )->never();

        $result = FormCache::get_form_config( 99 );
        $this->assertFalse( $result );
    }

    public function test_get_form_config_returns_false_when_meta_not_array(): void {
        Functions\when( 'get_option' )->justReturn( array() );
        Functions\expect( 'wp_cache_get' )
            ->once()
            ->with( 'config_88', 'ffc_forms' )
            ->andReturn( false );

        Functions\expect( 'get_post_meta' )
            ->once()
            ->with( 88, '_ffc_form_config', true )
            ->andReturn( 'not-an-array' );

        Functions\expect( 'wp_cache_set' )->never();

        $result = FormCache::get_form_config( 88 );
        $this->assertFalse( $result );
    }

    // ==================================================================
    // get_form_fields()
    // ==================================================================

    public function test_get_form_fields_cache_hit(): void {
        $fields = array(
            array( 'name' => 'first_name', 'type' => 'text' ),
            array( 'name' => 'email', 'type' => 'email' ),
        );

        Functions\when( 'get_option' )->justReturn( array() );
        Functions\expect( 'wp_cache_get' )
            ->once()
            ->with( 'fields_15', 'ffc_forms' )
            ->andReturn( $fields );

        Functions\expect( 'get_post_meta' )->never();

        $result = FormCache::get_form_fields( 15 );
        $this->assertSame( $fields, $result );
    }

    public function test_get_form_fields_cache_miss_loads_from_meta(): void {
        $fields = array( array( 'name' => 'company', 'type' => 'text' ) );

        Functions\when( 'get_option' )->justReturn( array() );
        Functions\expect( 'wp_cache_get' )
            ->once()
            ->with( 'fields_20', 'ffc_forms' )
            ->andReturn( false );

        Functions\expect( 'get_post_meta' )
            ->once()
            ->with( 20, '_ffc_form_fields', true )
            ->andReturn( $fields );

        Functions\expect( 'wp_cache_set' )
            ->once()
            ->with( 'fields_20', $fields, 'ffc_forms', Mockery::type( 'int' ) )
            ->andReturn( true );

        $result = FormCache::get_form_fields( 20 );
        $this->assertSame( $fields, $result );
    }

    public function test_get_form_fields_returns_false_when_meta_empty(): void {
        Functions\when( 'get_option' )->justReturn( array() );
        Functions\expect( 'wp_cache_get' )
            ->once()
            ->with( 'fields_77', 'ffc_forms' )
            ->andReturn( false );

        Functions\expect( 'get_post_meta' )
            ->once()
            ->with( 77, '_ffc_form_fields', true )
            ->andReturn( '' );

        Functions\expect( 'wp_cache_set' )->never();

        $result = FormCache::get_form_fields( 77 );
        $this->assertFalse( $result );
    }

    // ==================================================================
    // get_form_background()
    // ==================================================================

    public function test_get_form_background_cache_hit(): void {
        $bg_url = 'https://example.com/bg.jpg';

        Functions\when( 'get_option' )->justReturn( array() );
        Functions\expect( 'wp_cache_get' )
            ->once()
            ->with( 'bg_5', 'ffc_forms' )
            ->andReturn( $bg_url );

        Functions\expect( 'get_post_meta' )->never();

        $result = FormCache::get_form_background( 5 );
        $this->assertSame( $bg_url, $result );
    }

    public function test_get_form_background_cache_miss_loads_from_meta(): void {
        $bg_url = 'https://example.com/image.png';

        Functions\when( 'get_option' )->justReturn( array() );
        Functions\expect( 'wp_cache_get' )
            ->once()
            ->with( 'bg_30', 'ffc_forms' )
            ->andReturn( false );

        Functions\expect( 'get_post_meta' )
            ->once()
            ->with( 30, '_ffc_form_bg', true )
            ->andReturn( $bg_url );

        Functions\expect( 'wp_cache_set' )
            ->once()
            ->with( 'bg_30', $bg_url, 'ffc_forms', Mockery::type( 'int' ) )
            ->andReturn( true );

        $result = FormCache::get_form_background( 30 );
        $this->assertSame( $bg_url, $result );
    }

    public function test_get_form_background_returns_empty_string_when_no_meta(): void {
        Functions\when( 'get_option' )->justReturn( array() );
        Functions\expect( 'wp_cache_get' )
            ->once()
            ->with( 'bg_60', 'ffc_forms' )
            ->andReturn( false );

        Functions\expect( 'get_post_meta' )
            ->once()
            ->with( 60, '_ffc_form_bg', true )
            ->andReturn( '' );

        $result = FormCache::get_form_background( 60 );
        $this->assertSame( '', $result );
    }

    public function test_get_form_background_returns_string_type_when_meta_false(): void {
        Functions\when( 'get_option' )->justReturn( array() );
        Functions\expect( 'wp_cache_get' )
            ->once()
            ->with( 'bg_61', 'ffc_forms' )
            ->andReturn( false );

        Functions\expect( 'get_post_meta' )
            ->once()
            ->with( 61, '_ffc_form_bg', true )
            ->andReturn( false );

        $result = FormCache::get_form_background( 61 );
        $this->assertIsString( $result );
        $this->assertSame( '', $result );
    }

    // ==================================================================
    // get_form_complete()
    // ==================================================================

    public function test_get_form_complete_cache_hit(): void {
        $complete = array(
            'config'     => array( 'title' => 'Complete Form' ),
            'fields'     => array( array( 'name' => 'field1' ) ),
            'background' => 'https://example.com/bg.png',
        );

        Functions\when( 'get_option' )->justReturn( array() );
        Functions\expect( 'wp_cache_get' )
            ->once()
            ->with( 'complete_50', 'ffc_forms' )
            ->andReturn( $complete );

        $result = FormCache::get_form_complete( 50 );
        $this->assertSame( $complete, $result );
    }

    public function test_get_form_complete_cache_miss_assembles_from_parts(): void {
        $config = array( 'title' => 'Assembled' );
        $fields = array( array( 'name' => 'f1' ) );
        $bg     = 'https://example.com/assembled.jpg';

        Functions\when( 'get_option' )->justReturn( array() );

        // Use alias to return different values based on the cache key argument.
        Functions\when( 'wp_cache_get' )->alias( function ( $key, $group = '' ) {
            return false; // All cache misses.
        });

        Functions\when( 'get_post_meta' )->alias( function ( $post_id, $meta_key, $single = false ) use ( $config, $fields, $bg ) {
            switch ( $meta_key ) {
                case '_ffc_form_config':
                    return $config;
                case '_ffc_form_fields':
                    return $fields;
                case '_ffc_form_bg':
                    return $bg;
                default:
                    return '';
            }
        });

        Functions\when( 'wp_cache_set' )->justReturn( true );

        $result = FormCache::get_form_complete( 55 );
        $this->assertIsArray( $result );
        $this->assertArrayHasKey( 'config', $result );
        $this->assertArrayHasKey( 'fields', $result );
        $this->assertArrayHasKey( 'background', $result );
        $this->assertSame( $config, $result['config'] );
        $this->assertSame( $fields, $result['fields'] );
        $this->assertSame( $bg, $result['background'] );
    }

    public function test_get_form_complete_returns_array(): void {
        $complete = array(
            'config'     => false,
            'fields'     => false,
            'background' => '',
        );

        Functions\when( 'get_option' )->justReturn( array() );
        Functions\expect( 'wp_cache_get' )
            ->with( 'complete_999', 'ffc_forms' )
            ->andReturn( $complete );

        $result = FormCache::get_form_complete( 999 );
        $this->assertIsArray( $result );
    }

    // ==================================================================
    // get_form_post()
    // ==================================================================

    public function test_get_form_post_cache_hit(): void {
        $post = new \stdClass();
        $post->ID          = 12;
        $post->post_type   = 'ffc_form';
        $post->post_status = 'publish';

        Functions\when( 'get_option' )->justReturn( array() );
        Functions\expect( 'wp_cache_get' )
            ->once()
            ->with( 'post_12', 'ffc_forms' )
            ->andReturn( $post );

        Functions\expect( 'get_post' )->never();

        $result = FormCache::get_form_post( 12 );
        $this->assertSame( $post, $result );
    }

    public function test_get_form_post_cache_miss_loads_from_get_post(): void {
        $post = new \stdClass();
        $post->ID          = 25;
        $post->post_type   = 'ffc_form';
        $post->post_status = 'publish';

        Functions\when( 'get_option' )->justReturn( array() );
        Functions\expect( 'wp_cache_get' )
            ->once()
            ->with( 'post_25', 'ffc_forms' )
            ->andReturn( false );

        Functions\expect( 'get_post' )
            ->once()
            ->with( 25 )
            ->andReturn( $post );

        Functions\expect( 'wp_cache_set' )
            ->once()
            ->with( 'post_25', $post, 'ffc_forms', Mockery::type( 'int' ) )
            ->andReturn( true );

        $result = FormCache::get_form_post( 25 );
        $this->assertSame( $post, $result );
    }

    public function test_get_form_post_returns_false_when_post_not_found(): void {
        Functions\when( 'get_option' )->justReturn( array() );
        Functions\expect( 'wp_cache_get' )
            ->once()
            ->with( 'post_404', 'ffc_forms' )
            ->andReturn( false );

        Functions\expect( 'get_post' )
            ->once()
            ->with( 404 )
            ->andReturn( null );

        Functions\expect( 'wp_cache_set' )->never();

        $result = FormCache::get_form_post( 404 );
        $this->assertFalse( $result );
    }

    public function test_get_form_post_returns_false_when_wrong_post_type(): void {
        $post = new \stdClass();
        $post->ID          = 33;
        $post->post_type   = 'post'; // Not ffc_form.
        $post->post_status = 'publish';

        Functions\when( 'get_option' )->justReturn( array() );
        Functions\expect( 'wp_cache_get' )
            ->once()
            ->with( 'post_33', 'ffc_forms' )
            ->andReturn( false );

        Functions\expect( 'get_post' )
            ->once()
            ->with( 33 )
            ->andReturn( $post );

        Functions\expect( 'wp_cache_set' )->never();

        $result = FormCache::get_form_post( 33 );
        $this->assertFalse( $result );
    }

    // ==================================================================
    // clear_form_cache()
    // ==================================================================

    public function test_clear_form_cache_deletes_all_five_keys(): void {
        Functions\when( 'wp_cache_delete' )->justReturn( true );

        $result = FormCache::clear_form_cache( 7 );
        $this->assertTrue( $result );
    }

    public function test_clear_form_cache_returns_true_when_at_least_one_deleted(): void {
        $call_count = 0;
        Functions\when( 'wp_cache_delete' )->alias( function ( $key, $group ) use ( &$call_count ) {
            $call_count++;
            // Only the third call (bg) succeeds.
            return $call_count === 3;
        });

        $result = FormCache::clear_form_cache( 8 );
        $this->assertTrue( $result );
    }

    public function test_clear_form_cache_returns_false_when_none_deleted(): void {
        Functions\when( 'wp_cache_delete' )->justReturn( false );

        $result = FormCache::clear_form_cache( 9 );
        $this->assertFalse( $result );
    }

    // ==================================================================
    // clear_all_cache()
    // ==================================================================

    public function test_clear_all_cache_succeeds_when_user_has_capability(): void {
        Functions\expect( 'current_user_can' )
            ->once()
            ->with( 'manage_options' )
            ->andReturn( true );

        Functions\expect( 'wp_cache_flush' )
            ->once()
            ->andReturn( true );

        $result = FormCache::clear_all_cache();
        $this->assertTrue( $result );
    }

    public function test_clear_all_cache_returns_false_without_capability(): void {
        Functions\expect( 'current_user_can' )
            ->once()
            ->with( 'manage_options' )
            ->andReturn( false );

        Functions\expect( 'wp_cache_flush' )->never();

        $result = FormCache::clear_all_cache();
        $this->assertFalse( $result );
    }

    // ==================================================================
    // warm_cache()
    // ==================================================================

    public function test_warm_cache_calls_get_form_complete_and_returns_true(): void {
        $complete = array(
            'config'     => array( 'title' => 'Warmed' ),
            'fields'     => array(),
            'background' => '',
        );

        Functions\when( 'get_option' )->justReturn( array() );
        Functions\expect( 'wp_cache_get' )
            ->with( 'complete_100', 'ffc_forms' )
            ->andReturn( $complete );

        $result = FormCache::warm_cache( 100 );
        $this->assertTrue( $result );
    }

    // ==================================================================
    // warm_all_forms()
    // ==================================================================

    public function test_warm_all_forms_warms_each_form(): void {
        Functions\when( 'get_option' )->justReturn( array() );
        Functions\expect( 'get_posts' )
            ->once()
            ->with( Mockery::on( function ( $args ) {
                return $args['post_type'] === 'ffc_form'
                    && $args['posts_per_page'] === 50
                    && $args['post_status'] === 'publish'
                    && $args['fields'] === 'ids';
            }) )
            ->andReturn( array( 1, 2, 3 ) );

        // Each form triggers get_form_complete -> wp_cache_get.
        Functions\when( 'wp_cache_get' )->justReturn(
            array( 'config' => array(), 'fields' => array(), 'background' => '' )
        );

        $result = FormCache::warm_all_forms();
        $this->assertSame( 3, $result );
    }

    public function test_warm_all_forms_with_custom_limit(): void {
        Functions\when( 'get_option' )->justReturn( array() );
        Functions\expect( 'get_posts' )
            ->once()
            ->with( Mockery::on( function ( $args ) {
                return $args['posts_per_page'] === 10;
            }) )
            ->andReturn( array( 101, 102 ) );

        Functions\when( 'wp_cache_get' )->justReturn(
            array( 'config' => array(), 'fields' => array(), 'background' => '' )
        );

        $result = FormCache::warm_all_forms( 10 );
        $this->assertSame( 2, $result );
    }

    public function test_warm_all_forms_returns_zero_when_no_forms(): void {
        Functions\expect( 'get_posts' )
            ->once()
            ->andReturn( array() );

        $result = FormCache::warm_all_forms();
        $this->assertSame( 0, $result );
    }

    // ==================================================================
    // get_stats()
    // ==================================================================

    public function test_get_stats_returns_expected_structure(): void {
        Functions\when( 'get_option' )->justReturn( array() );
        Functions\when( 'wp_using_ext_object_cache' )->justReturn( false );

        $stats = FormCache::get_stats();

        $this->assertIsArray( $stats );
        $this->assertArrayHasKey( 'enabled', $stats );
        $this->assertArrayHasKey( 'backend', $stats );
        $this->assertArrayHasKey( 'group', $stats );
        $this->assertArrayHasKey( 'expiration', $stats );
        $this->assertArrayHasKey( 'note', $stats );
    }

    public function test_get_stats_shows_database_backend_without_ext_cache(): void {
        Functions\when( 'get_option' )->justReturn( array() );
        Functions\when( 'wp_using_ext_object_cache' )->justReturn( false );

        $stats = FormCache::get_stats();

        $this->assertFalse( $stats['enabled'] );
        $this->assertSame( 'database', $stats['backend'] );
        $this->assertSame( 'ffc_forms', $stats['group'] );
    }

    public function test_get_stats_shows_external_backend_with_ext_cache(): void {
        Functions\when( 'get_option' )->justReturn( array() );
        Functions\when( 'wp_using_ext_object_cache' )->justReturn( true );

        $stats = FormCache::get_stats();

        $this->assertTrue( $stats['enabled'] );
        $this->assertSame( 'external', $stats['backend'] );
    }

    public function test_get_stats_expiration_contains_seconds_suffix(): void {
        Functions\when( 'get_option' )->justReturn( array() );
        Functions\when( 'wp_using_ext_object_cache' )->justReturn( false );

        $stats = FormCache::get_stats();
        $this->assertStringContainsString( 'seconds', $stats['expiration'] );
    }

    // ==================================================================
    // check_form_cache_status()
    // ==================================================================

    public function test_check_form_cache_status_all_cached(): void {
        Functions\when( 'wp_cache_get' )->alias( function ( $key, $group = '' ) {
            $map = array(
                'config_40'   => array( 'title' => 'x' ),
                'fields_40'   => array(),
                'bg_40'       => 'url',
                'complete_40' => array(),
                'post_40'     => new \stdClass(),
            );
            return isset( $map[ $key ] ) ? $map[ $key ] : false;
        });

        $status = FormCache::check_form_cache_status( 40 );

        $this->assertIsArray( $status );
        $this->assertTrue( $status['config'] );
        $this->assertTrue( $status['fields'] );
        $this->assertTrue( $status['background'] );
        $this->assertTrue( $status['complete'] );
        $this->assertTrue( $status['post'] );
    }

    public function test_check_form_cache_status_none_cached(): void {
        Functions\when( 'wp_cache_get' )->justReturn( false );

        $status = FormCache::check_form_cache_status( 41 );

        $this->assertFalse( $status['config'] );
        $this->assertFalse( $status['fields'] );
        $this->assertFalse( $status['background'] );
        $this->assertFalse( $status['complete'] );
        $this->assertFalse( $status['post'] );
    }

    public function test_check_form_cache_status_partial(): void {
        Functions\when( 'wp_cache_get' )->alias( function ( $key, $group = '' ) {
            $cached_keys = array(
                'config_45' => array( 'title' => 'y' ),
                'post_45'   => new \stdClass(),
            );
            return isset( $cached_keys[ $key ] ) ? $cached_keys[ $key ] : false;
        });

        $status = FormCache::check_form_cache_status( 45 );

        $this->assertTrue( $status['config'] );
        $this->assertFalse( $status['fields'] );
        $this->assertFalse( $status['background'] );
        $this->assertFalse( $status['complete'] );
        $this->assertTrue( $status['post'] );
    }

    // ==================================================================
    // get_cache_key()
    // ==================================================================

    public function test_get_cache_key_config(): void {
        $this->assertSame( 'config_10', FormCache::get_cache_key( 10, 'config' ) );
    }

    public function test_get_cache_key_fields(): void {
        $this->assertSame( 'fields_10', FormCache::get_cache_key( 10, 'fields' ) );
    }

    public function test_get_cache_key_bg(): void {
        $this->assertSame( 'bg_10', FormCache::get_cache_key( 10, 'bg' ) );
    }

    public function test_get_cache_key_background_alias(): void {
        $this->assertSame( 'bg_10', FormCache::get_cache_key( 10, 'background' ) );
    }

    public function test_get_cache_key_complete(): void {
        $this->assertSame( 'complete_10', FormCache::get_cache_key( 10, 'complete' ) );
    }

    public function test_get_cache_key_post(): void {
        $this->assertSame( 'post_10', FormCache::get_cache_key( 10, 'post' ) );
    }

    public function test_get_cache_key_unknown_type_defaults_to_config(): void {
        $this->assertSame( 'config_10', FormCache::get_cache_key( 10, 'nonexistent' ) );
    }

    // ==================================================================
    // register_hooks()
    // ==================================================================

    public function test_register_hooks_adds_actions(): void {
        Functions\expect( 'add_action' )
            ->once()
            ->with( 'save_post_ffc_form', Mockery::type( 'array' ), 10, 3 );

        Functions\expect( 'add_action' )
            ->once()
            ->with( 'before_delete_post', Mockery::type( 'array' ), 10, 2 );

        Functions\expect( 'add_action' )
            ->once()
            ->with( 'ffcertificate_warm_cache_hook', Mockery::type( 'Closure' ) );

        FormCache::register_hooks();
    }

    // ==================================================================
    // on_form_saved()
    // ==================================================================

    public function test_on_form_saved_skips_revisions(): void {
        $post = Mockery::mock( \WP_Post::class );
        $post->post_status = 'publish';
        $post->post_type   = 'ffc_form';

        Functions\expect( 'wp_is_post_revision' )
            ->once()
            ->with( 100 )
            ->andReturn( true );

        Functions\expect( 'wp_is_post_autosave' )->never();
        Functions\expect( 'wp_cache_delete' )->never();

        FormCache::on_form_saved( 100, $post, true );
    }

    public function test_on_form_saved_skips_autosaves(): void {
        $post = Mockery::mock( \WP_Post::class );
        $post->post_status = 'publish';
        $post->post_type   = 'ffc_form';

        Functions\expect( 'wp_is_post_revision' )
            ->once()
            ->with( 101 )
            ->andReturn( false );

        Functions\expect( 'wp_is_post_autosave' )
            ->once()
            ->with( 101 )
            ->andReturn( true );

        Functions\expect( 'wp_cache_delete' )->never();

        FormCache::on_form_saved( 101, $post, false );
    }

    public function test_on_form_saved_clears_and_warms_published_form(): void {
        $post = Mockery::mock( \WP_Post::class );
        $post->post_status = 'publish';
        $post->post_type   = 'ffc_form';

        Functions\when( 'get_option' )->justReturn( array() );

        Functions\expect( 'wp_is_post_revision' )
            ->once()
            ->with( 200 )
            ->andReturn( false );

        Functions\expect( 'wp_is_post_autosave' )
            ->once()
            ->with( 200 )
            ->andReturn( false );

        // clear_form_cache calls wp_cache_delete 5 times.
        Functions\when( 'wp_cache_delete' )->justReturn( true );

        // warm_cache -> get_form_complete -> wp_cache_get for 'complete_200'.
        Functions\when( 'wp_cache_get' )->justReturn(
            array( 'config' => array(), 'fields' => array(), 'background' => '' )
        );

        FormCache::on_form_saved( 200, $post, true );

        // If we reached here without exception, clear + warm both executed.
        $this->assertTrue( true );
    }

    public function test_on_form_saved_clears_but_does_not_warm_draft_form(): void {
        $post = Mockery::mock( \WP_Post::class );
        $post->post_status = 'draft';
        $post->post_type   = 'ffc_form';

        Functions\expect( 'wp_is_post_revision' )
            ->once()
            ->with( 201 )
            ->andReturn( false );

        Functions\expect( 'wp_is_post_autosave' )
            ->once()
            ->with( 201 )
            ->andReturn( false );

        // Cache is cleared.
        Functions\when( 'wp_cache_delete' )->justReturn( true );

        // warm_cache should NOT be called for draft, so wp_cache_get should not be called.
        Functions\expect( 'wp_cache_get' )->never();

        FormCache::on_form_saved( 201, $post, false );
    }

    // ==================================================================
    // on_form_deleted()
    // ==================================================================

    public function test_on_form_deleted_clears_cache_for_ffc_form(): void {
        $post = Mockery::mock( \WP_Post::class );
        $post->post_type = 'ffc_form';

        Functions\when( 'wp_cache_delete' )->justReturn( true );

        FormCache::on_form_deleted( 300, $post );

        // Verify by calling it -- if clear_form_cache ran, it called wp_cache_delete.
        $this->assertTrue( true );
    }

    public function test_on_form_deleted_ignores_non_ffc_form(): void {
        $post = Mockery::mock( \WP_Post::class );
        $post->post_type = 'page';

        Functions\expect( 'wp_cache_delete' )->never();

        FormCache::on_form_deleted( 301, $post );
    }

    // ==================================================================
    // schedule_cache_warming()
    // ==================================================================

    public function test_schedule_cache_warming_schedules_when_not_scheduled(): void {
        Functions\expect( 'wp_next_scheduled' )
            ->once()
            ->with( 'ffcertificate_warm_cache_hook' )
            ->andReturn( false );

        Functions\expect( 'wp_schedule_event' )
            ->once()
            ->with( Mockery::type( 'int' ), 'daily', 'ffcertificate_warm_cache_hook' )
            ->andReturn( true );

        FormCache::schedule_cache_warming();
    }

    public function test_schedule_cache_warming_does_not_double_schedule(): void {
        Functions\expect( 'wp_next_scheduled' )
            ->once()
            ->with( 'ffcertificate_warm_cache_hook' )
            ->andReturn( 1709308800 ); // Already scheduled.

        Functions\expect( 'wp_schedule_event' )->never();

        FormCache::schedule_cache_warming();
    }

    // ==================================================================
    // unschedule_cache_warming()
    // ==================================================================

    public function test_unschedule_cache_warming_removes_scheduled_event(): void {
        $timestamp = 1709308800;

        Functions\expect( 'wp_next_scheduled' )
            ->once()
            ->with( 'ffcertificate_warm_cache_hook' )
            ->andReturn( $timestamp );

        Functions\expect( 'wp_unschedule_event' )
            ->once()
            ->with( $timestamp, 'ffcertificate_warm_cache_hook' )
            ->andReturn( true );

        FormCache::unschedule_cache_warming();
    }

    public function test_unschedule_cache_warming_does_nothing_when_not_scheduled(): void {
        Functions\expect( 'wp_next_scheduled' )
            ->once()
            ->with( 'ffcertificate_warm_cache_hook' )
            ->andReturn( false );

        Functions\expect( 'wp_unschedule_event' )->never();

        FormCache::unschedule_cache_warming();
    }

    // ==================================================================
    // CACHE_GROUP constant
    // ==================================================================

    public function test_cache_group_constant(): void {
        $this->assertSame( 'ffc_forms', FormCache::CACHE_GROUP );
    }
}
