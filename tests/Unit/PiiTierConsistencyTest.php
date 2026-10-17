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
 * One rule for every role in every PII domain (#1815, #1863).
 *
 * Every domain hands `PiiAccessPolicy` its reveal cap. Since #1863 there is
 * no staff unmasked tier: a role reveals in a domain exactly when it holds
 * that domain's `_pii` cap, and is masked otherwise. No role -- not a module
 * administrator, not FFC Administrator -- sees another person's data without
 * Reveal.
 *
 * The caps are read from `AdminAjax::pii_reveal_configs()`, the one place
 * that lists every reveal type, and each user is built from the caps
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
	 * Reveal type => its endpoint config.
	 *
	 * @return array<string, array<string, string>>
	 */
	private function configs(): array {
		$method = new \ReflectionMethod( AdminAjax::class, 'pii_reveal_configs' );
		$method->setAccessible( true );
		$configs = $method->invoke( new AdminAjax() );
		$this->assertSame(
			array( 'submission', 'appointment', 'reregistration', 'audience_account', 'user_profile', 'date_messages_person' ),
			array_keys( $configs ),
			'Every reveal type.'
		);
		foreach ( $configs as $type => $config ) {
			$this->assertArrayNotHasKey( 'admin_role', $config, "{$type} names no unmasked role (#1863)." );
			$this->assertMatchesRegularExpression( '/^ffc_view_[a-z_]+_pii$/', $config['pii_cap'], "{$type} reveals through a module PII cap." );
			$this->assertContains( $config['pii_cap'], CapabilityManager::get_all_capabilities(), "{$type}'s cap is registered." );
		}
		return $configs;
	}

	/**
	 * Make user 5 hold exactly these capabilities.
	 *
	 * @param array<int, string> $caps Capabilities.
	 */
	private function actAs( array $caps ): void {
		Functions\when( 'user_can' )->alias( static fn ( $uid, $cap ) => in_array( $cap, $caps, true ) );
	}

	/**
	 * The tier user 5 resolves to in each reveal type, on someone else's record.
	 *
	 * @return array<string, string>
	 */
	private function tiers(): array {
		$tiers = array();
		foreach ( $this->configs() as $type => $config ) {
			$tiers[ $type ] = PiiAccessPolicy::resolve( $config['pii_cap'], 99, 5 );
		}
		return $tiers;
	}

	public function test_no_role_is_unmasked_anywhere_and_each_reveals_exactly_where_it_holds_the_cap(): void {
		$configs = $this->configs();
		foreach ( CapabilityManager::module_roles_definition() as $role => $definition ) {
			$this->actAs( $definition['caps'] );
			foreach ( $this->tiers() as $type => $tier ) {
				$this->assertSame(
					in_array( $configs[ $type ]['pii_cap'], $definition['caps'], true ) ? PiiAccessPolicy::TIER_REVEAL : PiiAccessPolicy::TIER_MASKED,
					$tier,
					"{$role} in {$type}"
				);
			}
		}
	}

	public function test_ffc_administrator_reveals_in_every_domain(): void {
		$this->actAs( CapabilityManager::module_roles_definition()['ffc_administrator']['caps'] );
		$this->assertSame( array( PiiAccessPolicy::TIER_REVEAL ), array_values( array_unique( $this->tiers() ) ) );
	}

	public function test_a_site_administrator_reveals_in_every_domain(): void {
		$this->actAs( array( 'manage_options' ) );
		$this->assertSame( array( PiiAccessPolicy::TIER_REVEAL ), array_values( array_unique( $this->tiers() ) ) );
	}

	public function test_ffc_readonly_is_masked_in_every_domain(): void {
		$this->actAs( CapabilityManager::module_roles_definition()['ffc_readonly']['caps'] );
		$this->assertSame( array( PiiAccessPolicy::TIER_MASKED ), array_values( array_unique( $this->tiers() ) ) );
	}

	public function test_each_pii_cap_belongs_to_its_module_manager_and_administrator(): void {
		$definition = CapabilityManager::module_roles_definition();
		$owners     = array(
			'ffc_view_certificates_pii'   => array( 'ffc_certificates_manager', 'ffc_certificates_admin' ),
			'ffc_view_appointments_pii'   => array( 'ffc_appointments_manager', 'ffc_appointments_admin' ),
			'ffc_view_reregistration_pii' => array( 'ffc_reregistration_manager', 'ffc_reregistration_admin' ),
			'ffc_view_audiences_pii'      => array( 'ffc_audiences_manager', 'ffc_audiences_admin' ),
			'ffc_view_date_messages_pii'  => array( 'ffc_date_messages_manager', 'ffc_date_messages_admin' ),
			'ffc_view_recruitment_pii'    => array( CapabilityManager::RECRUITMENT_MANAGER_ROLE, 'ffc_recruitment_admin' ),
		);
		foreach ( $owners as $cap => $roles ) {
			$holders = array();
			foreach ( $definition as $role => $def ) {
				if ( in_array( $cap, $def['caps'], true ) ) {
					$holders[] = $role;
				}
			}
			sort( $holders );
			$expected = array_merge( $roles, array( 'ffc_administrator' ) );
			sort( $expected );
			$this->assertSame( $expected, $holders, $cap );
		}
	}

	public function test_reregistration_tiers_per_role(): void {
		$definition = CapabilityManager::module_roles_definition();
		$expected   = array(
			'ffc_reregistration_admin'    => PiiAccessPolicy::TIER_REVEAL,
			'ffc_reregistration_manager'  => PiiAccessPolicy::TIER_REVEAL,
			'ffc_administrator'           => PiiAccessPolicy::TIER_REVEAL,
			'ffc_reregistration_viewer'   => PiiAccessPolicy::TIER_MASKED,
			'ffc_reregistration_operator' => PiiAccessPolicy::TIER_MASKED,
		);
		foreach ( $expected as $role => $tier ) {
			$this->actAs( $definition[ $role ]['caps'] );
			$this->assertSame( $tier, $this->tiers()['reregistration'], $role );
		}
	}

	public function test_the_details_endpoint_asks_the_same_question_as_the_reveal_endpoint(): void {
		$config = $this->configs()['reregistration'];
		$class  = new \ReflectionClass( ReregistrationAjaxHandler::class );

		$this->assertSame( $config['pii_cap'], $class->getConstant( 'PII_CAPABILITY' ) );
		$this->assertFalse( $class->hasConstant( 'PII_UNMASKED_ROLE' ), 'The unmasked role is retired (#1863).' );
	}
}
