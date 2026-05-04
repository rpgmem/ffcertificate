<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Frontend\Shortcodes;

/**
 * @covers \FreeFormCertificate\Frontend\Shortcodes
 */
class FrontendShortcodesTest extends TestCase {

    use MockeryPHPUnitIntegration;

    private Shortcodes $shortcodes;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        Functions\when( '__' )->returnArg();
        Functions\when( 'esc_html__' )->returnArg();
        Functions\when( 'esc_html' )->returnArg();
        Functions\when( 'esc_attr' )->returnArg();
        Functions\when( 'esc_html_e' )->alias( function ( $text ) { echo $text; } );
        Functions\when( 'esc_attr_e' )->alias( function ( $text ) { echo $text; } );
        Functions\when( 'esc_url' )->returnArg();
        Functions\when( 'esc_textarea' )->returnArg();
        Functions\when( 'wp_kses_post' )->returnArg();
        Functions\when( 'selected' )->justReturn( '' );
        Functions\when( 'checked' )->justReturn( '' );
        Functions\when( 'wp_oembed_get' )->justReturn( false );
        Functions\when( 'get_privacy_policy_url' )->justReturn( 'https://example.com/privacy' );
        Functions\when( 'absint' )->alias( function ( $val ) { return abs( (int) $val ); } );
        Functions\when( 'sanitize_text_field' )->returnArg();
        Functions\when( 'wp_unslash' )->returnArg();
        Functions\when( 'wp_rand' )->alias( function ( int $min = 0, int $max = 0 ) { return random_int( $min, $max ); } );
        Functions\when( 'wp_hash' )->alias( function ( $data ) { return hash( 'sha256', $data ); } );
        // 6.2.0: every legacy `Utils::debug_log()` call became `Debug::log_*()`,
        // which reads `ffc_settings` to gate per-area. Stub get_option so the
        // gate is reached without exploding.
        Functions\when( 'get_option' )->justReturn( array() );

        if ( ! defined( 'ABSPATH' ) ) {
            define( 'ABSPATH', '/tmp/' );
        }

