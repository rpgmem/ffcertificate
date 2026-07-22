<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Core\PiiAccessPolicy;

/**
 * Tests for the #739 §3.3 generic PII access-tier resolver.
 *
 * @covers \FreeFormCertificate\Core\PiiAccessPolicy
 */
class PiiAccessPolicyTest extends TestCase {

	private const CAP  = 'ffc_view_certificates_pii';
	private const ROLE = 'ffc_certificates_admin';

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		class_exists( '\\FreeFormCertificate\\Core\\PiiAccessPolicy' );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Stub get_user_by to return a user with the given roles.
	 *
	 * @param list<string> $roles Roles.
	 * @return void
	 */
	private function user_with_roles( array $roles ): void {
		$user        = new \stdClass();
		$user->roles = $roles;
		Functions\when( 'get_user_by' )->justReturn( $user );
	}

	public function test_logged_out_is_masked(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 0 );
		$this->assertSame( PiiAccessPolicy::TIER_MASKED, PiiAccessPolicy::resolve( self::CAP, self::ROLE ) );
	}

	public function test_super_admin_is_unmasked(): void {
		Functions\when( 'user_can' )->alias(
			static fn( $uid, $cap ) => 'manage_options' === $cap
		);
		$this->user_with_roles( array() );
		$this->assertSame( PiiAccessPolicy::TIER_UNMASKED, PiiAccessPolicy::resolve( self::CAP, self::ROLE, null, 5 ) );
	}

	public function test_domain_admin_role_is_unmasked(): void {
		Functions\when( 'user_can' )->justReturn( false );
		$this->user_with_roles( array( 'ffc_certificates_admin' ) );
		$this->assertSame( PiiAccessPolicy::TIER_UNMASKED, PiiAccessPolicy::resolve( self::CAP, self::ROLE, null, 5 ) );
	}

	public function test_pii_cap_holder_is_reveal(): void {
		Functions\when( 'user_can' )->alias(
			static fn( $uid, $cap ) => self::CAP === $cap
		);
		$this->user_with_roles( array( 'ffc_certificates_manager' ) );
		$this->assertSame( PiiAccessPolicy::TIER_REVEAL, PiiAccessPolicy::resolve( self::CAP, self::ROLE, null, 5 ) );
	}

	public function test_owner_is_reveal(): void {
		Functions\when( 'user_can' )->justReturn( false );
		$this->user_with_roles( array( 'ffc_certificates_viewer' ) );
		// Owner id equals the checked user id → reveal (audited self-view).
		$this->assertSame( PiiAccessPolicy::TIER_REVEAL, PiiAccessPolicy::resolve( self::CAP, self::ROLE, 5, 5 ) );
	}

	public function test_view_only_operator_is_masked(): void {
		Functions\when( 'user_can' )->justReturn( false );
		$this->user_with_roles( array( 'ffc_certificates_viewer' ) );
		// No cap, not the owner, not the admin role → masked.
		$this->assertSame( PiiAccessPolicy::TIER_MASKED, PiiAccessPolicy::resolve( self::CAP, self::ROLE, 99, 5 ) );
	}

	public function test_should_audit_only_for_reveal(): void {
		Functions\when( 'user_can' )->justReturn( false );
		// Unmasked (admin role) does NOT audit.
		$this->user_with_roles( array( 'ffc_certificates_admin' ) );
		$this->assertFalse( PiiAccessPolicy::should_audit( self::CAP, self::ROLE, null, 5 ) );

		// Reveal (owner) DOES audit.
		$this->user_with_roles( array() );
		$this->assertTrue( PiiAccessPolicy::should_audit( self::CAP, self::ROLE, 5, 5 ) );
	}

	public function test_can_reveal_true_unless_masked(): void {
		Functions\when( 'user_can' )->justReturn( false );
		$this->user_with_roles( array( 'ffc_certificates_viewer' ) );
		$this->assertFalse( PiiAccessPolicy::can_reveal( self::CAP, self::ROLE, 99, 5 ) );
		$this->assertTrue( PiiAccessPolicy::can_reveal( self::CAP, self::ROLE, 5, 5 ) );
	}
}
