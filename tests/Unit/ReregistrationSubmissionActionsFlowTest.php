<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Reregistration\ReregistrationSubmissionActions;

/**
 * Tests for what ReregistrationSubmissionActions does once its guards pass:
 * the write it delegates, the actor it records, and where it redirects. The
 * guard half lives in ReregistrationSubmissionActionsTest.
 *
 * The writer, the reader and the email handler are aliased, which must
 * happen before they autoload, so each test runs in its own process. Every
 * handler ends in `exit` after its redirect; the redirect throws to stand in
 * for it.
 *
 * @covers \FreeFormCertificate\Reregistration\ReregistrationSubmissionActions
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class ReregistrationSubmissionActionsFlowTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var list<string> */
	private array $redirects = array();

	/** @var list<string> Nonce actions verified. */
	private array $nonces = array();

	/** @var Mockery\MockInterface */
	private $writer;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$_GET  = array();
		$_POST = array();

		Functions\when( 'absint' )->alias( static fn ( $v ) => abs( (int) $v ) );
		Functions\when( 'sanitize_text_field' )->alias( 'trim' );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'get_current_user_id' )->justReturn( 8 );
		Functions\when( 'admin_url' )->alias( static fn ( $path = '' ) => 'https://example.com/wp-admin/' . $path );

		$this->nonces = array();
		$nonces       = &$this->nonces;
		Functions\when( 'wp_verify_nonce' )->alias( static function ( $nonce, $action ) use ( &$nonces ) {
			$nonces[] = $action;
			return 'good' === $nonce;
		} );

		$this->redirects = array();
		$redirects       = &$this->redirects;
		Functions\when( 'wp_safe_redirect' )->alias( static function ( $url ) use ( &$redirects ) {
			$redirects[] = $url;
			throw new \RuntimeException( 'redirected' );
		} );

		$this->writer = Mockery::mock( 'alias:FreeFormCertificate\Reregistration\ReregistrationSubmissionWriter' );
	}

	protected function tearDown(): void {
		$_GET  = array();
		$_POST = array();
		Monkey\tearDown();
		parent::tearDown();
	}

	private function runHandler( string $handler ): void {
		try {
			ReregistrationSubmissionActions::$handler();
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'redirected', $e->getMessage() );
		}
	}

	private function single( string $action ): void {
		$_GET = array(
			'action'   => $action,
			'sub_id'   => '21',
			'id'       => '4',
			'_wpnonce' => 'good',
		);
	}

	public function test_approve_records_the_reviewer_and_returns_to_the_list(): void {
		$this->single( 'approve' );
		$this->writer->shouldReceive( 'approve' )->once()->with( 21, 8 );

		$this->runHandler( 'handle_approve' );

		$this->assertSame( array( 'approve_submission_21' ), $this->nonces );
		$this->assertSame( array( 'https://example.com/wp-admin/admin.php?page=ffc-reregistration&view=submissions&id=4&message=approved' ), $this->redirects );
	}

	public function test_reject_records_the_reviewer_and_returns_to_the_list(): void {
		$this->single( 'reject' );
		$this->writer->shouldReceive( 'reject' )->once()->with( 21, 8 );

		$this->runHandler( 'handle_reject' );

		$this->assertSame( array( 'reject_submission_21' ), $this->nonces );
		$this->assertSame( array( 'https://example.com/wp-admin/admin.php?page=ffc-reregistration&view=submissions&id=4&message=rejected' ), $this->redirects );
	}

	public function test_return_to_draft_reopens_the_submission(): void {
		$this->single( 'return_to_draft' );
		$this->writer->shouldReceive( 'return_to_draft' )->once()->with( 21 );

		$this->runHandler( 'handle_return_to_draft' );

		$this->assertSame( array( 'return_to_draft_submission_21' ), $this->nonces );
		$this->assertSame( array( 'https://example.com/wp-admin/admin.php?page=ffc-reregistration&view=submissions&id=4&message=returned_to_draft' ), $this->redirects );
	}

	private function bulk( string $action, array $ids ): void {
		$_POST = array(
			'ffc_action'        => 'bulk_submissions',
			'reregistration_id' => '4',
			'ffc_bulk_nonce'    => 'good',
			'bulk_action'       => $action,
			'submission_ids'    => $ids,
		);
	}

	public function test_bulk_approve_approves_every_selected_id(): void {
		$this->bulk( 'approve', array( '3', '5' ) );
		$this->writer->shouldReceive( 'bulk_approve' )->once()->with( array( 3, 5 ), 8 );

		$this->runHandler( 'handle_bulk' );

		$this->assertSame( array( 'bulk_submissions_4' ), $this->nonces );
		$this->assertStringEndsWith( '&message=bulk_approved', $this->redirects[0] );
	}

	public function test_bulk_return_to_draft_reopens_every_selected_id(): void {
		$this->bulk( 'return_to_draft', array( '3' ) );
		$this->writer->shouldReceive( 'bulk_return_to_draft' )->once()->with( array( 3 ) );

		$this->runHandler( 'handle_bulk' );

		$this->assertStringEndsWith( '&message=bulk_returned_to_draft', $this->redirects[0] );
	}

	public function test_bulk_reminder_reaches_the_owners_of_the_submissions_that_exist(): void {
		$this->bulk( 'send_reminder', array( '3', '9' ) );
		$reader = Mockery::mock( 'alias:FreeFormCertificate\Reregistration\ReregistrationSubmissionReader' );
		$reader->shouldReceive( 'get_by_id' )->with( 3 )->andReturn( (object) array( 'user_id' => '50' ) );
		$reader->shouldReceive( 'get_by_id' )->with( 9 )->andReturn( null );
		Mockery::mock( 'alias:FreeFormCertificate\Reregistration\ReregistrationEmailHandler' )
			->shouldReceive( 'send_reminders' )->once()->with( 4, array( 50 ) )->andReturn( 1 );

		$this->runHandler( 'handle_bulk' );

		$this->assertSame(
			array( 'https://example.com/wp-admin/admin.php?page=ffc-reregistration&view=submissions&id=4&message=reminders_sent&count=1' ),
			$this->redirects
		);
	}

	public function test_bulk_with_an_unknown_action_does_nothing(): void {
		$this->bulk( 'delete_everything', array( '3' ) );
		$this->writer->shouldNotReceive( 'bulk_approve', 'bulk_return_to_draft' );

		$this->runHandler( 'handle_bulk' );

		$this->assertSame( array( 'bulk_submissions_4' ), $this->nonces );
		$this->assertSame( array(), $this->redirects );
	}
}
