<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Admin\FormEditorSaveHandler;

/**
 * Tests for FormEditorSaveHandler: geofence validation logic.
 *
 * Uses Reflection to access private methods for testing pure business logic.
 */
class FormEditorSaveHandlerTest extends TestCase {

    use MockeryPHPUnitIntegration;

    /** @var FormEditorSaveHandler */
    private $handler;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        Functions\when( '__' )->returnArg();
        Functions\when( 'sanitize_text_field' )->returnArg();
        Functions\when( 'sanitize_key' )->alias( function ( $key ) {
            return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $key ) );
        } );

        $this->handler = new FormEditorSaveHandler();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * Invoke a private method on FormEditorSaveHandler.
     */
    private function invoke( string $method, array $args = [] ) {
        $ref = new \ReflectionMethod( FormEditorSaveHandler::class, $method );
        $ref->setAccessible( true );
        return $ref->invokeArgs( $this->handler, $args );
    }

    /**
     * Invoke a private static method on FormEditorSaveHandler.
     */
    private function invoke_static( string $method, array $args = [] ) {
        $ref = new \ReflectionMethod( FormEditorSaveHandler::class, $method );
        $ref->setAccessible( true );
        return $ref->invokeArgs( null, $args );
    }

    // ==================================================================
    // validate_geofence_config()
    // ==================================================================

    public function test_geofence_valid_gps_config_returns_no_errors(): void {
        $config = array(
            'geo_gps_enabled' => '1',
            'geo_ip_enabled' => '0',
            'geo_ip_areas_permissive' => '0',
            'geo_areas' => "-23.5505,-46.6333,500",
            'geo_ip_areas' => '',
        );

        $errors = $this->invoke( 'validate_geofence_config', array( $config ) );
        $this->assertSame( array(), $errors );
    }

    public function test_geofence_gps_enabled_no_areas_returns_error(): void {
        $config = array(
            'geo_gps_enabled' => '1',
            'geo_ip_enabled' => '0',
            'geo_ip_areas_permissive' => '0',
            'geo_areas' => '',
            'geo_ip_areas' => '',
        );

        $errors = $this->invoke( 'validate_geofence_config', array( $config ) );
        $this->assertCount( 1, $errors );
        $this->assertStringContainsString( 'GPS', $errors[0] );
    }

    public function test_geofence_ip_permissive_no_areas_returns_error(): void {
        $config = array(
            'geo_gps_enabled' => '0',
            'geo_ip_enabled' => '1',
            'geo_ip_areas_permissive' => '1',
            'geo_areas' => '',
            'geo_ip_areas' => '',
        );

        $errors = $this->invoke( 'validate_geofence_config', array( $config ) );
        $this->assertCount( 1, $errors );
        $this->assertStringContainsString( 'IP', $errors[0] );
    }

    public function test_geofence_both_disabled_returns_no_errors(): void {
        $config = array(
            'geo_gps_enabled' => '0',
            'geo_ip_enabled' => '0',
            'geo_ip_areas_permissive' => '0',
            'geo_areas' => '',
            'geo_ip_areas' => '',
        );

        $errors = $this->invoke( 'validate_geofence_config', array( $config ) );
        $this->assertSame( array(), $errors );
    }

    public function test_geofence_ip_non_permissive_empty_areas_no_error(): void {
        $config = array(
            'geo_gps_enabled' => '0',
            'geo_ip_enabled' => '1',
            'geo_ip_areas_permissive' => '0',
            'geo_areas' => '',
            'geo_ip_areas' => '',
        );

        $errors = $this->invoke( 'validate_geofence_config', array( $config ) );
        $this->assertSame( array(), $errors );
    }

    public function test_geofence_gps_invalid_areas_propagates_format_errors(): void {
        $config = array(
            'geo_gps_enabled' => '1',
            'geo_ip_enabled' => '0',
            'geo_ip_areas_permissive' => '0',
            'geo_areas' => "invalid_format",
            'geo_ip_areas' => '',
        );

        $errors = $this->invoke( 'validate_geofence_config', array( $config ) );
        $this->assertNotEmpty( $errors );
        $this->assertStringContainsString( 'Invalid format', $errors[0] );
    }

    public function test_geofence_both_gps_and_ip_errors_combined(): void {
        $config = array(
            'geo_gps_enabled' => '1',
            'geo_ip_enabled' => '1',
            'geo_ip_areas_permissive' => '1',
            'geo_areas' => "invalid",
            'geo_ip_areas' => "also_invalid",
        );

        $errors = $this->invoke( 'validate_geofence_config', array( $config ) );
        $this->assertGreaterThanOrEqual( 2, count( $errors ) );
    }

    // ==================================================================
    // missing_required_tags()
    // ==================================================================

    public function test_missing_required_tags_flags_all_when_layout_empty(): void {
        // No saved list → SettingsReader returns the default trio.
        Functions\when( 'get_option' )->justReturn( array() );

        $missing = $this->invoke( 'missing_required_tags', array( '<p>nothing here</p>' ) );
        $this->assertSame( array( '{{auth_code}}', '{{name}}', '{{cpf_rf}}' ), $missing );
    }

    public function test_missing_required_tags_empty_when_all_present(): void {
        Functions\when( 'get_option' )->justReturn( array() );

        $layout  = '{{auth_code}} {{name}} {{cpf_rf}}';
        $missing = $this->invoke( 'missing_required_tags', array( $layout ) );
        $this->assertSame( array(), $missing );
    }

    public function test_missing_required_tags_accepts_nome_alias_for_name(): void {
        Functions\when( 'get_option' )->justReturn( array() );

        // {{nome}} satisfies the {{name}} requirement.
        $layout  = '{{auth_code}} {{nome}} {{cpf_rf}}';
        $missing = $this->invoke( 'missing_required_tags', array( $layout ) );
        $this->assertSame( array(), $missing );
    }

    public function test_missing_required_tags_honours_configured_list(): void {
        Functions\when( 'get_option' )->alias( function ( $key ) {
            return ( 'ffc_settings' === $key )
                ? array( 'required_certificate_tags' => "{{auth_code}}\n{{course}}" )
                : array();
        } );

        $layout  = '{{auth_code}} only';
        $missing = $this->invoke( 'missing_required_tags', array( $layout ) );
        $this->assertSame( array( '{{course}}' ), $missing );
    }

    // ==================================================================
    // geofence_error_tab_keys()
    // ==================================================================

    public function test_geofence_error_tab_keys_area_error_maps_to_geolocation(): void {
        $config = array(
            'geo_gps_enabled' => '1',
            'geo_ip_enabled' => '0',
            'geo_ip_areas_permissive' => '0',
            'geo_areas' => '',
            'geo_ip_areas' => '',
        );
        $errors = $this->invoke( 'validate_geofence_config', array( $config ) );
        $keys   = $this->invoke( 'geofence_error_tab_keys', array( $config, $errors ) );
        $this->assertSame( array( 'geolocation' ), $keys );
    }

    public function test_geofence_error_tab_keys_datetime_error_maps_to_time(): void {
        $config = array(
            'datetime_enabled' => '1',
            'date_start' => '2026-12-31',
            'date_end'   => '2026-01-01',
        );
        $errors = $this->invoke( 'validate_geofence_config', array( $config ) );
        $this->assertNotEmpty( $errors );
        $keys = $this->invoke( 'geofence_error_tab_keys', array( $config, $errors ) );
        $this->assertSame( array( 'time' ), $keys );
    }

    public function test_geofence_error_tab_keys_combined_maps_to_both(): void {
        $config = array(
            'datetime_enabled' => '1',
            'date_start' => '2026-12-31',
            'date_end'   => '2026-01-01',
            'geo_gps_enabled' => '1',
            'geo_ip_enabled' => '0',
            'geo_ip_areas_permissive' => '0',
            'geo_areas' => '',
            'geo_ip_areas' => '',
        );
        $errors = $this->invoke( 'validate_geofence_config', array( $config ) );
        $keys   = $this->invoke( 'geofence_error_tab_keys', array( $config, $errors ) );
        $this->assertSame( array( 'time', 'geolocation' ), $keys );
    }

    // ==================================================================
    // validate_areas_format()
    // ==================================================================

    public function test_areas_valid_single_line(): void {
        $errors = $this->invoke( 'validate_areas_format', array( "-23.5505,-46.6333,500", 'GPS' ) );
        $this->assertSame( array(), $errors );
    }

    public function test_areas_valid_multiple_lines(): void {
        $areas = "-23.5505,-46.6333,500\n-22.9068,-43.1729,1000";
        $errors = $this->invoke( 'validate_areas_format', array( $areas, 'GPS' ) );
        $this->assertSame( array(), $errors );
    }

    public function test_areas_valid_with_spaces_around_commas(): void {
        $errors = $this->invoke( 'validate_areas_format', array( "-23.5505 , -46.6333 , 500", 'GPS' ) );
        $this->assertSame( array(), $errors );
    }

    public function test_areas_invalid_format_returns_error(): void {
        $errors = $this->invoke( 'validate_areas_format', array( "not_valid", 'GPS' ) );
        $this->assertCount( 1, $errors );
        $this->assertStringContainsString( 'Invalid format', $errors[0] );
        $this->assertStringContainsString( 'GPS', $errors[0] );
    }

    public function test_areas_latitude_out_of_range_high(): void {
        $errors = $this->invoke( 'validate_areas_format', array( "91.0,-46.6,500", 'GPS' ) );
        $this->assertNotEmpty( $errors );
        $this->assertStringContainsString( 'latitude', $errors[0] );
    }

    public function test_areas_latitude_out_of_range_low(): void {
        $errors = $this->invoke( 'validate_areas_format', array( "-91.0,-46.6,500", 'GPS' ) );
        $this->assertNotEmpty( $errors );
        $this->assertStringContainsString( 'latitude', $errors[0] );
    }

    public function test_areas_longitude_out_of_range_high(): void {
        $errors = $this->invoke( 'validate_areas_format', array( "23.5,181.0,500", 'GPS' ) );
        $this->assertNotEmpty( $errors );
        $this->assertStringContainsString( 'longitude', $errors[0] );
    }

    public function test_areas_longitude_out_of_range_low(): void {
        $errors = $this->invoke( 'validate_areas_format', array( "23.5,-181.0,500", 'GPS' ) );
        $this->assertNotEmpty( $errors );
        $this->assertStringContainsString( 'longitude', $errors[0] );
    }

    public function test_areas_zero_radius_returns_error(): void {
        $errors = $this->invoke( 'validate_areas_format', array( "23.5,-46.6,0", 'GPS' ) );
        $this->assertNotEmpty( $errors );
        $this->assertStringContainsString( 'Radius', $errors[0] );
    }

    public function test_areas_edge_latitude_90_valid(): void {
        $errors = $this->invoke( 'validate_areas_format', array( "90.0,-46.6,500", 'GPS' ) );
        $this->assertSame( array(), $errors );
    }

    public function test_areas_edge_latitude_minus90_valid(): void {
        $errors = $this->invoke( 'validate_areas_format', array( "-90.0,-46.6,500", 'GPS' ) );
        $this->assertSame( array(), $errors );
    }

    public function test_areas_edge_longitude_180_valid(): void {
        $errors = $this->invoke( 'validate_areas_format', array( "23.5,180.0,500", 'GPS' ) );
        $this->assertSame( array(), $errors );
    }

    public function test_areas_edge_longitude_minus180_valid(): void {
        $errors = $this->invoke( 'validate_areas_format', array( "23.5,-180.0,500", 'GPS' ) );
        $this->assertSame( array(), $errors );
    }

    public function test_areas_mixed_valid_and_invalid_lines(): void {
        $areas = "-23.5,-46.6,500\ninvalid\n91.0,-46.6,100";
        $errors = $this->invoke( 'validate_areas_format', array( $areas, 'IP' ) );
        // Line 2 has invalid format, line 3 has invalid latitude
        $this->assertCount( 2, $errors );
        $this->assertStringContainsString( 'IP', $errors[0] );
    }

    public function test_areas_empty_lines_skipped(): void {
        $areas = "-23.5,-46.6,500\n\n-22.9,-43.1,1000";
        $errors = $this->invoke( 'validate_areas_format', array( $areas, 'GPS' ) );
        $this->assertSame( array(), $errors );
    }

    public function test_areas_integer_coordinates_valid(): void {
        $errors = $this->invoke( 'validate_areas_format', array( "23,-46,500", 'GPS' ) );
        $this->assertSame( array(), $errors );
    }

    public function test_areas_type_label_appears_in_error(): void {
        $errors = $this->invoke( 'validate_areas_format', array( "bad", 'IP' ) );
        $this->assertStringContainsString( 'IP', $errors[0] );
    }

    // ==================================================================
    // save_form_data() — postpone-end one-shot reset
    // ==================================================================

    /**
     * Admin save must clear the postpone-end one-shot meta so the operator
     * can postpone again within the newly-configured close window.
     *
     * `EarlyOpenAction` has no persistent flag (its one-shot is structural:
     * once `date_start` is in the past, eligibility flips to `already_started`),
     * so only the postpone-end pair gets cleared here.
     */
    public function test_save_form_data_clears_postpone_end_one_shot(): void {
        Functions\when( 'wp_verify_nonce' )->justReturn( true );
        Functions\when( 'current_user_can' )->justReturn( true );
        Functions\when( 'wp_unslash' )->returnArg();
        Functions\when( 'sanitize_key' )->returnArg();
        Functions\when( 'sanitize_textarea_field' )->returnArg();
        Functions\when( 'absint' )->alias( static fn( $v ) => (int) $v );
        Functions\when( 'get_option' )->justReturn( array() );

        $deleted = array();
        Functions\when( 'delete_post_meta' )->alias(
            static function ( $id, $key ) use ( &$deleted ): bool {
                $deleted[] = array( (int) $id, (string) $key );
                return true;
            }
        );
        Functions\when( 'update_post_meta' )->justReturn( true );
        Functions\when( 'get_post_meta' )->justReturn( '' );
        Functions\when( 'set_transient' )->justReturn( true );

        $_POST = array( 'ffc_form_nonce' => 'abc' );

        $this->handler->save_form_data( 42 );

        $keys = array_column( $deleted, 1 );
        $this->assertContains( '_ffc_csv_public_end_postponed_at', $keys );
        $this->assertContains( '_ffc_csv_public_end_postponed_from', $keys );
        $this->assertContains( 42, array_column( $deleted, 0 ) );
    }

    public function test_save_form_data_skips_reset_when_nonce_invalid(): void {
        Functions\when( 'wp_verify_nonce' )->justReturn( false );
        Functions\when( 'current_user_can' )->justReturn( true );
        Functions\when( 'wp_unslash' )->returnArg();

        $deleted = array();
        Functions\when( 'delete_post_meta' )->alias(
            static function ( $id, $key ) use ( &$deleted ): bool {
                $deleted[] = $key;
                return true;
            }
        );

        $_POST = array( 'ffc_form_nonce' => 'bad' );
        $this->handler->save_form_data( 42 );

        $this->assertSame( array(), $deleted, 'No deletes when nonce check fails.' );
    }

    // ==================================================================
    // Sprint 2 / #238 — skip-on-off save semantics
    // ==================================================================

    /**
     * Common stub setup for save_form_data() integration tests:
     * green nonce + caps + helper stubs.
     */
    private function stub_for_save(): array {
        Functions\when( 'wp_verify_nonce' )->justReturn( true );
        Functions\when( 'current_user_can' )->justReturn( true );
        Functions\when( 'wp_unslash' )->returnArg();
        Functions\when( 'sanitize_key' )->returnArg();
        Functions\when( 'sanitize_textarea_field' )->returnArg();
        Functions\when( 'sanitize_text_field' )->returnArg();
        Functions\when( 'wp_kses' )->alias( static fn( $s ) => (string) $s );
        Functions\when( 'wp_kses_post' )->returnArg();
        Functions\when( 'esc_url_raw' )->returnArg();
        Functions\when( 'absint' )->alias( static fn( $v ) => (int) $v );
        Functions\when( 'delete_post_meta' )->justReturn( true );
        Functions\when( 'set_transient' )->justReturn( true );
        Functions\when( 'get_option' )->justReturn( array() );
        Functions\when( 'get_current_user_id' )->justReturn( 1 );
        // Cache helpers used by the geofence save path's purge calls.
        Functions\when( 'wp_cache_delete' )->justReturn( true );
        Functions\when( 'wp_cache_flush_group' )->justReturn( true );
        Functions\when( 'do_action' )->justReturn( null );

        $written = array();
        Functions\when( 'update_post_meta' )->alias(
            static function ( $id, $key, $value ) use ( &$written ): bool {
                $written[ $key ] = $value;
                return true;
            }
        );
        return [ &$written ];
    }

    /**
     * Email send_user_email OFF → email_subject and email_body are NOT in
     * the new $clean_config. The merge with $current_config preserves the
     * prior values. (Sprint 2)
     */
    public function test_email_off_preserves_subject_and_body(): void {
        $bag = $this->stub_for_save();
        $written = &$bag[0];

        Functions\when( 'get_post_meta' )->alias( static fn( $id, $key ) => array() );

        $_POST = array(
            'ffc_form_nonce' => 'ok',
            'ffc_config'     => array(
                'pdf_layout'       => '{{auth_code}} {{name}} {{cpf_rf}}',
                'send_user_email'  => '0',
                'email_subject'    => 'fresh subject from form',
                'email_body'       => 'fresh body from form',
            ),
        );

        $this->handler->save_form_data( 10 );

        $form_config = $written['_ffc_form_config'];
        $this->assertSame( '0', $form_config['send_user_email'] );
        $this->assertArrayNotHasKey( 'email_subject', $form_config, 'email_subject must NOT be written when send_user_email is off' );
        $this->assertArrayNotHasKey( 'email_body', $form_config, 'email_body must NOT be written when send_user_email is off' );
    }

    public function test_email_on_writes_subject_and_body(): void {
        $bag = $this->stub_for_save();
        $written = &$bag[0];

        Functions\when( 'get_post_meta' )->alias( static fn( $id, $key ) => array() );

        $_POST = array(
            'ffc_form_nonce' => 'ok',
            'ffc_config'     => array(
                'pdf_layout'       => '{{auth_code}} {{name}} {{cpf_rf}}',
                'send_user_email'  => '1',
                'email_subject'    => 'hello',
                'email_body'       => '<p>body</p>',
            ),
        );

        $this->handler->save_form_data( 10 );

        $form_config = $written['_ffc_form_config'];
        $this->assertSame( '1', $form_config['send_user_email'] );
        $this->assertSame( 'hello', $form_config['email_subject'] );
        $this->assertSame( '<p>body</p>', $form_config['email_body'] );
    }

    /**
     * The 4 restriction toggles independently gate their own data fields.
     * Each off → the corresponding meta is omitted from the new write.
     */
    public function test_restriction_toggles_off_skip_their_data_fields(): void {
        $bag = $this->stub_for_save();
        $written = &$bag[0];

        Functions\when( 'get_post_meta' )->alias( static fn( $id, $key ) => array() );

        $_POST = array(
            'ffc_form_nonce' => 'ok',
            'ffc_config'     => array(
                'pdf_layout'           => '{{auth_code}} {{name}} {{cpf_rf}}',
                'restrictions'         => array(),   // ALL four off
                'allowed_users_list'   => 'fresh-allow',
                'denied_users_list'    => 'fresh-deny',
                'validation_code'      => 'fresh-pass',
                'generated_codes_list' => 'fresh-tickets',
            ),
        );

        $this->handler->save_form_data( 10 );

        $form_config = $written['_ffc_form_config'];
        $this->assertArrayNotHasKey( 'allowed_users_list', $form_config );
        $this->assertArrayNotHasKey( 'denied_users_list', $form_config );
        $this->assertArrayNotHasKey( 'validation_code', $form_config );
        $this->assertArrayNotHasKey( 'generated_codes_list', $form_config );
        // The 4 toggle states themselves ARE recorded.
        $this->assertSame( '0', $form_config['restrictions']['password'] );
        $this->assertSame( '0', $form_config['restrictions']['allowlist'] );
        $this->assertSame( '0', $form_config['restrictions']['denylist'] );
        $this->assertSame( '0', $form_config['restrictions']['ticket'] );
    }

    public function test_restriction_password_only_on_writes_validation_code_only(): void {
        $bag = $this->stub_for_save();
        $written = &$bag[0];

        Functions\when( 'get_post_meta' )->alias( static fn( $id, $key ) => array() );

        $_POST = array(
            'ffc_form_nonce' => 'ok',
            'ffc_config'     => array(
                'pdf_layout'           => '{{auth_code}} {{name}} {{cpf_rf}}',
                'restrictions'         => array( 'password' => '1' ),
                'validation_code'      => 'secret',
                'allowed_users_list'   => 'should-not-save',
                'denied_users_list'    => 'should-not-save',
                'generated_codes_list' => 'should-not-save',
            ),
        );

        $this->handler->save_form_data( 10 );

        $form_config = $written['_ffc_form_config'];
        $this->assertSame( 'secret', $form_config['validation_code'] );
        $this->assertArrayNotHasKey( 'allowed_users_list', $form_config );
        $this->assertArrayNotHasKey( 'denied_users_list', $form_config );
        $this->assertArrayNotHasKey( 'generated_codes_list', $form_config );
    }

    /**
     * Quiz quiz_enabled OFF → 4 sub-options skipped; merge preserves old.
     */
    public function test_quiz_off_skips_sub_options(): void {
        $bag = $this->stub_for_save();
        $written = &$bag[0];

        Functions\when( 'get_post_meta' )->alias( static fn( $id, $key ) => array() );

        $_POST = array(
            'ffc_form_nonce' => 'ok',
            'ffc_config'     => array(
                'pdf_layout'         => '{{auth_code}} {{name}} {{cpf_rf}}',
                // quiz_enabled key absent → '0'
                'quiz_passing_score' => '99',
                'quiz_max_attempts'  => '5',
                'quiz_show_score'    => '1',
                'quiz_show_correct'  => '1',
            ),
        );

        $this->handler->save_form_data( 10 );

        $form_config = $written['_ffc_form_config'];
        $this->assertSame( '0', $form_config['quiz_enabled'] );
        $this->assertArrayNotHasKey( 'quiz_passing_score', $form_config );
        $this->assertArrayNotHasKey( 'quiz_max_attempts', $form_config );
        $this->assertArrayNotHasKey( 'quiz_show_score', $form_config );
        $this->assertArrayNotHasKey( 'quiz_show_correct', $form_config );
    }

    /**
     * DateTime datetime_enabled OFF → 9 sub-options skipped; merge picks up
     * preserved values from the prior _ffc_geofence_config meta.
     */
    public function test_datetime_off_preserves_via_merge_with_current_config(): void {
        $bag = $this->stub_for_save();
        $written = &$bag[0];

        $existing_geofence = array(
            'datetime_enabled' => '1',
            'date_start'       => '2024-01-01',
            'date_end'         => '2024-01-31',
            'time_start'       => '08:00',
            'time_end'         => '18:00',
            'time_mode'        => 'daily',
            'msg_datetime'     => 'old message',
        );
        Functions\when( 'get_post_meta' )->alias(
            static function ( $id, $key ) use ( $existing_geofence ) {
                if ( '_ffc_geofence_config' === $key ) {
                    return $existing_geofence;
                }
                return array();
            }
        );

        $_POST = array(
            'ffc_form_nonce' => 'ok',
            'ffc_geofence'   => array(
                // datetime_enabled absent → '0'
                'date_start'   => 'should-not-overwrite',
                'time_start'   => 'should-not-overwrite',
                'msg_datetime' => 'spurious value',
            ),
        );

        $this->handler->save_form_data( 10 );

        $merged = $written['_ffc_geofence_config'];
        $this->assertSame( '0', $merged['datetime_enabled'], 'toggle state recorded as off' );
        $this->assertSame( '2024-01-01', $merged['date_start'], 'date_start preserved from prior config' );
        $this->assertSame( '08:00', $merged['time_start'], 'time_start preserved' );
        $this->assertSame( 'old message', $merged['msg_datetime'], 'msg_datetime preserved' );
    }

    /**
     * Geolocation geo_enabled OFF → all geo sub-options skipped, including
     * the nested permissive ones. Merge preserves prior values.
     */
    public function test_geolocation_off_preserves_via_merge(): void {
        $bag = $this->stub_for_save();
        $written = &$bag[0];

        $existing = array(
            'geo_enabled'      => '1',
            'geo_gps_enabled'  => '1',
            'geo_areas'        => '-23.55,-46.63,500',
            'geo_hide_mode'    => 'message',
            'msg_geo_blocked'  => 'old blocked',
        );
        Functions\when( 'get_post_meta' )->alias(
            static fn( $id, $key ) => ( '_ffc_geofence_config' === $key ) ? $existing : array()
        );

        $_POST = array(
            'ffc_form_nonce' => 'ok',
            'ffc_geofence'   => array(
                // geo_enabled absent → '0'
                'geo_areas'       => 'should-not-overwrite',
                'msg_geo_blocked' => 'spurious',
            ),
        );

        $this->handler->save_form_data( 10 );

        $merged = $written['_ffc_geofence_config'];
        $this->assertSame( '0', $merged['geo_enabled'] );
        $this->assertSame( '-23.55,-46.63,500', $merged['geo_areas'] );
        $this->assertSame( 'old blocked', $merged['msg_geo_blocked'] );
    }

    /**
     * Nested toggle: geo_enabled ON + geo_ip_areas_permissive OFF →
     * permissive sub-options (ip_area_source / ids / areas) preserved.
     */
    public function test_ip_permissive_off_preserves_ip_area_fields(): void {
        $bag = $this->stub_for_save();
        $written = &$bag[0];

        $existing = array(
            'geo_ip_areas_permissive'  => '1',
            'geo_ip_area_source'       => 'custom',
            'geo_ip_areas'             => 'old-ip-areas',
        );
        Functions\when( 'get_post_meta' )->alias(
            static fn( $id, $key ) => ( '_ffc_geofence_config' === $key ) ? $existing : array()
        );

        $_POST = array(
            'ffc_form_nonce' => 'ok',
            'ffc_geofence'   => array(
                'geo_enabled'   => '1',                 // outer master on
                // geo_ip_areas_permissive absent → '0'
                'geo_ip_areas'  => 'should-not-overwrite',
            ),
        );

        $this->handler->save_form_data( 10 );

        $merged = $written['_ffc_geofence_config'];
        $this->assertSame( '0', $merged['geo_ip_areas_permissive'] );
        $this->assertSame( 'old-ip-areas', $merged['geo_ip_areas'], 'IP areas preserved when permissive off' );
    }

    // ==================================================================
    // sanitize_schedule_exception_config() (#366 Sprint 2)
    // ==================================================================

    public function test_schedule_exception_toggle_absent_defaults_to_off(): void {
        $result = $this->invoke_static( 'sanitize_schedule_exception_config', array( array() ) );

        $this->assertSame( '0', $result['schedule_exception_enabled'] );
        $this->assertSame( '', $result['class_time_start'] );
        $this->assertSame( '', $result['class_time_end'] );
        $this->assertSame( 'now', $result['schedule_default_mode'], 'default mode defaults to "now" when unset' );
    }

    public function test_schedule_exception_toggle_present_records_on(): void {
        $result = $this->invoke_static( 'sanitize_schedule_exception_config', array( array(
            'schedule_exception_enabled' => '1',
            'class_time_start'           => '08:00',
            'class_time_end'             => '19:30',
            'schedule_default_mode'      => 'manual',
        ) ) );

        $this->assertSame( '1', $result['schedule_exception_enabled'] );
        $this->assertSame( '08:00', $result['class_time_start'] );
        $this->assertSame( '19:30', $result['class_time_end'] );
        $this->assertSame( 'manual', $result['schedule_default_mode'] );
    }

    public function test_schedule_exception_default_mode_whitelists(): void {
        $result = $this->invoke_static( 'sanitize_schedule_exception_config', array( array(
            'schedule_default_mode' => 'whatever-the-attacker-sent',
        ) ) );

        $this->assertSame( 'now', $result['schedule_default_mode'], 'unknown mode folds back to "now"' );
    }

    public function test_schedule_exception_empty_class_times_stay_empty(): void {
        // Empty strings preserve the "fall back to geofence baseline"
        // semantic. The helper must NOT replace them with a default time.
        $result = $this->invoke_static( 'sanitize_schedule_exception_config', array( array(
            'schedule_exception_enabled' => '1',
            'class_time_start'           => '',
            'class_time_end'             => '',
        ) ) );

        $this->assertSame( '', $result['class_time_start'] );
        $this->assertSame( '', $result['class_time_end'] );
    }

    public function test_save_form_data_persists_schedule_exception_keys(): void {
        $bag     = $this->stub_for_save();
        $written = &$bag[0];

        Functions\when( 'get_post_meta' )->justReturn( array() );

        $_POST = array(
            'ffc_form_nonce' => 'ok',
            'ffc_geofence'   => array(
                'schedule_exception_enabled' => '1',
                'class_time_start'           => '08:00',
                'class_time_end'             => '17:30',
                'schedule_default_mode'      => 'manual',
            ),
        );

        $this->handler->save_form_data( 10 );

        $this->assertArrayHasKey( '_ffc_geofence_config', $written );
        $merged = $written['_ffc_geofence_config'];
        $this->assertSame( '1', $merged['schedule_exception_enabled'] );
        $this->assertSame( '08:00', $merged['class_time_start'] );
        $this->assertSame( '17:30', $merged['class_time_end'] );
        $this->assertSame( 'manual', $merged['schedule_default_mode'] );
    }
}
