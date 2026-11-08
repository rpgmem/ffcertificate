<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FreeFormCertificate\Admin\SettingsChangeAudit;
use FreeFormCertificate\Audience\AudienceAttendanceWriter;
use FreeFormCertificate\Audience\AudienceCheckinWindowWriter;
use FreeFormCertificate\Core\ScheduledTasks;
use FreeFormCertificate\Security\RateLimitChecker;
use FreeFormCertificate\Security\RateLimitLogger;
use FreeFormCertificate\Tests\Support\ActivityLogBuffer;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * The secondary activity-log events (#2340): rate-limit blocks, attendance
 * and check-in windows, settings changed outside the Danger Zone and
 * scheduled tasks that died.
 *
 * @covers \FreeFormCertificate\Security\RateLimitLogger
 * @covers \FreeFormCertificate\Audience\AudienceAttendanceWriter
 * @covers \FreeFormCertificate\Audience\AudienceCheckinWindowWriter
 * @covers \FreeFormCertificate\Admin\SettingsChangeAudit
 * @covers \FreeFormCertificate\Core\ScheduledTasks
 */
class ActivityLogSecondaryEventsTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var mixed */
	private $wpdb_backup;

	/** @var array<string, mixed> Options by name. */
	private array $options = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		ActivityLogBuffer::reset();
		ActivityLogBuffer::stub_functions();
		$this->options = array( 'ffc_settings' => ActivityLogBuffer::ENABLED_SETTINGS );
		Functions\when( 'get_option' )->alias(
			fn( $key, $default = false ) => $this->options[ $key ] ?? $default
		);
		Functions\when( 'get_current_user_id' )->justReturn( 9 );
		Functions\when( '__' )->returnArg();
		Functions\when( 'wp_parse_args' )->alias(
			static fn( $args, $defaults ) => array_replace_recursive( $defaults, is_array( $args ) ? $args : array() )
		);
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$this->wpdb_backup = $GLOBALS['wpdb'] ?? null;
		$wpdb              = Mockery::mock( 'wpdb' );
		$wpdb->prefix      = 'wp_';
		$wpdb->insert_id   = 41;
		$wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' )->byDefault();
		$wpdb->shouldReceive( 'query' )->andReturn( 1 )->byDefault();
		$wpdb->shouldReceive( 'insert' )->andReturn( 1 )->byDefault();
		$wpdb->shouldReceive( 'delete' )->andReturn( 1 )->byDefault();
		$wpdb->shouldReceive( 'get_var' )->andReturn( '0' )->byDefault();
		$wpdb->shouldReceive( 'get_row' )->andReturn( null )->byDefault();
		$GLOBALS['wpdb'] = $wpdb;

		$cache = new \ReflectionProperty( RateLimitChecker::class, 'settings_cache' );
		$cache->setAccessible( true );
		$cache->setValue( null, null );
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb'] = $this->wpdb_backup;
		ActivityLogBuffer::reset();
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_a_rate_limit_block_names_the_limit_and_never_the_person(): void {
		// The rate-limit table's own log is off; the activity log still records.
		$this->options['ffc_rate_limit_settings'] = array( 'logging' => array( 'enabled' => false ) );

		RateLimitLogger::log_attempt( 'email', 'ana@example.org', 'blocked', 'Too many', 12 );

		$this->assertSame(
			array(
				array(
					'action'  => 'rate_limit_blocked',
					'level'   => 'warning',
					'context' => array(
						'limit'   => 'email',
						'reason'  => 'Too many',
						'form_id' => 12,
					),
				),
			),
			ActivityLogBuffer::contexts()
		);
		$this->assertStringNotContainsString( 'ana@example.org', (string) wp_json_encode( ActivityLogBuffer::entries() ) );
	}

	public function test_an_allowed_attempt_writes_nothing(): void {
		$this->options['ffc_rate_limit_settings'] = array( 'logging' => array( 'enabled' => false ) );

		RateLimitLogger::log_attempt( 'ip', '198.51.100.7', 'allowed', 'Passed', 12 );

		$this->assertSame( array(), ActivityLogBuffer::entries() );
	}

	public function test_a_manual_mark_is_attributed_to_the_operator_and_a_qr_one_to_the_person(): void {
		Functions\when( 'current_time' )->justReturn( '2026-11-06 10:00:00' );
		AudienceAttendanceWriter::record( 5, 77, '2026-11-06', AudienceAttendanceWriter::METHOD_MANUAL, 3, 1000 );
		AudienceAttendanceWriter::record( 5, 78, '2026-11-06', AudienceAttendanceWriter::METHOD_QR, null, 1000 );

		$entries = ActivityLogBuffer::entries();
		$this->assertCount( 2, $entries );
		$this->assertSame( 3, $entries[0]['user_id'] );
		$this->assertSame( 78, $entries[1]['user_id'] );
		$this->assertSame(
			array(
				'booking_id'     => 5,
				'target_user_id' => 77,
				'date'           => '2026-11-06',
				'method'         => 'manual',
			),
			ActivityLogBuffer::contexts()[0]['context']
		);
	}

	public function test_a_mark_already_there_writes_nothing(): void {
		$GLOBALS['wpdb']->shouldReceive( 'query' )->andReturn( 0 );

		$this->assertSame( AudienceAttendanceWriter::ALREADY, AudienceAttendanceWriter::record( 5, 77, '2026-11-06', 'qr', null, 1000 ) );
		$this->assertSame( array(), ActivityLogBuffer::entries() );
	}

	public function test_removing_a_mark_is_attributed_to_the_current_user(): void {
		$this->assertTrue( AudienceAttendanceWriter::remove( 5, 77, '2026-11-06' ) );

		$this->assertSame( 'attendance_removed', ActivityLogBuffer::contexts()[0]['action'] );
		$this->assertSame( 9, ActivityLogBuffer::entries()[0]['user_id'] );
	}

	public function test_opening_and_closing_a_check_in_window_are_recorded(): void {
		$this->assertSame( 41, AudienceCheckinWindowWriter::open( 5, '2026-11-06', 30, 2000, 3, 1000 ) );
		$this->assertTrue( AudienceCheckinWindowWriter::close( 41, 3, 1500 ) );

		$actions = array_column( ActivityLogBuffer::contexts(), 'action' );
		$this->assertSame( array( 'checkin_window_opened', 'checkin_window_closed' ), $actions );
		$this->assertSame( 41, ActivityLogBuffer::contexts()[0]['context']['window_id'] );
		$this->assertSame( 3, ActivityLogBuffer::entries()[1]['user_id'] );
	}

	public function test_a_settings_change_names_the_keys_and_never_the_values(): void {
		$written = SettingsChangeAudit::record(
			'ffc_settings',
			array(
				'smtp_password' => 'old-secret',
				'dark_mode'     => 'off',
				'cleanup_days'  => 365,
			),
			array(
				'smtp_password' => 'new-secret',
				'dark_mode'     => 'off',
				'cleanup_days'  => 30,
			)
		);

		$this->assertTrue( $written );
		$this->assertSame(
			array(
				'option' => 'ffc_settings',
				'keys'   => array( 'smtp_password' ),
			),
			ActivityLogBuffer::contexts()[0]['context'],
			'cleanup_days is a Danger Zone key, recorded with its values by the audit.'
		);
		$this->assertStringNotContainsString( 'secret', (string) wp_json_encode( ActivityLogBuffer::entries() ) );
	}

	public function test_a_save_that_changes_nothing_writes_nothing(): void {
		$this->assertFalse( SettingsChangeAudit::record( 'ffc_settings', array( 'dark_mode' => '1' ), array( 'dark_mode' => 1 ) ) );
		$this->assertSame( array(), ActivityLogBuffer::entries() );
	}

	public function test_another_options_key_is_recorded_by_its_path(): void {
		SettingsChangeAudit::record_keys( 'ffc_rate_limit_settings', array( 'ip.enabled' ) );

		$this->assertSame(
			array(
				'option' => 'ffc_rate_limit_settings',
				'keys'   => array( 'ip.enabled' ),
			),
			ActivityLogBuffer::contexts()[0]['context']
		);
	}

	public function test_a_fatal_error_inside_a_task_is_a_failure_entry(): void {
		$entry = ScheduledTasks::failure_entry(
			'ffc_daily_cleanup',
			array(
				'type'    => E_ERROR,
				'message' => 'Uncaught Error: boom',
				'file'    => '/var/www/site/wp-content/plugins/ffcertificate/includes/x.php',
				'line'    => 12,
			)
		);

		$this->assertSame(
			array(
				'task'  => 'ffc_daily_cleanup',
				'error' => 'Uncaught Error: boom',
				'file'  => 'x.php',
				'line'  => 12,
			),
			$entry,
			'The file is kept by name only.'
		);
	}

	public function test_no_running_task_or_a_non_fatal_error_is_no_failure(): void {
		$this->assertNull( ScheduledTasks::failure_entry( null, array( 'type' => E_ERROR ) ) );
		$this->assertNull( ScheduledTasks::failure_entry( 'ffc_daily_cleanup', null ) );
		$this->assertNull( ScheduledTasks::failure_entry( 'ffc_daily_cleanup', array( 'type' => E_WARNING ) ) );
	}

	public function test_a_long_error_message_is_cut_short(): void {
		$entry = ScheduledTasks::failure_entry( 'ffc_daily_cleanup', array( 'type' => E_ERROR, 'message' => str_repeat( 'x', 900 ) ) );

		$this->assertSame( 300, strlen( (string) $entry['error'] ) );
	}

	public function test_a_task_that_finished_is_no_longer_marked_as_running(): void {
		$hooks = array();
		Functions\when( 'add_action' )->alias(
			static function ( $hook, $callback, $priority = 10 ) use ( &$hooks ) {
				$hooks[ $hook ][ $priority ] = $callback;
				return true;
			}
		);
		ScheduledTasks::init();

		$running = new \ReflectionProperty( ScheduledTasks::class, 'running' );
		$running->setAccessible( true );
		$registered = new \ReflectionProperty( ScheduledTasks::class, 'handler_registered' );
		$registered->setAccessible( true );
		// Skip the shutdown registration: a real one would outlive the test.
		$registered->setValue( null, true );

		( $hooks['ffc_daily_cleanup'][ PHP_INT_MIN ] )();
		$this->assertSame( 'ffc_daily_cleanup', $running->getValue() );

		( $hooks['ffc_daily_cleanup'][ PHP_INT_MAX ] )();
		$this->assertNull( $running->getValue() );

		$registered->setValue( null, false );
	}
}
