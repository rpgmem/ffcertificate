<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Recruitment\CandidatePersister;

/**
 * Tests for CandidatePersister.
 *
 * The class is a static persistence cluster that fans out to a wide set of
 * static collaborators (Encryption, the candidate Reader/Writer, the PCD
 * hasher, the adjutancy reader/repository, UserCreator, the activity logger).
 * Each collaborator is replaced with a Mockery `alias:` mock, which requires
 * process isolation so the alias does not leak into other tests.
 *
 * Coverage targets every public path:
 *   - upsert_candidate(): new-candidate insert (full PII), insert with empty
 *     fields, insert DB failure, existing-candidate update (matched by cpf,
 *     then by rf), and the wp_user promotion branch.
 *   - build_adjutancy_map(): empty notice and a populated slug→id map.
 *
 * @covers \FreeFormCertificate\Recruitment\CandidatePersister
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class CandidatePersisterTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var Mockery\MockInterface */
	private $wpdb;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		class_exists( '\\FreeFormCertificate\\Recruitment\\CandidatePersister' );

		global $wpdb;
		$wpdb         = Mockery::mock( 'wpdb' )->makePartial();
		$wpdb->prefix = 'wp_';
		$this->wpdb   = $wpdb;

		Functions\when( 'current_time' )->justReturn( '2026-06-28 10:00:00' );
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'wp_cache_delete' )->justReturn( true );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Stub the CsvParser::parse_pcd_flag the class calls. Returns false for
	 * everything except 'true'/'1'/'sim'/'yes', so we pass a literal flag in.
	 */
	private function stubPcdParser( bool $returns ): void {
		$parser = Mockery::mock( 'alias:FreeFormCertificate\\Recruitment\\CsvParser' );
		$parser->shouldReceive( 'parse_pcd_flag' )->andReturn( $returns );
	}

	/** Stub Encryption with deterministic encrypt/hash transforms. */
	private function stubEncryption(): void {
		$enc = Mockery::mock( 'alias:FreeFormCertificate\\Core\\Encryption' );
		$enc->shouldReceive( 'hash' )->andReturnUsing(
			static fn( $v ) => 'hash:' . $v
		);
		$enc->shouldReceive( 'encrypt' )->andReturnUsing(
			static fn( $v ) => 'enc:' . $v
		);
	}

	/** Stub the PCD hasher + table name (used by refresh_pcd_hash). */
	private function stubPcdHasherAndTable(): void {
		$hasher = Mockery::mock( 'alias:FreeFormCertificate\\Recruitment\\RecruitmentPcdHasher' );
		$hasher->shouldReceive( 'compute' )->andReturn( 'pcd-hash' );
	}

	/**
	 * Define CapabilityManager with the CONTEXT_RECRUITMENT const the persister
	 * reads. An `alias:` mock has no constants, so eval a minimal real class.
	 */
	private function stubCapabilityManager(): void {
		if ( ! class_exists( 'FreeFormCertificate\\UserDashboard\\CapabilityManager', false ) ) {
			eval(
				'namespace FreeFormCertificate\\UserDashboard;'
				. ' class CapabilityManager { const CONTEXT_RECRUITMENT = "recruitment"; }'
			);
		}
	}

	private function row( array $overrides = array() ): array {
		return array_merge(
			array(
				'cpf'   => '12345678909',
				'rf'    => '1234567',
				'email' => 'Person@Example.COM',
				'name'  => '  Maria  ',
				'phone' => '11999998888',
				'pcd'   => 'sim',
			),
			$overrides
		);
	}

	public function test_upsert_inserts_new_candidate_with_full_pii(): void {
		$this->stubPcdParser( true );
		$this->stubEncryption();
		$this->stubPcdHasherAndTable();

		$reader = Mockery::mock( 'alias:FreeFormCertificate\\Recruitment\\RecruitmentCandidateReader' );
		$reader->shouldReceive( 'get_by_cpf_hash' )->andReturn( null );
		$reader->shouldReceive( 'get_by_rf_hash' )->andReturn( null );
		$reader->shouldReceive( 'get_table_name' )->andReturn( 'wp_ffc_recruitment_candidates' );

		$captured = null;
		$writer   = Mockery::mock( 'alias:FreeFormCertificate\\Recruitment\\RecruitmentCandidateWriter' );
		$writer->shouldReceive( 'create' )->once()->andReturnUsing(
			function ( $payload ) use ( &$captured ) {
				$captured = $payload;
				return 42;
			}
		);
		$writer->shouldReceive( 'set_user_id' )->andReturn( true );

		// No promotion: UserCreator class not loaded → maybe_promote returns early
		// after the hash/email check passes. To exercise the promotion no-op,
		// stub UserCreator returning 0.
		$uc = Mockery::mock( 'alias:FreeFormCertificate\\UserDashboard\\UserCreator' );
		$uc->shouldReceive( 'get_or_create_user_dual' )->andReturn( 0 );
		$this->stubCapabilityManager();

		$this->wpdb->shouldReceive( 'update' )->andReturn( 1 );

		$id = CandidatePersister::upsert_candidate( $this->row() );

		$this->assertSame( 42, $id );
		$this->assertSame( 'Maria', $captured['name'] );
		$this->assertSame( 'pending', $captured['pcd_hash'] );
		$this->assertSame( 'enc:12345678909', $captured['cpf_encrypted'] );
		$this->assertSame( 'hash:12345678909', $captured['cpf_hash'] );
		$this->assertSame( 'enc:1234567', $captured['rf_encrypted'] );
		// Email is lowercased before encryption/hash.
		$this->assertSame( 'enc:person@example.com', $captured['email_encrypted'] );
		$this->assertSame( '11999998888', $captured['phone'] );
	}

	public function test_upsert_insert_omits_empty_optional_fields(): void {
		$this->stubPcdParser( false );
		$this->stubEncryption();
		$this->stubPcdHasherAndTable();

		$reader = Mockery::mock( 'alias:FreeFormCertificate\\Recruitment\\RecruitmentCandidateReader' );
		$reader->shouldReceive( 'get_by_cpf_hash' )->andReturn( null );
		$reader->shouldReceive( 'get_by_rf_hash' )->andReturn( null );
		$reader->shouldReceive( 'get_table_name' )->andReturn( 'wp_ffc_recruitment_candidates' );

		$captured = null;
		$writer   = Mockery::mock( 'alias:FreeFormCertificate\\Recruitment\\RecruitmentCandidateWriter' );
		$writer->shouldReceive( 'create' )->once()->andReturnUsing(
			function ( $payload ) use ( &$captured ) {
				$captured = $payload;
				return 7;
			}
		);

		$this->wpdb->shouldReceive( 'update' )->andReturn( 1 );

		// All PII empty: no cpf/rf/email hashes, no promotion attempt.
		$row = $this->row(
			array(
				'cpf'   => '',
				'rf'    => '',
				'email' => '',
				'phone' => '',
				'name'  => 'OnlyName',
			)
		);

		$id = CandidatePersister::upsert_candidate( $row );

		$this->assertSame( 7, $id );
		$this->assertSame( 'OnlyName', $captured['name'] );
		$this->assertArrayNotHasKey( 'cpf_encrypted', $captured );
		$this->assertArrayNotHasKey( 'rf_encrypted', $captured );
		$this->assertArrayNotHasKey( 'email_encrypted', $captured );
		$this->assertArrayNotHasKey( 'phone', $captured );
	}

	public function test_upsert_returns_false_on_insert_failure(): void {
		$this->stubPcdParser( false );
		$this->stubEncryption();

		$reader = Mockery::mock( 'alias:FreeFormCertificate\\Recruitment\\RecruitmentCandidateReader' );
		$reader->shouldReceive( 'get_by_cpf_hash' )->andReturn( null );
		$reader->shouldReceive( 'get_by_rf_hash' )->andReturn( null );

		$writer = Mockery::mock( 'alias:FreeFormCertificate\\Recruitment\\RecruitmentCandidateWriter' );
		$writer->shouldReceive( 'create' )->once()->andReturn( false );

		$result = CandidatePersister::upsert_candidate( $this->row() );

		$this->assertFalse( $result );
	}

	public function test_upsert_updates_existing_candidate_matched_by_cpf(): void {
		$this->stubPcdParser( true );
		$this->stubEncryption();
		$this->stubPcdHasherAndTable();

		$existing = (object) array( 'id' => '99' );

		$reader = Mockery::mock( 'alias:FreeFormCertificate\\Recruitment\\RecruitmentCandidateReader' );
		$reader->shouldReceive( 'get_by_cpf_hash' )->once()->andReturn( $existing );
		// rf lookup must NOT happen when cpf already matched.
		$reader->shouldReceive( 'get_by_rf_hash' )->never();
		$reader->shouldReceive( 'get_table_name' )->andReturn( 'wp_ffc_recruitment_candidates' );

		$capturedUpdate = null;
		$writer         = Mockery::mock( 'alias:FreeFormCertificate\\Recruitment\\RecruitmentCandidateWriter' );
		$writer->shouldReceive( 'create' )->never();
		$writer->shouldReceive( 'update' )->once()->andReturnUsing(
			function ( $id, $data ) use ( &$capturedUpdate ) {
				$capturedUpdate = array( $id, $data );
				return true;
			}
		);
		$writer->shouldReceive( 'set_user_id' )->andReturn( true );

		$uc = Mockery::mock( 'alias:FreeFormCertificate\\UserDashboard\\UserCreator' );
		$uc->shouldReceive( 'get_or_create_user_dual' )->andReturn( 0 );
		$this->stubCapabilityManager();

		$this->wpdb->shouldReceive( 'update' )->andReturn( 1 );

		$id = CandidatePersister::upsert_candidate( $this->row() );

		$this->assertSame( 99, $id );
		$this->assertSame( 99, $capturedUpdate[0] );
		// array_filter drops nulls; full row keeps all keys.
		$this->assertArrayHasKey( 'cpf_hash', $capturedUpdate[1] );
		$this->assertArrayHasKey( 'email_hash', $capturedUpdate[1] );
		$this->assertSame( 'Maria', $capturedUpdate[1]['name'] );
	}

	public function test_upsert_falls_back_to_rf_lookup_when_cpf_misses(): void {
		$this->stubPcdParser( false );
		$this->stubEncryption();
		$this->stubPcdHasherAndTable();

		$existing = (object) array( 'id' => 5 );

		$reader = Mockery::mock( 'alias:FreeFormCertificate\\Recruitment\\RecruitmentCandidateReader' );
		$reader->shouldReceive( 'get_by_cpf_hash' )->once()->andReturn( null );
		$reader->shouldReceive( 'get_by_rf_hash' )->once()->andReturn( $existing );
		$reader->shouldReceive( 'get_table_name' )->andReturn( 'wp_ffc_recruitment_candidates' );

		$writer = Mockery::mock( 'alias:FreeFormCertificate\\Recruitment\\RecruitmentCandidateWriter' );
		$writer->shouldReceive( 'update' )->once()->andReturn( true );
		$writer->shouldReceive( 'set_user_id' )->andReturn( true );

		$uc = Mockery::mock( 'alias:FreeFormCertificate\\UserDashboard\\UserCreator' );
		$uc->shouldReceive( 'get_or_create_user_dual' )->andReturn( 0 );
		$this->stubCapabilityManager();

		$this->wpdb->shouldReceive( 'update' )->andReturn( 1 );

		$id = CandidatePersister::upsert_candidate( $this->row() );

		$this->assertSame( 5, $id );
	}

	public function test_upsert_promotes_candidate_when_user_resolved(): void {
		$this->stubPcdParser( false );
		$this->stubEncryption();
		$this->stubPcdHasherAndTable();

		$reader = Mockery::mock( 'alias:FreeFormCertificate\\Recruitment\\RecruitmentCandidateReader' );
		$reader->shouldReceive( 'get_by_cpf_hash' )->andReturn( null );
		$reader->shouldReceive( 'get_by_rf_hash' )->andReturn( null );
		$reader->shouldReceive( 'get_table_name' )->andReturn( 'wp_ffc_recruitment_candidates' );

		$writer = Mockery::mock( 'alias:FreeFormCertificate\\Recruitment\\RecruitmentCandidateWriter' );
		$writer->shouldReceive( 'create' )->andReturn( 314 );
		$writer->shouldReceive( 'set_user_id' )->once()->with( 314, 808 )->andReturn( true );

		$uc = Mockery::mock( 'alias:FreeFormCertificate\\UserDashboard\\UserCreator' );
		$uc->shouldReceive( 'get_or_create_user_dual' )->once()->andReturn( 808 );
		$this->stubCapabilityManager();

		$logger = Mockery::mock( 'alias:FreeFormCertificate\\Recruitment\\RecruitmentActivityLogger' );
		$logger->shouldReceive( 'candidate_promoted' )->once()->with( 314, 808 );

		$this->wpdb->shouldReceive( 'update' )->andReturn( 1 );

		$id = CandidatePersister::upsert_candidate( $this->row() );

		$this->assertSame( 314, $id );
	}

	public function test_build_adjutancy_map_returns_empty_when_no_ids(): void {
		$repo = Mockery::mock( 'alias:FreeFormCertificate\\Recruitment\\RecruitmentNoticeAdjutancyRepository' );
		$repo->shouldReceive( 'get_adjutancy_ids_for_notice' )->with( 11 )->andReturn( array() );

		$this->assertSame( array(), CandidatePersister::build_adjutancy_map( 11 ) );
	}

	public function test_build_adjutancy_map_builds_slug_to_id_map(): void {
		$repo = Mockery::mock( 'alias:FreeFormCertificate\\Recruitment\\RecruitmentNoticeAdjutancyRepository' );
		$repo->shouldReceive( 'get_adjutancy_ids_for_notice' )->with( 11 )->andReturn( array( 1, 2, 3 ) );

		$adj = Mockery::mock( 'alias:FreeFormCertificate\\Recruitment\\RecruitmentAdjutancyReader' );
		$adj->shouldReceive( 'get_by_id' )->with( 1 )->andReturn( (object) array( 'slug' => 'norte' ) );
		// id 2 resolves to null → skipped.
		$adj->shouldReceive( 'get_by_id' )->with( 2 )->andReturn( null );
		$adj->shouldReceive( 'get_by_id' )->with( 3 )->andReturn( (object) array( 'slug' => 'sul' ) );

		$map = CandidatePersister::build_adjutancy_map( 11 );

		$this->assertSame(
			array(
				'norte' => 1,
				'sul'   => 3,
			),
			$map
		);
	}
}
