<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Core\Encryption;
use FreeFormCertificate\Recruitment\RecruitmentClassificationFilterManager;
use FreeFormCertificate\Recruitment\RecruitmentPcdHasher;
use FreeFormCertificate\Tests\Support\Identifiers;

/**
 * Tests for the recruitment classification filter manager — read_filters
 * (GET-param normalization + CPF/RF candidate-id resolution) and
 * apply_filters (AND-composed row filtering).
 *
 * The candidate reader, the sanitizer, the encryption hash and the PCD hasher
 * are the real classes: candidates live in a `$wpdb` double keyed by id and by
 * CPF hash, the hash salt comes from the bootstrap's keys, and the PCD hasher
 * reads a stubbed `wp_salt()`.
 *
 * @covers \FreeFormCertificate\Recruitment\RecruitmentClassificationFilterManager
 */
class RecruitmentClassificationFilterManagerTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/**
	 * A CPF in its `ddd.ddd.ddd-dd` display form, built from the standard fixture.
	 *
	 * @param string $cpf Eleven digits.
	 * @return string
	 */
	private static function mask_cpf( string $cpf ): string {
		return substr( $cpf, 0, 3 ) . '.' . substr( $cpf, 3, 3 ) . '.' . substr( $cpf, 6, 3 ) . '-' . substr( $cpf, 9, 2 );
	}

	/**
	 * Candidate rows by id.
	 *
	 * @var array<int, object>
	 */
	private array $candidates = array();

	/**
	 * Candidate rows by CPF hash.
	 *
	 * @var array<string, object>
	 */
	private array $by_cpf_hash = array();

	/**
	 * CPF hashes the reader was asked for, in order.
	 *
	 * @var list<string>
	 */
	private array $cpf_lookups = array();

	/** @var mixed The `$wpdb` global an earlier test left, restored afterwards. */
	private $wpdb_backup;

	/** @var bool Whether `$wpdb` was set before this test. */
	private bool $had_wpdb = false;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->had_wpdb    = array_key_exists( 'wpdb', $GLOBALS );
		$this->wpdb_backup = $GLOBALS['wpdb'] ?? null;

		$this->candidates  = array();
		$this->by_cpf_hash = array();
		$this->cpf_lookups = array();

		Functions\when( '__' )->returnArg();
		Functions\when( 'absint' )->alias( fn( $v ) => abs( (int) $v ) );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'sanitize_key' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'wp_salt' )->justReturn( 'test-auth-salt' );

		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			static fn( $sql, ...$args ) => array(
				'sql'  => $sql,
				'args' => $args,
			)
		);
		$wpdb->shouldReceive( 'get_row' )->andReturnUsing(
			function ( $query ) {
				$this->assertSame( 'wp_ffc_recruitment_candidate', $query['args'][0] );
				$value = $query['args'][1];
				if ( str_contains( $query['sql'], 'cpf_hash = %s' ) ) {
					$this->cpf_lookups[] = (string) $value;
					return $this->by_cpf_hash[ $value ] ?? null;
				}
				if ( str_contains( $query['sql'], 'WHERE id = %d' ) ) {
					return $this->candidates[ (int) $value ] ?? null;
				}
				return null;
			}
		);
		$GLOBALS['wpdb'] = $wpdb;
	}

	protected function tearDown(): void {
		unset( $_GET['ffc_cls_adj'], $_GET['ffc_cls_q'], $_GET['ffc_cls_cpf'], $_GET['ffc_cls_rf'], $_GET['ffc_cls_sub'] );
		if ( $this->had_wpdb ) {
			$GLOBALS['wpdb'] = $this->wpdb_backup;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		Monkey\tearDown();
		parent::tearDown();
	}

	// ------------------------------------------------------------------
	// read_filters() — defaults
	// ------------------------------------------------------------------

	public function test_read_filters_returns_zeros_when_no_get_params(): void {
		$filters = RecruitmentClassificationFilterManager::read_filters( 42 );

		$this->assertSame( 42, $filters['notice_id'] );
		$this->assertSame( 0, $filters['adjutancy_id'] );
		$this->assertSame( '', $filters['query'] );
		$this->assertSame( 0, $filters['cpf_candidate_id'] );
		$this->assertSame( 0, $filters['rf_candidate_id'] );
		$this->assertSame( '', $filters['subscription'] );
	}

	// ------------------------------------------------------------------
	// read_filters() — CPF resolution
	// ------------------------------------------------------------------

	public function test_read_filters_resolves_cpf_to_candidate_id_when_match_found(): void {
		$_GET['ffc_cls_cpf'] = self::mask_cpf( Identifiers::CPF_A );
		// The lookup is keyed on the hash of the digits only.
		$this->by_cpf_hash[ (string) Encryption::hash( Identifiers::CPF_A ) ] = (object) array( 'id' => 7 );

		$filters = RecruitmentClassificationFilterManager::read_filters( 1 );

		$this->assertSame( 7, $filters['cpf_candidate_id'] );
	}

	public function test_read_filters_marks_cpf_candidate_minus_one_when_no_match(): void {
		$_GET['ffc_cls_cpf'] = Identifiers::CPF_A;

		$filters = RecruitmentClassificationFilterManager::read_filters( 1 );

		$this->assertSame( array( (string) Encryption::hash( Identifiers::CPF_A ) ), $this->cpf_lookups );
		$this->assertSame( -1, $filters['cpf_candidate_id'] );
	}

	public function test_read_filters_normalizes_subscription_to_allowed_set(): void {
		$_GET['ffc_cls_sub'] = 'bogus';

		$filters = RecruitmentClassificationFilterManager::read_filters( 1 );

		$this->assertSame( '', $filters['subscription'] );
	}

	public function test_read_filters_keeps_pcd_subscription(): void {
		$_GET['ffc_cls_sub'] = 'pcd';

		$filters = RecruitmentClassificationFilterManager::read_filters( 1 );

		$this->assertSame( 'pcd', $filters['subscription'] );
	}

	// ------------------------------------------------------------------
	// apply_filters() — early return on unresolved CPF/RF
	// ------------------------------------------------------------------

	public function test_apply_filters_returns_empty_when_cpf_unresolved(): void {
		$rows = array(
			(object) array( 'adjutancy_id' => 1, 'candidate_id' => 1 ),
			(object) array( 'adjutancy_id' => 1, 'candidate_id' => 2 ),
		);
		$filters = array( 'cpf_candidate_id' => -1 );

		$out = RecruitmentClassificationFilterManager::apply_filters( $rows, $filters );

		$this->assertSame( array(), $out );
	}

	public function test_apply_filters_returns_empty_when_rf_unresolved(): void {
		$rows    = array( (object) array( 'adjutancy_id' => 1, 'candidate_id' => 1 ) );
		$filters = array( 'rf_candidate_id' => -1 );

		$out = RecruitmentClassificationFilterManager::apply_filters( $rows, $filters );

		$this->assertSame( array(), $out );
	}

	// ------------------------------------------------------------------
	// apply_filters() — adjutancy filter
	// ------------------------------------------------------------------

	public function test_apply_filters_keeps_only_rows_with_matching_adjutancy_id(): void {
		$rows = array(
			(object) array( 'adjutancy_id' => 1, 'candidate_id' => 10 ),
			(object) array( 'adjutancy_id' => 2, 'candidate_id' => 20 ),
			(object) array( 'adjutancy_id' => 1, 'candidate_id' => 30 ),
		);
		$filters = array( 'adjutancy_id' => 1 );

		$out = RecruitmentClassificationFilterManager::apply_filters( $rows, $filters );

		$this->assertCount( 2, $out );
		$this->assertSame( 10, $out[0]->candidate_id );
		$this->assertSame( 30, $out[1]->candidate_id );
	}

	// ------------------------------------------------------------------
	// apply_filters() — CPF candidate match
	// ------------------------------------------------------------------

	public function test_apply_filters_keeps_only_rows_for_resolved_cpf_candidate(): void {
		$rows = array(
			(object) array( 'adjutancy_id' => 1, 'candidate_id' => 7 ),
			(object) array( 'adjutancy_id' => 1, 'candidate_id' => 99 ),
		);
		$filters = array( 'cpf_candidate_id' => 7 );

		$out = RecruitmentClassificationFilterManager::apply_filters( $rows, $filters );

		$this->assertCount( 1, $out );
		$this->assertSame( 7, $out[0]->candidate_id );
	}

	// ------------------------------------------------------------------
	// apply_filters() — name substring search
	// ------------------------------------------------------------------

	public function test_apply_filters_name_query_is_case_insensitive_substring(): void {
		$rows = array(
			(object) array( 'adjutancy_id' => 0, 'candidate_id' => 1 ),
			(object) array( 'adjutancy_id' => 0, 'candidate_id' => 2 ),
		);
		$this->candidates[1] = (object) array( 'name' => 'John Doe' );
		$this->candidates[2] = (object) array( 'name' => 'Jane Smith' );

		$filters = array( 'query' => 'doe' );

		$out = RecruitmentClassificationFilterManager::apply_filters( $rows, $filters );

		$this->assertCount( 1, $out );
		$this->assertSame( 1, $out[0]->candidate_id );
	}

	public function test_apply_filters_drops_rows_when_candidate_lookup_fails_during_name_search(): void {
		$rows = array( (object) array( 'adjutancy_id' => 0, 'candidate_id' => 99 ) );
		// No candidate 99 in the table.

		$out = RecruitmentClassificationFilterManager::apply_filters( $rows, array( 'query' => 'anything' ) );

		$this->assertSame( array(), $out );
	}

	// ------------------------------------------------------------------
	// apply_filters() — subscription filter
	// ------------------------------------------------------------------

	public function test_apply_filters_subscription_pcd_keeps_only_pcd_candidates(): void {
		$rows = array(
			(object) array( 'adjutancy_id' => 0, 'candidate_id' => 1 ),
			(object) array( 'adjutancy_id' => 0, 'candidate_id' => 2 ),
		);
		$this->candidates[1] = (object) array(
			'name'     => 'A',
			'pcd_hash' => RecruitmentPcdHasher::compute( 1, true ),
		);
		$this->candidates[2] = (object) array(
			'name'     => 'B',
			'pcd_hash' => RecruitmentPcdHasher::compute( 2, false ),
		);

		$filters = array( 'subscription' => 'pcd' );

		$out = RecruitmentClassificationFilterManager::apply_filters( $rows, $filters );

		$this->assertCount( 1, $out );
		$this->assertSame( 1, $out[0]->candidate_id );
	}

	public function test_apply_filters_passes_through_when_no_filters_active(): void {
		$rows = array(
			(object) array( 'adjutancy_id' => 1, 'candidate_id' => 1 ),
			(object) array( 'adjutancy_id' => 2, 'candidate_id' => 2 ),
		);

		$out = RecruitmentClassificationFilterManager::apply_filters( $rows, array() );

		$this->assertSame( $rows, $out );
	}
}
