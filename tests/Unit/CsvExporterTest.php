<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Admin\CsvExporter;

/**
 * Tests for CsvExporter (issue #772): it registers the submissions source with
 * the shared {@see \FreeFormCertificate\Core\SourceRegistry} (the unified
 * dispatcher routes `type=submissions` to it) and owns the daily stale-job
 * cleanup cron. The export logic lives in
 * {@see \FreeFormCertificate\Admin\SubmissionsExportSource} (see
 * SubmissionsExportSourceTest) and the job lifecycle in
 * {@see \FreeFormCertificate\Core\BatchedCsvExport} (see BatchedCsvExportTest).
 *
 * @covers \FreeFormCertificate\Admin\CsvExporter
 */
class CsvExporterTest extends TestCase {

	/** @var string The uploads directory the age sweep reads. */
	private string $uploads = '';

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		class_exists( '\\FreeFormCertificate\Admin\CsvExporter' );
		Functions\when( '__' )->returnArg();
		// The age sweep (#2343) reads the uploads directory; an empty temp
		// directory unless a test fills it.
		$this->uploads = sys_get_temp_dir() . '/ffc-csv-exporter-' . uniqid();
		mkdir( $this->uploads . '/ffc-tmp', 0777, true );
		Functions\when( 'wp_upload_dir' )->justReturn( array( 'basedir' => $this->uploads ) );
		Functions\when( 'wp_delete_file' )->alias( static fn( $file ) => unlink( $file ) );
	}

	protected function tearDown(): void {
		foreach ( array_diff( (array) scandir( $this->uploads . '/ffc-tmp' ), array( '.', '..' ) ) as $file ) {
			unlink( $this->uploads . '/ffc-tmp/' . $file );
		}
		rmdir( $this->uploads . '/ffc-tmp' );
		rmdir( $this->uploads );
		Monkey\tearDown();
		parent::tearDown();
	}

	// ==================================================================
	// register_source()
	// ==================================================================

	public function test_register_source_registers_submissions_type(): void {
		\FreeFormCertificate\Core\SourceRegistry::reset();

		$ref      = new \ReflectionClass( CsvExporter::class );
		$exporter = $ref->newInstanceWithoutConstructor();
		$exporter->register_source();

		$this->assertTrue( \FreeFormCertificate\Core\SourceRegistry::has( 'submissions' ) );
		$this->assertContains( 'submissions', \FreeFormCertificate\Core\SourceRegistry::types() );
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

	public function test_the_age_sweep_deletes_only_export_files_older_than_a_day(): void {
		// An export whose transient is gone -- expired and deleted by core, or
		// never in wp_options under an object cache -- left its file behind.
		$old   = $this->uploads . '/ffc-tmp/ffc-export-old.csv';
		$fresh = $this->uploads . '/ffc-tmp/ffc-export-fresh.csv';
		$guard = $this->uploads . '/ffc-tmp/.htaccess';
		file_put_contents( $old, 'a' );
		file_put_contents( $fresh, 'b' );
		file_put_contents( $guard, "Deny from all\n" );
		touch( $old, time() - DAY_IN_SECONDS - 60 );
		touch( $guard, time() - DAY_IN_SECONDS - 60 );

		$this->assertSame( 1, CsvExporter::sweep_temp_files_by_age() );
		$this->assertFileDoesNotExist( $old );
		$this->assertFileExists( $fresh, 'A file younger than a day may belong to a live job.' );
		$this->assertFileExists( $guard, 'The directory guard is not an export.' );
	}
}
