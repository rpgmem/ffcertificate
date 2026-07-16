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
        Functions\when( 'sanitize_text_field' )->returnArg();
        Functions\when( 'wp_unslash' )->returnArg();
        Functions\when( 'wp_rand' )->alias( function ( int $min = 0, int $max = 0 ) { return random_int( $min, $max ); } );
        Functions\when( 'wp_hash' )->alias( function ( $data ) { return hash( 'sha256', $data ); } );
        // #Item11: the schedule-exception render path checks the consumed-jti
        // ledger via get_option( 'ffc_sched_exc_used_<jti>', false ). Honor the
        // passed default for that key so an unspent token still renders its
        // banner; every other key keeps the legacy array() stand-in.
        Functions\when( 'get_option' )->alias(
            function ( $key, $default = false ) {
                if ( is_string( $key ) && 0 === strpos( $key, 'ffc_sched_exc_used_' ) ) {
                    return $default;
                }
                return array();
            }
        );
        // Sprint 5 #366: render_form() now reaches into
        // ScheduleExceptionSession which needs wp_salt + is_ssl +
        // setcookie stubs even on the no-exception path (the cookie
        // read is unconditional — only the embed is gated).
        Functions\when( 'wp_salt' )->justReturn( 'test-nonce-salt' );
        Functions\when( 'is_ssl' )->justReturn( false );
        Functions\when( 'setcookie' )->justReturn( true );
        Functions\when( 'wp_json_encode' )->alias( static fn( $v ) => json_encode( $v ) );
        // render_form() now reads RateLimiter::get_settings() unconditionally
        // (to decide the LGPD device notice in the global-ON + form-OFF gap),
        // which parses the settings option via wp_parse_args (#647).
        Functions\when( 'wp_parse_args' )->alias(
            static function ( $args, $defaults = array() ) {
                return array_merge( (array) $defaults, (array) $args );
            }
        );

        if ( ! defined( 'ABSPATH' ) ) {
            define( 'ABSPATH', '/tmp/' );
        }

        $this->shortcodes = new Shortcodes();
    }

    protected function tearDown(): void {
        unset( $_GET['token'] );
        $_COOKIE = array();
        $this->reset_rate_limit_cache();
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * Reset RateLimitChecker's private static settings cache so a global
     * device state set in one test does not leak into the next.
     */
    private function reset_rate_limit_cache(): void {
        if ( ! class_exists( '\FreeFormCertificate\Security\RateLimitChecker' ) ) {
            return;
        }
        $ref = new \ReflectionProperty( '\FreeFormCertificate\Security\RateLimitChecker', 'settings_cache' );
        $ref->setAccessible( true );
        $ref->setValue( null, null );
    }

    /**
     * Render a minimal form with the device subsystem in a chosen state.
     *
     * @param bool $global_on Global Device Fingerprint subsystem enabled.
     * @param bool $form_on    Per-form device limit enabled.
     * @param int  $form_id    Form ID to render.
     * @return string Rendered HTML.
     */
    private function render_form_with_device( bool $global_on, bool $form_on, int $form_id ): string {
        $this->reset_rate_limit_cache();
        Functions\when( 'shortcode_atts' )->alias( fn( $d, $a ) => array_merge( $d, $a ) );
        Functions\when( 'get_post_type' )->justReturn( 'ffc_form' );
        Functions\when( 'get_post' )->justReturn( (object) array( 'post_title' => 'My Certificate' ) );
        Functions\when( 'wp_rand' )->justReturn( 4 );
        Functions\when( 'wp_hash' )->justReturn( 'hash' );
        Functions\when( 'get_post_meta' )->alias(
            function ( $id, $key, $single = false ) use ( $form_on ) {
                if ( '_ffc_form_fields' === $key ) {
                    return array(
                        array( 'type' => 'text', 'name' => 'name', 'label' => 'Full Name', 'required' => true ),
                    );
                }
                if ( '_ffc_device_limit_enabled' === $key ) {
                    return $form_on ? '1' : '0';
                }
                if ( '_ffc_form_config' === $key ) {
                    return array( 'restrictions' => array() );
                }
                return '';
            }
        );
        Functions\when( 'get_option' )->alias(
            function ( $key, $default = false ) use ( $global_on ) {
                if ( is_string( $key ) && 0 === strpos( $key, 'ffc_sched_exc_used_' ) ) {
                    return $default;
                }
                if ( 'ffc_rate_limit_settings' === $key ) {
                    return array( 'device' => array( 'enabled' => $global_on ) );
                }
                return array();
            }
        );

        return (string) $this->shortcodes->render_form( array( 'id' => $form_id ) );
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
    // LGPD consent — device notice branches (#647)
    // ==================================================================

    public function test_render_form_shows_generic_fraud_notice_when_global_on_form_off(): void {
        $html = $this->render_form_with_device( true, false, 51 );

        // Generic, truthful monitoring line — no device-duplicate claim.
        $this->assertStringContainsString( 'ffc-fraud-monitoring-notice', $html );
        $this->assertStringContainsString( 'logged and may be audited', $html );
        // The device-specific disclosure must NOT appear (no signal collected).
        $this->assertStringNotContainsString( 'ffc-device-disclosure', $html );
    }

    public function test_render_form_shows_device_disclosure_when_global_on_form_on(): void {
        $html = $this->render_form_with_device( true, true, 52 );

        // Form ON: the honest device disclosure appears...
        $this->assertStringContainsString( 'ffc-device-disclosure', $html );
        $this->assertStringContainsString( 'anonymously identify your device', $html );
        // ...and the generic gap-notice does not.
        $this->assertStringNotContainsString( 'ffc-fraud-monitoring-notice', $html );
    }

    public function test_render_form_shows_no_device_notice_when_global_off(): void {
        $html = $this->render_form_with_device( false, false, 53 );

        $this->assertStringNotContainsString( 'ffc-fraud-monitoring-notice', $html );
        $this->assertStringNotContainsString( 'ffc-device-disclosure', $html );
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

    // ==================================================================
    // Schedule exception consumption (#366 Sprint 5)
    // ==================================================================

    /**
     * Stub a minimal `render_form()` happy path with a per-test override
     * of geofence config + form fields. Returns the rendered HTML.
     *
     * @param array<string, mixed> $geofence_overrides Geofence config to merge.
     */
    private function render_form_with( array $geofence_overrides = array() ): string {
        Functions\when( 'shortcode_atts' )->alias( fn( $d, $a ) => array_merge( $d, $a ) );
        Functions\when( 'get_post_type' )->justReturn( 'ffc_form' );
        Functions\when( 'get_post' )->justReturn( (object) array( 'post_title' => 'Test Form' ) );
        Functions\when( 'wp_rand' )->justReturn( 5 );
        Functions\when( 'wp_hash' )->justReturn( 'hash' );

        Functions\when( 'get_post_meta' )->alias( static function ( $id, $key ) use ( $geofence_overrides ) {
            if ( '_ffc_form_fields' === $key ) {
                return array(
                    array( 'type' => 'text', 'name' => 'name', 'label' => 'Name' ),
                );
            }
            if ( '_ffc_geofence_config' === $key ) {
                return $geofence_overrides ?: '';
            }
            if ( '_ffc_form_config' === $key ) {
                return array( 'restrictions' => array() );
            }
            return '';
        } );

        return $this->shortcodes->render_form( array( 'id' => 42 ) );
    }

    public function test_render_form_omits_schedule_exception_banner_when_no_cookie(): void {
        $html = $this->render_form_with();

        $this->assertStringNotContainsString( 'ffc-schedule-exception-banner', $html );
        $this->assertStringNotContainsString( 'ffc_schedule_exception_token', $html );
    }

    public function test_render_form_omits_banner_when_cookie_is_tampered(): void {
        $_COOKIE['ffc_exception_42'] = 'not-a-valid.token';

        $html = $this->render_form_with();

        $this->assertStringNotContainsString( 'ffc-schedule-exception-banner', $html );
        $this->assertStringNotContainsString( 'ffc_schedule_exception_token', $html );
    }

    public function test_render_form_embeds_banner_and_hidden_input_when_cookie_is_valid(): void {
        $token = \FreeFormCertificate\Frontend\ScheduleExceptionSession::create(
            42,
            '08:00',
            '17:30',
            str_repeat( 'a', 64 )
        );
        $_COOKIE['ffc_exception_42'] = $token;

        $html = $this->render_form_with();

        // Banner present + carries both override values.
        $this->assertStringContainsString( 'ffc-schedule-exception-banner', $html );
        $this->assertStringContainsString( '08:00', $html );
        $this->assertStringContainsString( '17:30', $html );
        // Hidden input carries the exact cookie value (no re-sign).
        $this->assertStringContainsString( 'name="ffc_schedule_exception_token"', $html );
        $this->assertStringContainsString( 'value="' . $token . '"', $html );
    }

    public function test_render_form_uses_baseline_label_when_only_one_end_is_overridden(): void {
        // Token has start=null (operator left start at baseline) but
        // overrides end. The banner text falls back to "baseline" for
        // the missing side instead of an empty gap.
        $token = \FreeFormCertificate\Frontend\ScheduleExceptionSession::create(
            42,
            null,
            '17:30',
            str_repeat( 'a', 64 )
        );
        $_COOKIE['ffc_exception_42'] = $token;

        $html = $this->render_form_with();

        $this->assertStringContainsString( 'ffc-schedule-exception-banner', $html );
        $this->assertStringContainsString( 'baseline', $html );
        $this->assertStringContainsString( '17:30', $html );
    }

    public function test_render_form_rejects_cookie_scoped_to_other_form(): void {
        // Cookie was issued for form 99 but the rendered form is 42 —
        // ScheduleExceptionSession::read_from_cookie rejects on the
        // form_id mismatch, so no banner and no embed.
        $token = \FreeFormCertificate\Frontend\ScheduleExceptionSession::create(
            99,
            '08:00',
            '17:30',
            str_repeat( 'a', 64 )
        );
        $_COOKIE['ffc_exception_42'] = $token;

        $html = $this->render_form_with();

        $this->assertStringNotContainsString( 'ffc-schedule-exception-banner', $html );
        $this->assertStringNotContainsString( 'ffc_schedule_exception_token', $html );
    }

    public function test_render_form_suppresses_banner_when_jti_already_consumed(): void {
        // #Item11 — a token whose jti is in the consumed ledger is a spent
        // exception: even though the cookie is still valid, the render path
        // must not re-show the banner or re-embed the token (this is what
        // makes the message disappear after the submission, on refresh).
        $token                       = \FreeFormCertificate\Frontend\ScheduleExceptionSession::create(
            42,
            '08:00',
            '17:30',
            str_repeat( 'a', 64 )
        );
        $_COOKIE['ffc_exception_42'] = $token;

        // Force the ledger to report ANY consumed-marker lookup as present.
        Functions\when( 'get_option' )->alias(
            function ( $key, $default = false ) {
                if ( is_string( $key ) && 0 === strpos( $key, 'ffc_sched_exc_used_' ) ) {
                    return '1'; // marker present → consumed.
                }
                return array();
            }
        );

        $html = $this->render_form_with();

        $this->assertStringNotContainsString( 'ffc-schedule-exception-banner', $html );
        $this->assertStringNotContainsString( 'ffc_schedule_exception_token', $html );
    }

    public function test_render_form_clears_the_cookie_after_successful_read(): void {
        // ScheduleExceptionSession::clear() unsets `$_COOKIE[$name]` so
        // a follow-up render-in-the-same-request reflects the
        // consumed state. (The browser-side delete happens via the
        // mocked setcookie expiry, which the cookie-attr assertions
        // live with in ScheduleExceptionSessionTest, not here.)
        $token                       = \FreeFormCertificate\Frontend\ScheduleExceptionSession::create(
            42,
            '08:00',
            '17:30',
            str_repeat( 'a', 64 )
        );
        $_COOKIE['ffc_exception_42'] = $token;

        $this->render_form_with();

        $this->assertArrayNotHasKey( 'ffc_exception_42', $_COOKIE );
    }
}
