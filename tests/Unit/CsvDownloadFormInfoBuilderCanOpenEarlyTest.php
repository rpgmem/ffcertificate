<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Frontend\CsvDownloadFormInfoBuilder;
use FreeFormCertificate\Tests\Support\SiteClock;

/**
 * Focused tests for the `can_open_early` flag introduced in 6.5.6.
 *
 * The flag must mirror EarlyOpenAction::is_eligible() exactly so the JS
 * never sees a "can-open" state that the server rejects on POST.
 *
 * Geofence, GeofenceLocationRegistry and SubmissionRepository are the real
 * ones: the form window is written into the stored geofence config as
 * wall-clock strings, read in a site timezone where the real "now" is noon
 * (SiteClock), the registered locations come from `get_option()`, and the
 * submission count from a `$wpdb` double.
 *
 * @covers \FreeFormCertificate\Frontend\CsvDownloadFormInfoBuilder
 */
class CsvDownloadFormInfoBuilderCanOpenEarlyTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var array<int, array<string, mixed>> */
	private array $meta_store = array();

	/** @var array<string, mixed> Options get_option() answers, beyond the formats. */
	private array $options = array();

	/** @var SiteClock A site timezone in which now is noon. */
	private SiteClock $clock;

	/** @var array{0: int, 1: string}|null Form start: days from today, local time. */
	private ?array $start = null;

	/** @var array{0: int, 1: string}|null Form end: days from today, local time. */
	private ?array $end = null;

	/** @var mixed The `$wpdb` global an earlier test left, restored afterwards. */
	private $wpdb_backup;

	/** @var bool Whether `$wpdb` was set before this test. */
	private bool $had_wpdb = false;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( '__' )->returnArg();
		Functions\when( 'get_the_title' )->justReturn( 'Form X' );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_date' )->alias( function ( $fmt, $ts = null, $tz = null ) {
			return gmdate( $fmt, (int) $ts );
		} );
		$this->options = array(
			'ffc_settings' => array(),
			'date_format'  => 'Y-m-d',
			'time_format'  => 'H:i',
		);
		Functions\when( 'get_option' )->alias( function ( $name, $default = false ) {
			return array_key_exists( $name, $this->options ) ? $this->options[ $name ] : $default;
		} );
		// wp_timezone() and current_time(): today is the site's date and the
		// window below is read as wall-clock strings hours away from midnight.
		$this->clock = new SiteClock();
		$this->clock->stub();

		$this->meta_store = array();
		$this->start      = null;
		$this->end        = null;
		Functions\when( 'get_post_meta' )->alias( function ( $id, $key ) {
			return $this->meta_store[ $id ][ $key ] ?? '';
		} );

		// Participant-form URL lookup (ScheduleExceptionAction::find_form_page_url,
		// now resolved on every build). Default: no embedding page found, so the
		// status carries an empty schedule_form_url and the summary shows no link.
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'get_posts' )->justReturn( array() );
		Functions\when( 'get_permalink' )->justReturn( '' );

		// SubmissionRepository::countForExport: no submissions.
		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing( static fn( $sql ) => $sql );
		$wpdb->shouldReceive( 'get_var' )->andReturn( '0' );

		$this->had_wpdb    = array_key_exists( 'wpdb', $GLOBALS );
		$this->wpdb_backup = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb']   = $wpdb;
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
	 * Set the form window the real Geofence reads. Each bound is the days
	 * from today and a local time; now is noon, so "13:00" today is an hour
	 * ahead and "11:00" an hour behind. Null leaves the bound unset.
	 *
	 * @param array{0: int, 1: string}|null $start Start bound.
	 * @param array{0: int, 1: string}|null $end   End bound.
	 */
	private function window( ?array $start, ?array $end ): void {
		$this->start = $start;
		$this->end   = $end;
	}

	/**
	 * Default form meta — every flag in the affirmative, with the window set
	 * by window(). Tests then flip exactly one toggle to assert it's the
	 * discriminating one.
	 */
	private function configure_form( int $form_id ): void {
		$geofence = array( 'datetime_enabled' => '1' );
		if ( null !== $this->start ) {
			// Same-day guard: date_start must match today for the early-open
			// button to surface.
			$geofence['date_start'] = $this->clock->day( $this->start[0] );
			$geofence['time_start'] = $this->start[1];
		}
		if ( null !== $this->end ) {
			$geofence['date_end'] = $this->clock->day( $this->end[0] );
			$geofence['time_end'] = $this->end[1];
		}

		$this->meta_store[ $form_id ] = array(
			'_ffc_form_config'        => array(),
			'_ffc_geofence_config'    => $geofence,
			'_ffc_csv_public_enabled' => '1',
			'_ffc_csv_public_limit'   => 5,
			'_ffc_csv_public_count'   => 0,
		);
	}

	public function test_can_open_early_true_when_all_conditions_met(): void {
		$this->window( array( 0, '13:00' ), array( 0, '14:00' ) );
		$this->configure_form( 1 );

		$info = ( new CsvDownloadFormInfoBuilder() )->build_form_info( 1 );
		$this->assertTrue( $info['status']['can_open_early'] );
	}

	public function test_can_open_early_false_when_form_already_started(): void {
		$this->window( array( 0, '11:00' ), array( 0, '13:00' ) );
		$this->configure_form( 1 );

		$info = ( new CsvDownloadFormInfoBuilder() )->build_form_info( 1 );
		$this->assertFalse( $info['status']['can_open_early'] );
	}

	public function test_can_open_early_false_when_form_already_ended(): void {
		$this->window( array( 0, '13:00' ), array( 0, '11:30' ) );
		$this->configure_form( 1 );

		$info = ( new CsvDownloadFormInfoBuilder() )->build_form_info( 1 );
		$this->assertFalse( $info['status']['can_open_early'] );
	}

	public function test_can_open_early_false_when_csv_public_disabled(): void {
		$this->window( array( 0, '13:00' ), array( 0, '14:00' ) );
		$this->configure_form( 1 );
		$this->meta_store[1]['_ffc_csv_public_enabled'] = '';

		$info = ( new CsvDownloadFormInfoBuilder() )->build_form_info( 1 );
		$this->assertFalse( $info['status']['can_open_early'] );
	}

	public function test_can_open_early_false_when_datetime_restrictions_disabled(): void {
		$this->window( array( 0, '13:00' ), array( 0, '14:00' ) );
		$this->configure_form( 1 );
		$this->meta_store[1]['_ffc_geofence_config']['datetime_enabled'] = '';

		$info = ( new CsvDownloadFormInfoBuilder() )->build_form_info( 1 );
		$this->assertFalse( $info['status']['can_open_early'] );
	}

	public function test_can_open_early_false_when_date_start_is_not_today(): void {
		// Same-day guard: button must not surface when the form's
		// configured start date is a different calendar day.
		$this->window( array( 1, '13:00' ), array( 2, '13:00' ) );
		$this->configure_form( 1 );
		$this->meta_store[1]['_ffc_geofence_config']['date_start'] = $this->clock->day( 1 );

		$info = ( new CsvDownloadFormInfoBuilder() )->build_form_info( 1 );
		$this->assertFalse( $info['status']['can_open_early'] );
	}

	public function test_can_open_early_false_when_no_start_date(): void {
		$this->window( null, array( 0, '14:00' ) );
		$this->configure_form( 1 );

		$info = ( new CsvDownloadFormInfoBuilder() )->build_form_info( 1 );
		$this->assertFalse( $info['status']['can_open_early'] );
	}

	// ==================================================================
	//  download_blocked_reason branches + quota / settings default limit
	// ==================================================================

	public function test_download_reason_no_end_date_when_form_has_no_close(): void {
		$this->window( array( 0, '10:00' ), null ); // started, no end date.
		$this->configure_form( 1 );

		$info = ( new CsvDownloadFormInfoBuilder() )->build_form_info( 1 );
		$this->assertSame( 'no_end_date', $info['status']['download_blocked_reason'] );
		$this->assertFalse( $info['status']['has_end_date'] );
	}

	public function test_download_reason_active_when_form_not_yet_ended(): void {
		$this->window( array( 0, '10:00' ), array( 0, '14:00' ) ); // running.
		$this->configure_form( 1 );

		$info = ( new CsvDownloadFormInfoBuilder() )->build_form_info( 1 );
		$this->assertSame( 'active', $info['status']['download_blocked_reason'] );
		$this->assertFalse( $info['status']['can_download'] );
	}

	public function test_download_reason_quota_exhausted_when_count_reaches_limit(): void {
		$this->window( array( 0, '10:00' ), array( 0, '11:00' ) ); // ended.
		$this->configure_form( 1 );
		$this->meta_store[1]['_ffc_csv_public_limit'] = 2;
		$this->meta_store[1]['_ffc_csv_public_count'] = 2;

		$info = ( new CsvDownloadFormInfoBuilder() )->build_form_info( 1 );
		$this->assertSame( 'quota_exhausted', $info['status']['download_blocked_reason'] );
		$this->assertSame( 0, $info['csv']['remaining'] );
	}

	public function test_download_reason_download_disabled_when_subtoggle_off(): void {
		$this->window( array( 0, '10:00' ), array( 0, '11:00' ) ); // ended, quota OK.
		$this->configure_form( 1 );
		$this->meta_store[1]['_ffc_csv_public_download_enabled'] = '0';

		$info = ( new CsvDownloadFormInfoBuilder() )->build_form_info( 1 );
		$this->assertSame( 'download_disabled', $info['status']['download_blocked_reason'] );
		$this->assertFalse( $info['status']['can_download'] );
		$this->assertTrue( $info['status']['csv_download_disabled_by_admin'] );
	}

	public function test_can_download_true_when_ended_quota_ok_and_enabled(): void {
		$this->window( array( 0, '10:00' ), array( 0, '11:00' ) ); // ended.
		$this->configure_form( 1 );

		$info = ( new CsvDownloadFormInfoBuilder() )->build_form_info( 1 );
		$this->assertNull( $info['status']['download_blocked_reason'] );
		$this->assertTrue( $info['status']['can_download'] );
	}

	public function test_limit_falls_back_to_settings_default_when_form_limit_zero(): void {
		$this->window( array( 0, '10:00' ), array( 0, '11:00' ) );
		$this->configure_form( 1 );
		$this->meta_store[1]['_ffc_csv_public_limit'] = 0;

		// SettingsReader::get_int reads ffc_settings; surface a default there.
		$this->options['ffc_settings'] = array( 'public_csv_default_limit' => 9 );

		$info = ( new CsvDownloadFormInfoBuilder() )->build_form_info( 1 );
		$this->assertSame( 9, $info['csv']['limit'] );
	}

	public function test_limit_defaults_to_one_when_no_form_or_settings_limit(): void {
		$this->window( array( 0, '10:00' ), array( 0, '11:00' ) );
		$this->configure_form( 1 );
		$this->meta_store[1]['_ffc_csv_public_limit'] = 0;
		// ffc_settings has no public_csv_default_limit (setUp returns array())
		// → SettingsReader::get_int returns 0 → builder floors the quota at 1.

		$info = ( new CsvDownloadFormInfoBuilder() )->build_form_info( 1 );
		$this->assertSame( 1, $info['csv']['limit'] );
	}

	// ==================================================================
	//  can_extend_end + is_today_end_date + schedule baselines
	// ==================================================================

	public function test_can_extend_end_true_when_all_conditions_met(): void {
		$this->window( array( 0, '11:00' ), array( 0, '13:00' ) ); // started, not ended.
		$this->configure_form( 1 );
		$this->meta_store[1]['_ffc_geofence_config']['date_end']   = $this->clock->day();
		$this->meta_store[1]['_ffc_geofence_config']['time_end']   = '23:59';
		$this->meta_store[1]['_ffc_csv_public_extend_end_enabled']            = '1';

		$info = ( new CsvDownloadFormInfoBuilder() )->build_form_info( 1 );
		$this->assertTrue( $info['status']['can_extend_end'] );
		$this->assertSame( '23:59', $info['status']['current_time_end'] );
	}

	public function test_can_extend_end_false_when_date_end_not_today(): void {
		$this->window( array( 0, '11:00' ), array( 1, '13:00' ) );
		$this->configure_form( 1 );
		$this->meta_store[1]['_ffc_geofence_config']['date_end'] = $this->clock->day( 1 );
		$this->meta_store[1]['_ffc_csv_public_extend_end_enabled']          = '1';

		$info = ( new CsvDownloadFormInfoBuilder() )->build_form_info( 1 );
		$this->assertFalse( $info['status']['can_extend_end'] );
	}

	public function test_schedule_exception_and_baselines_from_class_times(): void {
		$this->window( array( 0, '11:00' ), array( 0, '13:00' ) ); // open window.
		$this->configure_form( 1 );
		$this->meta_store[1]['_ffc_geofence_config'] = array_merge(
			$this->meta_store[1]['_ffc_geofence_config'],
			array(
				'schedule_exception_enabled' => '1',
				'datetime_enabled'           => '1',
				'class_time_start'           => '08:00',
				'class_time_end'             => '10:00',
				'time_start'                 => '07:00',
				'time_end'                   => '18:00',
				'schedule_default_mode'      => 'manual',
			)
		);

		$info = ( new CsvDownloadFormInfoBuilder() )->build_form_info( 1 );
		$this->assertTrue( $info['status']['can_schedule_exception'] );
		$this->assertSame( '08:00', $info['status']['schedule_baseline_start'] );
		$this->assertSame( '10:00', $info['status']['schedule_baseline_end'] );
		$this->assertSame( 'manual', $info['status']['schedule_default_mode'] );
	}

	public function test_schedule_baselines_fall_back_to_window_times_and_mode_defaults_to_now(): void {
		$this->window( array( 0, '11:00' ), array( 0, '13:00' ) );
		$this->configure_form( 1 );
		$this->meta_store[1]['_ffc_geofence_config'] = array_merge(
			$this->meta_store[1]['_ffc_geofence_config'],
			array(
				'time_start'            => '07:00',
				'time_end'              => '18:00',
				'schedule_default_mode' => 'bogus', // not in allowlist → 'now'.
			)
		);

		$info = ( new CsvDownloadFormInfoBuilder() )->build_form_info( 1 );
		$this->assertSame( '07:00', $info['status']['schedule_baseline_start'] );
		$this->assertSame( '18:00', $info['status']['schedule_baseline_end'] );
		$this->assertSame( 'now', $info['status']['schedule_default_mode'] );
	}

	// ==================================================================
	//  restrictions / quiz / geolocation section builders
	// ==================================================================

	public function test_restrictions_and_quiz_populated_from_form_config(): void {
		$this->window( array( 0, '13:00' ), array( 0, '14:00' ) );
		$this->configure_form( 1 );
		$this->meta_store[1]['_ffc_form_config'] = array(
			'restrictions' => array(
				'password'  => '1',
				'allowlist' => '1',
				'denylist'  => '1',
				'ticket'    => '1',
			),
			'quiz_enabled'       => '1',
			'quiz_passing_score' => 70,
			'quiz_max_attempts'  => 3,
		);

		$info = ( new CsvDownloadFormInfoBuilder() )->build_form_info( 1 );
		$this->assertTrue( $info['restrictions']['password'] );
		$this->assertTrue( $info['restrictions']['allowlist'] );
		$this->assertTrue( $info['restrictions']['denylist'] );
		$this->assertTrue( $info['restrictions']['ticket'] );
		$this->assertTrue( $info['quiz']['enabled'] );
		$this->assertSame( 70, $info['quiz']['passing_score'] );
		$this->assertSame( 3, $info['quiz']['max_attempts'] );
	}

	public function test_geolocation_disabled_returns_enabled_false(): void {
		$this->window( array( 0, '13:00' ), array( 0, '14:00' ) );
		$this->configure_form( 1 );
		// geofence config has no geo_enabled key → disabled.

		$info = ( new CsvDownloadFormInfoBuilder() )->build_form_info( 1 );
		$this->assertFalse( $info['geolocation']['enabled'] );
	}

	public function test_geolocation_with_gps_and_ip_locations(): void {
		$this->window( array( 0, '13:00' ), array( 0, '14:00' ) );
		$this->configure_form( 1 );
		$this->meta_store[1]['_ffc_geofence_config'] = array_merge(
			$this->meta_store[1]['_ffc_geofence_config'],
			array(
				'geo_enabled'              => '1',
				'geo_gps_enabled'         => '1',
				'geo_ip_enabled'          => '1',
				'geo_ip_areas_permissive' => '1',
				'geo_area_source'         => 'locations',
				'geo_area_location_ids'   => array( 'loc_1' ),
				'geo_ip_area_source'      => 'locations',
				'geo_ip_area_location_ids' => array( 'loc_2' ),
			)
		);

		// GeofenceLocationRegistry reads the registered locations from this option.
		$this->options['ffc_geofence_locations'] = array(
			array( 'id' => 'loc_1', 'name' => 'HQ', 'lat' => -23.5, 'lng' => -46.6, 'radius' => 100 ),
			array( 'id' => 'loc_2', 'name' => 'Annex', 'lat' => -22.9, 'lng' => -43.2, 'radius' => 50 ),
			array( 'id' => 'loc_3', 'name' => 'Unused', 'lat' => 0, 'lng' => 0, 'radius' => 10 ),
		);

		$info = ( new CsvDownloadFormInfoBuilder() )->build_form_info( 1 );
		$geo  = $info['geolocation'];
		$this->assertTrue( $geo['enabled'] );
		$this->assertTrue( $geo['gps_enabled'] );
		$this->assertTrue( $geo['ip_enabled'] );
		$this->assertSame( 'HQ', $geo['gps_locations'][0]['name'] );
		$this->assertSame( -23.5, $geo['gps_locations'][0]['lat'] );
		$this->assertStringContainsString( 'google.com/maps', $geo['gps_locations'][0]['maps_url'] );
		$this->assertSame( array( 'HQ' ), array_column( $geo['gps_locations'], 'name' ), 'Only the GPS ids are read for GPS.' );
		$this->assertSame( array( 'Annex' ), array_column( $geo['ip_locations'], 'name' ), 'Only the IP ids are read for IP.' );
	}

	public function test_geolocation_custom_area_when_source_not_locations(): void {
		$this->window( array( 0, '13:00' ), array( 0, '14:00' ) );
		$this->configure_form( 1 );
		$this->meta_store[1]['_ffc_geofence_config'] = array_merge(
			$this->meta_store[1]['_ffc_geofence_config'],
			array(
				'geo_enabled'             => '1',
				'geo_gps_enabled'         => '1',
				'geo_ip_enabled'          => '1',
				'geo_ip_areas_permissive' => '1',
				'geo_area_source'         => 'custom',
				'geo_ip_area_source'      => 'custom',
			)
		);

		$info = ( new CsvDownloadFormInfoBuilder() )->build_form_info( 1 );
		$geo  = $info['geolocation'];
		$this->assertTrue( $geo['gps_custom'] );
		$this->assertTrue( $geo['ip_custom'] );
	}

	// ==================================================================
	//  cert preview + before/after date formatting
	// ==================================================================

	public function test_can_preview_cert_true_before_start_and_formatted_dates(): void {
		$this->window( array( 0, '13:00' ), array( 0, '14:00' ) ); // before start.
		$this->configure_form( 1 );

		$info = ( new CsvDownloadFormInfoBuilder() )->build_form_info( 1 );
		$this->assertTrue( $info['status']['can_preview_cert'] );
		$this->assertTrue( $info['status']['before_start'] );
		$this->assertIsString( $info['status']['start_date_formatted'] );
		$this->assertIsString( $info['status']['end_date_formatted'] );
	}
}
