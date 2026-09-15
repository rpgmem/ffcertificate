<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Admin\AdminLoader;

/**
 * Tests for AdminLoader — the single bootstrap entry point for the Admin
 * module (#563 B3 coupling reduction). Pins that init() constructs the
 * stateful trio (CsvExporter / Admin / AdminAjax) and fires ::init() on every
 * admin-only endpoint exactly once, so a future refactor can't silently drop
 * one when the orchestrator stops newing them up directly.
 *
 * Also pins the Certificates-module gate on the expired-tickets cleanup cron:
 * its callback is wired only when `module_enabled('certificates')` is true, so
 * a disabled module halts the daily `ffc_form` sweep.
 *
 * @covers \FreeFormCertificate\Admin\AdminLoader
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class AdminLoaderTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/**
	 * Static endpoints wired unconditionally by init() (i.e. every one except
	 * the certificates-gated ExpiredTicketsCleanup).
	 *
	 * @var string[]
	 */
	private const UNGATED_ENDPOINTS = array(
		'AdminUserColumns',
		'AdminUserCapabilities',
		'RoleCapabilityEditor',
		'AdminMenuVisibility',
		'DeviceThresholdUpgradeNotice',
		'SettingsAjaxEndpoint',
		'FormMetaAjaxEndpoint',
		'LocationsAjaxEndpoint',
		'CacheActionsAjaxEndpoint',
		'FormFeaturesAjaxEndpoint',
		'MigrationActionsAjaxEndpoint',
		'ActivityLogAjaxEndpoint',
		'SubmissionsBulkActionsAjaxEndpoint',
		'FormListColumns',
		'AdminUserCustomFields',
	);

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		// pcov does not attribute coverage to a class first autoloaded mid-test;
		// preload AdminLoader so its lines attribute to this test.
		class_exists( '\\FreeFormCertificate\\Admin\\AdminLoader' );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Wire the stateful trio + every ungated endpoint, each ::init() once.
	 */
	private function mock_common_wiring(): void {
		Mockery::mock( 'overload:FreeFormCertificate\Admin\CsvExporter' );
		Mockery::mock( 'overload:FreeFormCertificate\Admin\Admin' );
		Mockery::mock( 'overload:FreeFormCertificate\Admin\AdminAjax' );

		foreach ( self::UNGATED_ENDPOINTS as $cls ) {
			Mockery::mock( 'alias:FreeFormCertificate\Admin\\' . $cls )
				->shouldReceive( 'init' )->once();
		}
	}

	public function test_init_wires_every_admin_module_class(): void {
		$this->mock_common_wiring();

		$handler = Mockery::mock( 'FreeFormCertificate\Submissions\SubmissionHandler' );

		( new AdminLoader( $handler ) )->init();

		// Mockery's ->once() expectations are verified on tearDown; assert here
		// too so the test never counts as risky/assertion-less.
		$this->assertTrue( true );
	}

	/**
	 * Este loader NAO pode montar a cron de tickets expirados (#1234).
	 *
	 * Ele montava, e por isso ela nunca rodou: `AdminLoader` so e construido
	 * dentro de `if ( is_admin() )`, e `wp-cron.php` define `DOING_CRON` e
	 * nunca `WP_ADMIN` -- entao `is_admin()` e falso em todo contexto que
	 * EXECUTA o gancho. O registro mora agora em
	 * `Loader::define_admin_hooks()`, que apesar do nome roda em toda
	 * requisicao, e e la que os testes do gate de modulo vivem.
	 *
	 * O TESTE ANTIGO PROVAVA A COISA CERTA NO LUGAR ERRADO
	 *
	 * Ele cobrava que `AdminLoader::init()` chamasse
	 * `ExpiredTicketsCleanup::init()`, o que era verdade e inutil: fixava uma
	 * fiacao que nunca disparava. Uma expectativa verde nao diz que o gancho
	 * chega a ser chamado -- so que o caminho que o teste encena o registra.
	 */
	public function test_init_does_not_wire_the_expired_tickets_cron(): void {
		$this->mock_common_wiring();

		Mockery::mock( 'alias:FreeFormCertificate\Admin\ExpiredTicketsCleanup' )
			->shouldReceive( 'init' )->never();

		$handler = Mockery::mock( 'FreeFormCertificate\Submissions\SubmissionHandler' );

		( new AdminLoader( $handler ) )->init();

		$this->assertTrue( true );
	}
}
