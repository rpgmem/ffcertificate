<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Deactivator;

/**
 * Tests for Deactivator: deactivation hook cleanup and destructive uninstall.
 *
 * @covers \FreeFormCertificate\Deactivator
 * @preserveGlobalState disabled
 */
class DeactivatorTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var Mockery\MockInterface */
	private $wpdb;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		// Several methods this class reaches are deprecated (#2089) and emit
		// the notice; stub it so each test chooses the branch rather than
		// inheriting it from an earlier class.
		\Brain\Monkey\Functions\when( '_deprecated_function' )->justReturn( null );

		global $wpdb;
		$wpdb = Mockery::mock( 'wpdb' )->makePartial();
		$wpdb->prefix = 'wp_';
		$wpdb->last_error = '';
		$this->wpdb = $wpdb;

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();

		// Default stubs — overridden per test as needed
		Functions\when( 'wp_clear_scheduled_hook' )->justReturn( 0 );
		Functions\when( 'wp_unschedule_hook' )->justReturn( 0 );
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\when( 'wp_unschedule_event' )->justReturn( true );
		Functions\when( 'flush_rewrite_rules' )->justReturn( null );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_die' )->alias( function ( $msg ) {
			throw new \RuntimeException( 'wp_die: ' . $msg );
		});
		Functions\when( 'delete_option' )->justReturn( true );
		Functions\when( 'get_posts' )->justReturn( array() );
		Functions\when( 'wp_delete_post' )->justReturn( null );

		// RequestInput runs for real over $_GET / $_POST, through these.
		Functions\when( 'sanitize_key' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();

		// Default wpdb stubs for uninstall_cleanup
		$this->wpdb->shouldReceive( 'prepare' )->andReturn( 'QUERY' )->byDefault();
		$this->wpdb->shouldReceive( 'query' )->andReturn( 1 )->byDefault();
	}

	protected function tearDown(): void {
		unset( $_POST['confirm_uninstall'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	// ==================================================================
	// deactivate()
	// ==================================================================

	public function test_deactivate_clears_scheduled_hooks(): void {
		$cleared = array();
		Functions\when( 'wp_clear_scheduled_hook' )->alias( function ( $hook ) use ( &$cleared ) {
			$cleared[] = $hook;
		});
		// A single event carries arguments, so it is removed whatever they are.
		Functions\when( 'wp_unschedule_hook' )->alias( function ( $hook ) use ( &$cleared ) {
			$cleared[] = $hook;
		});

		Deactivator::deactivate();

		$this->assertContains( 'ffc_daily_cleanup', $cleared );
		$this->assertContains( 'ffc_self_scheduling_reminder_scan', $cleared );
		$this->assertContains( 'ffc_reregistration_expire', $cleared );
		$this->assertContains( 'ffc_cloudflare_cidr_refresh', $cleared );
		// A pending reminder batch was cleared nowhere until #1538.
		$this->assertContains( 'ffc_reregistration_reminder_batch', $cleared );
		$this->assertContains( 'ffc_reregistration_invitation_batch', $cleared );
		$this->assertContains( 'ffc_audience_notification_batch', $cleared );
		// The date-messages daily send and its pending batches (#1538).
		$this->assertContains( 'ffc_date_messages_daily', $cleared );
		$this->assertContains( 'ffc_date_messages_batch', $cleared );
		$this->assertContains( 'ffc_date_messages_announce', $cleared );
		$this->assertContains( 'ffc_date_messages_digest', $cleared );
		// One rule's daily start (#2336).
		$this->assertContains( 'ffc_date_messages_start', $cleared );
		// The form-cache warming (#1541).
		$this->assertContains( 'ffcertificate_warm_cache_hook', $cleared );
		// The bulk-issue purge of unfinished imports (#1945).
		$this->assertContains( 'ffc_bulk_issue_purge', $cleared );
		$this->assertContains( 'ffc_audience_post_event_invites', $cleared );
		// The old-appointment close-out (#2285).
		$this->assertContains( 'ffc_self_scheduling_closeout', $cleared );
		// The recruitment account batch (#2245).
		$this->assertContains( 'ffc_recruitment_account_link_batch', $cleared );
		// The pre-#2323 names, which an install that skipped the relay may still hold.
		foreach ( \FreeFormCertificate\Deactivator::LEGACY_CRON_HOOKS as $legacy ) {
			$this->assertContains( $legacy, $cleared );
		}
		// The one-shot submission pipeline, under its name and its pre-#1248 one (#2343).
		$this->assertContains( 'ffc_process_submission_async', $cleared );
		$this->assertContains( 'ffcertificate_process_submission_hook', $cleared );
		// The follow-up run of a cleanup its budget cut short (#2340).
		$this->assertContains( 'ffc_activity_log_cleanup_continue', $cleared );
		$this->assertCount( 26, $cleared );
	}

	public function test_deactivate_flushes_rewrite_rules(): void {
		$flushed = false;
		Functions\when( 'flush_rewrite_rules' )->alias( function () use ( &$flushed ) {
			$flushed = true;
		});

		Deactivator::deactivate();

		$this->assertTrue( $flushed );
	}

	// ==================================================================
	// uninstall_cleanup()
	// ==================================================================
}
