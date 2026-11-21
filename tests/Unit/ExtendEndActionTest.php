<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Frontend\ExtendEndAction;
use FreeFormCertificate\Tests\Support\SiteClock;

/**
 * Tests for the ExtendEndAction service introduced in 6.5.12.
 *
 * Sibling of EarlyOpenAction but for the close boundary. Lets a trusted
 * operator push `time_end` later within the same day, exactly once per
 * form. Geofence and FormCache are the real classes, and the site
 * timezone puts the real "now" at noon (#2121), as in EarlyOpenActionTest.
 *
 * @covers \FreeFormCertificate\Frontend\ExtendEndAction
 */
class ExtendEndActionTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var mixed The `$wpdb` before the test, restored after it. */
	private $wpdb_backup;

	/** @var bool Whether `$wpdb` was set before the test. */
	private bool $had_wpdb = false;

	/** @var array<int, array<string, mixed>> */
	private array $meta_store = array();

	private SiteClock $clock;

	protected function setUp(): void {
		parent::setUp();
		$this->had_wpdb    = array_key_exists( 'wpdb', $GLOBALS );
		$this->wpdb_backup = $this->had_wpdb ? $GLOBALS['wpdb'] : null;
		Monkey\setUp();

		Functions\when( '__' )->returnArg();
		Functions\when( 'get_post_type' )->justReturn( 'ffc_form' );
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
		if ( $this->had_wpdb ) {
			$GLOBALS['wpdb'] = $this->wpdb_backup;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * A form with the CSV-public hash, the extend opt-in and the datetime window on.
	 *
	 * @param array<string, string> $window date_start, time_start, date_end, time_end.
	 */
	private function configure_form( int $form_id, array $window ): void {
		$this->meta_store[ $form_id ] = array(
			'_ffc_csv_public_enabled'            => '1',
			'_ffc_csv_public_hash'               => 'h',
			'_ffc_csv_public_extend_end_enabled' => '1',
			'_ffc_geofence_config'               => array( 'datetime_enabled' => '1' ) + $window,
		);
	}

	/**
	 * Eligible fixture — form is currently open (10:00 to 14:00 today, and
	 * it is noon), opt-in on, hash matches, no prior postponement.
	 */
	private function configure_eligible_form( int $form_id, string $hash ): void {
		$this->configure_form(
			$form_id,
			array(
				'date_start' => $this->clock->day(),
				'time_start' => '10:00',
				'date_end'   => $this->clock->day(),
				'time_end'   => '14:00',
			)
		);
		$this->meta_store[ $form_id ]['_ffc_csv_public_hash'] = $hash;
	}

	// ==================================================================
	// is_eligible() — rejection branches
	// ==================================================================

	public function test_unknown_form_when_post_type_mismatch(): void {
		Functions\when( 'get_post_type' )->justReturn( 'page' );
		$r = ExtendEndAction::is_eligible( 99, 'h' );
		$this->assertSame( 'unknown_form', $r['reason'] );
	}

	public function test_csv_disabled_when_toggle_off(): void {
		$this->meta_store[1]['_ffc_csv_public_enabled'] = '';
		$r = ExtendEndAction::is_eligible( 1, 'h' );
		$this->assertSame( 'csv_disabled', $r['reason'] );
	}

	public function test_bad_hash_when_mismatched(): void {
		$this->meta_store[1] = array(
			'_ffc_csv_public_enabled' => '1',
			'_ffc_csv_public_hash'    => 'good',
		);
		$r = ExtendEndAction::is_eligible( 1, 'bad' );
		$this->assertSame( 'bad_hash', $r['reason'] );
	}

	public function test_extend_end_disabled_when_opt_in_unset(): void {
		$this->meta_store[1] = array(
			'_ffc_csv_public_enabled' => '1',
			'_ffc_csv_public_hash'    => 'h',
			// _ffc_csv_public_extend_end_enabled unset → reads as '0'.
		);
		$r = ExtendEndAction::is_eligible( 1, 'h' );
		$this->assertSame( 'extend_end_disabled', $r['reason'] );
	}

	public function test_datetime_disabled_when_geofence_off(): void {
		$this->meta_store[1] = array(
			'_ffc_csv_public_enabled'            => '1',
			'_ffc_csv_public_hash'               => 'h',
			'_ffc_csv_public_extend_end_enabled' => '1',
			'_ffc_geofence_config'               => array( 'datetime_enabled' => '' ),
		);
		$r = ExtendEndAction::is_eligible( 1, 'h' );
		$this->assertSame( 'datetime_disabled', $r['reason'] );
	}

	public function test_no_end_date(): void {
		$this->meta_store[1] = array(
			'_ffc_csv_public_enabled'            => '1',
			'_ffc_csv_public_hash'               => 'h',
			'_ffc_csv_public_extend_end_enabled' => '1',
			'_ffc_geofence_config'               => array( 'datetime_enabled' => '1' ),
		);
		$r = ExtendEndAction::is_eligible( 1, 'h' );
		$this->assertSame( 'no_end_date', $r['reason'] );
	}

	public function test_not_started_yet(): void {
		// Opens at 14:00 today; it is noon.
		$this->configure_form(
			1,
			array(
				'date_start' => $this->clock->day(),
				'time_start' => '14:00',
				'date_end'   => $this->clock->day(),
				'time_end'   => '20:00',
			)
		);
		$r = ExtendEndAction::is_eligible( 1, 'h' );
		$this->assertSame( 'not_started_yet', $r['reason'] );
	}

	public function test_already_ended(): void {
		// Closed at 08:00 today.
		$this->configure_form(
			1,
			array(
				'date_start' => $this->clock->day(),
				'time_start' => '07:00',
				'date_end'   => $this->clock->day(),
				'time_end'   => '08:00',
			)
		);
		$r = ExtendEndAction::is_eligible( 1, 'h' );
		$this->assertSame( 'already_ended', $r['reason'] );
	}

	public function test_not_today_when_date_end_is_future(): void {
		$this->configure_form(
			1,
			array(
				'date_start' => $this->clock->day( -1 ),
				'date_end'   => $this->clock->day( 2 ),
				'time_end'   => '20:00',
			)
		);
		$r = ExtendEndAction::is_eligible( 1, 'h' );
		$this->assertSame( 'not_today', $r['reason'] );
	}

	public function test_already_postponed_blocks_second_run(): void {
		$this->configure_eligible_form( 1, 'h' );
		$this->meta_store[1][ ExtendEndAction::META_POSTPONED_AT ] = (string) ( $this->clock->now - 1000 );
		$r = ExtendEndAction::is_eligible( 1, 'h' );
		$this->assertSame( 'already_postponed', $r['reason'] );
	}

	// ==================================================================
	// is_eligible() — happy path
	// ==================================================================

	public function test_ok_when_all_conditions_met(): void {
		$this->configure_eligible_form( 1, 'h' );
		$r = ExtendEndAction::is_eligible( 1, 'h' );
		$this->assertTrue( $r['ok'] );
	}

	// ==================================================================
	// execute() — happy path + write semantics
	// ==================================================================

	public function test_execute_writes_new_time_end_and_flips_one_shot(): void {
		$this->configure_eligible_form( 1, 'h' );
		// The plugin's cache and the per-form and site-wide page purges.
		Actions\expectDone( 'ffc_form_cache_purged' )->twice();

		$r = ExtendEndAction::execute( 1, 'h', '15:30', array( 'user_id' => 7, 'ip' => '1.2.3.4', 'ua' => 'UA' ) );

		$this->assertTrue( $r['ok'] );
		$this->assertSame( '15:30', $this->meta_store[1]['_ffc_geofence_config']['time_end'] );
		// Original window is snapshotted for the audit trail.
		$this->assertSame( '14:00', $this->meta_store[1][ ExtendEndAction::META_POSTPONED_FROM ] );
		// One-shot flag set.
		$this->assertNotEmpty( $this->meta_store[1][ ExtendEndAction::META_POSTPONED_AT ] );
		$this->assertSame( $this->clock->day() . ' 14:00', $r['original_end_iso'] );
		$this->assertSame( $this->clock->day() . ' 15:30', $r['new_end_iso'] );
	}

	public function test_execute_preserves_date_end_and_other_fields(): void {
		$this->configure_eligible_form( 1, 'h' );

		ExtendEndAction::execute( 1, 'h', '18:45' );

		$this->assertSame( $this->clock->day(), $this->meta_store[1]['_ffc_geofence_config']['date_start'] );
		$this->assertSame( '10:00', $this->meta_store[1]['_ffc_geofence_config']['time_start'] );
		$this->assertSame( $this->clock->day(), $this->meta_store[1]['_ffc_geofence_config']['date_end'] );
	}

	// ==================================================================
	// execute() — new_time_end validation
	// ==================================================================

	public function test_execute_rejects_bad_time_format(): void {
		$this->configure_eligible_form( 1, 'h' );
		$r = ExtendEndAction::execute( 1, 'h', '25:99' );
		$this->assertFalse( $r['ok'] );
		$this->assertSame( 'bad_time_format', $r['reason'] );
	}

	public function test_execute_rejects_non_extending_value(): void {
		$this->configure_eligible_form( 1, 'h' );
		// Current time_end is 14:00, picking 13:30 doesn't extend.
		$r = ExtendEndAction::execute( 1, 'h', '13:30' );
		$this->assertSame( 'not_extending', $r['reason'] );
	}

	public function test_execute_rejects_equal_value(): void {
		$this->configure_eligible_form( 1, 'h' );
		$r = ExtendEndAction::execute( 1, 'h', '14:00' );
		$this->assertSame( 'not_extending', $r['reason'] );
	}

	public function test_a_form_with_no_closing_time_has_no_later_minute(): void {
		// A form that closes at the end of today with no closing time stored
		// already ends at the day's last minute (#2341). Before, it was
		// eligible, and any time not before now "postponed" it, which could
		// only move the close earlier.
		$this->configure_form(
			1,
			array(
				'date_start' => $this->clock->day(),
				'time_start' => '08:00',
				'date_end'   => $this->clock->day(),
			)
		);

		$this->assertSame( 'last_minute', ExtendEndAction::execute( 1, 'h', '11:00' )['reason'] );
		$this->assertSame( 'last_minute', ExtendEndAction::is_eligible( 1, 'h' )['reason'] );
	}

	public function test_a_form_closing_at_2359_cannot_be_postponed(): void {
		$this->configure_form(
			1,
			array(
				'date_start' => $this->clock->day(),
				'time_start' => '08:00',
				'date_end'   => $this->clock->day(),
				'time_end'   => '23:59',
			)
		);

		$this->assertSame( 'last_minute', ExtendEndAction::is_eligible( 1, 'h' )['reason'] );
		$this->assertTrue( ExtendEndAction::closes_at_last_minute( array( 'time_end' => '23:59' ) ) );
		$this->assertTrue( ExtendEndAction::closes_at_last_minute( array() ) );
		$this->assertFalse( ExtendEndAction::closes_at_last_minute( array( 'time_end' => '23:58' ) ) );
	}

	// ==================================================================
	// execute() — short-circuits on eligibility failure
	// ==================================================================

	public function test_execute_short_circuits_when_eligibility_fails(): void {
		$this->meta_store[1] = array(
			'_ffc_csv_public_enabled'            => '1',
			'_ffc_csv_public_hash'               => 'h',
			'_ffc_csv_public_extend_end_enabled' => '1',
			'_ffc_geofence_config'               => array( 'datetime_enabled' => '1' ),
		);
		$r = ExtendEndAction::execute( 1, 'h', '20:00' );
		$this->assertFalse( $r['ok'] );
		$this->assertSame( 'no_end_date', $r['reason'] );
		// No write happened.
		$this->assertArrayNotHasKey( ExtendEndAction::META_POSTPONED_AT, $this->meta_store[1] );
	}

	/**
	 * A `$wpdb` that plays the form's window lock (#2695): `$on_lock` runs
	 * while this request waits, as another tab writing, then the lock answers
	 * `$answer`.
	 *
	 * @param string        $answer  GET_LOCK's answer.
	 * @param callable|null $on_lock What another request does meanwhile.
	 * @return \ArrayObject<int, string> Every lock query, in order.
	 */
	private function lock_server( string $answer, ?callable $on_lock = null ): \ArrayObject {
		$queries = new \ArrayObject();
		$wpdb    = \Mockery::mock( 'wpdb' );
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing( static fn( $sql, ...$args ) => vsprintf( str_replace( '%s', "'%s'", (string) $sql ), $args ) );
		$wpdb->shouldReceive( 'get_var' )->andReturnUsing(
			static function ( $sql ) use ( $queries, $answer, $on_lock ) {
				$queries[] = (string) $sql;
				if ( 0 === strpos( (string) $sql, 'SELECT GET_LOCK' ) ) {
					if ( null !== $on_lock ) {
						$on_lock();
					}
					return $answer;
				}
				return '1';
			}
		);
		$GLOBALS['wpdb'] = $wpdb;
		return $queries;
	}

	/**
	 * A tab that writes while this one waits for the form's lock leaves
	 * this one refused, with nothing written twice (#2695).
	 */
	public function test_execute_rereads_under_the_lock_what_another_tab_wrote(): void {
		$this->configure_eligible_form( 1, 'h' );
		$before  = $this->meta_store[1];
		$queries = $this->lock_server(
			'1',
			function () {
				$this->meta_store[1][ ExtendEndAction::META_POSTPONED_AT ] = 12345;
			}
		);

		$r = ExtendEndAction::execute( 1, 'h', '15:30' );

		$this->assertFalse( $r['ok'] );
		$this->assertSame( 'already_postponed', $r['reason'] );
		$this->assertSame( 12345, $this->meta_store[1][ ExtendEndAction::META_POSTPONED_AT ], 'The other tab\'s write stands.' );
		$this->assertSame( $before['_ffc_geofence_config'], $this->meta_store[1]['_ffc_geofence_config'] );
		$this->assertSame( "SELECT RELEASE_LOCK('ffc_form_window_1')", $queries[ count( $queries ) - 1 ], 'The lock is let go.' );
	}

	/**
	 * A lock still held when the wait runs out refuses without writing.
	 */
	public function test_execute_refuses_when_the_form_lock_stays_held(): void {
		$this->configure_eligible_form( 1, 'h' );
		$before  = $this->meta_store[1];
		$queries = $this->lock_server( '0' );

		$r = ExtendEndAction::execute( 1, 'h', '15:30' );

		$this->assertFalse( $r['ok'] );
		$this->assertSame( 'already_postponed', $r['reason'] );
		$this->assertSame( $before, $this->meta_store[1] );
		$this->assertCount( 1, $queries, 'A lock never granted is not released.' );
	}
}
