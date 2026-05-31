<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Maintenance\MaintenanceToolInterface;
use FreeFormCertificate\Maintenance\UrlShortenerCleaner;
use FreeFormCertificate\UrlShortener\UrlShortenerRepository;

/**
 * Tests for the UrlShortenerCleaner maintenance tool.
 *
 * @covers \FreeFormCertificate\Maintenance\UrlShortenerCleaner
 */
class UrlShortenerCleanerTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var UrlShortenerRepository|Mockery\MockInterface */
	private $repo;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();
		$this->repo = Mockery::mock( UrlShortenerRepository::class );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function cleaner(): UrlShortenerCleaner {
		return new UrlShortenerCleaner( $this->repo );
	}

	public function test_is_actionable_and_metadata(): void {
		$cleaner = $this->cleaner();
		$this->assertInstanceOf( MaintenanceToolInterface::class, $cleaner );
		$this->assertSame( 'url_shortener_cleanup', $cleaner->get_id() );
		$this->assertTrue( $cleaner->is_actionable() );
		$this->assertNotSame( '', $cleaner->get_title() );
		$this->assertNotSame( '', $cleaner->get_description() );

		$defaults = $cleaner->get_default_options();
		$this->assertSame( UrlShortenerCleaner::DEFAULT_DAYS, $defaults['days'] );
		$this->assertTrue( $defaults['dry_run'] );
		$this->assertArrayHasKey( 'orphaned', $defaults['criteria'] );
	}

	public function test_no_criteria_selected_does_not_query_or_delete(): void {
		$this->repo->shouldNotReceive( 'find_cleanup_candidates' );
		$this->repo->shouldNotReceive( 'delete' );

		$report = $this->cleaner()->run(
			array(
				'criteria' => array(
					'orphaned'      => false,
					'never_clicked' => false,
					'trashed'       => false,
				),
				'dry_run'  => true,
			)
		);

		$this->assertSame( 0, $report['candidates'] );
		$this->assertSame( 0, $report['deleted'] );
		$this->assertSame( array(), $report['affected'] );
	}

	public function test_dry_run_counts_reasons_without_deleting(): void {
		$this->repo->shouldReceive( 'find_cleanup_candidates' )
			->once()
			->with(
				array(
					'orphaned'      => true,
					'never_clicked' => true,
					'trashed'       => true,
				),
				90
			)
			->andReturn(
				array(
					array(
						'id'               => 1,
						'short_code'       => 'aaa',
						'title'            => 'Orphan',
						'target_url'       => 'https://x/1',
						'is_orphaned'      => 1,
						'is_never_clicked' => 0,
						'is_trashed'       => 0,
					),
					array(
						'id'               => 2,
						'short_code'       => 'bbb',
						'title'            => 'Stale + trashed',
						'target_url'       => 'https://x/2',
						'is_orphaned'      => 0,
						'is_never_clicked' => 1,
						'is_trashed'       => 1,
					),
				)
			);
		$this->repo->shouldNotReceive( 'delete' );

		$report = $this->cleaner()->run(
			array(
				'criteria' => array(
					'orphaned'      => true,
					'never_clicked' => true,
					'trashed'       => true,
				),
				'days'     => 90,
				'dry_run'  => true,
			)
		);

		$this->assertTrue( $report['dry_run'] );
		$this->assertSame( 2, $report['candidates'] );
		$this->assertSame( 0, $report['deleted'] );
		$this->assertSame( 1, $report['by_reason']['orphaned'] );
		$this->assertSame( 1, $report['by_reason']['never_clicked'] );
		$this->assertSame( 1, $report['by_reason']['trashed'] );
		$this->assertCount( 2, $report['affected'] );
		$this->assertSame( array( 'never_clicked', 'trashed' ), $report['affected'][1]['reasons'] );
	}

	public function test_execute_deletes_each_matched_row(): void {
		$this->repo->shouldReceive( 'find_cleanup_candidates' )->once()->andReturn(
			array(
				array(
					'id'               => 7,
					'short_code'       => 'ggg',
					'title'            => '',
					'target_url'       => '',
					'is_orphaned'      => 1,
					'is_never_clicked' => 0,
					'is_trashed'       => 0,
				),
				array(
					'id'               => 8,
					'short_code'       => 'hhh',
					'title'            => '',
					'target_url'       => '',
					'is_orphaned'      => 1,
					'is_never_clicked' => 0,
					'is_trashed'       => 0,
				),
			)
		);
		$this->repo->shouldReceive( 'delete' )->once()->with( 7 )->andReturn( 1 );
		$this->repo->shouldReceive( 'delete' )->once()->with( 8 )->andReturn( 1 );

		$report = $this->cleaner()->run(
			array(
				'criteria' => array( 'orphaned' => true ),
				'dry_run'  => false,
			)
		);

		$this->assertFalse( $report['dry_run'] );
		$this->assertSame( 2, $report['candidates'] );
		$this->assertSame( 2, $report['deleted'] );
	}

	public function test_failed_delete_is_not_counted(): void {
		$this->repo->shouldReceive( 'find_cleanup_candidates' )->once()->andReturn(
			array(
				array(
					'id'               => 9,
					'short_code'       => 'iii',
					'title'            => '',
					'target_url'       => '',
					'is_orphaned'      => 1,
					'is_never_clicked' => 0,
					'is_trashed'       => 0,
				),
			)
		);
		$this->repo->shouldReceive( 'delete' )->once()->with( 9 )->andReturn( false );

		$report = $this->cleaner()->run(
			array(
				'criteria' => array( 'orphaned' => true ),
				'dry_run'  => false,
			)
		);

		$this->assertSame( 1, $report['candidates'] );
		$this->assertSame( 0, $report['deleted'] );
	}

	public function test_only_enabled_criterion_attributes_reasons(): void {
		// Row flagged never_clicked + trashed, but only `trashed` is enabled.
		$this->repo->shouldReceive( 'find_cleanup_candidates' )->once()->andReturn(
			array(
				array(
					'id'               => 3,
					'short_code'       => 'ccc',
					'title'            => '',
					'target_url'       => '',
					'is_orphaned'      => 0,
					'is_never_clicked' => 1,
					'is_trashed'       => 1,
				),
			)
		);

		$report = $this->cleaner()->run(
			array(
				'criteria' => array( 'trashed' => true ),
				'dry_run'  => true,
			)
		);

		$this->assertSame( 0, $report['by_reason']['never_clicked'] );
		$this->assertSame( 1, $report['by_reason']['trashed'] );
		$this->assertSame( array( 'trashed' ), $report['affected'][0]['reasons'] );
	}

	public function test_truncates_affected_list_but_counts_all(): void {
		$rows = array();
		for ( $i = 1; $i <= UrlShortenerCleaner::REPORT_LIMIT + 5; $i++ ) {
			$rows[] = array(
				'id'               => $i,
				'short_code'       => 'c' . $i,
				'title'            => '',
				'target_url'       => '',
				'is_orphaned'      => 1,
				'is_never_clicked' => 0,
				'is_trashed'       => 0,
			);
		}
		$this->repo->shouldReceive( 'find_cleanup_candidates' )->once()->andReturn( $rows );

		$report = $this->cleaner()->run(
			array(
				'criteria' => array( 'orphaned' => true ),
				'dry_run'  => true,
			)
		);

		$this->assertSame( UrlShortenerCleaner::REPORT_LIMIT + 5, $report['candidates'] );
		$this->assertSame( UrlShortenerCleaner::REPORT_LIMIT + 5, $report['by_reason']['orphaned'] );
		$this->assertCount( UrlShortenerCleaner::REPORT_LIMIT, $report['affected'] );
		$this->assertTrue( $report['truncated'] );
	}
}
