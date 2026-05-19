<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Recruitment\RecruitmentPiiAccessPolicy;

/**
 * Tests for RecruitmentPiiAccessPolicy — the three-tier resolver that
 * decides whether the operator sees PII unmasked, behind a click-to-
 * reveal toggle, or never. Covers each of the four resolution rules
 * (cap, role, granular cap, owner) plus the convenience predicates.
 *
 * @covers \FreeFormCertificate\Recruitment\RecruitmentPiiAccessPolicy
 */
class RecruitmentPiiAccessPolicyTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function setUserCapsAndRoles( int $uid, array $caps, array $roles = array() ): void {
		Functions\when( 'get_current_user_id' )->justReturn( $uid );

		// user_can( $uid, $cap ) returns true if cap is in $caps.
		Functions\when( 'user_can' )->alias( static function ( $arg_uid, $cap ) use ( $uid, $caps ) {
			if ( (int) $arg_uid !== $uid ) {
				return false;
			}
			return in_array( $cap, $caps, true );
		} );

		// get_user_by('id', $uid) returns a stub with the given roles.
		Functions\when( 'get_user_by' )->alias( static function ( $field, $arg_uid ) use ( $uid, $roles ) {
			if ( 'id' !== $field || (int) $arg_uid !== $uid ) {
				return false;
			}
			$stub        = new \stdClass();
			$stub->roles = $roles;
			return $stub;
		} );
	}

	private function candidate( ?int $linked_user_id = null ): object {
		$c          = new \stdClass();
		$c->id      = 7;
		$c->user_id = $linked_user_id;
		return $c;
	}

	// ------------------------------------------------------------------
	// Tier resolution
	// ------------------------------------------------------------------

	public function test_manage_options_user_gets_unmasked_tier(): void {
		$this->setUserCapsAndRoles( 10, array( 'manage_options' ) );
		$this->assertSame(
			RecruitmentPiiAccessPolicy::TIER_UNMASKED,
			RecruitmentPiiAccessPolicy::resolve( $this->candidate() )
		);
	}

	public function test_ffc_recruitment_admin_role_gets_unmasked_tier(): void {
		$this->setUserCapsAndRoles( 10, array(), array( 'ffc_recruitment_admin' ) );
		$this->assertSame(
			RecruitmentPiiAccessPolicy::TIER_UNMASKED,
			RecruitmentPiiAccessPolicy::resolve( $this->candidate() )
		);
	}

	public function test_user_with_granular_pii_cap_gets_reveal_tier(): void {
		$this->setUserCapsAndRoles( 10, array( 'ffc_view_recruitment_pii' ), array( 'ffc_recruitment_manager' ) );
		$this->assertSame(
			RecruitmentPiiAccessPolicy::TIER_REVEAL,
			RecruitmentPiiAccessPolicy::resolve( $this->candidate() )
		);
	}

	public function test_candidate_owner_gets_reveal_tier_even_without_caps(): void {
		// Owner of the data (linked WP user) — no caps, no role.
		$this->setUserCapsAndRoles( 42, array(), array( 'subscriber' ) );
		$this->assertSame(
			RecruitmentPiiAccessPolicy::TIER_REVEAL,
			RecruitmentPiiAccessPolicy::resolve( $this->candidate( 42 ) )
		);
	}

	public function test_unrelated_user_without_caps_gets_masked_tier(): void {
		$this->setUserCapsAndRoles( 50, array(), array( 'subscriber' ) );
		$this->assertSame(
			RecruitmentPiiAccessPolicy::TIER_MASKED,
			RecruitmentPiiAccessPolicy::resolve( $this->candidate( 42 ) )
		);
	}

	public function test_logged_out_user_gets_masked_tier(): void {
		$this->setUserCapsAndRoles( 0, array() );
		$this->assertSame(
			RecruitmentPiiAccessPolicy::TIER_MASKED,
			RecruitmentPiiAccessPolicy::resolve( $this->candidate( 42 ) )
		);
	}

	public function test_owner_check_only_fires_when_candidate_is_provided(): void {
		// Same user (uid=42) but candidate is null — falls through to caps,
		// no caps → masked.
		$this->setUserCapsAndRoles( 42, array() );
		$this->assertSame(
			RecruitmentPiiAccessPolicy::TIER_MASKED,
			RecruitmentPiiAccessPolicy::resolve( null )
		);
	}

	public function test_cap_path_wins_over_owner_path_for_higher_tier(): void {
		// Owner of the record + admin role → unmasked (the higher tier wins).
		$this->setUserCapsAndRoles( 42, array( 'manage_options' ) );
		$this->assertSame(
			RecruitmentPiiAccessPolicy::TIER_UNMASKED,
			RecruitmentPiiAccessPolicy::resolve( $this->candidate( 42 ) )
		);
	}

	// ------------------------------------------------------------------
	// Convenience predicates
	// ------------------------------------------------------------------

	public function test_can_reveal_true_for_unmasked_and_reveal_tiers(): void {
		$this->setUserCapsAndRoles( 10, array( 'manage_options' ) );
		$this->assertTrue( RecruitmentPiiAccessPolicy::can_reveal( $this->candidate() ) );

		$this->setUserCapsAndRoles( 10, array( 'ffc_view_recruitment_pii' ) );
		$this->assertTrue( RecruitmentPiiAccessPolicy::can_reveal( $this->candidate() ) );
	}

	public function test_can_reveal_false_for_masked_tier(): void {
		$this->setUserCapsAndRoles( 10, array() );
		$this->assertFalse( RecruitmentPiiAccessPolicy::can_reveal( $this->candidate( 42 ) ) );
	}

	public function test_should_audit_true_only_for_reveal_tier(): void {
		// unmasked → no audit (high-trust roles, no noise).
		$this->setUserCapsAndRoles( 10, array( 'manage_options' ) );
		$this->assertFalse( RecruitmentPiiAccessPolicy::should_audit( $this->candidate() ) );

		// reveal → audit fires.
		$this->setUserCapsAndRoles( 10, array( 'ffc_view_recruitment_pii' ) );
		$this->assertTrue( RecruitmentPiiAccessPolicy::should_audit( $this->candidate() ) );

		// masked → no reveal possible, audit moot but contract is false.
		$this->setUserCapsAndRoles( 10, array() );
		$this->assertFalse( RecruitmentPiiAccessPolicy::should_audit( $this->candidate( 42 ) ) );
	}
}
