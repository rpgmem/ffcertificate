<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FreeFormCertificate\Recruitment\CsvStagingService;
use FreeFormCertificate\Reregistration\ReregistrationImportStagingService;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * The import staging tables are swept by the daily cleanup, not only by the
 * next import (#2343), and the sweep tolerates a module that never created
 * them.
 *
 * @covers \FreeFormCertificate\Recruitment\CsvStagingService
 * @covers \FreeFormCertificate\Reregistration\ReregistrationImportStagingService
 */
class ImportStagingDailySweepTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var array{0: bool, 1: mixed} Whether `$wpdb` was set, and its value. */
	private array $wpdb_backup = array( false, null );

	/**
	 * Every statement sent to query().
	 *
	 * @var array<int, string>
	 */
	private array $queries = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->wpdb_backup = array( array_key_exists( 'wpdb', $GLOBALS ), $GLOBALS['wpdb'] ?? null );
		Functions\when( 'wp_date' )->alias( static fn( $format, $ts ) => gmdate( $format, (int) $ts ) );
	}

	protected function tearDown(): void {
		if ( $this->wpdb_backup[0] ) {
			$GLOBALS['wpdb'] = $this->wpdb_backup[1];
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * A `$wpdb` whose tables exist or not.
	 *
	 * @param bool $tables_exist Whether SHOW TABLES finds the jobs table.
	 */
	private function wpdb( bool $tables_exist ): void {
		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			static fn( $sql, ...$args ) => 0 === strpos( (string) $sql, 'SHOW TABLES LIKE' ) ? 'SHOW TABLES LIKE ' . $args[0] : (string) $sql
		);
		$wpdb->shouldReceive( 'get_var' )->andReturnUsing(
			static fn( $sql ) => $tables_exist ? substr( (string) $sql, 17 ) : null
		);
		$wpdb->shouldReceive( 'query' )->andReturnUsing(
			function ( $sql ) {
				$this->queries[] = (string) $sql;
				return 0;
			}
		);
		$GLOBALS['wpdb'] = $wpdb;
	}

	public function test_both_sweeps_delete_stale_jobs_when_the_tables_exist(): void {
		$this->wpdb( true );

		CsvStagingService::sweep_stale_jobs();
		ReregistrationImportStagingService::sweep_stale_jobs();

		$deletes = array_filter( $this->queries, static fn( $q ) => 0 === strpos( $q, 'DELETE' ) );
		$this->assertCount( 4, $deletes, 'Staging rows, then jobs, for each module.' );
		$this->assertNotEmpty( array_filter( $deletes, static fn( $q ) => false !== strpos( $q, 'ffc_recruitment_import_jobs' ) ) );
		$this->assertNotEmpty( array_filter( $deletes, static fn( $q ) => false !== strpos( $q, 'ffc_reregistration_import_jobs' ) ) );
	}

	public function test_a_module_without_tables_is_left_alone(): void {
		$this->wpdb( false );

		CsvStagingService::sweep_stale_jobs();
		ReregistrationImportStagingService::sweep_stale_jobs();

		$this->assertSame( array(), $this->queries );
	}
}
