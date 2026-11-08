<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FreeFormCertificate\Core\NoCache;
use FreeFormCertificate\Frontend\PersonalPageCache;
use PHPUnit\Framework\TestCase;

/**
 * A page that shows one visitor's schedule exception or invitation stays out
 * of page caches (#2341), through the one no-cache helper the dashboard and
 * the scheduling shortcode share.
 *
 * @covers \FreeFormCertificate\Frontend\PersonalPageCache
 * @covers \FreeFormCertificate\Core\NoCache
 */
class PersonalPageCacheTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		$_COOKIE = array();
		unset( $_GET['ffc_invite'] );
	}

	protected function tearDown(): void {
		$_COOKIE = array();
		unset( $_GET['ffc_invite'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_init_hooks_template_redirect(): void {
		PersonalPageCache::init();

		$this->assertNotFalse( has_action( 'template_redirect', array( PersonalPageCache::class, 'maybe_forbid' ) ) );
	}

	public function test_a_schedule_exception_cookie_carries_personal_state(): void {
		$_COOKIE['ffc_exception_42'] = 'token';

		$this->assertTrue( PersonalPageCache::carries_personal_state() );
	}

	public function test_an_invitation_code_carries_personal_state(): void {
		$_GET['ffc_invite'] = 'abc123';

		$this->assertTrue( PersonalPageCache::carries_personal_state() );
	}

	public function test_an_ordinary_request_carries_none(): void {
		$_COOKIE['wordpress_logged_in_x'] = 'session';
		$_COOKIE['ffc_other']             = '1';

		$this->assertFalse( PersonalPageCache::carries_personal_state() );
	}

	/**
	 * Runs in its own process: NoCache defines the process-wide
	 * DONOTCACHEPAGE constant, whose presence is what this test asserts.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_personal_request_is_marked_not_cacheable_without_headers_once_output_began(): void {
		$_COOKIE['ffc_exception_42'] = 'token';
		Functions\when( 'headers_sent' )->justReturn( true );
		$nocache = 0;
		Functions\when( 'nocache_headers' )->alias(
			function () use ( &$nocache ) {
				++$nocache;
			}
		);
		$reasons = array();
		Functions\when( 'do_action' )->alias(
			function ( $hook, $reason = '' ) use ( &$reasons ) {
				if ( 'litespeed_control_set_nocache' === $hook ) {
					$reasons[] = $reason;
				}
			}
		);

		PersonalPageCache::maybe_forbid();

		$this->assertTrue( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE );
		$this->assertSame( array( 'FFC page shows a schedule exception or an invitation' ), $reasons );
		$this->assertSame( 0, $nocache, 'No header is sent after output began.' );
	}

	/**
	 * Runs in its own process: NoCache defines the process-wide
	 * DONOTCACHEPAGE constant, which must stay undefined here.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_an_ordinary_request_is_left_cacheable(): void {
		$fired = false;
		Functions\when( 'do_action' )->alias(
			function () use ( &$fired ) {
				$fired = true;
			}
		);

		PersonalPageCache::maybe_forbid();

		$this->assertFalse( defined( 'DONOTCACHEPAGE' ) );
		$this->assertFalse( $fired );
	}

	/**
	 * Runs in its own process: NoCache defines the process-wide
	 * DONOTCACHEPAGE constant.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_no_cache_sends_the_wordpress_headers_while_it_still_can(): void {
		Functions\when( 'headers_sent' )->justReturn( false );
		Functions\when( 'do_action' )->justReturn( null );
		$nocache = 0;
		Functions\when( 'nocache_headers' )->alias(
			function () use ( &$nocache ) {
				++$nocache;
			}
		);

		// header() is native and cannot be stubbed; under CLI output has begun,
		// so its warning is silenced and the observable effects are asserted.
		$prev = error_reporting( error_reporting() & ~E_WARNING );
		try {
			NoCache::send( 'test' );
		} finally {
			error_reporting( $prev );
		}

		$this->assertSame( 1, $nocache );
		$this->assertTrue( DONOTCACHEPAGE );
	}
}
