<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Core\PiiAccessPolicy;

/**
 * Tests for the shared PII access-tier resolver (#739 §3.3, #1863).
 *
 * @covers \FreeFormCertificate\Core\PiiAccessPolicy
 */
class PiiAccessPolicyTest extends TestCase {

	private const CAP  = 'ffc_view_certificates_pii';

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
		$this->assertSame( PiiAccessPolicy::TIER_MASKED, PiiAccessPolicy::resolve( self::CAP ) );
	}

	public function test_super_admin_reveals_and_is_never_unmasked(): void {
		// The staff unmasked tier is retired (#1863): a WordPress
		// administrator goes through Reveal like everyone else.
		Functions\when( 'user_can' )->alias(
			static fn( $uid, $cap ) => 'manage_options' === $cap
		);
		$this->assertSame( PiiAccessPolicy::TIER_REVEAL, PiiAccessPolicy::resolve( self::CAP, null, 5 ) );
		$this->assertTrue( PiiAccessPolicy::should_audit( self::CAP, null, 5 ) );
	}

	public function test_the_module_admin_role_alone_reveals_nothing(): void {
		// Roles no longer matter: only the cap does. A user holding the old
		// unmasked role but no PII cap is masked.
		Functions\when( 'user_can' )->justReturn( false );
		$this->user_with_roles( array( 'ffc_certificates_admin' ) );
		$this->assertSame( PiiAccessPolicy::TIER_MASKED, PiiAccessPolicy::resolve( self::CAP, null, 5 ) );
	}

	public function test_pii_cap_holder_is_reveal(): void {
		Functions\when( 'user_can' )->alias(
			static fn( $uid, $cap ) => self::CAP === $cap
		);
		$this->assertSame( PiiAccessPolicy::TIER_REVEAL, PiiAccessPolicy::resolve( self::CAP, null, 5 ) );
	}

	public function test_owner_sees_their_own_record_unmasked_without_audit(): void {
		Functions\when( 'user_can' )->justReturn( false );
		$this->assertSame( PiiAccessPolicy::TIER_UNMASKED, PiiAccessPolicy::resolve( self::CAP, 5, 5 ) );
		$this->assertFalse( PiiAccessPolicy::should_audit( self::CAP, 5, 5 ) );
		$this->assertTrue( PiiAccessPolicy::can_reveal( self::CAP, 5, 5 ) );
	}

	public function test_owner_clause_wins_over_the_cap(): void {
		Functions\when( 'user_can' )->justReturn( true );
		$this->assertSame( PiiAccessPolicy::TIER_UNMASKED, PiiAccessPolicy::resolve( self::CAP, 5, 5 ) );
	}

	public function test_view_only_operator_is_masked(): void {
		Functions\when( 'user_can' )->justReturn( false );
		// No cap, not the owner → masked.
		$this->assertSame( PiiAccessPolicy::TIER_MASKED, PiiAccessPolicy::resolve( self::CAP, 99, 5 ) );
		$this->assertFalse( PiiAccessPolicy::can_reveal( self::CAP, 99, 5 ) );
		$this->assertFalse( PiiAccessPolicy::should_audit( self::CAP, 99, 5 ) );
	}

	public function test_a_zero_owner_is_nobody(): void {
		Functions\when( 'user_can' )->justReturn( false );
		$this->assertSame( PiiAccessPolicy::TIER_MASKED, PiiAccessPolicy::resolve( self::CAP, 0, 5 ) );
	}

	public function test_should_audit_every_staff_reveal(): void {
		Functions\when( 'user_can' )->alias(
			static fn( $uid, $cap ) => self::CAP === $cap
		);
		$this->assertTrue( PiiAccessPolicy::should_audit( self::CAP, 99, 5 ) );
	}

	public function test_can_reveal_any_is_the_reveal_tier_whoever_owns_the_record(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'user_can' )->alias(
			static fn( $uid, $cap ) => self::CAP === $cap
		);
		$this->assertTrue( PiiAccessPolicy::can_reveal_any( self::CAP ) );

		Functions\when( 'user_can' )->justReturn( false );
		$this->assertFalse( PiiAccessPolicy::can_reveal_any( self::CAP ), 'An owner of one record is not a revealer of the module.' );
	}

	public function test_ffc_readonly_never_reveals(): void {
		Functions\when( '__' )->returnArg();
		$caps = \FreeFormCertificate\UserDashboard\CapabilityManager::module_roles_definition()['ffc_readonly']['caps'];
		Functions\when( 'user_can' )->alias(
			static fn( $uid, $cap ) => in_array( $cap, $caps, true )
		);
		foreach ( array( 'ffc_view_certificates_pii', 'ffc_view_appointments_pii', 'ffc_view_reregistration_pii', 'ffc_view_recruitment_pii', 'ffc_view_audiences_pii', 'ffc_view_date_messages_pii' ) as $cap ) {
			$this->assertSame( PiiAccessPolicy::TIER_MASKED, PiiAccessPolicy::resolve( $cap, 99, 5 ), $cap );
		}
	}
}
