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
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'wp_kses_post' )->returnArg();
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

		// The `.ffc-notice` callout in the warning tone, with the email icon
		// (#1843), never a core `.notice` that common.js would lift away.
		$this->assertStringStartsWith( '<div class="ffc-notice ffc-notice-warning" role="note" data-ffc-notice="emails-disabled">', $html );
		$this->assertStringContainsString( '<span class="ffc-notice-icon ffc-icon-email" aria-hidden="true"></span>', $html );
		$this->assertStringNotContainsString( 'class="notice', $html );
		$this->assertStringContainsString( 'admin.php?page=ffc-settings&tab=smtp', $html );
		$this->assertStringContainsString( 'currently turned off', $html );
	}
}
