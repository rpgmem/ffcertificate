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
}
