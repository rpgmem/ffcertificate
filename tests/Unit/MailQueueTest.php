<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FreeFormCertificate\Core\EmailService;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Integrations\MailQueue;

/**
 * One detection of the total-mail-queue plugin (#2336): the SMTP screen and
 * the send path ask the same question, the queue's own class.
 *
 * @covers \FreeFormCertificate\Integrations\MailQueue
 * @covers \FreeFormCertificate\Core\EmailService::mail_queue_active
 */
class MailQueueTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		// The detector's answer passes through this filter unchanged by default.
		Functions\when( 'apply_filters' )->returnArg( 2 );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_inactive_while_the_queue_class_is_not_loaded(): void {
		$this->assertFalse( MailQueue::is_active() );
		$this->assertFalse( EmailService::mail_queue_active() );
	}

	/**
	 * Runs in its own process because it declares the queue's class, which
	 * would stay declared for every later test and stamp their messages.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_active_once_the_queue_class_is_loaded_and_both_callers_agree(): void {
		eval( 'namespace TotalMailQueue\Sources; class Detector {}' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- Declares the sibling plugin's class for this one isolated test.

		$this->assertTrue( MailQueue::is_active() );
		$this->assertTrue( EmailService::mail_queue_active() );
	}

	public function test_filter_can_force_active(): void {
		Functions\when( 'apply_filters' )->justReturn( true );
		$this->assertTrue( MailQueue::is_active() );
	}

	public function test_filter_can_force_inactive(): void {
		Functions\when( 'apply_filters' )->justReturn( false );
		$this->assertFalse( MailQueue::is_active() );
	}
}
