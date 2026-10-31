<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use FreeFormCertificate\Tests\Support\CallLog;
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

	/** @var mixed The `$wpdb` global an earlier test left, restored afterwards. */
	private $wpdb_backup;

	/** @var bool Whether `$wpdb` was set before this test. */
	private bool $had_wpdb = false;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'sanitize_textarea_field' )->returnArg();
		// The confirmation body is now sanitised with wp_kses_post (#965).
		Functions\when( 'wp_kses_post' )->returnArg();
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

		$this->had_wpdb    = array_key_exists( 'wpdb', $GLOBALS );
		$this->wpdb_backup = $GLOBALS['wpdb'] ?? null;

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
			$_POST['ffc_self_scheduling_email_config'],
			$_POST['ffc_calendar_names']
		);
		$this->saved_meta = array();
		if ( $this->had_wpdb ) {
			$GLOBALS['wpdb'] = $this->wpdb_backup;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
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
	// save_names() (#2223)
	// ==================================================================

	public function test_names_store_only_what_the_calendar_sets(): void {
		$deleted = CallLog::record( 'delete_post_meta', true );
		$_POST['ffc_calendar_names'] = array(
			'legend'            => array( 'closed' => 'Sem expediente', 'available' => '', 'bogus' => 'x' ),
			'cell'              => array( 'booking_plural' => 'sessões' ),
			'show_closed_label' => 'hide',
		);
		$this->invoke( 'save_names', array( 100 ) );

		$this->assertSame(
			array(
				'legend'            => array( 'closed' => 'Sem expediente' ),
				'cell'              => array( 'booking_plural' => 'sessões' ),
				'show_closed_label' => 'hide',
			),
			$this->saved_meta['_ffc_self_scheduling_names']
		);
		$this->assertSame( 0, $deleted->count() );
	}

	public function test_names_left_empty_remove_the_override(): void {
		$deleted = CallLog::record( 'delete_post_meta', true );
		$_POST['ffc_calendar_names'] = array(
			'legend'            => array( 'closed' => '   ' ),
			'show_closed_label' => 'inherit',
		);
		$this->invoke( 'save_names', array( 100 ) );

		$this->assertArrayNotHasKey( '_ffc_self_scheduling_names', $this->saved_meta );
		$this->assertSame( 1, $deleted->count() );
	}

	public function test_a_submit_without_the_names_block_leaves_them_alone(): void {
		$deleted = CallLog::record( 'delete_post_meta', true );
		$this->invoke( 'save_names', array( 100 ) );

		$this->assertSame( array(), $this->saved_meta );
		$this->assertSame( 0, $deleted->count() );
	}

	// ==================================================================
	// save_config()
	// ==================================================================

	public function test_config_slot_duration_sanitized_as_int(): void {
		$_POST['ffc_self_scheduling_config'] = array( 'slot_duration' => '45' );
		$this->invoke( 'save_config', array( 100 ) );
		$this->assertSame( 45, $this->saved_meta['_ffc_self_scheduling_config']['slot_duration'] );
	}

	/**
	 * #1075 — the stored value used to be the posted array with the known
	 * keys overwritten, so every key the sanitiser did not name survived
	 * verbatim into the post meta. It is now rebuilt from the declared list.
	 */
	public function test_config_drops_keys_that_are_not_declared(): void {
		$_POST['ffc_self_scheduling_config'] = array(
			'slot_duration' => '45',
			'evil'          => '<script>alert(1)</script>',
			'another'       => array( 'nested' => 'junk' ),
		);
		$this->invoke( 'save_config', array( 100 ) );

		$saved = $this->saved_meta['_ffc_self_scheduling_config'];
		$this->assertArrayNotHasKey( 'evil', $saved );
		$this->assertArrayNotHasKey( 'another', $saved );
		$this->assertSame( 45, $saved['slot_duration'], 'The declared keys still save.' );
	}

	/** #1075 — same rebuild on the email payload. */
	public function test_email_config_drops_keys_that_are_not_declared(): void {
		$_POST['ffc_self_scheduling_email_config'] = array(
			'admin_emails' => 'a@example.com',
			'evil'         => 'whatever',
		);
		$this->invoke( 'save_email_config', array( 100 ) );

		$saved = $this->saved_meta['_ffc_self_scheduling_email_config'];
		$this->assertArrayNotHasKey( 'evil', $saved );
		$this->assertSame( 'a@example.com', $saved['admin_emails'] );
	}

	/**
	 * #1075 — `absint( array( '45' ) )` is 1, because `intval()` of a
	 * non-empty array is 1. A forged `slot_duration[]=45` therefore stored a
	 * one-minute slot duration. A non-numeric value now falls back to the
	 * declared default.
	 */
	public function test_config_non_scalar_int_field_falls_back_to_the_default(): void {
		$_POST['ffc_self_scheduling_config'] = array( 'slot_duration' => array( '45' ) );
		$this->invoke( 'save_config', array( 100 ) );

		$this->assertSame( 30, $this->saved_meta['_ffc_self_scheduling_config']['slot_duration'] );
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

	/**
	 * #1075 — a row that is not an array is not a row. Indexing a string by
	 * `['day']` reads character 0 with a warning, so the old code stored a
	 * junk entry for it instead of skipping it.
	 */
	public function test_working_hours_skips_rows_that_are_not_arrays(): void {
		$_POST['ffc_self_scheduling_working_hours'] = array(
			array(
				'day'   => '1',
				'start' => '08:00',
				'end'   => '12:00',
			),
			'not-a-row',
		);
		$this->invoke( 'save_working_hours', array( 100 ) );

		$saved = $this->saved_meta['_ffc_self_scheduling_working_hours'];
		$this->assertCount( 1, $saved );
		$this->assertSame( 1, $saved[0]['day'] );
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
	 * Its own process: it defines DOING_AUTOSAVE, a constant no later test may inherit.
	 *
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
	 * The real FormCache runs: its purge looks up the pages embedding this
	 * calendar's shortcode, which is the call that proves the purge ran.
	 */
	public function test_save_calendar_data_orchestrates_saves_and_purges_cache(): void {
		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'do_action' )->justReturn( null );
		$lookups = array();
		Functions\when( 'get_posts' )->alias(
			static function ( $args ) use ( &$lookups ) {
				$lookups[] = $args;
				return array();
			}
		);

		// No $_POST config → the three save_* helpers skip their writes; the
		// orchestration still runs through to the cache purge.
		$this->handler->save_calendar_data( 1, new \stdClass(), false );

		$this->assertCount( 1, $lookups, 'The page-cache purge ran once.' );
		$this->assertSame( '[ffc_self_scheduling id="1"', $lookups[0]['s'], 'It purges the pages embedding this calendar.' );
		$this->assertEmpty( $this->saved_meta );
	}

	// ==================================================================
	// Grid lock (#2244)
	// ==================================================================

	/**
	 * A regular calendar stored at 30 minutes, 09:00–12:00 every day, with an
	 * open appointment at 09:30.
	 *
	 * @return array<int, array<string, string>> The working hours rows.
	 */
	private function booked_regular_calendar(): array {
		$hours = array();
		for ( $day = 0; $day <= 6; $day++ ) {
			$hours[] = array( 'day' => $day, 'start' => '09:00', 'end' => '12:00' );
		}
		Functions\when( 'get_post_meta' )->alias(
			static function ( $post_id, $key ) use ( $hours ) {
				if ( '_ffc_self_scheduling_working_hours' === $key ) {
					return $hours;
				}
				return array( 'schedule_type' => 'regular', 'slot_duration' => 30, 'slot_interval' => 0 );
			}
		);
		Functions\when( 'wp_date' )->justReturn( '2032-05-01' );
		global $wpdb;
		$wpdb->shouldReceive( 'get_results' )->andReturn(
			array( array( 'id' => 9, 'appointment_date' => '2032-05-20', 'start_time' => '09:30:00', 'end_time' => '10:00:00' ) )
		);
		return $hours;
	}

	public function test_a_duration_that_moves_a_booked_slot_keeps_the_stored_grid(): void {
		$this->booked_regular_calendar();
		$_POST['ffc_self_scheduling_config'] = array( 'slot_duration' => '45', 'slot_interval' => '0', 'schedule_type' => 'regular' );

		$this->invoke( 'save_config', array( 100 ) );

		$this->assertSame( 30, $this->saved_meta['_ffc_self_scheduling_config']['slot_duration'] );
	}

	public function test_hours_that_remove_a_booked_slot_are_not_written(): void {
		$this->booked_regular_calendar();
		$_POST['ffc_self_scheduling_config']        = array( 'slot_duration' => '30', 'slot_interval' => '0' );
		$_POST['ffc_self_scheduling_working_hours'] = array( array( 'day' => '4', 'start' => '10:00', 'end' => '12:00' ) );

		$this->invoke( 'save_working_hours', array( 100 ) );

		$this->assertArrayNotHasKey( '_ffc_self_scheduling_working_hours', $this->saved_meta );
	}

	public function test_a_change_that_keeps_every_booked_slot_is_saved(): void {
		$this->booked_regular_calendar();
		$_POST['ffc_self_scheduling_config']        = array( 'slot_duration' => '30', 'slot_interval' => '0' );
		$_POST['ffc_self_scheduling_working_hours'] = array( array( 'day' => '4', 'start' => '09:00', 'end' => '18:00' ) );

		$this->invoke( 'save_working_hours', array( 100 ) );
		$this->invoke( 'save_config', array( 100 ) );

		$this->assertSame( '18:00', $this->saved_meta['_ffc_self_scheduling_working_hours'][0]['end'] );
		$this->assertSame( 30, $this->saved_meta['_ffc_self_scheduling_config']['slot_duration'] );
	}

	public function test_a_refused_save_leaves_the_list_for_the_editor(): void {
		$this->booked_regular_calendar();
		$_POST['ffc_self_scheduling_config_nonce'] = 'n';
		$_POST['ffc_self_scheduling_config']       = array( 'slot_duration' => '45', 'slot_interval' => '0' );
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 3 );
		$stored = array();
		Functions\when( 'set_transient' )->alias( static function ( $key, $value ) use ( &$stored ) { $stored[ $key ] = $value; return true; } );
		Functions\when( 'delete_post_meta' )->justReturn( true );
		$GLOBALS['wpdb']->shouldReceive( 'get_var' )->andReturn( 0 );

		try {
			$this->handler->save_calendar_data( 100, new \stdClass(), true );
		} catch ( \Throwable $e ) {
			// The page-cache purge after the save is not under test.
			unset( $e );
		} finally {
			unset( $_POST['ffc_self_scheduling_config_nonce'] );
		}

		$this->assertSame( array( array( 'date' => '2032-05-20', 'start' => '09:30', 'end' => '10:00' ) ), $stored['ffc_ss_grid_lock_3_100'] );
	}

	public function test_a_custom_calendar_is_not_grid_locked(): void {
		Functions\when( 'get_post_meta' )->justReturn( array( 'schedule_type' => 'custom', 'slot_duration' => 30 ) );
		global $wpdb;
		$wpdb->shouldNotReceive( 'get_results' );
		$_POST['ffc_self_scheduling_config'] = array( 'slot_duration' => '45', 'schedule_type' => 'custom' );

		$this->invoke( 'save_config', array( 100 ) );

		$this->assertSame( 45, $this->saved_meta['_ffc_self_scheduling_config']['slot_duration'] );
	}
}
