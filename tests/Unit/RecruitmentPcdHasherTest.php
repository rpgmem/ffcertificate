<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Recruitment\RecruitmentPcdHasher;

/**
 * Tests for RecruitmentPcdHasher — verifies the §3.4 PCD-hash formula
 * `HMAC-SHA256(secret, ("1"|"0") || candidate_id)` and the round-trip
 * verification helper.
 *
 * @covers \FreeFormCertificate\Recruitment\RecruitmentPcdHasher
 */
class RecruitmentPcdHasherTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// Stable salt across the test so `compute()` is deterministic.
		Functions\when( 'wp_salt' )->justReturn( 'test-auth-salt' );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_pcd_and_non_pcd_hashes_differ_for_same_candidate(): void {
		$pcd     = RecruitmentPcdHasher::compute( 42, true );
		$not_pcd = RecruitmentPcdHasher::compute( 42, false );

		$this->assertNotSame( $pcd, $not_pcd, 'Different PCD value must produce different hash' );
	}

	public function test_compute_is_deterministic_for_same_inputs(): void {
		$first  = RecruitmentPcdHasher::compute( 7, true );
		$second = RecruitmentPcdHasher::compute( 7, true );

		$this->assertSame( $first, $second );
		$this->assertSame( 64, strlen( $first ), 'SHA-256 hex digest is 64 chars' );
	}

	public function test_compute_differs_across_candidate_ids(): void {
		$id_1 = RecruitmentPcdHasher::compute( 1, true );
		$id_2 = RecruitmentPcdHasher::compute( 2, true );

		$this->assertNotSame( $id_1, $id_2, 'candidate_id is part of the HMAC input' );
	}

	public function test_verify_returns_true_for_pcd_hash(): void {
		$hash = RecruitmentPcdHasher::compute( 5, true );

		$this->assertTrue( RecruitmentPcdHasher::verify( $hash, 5 ) );
	}

	public function test_verify_returns_false_for_non_pcd_hash(): void {
		$hash = RecruitmentPcdHasher::compute( 5, false );

		$this->assertFalse( RecruitmentPcdHasher::verify( $hash, 5 ) );
	}

	public function test_verify_returns_null_when_hash_does_not_match_either_domain(): void {
		// Tampered hash (or wrong candidate_id) — neither domain matches.
		$tampered = str_repeat( 'a', 64 );

		$this->assertNull( RecruitmentPcdHasher::verify( $tampered, 5 ) );
	}

	public function test_verify_returns_null_for_empty_hash(): void {
		$this->assertNull( RecruitmentPcdHasher::verify( '', 5 ) );
	}

	public function test_verify_returns_null_when_candidate_id_does_not_match_stored_hash(): void {
		// Hash produced for candidate 5; verifying against candidate 6 should
		// fail both domains because the candidate_id is part of the HMAC input.
		$hash = RecruitmentPcdHasher::compute( 5, true );

		$this->assertNull( RecruitmentPcdHasher::verify( $hash, 6 ) );
	}

	// ------------------------------------------------------------------
	// Per-classification hash (#2245)
	// ------------------------------------------------------------------

	/**
	 * A classification row the reader takes.
	 */
	private static function cls( int $notice, int $candidate, int $adjutancy, ?string $hash ): object {
		return (object) array(
			'notice_id'    => $notice,
			'candidate_id' => $candidate,
			'adjutancy_id' => $adjutancy,
			'pcd_hash'     => $hash,
		);
	}

	public function test_the_row_hash_names_the_notice_candidate_and_adjutancy(): void {
		$base = RecruitmentPcdHasher::compute_for_row( 1, 2, 3, true );

		$this->assertSame( 64, strlen( $base ) );
		$this->assertSame( $base, RecruitmentPcdHasher::compute_for_row( 1, 2, 3, true ), 'Deterministic, so a snapshot can copy it.' );
		$this->assertNotSame( $base, RecruitmentPcdHasher::compute_for_row( 1, 2, 3, false ) );
		$this->assertNotSame( $base, RecruitmentPcdHasher::compute_for_row( 9, 2, 3, true ), 'Another notice' );
		$this->assertNotSame( $base, RecruitmentPcdHasher::compute_for_row( 1, 9, 3, true ), 'Another candidate' );
		$this->assertNotSame( $base, RecruitmentPcdHasher::compute_for_row( 1, 2, 9, true ), 'Another adjutancy' );
	}

	public function test_the_row_hash_does_not_depend_on_the_wordpress_auth_salt(): void {
		$before = RecruitmentPcdHasher::compute_for_row( 1, 2, 3, true );
		Functions\when( 'wp_salt' )->justReturn( 'rotated-auth-salt' );

		$this->assertSame( $before, RecruitmentPcdHasher::compute_for_row( 1, 2, 3, true ), 'Rotating wp_salt(auth) no longer turns PCD into general.' );
	}

	public function test_read_row_recognises_both_flags_under_the_active_salt(): void {
		$this->assertSame( array( 'pcd' => true, 'legacy' => false ), RecruitmentPcdHasher::read_row( self::cls( 1, 2, 3, RecruitmentPcdHasher::compute_for_row( 1, 2, 3, true ) ) ) );
		$this->assertSame( array( 'pcd' => false, 'legacy' => false ), RecruitmentPcdHasher::read_row( self::cls( 1, 2, 3, RecruitmentPcdHasher::compute_for_row( 1, 2, 3, false ) ) ) );
		$this->assertTrue( RecruitmentPcdHasher::row_is_pcd( self::cls( 1, 2, 3, RecruitmentPcdHasher::compute_for_row( 1, 2, 3, true ) ) ) );
	}

	public function test_read_row_reads_missing_moved_or_tampered_hashes_as_unknown(): void {
		$hash = RecruitmentPcdHasher::compute_for_row( 1, 2, 3, true );

		foreach ( array( null, '', str_repeat( 'a', 64 ) ) as $stored ) {
			$this->assertNull( RecruitmentPcdHasher::read_row( self::cls( 1, 2, 3, $stored ) )['pcd'] );
		}
		$this->assertNull( RecruitmentPcdHasher::read_row( self::cls( 1, 7, 3, $hash ) )['pcd'], 'A hash copied onto another candidacy reads as nothing.' );
		$this->assertFalse( RecruitmentPcdHasher::row_is_pcd( self::cls( 1, 7, 3, $hash ) ), 'And therefore as general on every screen.' );
		$this->assertFalse( RecruitmentPcdHasher::row_is_pcd( (object) array() ) );
	}

	/**
	 * A hash written before FFC_HASH_SALT was defined still reads, and is
	 * flagged for the key rotation to rewrite.
	 *
	 * Own process: it defines FFC_HASH_SALT, which Encryption reads with no
	 * other seam and every other test here needs absent.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_read_row_falls_back_to_the_wordpress_derived_salt(): void {
		$legacy_pcd     = RecruitmentPcdHasher::compute_for_row( 1, 2, 3, true );
		$legacy_general = RecruitmentPcdHasher::compute_for_row( 1, 2, 3, false );
		define( 'FFC_HASH_SALT', str_repeat( 'S', 64 ) );

		$this->assertNotSame( $legacy_pcd, RecruitmentPcdHasher::compute_for_row( 1, 2, 3, true ), 'The active salt changed.' );
		$this->assertSame( array( 'pcd' => true, 'legacy' => true ), RecruitmentPcdHasher::read_row( self::cls( 1, 2, 3, $legacy_pcd ) ) );
		$this->assertSame( array( 'pcd' => false, 'legacy' => true ), RecruitmentPcdHasher::read_row( self::cls( 1, 2, 3, $legacy_general ) ) );
		$this->assertSame(
			array( 'pcd' => true, 'legacy' => false ),
			RecruitmentPcdHasher::read_row( self::cls( 1, 2, 3, RecruitmentPcdHasher::compute_for_row( 1, 2, 3, true ) ) ),
			'A hash under the active salt is not legacy.'
		);
	}
}
