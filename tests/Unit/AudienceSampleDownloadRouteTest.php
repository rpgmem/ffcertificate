<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Audience\AudienceAdminPage;

/**
 * The "Download Sample" request on the URL the Import & Export tab now links
 * to (`page=ffc-scheduling-settings&tab=import&download_sample=…`) reaches the
 * sample download through the coordinator's `admin_init` dispatch (#1771).
 *
 * `SyncCsvExport` is overload-mocked, which pollutes the class table, so the
 * class runs each test in isolation.
 *
 * @covers \FreeFormCertificate\Audience\AudienceAdminPage
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class AudienceSampleDownloadRouteTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( '__' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'sanitize_key' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
	}

	protected function tearDown(): void {
		$_GET = array();
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_sample_download_runs_on_the_settings_import_tab(): void {
		$_GET = array(
			'page'            => 'ffc-scheduling-settings',
			'tab'             => 'import',
			'download_sample' => 'members',
			'_wpnonce'        => 'n',
		);

		$sources = array();
		$export  = Mockery::mock( 'overload:FreeFormCertificate\Core\SyncCsvExport' );
		$export->shouldReceive( 'handle' )->once()->andReturnUsing(
			static function ( $source ) use ( &$sources ) {
				$sources[] = $source;
			}
		);

		$page = new AudienceAdminPage();
		$page->init();
		$page->handle_form_submissions();

		$this->assertCount( 1, $sources );
		$this->assertInstanceOf( \FreeFormCertificate\Audience\AudienceSampleCsvSource::class, $sources[0] );
	}
}
