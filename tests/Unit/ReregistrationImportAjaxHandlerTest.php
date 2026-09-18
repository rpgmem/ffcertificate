<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Reregistration\ReregistrationImportAjaxHandler;

/**
 * Tests for the import's request boundary (#1214 sprint 5).
 *
 * Three things are pinned here and each is a decision rather than a detail:
 * every phase checks a nonce AND a capability, every phase after the first
 * checks that the job is the caller's, and the upload is refused unless PHP
 * itself says it is an upload.
 *
 * The ownership fence is the one worth stating. Every operator who can import
 * holds the same capability, so the capability alone lets a second one drive a
 * job somebody else staged — commit it away mid-review, or promote a file they
 * have not read. The job id being a random UUID is why that is unlikely by
 * accident and exactly why it is not a defence.
 *
 * The three collaborators are alias mocks, so each test needs a process in
 * which the real class was never loaded. Same annotation the sibling import
 * tests carry.
 *
 * @covers \FreeFormCertificate\Reregistration\ReregistrationImportAjaxHandler
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class ReregistrationImportAjaxHandlerTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var array<string, mixed>|null */
	private ?array $json = null;

	/** @var list<array<int, mixed>> */
	private array $service_calls = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// pcov attribution preload (CLAUDE.md pcov gotcha).
		class_exists( '\FreeFormCertificate\Reregistration\ReregistrationImportAjaxHandler' );

		$this->json          = null;
		$this->service_calls = array();

		Functions\when( '__' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'absint' )->alias( static fn( $v ) => abs( (int) $v ) );
		Functions\when( 'size_format' )->justReturn( '10 MB' );
		Functions\when( 'get_current_user_id' )->justReturn( 5 );

		// `wp_send_json_*` exits in production; here it records and throws, so
		// a handler that carried on past a refusal would be visible instead of
		// silently reaching the next line.
		Functions\when( 'wp_send_json_success' )->alias(
			function ( $data = null ) {
				$this->json = array( 'success' => true, 'data' => $data );
				throw new \RuntimeException( 'sent' );
			}
		);
		Functions\when( 'wp_send_json_error' )->alias(
			function ( $data = null ) {
				$this->json = array( 'success' => false, 'data' => $data );
				throw new \RuntimeException( 'sent' );
			}
		);
	}

	protected function tearDown(): void {
		$_POST   = array();
		$_FILES  = array();
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Run a handler and capture whatever it sent.
	 *
	 * @param string $method Handler method.
	 * @return array<string, mixed> The JSON envelope.
	 */
	private function invoke( string $method ): array {
		$handler = new ReregistrationImportAjaxHandler();
		try {
			$handler->{$method}();
		} catch ( \RuntimeException $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- The throw IS the exit; the envelope is the assertion.
			unset( $e );
		}

		return (array) $this->json;
	}

	/**
	 * Allow the capability, or refuse it — and let the nonce pass.
	 *
	 * The nonce stub lives here rather than in `setUp()` because Brain\Monkey's
	 * `when()` SATISFIES the call, so a later `expect()` on the same function
	 * counts zero and the one test that asserts the nonce is checked would pass
	 * whether or not it is. That test calls `expect()` and skips this helper.
	 *
	 * @param bool $allowed Whether the capability check passes.
	 * @return void
	 */
	private function with_capability( bool $allowed ): void {
		Functions\when( 'check_ajax_referer' )->justReturn( true );

		$caps = Mockery::mock( 'alias:FreeFormCertificate\Core\Capabilities' );
		$caps->shouldReceive( 'current_user_can_admin_or' )->andReturn( $allowed );
	}

	/**
	 * Stand the staging service up with a job of a given owner.
	 *
	 * @param int|null             $owner   Job owner, or null for "no such job".
	 * @param array<string, mixed> $answers Method => return value.
	 * @return void
	 */
	private function with_service( ?int $owner, array $answers = array() ): void {
		$service = Mockery::mock( 'alias:FreeFormCertificate\Reregistration\ReregistrationImportStagingService' );

		$service->shouldReceive( 'get_job' )->andReturnUsing(
			static function () use ( $owner ) {
				return null === $owner ? null : (object) array( 'user_id' => (string) $owner );
			}
		);

		foreach ( array( 'ingest_job', 'validate_job', 'promote_batch', 'commit_job' ) as $method ) {
			$service->shouldReceive( $method )->andReturnUsing(
				function ( ...$args ) use ( $method, $answers ) {
					$this->service_calls[] = array( $method, $args );
					return $answers[ $method ] ?? array( 'ok' => false, 'errors' => array( 'rereg_import_job_not_found' ) );
				}
			);
		}

		Mockery::mock( 'alias:FreeFormCertificate\Reregistration\ReregistrationImportMessages' )
			->shouldReceive( 'job_error' )->andReturnUsing( static fn( string $c ) => 'msg:' . $c )
			->shouldReceive( 'row_error' )->andReturnUsing( static fn( int $l, string $c ) => 'row:' . $l . ':' . $c );
	}

	/** Names of the service methods the handler reached. */
	private function reached(): array {
		return array_column( $this->service_calls, 0 );
	}

	// ==================================================================
	// Authorization — nonce, capability, ownership
	// ==================================================================

	/**
	 * @dataProvider provide_phases
	 * @param string $method Handler method.
	 */
	public function test_every_phase_checks_the_nonce( string $method ): void {
		$caps = Mockery::mock( 'alias:FreeFormCertificate\Core\Capabilities' );
		$caps->shouldReceive( 'current_user_can_admin_or' )->andReturn( true );
		$this->with_service( 5 );

		Functions\expect( 'check_ajax_referer' )
			->once()
			->with( ReregistrationImportAjaxHandler::NONCE_ACTION, 'nonce' )
			->andReturn( true );

		$this->invoke( $method );
	}

	/**
	 * **The request is otherwise complete**, and that is the whole point.
	 *
	 * The first version of this test sent an EMPTY request, so deleting the
	 * capability check left it green — the phase was refused a line later, for
	 * missing a `job_id` or a file. A test that cannot tell which guard refused
	 * is not testing either of them. Here every phase gets input it would
	 * succeed on, so the capability is the only thing left that can say no.
	 *
	 * @dataProvider provide_phases
	 * @param string $method Handler method.
	 */
	public function test_every_phase_refuses_without_the_capability( string $method ): void {
		$this->with_capability( false );
		$this->with_service( 5, $this->happy_answers() );
		$this->given_a_complete_request();

		$envelope = $this->invoke( $method );

		$this->assertFalse( $envelope['success'] );
		$this->assertSame( array(), $this->reached(), 'Nothing of the service may run for a caller without the cap.' );
	}

	/**
	 * Proof that the request above really is complete: with the capability
	 * granted, the same input reaches the service and succeeds. Without this,
	 * the test above could go green on a request that was broken for some other
	 * reason — which is exactly how it was broken before.
	 *
	 * @dataProvider provide_phases
	 * @param string $method Handler method.
	 */
	public function test_the_same_request_succeeds_with_the_capability( string $method ): void {
		$this->with_capability( true );
		$this->with_service( 5, $this->happy_answers() );
		$this->given_a_complete_request();

		$envelope = $this->invoke( $method );

		$this->assertTrue( $envelope['success'] );
		$this->assertNotSame( array(), $this->reached() );
	}

	/**
	 * Input every phase accepts: a campaign, an audience, a real uploaded file
	 * and a job owned by the caller.
	 *
	 * @return void
	 */
	private function given_a_complete_request(): void {
		$_POST['rereg_id']    = '3';
		$_POST['audience_id'] = '7';
		$_POST['job_id']      = 'job-1';

		$tmp = (string) tempnam( sys_get_temp_dir(), 'ffc' );
		file_put_contents( $tmp, "cpf\n111\n" );
		$_FILES['csv_file'] = array(
			'name'     => 'people.csv',
			'tmp_name' => $tmp,
			'size'     => 8,
		);
		Functions\when( 'is_uploaded_file' )->justReturn( true );
	}

	/**
	 * Service answers that let every phase return a success envelope.
	 *
	 * @return array<string, mixed>
	 */
	private function happy_answers(): array {
		return array(
			'ingest_job'    => array( 'ok' => true, 'job_id' => 'job-1', 'total' => 1, 'mapped' => array(), 'ignored' => array() ),
			'validate_job'  => array( 'ok' => true, 'status' => 'validated', 'total' => 1, 'ready' => 1, 'skipped' => 0, 'failed' => 0, 'failures' => array() ),
			'promote_batch' => array( 'ok' => true, 'processed' => 1, 'total' => 1, 'done' => true ),
			'commit_job'    => array( 'ok' => true, 'promoted' => 1, 'skipped' => 0 ),
		);
	}

	/** @return array<string, array{string}> */
	public function provide_phases(): array {
		return array(
			'start'    => array( 'ajax_start' ),
			'validate' => array( 'ajax_validate' ),
			'promote'  => array( 'ajax_promote' ),
			'commit'   => array( 'ajax_commit' ),
		);
	}

	/**
	 * The fence. Same capability, different operator, someone else's job.
	 *
	 * @dataProvider provide_fenced_phases
	 * @param string $method Handler method.
	 */
	public function test_a_phase_refuses_a_job_owned_by_somebody_else( string $method ): void {
		$this->with_capability( true );
		$this->with_service( 99 );
		$_POST['job_id'] = 'job-1';

		$envelope = $this->invoke( $method );

		$this->assertFalse( $envelope['success'] );
		$this->assertSame( 'msg:rereg_import_not_your_job', $envelope['data']['message'] );
		$this->assertSame( array(), $this->reached(), 'The job must not be touched at all.' );
	}

	/**
	 * @dataProvider provide_fenced_phases
	 * @param string $method Handler method.
	 */
	public function test_a_phase_refuses_an_unknown_job( string $method ): void {
		$this->with_capability( true );
		$this->with_service( null );
		$_POST['job_id'] = 'job-gone';

		$envelope = $this->invoke( $method );

		$this->assertFalse( $envelope['success'] );
		$this->assertSame( 'msg:rereg_import_job_not_found', $envelope['data']['message'] );
	}

	/** @return array<string, array{string}> */
	public function provide_fenced_phases(): array {
		return array(
			'validate' => array( 'ajax_validate' ),
			'promote'  => array( 'ajax_promote' ),
			'commit'   => array( 'ajax_commit' ),
		);
	}

	// ==================================================================
	// Phase 1 — the upload
	// ==================================================================

	public function test_start_refuses_a_path_that_is_not_an_upload(): void {
		$this->with_capability( true );
		$this->with_service( 5 );
		$_POST['rereg_id']    = '3';
		$_POST['audience_id'] = '7';
		$_FILES['csv_file']   = array(
			'name'     => 'people.csv',
			'tmp_name' => '/etc/passwd',
			'size'     => 10,
		);
		Functions\when( 'is_uploaded_file' )->justReturn( false );

		$envelope = $this->invoke( 'ajax_start' );

		$this->assertFalse( $envelope['success'] );
		$this->assertSame( array(), $this->reached(), 'A crafted tmp_name must never be read.' );
	}

	public function test_start_refuses_a_file_that_is_not_a_csv(): void {
		$this->with_capability( true );
		$this->with_service( 5 );
		$_POST['rereg_id']    = '3';
		$_POST['audience_id'] = '7';
		$_FILES['csv_file']   = array(
			'name'     => 'payload.php',
			'tmp_name' => '/tmp/upload',
			'size'     => 10,
		);
		Functions\when( 'is_uploaded_file' )->justReturn( true );

		$envelope = $this->invoke( 'ajax_start' );

		$this->assertFalse( $envelope['success'] );
		$this->assertSame( array(), $this->reached() );
	}

	public function test_start_refuses_a_file_over_the_cap(): void {
		$this->with_capability( true );
		$this->with_service( 5 );
		$_POST['rereg_id']    = '3';
		$_POST['audience_id'] = '7';
		$_FILES['csv_file']   = array(
			'name'     => 'huge.csv',
			'tmp_name' => '/tmp/upload',
			'size'     => 999999999,
		);
		Functions\when( 'is_uploaded_file' )->justReturn( true );

		$envelope = $this->invoke( 'ajax_start' );

		$this->assertFalse( $envelope['success'] );
		$this->assertStringContainsString( '10 MB', (string) $envelope['data']['message'] );
		$this->assertSame( array(), $this->reached() );
	}

	public function test_start_refuses_without_a_campaign_and_audience(): void {
		$this->with_capability( true );
		$this->with_service( 5 );

		$envelope = $this->invoke( 'ajax_start' );

		$this->assertFalse( $envelope['success'] );
		$this->assertSame( array(), $this->reached() );
	}

	/**
	 * The missing-column list arrives beside the code, not inside it, so the
	 * handler is what joins the two — and without that join the operator is
	 * told a column is missing but not which.
	 */
	public function test_start_names_the_columns_a_refused_file_lacks(): void {
		$this->with_capability( true );
		$this->with_service(
			5,
			array(
				'ingest_job' => array(
					'ok'               => false,
					'errors'           => array( 'rereg_import_required_column_absent' ),
					'missing_required' => array( 'cpf', 'nome_completo' ),
				),
			)
		);
		$_POST['rereg_id']    = '3';
		$_POST['audience_id'] = '7';
		$_FILES['csv_file']   = array(
			'name'     => 'people.csv',
			'tmp_name' => tempnam( sys_get_temp_dir(), 'ffc' ),
			'size'     => 10,
		);
		file_put_contents( (string) $_FILES['csv_file']['tmp_name'], "cpf\n111\n" );
		Functions\when( 'is_uploaded_file' )->justReturn( true );

		$envelope = $this->invoke( 'ajax_start' );

		$this->assertFalse( $envelope['success'] );
		$this->assertSame(
			'msg:rereg_import_required_column_absent:cpf, nome_completo',
			$envelope['data']['message']
		);
	}

	// ==================================================================
	// The happy paths
	// ==================================================================

	public function test_validate_renders_every_failure_through_the_presenter(): void {
		$this->with_capability( true );
		$this->with_service(
			5,
			array(
				'validate_job' => array(
					'ok'       => false,
					'status'   => 'blocked',
					'total'    => 3,
					'ready'    => 1,
					'skipped'  => 0,
					'failed'   => 2,
					'failures' => array(
						array( 'line' => 3, 'error' => 'rereg_import_no_identifier' ),
						array( 'line' => 5, 'error' => 'field_invalid_number:idade' ),
					),
				),
			)
		);
		$_POST['job_id'] = 'job-1';

		$envelope = $this->invoke( 'ajax_validate' );

		$this->assertTrue( $envelope['success'], 'A blocked report is still a report, not a transport failure.' );
		$this->assertFalse( $envelope['data']['ok'] );
		$this->assertSame(
			array( 'row:3:rereg_import_no_identifier', 'row:5:field_invalid_number:idade' ),
			$envelope['data']['failures'],
			'A raw code on screen is the defect this presenter exists to prevent.'
		);
	}

	public function test_promote_passes_the_progress_through(): void {
		$this->with_capability( true );
		$this->with_service(
			5,
			array(
				'promote_batch' => array( 'ok' => true, 'processed' => 50, 'total' => 120, 'done' => false ),
			)
		);
		$_POST['job_id'] = 'job-1';

		$envelope = $this->invoke( 'ajax_promote' );

		$this->assertTrue( $envelope['success'] );
		$this->assertSame( 50, $envelope['data']['processed'] );
		$this->assertSame( 120, $envelope['data']['total'] );
		$this->assertFalse( $envelope['data']['done'] );
	}

	public function test_commit_reports_what_it_wrote(): void {
		$this->with_capability( true );
		$this->with_service(
			5,
			array( 'commit_job' => array( 'ok' => true, 'promoted' => 40, 'skipped' => 2 ) )
		);
		$_POST['job_id'] = 'job-1';

		$envelope = $this->invoke( 'ajax_commit' );

		$this->assertTrue( $envelope['success'] );
		$this->assertSame( 40, $envelope['data']['promoted'] );
		$this->assertSame( 2, $envelope['data']['skipped'] );
	}

	public function test_register_wires_the_four_actions(): void {
		$handler = new ReregistrationImportAjaxHandler();

		foreach ( array( 'start', 'validate', 'promote', 'commit' ) as $phase ) {
			Functions\expect( 'add_action' )
				->once()
				->with( 'wp_ajax_ffc_rereg_import_' . $phase, Mockery::type( 'array' ) );
		}

		$handler->register();
	}
}
