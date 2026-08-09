<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Core\EmailTemplateDefaults;

/**
 * Direct unit coverage for the shipped submitter-confirmation email defaults.
 *
 * Exercised only indirectly by EmailHandler / FormEditorEmailMetabox (and only
 * on the no-stored-config fallback), so pcov recorded it as uncovered. Alias-
 * mocks EmailTemplates so the body default resolves without touching the
 * on-disk template, hence separate processes.
 *
 * @covers \FreeFormCertificate\Core\EmailTemplateDefaults
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class EmailTemplateDefaultsTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		class_exists( '\FreeFormCertificate\Core\EmailTemplateDefaults' );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_subject_carries_the_form_title_placeholder(): void {
		Functions\when( '__' )->returnArg();

		$this->assertStringContainsString( '{{form_title}}', EmailTemplateDefaults::user_email_subject() );
	}

	public function test_body_delegates_to_the_certificate_user_template(): void {
		Mockery::mock( 'alias:FreeFormCertificate\Core\EmailTemplates' )
			->shouldReceive( 'body' )
			->once()
			->with( 'certificate-user' )
			->andReturn( '<p>default body</p>' );

		$this->assertSame( '<p>default body</p>', EmailTemplateDefaults::user_email_body() );
	}
}
