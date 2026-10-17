<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Core\PiiExport;

/**
 * The PII export rule (#1863): personal columns in clear only for the
 * module's PII cap, masked otherwise, and a clear export recorded.
 *
 * @covers \FreeFormCertificate\Core\PiiExport
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class PiiExportTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'get_current_user_id' )->justReturn( 4 );
		Functions\when( 'is_email' )->alias( static fn( $v ) => is_string( $v ) && 1 === substr_count( $v, '@' ) );
		// No dynamic sensitive key unless a test says so.
		Functions\when( 'wp_cache_get' )->justReturn( array() );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_allowed_follows_the_module_pii_cap(): void {
		Functions\when( 'user_can' )->alias( static fn( $uid, $cap ) => 'ffc_view_appointments_pii' === $cap );

		$this->assertTrue( PiiExport::allowed( 'ffc_view_appointments_pii' ) );
		$this->assertFalse( PiiExport::allowed( 'ffc_view_certificates_pii' ) );
	}

	public function test_a_site_administrator_exports_in_clear(): void {
		Functions\when( 'user_can' )->alias( static fn( $uid, $cap ) => 'manage_options' === $cap );

		$this->assertTrue( PiiExport::allowed( 'ffc_view_reregistration_pii' ) );
	}

	public function test_is_personal_knows_the_identifiers_and_the_dynamic_sensitive_keys(): void {
		Functions\when( 'wp_cache_get' )->justReturn( array( 'rg_estadual' => true ) );

		foreach ( array( 'cpf', 'rf', 'rg', 'email', 'user_email', 'phone', 'telefone', 'user_ip', 'rg_estadual' ) as $key ) {
			$this->assertTrue( PiiExport::is_personal( $key ), $key );
		}
		foreach ( array( 'name', 'course', 'data', 'status' ) as $key ) {
			$this->assertFalse( PiiExport::is_personal( $key ), $key );
		}
	}

	public function test_cell_masks_a_personal_value_only_when_the_export_is_not_clear(): void {
		$this->assertSame( 'ana@x.com', PiiExport::cell( 'email', 'ana@x.com', true ) );
		$this->assertSame( 'a***@x.com', PiiExport::cell( 'email', 'ana@x.com', false ) );
		$this->assertSame( 'a***@x.com', PiiExport::cell( 'user_email', 'ana@x.com', false ) );
		$this->assertSame( '203.0.***.***', PiiExport::cell( 'user_ip', '203.0.113.7', false ) );
		$this->assertSame( '518.***.***-80', PiiExport::cell( 'cpf', '51817842080', false ) );
		$this->assertSame( 'Math', PiiExport::cell( 'course', 'Math', false ), 'A column that is not personal stays.' );
		$this->assertSame( '', PiiExport::cell( 'cpf', '', false ) );
	}

	public function test_log_records_the_export_and_its_rows(): void {
		Mockery::getConfiguration()->setConstantsMap(
			array( 'FreeFormCertificate\Core\ActivityLog' => array( 'LEVEL_INFO' => 'info' ) )
		);
		Mockery::mock( 'alias:FreeFormCertificate\Core\ActivityLog' )
			->shouldReceive( 'log' )->once()
			->with( 'pii_exported', 'info', array( 'export' => 'submissions', 'rows' => 7 ), 4, 0 )
			->andReturn( true );

		PiiExport::log( 'submissions', 7 );
	}
}
