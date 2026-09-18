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
use FreeFormCertificate\Maintenance\IdentityConflictQuery;
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

	/** @var IdentityConflictQuery|Mockery\MockInterface */
	private $conflicts;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();
		$this->repo = Mockery::mock( SubmissionRepository::class );

		// The cross-store questions reach four tables through the global
		// `$wpdb` (#1313 PR 10). What this file measures is how the auditor
		// AGGREGATES answers, so the query object is a double and its SQL is
		// its own test's subject.
		$this->conflicts = Mockery::mock( IdentityConflictQuery::class );
		$this->conflicts->shouldReceive( 'shared_identities' )->andReturn( array() )->byDefault();
		$this->conflicts->shouldReceive( 'multiple_identities' )->andReturn( array() )->byDefault();
		$this->conflicts->shouldReceive( 'unindexed_links' )->andReturn( array() )->byDefault();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * The auditor with the cross-store seam filled by the double.
	 */
	private function auditor(): SubmissionLinkAuditor {
		$conflicts = $this->conflicts;

		return new class( $this->repo, $conflicts ) extends SubmissionLinkAuditor {
			/** @var IdentityConflictQuery */
			private $double;

			/**
			 * @param SubmissionRepository  $repository Repository double.
			 * @param IdentityConflictQuery $double     Cross-store double.
			 */
			public function __construct( $repository, $double ) {
				parent::__construct( $repository );
				$this->double = $double;
			}

			/**
			 * @return IdentityConflictQuery
			 */
			protected function conflicts(): IdentityConflictQuery {
				return $this->double;
			}
		};
	}

	public function test_is_report_only_metadata(): void {
		$tool = $this->auditor();
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

		$report = $this->auditor()->run( array() );

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

		$report = $this->auditor()->run( array() );

		$this->assertTrue( $report['checks']['orphan_links']['truncated'] );
		$this->assertSame( SubmissionLinkAuditor::SAMPLE_LIMIT, $report['checks']['orphan_links']['count'] );
	}

	public function test_the_cross_store_checks_are_aggregated_too(): void {
		$this->repo->shouldReceive( 'find_orphan_user_links' )->andReturn( array() );
		$this->repo->shouldReceive( 'find_users_with_multiple_identities' )->andReturn( array() );
		$this->repo->shouldReceive( 'find_unlinked_with_matching_identity' )->andReturn( array() );
		$this->repo->shouldReceive( 'find_shared_identities' )->andReturn( array() );

		$this->conflicts->shouldReceive( 'shared_identities' )->once()->andReturn(
			array(
				array(
					'subject'           => 'hash-of-one-cpf',
					'user_count'        => 2,
					'identifier_column' => 'cpf_hash',
				),
			)
		);
		$this->conflicts->shouldReceive( 'multiple_identities' )->once()->andReturn(
			array(
				array(
					'subject'           => 7,
					'identity_count'    => 2,
					'identifier_column' => 'cpf_hash',
				),
			)
		);
		$this->conflicts->shouldReceive( 'unindexed_links' )->once()->andReturn(
			array(
				array(
					'user_id'           => 9,
					'identifier_column' => 'rf_hash',
				),
			)
		);

		$report = $this->auditor()->run( array() );

		$this->assertSame( 1, $report['checks']['cross_store_shared_identities']['count'] );
		$this->assertSame( 1, $report['checks']['cross_store_multiple_identities']['count'] );
		$this->assertSame( 1, $report['checks']['unindexed_links']['count'] );
		$this->assertSame( 3, $report['total'], 'The cross-store findings are computed and left out of the total, so the screen would say the install is clean.' );
	}

	/**
	 * Every check the auditor returns is shown.
	 *
	 * THE REPORT IS NOT THE SCREEN. The Migrations tab renders the counts by
	 * iterating a hand-written label map, so a check added to the auditor
	 * without a label there is computed on every run and displayed nowhere --
	 * the "built but never wired" class `AjaxWiringTest` exists for, and the
	 * one that would quietly make #1313\'s conflict count unreadable after all
	 * this.
	 *
	 * The view is read as text rather than rendered: it is a template that
	 * expects a page full of locals, and what matters here is which keys it
	 * names.
	 */
	public function test_every_check_has_a_label_on_the_migrations_tab(): void {
		$this->repo->shouldReceive( 'find_orphan_user_links' )->andReturn( array() );
		$this->repo->shouldReceive( 'find_users_with_multiple_identities' )->andReturn( array() );
		$this->repo->shouldReceive( 'find_unlinked_with_matching_identity' )->andReturn( array() );
		$this->repo->shouldReceive( 'find_shared_identities' )->andReturn( array() );

		$report = $this->auditor()->run( array() );
		$view   = (string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/settings/views/ffc-tab-migrations.php' );

		$this->assertSame(
			1,
			preg_match( '/\$ffcertificate_sa_labels = array\((.*?)\n\t\);/s', $view, $m ),
			'Could not find the label map — it moved or changed shape, so this test is no longer measuring the screen.'
		);

		foreach ( array_keys( $report['checks'] ) as $key ) {
			$this->assertStringContainsString(
				"'{$key}'",
				$m[1],
				"The audit computes '{$key}' and the Migrations tab has no label for it, so its count is never shown."
			);
		}
	}

	public function test_clean_database_reports_zero(): void {
		$this->repo->shouldReceive( 'find_orphan_user_links' )->once()->andReturn( array() );
		$this->repo->shouldReceive( 'find_users_with_multiple_identities' )->once()->andReturn( array() );
		$this->repo->shouldReceive( 'find_unlinked_with_matching_identity' )->once()->andReturn( array() );
		$this->repo->shouldReceive( 'find_shared_identities' )->once()->andReturn( array() );

		$report = $this->auditor()->run( array() );

		$this->assertSame( 0, $report['total'] );
	}
}
