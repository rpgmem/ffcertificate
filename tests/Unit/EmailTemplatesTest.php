<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Core\EmailTemplates;

/**
 * @covers \FreeFormCertificate\Core\EmailTemplates
 */
class EmailTemplatesTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		\class_exists( '\FreeFormCertificate\Core\EmailTemplates' );
		Functions\when( '__' )->returnArg();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_load_returns_subject_and_body_for_reregistration(): void {
		$t = EmailTemplates::load( 'reregistration-invitation' );

		$this->assertIsArray( $t );
		$this->assertArrayHasKey( 'subject', $t );
		$this->assertArrayHasKey( 'body', $t );
		$this->assertStringContainsString( '{{reregistration_title}}', $t['body'] );
	}

	public function test_load_returns_null_for_unknown_template(): void {
		$this->assertNull( EmailTemplates::load( 'does-not-exist' ) );
	}

	public function test_body_returns_audience_default_body(): void {
		$body = EmailTemplates::body( 'audience-booking' );

		$this->assertStringContainsString( '{{schedule_name}}', $body );
		$this->assertStringContainsString( '{{creator_name}}', $body );
	}

	public function test_body_returns_empty_string_for_unknown(): void {
		$this->assertSame( '', EmailTemplates::body( 'nope' ) );
	}

	/**
	 * @dataProvider editable_default_templates
	 */
	public function test_editable_default_bodies_load_from_files( string $name, string $token ): void {
		$body = EmailTemplates::body( $name );

		$this->assertNotSame( '', $body );
		$this->assertStringContainsString( $token, $body );
	}

	/**
	 * @return array<string, array{0:string,1:string}>
	 */
	public function editable_default_templates(): array {
		return array(
			'certificate'  => array( 'certificate-user', '{{auth_code}}' ),
			'recruitment'  => array( 'recruitment-convocation', '{{notice_code}}' ),
			'confirmation' => array( 'selfscheduling-confirmation', '{{calendar_title}}' ),
		);
	}
}
