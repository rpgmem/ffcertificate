<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Recruitment\RecruitmentAdminActions;

/**
 * Characterization tests for RecruitmentAdminActions::dispatch() — the
 * `?action=…` row-action dispatcher extracted from RecruitmentAdminPage.
 * Each branch validates a nonce, mutates through a repository/service, then
 * redirects back to its canonical tab (and exits). We mock wp_safe_redirect
 * to throw so the trailing `exit;` is never reached, letting us assert both
 * the mutation and the redirect target.
 *
 * @covers \FreeFormCertificate\Recruitment\RecruitmentAdminActions
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class RecruitmentAdminActionsTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'absint' )->alias( static fn( $v ) => (int) $v );
		// dispatch() now gates every write on the manage cap (via Utils).
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'check_admin_referer' )->justReturn( true );
		Functions\when( 'admin_url' )->returnArg();
		Functions\when( 'add_query_arg' )->alias(
			static fn( $args ) => 'admin.php?' . http_build_query( (array) $args )
		);
		// Make the terminal redirect observable without killing the process:
		// throw a marker exception carrying the target URL, skipping `exit;`.
		Functions\when( 'wp_safe_redirect' )->alias(
			static function ( $url ) {
				throw new \RuntimeException( 'REDIRECT:' . $url );
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		unset( $_GET['notice_id'], $_GET['adjutancy_id'], $_GET['reason_id'], $_GET['candidate_id'] );
		parent::tearDown();
	}

	/** Run dispatch() and return the redirect URL captured from the marker exception. */
	private function dispatchCapture( string $action ): string {
		try {
			RecruitmentAdminActions::dispatch( $action );
		} catch ( \RuntimeException $e ) {
			$msg = $e->getMessage();
			if ( 0 === strpos( $msg, 'REDIRECT:' ) ) {
				return substr( $msg, strlen( 'REDIRECT:' ) );
			}
			throw $e;
		}
		return '';
	}

	public function test_delete_notice_deletes_and_redirects_to_notices_tab(): void {
		$_GET['notice_id'] = '42';
		$repo              = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentNoticeWriter' );
		$repo->shouldReceive( 'delete' )->once()->with( 42 );

		$url = $this->dispatchCapture( 'delete-notice' );

		$this->assertStringContainsString( 'tab=notices', $url );
		$this->assertStringContainsString( 'page=ffc-recruitment', $url );
	}

	public function test_dispatch_is_noop_without_manage_cap(): void {
		// 3-state: a read-only viewer (no ffc_manage_recruitment) must not be
		// able to trigger a destructive dispatch action via a crafted URL.
		Functions\when( 'current_user_can' )->justReturn( false );
		$_GET['notice_id'] = '42';
		$repo              = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentNoticeWriter' );
		$repo->shouldNotReceive( 'delete' );

		$url = $this->dispatchCapture( 'delete-notice' );

		$this->assertSame( '', $url );
	}

	public function test_delete_notice_skips_delete_when_id_is_zero_but_still_redirects(): void {
		$_GET['notice_id'] = '0';
		$repo              = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentNoticeWriter' );
		$repo->shouldNotReceive( 'delete' );

		$url = $this->dispatchCapture( 'delete-notice' );

		$this->assertStringContainsString( 'tab=notices', $url );
	}

	public function test_delete_adjutancy_delegates_to_delete_service_and_redirects(): void {
		$_GET['adjutancy_id'] = '7';
		$svc                  = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentDeleteService' );
		$svc->shouldReceive( 'delete_adjutancy' )->once()->with( 7 );

		$url = $this->dispatchCapture( 'delete-adjutancy' );

		$this->assertStringContainsString( 'tab=adjutancies', $url );
	}

	public function test_delete_reason_deletes_only_when_unreferenced(): void {
		$_GET['reason_id'] = '5';
		$repo              = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentReasonRepository' );
		$repo->shouldReceive( 'count_references' )->once()->with( 5 )->andReturn( 0 );
		$repo->shouldReceive( 'delete' )->once()->with( 5 );

		$url = $this->dispatchCapture( 'delete-reason' );

		$this->assertStringContainsString( 'tab=reasons', $url );
	}

	public function test_delete_reason_blocked_when_still_referenced(): void {
		$_GET['reason_id'] = '5';
		$repo              = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentReasonRepository' );
		$repo->shouldReceive( 'count_references' )->once()->with( 5 )->andReturn( 3 );
		$repo->shouldNotReceive( 'delete' );

		$url = $this->dispatchCapture( 'delete-reason' );

		$this->assertStringContainsString( 'tab=reasons', $url );
	}

	public function test_delete_candidate_delegates_to_delete_service_and_redirects(): void {
		$_GET['candidate_id'] = '9';
		$svc                  = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentDeleteService' );
		$svc->shouldReceive( 'delete_candidate' )->once()->with( 9 );

		$url = $this->dispatchCapture( 'delete-candidate' );

		$this->assertStringContainsString( 'tab=candidates', $url );
	}

	public function test_unknown_action_is_a_noop_without_redirect(): void {
		// No matching case → method returns normally, no redirect thrown.
		$this->assertSame( '', $this->dispatchCapture( 'totally-unknown' ) );
	}
}
