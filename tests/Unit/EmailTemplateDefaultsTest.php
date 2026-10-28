<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Core\EmailTemplateDefaults;
use FreeFormCertificate\Core\EmailTemplates;

/**
 * Direct unit coverage for the shipped submitter-confirmation email defaults.
 *
 * Exercised only indirectly by EmailHandler / FormEditorEmailMetabox (and only
 * on the no-stored-config fallback), so pcov recorded it as uncovered. The
 * body default is read from the real on-disk template through EmailTemplates
 * (#2119).
 *
 * @covers \FreeFormCertificate\Core\EmailTemplateDefaults
 */
class EmailTemplateDefaultsTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		// Several methods this class reaches are deprecated (#2089) and emit
		// the notice; stub it so each test chooses the branch rather than
		// inheriting it from an earlier class.
		\Brain\Monkey\Functions\when( '_deprecated_function' )->justReturn( null );
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
		Functions\when( '__' )->returnArg();

		$body = EmailTemplateDefaults::user_email_body();

		$this->assertNotSame( '', $body, 'the shipped template has a body' );
		$this->assertSame( EmailTemplates::body( 'certificate-user' ), $body );
	}
}
