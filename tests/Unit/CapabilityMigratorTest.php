<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\UserDashboard\CapabilityManager;
use FreeFormCertificate\UserDashboard\CapabilityMigrator;

/**
 * Tests for the #739 admin role-assignment migration.
 *
 * @covers \FreeFormCertificate\UserDashboard\CapabilityMigrator
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class CapabilityMigratorTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		class_exists( '\\FreeFormCertificate\\UserDashboard\\CapabilityMigrator' );
		class_exists( '\\FreeFormCertificate\\UserDashboard\\CapabilityManager' );

		// `users_with_ffc_grants()` monta a chave da meta de capabilities a
		// partir do prefixo do blog (#1254) -- e essa dependencia e real, nao
		// incidental: em multisite a meta e por blog, e usar o prefixo errado
		// devolveria a lista vazia em silencio.
		global $wpdb;
		$wpdb = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'get_blog_prefix' )->andReturn( 'wp_' );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_email_templates_cap_grant_map_seeds_from_manage_settings(): void {
		// #964: the email-template hub cap is seeded onto ffc_manage_settings
		// holders so existing settings managers keep editing email copy.
		$map = CapabilityMigrator::email_templates_cap_grant_map();

		$this->assertSame(
			array( 'ffc_manage_settings' => array( 'ffc_manage_email_templates' ) ),
			$map
		);
		// The target cap is a registered admin capability.
		$this->assertContains( 'ffc_manage_email_templates', CapabilityManager::ADMIN_CAPABILITIES );
	}

	public function test_admin_role_assignment_backfills_role_and_strips_caps(): void {
		$strip_cap = CapabilityManager::ADMIN_CAPABILITIES[0];

		// Administrator role carries one FFC cap to be stripped.
		$stripped   = array();
		$admin_role = new class( $stripped, $strip_cap ) {
			/** @var array<string, bool> */
			public $capabilities;
			/** @var array<int, string> */
			public $stripped;
			public function __construct( &$stripped, string $cap ) {
				$this->stripped     = &$stripped;
				$this->capabilities = array( $cap => true );
			}
			public function remove_cap( string $cap ): void {
				$this->stripped[] = $cap;
			}
		};

		// ffc_administrator already exists (truthy) → no self-heal.
		Functions\when( 'get_role' )->alias(
			static function ( $role ) use ( $admin_role ) {
				return 'administrator' === $role ? $admin_role : (object) array();
			}
		);

		Functions\when( 'get_users' )->justReturn( array( 7, 8 ) );

		$added7 = array();
		$user7  = new class( $added7 ) {
			/** @var array<int, string> */
			public $roles = array();
			/** @var array<int, string> */
			public $added;
			public function __construct( &$added ) {
				$this->added = &$added;
			}
			public function add_role( string $role ): void {
				$this->added[] = $role;
				$this->roles[] = $role;
			}
		};
		$user8 = new class() {
			/** @var array<int, string> */
			public $roles = array( 'ffc_administrator' );
			/** @var array<int, string> */
			public $added = array();
			public function add_role( string $role ): void {
				$this->added[] = $role;
			}
		};
		Functions\when( 'get_userdata' )->alias(
			static function ( $id ) use ( $user7, $user8 ) {
				return 7 === $id ? $user7 : $user8;
			}
		);

		$counts = CapabilityMigrator::migrate_admin_role_assignment();

		// Back-fill: user7 gets the role, user8 (already has it) is skipped.
		$this->assertContains( 'ffc_administrator', $user7->added );
		$this->assertNotContains( 'ffc_administrator', $user8->added );
		$this->assertSame( 1, $counts['roles_assigned'] );
		// Strip: the FFC cap is removed from the native administrator role.
		$this->assertContains( $strip_cap, $stripped );
		$this->assertGreaterThanOrEqual( 1, $counts['caps_stripped'] );
	}

	public function test_admin_role_assignment_self_heals_missing_role(): void {
		// ffc_administrator missing → the role registry is re-run once.
		Mockery::mock( 'alias:\\FreeFormCertificate\\UserDashboard\\RoleRegistrar' )
			->shouldReceive( 'register_module_roles' )->atLeast()->once();

		Functions\when( 'get_role' )->justReturn( null );
		Functions\when( 'get_users' )->justReturn( array() );

		$counts = CapabilityMigrator::migrate_admin_role_assignment();

		$this->assertSame( 0, $counts['roles_assigned'] );
		$this->assertSame( 0, $counts['caps_stripped'] );
	}

	public function test_rbac_cap_renames_rewrites_user_meta_and_roles(): void {
		Functions\when( 'get_users' )->justReturn( array( 7 ) );

		$user = new class() {
			/** @var array<string, bool> */
			public $caps = array( 'ffc_scheduling_bypass' => true );
			/** @var array<string, bool> */
			public $added = array();
			/** @var array<int, string> */
			public $removed = array();
			public function add_cap( string $cap, bool $grant = true ): void {
				$this->added[ $cap ] = $grant;
			}
			public function remove_cap( string $cap ): void {
				$this->removed[] = $cap;
			}
		};
		Functions\when( 'get_userdata' )->justReturn( $user );

		$role = new class() {
			/** @var array<string, bool> */
			public $capabilities = array( 'ffc_scheduling_bypass' => true );
			/** @var array<string, bool> */
			public $added = array();
			/** @var array<int, string> */
			public $removed = array();
			public function add_cap( string $cap, bool $grant = true ): void {
				$this->added[ $cap ] = $grant;
			}
			public function remove_cap( string $cap ): void {
				$this->removed[] = $cap;
			}
		};
		$roles_obj = new class() {
			/** @var array<string, array<string, mixed>> */
			public $roles = array( 'ffc_appointments_manager' => array() );
		};
		Functions\when( 'wp_roles' )->justReturn( $roles_obj );
		Functions\when( 'get_role' )->justReturn( $role );

		$counts = CapabilityMigrator::migrate_rbac_cap_renames();

		// User-meta: old cap rewritten to the new slug.
		$this->assertArrayHasKey( 'ffc_bypass_appointments', $user->added );
		$this->assertContains( 'ffc_scheduling_bypass', $user->removed );
		// Role definition: same rewrite.
		$this->assertArrayHasKey( 'ffc_bypass_appointments', $role->added );
		$this->assertContains( 'ffc_scheduling_bypass', $role->removed );
		$this->assertSame( 1, $counts['ffc_scheduling_bypass'] );
	}

	public function test_role_renames_reassigns_users_and_drops_old_roles(): void {
		$registrar = Mockery::mock( 'alias:\\FreeFormCertificate\\UserDashboard\\RoleRegistrar' );
		// `register_role()` creates `ffc_end_user`; `register_module_roles()`
		// creates the module-manager roles — both must run before reassigning.
		$registrar->shouldReceive( 'register_role' )->atLeast()->once();
		$registrar->shouldReceive( 'register_module_roles' )->atLeast()->once();

		Functions\when( 'get_users' )->justReturn( array( 7 ) );
		$user = new class() {
			/** @var array<int, string> */
			public $roles = array( 'ffc_user' );
			/** @var array<int, string> */
			public $added = array();
			/** @var array<int, string> */
			public $removed = array();
			public function add_role( string $role ): void {
				$this->added[] = $role;
			}
			public function remove_role( string $role ): void {
				$this->removed[] = $role;
			}
		};
		Functions\when( 'get_userdata' )->justReturn( $user );

		$removed_roles = array();
		Functions\when( 'remove_role' )->alias(
			static function ( $role ) use ( &$removed_roles ) {
				$removed_roles[] = $role;
			}
		);

		$counts = CapabilityMigrator::migrate_role_renames();

		// The user assigned the old end-user role is moved to the new one.
		$this->assertContains( 'ffc_end_user', $user->added );
		$this->assertContains( 'ffc_user', $user->removed );
		$this->assertSame( 1, $counts['ffc_user'] );
		// Every old role slug is dropped, even ones no user held.
		$this->assertContains( 'ffc_user', $removed_roles );
		$this->assertContains( 'ffc_operator', $removed_roles );
		$this->assertContains( 'ffc_self_scheduling_manager', $removed_roles );
		// #739 §3.2/§6 pluralization + recruitment auditor→viewer renames.
		$this->assertContains( 'ffc_certificate_manager', $removed_roles );
		$this->assertContains( 'ffc_audience_manager', $removed_roles );
		$this->assertContains( 'ffc_recruitment_auditor', $removed_roles );
	}

	// ==================================================================
	// users_with_ffc_grants() — o estreitamento do #1254
	// ==================================================================

	/**
	 * A varredura pergunta por quem tem `ffc_*` pessoal, nao por todo mundo.
	 *
	 * O DEFEITO QUE ISTO FECHA
	 *
	 * Onze migracoes deste ficheiro pediam `get_users( array( 'fields' => 'ID'
	 * ) )` -- a base inteira -- e faziam um `get_userdata()` por usuario,
	 * rodando no `plugins_loaded`, isto e, podendo cair numa requisicao de
	 * frontend anonima. E a flag de conclusao so e gravada DEPOIS da
	 * varredura: um timeout no meio fazia a requisicao seguinte recomecar do
	 * zero, indefinidamente.
	 *
	 * POR QUE A ASERCAO E SOBRE OS ARGUMENTOS
	 *
	 * Os testes desta classe encenam `get_users()` pelo retorno, entao um
	 * pedido pela base inteira e um pedido estreito sao indistinguiveis para
	 * eles -- e foi assim que a varredura sobreviveu a uma suite verde. O que
	 * esta asercao le e o PEDIDO.
	 */
	public function test_the_user_scan_asks_only_for_holders_of_an_ffc_grant(): void {
		$captured = array();
		Functions\when( 'get_users' )->alias(
			function ( $args ) use ( &$captured ) {
				$captured = $args;
				return array( 7 );
			}
		);
		Functions\when( 'get_userdata' )->justReturn( false );
		Functions\when( 'wp_roles' )->justReturn( (object) array( 'roles' => array() ) );
		Functions\when( 'get_role' )->justReturn( false );

		CapabilityMigrator::migrate_taxonomy_renames();

		$this->assertSame( 'ID', $captured['fields'] ?? null );
		$this->assertSame( 'wp_capabilities', $captured['meta_key'] ?? null, 'A meta de capabilities e por blog; o prefixo errado devolveria vazio em silencio.' );
		$this->assertSame( 'ffc_', $captured['meta_value'] ?? null );
		$this->assertSame( 'LIKE', $captured['meta_compare'] ?? null );
	}

	/**
	 * O prefiltro cobre PAPEIS tambem, e isso nao e coincidencia.
	 *
	 * Capabilities e papeis moram na mesma meta serializada: um papel aparece
	 * nela como `s:13:"ffc_readonly";b:1;`, igual a uma capability. E todos os
	 * seis papeis antigos que a renomeacao alcanca comecam por `ffc_` -- se um
	 * futuro rename incluir um papel sem esse prefixo, ele sai do prefiltro e
	 * a migracao deixa de encontra-lo em silencio. Esta asercao e onde isso
	 * seria notado.
	 */
	public function test_every_renamed_role_is_reachable_by_the_prefilter(): void {
		foreach ( array_keys( CapabilityMigrator::role_renames() ) as $old_role ) {
			$this->assertStringStartsWith(
				'ffc_',
				$old_role,
				'Um papel antigo sem o prefixo `ffc_` nao seria encontrado pelo prefiltro de `users_with_ffc_grants()`.'
			);
		}
	}
}
