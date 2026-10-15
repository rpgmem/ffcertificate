<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Admin\AdminAjax;
use FreeFormCertificate\Core\PiiAccessPolicy;
use FreeFormCertificate\Reregistration\ReregistrationAjaxHandler;
use FreeFormCertificate\UserDashboard\CapabilityManager;

/**
 * One role lands on one PII tier in every domain (#1815).
 *
 * The three PII domains -- certificates, appointments, reregistration --
 * hand `PiiAccessPolicy` a (reveal cap, unmasked role) pair each. Nothing
 * else ties the pairs together, so a domain that named a different kind of
 * role as unmasked (reregistration first named `ffc_administrator`) would
 * make one role see plaintext in one domain and a reveal button in the next.
 *
 * The pairs are read from `AdminAjax::pii_reveal_configs()`, the one place
 * that lists every domain, and each user is built from the caps and roles
 * `CapabilityManager::module_roles_definition()` actually grants, so a role
 * that changes its caps changes what is measured here.
 *
 * @covers \FreeFormCertificate\Core\PiiAccessPolicy
 */
class PiiTierConsistencyTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();
		Functions\when( 'add_action' )->justReturn( true );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Reveal types that own no PII pair and borrow a domain's, => the lender.
	 * The audience screens' account email (#1824) uses the appointments pair,
	 * because audiences have no PII capability or admin role of their own.
	 */
	private const BORROWED = array( 'audience_account' => 'appointment' );

	/**
	 * Domain => its reveal-endpoint config. A borrowing type must hand the
	 * policy exactly its lender's pair, and is then left out: it is not a
	 * domain, so it would read as one role unmasked in two domains.
	 *
	 * @return array<string, array<string, string>>
	 */
	private function configs(): array {
		$method = new \ReflectionMethod( AdminAjax::class, 'pii_reveal_configs' );
		$method->setAccessible( true );
		$configs = $method->invoke( new AdminAjax() );
		$this->assertSame( array( 'submission', 'appointment', 'reregistration', 'audience_account' ), array_keys( $configs ), 'The three PII domains and the borrowing type.' );
		foreach ( self::BORROWED as $type => $lender ) {
			$this->assertSame( $configs[ $lender ]['pii_cap'], $configs[ $type ]['pii_cap'], "{$type} borrows the {$lender} reveal cap" );
			$this->assertSame( $configs[ $lender ]['admin_role'], $configs[ $type ]['admin_role'], "{$type} borrows the {$lender} unmasked role" );
			unset( $configs[ $type ] );
		}
		return $configs;
	}

	/**
	 * Make user 5 hold exactly these roles and capabilities.
	 *
	 * @param array<int, string> $roles Roles.
	 * @param array<int, string> $caps  Capabilities.
	 */
	private function actAs( array $roles, array $caps ): void {
		Functions\when( 'get_user_by' )->justReturn( (object) array( 'roles' => $roles ) );
		Functions\when( 'user_can' )->alias( static fn ( $uid, $cap ) => in_array( $cap, $caps, true ) );
	}

	/**
	 * The tier user 5 resolves to in each domain.
	 *
	 * @return array<string, string>
	 */
	private function tiers(): array {
		$tiers = array();
		foreach ( $this->configs() as $domain => $config ) {
			$tiers[ $domain ] = PiiAccessPolicy::resolve( $config['pii_cap'], $config['admin_role'], null, 5 );
		}
		return $tiers;
	}

	/**
	 * @return array<int, string>
	 */
	private function roleCaps( string $role ): array {
		$definition = CapabilityManager::module_roles_definition();
		$this->assertArrayHasKey( $role, $definition );
		return $definition[ $role ]['caps'];
	}

	public function test_ffc_administrator_is_the_reveal_tier_in_every_domain(): void {
		$this->actAs( array( 'ffc_administrator' ), $this->roleCaps( 'ffc_administrator' ) );

		$this->assertSame(
			array(
				'submission'     => PiiAccessPolicy::TIER_REVEAL,
				'appointment'    => PiiAccessPolicy::TIER_REVEAL,
				'reregistration' => PiiAccessPolicy::TIER_REVEAL,
			),
			$this->tiers()
		);
	}

	public function test_a_site_administrator_is_the_unmasked_tier_in_every_domain(): void {
		$this->actAs( array( 'administrator' ), array( 'manage_options' ) );

		$this->assertSame( array( PiiAccessPolicy::TIER_UNMASKED ), array_values( array_unique( $this->tiers() ) ) );
	}

	public function test_each_domain_admin_role_holds_its_pii_cap_and_is_unmasked_in_its_own_domain_only(): void {
		foreach ( $this->configs() as $domain => $config ) {
			$caps = $this->roleCaps( $config['admin_role'] );
			$this->assertContains( $config['pii_cap'], $caps, "{$config['admin_role']} holds {$config['pii_cap']}" );
			$this->assertStringEndsWith( '_admin', $config['admin_role'], "The {$domain} unmasked role is a domain admin role." );

			$this->actAs( array( $config['admin_role'] ), $caps );
			foreach ( $this->tiers() as $other => $tier ) {
				$this->assertSame(
					$other === $domain ? PiiAccessPolicy::TIER_UNMASKED : PiiAccessPolicy::TIER_MASKED,
					$tier,
					"{$config['admin_role']} in {$other}"
				);
			}
		}
	}

	public function test_reregistration_tiers_per_role(): void {
		$expected = array(
			'ffc_reregistration_admin'   => PiiAccessPolicy::TIER_UNMASKED,
			'ffc_reregistration_manager' => PiiAccessPolicy::TIER_REVEAL,
			'ffc_administrator'          => PiiAccessPolicy::TIER_REVEAL,
			'ffc_reregistration_viewer'  => PiiAccessPolicy::TIER_MASKED,
			'ffc_reregistration_operator' => PiiAccessPolicy::TIER_MASKED,
		);
		foreach ( $expected as $role => $tier ) {
			$this->actAs( array( $role ), $this->roleCaps( $role ) );
			$this->assertSame( $tier, $this->tiers()['reregistration'], $role );
		}
	}

	public function test_the_details_endpoint_asks_the_same_question_as_the_reveal_endpoint(): void {
		$config = $this->configs()['reregistration'];
		$class  = new \ReflectionClass( ReregistrationAjaxHandler::class );

		$this->assertSame( $config['pii_cap'], $class->getConstant( 'PII_CAPABILITY' ) );
		$this->assertSame( $config['admin_role'], $class->getConstant( 'PII_UNMASKED_ROLE' ) );
	}
}
