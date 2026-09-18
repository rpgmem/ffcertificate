<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Maintenance\IdentityAuditExportSource;

/**
 * The link audit's CSV export (#1295).
 *
 * Every assertion here is on the ROWS the source hands the streamer, because
 * that is the artefact an operator opens. The auditor is injected, so what is
 * under test is the normalisation — seven checks return seven different column
 * sets onto one header — and never the queries, which have their own tests.
 *
 * @covers \FreeFormCertificate\Maintenance\IdentityAuditExportSource
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class IdentityAuditExportSourceTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		class_exists( '\\FreeFormCertificate\\Maintenance\\IdentityAuditExportSource' );

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * An auditor double that returns a fixed report and records the options it
	 * was called with.
	 *
	 * @param array<string, mixed> $checks Report checks.
	 * @param array<string, mixed> $seen   Filled with the options passed in.
	 * @return \FreeFormCertificate\Maintenance\MaintenanceToolInterface
	 */
	private function auditor( array $checks, array &$seen ) {
		$tool = Mockery::mock( '\\FreeFormCertificate\\Maintenance\\MaintenanceToolInterface' );
		$tool->shouldReceive( 'run' )->andReturnUsing(
			function ( array $options ) use ( $checks, &$seen ) {
				$seen = $options;
				return array( 'checks' => $checks, 'total' => 1 );
			}
		);
		return $tool;
	}

	/**
	 * Index the rendered rows by their header name, for readable assertions.
	 *
	 * @param IdentityAuditExportSource $source Source.
	 * @return array<int, array<string, string>>
	 */
	private function rows( IdentityAuditExportSource $source ): array {
		$header = $source->header();
		$out    = array();
		foreach ( $source->rows() as $row ) {
			$out[] = array_combine( $header, array_map( 'strval', $row ) );
		}
		return $out;
	}

	/**
	 * The export asks for its OWN cap, not the screen's sample.
	 *
	 * The screen's 50 is what makes the download pointless — it is the number
	 * the operator already has. If this regressed to the default, the CSV would
	 * duplicate the screen and the issue it closes would still be open.
	 */
	public function test_it_asks_the_auditor_for_the_export_cap(): void {
		$seen   = array();
		$source = new IdentityAuditExportSource( $this->auditor( array(), $seen ) );
		$this->rows( $source );

		$this->assertSame(
			IdentityAuditExportSource::EXPORT_LIMIT,
			$seen['limit'] ?? null,
			'The export must raise the per-check cap; the screen sample is what it exists to go beyond.'
		);
		$this->assertGreaterThan( 50, IdentityAuditExportSource::EXPORT_LIMIT );
	}

	/**
	 * `subject` means a hash in one cross-store check and a user id in the
	 * other, because `grouped()` aliases whatever it grouped BY.
	 *
	 * Reading it positionally puts a 64-character hash in the `user_id` column
	 * of a file somebody is about to filter by user id.
	 */
	public function test_subject_lands_in_the_column_its_check_means(): void {
		$seen   = array();
		$hash   = str_repeat( 'a', 64 );
		$source = new IdentityAuditExportSource(
			$this->auditor(
				array(
					'cross_store_shared_identities'   => array(
						'count'     => 1,
						'truncated' => false,
						'rows'      => array( array( 'subject' => $hash, 'user_count' => 2, 'identifier_column' => 'cpf_hash' ) ),
					),
					'cross_store_multiple_identities' => array(
						'count'     => 1,
						'truncated' => false,
						'rows'      => array( array( 'subject' => 77, 'identifier_count' => 3, 'identifier_column' => 'rf_hash' ) ),
					),
				),
				$seen
			)
		);

		$rows = $this->rows( $source );

		$this->assertSame( '', $rows[0]['user_id'], 'A shared-identity row groups by HASH; its subject is not a user id.' );
		$this->assertSame( substr( $hash, 0, IdentityAuditExportSource::HASH_PREFIX_CHARS ), $rows[0]['identifier_hash_prefix'] );
		$this->assertSame( '2', $rows[0]['related_count'] );

		$this->assertSame( '77', $rows[1]['user_id'], 'A multiple-identity row groups by USER; its subject is the user id.' );
		$this->assertSame( '', $rows[1]['identifier_hash_prefix'] );
		$this->assertSame( '3', $rows[1]['related_count'] );
	}

	/**
	 * The hash is a grouping prefix, never the whole value.
	 */
	public function test_the_hash_is_truncated_to_a_grouping_prefix(): void {
		$seen   = array();
		$hash   = str_repeat( 'b', 64 );
		$source = new IdentityAuditExportSource(
			$this->auditor(
				array(
					'shared_identities' => array(
						'count'     => 1,
						'truncated' => false,
						'rows'      => array( array( 'cpf_hash' => $hash, 'user_count' => 2 ) ),
					),
				),
				$seen
			)
		);

		$prefix = $this->rows( $source )[0]['identifier_hash_prefix'];

		$this->assertSame( IdentityAuditExportSource::HASH_PREFIX_CHARS, strlen( $prefix ) );
		$this->assertNotSame( $hash, $prefix, 'The full hash must not reach the file.' );
	}

	/**
	 * `multiple_identities` carries two counts, and a row saying "2" without
	 * saying two of WHAT is not a lead anybody can act on.
	 */
	public function test_the_two_count_check_names_the_column_it_reports(): void {
		$seen   = array();
		$source = new IdentityAuditExportSource(
			$this->auditor(
				array(
					'multiple_identities' => array(
						'count'     => 2,
						'truncated' => false,
						'rows'      => array(
							array( 'user_id' => 5, 'cpf_count' => 3, 'rf_count' => 1 ),
							array( 'user_id' => 6, 'cpf_count' => 1, 'rf_count' => 4 ),
						),
					),
				),
				$seen
			)
		);

		$rows = $this->rows( $source );

		$this->assertSame( array( '3', 'cpf_hash' ), array( $rows[0]['related_count'], $rows[0]['identifier_column'] ) );
		$this->assertSame( array( '4', 'rf_hash' ), array( $rows[1]['related_count'], $rows[1]['identifier_column'] ) );
	}

	/**
	 * A capped list must never read as a complete one.
	 *
	 * This is the export's half of the rule the schema gates state as "never
	 * count as clean what it did not look at": the operator acting on this file
	 * has to know when it is partial.
	 */
	public function test_a_truncated_check_says_so_in_the_file(): void {
		$seen   = array();
		$source = new IdentityAuditExportSource(
			$this->auditor(
				array(
					'unindexed_links' => array(
						'count'     => 1,
						'truncated' => true,
						'rows'      => array( array( 'user_id' => 9, 'identifier_column' => 'cpf_hash' ) ),
					),
				),
				$seen
			)
		);

		$rows = $this->rows( $source );

		$this->assertCount( 2, $rows, 'A truncated check emits its finding plus the truncation note.' );
		$this->assertStringContainsString( 'TRUNCATED', $rows[1]['note'] );
		$this->assertSame( 'unindexed_links', $rows[1]['check'], 'The note has to name which check was cut.' );
	}

	/**
	 * A clean install still produces a file that says something.
	 */
	public function test_an_empty_report_still_says_so(): void {
		$seen   = array();
		$source = new IdentityAuditExportSource( $this->auditor( array(), $seen ) );

		$rows = $this->rows( $source );

		$this->assertCount( 1, $rows );
		$this->assertStringContainsString( 'No link problems found', $rows[0]['note'] );
	}

	/**
	 * No capability, no file — and the source runs its own gate rather than
	 * trusting whoever constructed it.
	 */
	public function test_authorize_refuses_without_the_danger_zone_capability(): void {
		Mockery::mock( 'alias:FreeFormCertificate\Core\Capabilities' )
			->shouldReceive( 'current_user_can_admin_or' )->andReturn( false );

		Functions\when( 'wp_die' )->alias(
			function () {
				throw new \RuntimeException( 'denied' );
			}
		);

		$this->expectException( \RuntimeException::class );
		( new IdentityAuditExportSource() )->authorize();
	}

	/**
	 * The capability is not the whole gate: every operator who can run the
	 * audit holds it, so the nonce is what ties the download to this request.
	 */
	public function test_authorize_refuses_without_the_nonce(): void {
		Mockery::mock( 'alias:FreeFormCertificate\Core\Capabilities' )
			->shouldReceive( 'current_user_can_admin_or' )->andReturn( true );
		Mockery::mock( 'alias:FreeFormCertificate\Core\RequestInput' )
			->shouldReceive( 'get_get_string' )->andReturn( 'wrong' );

		Functions\when( 'wp_verify_nonce' )->justReturn( false );
		Functions\when( 'wp_die' )->alias(
			function () {
				throw new \RuntimeException( 'denied' );
			}
		);

		$this->expectException( \RuntimeException::class );
		( new IdentityAuditExportSource() )->authorize();
	}

	/**
	 * The filename carries a timestamp, so two exports on one day do not
	 * overwrite each other in the operator's downloads folder.
	 */
	public function test_filename_is_dated(): void {
		$this->assertMatchesRegularExpression(
			'/^ffc-identity-audit-\d{4}-\d{2}-\d{2}-\d{6}\.csv$/',
			( new IdentityAuditExportSource() )->filename()
		);
	}
}
