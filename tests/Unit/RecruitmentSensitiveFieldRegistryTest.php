<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Core\SensitiveFieldRegistry;

/**
 * Tests for the recruitment-specific extension of SensitiveFieldRegistry.
 *
 * Sprint 2 adds CONTEXT_RECRUITMENT_CANDIDATE — verifies the new context is
 * registered with the expected three fields (cpf / rf / email), each pointing
 * at the matching `*_encrypted` + `*_hash` columns on the candidate table.
 *
 * @covers \FreeFormCertificate\Core\SensitiveFieldRegistry
 */
class RecruitmentSensitiveFieldRegistryTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_recruitment_candidate_context_constant_exists(): void {
		$this->assertSame( 'recruitment_candidate', SensitiveFieldRegistry::CONTEXT_RECRUITMENT_CANDIDATE );
	}

	public function test_fields_for_recruitment_candidate_returns_cpf_rf_email(): void {
		$fields = SensitiveFieldRegistry::fields_for( SensitiveFieldRegistry::CONTEXT_RECRUITMENT_CANDIDATE );

		$this->assertArrayHasKey( 'cpf', $fields );
		$this->assertArrayHasKey( 'rf', $fields );
		$this->assertArrayHasKey( 'email', $fields );
		$this->assertCount( 3, $fields, 'Recruitment candidate context exposes exactly 3 sensitive fields' );
	}

	public function test_recruitment_candidate_fields_map_to_expected_columns(): void {
		$fields = SensitiveFieldRegistry::fields_for( SensitiveFieldRegistry::CONTEXT_RECRUITMENT_CANDIDATE );

		$this->assertSame( 'cpf_encrypted', $fields['cpf']['encrypted_column'] );
		$this->assertSame( 'cpf_hash', $fields['cpf']['hash_column'] );

		$this->assertSame( 'rf_encrypted', $fields['rf']['encrypted_column'] );
		$this->assertSame( 'rf_hash', $fields['rf']['hash_column'] );

		$this->assertSame( 'email_encrypted', $fields['email']['encrypted_column'] );
		$this->assertSame( 'email_hash', $fields['email']['hash_column'] );
	}

	public function test_has_returns_true_for_each_recruitment_field(): void {
		$context = SensitiveFieldRegistry::CONTEXT_RECRUITMENT_CANDIDATE;

		$this->assertTrue( SensitiveFieldRegistry::has( $context, 'cpf' ) );
		$this->assertTrue( SensitiveFieldRegistry::has( $context, 'rf' ) );
		$this->assertTrue( SensitiveFieldRegistry::has( $context, 'email' ) );
	}

	public function test_has_returns_false_for_unregistered_keys(): void {
		$context = SensitiveFieldRegistry::CONTEXT_RECRUITMENT_CANDIDATE;

		// `phone` and `notes` are stored plain on the candidate row (low
		// sensitivity per §3.4 of the implementation plan).
		$this->assertFalse( SensitiveFieldRegistry::has( $context, 'phone' ) );
		$this->assertFalse( SensitiveFieldRegistry::has( $context, 'notes' ) );
		$this->assertFalse( SensitiveFieldRegistry::has( $context, 'name' ) );
		$this->assertFalse( SensitiveFieldRegistry::has( $context, 'pcd' ) );
	}

	public function test_plaintext_keys_for_recruitment_returns_three_keys(): void {
		$keys = SensitiveFieldRegistry::plaintext_keys( SensitiveFieldRegistry::CONTEXT_RECRUITMENT_CANDIDATE );

		// Order doesn't matter for plaintext-key removal callers.
		sort( $keys );

		$this->assertSame( array( 'cpf', 'email', 'rf' ), $keys );
	}
}
