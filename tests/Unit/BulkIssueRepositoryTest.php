<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use FreeFormCertificate\Submissions\BulkIssueActivator;
use FreeFormCertificate\Submissions\BulkIssueRepository;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * Tests for BulkIssueRepository: the staging rows of the bulk-issue import (#1945).
 *
 * @covers \FreeFormCertificate\Submissions\BulkIssueRepository
 */
class BulkIssueRepositoryTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		global $wpdb;
		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_the_two_tables_are_named_under_the_site_prefix(): void {
		$this->assertSame( 'wp_ffc_bulk_issue_jobs', BulkIssueActivator::jobs_table() );
		$this->assertSame( 'wp_ffc_bulk_issue_rows', BulkIssueActivator::rows_table() );
	}

	public function test_delete_rows_removes_only_the_lines_of_that_job(): void {
		global $wpdb;
		$wpdb->shouldReceive( 'delete' )
			->once()
			->with( 'wp_ffc_bulk_issue_rows', array( 'job_id' => 7 ), array( '%d' ) )
			->andReturn( 3 );

		$this->assertSame( 3, BulkIssueRepository::delete_rows( 7 ) );
	}

	public function test_delete_rows_reports_zero_when_the_delete_fails(): void {
		global $wpdb;
		$wpdb->shouldReceive( 'delete' )->once()->andReturn( false );

		$this->assertSame( 0, BulkIssueRepository::delete_rows( 7 ) );
	}

	public function test_purge_stale_expires_each_unfinished_job_and_clears_its_rows(): void {
		global $wpdb;
		$wpdb->shouldReceive( 'prepare' )->once()->andReturn( 'SELECT id ...' );
		$wpdb->shouldReceive( 'get_col' )->once()->andReturn( array( '7', '9' ) );
		$wpdb->shouldReceive( 'delete' )
			->with( 'wp_ffc_bulk_issue_rows', array( 'job_id' => 7 ), array( '%d' ) )
			->once()
			->andReturn( 2 );
		$wpdb->shouldReceive( 'delete' )
			->with( 'wp_ffc_bulk_issue_rows', array( 'job_id' => 9 ), array( '%d' ) )
			->once()
			->andReturn( 0 );
		$wpdb->shouldReceive( 'update' )
			->twice()
			->with(
				'wp_ffc_bulk_issue_jobs',
				Mockery::on(
					static fn( array $data ): bool => BulkIssueRepository::STATUS_EXPIRED === $data['status'] && isset( $data['finished_at'] )
				),
				Mockery::type( 'array' ),
				array( '%s', '%d' ),
				array( '%d' )
			)
			->andReturn( 1 );

		$this->assertSame( 2, BulkIssueRepository::purge_stale( 1700000000 ) );
	}

	public function test_purge_stale_leaves_the_jobs_alone_when_none_is_old_enough(): void {
		global $wpdb;
		$wpdb->shouldReceive( 'prepare' )->once()->andReturn( 'SELECT id ...' );
		$wpdb->shouldReceive( 'get_col' )->once()->andReturn( array() );
		$wpdb->shouldNotReceive( 'delete' );
		$wpdb->shouldNotReceive( 'update' );

		$this->assertSame( 0, BulkIssueRepository::purge_stale( 1700000000 ) );
	}
}
