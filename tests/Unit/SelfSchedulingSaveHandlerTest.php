<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
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
        Functions\when( 'get_post_meta' )->justReturn( false );

        // $wpdb mock — no appointments by default, so the #941 booking locks
        // stay inactive for the plain parse tests.
        global $wpdb;
        $wpdb         = Mockery::mock();
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive( 'prepare' )->andReturnUsing(
            function ( $query ) {
                return $query;
            }
        );
        $wpdb->shouldReceive( 'get_var' )->andReturn( 0 )->byDefault();
        $wpdb->shouldReceive( 'get_results' )->andReturn( array() )->byDefault();
        $GLOBALS['wpdb'] = $wpdb;

        $this->handler = new SelfSchedulingSaveHandler();
    }

    protected function tearDown(): void {
        unset(
            $_POST['ffc_self_scheduling_config'],
            $_POST['ffc_self_scheduling_working_hours'],
            $_POST['ffc_self_scheduling_custom_slots'],
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

    public function test_config_waitlist_enabled_and_capacity_parsed(): void {
        $_POST['ffc_self_scheduling_config'] = array(
            'waitlist_enabled'  => 'on',
            'waitlist_capacity' => '5',
        );
        $this->invoke( 'save_config', array( 100 ) );
        $this->assertSame( 1, $this->saved_meta['_ffc_self_scheduling_config']['waitlist_enabled'] );
        $this->assertSame( 5, $this->saved_meta['_ffc_self_scheduling_config']['waitlist_capacity'] );
    }

    public function test_config_waitlist_defaults_off_when_absent(): void {
        $_POST['ffc_self_scheduling_config'] = array( 'slot_duration' => '30' );
        $this->invoke( 'save_config', array( 100 ) );
        $this->assertSame( 0, $this->saved_meta['_ffc_self_scheduling_config']['waitlist_enabled'] );
        $this->assertSame( 0, $this->saved_meta['_ffc_self_scheduling_config']['waitlist_capacity'] );
    }

    public function test_config_max_blocks_per_user_parsed(): void {
        $_POST['ffc_self_scheduling_config'] = array( 'max_blocks_per_user' => '3' );
        $this->invoke( 'save_config', array( 100 ) );
        $this->assertSame( 3, $this->saved_meta['_ffc_self_scheduling_config']['max_blocks_per_user'] );
    }

    public function test_config_max_blocks_per_user_defaults_zero(): void {
        $_POST['ffc_self_scheduling_config'] = array( 'slot_duration' => '30' );
        $this->invoke( 'save_config', array( 100 ) );
        $this->assertSame( 0, $this->saved_meta['_ffc_self_scheduling_config']['max_blocks_per_user'] );
    }

    // ==================================================================
    // schedule_type + save_custom_slots() (#941)
    // ==================================================================

    public function test_config_schedule_type_custom_accepted(): void {
        $_POST['ffc_self_scheduling_config'] = array( 'schedule_type' => 'custom' );
        $this->invoke( 'save_config', array( 100 ) );
        $this->assertSame( 'custom', $this->saved_meta['_ffc_self_scheduling_config']['schedule_type'] );
    }

    public function test_config_schedule_type_unknown_defaults_regular(): void {
        $_POST['ffc_self_scheduling_config'] = array( 'schedule_type' => 'bogus' );
        $this->invoke( 'save_config', array( 100 ) );
        $this->assertSame( 'regular', $this->saved_meta['_ffc_self_scheduling_config']['schedule_type'] );
    }

    public function test_custom_slots_parsed_normalized_and_sorted(): void {
        $_POST['ffc_self_scheduling_custom_slots'] = array(
            array( 'date' => '2026-09-05', 'start' => '8:00', 'end' => '13:00', 'capacity' => '20', 'label' => 'B' ),
            array( 'date' => '2026-09-03', 'start' => '14:00', 'end' => '18:00', 'capacity' => '40' ),
            array( 'date' => '2026-09-03', 'start' => '08:00', 'end' => '13:00', 'capacity' => '40', 'label' => 'A' ),
        );
        $this->invoke( 'save_custom_slots', array( 100 ) );
        $blocks = $this->saved_meta['_ffc_self_scheduling_custom_slots'];

        $this->assertCount( 3, $blocks );
        // Sorted by (date, start); '8:00' normalized to '08:00'.
        $this->assertSame( array( '2026-09-03', '08:00', 'A' ), array( $blocks[0]['date'], $blocks[0]['start'], $blocks[0]['label'] ) );
        $this->assertSame( array( '2026-09-03', '14:00' ), array( $blocks[1]['date'], $blocks[1]['start'] ) );
        $this->assertSame( array( '2026-09-05', '08:00', 20 ), array( $blocks[2]['date'], $blocks[2]['start'], $blocks[2]['capacity'] ) );
    }

    public function test_custom_slots_drops_invalid_and_clamps_capacity(): void {
        $_POST['ffc_self_scheduling_custom_slots'] = array(
            array( 'date' => '2026-13-01', 'start' => '08:00', 'end' => '13:00', 'capacity' => '5' ), // bad month
            array( 'date' => '2026-09-03', 'start' => '13:00', 'end' => '08:00', 'capacity' => '5' ), // start >= end
            array( 'date' => '2026-09-03', 'start' => '08:00', 'end' => '13:00', 'capacity' => '0' ), // capacity clamps to 1
            'scalar-row',
        );
        $this->invoke( 'save_custom_slots', array( 100 ) );
        $blocks = $this->saved_meta['_ffc_self_scheduling_custom_slots'];

        $this->assertCount( 1, $blocks );
        $this->assertSame( 1, $blocks[0]['capacity'] );
    }

    public function test_custom_slots_dedupes_date_start_keeping_first(): void {
        $_POST['ffc_self_scheduling_custom_slots'] = array(
            array( 'date' => '2026-09-03', 'start' => '08:00', 'end' => '13:00', 'capacity' => '40', 'label' => 'first' ),
            array( 'date' => '2026-09-03', 'start' => '08:00', 'end' => '18:00', 'capacity' => '99', 'label' => 'dup' ),
        );
        $this->invoke( 'save_custom_slots', array( 100 ) );
        $blocks = $this->saved_meta['_ffc_self_scheduling_custom_slots'];

        $this->assertCount( 1, $blocks );
        $this->assertSame( 'first', $blocks[0]['label'] );
    }

    public function test_custom_slots_absent_field_is_noop(): void {
        $this->invoke( 'save_custom_slots', array( 100 ) );
        $this->assertArrayNotHasKey( '_ffc_self_scheduling_custom_slots', $this->saved_meta );
    }

    public function test_schedule_type_locked_to_stored_when_bookings_exist(): void {
        global $wpdb;
        $wpdb->shouldReceive( 'get_var' )->andReturn( 3 ); // calendar has bookings
        Functions\when( 'get_post_meta' )->justReturn( array( 'schedule_type' => 'regular' ) );

        $_POST['ffc_self_scheduling_config'] = array( 'schedule_type' => 'custom' ); // attempt to switch
        $this->invoke( 'save_config', array( 100 ) );

        $this->assertSame( 'regular', $this->saved_meta['_ffc_self_scheduling_config']['schedule_type'] );
    }

    public function test_booked_block_is_preserved_when_removed(): void {
        global $wpdb;
        $wpdb->shouldReceive( 'get_var' )->andReturn( 1 );
        $wpdb->shouldReceive( 'get_results' )->andReturn(
            array( array( 'd' => '2026-09-03', 's' => '08:00:00' ) ) // a booked (date, start)
        );
        Functions\when( 'get_post_meta' )->justReturn(
            array(
                array( 'date' => '2026-09-03', 'start' => '08:00', 'end' => '13:00', 'capacity' => 40, 'label' => 'orig' ),
            )
        );

        // Incoming tries to drop the booked block and add a different one.
        $_POST['ffc_self_scheduling_custom_slots'] = array(
            array( 'date' => '2026-09-10', 'start' => '09:00', 'end' => '12:00', 'capacity' => '10' ),
        );
        $this->invoke( 'save_custom_slots', array( 100 ) );

        $keys = array_map(
            static function ( $b ) {
                return $b['date'] . ' ' . $b['start'];
            },
            $this->saved_meta['_ffc_self_scheduling_custom_slots']
        );
        $this->assertContains( '2026-09-03 08:00', $keys, 'the booked block is restored' );
        $this->assertContains( '2026-09-10 09:00', $keys, 'the new block is kept' );
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

    // ==================================================================
    // save_calendar_data() — security guards + orchestration
    // ==================================================================

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_save_calendar_data_skips_on_autosave(): void {
        define( 'DOING_AUTOSAVE', true );
        $nonce_checked = false;
        Functions\when( 'wp_verify_nonce' )->alias( function () use ( &$nonce_checked ) {
            $nonce_checked = true;
            return true;
        } );

        $this->handler->save_calendar_data( 1, new \stdClass(), false );

        $this->assertFalse( $nonce_checked, 'autosave returns before the nonce check' );
    }

    public function test_save_calendar_data_skips_on_invalid_nonce(): void {
        Functions\when( 'wp_verify_nonce' )->justReturn( false );
        $cap_checked = false;
        Functions\when( 'current_user_can' )->alias( function () use ( &$cap_checked ) {
            $cap_checked = true;
            return true;
        } );

        $this->handler->save_calendar_data( 1, new \stdClass(), false );

        $this->assertFalse( $cap_checked, 'invalid nonce returns before the capability check' );
    }

    public function test_save_calendar_data_skips_without_capability(): void {
        Functions\when( 'wp_verify_nonce' )->justReturn( true );
        Functions\when( 'current_user_can' )->justReturn( false );

        $this->handler->save_calendar_data( 1, new \stdClass(), false );

        $this->assertEmpty( $this->saved_meta, 'no writes when the capability is missing' );
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_save_calendar_data_orchestrates_saves_and_purges_cache(): void {
        Functions\when( 'wp_verify_nonce' )->justReturn( true );
        Functions\when( 'current_user_can' )->justReturn( true );
        $purged = false;
        Mockery::mock( 'alias:\FreeFormCertificate\Submissions\FormCache' )
            ->shouldReceive( 'purge_page_cache' )->andReturnUsing( function () use ( &$purged ) {
                $purged = true;
            } );

        // No $_POST config → the three save_* helpers skip their writes; the
        // orchestration still runs through to the cache purge.
        $this->handler->save_calendar_data( 1, new \stdClass(), false );

        $this->assertTrue( $purged );
    }
}
