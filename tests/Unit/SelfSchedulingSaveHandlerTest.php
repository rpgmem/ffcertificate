<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\SelfScheduling\SelfSchedulingSaveHandler;

/**
 * Tests for SelfSchedulingSaveHandler: config, working hours, email config sanitization.
 *
 * Uses Reflection to access private methods; sets $_POST data to simulate form submission.
 */
class SelfSchedulingSaveHandlerTest extends TestCase {

    use MockeryPHPUnitIntegration;

    /** @var SelfSchedulingSaveHandler */
    private $handler;

    /** @var array Captured update_post_meta calls */
    private $saved_meta = array();

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        Functions\when( 'sanitize_text_field' )->returnArg();
        Functions\when( 'sanitize_textarea_field' )->returnArg();
        Functions\when( 'absint' )->alias( function ( $val ) {
            return abs( (int) $val );
        } );
        Functions\when( 'wp_unslash' )->returnArg();
        Functions\when( 'add_action' )->justReturn( true );

        // Capture update_post_meta calls
        $saved = &$this->saved_meta;
        Functions\when( 'update_post_meta' )->alias( function ( $post_id, $key, $value ) use ( &$saved ) {
            $saved[ $key ] = $value;
            return true;
        } );

        $this->handler = new SelfSchedulingSaveHandler();
    }

    protected function tearDown(): void {
        unset(
            $_POST['ffc_self_scheduling_config'],
            $_POST['ffc_self_scheduling_working_hours'],
            $_POST['ffc_self_scheduling_email_config']
        );
        $this->saved_meta = array();
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * Invoke a private method on the handler.
     */
    private function invoke( string $method, array $args = [] ) {
        $ref = new \ReflectionMethod( SelfSchedulingSaveHandler::class, $method );
        $ref->setAccessible( true );
        return $ref->invokeArgs( $this->handler, $args );
    }

    // ==================================================================
    // save_config()
    // ==================================================================

    public function test_config_slot_duration_sanitized_as_int(): void {
        $_POST['ffc_self_scheduling_config'] = array( 'slot_duration' => '45' );
        $this->invoke( 'save_config', array( 100 ) );
        $this->assertSame( 45, $this->saved_meta['_ffc_self_scheduling_config']['slot_duration'] );
    }

    public function test_config_defaults_for_missing_fields(): void {
        $_POST['ffc_self_scheduling_config'] = array();
        $this->invoke( 'save_config', array( 100 ) );
        $config = $this->saved_meta['_ffc_self_scheduling_config'];
        $this->assertSame( 30, $config['slot_duration'] );
        $this->assertSame( 0, $config['slot_interval'] );
        $this->assertSame( 1, $config['max_appointments_per_slot'] );
        $this->assertSame( 30, $config['advance_booking_max'] );
    }

    public function test_config_boolean_toggles_present(): void {
        $_POST['ffc_self_scheduling_config'] = array(
            'allow_cancellation'       => '1',
            'requires_approval'        => '1',
            'restrict_viewing_to_hours' => '1',
            'restrict_booking_to_hours' => '1',
        );
        $this->invoke( 'save_config', array( 100 ) );
        $config = $this->saved_meta['_ffc_self_scheduling_config'];
        $this->assertSame( 1, $config['allow_cancellation'] );
        $this->assertSame( 1, $config['requires_approval'] );
        $this->assertSame( 1, $config['restrict_viewing_to_hours'] );
        $this->assertSame( 1, $config['restrict_booking_to_hours'] );
    }

    public function test_config_boolean_toggles_absent(): void {
        $_POST['ffc_self_scheduling_config'] = array();
        $this->invoke( 'save_config', array( 100 ) );
        $config = $this->saved_meta['_ffc_self_scheduling_config'];
        $this->assertSame( 0, $config['allow_cancellation'] );
        $this->assertSame( 0, $config['requires_approval'] );
        $this->assertSame( 0, $config['restrict_viewing_to_hours'] );
        $this->assertSame( 0, $config['restrict_booking_to_hours'] );
    }

    public function test_config_visibility_valid_values(): void {
        $_POST['ffc_self_scheduling_config'] = array(
            'visibility'              => 'private',
            'scheduling_visibility'   => 'private',
        );
        $this->invoke( 'save_config', array( 100 ) );
        $config = $this->saved_meta['_ffc_self_scheduling_config'];
        $this->assertSame( 'private', $config['visibility'] );
        $this->assertSame( 'private', $config['scheduling_visibility'] );
    }

    public function test_config_visibility_invalid_defaults_to_public(): void {
        $_POST['ffc_self_scheduling_config'] = array(
            'visibility'            => 'invalid',
            'scheduling_visibility' => 'other',
        );
        $this->invoke( 'save_config', array( 100 ) );
        $config = $this->saved_meta['_ffc_self_scheduling_config'];
        $this->assertSame( 'public', $config['visibility'] );
        $this->assertSame( 'public', $config['scheduling_visibility'] );
    }

    public function test_config_private_visibility_forces_scheduling_private(): void {
        $_POST['ffc_self_scheduling_config'] = array(
            'visibility'            => 'private',
            'scheduling_visibility' => 'public',
        );
        $this->invoke( 'save_config', array( 100 ) );
        $config = $this->saved_meta['_ffc_self_scheduling_config'];
        $this->assertSame( 'private', $config['scheduling_visibility'] );
    }

    public function test_config_description_sanitized(): void {
        $_POST['ffc_self_scheduling_config'] = array( 'description' => 'A test description' );
        $this->invoke( 'save_config', array( 100 ) );
        $this->assertSame( 'A test description', $this->saved_meta['_ffc_self_scheduling_config']['description'] );
    }

    public function test_config_no_post_data_skips_save(): void {
        // No $_POST['ffc_self_scheduling_config'] set
        $this->invoke( 'save_config', array( 100 ) );
        $this->assertArrayNotHasKey( '_ffc_self_scheduling_config', $this->saved_meta );
    }

    // ==================================================================
    // save_working_hours()
    // ==================================================================

    public function test_working_hours_sanitized(): void {
        $_POST['ffc_self_scheduling_working_hours'] = array(
            array( 'day' => '1', 'start' => '09:00', 'end' => '17:00' ),
            array( 'day' => '2', 'start' => '08:00', 'end' => '18:00' ),
        );
        $this->invoke( 'save_working_hours', array( 100 ) );
        $hours = $this->saved_meta['_ffc_self_scheduling_working_hours'];
        $this->assertCount( 2, $hours );
        $this->assertSame( 1, $hours[0]['day'] );
        $this->assertSame( '09:00', $hours[0]['start'] );
        $this->assertSame( '17:00', $hours[0]['end'] );
        $this->assertSame( 2, $hours[1]['day'] );
    }

    public function test_working_hours_defaults_for_missing_fields(): void {
        $_POST['ffc_self_scheduling_working_hours'] = array(
            array(), // all missing
        );
        $this->invoke( 'save_working_hours', array( 100 ) );
        $hours = $this->saved_meta['_ffc_self_scheduling_working_hours'];
        $this->assertSame( 0, $hours[0]['day'] );
        $this->assertSame( '09:00', $hours[0]['start'] );
        $this->assertSame( '17:00', $hours[0]['end'] );
    }

    public function test_working_hours_no_post_data_skips_save(): void {
        $this->invoke( 'save_working_hours', array( 100 ) );
        $this->assertArrayNotHasKey( '_ffc_self_scheduling_working_hours', $this->saved_meta );
    }

    // ==================================================================
    // save_email_config()
    // ==================================================================

    public function test_email_config_boolean_toggles(): void {
        $_POST['ffc_self_scheduling_email_config'] = array(
            'send_user_confirmation'       => '1',
            'send_admin_notification'      => '1',
            'send_approval_notification'   => '1',
            'send_cancellation_notification' => '1',
            'send_reminder'                => '1',
        );
        $this->invoke( 'save_email_config', array( 100 ) );
        $config = $this->saved_meta['_ffc_self_scheduling_email_config'];
        $this->assertSame( 1, $config['send_user_confirmation'] );
        $this->assertSame( 1, $config['send_admin_notification'] );
        $this->assertSame( 1, $config['send_approval_notification'] );
        $this->assertSame( 1, $config['send_cancellation_notification'] );
        $this->assertSame( 1, $config['send_reminder'] );
    }

    public function test_email_config_boolean_toggles_absent(): void {
        $_POST['ffc_self_scheduling_email_config'] = array();
        $this->invoke( 'save_email_config', array( 100 ) );
        $config = $this->saved_meta['_ffc_self_scheduling_email_config'];
        $this->assertSame( 0, $config['send_user_confirmation'] );
        $this->assertSame( 0, $config['send_admin_notification'] );
        $this->assertSame( 0, $config['send_reminder'] );
    }

    public function test_email_config_reminder_hours_sanitized(): void {
        $_POST['ffc_self_scheduling_email_config'] = array( 'reminder_hours_before' => '48' );
        $this->invoke( 'save_email_config', array( 100 ) );
        $this->assertSame( 48, $this->saved_meta['_ffc_self_scheduling_email_config']['reminder_hours_before'] );
    }

    public function test_email_config_text_fields_sanitized(): void {
        $_POST['ffc_self_scheduling_email_config'] = array(
            'admin_emails'               => 'admin@test.com',
            'user_confirmation_subject'  => 'Your appointment',
            'user_confirmation_body'     => 'Details here',
        );
        $this->invoke( 'save_email_config', array( 100 ) );
        $config = $this->saved_meta['_ffc_self_scheduling_email_config'];
        $this->assertSame( 'admin@test.com', $config['admin_emails'] );
        $this->assertSame( 'Your appointment', $config['user_confirmation_subject'] );
        $this->assertSame( 'Details here', $config['user_confirmation_body'] );
    }

    public function test_email_config_no_post_data_skips_save(): void {
        $this->invoke( 'save_email_config', array( 100 ) );
        $this->assertArrayNotHasKey( '_ffc_self_scheduling_email_config', $this->saved_meta );
    }
}
