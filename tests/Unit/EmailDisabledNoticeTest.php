<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Core\EmailDisabledNotice;

/**
 * @covers \FreeFormCertificate\Core\EmailDisabledNotice
 */
class EmailDisabledNoticeTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		\class_exists( '\FreeFormCertificate\Core\EmailDisabledNotice' );

		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'admin_url' )->alias( static fn( $p = '' ) => '/wp-admin/' . $p );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function capture(): string {
		ob_start();
		EmailDisabledNotice::render();
		return (string) ob_get_clean();
	}

	public function test_render_outputs_nothing_when_emails_enabled(): void {
		Functions\when( 'get_option' )->justReturn( array() );

		$this->assertSame( '', $this->capture() );
	}

	public function test_render_outputs_nothing_when_toggle_is_off(): void {
		Functions\when( 'get_option' )->justReturn( array( 'disable_all_emails' => '0' ) );

		$this->assertSame( '', $this->capture() );
	}

	public function test_render_outputs_notice_when_emails_disabled(): void {
		Functions\when( 'get_option' )->justReturn( array( 'disable_all_emails' => '1' ) );

		$html = $this->capture();

		$this->assertStringContainsString( 'ffc-email-disabled-notice', $html );
		$this->assertStringContainsString( 'admin.php?page=ffc-settings&tab=smtp', $html );
		$this->assertStringContainsString( 'currently turned off', $html );
	}
}
