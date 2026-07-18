<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Integrations\MailQueue;

/**
 * @covers \FreeFormCertificate\Integrations\MailQueue
 */
class MailQueueTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		\class_exists( '\FreeFormCertificate\Integrations\MailQueue' );
		// The detector's answer passes through this filter unchanged by default.
		Functions\when( 'apply_filters' )->returnArg( 2 );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @param array<int, string>    $active  Per-site active_plugins.
	 * @param array<string, mixed>  $network Network active_sitewide_plugins.
	 */
	private function stub_plugin_lists( array $active, array $network = array() ): void {
		Functions\when( 'get_option' )->alias(
			static function ( $key, $default = false ) use ( $active ) {
				return 'active_plugins' === $key ? $active : $default;
			}
		);
		Functions\when( 'get_site_option' )->alias(
			static function ( $key, $default = false ) use ( $network ) {
				return 'active_sitewide_plugins' === $key ? $network : $default;
			}
		);
	}

	public function test_inactive_when_no_plugins(): void {
		$this->stub_plugin_lists( array() );
		$this->assertFalse( MailQueue::is_active() );
	}

	public function test_active_when_persite_plugin_present(): void {
		$this->stub_plugin_lists( array( 'akismet/akismet.php', 'total-mail-queue/total-mail-queue.php' ) );
		$this->assertTrue( MailQueue::is_active() );
	}

	public function test_active_matches_folder_regardless_of_main_file_name(): void {
		// Detection keys on the folder, so a renamed bootstrap file still matches.
		$this->stub_plugin_lists( array( 'total-mail-queue/tmq.php' ) );
		$this->assertTrue( MailQueue::is_active() );
	}

	public function test_active_when_network_activated(): void {
		$this->stub_plugin_lists(
			array(),
			array( 'total-mail-queue/total-mail-queue.php' => 1700000000 )
		);
		$this->assertTrue( MailQueue::is_active() );
	}

	public function test_unrelated_plugins_do_not_match(): void {
		$this->stub_plugin_lists( array( 'woocommerce/woocommerce.php', 'total-mail-queue-lite/x.php' ) );
		$this->assertFalse( MailQueue::is_active() );
	}

	public function test_filter_can_force_active(): void {
		$this->stub_plugin_lists( array() );
		Functions\when( 'apply_filters' )->justReturn( true );
		$this->assertTrue( MailQueue::is_active() );
	}

	public function test_filter_can_force_inactive(): void {
		$this->stub_plugin_lists( array( 'total-mail-queue/total-mail-queue.php' ) );
		Functions\when( 'apply_filters' )->justReturn( false );
		$this->assertFalse( MailQueue::is_active() );
	}
}
