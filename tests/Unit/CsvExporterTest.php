<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Admin\CsvExporter;

/**
 * Tests for CsvExporter, the thin façade over the batched export engine
 * (issue #772): it registers the three AJAX hooks and owns the daily stale-job
 * cleanup cron. The export logic now lives in
 * {@see \FreeFormCertificate\Admin\SubmissionsExportSource} (see
 * SubmissionsExportSourceTest) and the job lifecycle in
 * {@see \FreeFormCertificate\Core\BatchedCsvExport} (see BatchedCsvExportTest).
 *
 * @covers \FreeFormCertificate\Admin\CsvExporter
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class CsvExporterTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		class_exists( '\\FreeFormCertificate\Admin\CsvExporter' );
		Functions\when( '__' )->returnArg();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// ==================================================================
	// register_ajax_hooks()
	// ==================================================================

	public function test_register_ajax_hooks_registers_three_actions(): void {
		$hooks = array();
		Functions\when( 'add_action' )->alias(
			static function ( $hook ) use ( &$hooks ) {
				$hooks[] = $hook;
				return true;
			}
		);

		$ref      = new \ReflectionClass( CsvExporter::class );
		$exporter = $ref->newInstanceWithoutConstructor();
		$exporter->register_ajax_hooks();

		$this->assertSame(
			array( 'wp_ajax_ffc_csv_export_start', 'wp_ajax_ffc_csv_export_batch', 'wp_ajax_ffc_csv_export_download' ),
			$hooks
		);
	}

	// ==================================================================
	// cleanup_stale_export_jobs()
	// ==================================================================

	public function test_cleanup_stale_export_jobs_reclaims_expired_and_unlinks_file(): void {
		$tmp = (string) tempnam( sys_get_temp_dir(), 'ffcstale' );

		$wpdb          = \Mockery::mock();
		$wpdb->options = 'wp_options';
		$wpdb->shouldReceive( 'esc_like' )->andReturnUsing( static fn( $v ) => $v );
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			static fn( $q, $arg ) => str_replace( '%s', $arg, $q )
		);
		// First prefix (admin) → one expired row referencing $tmp; second → none.
		$wpdb->shouldReceive( 'get_results' )->andReturn(
			array(
				(object) array(
					'option_name'  => '_transient_timeout_ffc_csv_export_abc',
					'option_value' => (string) ( time() - 100 ),
				),
				(object) array(
					'option_name'  => '_transient_timeout_ffc_csv_export_fresh',
					'option_value' => (string) ( time() + 9999 ),
				),
			),
			array()
		);
		$GLOBALS['wpdb'] = $wpdb;

		Functions\when( 'get_option' )->alias(
			static fn( $name ) => '_transient_ffc_csv_export_abc' === $name ? array( 'file' => $tmp ) : false
		);
		$deleted = array();
		Functions\when( 'delete_transient' )->alias(
			static function ( $key ) use ( &$deleted ) {
				$deleted[] = $key;
				return true;
			}
		);
		Functions\when( 'FreeFormCertificate\Admin\file_exists' )->alias( static fn( $f ) => file_exists( $f ) );
		Functions\when( 'FreeFormCertificate\Admin\unlink' )->alias( static fn( $f ) => @unlink( $f ) );

		$reclaimed = CsvExporter::cleanup_stale_export_jobs();

		$this->assertSame( 1, $reclaimed );
		$this->assertContains( 'ffc_csv_export_abc', $deleted );
		$this->assertFileDoesNotExist( $tmp );

		unset( $GLOBALS['wpdb'] );
		@unlink( $tmp );
	}

	public function test_cleanup_stale_export_jobs_returns_zero_when_no_rows(): void {
		$wpdb          = \Mockery::mock();
		$wpdb->options = 'wp_options';
		$wpdb->shouldReceive( 'esc_like' )->andReturnUsing( static fn( $v ) => $v );
		$wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );
		$wpdb->shouldReceive( 'get_results' )->andReturn( array(), array() );
		$GLOBALS['wpdb'] = $wpdb;

		$this->assertSame( 0, CsvExporter::cleanup_stale_export_jobs() );

		unset( $GLOBALS['wpdb'] );
	}
}
