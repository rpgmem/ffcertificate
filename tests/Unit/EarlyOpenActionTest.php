<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Frontend\EarlyOpenAction;
use FreeFormCertificate\Tests\Support\SiteClock;

/**
 * Tests for the EarlyOpenAction service introduced in 6.5.6.
 *
 * Geofence and FormCache are the real classes: the form's window is the
 * wall-clock config the metabox stores, and Geofence turns it into the
 * instants the action compares with `time()`. The site timezone is chosen
 * so that the real "now" is noon (#2121), which keeps every window hours
 * away from a day boundary without stubbing `time()`.
 *
 * @covers \FreeFormCertificate\Frontend\EarlyOpenAction
 */
class EarlyOpenActionTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var array<int, array<string, mixed>> */
	private array $meta_store = array();

	private SiteClock $clock;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( '__' )->returnArg();
		Functions\when( 'get_post_type' )->justReturn( 'ffc_form' );
		// ActivityLog::log() reads ffc_settings to gate itself; stub
		// the option as activity-log-disabled so the call no-ops.
		Functions\when( 'get_option' )->justReturn( array( 'enable_activity_log' => 0 ) );
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		$this->clock = new SiteClock();
		$this->clock->stub();

		$this->meta_store = array();
		Functions\when( 'get_post_meta' )->alias( function ( $id, $key ) {
			return $this->meta_store[ $id ][ $key ] ?? '';
		} );
		Functions\when( 'update_post_meta' )->alias( function ( $id, $key, $value ) {
			$this->meta_store[ $id ][ $key ] = $value;
			return true;
		} );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * A form with the CSV-public hash and the datetime window on.
	 *
	 * @param array<string, string> $window date_start, time_start, date_end, time_end, time_mode.
	 */
	private function configure_form( int $form_id, array $window ): void {
		$this->meta_store[ $form_id ] = array(
			'_ffc_csv_public_enabled' => '1',
			'_ffc_csv_public_hash'    => 'h',
			'_ffc_geofence_config'    => array( 'datetime_enabled' => '1' ) + $window,
		);
	}

	private function configure_eligible_form( int $form_id, string $hash ): void {
		// Scheduled to start later today (it is noon), with no close: the
		// button only surfaces when the form starts today.
		$this->configure_form(
			$form_id,
			array(
				'date_start' => $this->clock->day(),
				'time_start' => '23:00',
			)
		);
		$this->meta_store[ $form_id ]['_ffc_csv_public_hash'] = $hash;
	}

	// ==================================================================
	// is_eligible() — rejection branches
	// ==================================================================

	public function test_unknown_form_when_post_type_mismatch(): void {
		Functions\when( 'get_post_type' )->justReturn( 'page' );

		$r = EarlyOpenAction::is_eligible( 99, 'h' );
		$this->assertFalse( $r['ok'] );
		$this->assertSame( 'unknown_form', $r['reason'] );
	}

	public function test_unknown_form_when_id_zero(): void {
		$r = EarlyOpenAction::is_eligible( 0, 'h' );
		$this->assertSame( 'unknown_form', $r['reason'] );
	}

	public function test_csv_disabled_when_toggle_off(): void {
		$this->meta_store[1]['_ffc_csv_public_enabled'] = '';
		$r = EarlyOpenAction::is_eligible( 1, 'h' );
		$this->assertSame( 'csv_disabled', $r['reason'] );
	}

	public function test_bad_hash_when_stored_empty(): void {
		$this->meta_store[1] = array(
			'_ffc_csv_public_enabled' => '1',
			'_ffc_csv_public_hash'    => '',
		);
		$r = EarlyOpenAction::is_eligible( 1, 'whatever' );
		$this->assertSame( 'bad_hash', $r['reason'] );
	}

	public function test_bad_hash_when_mismatched(): void {
		$this->meta_store[1] = array(
			'_ffc_csv_public_enabled' => '1',
			'_ffc_csv_public_hash'    => 'good',
		);
		$r = EarlyOpenAction::is_eligible( 1, 'bad' );
		$this->assertSame( 'bad_hash', $r['reason'] );
	}

	public function test_early_open_disabled_when_per_form_toggle_off(): void {
		$this->meta_store[1] = array(
			'_ffc_csv_public_enabled'              => '1',
			'_ffc_csv_public_hash'                 => 'h',
			'_ffc_csv_public_start_early_enabled'  => '0',
		);
		$r = EarlyOpenAction::is_eligible( 1, 'h' );
		$this->assertSame( 'early_open_disabled', $r['reason'] );
	}

	public function test_early_open_enabled_when_per_form_toggle_unset_defaults_to_on(): void {
		// Pre-6.5.8 forms have no stored value — must NOT regress.
		$this->configure_eligible_form( 1, 'h' );
		// configure_eligible_form() doesn't set the new meta — leave unset.
		$r = EarlyOpenAction::is_eligible( 1, 'h' );
		$this->assertTrue( $r['ok'] );
	}

	public function test_datetime_disabled(): void {
		$this->meta_store[1] = array(
			'_ffc_csv_public_enabled' => '1',
			'_ffc_csv_public_hash'    => 'h',
			'_ffc_geofence_config'    => array( 'datetime_enabled' => '' ),
		);
		$r = EarlyOpenAction::is_eligible( 1, 'h' );
		$this->assertSame( 'datetime_disabled', $r['reason'] );
	}

	public function test_no_start_date(): void {
		$this->meta_store[1] = array(
			'_ffc_csv_public_enabled' => '1',
			'_ffc_csv_public_hash'    => 'h',
			'_ffc_geofence_config'    => array( 'datetime_enabled' => '1' ),
		);
		$r = EarlyOpenAction::is_eligible( 1, 'h' );
		$this->assertSame( 'no_start_date', $r['reason'] );
	}

	public function test_already_started_when_start_in_past(): void {
		// Started this morning; it is noon.
		$this->configure_form(
			1,
			array(
				'date_start' => $this->clock->day(),
				'time_start' => '08:00',
			)
		);
		$r = EarlyOpenAction::is_eligible( 1, 'h' );
		$this->assertSame( 'already_started', $r['reason'] );
	}

	public function test_already_ended_when_end_in_past(): void {
		// A window that opens tomorrow and closed yesterday: the start
		// check passes, the close check refuses.
		$this->configure_form(
			1,
			array(
				'date_start' => $this->clock->day( 1 ),
				'date_end'   => $this->clock->day( -1 ),
			)
		);
		$r = EarlyOpenAction::is_eligible( 1, 'h' );
		$this->assertSame( 'already_ended', $r['reason'] );
	}

	public function test_not_today_when_date_start_in_future(): void {
		// date_start is a future calendar day → button must not appear
		// because the action only rewrites time_start (operator can't
		// shift the form to a different day from this surface).
		$this->configure_form(
			1,
			array(
				'date_start' => $this->clock->day( 1 ),
				'time_start' => '21:00',
				'date_end'   => $this->clock->day( 2 ),
			)
		);
		$r = EarlyOpenAction::is_eligible( 1, 'h' );
		$this->assertSame( 'not_today', $r['reason'] );
	}

	// ==================================================================
	// is_eligible() — happy path
	// ==================================================================

	public function test_ok_when_all_conditions_met(): void {
		$this->configure_eligible_form( 1, 'h' );
		// The per-form toggle set explicitly; the unset case is the test above.
		$this->meta_store[1]['_ffc_csv_public_start_early_enabled'] = '1';
		$r = EarlyOpenAction::is_eligible( 1, 'h' );
		$this->assertTrue( $r['ok'] );
	}

	// ==================================================================
	// execute() — happy path
	// ==================================================================

	public function test_execute_writes_time_start_only_and_preserves_date_start(): void {
		// The action narrows the write to time_start. date_start /
		// date_end / time_end / time_mode are all preserved as-is —
		// the operator opens the form earlier within the same scheduled
		// day, nothing else.
		$this->configure_eligible_form( 1, 'h' );
		// The plugin's cache and the per-form and site-wide page purges.
		Actions\expectDone( 'ffc_form_cache_purged' )->twice();

		$r = EarlyOpenAction::execute( 1, 'h', array( 'user_id' => 7, 'ip' => '1.2.3.4', 'ua' => 'UA' ) );

		$today = $this->clock->day();
		$this->assertTrue( $r['ok'] );
		$this->assertSame( $today, $this->meta_store[1]['_ffc_geofence_config']['date_start'] );
		$this->assertSame( '12:00', $this->meta_store[1]['_ffc_geofence_config']['time_start'] );
		$this->assertSame( $today . ' 23:00', $r['original_start_iso'] );
		$this->assertSame( $today . ' 12:00', $r['new_start_iso'] );
	}

	public function test_execute_preserves_full_window_on_multi_day_forms(): void {
		// Form scheduled for today with a future date_end. The action
		// only touches time_start — date_end, time_end and time_mode
		// all stay exactly as the admin configured them.
		$this->configure_form(
			1,
			array(
				'date_start' => $this->clock->day(),
				'time_start' => '23:00',
				'date_end'   => $this->clock->day( 2 ),
				'time_end'   => '21:00',
				'time_mode'  => 'daily',
			)
		);

		$r = EarlyOpenAction::execute( 1, 'h' );

		$this->assertTrue( $r['ok'] );
		$this->assertSame( $this->clock->day(), $this->meta_store[1]['_ffc_geofence_config']['date_start'] );
		$this->assertSame( '12:00', $this->meta_store[1]['_ffc_geofence_config']['time_start'] );
		// date_end + time_end + time_mode are preserved.
		$this->assertSame( $this->clock->day( 2 ), $this->meta_store[1]['_ffc_geofence_config']['date_end'] );
		$this->assertSame( '21:00', $this->meta_store[1]['_ffc_geofence_config']['time_end'] );
		$this->assertSame( 'daily', $this->meta_store[1]['_ffc_geofence_config']['time_mode'] );
	}

	public function test_execute_short_circuits_when_eligibility_fails(): void {
		// Form exists but has no start date — eligibility fails, no writes.
		$this->meta_store[1] = array(
			'_ffc_csv_public_enabled' => '1',
			'_ffc_csv_public_hash'    => 'h',
			'_ffc_geofence_config'    => array( 'datetime_enabled' => '1' ),
		);

		$r = EarlyOpenAction::execute( 1, 'h' );

		$this->assertFalse( $r['ok'] );
		$this->assertSame( 'no_start_date', $r['reason'] );
		// Original geofence is untouched.
		$this->assertArrayNotHasKey( 'date_start', $this->meta_store[1]['_ffc_geofence_config'] );
	}

	// ------------------------------------------------------------------
	// META_OPENED_AT / META_OPENED_FROM snapshot — issue #350
	// ------------------------------------------------------------------

	public function test_execute_persists_opened_at_and_opened_from_metas(): void {
		$this->configure_eligible_form( 1, 'h' );

		$r = EarlyOpenAction::execute( 1, 'h' );

		$this->assertTrue( $r['ok'] );
		// Both metas should now be set on the form row.
		$this->assertArrayHasKey( EarlyOpenAction::META_OPENED_AT, $this->meta_store[1] );
		$this->assertArrayHasKey( EarlyOpenAction::META_OPENED_FROM, $this->meta_store[1] );
		$this->assertGreaterThan( 0, (int) $this->meta_store[1][ EarlyOpenAction::META_OPENED_AT ] );
		// META_OPENED_FROM is the original `time_start` before the flip.
		$this->assertSame( '23:00', $this->meta_store[1][ EarlyOpenAction::META_OPENED_FROM ] );
	}

	public function test_is_eligible_returns_already_opened_when_meta_set(): void {
		$this->configure_eligible_form( 1, 'h' );
		// Simulate a prior run by setting the META_OPENED_AT meta.
		$this->meta_store[1][ EarlyOpenAction::META_OPENED_AT ] = time();

		$r = EarlyOpenAction::is_eligible( 1, 'h' );

		$this->assertFalse( $r['ok'] );
		$this->assertSame( 'already_opened', $r['reason'] );
	}

	public function test_execute_short_circuits_when_already_opened(): void {
		// Both opens-in-a-row should be rejected by the new guard —
		// first one fires normally, second one returns `already_opened`
		// without touching `time_start` again.
		$this->configure_eligible_form( 1, 'h' );

		$first  = EarlyOpenAction::execute( 1, 'h' );
		$this->assertTrue( $first['ok'] );

		$second_time_start_before = $this->meta_store[1]['_ffc_geofence_config']['time_start'];
		$second                   = EarlyOpenAction::execute( 1, 'h' );

		$this->assertFalse( $second['ok'] );
		$this->assertSame( 'already_opened', $second['reason'] );
		// time_start untouched on the second call.
		$this->assertSame( $second_time_start_before, $this->meta_store[1]['_ffc_geofence_config']['time_start'] );
	}
}