        $this->shortcodes = new Shortcodes();
    }

    protected function tearDown(): void {
        unset( $_GET['token'] );
        Monkey\tearDown();
        parent::tearDown();
    }

    // ==================================================================
    // get_new_captcha_data()
    // ==================================================================

    public function test_get_new_captcha_data_returns_label_and_hash(): void {
        $captcha = $this->shortcodes->get_new_captcha_data();

        $this->assertArrayHasKey( 'label', $captcha );
        $this->assertArrayHasKey( 'hash', $captcha );
        $this->assertArrayHasKey( 'answer', $captcha );
        $this->assertIsInt( $captcha['answer'] );
        $this->assertGreaterThanOrEqual( 0, $captcha['answer'] );
        $this->assertSame(
            hash( 'sha256', $captcha['answer'] . 'ffc_math_salt' ),
            $captcha['hash']
        );
    }

    // ==================================================================
    // generate_security_fields()
    // ==================================================================

    public function test_generate_security_fields_contains_honeypot_and_captcha(): void {
        Functions\when( 'wp_rand' )->justReturn( 3 );
        Functions\when( 'wp_hash' )->justReturn( 'testhash123' );

        $html = $this->shortcodes->generate_security_fields();

        $this->assertStringContainsString( 'ffc-honeypot-field', $html );
        $this->assertStringContainsString( 'ffc_honeypot_trap', $html );
        $this->assertStringContainsString( 'ffc_captcha_ans', $html );
        $this->assertStringContainsString( 'ffc_captcha_hash', $html );
        $this->assertStringContainsString( 'testhash123', $html );
    }

    // ==================================================================
    // render_verification_page() — with magic token
    // ==================================================================

    public function test_render_verification_page_renders_magic_link_when_token_present(): void {
        $_GET['token'] = 'abc123magic';

        $html = $this->shortcodes->render_verification_page( array() );

        $this->assertStringContainsString( 'ffc-magic-link-container', $html );
        $this->assertStringContainsString( 'abc123magic', $html );
        $this->assertStringContainsString( 'ffc-verify-loading', $html );
    }

    // ==================================================================
    // render_verification_page() — without magic token
    // ==================================================================

    public function test_render_verification_page_includes_template_when_no_token(): void {
        unset( $_GET['token'] );

        if ( ! defined( 'FFC_PLUGIN_DIR' ) ) {
            define( 'FFC_PLUGIN_DIR', '/tmp/ffc_test_dir/' );
        }

        // Create a minimal template file
        @mkdir( '/tmp/ffc_test_dir/templates', 0777, true );
        file_put_contents( '/tmp/ffc_test_dir/templates/verification-page.php', '<div class="ffc-verification-form">FORM</div>' );

        Functions\when( 'wp_rand' )->justReturn( 2 );
        Functions\when( 'wp_hash' )->justReturn( 'hash' );

        $html = $this->shortcodes->render_verification_page( array() );

        $this->assertStringContainsString( 'ffc-verification-form', $html );

        // Cleanup
        @unlink( '/tmp/ffc_test_dir/templates/verification-page.php' );
        @rmdir( '/tmp/ffc_test_dir/templates' );
    }

    // ==================================================================
    // render_form() — invalid form ID
    // ==================================================================

    public function test_render_form_returns_error_for_invalid_form_id(): void {
        Functions\when( 'shortcode_atts' )->alias( function ( $defaults, $atts ) {
            return array_merge( $defaults, $atts );
        } );
        Functions\when( 'get_post_type' )->justReturn( 'post' ); // Not ffc_form

        $html = $this->shortcodes->render_form( array( 'id' => 99 ) );

        $this->assertStringContainsString( 'Form not found', $html );
    }

    // ==================================================================
    // render_form() — form with no fields
    // ==================================================================

    public function test_render_form_returns_error_when_no_fields(): void {
        Functions\when( 'shortcode_atts' )->alias( function ( $defaults, $atts ) {
            return array_merge( $defaults, $atts );
        } );
        Functions\when( 'get_post_type' )->justReturn( 'ffc_form' );
        Functions\when( 'get_post' )->justReturn( (object) array( 'post_title' => 'Test Form' ) );
        Functions\when( 'get_post_meta' )->justReturn( '' );

        $html = $this->shortcodes->render_form( array( 'id' => 10 ) );

        $this->assertStringContainsString( 'Form has no fields', $html );
    }

    // ==================================================================
    // render_form() — form with zero ID
    // ==================================================================

    public function test_render_form_returns_error_when_id_is_zero(): void {
        Functions\when( 'shortcode_atts' )->alias( function ( $defaults, $atts ) {
            return array_merge( $defaults, $atts );
        } );

        $html = $this->shortcodes->render_form( array( 'id' => 0 ) );

        $this->assertStringContainsString( 'Form not found', $html );
    }

    // ==================================================================
    // render_form() — full form render with fields
    // ==================================================================

    public function test_render_form_renders_form_with_fields(): void {
        Functions\when( 'shortcode_atts' )->alias( function ( $defaults, $atts ) {
            return array_merge( $defaults, $atts );
        } );
        Functions\when( 'get_post_type' )->justReturn( 'ffc_form' );
        Functions\when( 'get_post' )->justReturn( (object) array( 'post_title' => 'My Certificate' ) );
        Functions\when( 'wp_rand' )->justReturn( 4 );
        Functions\when( 'wp_hash' )->justReturn( 'hash' );

        $call_count = 0;
        Functions\when( 'get_post_meta' )->alias( function ( $id, $key, $single ) use ( &$call_count ) {
            $call_count++;
            if ( $key === '_ffc_form_fields' ) {
                return array(
                    array( 'type' => 'text', 'name' => 'name', 'label' => 'Full Name', 'required' => true ),
                    array( 'type' => 'textarea', 'name' => 'notes', 'label' => 'Notes' ),
                );
            }
            if ( $key === '_ffc_geofence_config' ) {
                return '';
            }
            if ( $key === '_ffc_form_config' ) {
                return array( 'restrictions' => array() );
            }
            return '';
        } );

        $html = $this->shortcodes->render_form( array( 'id' => 42 ) );

        $this->assertStringContainsString( 'My Certificate', $html );
        $this->assertStringContainsString( 'ffc-submission-form', $html );
        $this->assertStringContainsString( 'Full Name', $html );
        $this->assertStringContainsString( 'textarea', $html );
        $this->assertStringContainsString( 'ffc-lgpd-consent', $html );
        $this->assertStringContainsString( 'ffc-submit-btn', $html );
        $this->assertStringContainsString( 'ffc_honeypot_trap', $html );
    }

    // ==================================================================
    // render_form() — with password restriction
    // ==================================================================

    public function test_render_form_shows_password_field_when_restriction_active(): void {
        Functions\when( 'shortcode_atts' )->alias( function ( $defaults, $atts ) {
            return array_merge( $defaults, $atts );
        } );
        Functions\when( 'get_post_type' )->justReturn( 'ffc_form' );
        Functions\when( 'get_post' )->justReturn( (object) array( 'post_title' => 'Protected Form' ) );
        Functions\when( 'wp_rand' )->justReturn( 1 );
        Functions\when( 'wp_hash' )->justReturn( 'h' );

        Functions\when( 'get_post_meta' )->alias( function ( $id, $key, $single ) {
            if ( $key === '_ffc_form_fields' ) {
                return array( array( 'type' => 'text', 'name' => 'name', 'label' => 'Name', 'required' => true ) );
            }
            if ( $key === '_ffc_form_config' ) {
                return array( 'restrictions' => array( 'password' => '1' ) );
            }
            return '';
        } );

        $html = $this->shortcodes->render_form( array( 'id' => 5 ) );

        $this->assertStringContainsString( 'ffc_password', $html );
        $this->assertStringContainsString( 'type="password"', $html );
    }

    // ==================================================================
    // render_form() — with ticket restriction
    // ==================================================================

    public function test_render_form_shows_ticket_field_when_restriction_active(): void {
        Functions\when( 'shortcode_atts' )->alias( function ( $defaults, $atts ) {
            return array_merge( $defaults, $atts );
        } );
        Functions\when( 'get_post_type' )->justReturn( 'ffc_form' );
        Functions\when( 'get_post' )->justReturn( (object) array( 'post_title' => 'Ticket Form' ) );
        Functions\when( 'wp_rand' )->justReturn( 1 );
        Functions\when( 'wp_hash' )->justReturn( 'h' );

        Functions\when( 'get_post_meta' )->alias( function ( $id, $key, $single ) {
            if ( $key === '_ffc_form_fields' ) {
                return array( array( 'type' => 'text', 'name' => 'name', 'label' => 'Name' ) );
            }
            if ( $key === '_ffc_form_config' ) {
                return array( 'restrictions' => array( 'ticket' => '1' ) );
            }
            return '';
        } );

        $html = $this->shortcodes->render_form( array( 'id' => 7 ) );

        $this->assertStringContainsString( 'ffc_ticket', $html );
        $this->assertStringContainsString( 'Ticket Code', $html );
    }

    // ==================================================================
    // render_form() — geofence wrapper class
    // ==================================================================

    public function test_render_form_adds_geofence_class_when_enabled(): void {
        Functions\when( 'shortcode_atts' )->alias( function ( $defaults, $atts ) {
            return array_merge( $defaults, $atts );
        } );
        Functions\when( 'get_post_type' )->justReturn( 'ffc_form' );
        Functions\when( 'get_post' )->justReturn( (object) array( 'post_title' => 'Geo Form' ) );
        Functions\when( 'wp_rand' )->justReturn( 2 );
        Functions\when( 'wp_hash' )->justReturn( 'h' );

        Functions\when( 'get_post_meta' )->alias( function ( $id, $key, $single ) {
            if ( $key === '_ffc_form_fields' ) {
                return array( array( 'type' => 'text', 'name' => 'name', 'label' => 'Name' ) );
            }
            if ( $key === '_ffc_geofence_config' ) {
                return array( 'datetime_enabled' => '1', 'geo_enabled' => '0' );
            }
            if ( $key === '_ffc_form_config' ) {
                return array( 'restrictions' => array() );
            }
            return '';
        } );

        $html = $this->shortcodes->render_form( array( 'id' => 3 ) );

        $this->assertStringContainsString( 'ffc-has-geofence', $html );
    }

    // ==================================================================
    // render_form() — field types: select, radio, hidden, info, embed
    // ==================================================================

    public function test_render_form_renders_select_field(): void {
        Functions\when( 'shortcode_atts' )->alias( function ( $defaults, $atts ) {
            return array_merge( $defaults, $atts );
        } );
        Functions\when( 'get_post_type' )->justReturn( 'ffc_form' );
        Functions\when( 'get_post' )->justReturn( (object) array( 'post_title' => 'Select Form' ) );
        Functions\when( 'wp_rand' )->justReturn( 1 );
        Functions\when( 'wp_hash' )->justReturn( 'h' );

        Functions\when( 'get_post_meta' )->alias( function ( $id, $key, $single ) {
            if ( $key === '_ffc_form_fields' ) {
                return array(
                    array( 'type' => 'select', 'name' => 'program', 'label' => 'Program', 'options' => 'Option A,Option B,Option C' ),
                    array( 'type' => 'radio', 'name' => 'choice', 'label' => 'Choice', 'options' => 'Yes,No' ),
                    array( 'type' => 'hidden', 'name' => 'ref_id', 'label' => '', 'default_value' => '123' ),
                    array( 'type' => 'info', 'name' => '', 'label' => 'Important', 'content' => 'Read carefully.' ),
                );
            }
            if ( $key === '_ffc_form_config' ) {
                return array( 'restrictions' => array() );
            }
            return '';
        } );

        $html = $this->shortcodes->render_form( array( 'id' => 20 ) );

        $this->assertStringContainsString( '<select', $html );
        $this->assertStringContainsString( 'Option A', $html );
        $this->assertStringContainsString( 'ffc-radio-group', $html );
        $this->assertStringContainsString( 'type="hidden"', $html );
        $this->assertStringContainsString( 'ref_id', $html );
        $this->assertStringContainsString( 'ffc-form-info-block', $html );
        $this->assertStringContainsString( 'Read carefully.', $html );
    }

    // ==================================================================
    // render_form() — embed field with image URL
    // ==================================================================

    public function test_render_form_renders_embed_image(): void {
        Functions\when( 'shortcode_atts' )->alias( function ( $defaults, $atts ) {
            return array_merge( $defaults, $atts );
        } );
        Functions\when( 'get_post_type' )->justReturn( 'ffc_form' );
        Functions\when( 'get_post' )->justReturn( (object) array( 'post_title' => 'Embed Form' ) );
        Functions\when( 'wp_rand' )->justReturn( 1 );
        Functions\when( 'wp_hash' )->justReturn( 'h' );

        Functions\when( 'get_post_meta' )->alias( function ( $id, $key, $single ) {
            if ( $key === '_ffc_form_fields' ) {
                return array(
                    array( 'type' => 'embed', 'name' => '', 'label' => 'Photo', 'embed_url' => 'https://example.com/photo.jpg' ),
                );
            }
            if ( $key === '_ffc_form_config' ) {
                return array( 'restrictions' => array() );
            }
            return '';
        } );

        $html = $this->shortcodes->render_form( array( 'id' => 30 ) );

        $this->assertStringContainsString( 'ffc-form-embed-block', $html );
        $this->assertStringContainsString( '<img', $html );
        $this->assertStringContainsString( 'photo.jpg', $html );
    }

    // ==================================================================
    // render_form() — cpf_rf field gets tel type
    // ==================================================================

    public function test_render_form_renders_cpf_rf_as_tel(): void {
        Functions\when( 'shortcode_atts' )->alias( function ( $defaults, $atts ) {
            return array_merge( $defaults, $atts );
        } );
        Functions\when( 'get_post_type' )->justReturn( 'ffc_form' );
        Functions\when( 'get_post' )->justReturn( (object) array( 'post_title' => 'CPF Form' ) );
        Functions\when( 'wp_rand' )->justReturn( 1 );
        Functions\when( 'wp_hash' )->justReturn( 'h' );

        Functions\when( 'get_post_meta' )->alias( function ( $id, $key, $single ) {
            if ( $key === '_ffc_form_fields' ) {
                return array(
                    array( 'type' => 'text', 'name' => 'cpf_rf', 'label' => 'CPF/RF', 'required' => true ),
                );
            }
            if ( $key === '_ffc_form_config' ) {
                return array( 'restrictions' => array() );
            }
            return '';
        } );

        $html = $this->shortcodes->render_form( array( 'id' => 1 ) );

        $this->assertStringContainsString( 'type="tel"', $html );
    }
}
