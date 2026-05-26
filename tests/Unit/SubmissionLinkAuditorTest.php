<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Maintenance\MaintenanceToolInterface;
use FreeFormCertificate\Maintenance\SubmissionLinkAuditor;
use FreeFormCertificate\Repositories\SubmissionRepository;

/**
 * Tests for the SubmissionLinkAuditor maintenance tool.
 *
 * @covers \FreeFormCertificate\Maintenance\SubmissionLinkAuditor
 */
class SubmissionLinkAuditorTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var SubmissionRepository|Mockery\MockInterface */
	private $repo;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();
		$this->repo = Mockery::mock( SubmissionRepository::class );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_is_report_only_metadata(): void {
		$tool = new SubmissionLinkAuditor( $this->repo );
		$this->assertInstanceOf( MaintenanceToolInterface::class, $tool );
		$this->assertSame( 'submission_link_audit', $tool->get_id() );
		$this->assertFalse( $tool->is_actionable() );
		$this->assertSame( array(), $tool->get_default_options() );
		$this->assertNotSame( '', $tool->get_title() );
	}

	public function test_run_aggregates_all_four_checks(): void {
		$this->repo->shouldReceive( 'find_orphan_user_links' )->once()->andReturn(
			array(
				array(
					'id'      => 1,
					'user_id' => 99,
					'form_id' => 3,
				),
			)
		);
		$this->repo->shouldReceive( 'find_users_with_multiple_identities' )->once()->andReturn(
			array(
				array(
					'user_id'   => 5,
					'cpf_count' => 2,
					'rf_count'  => 1,
				),
				array(
					'user_id'   => 6,
					'cpf_count' => 1,
					'rf_count'  => 3,
				),
			)
		);
		$this->repo->shouldReceive( 'find_unlinked_with_matching_identity' )->once()->andReturn( array() );
		$this->repo->shouldReceive( 'find_shared_identities' )->once()->andReturn(
			array(
				array(
					'cpf_hash'   => 'abc',
					'user_count' => 2,
				),
			)
		);

		$report = ( new SubmissionLinkAuditor( $this->repo ) )->run( array() );

		$this->assertSame( 1, $report['checks']['orphan_links']['count'] );
		$this->assertSame( 2, $report['checks']['multiple_identities']['count'] );
		$this->assertSame( 0, $report['checks']['should_be_linked']['count'] );
		$this->assertSame( 1, $report['checks']['shared_identities']['count'] );
		$this->assertSame( 4, $report['total'] );
		$this->assertFalse( $report['checks']['orphan_links']['truncated'] );
		$this->assertSame( 99, $report['checks']['orphan_links']['rows'][0]['user_id'] );
	}

	public function test_truncated_flag_when_sample_limit_hit(): void {
		$full = array_fill( 0, SubmissionLinkAuditor::SAMPLE_LIMIT, array( 'id' => 1 ) );
		$this->repo->shouldReceive( 'find_orphan_user_links' )->once()->andReturn( $full );
		$this->repo->shouldReceive( 'find_users_with_multiple_identities' )->once()->andReturn( array() );
		$this->repo->shouldReceive( 'find_unlinked_with_matching_identity' )->once()->andReturn( array() );
		$this->repo->shouldReceive( 'find_shared_identities' )->once()->andReturn( array() );

		$report = ( new SubmissionLinkAuditor( $this->repo ) )->run( array() );

		$this->assertTrue( $report['checks']['orphan_links']['truncated'] );
		$this->assertSame( SubmissionLinkAuditor::SAMPLE_LIMIT, $report['checks']['orphan_links']['count'] );
	}

	public function test_clean_database_reports_zero(): void {
		$this->repo->shouldReceive( 'find_orphan_user_links' )->once()->andReturn( array() );
		$this->repo->shouldReceive( 'find_users_with_multiple_identities' )->once()->andReturn( array() );
		$this->repo->shouldReceive( 'find_unlinked_with_matching_identity' )->once()->andReturn( array() );
		$this->repo->shouldReceive( 'find_shared_identities' )->once()->andReturn( array() );

		$report = ( new SubmissionLinkAuditor( $this->repo ) )->run( array() );

		$this->assertSame( 0, $report['total'] );
	}
}
