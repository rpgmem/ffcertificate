<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Reregistration\ReregistrationImportAjaxHandler;
use FreeFormCertificate\Tests\Support\ActivityLogBuffer;

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
 * The collaborators are the real classes — `Capabilities` over a stubbed
 * `current_user_can()`, the staging service and the readers it calls over a
 * `$wpdb` double, and the real message presenter. The double holds one
 * campaign (3) reaching one audience (7), the audience's field definitions,
 * one job header and its staged rows; it records every query and every
 * write, and a query it does not recognise fails the test. "The service was
 * not reached" is therefore "the database saw nothing", and "the job was not
 * touched" is "only its header was read".
 *
 * @covers \FreeFormCertificate\Reregistration\ReregistrationImportAjaxHandler
 */
class ReregistrationImportAjaxHandlerTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** Owner of the job the double holds, unless a test changes it. */
	private const OWNER = 5;

	/** @var array<string, mixed>|null */
	private ?array $json = null;

	/** @var list<string> Every read query the double answered, prepared. */
	private array $reads = array();

	/**
	 * Every write the double received, as `[ verb, table-or-sql, data, where ]`.
	 *
	 * @var list<array{0: string, 1: string, 2: mixed, 3: mixed}>
	 */
	private array $writes = array();

	/** @var object|null The job header `job-1` reads as, or null for no such job. */
	private ?object $job = null;

	/** @var list<object> Field definitions of audience 7. */
	private array $fields = array();

	/** @var list<object> Staged rows the validate phase reads. */
	private array $staged = array();

	/** @var list<object> Unprocessed rows the promote phase reads. */
	private array $batch = array();

	/** @var array{unprocessed: int, ready: int, skipped: int} What the commit phase counts. */
	private array $counts = array(
		'unprocessed' => 0,
		'ready'       => 1,
		'skipped'     => 0,
	);

	/** @var list<string> Capabilities `current_user_can()` grants. */
	private array $granted = array();

	/** @var mixed The `$wpdb` global an earlier test left, restored afterwards. */
	private $wpdb_backup;

	/** @var bool Whether `$wpdb` was set before this test. */
	private bool $had_wpdb = false;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// pcov attribution preload (CLAUDE.md pcov gotcha).
		class_exists( '\FreeFormCertificate\Reregistration\ReregistrationImportAjaxHandler' );

		$this->had_wpdb    = array_key_exists( 'wpdb', $GLOBALS );
		$this->wpdb_backup = $GLOBALS['wpdb'] ?? null;

		$this->json    = null;
		$this->reads   = array();
		$this->writes  = array();
		$this->granted = array();
		$this->job     = $this->job_row( self::OWNER );
		$this->fields  = array( $this->field( 'email', 'text', false ) );
		$this->staged  = array( $this->staged_row( 1, 2, 'person@example.test', array( 'email' => 'person@example.test' ) ) );
		$this->batch   = array();
		$this->counts  = array(
			'unprocessed' => 0,
			'ready'       => 1,
			'skipped'     => 0,
		);

		$GLOBALS['wpdb'] = $this->database();

		// The promote phase flushes the real activity log's buffer; an empty
		// buffer returns before touching the database, whatever ran earlier.
		ActivityLogBuffer::reset();

		Functions\when( '__' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'absint' )->alias( static fn( $v ) => abs( (int) $v ) );
		Functions\when( 'size_format' )->justReturn( '10 MB' );
		Functions\when( 'get_current_user_id' )->justReturn( self::OWNER );
		Functions\when( 'get_option' )->alias( static fn( $name, $fallback = false ) => $fallback );
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'wp_generate_uuid4' )->justReturn( 'job-new' );
		Functions\when( 'is_wp_error' )->alias( static fn( $thing ): bool => $thing instanceof \WP_Error );
		// Nobody in the staged rows has an account yet.
		Functions\when( 'get_user_by' )->justReturn( false );
		Functions\when( 'current_user_can' )->alias(
			fn( $cap ): bool => in_array( $cap, $this->granted, true )
		);

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
		$_POST  = array();
		$_FILES = array();
		if ( $this->had_wpdb ) {
			$GLOBALS['wpdb'] = $this->wpdb_backup;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * A job header as `$wpdb` hands it back.
	 *
	 * @param int $owner Operator who staged it.
	 * @return object
	 */
	private function job_row( int $owner ): object {
		return (object) array(
			'job_id'            => 'job-1',
			'reregistration_id' => '3',
			'audience_id'       => '7',
			// `promoting` is a state the validate, promote and commit phases
			// all accept, so one header serves every phase.
			'status'            => 'promoting',
			'total'             => '1',
			'processed_count'   => '1',
			'user_id'           => (string) $owner,
			'created_at'        => '2026-10-28 10:00:00',
			'updated_at'        => '2026-10-28 10:00:00',
		);
	}

	/**
	 * A field definition of audience 7.
	 *
	 * @param string $key      Field key.
	 * @param string $type     Field type.
	 * @param bool   $required Whether the field is required.
	 * @return object
	 */
	private function field( string $key, string $type, bool $required ): object {
		return (object) array(
			'id'               => (string) ( 100 + count( $this->fields ) ),
			'audience_id'      => '7',
			'field_key'        => $key,
			'field_label'      => ucfirst( $key ),
			'field_type'       => $type,
			'field_options'    => null,
			'validation_rules' => null,
			'is_required'      => $required ? '1' : '0',
			'is_active'        => '1',
		);
	}

	/**
	 * A staged row as the validate phase reads it, with no CPF or RF, so the
	 * only identity it can resolve through is its e-mail.
	 *
	 * @param int                  $id      Staging row id.
	 * @param int                  $line    Line in the file.
	 * @param string               $email   E-mail, or ''.
	 * @param array<string, mixed> $payload Mapped values.
	 * @return object
	 */
	private function staged_row( int $id, int $line, string $email, array $payload ): object {
		return (object) array(
			'id'             => (string) $id,
			'row_no'         => (string) ( $line - 1 ),
			'line_no'        => (string) $line,
			'payload'        => json_encode( $payload ),
			'cpf_normalized' => '',
			'rf_normalized'  => '',
			'email'          => $email,
		);
	}

	/**
	 * The database the staging service and its readers query.
	 *
	 * @return Mockery\MockInterface
	 */
	private function database() {
		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';

		// Interpolates, so the reads below can dispatch on the query's intent.
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			static function ( $sql, ...$args ) {
				$values = ( 1 === count( $args ) && is_array( $args[0] ) ) ? $args[0] : $args;
				foreach ( $values as $v ) {
					$sql = (string) preg_replace( '/%[ids]/', str_replace( '$', '\$', (string) $v ), (string) $sql, 1 );
				}
				return $sql;
			}
		);

		$wpdb->shouldReceive( 'get_row' )->andReturnUsing(
			function ( $sql ) {
				$this->reads[] = (string) $sql;
				if ( str_contains( $sql, 'FROM wp_ffc_reregistration_import_jobs WHERE job_id = job-1' ) ) {
					return $this->job;
				}
				if ( str_contains( $sql, 'FROM wp_ffc_reregistration_import_jobs WHERE job_id = ' ) ) {
					return null;
				}
				if ( str_contains( $sql, 'FROM wp_ffc_audiences WHERE id = 7' ) ) {
					return (object) array(
						'id'        => '7',
						'name'      => 'Teachers',
						'parent_id' => null,
					);
				}
				if ( str_contains( $sql, 'FROM wp_ffc_reregistrations WHERE id = 3' ) ) {
					return (object) array(
						'id'    => '3',
						'title' => 'Campaign',
					);
				}
				$this->fail( 'Unexpected get_row: ' . $sql );
			}
		);

		$wpdb->shouldReceive( 'get_col' )->andReturnUsing(
			function ( $sql ) {
				$this->reads[] = (string) $sql;
				if ( str_contains( $sql, 'FROM wp_ffc_reregistration_audiences WHERE reregistration_id = 3' ) ) {
					return array( '7' );
				}
				$this->fail( 'Unexpected get_col: ' . $sql );
			}
		);

		$wpdb->shouldReceive( 'get_results' )->andReturnUsing(
			function ( $sql ) {
				$this->reads[] = (string) $sql;
				if ( str_contains( $sql, 'FROM wp_ffc_custom_fields WHERE audience_id = 7' ) ) {
					return $this->fields;
				}
				if ( str_contains( $sql, 'FROM wp_ffc_reregistration_import_staging WHERE job_id = job-1 AND processed = 0' ) ) {
					return $this->batch;
				}
				if ( str_contains( $sql, 'FROM wp_ffc_reregistration_import_staging WHERE job_id = job-1' ) ) {
					return $this->staged;
				}
				$this->fail( 'Unexpected get_results: ' . $sql );
			}
		);

		$wpdb->shouldReceive( 'get_var' )->andReturnUsing(
			function ( $sql ) {
				$this->reads[] = (string) $sql;
				if ( ! str_contains( $sql, 'FROM wp_ffc_reregistration_import_staging WHERE job_id = job-1' ) ) {
					$this->fail( 'Unexpected get_var: ' . $sql );
				}
				if ( str_contains( $sql, 'processed = 0' ) ) {
					return (string) $this->counts['unprocessed'];
				}
				return (string) ( str_contains( $sql, 'row_status = ready' ) ? $this->counts['ready'] : $this->counts['skipped'] );
			}
		);

		$wpdb->shouldReceive( 'query' )->andReturnUsing(
			function ( $sql ) {
				$this->writes[] = array( 'query', (string) $sql, null, null );
				return 1;
			}
		);
		foreach ( array( 'insert', 'update', 'delete' ) as $verb ) {
			$wpdb->shouldReceive( $verb )->andReturnUsing(
				function ( $table, $data, $where = null ) use ( $verb ) {
					$this->writes[] = array( $verb, (string) $table, $data, 'delete' === $verb ? $data : $where );
					return 1;
				}
			);
		}

		return $wpdb;
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
	 * Grant the import capability, or withhold it — and let the nonce pass.
	 *
	 * The nonce stub lives here rather than in `setUp()` because Brain\Monkey's
	 * `when()` SATISFIES the call, so a later `expect()` on the same function
	 * counts zero and the one test that asserts the nonce is checked would pass
	 * whether or not it is. That test calls `expect()` and skips this helper.
	 *
	 * `manage_options` is never granted, so the granular capability is the
	 * only thing that can open the gate.
	 *
	 * @param bool $allowed Whether the capability check passes.
	 * @return void
	 */
	private function with_capability( bool $allowed ): void {
		Functions\when( 'check_ajax_referer' )->justReturn( true );

		$this->granted = $allowed ? array( ReregistrationImportAjaxHandler::CAPABILITY ) : array();
	}

	/**
	 * Hand the job header to a different operator, or remove it.
	 *
	 * @param int|null $owner Job owner, or null for "no such job".
	 * @return void
	 */
	private function with_job_owner( ?int $owner ): void {
		$this->job = null === $owner ? null : $this->job_row( $owner );
	}

	/** The handler never reached the staging service: the database saw no read and no write. */
	private function assertDatabaseUntouched( string $message = '' ): void {
		$this->assertSame( array(), $this->reads, $message );
		$this->assertSame( array(), $this->writes, $message );
	}

	/**
	 * The tables written, by verb.
	 *
	 * @return list<string> `verb table` entries, in order.
	 */
	private function written(): array {
		return array_map(
			static fn( array $w ): string => $w[0] . ' ' . ( 'query' === $w[0] ? strtok( $w[1], '(' ) : $w[1] ),
			$this->writes
		);
	}

	// ==================================================================
	// Authorization — nonce, capability, ownership
	// ==================================================================

	/**
	 * @dataProvider provide_phases
	 * @param string $method Handler method.
	 */
	public function test_every_phase_checks_the_nonce( string $method ): void {
		$this->granted = array( ReregistrationImportAjaxHandler::CAPABILITY );

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
		$this->given_a_complete_request();

		$envelope = $this->invoke( $method );

		$this->assertFalse( $envelope['success'] );
		$this->assertSame( 'Permission denied.', $envelope['data']['message'] );
		$this->assertDatabaseUntouched( 'Nothing of the service may run for a caller without the cap.' );
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
		$this->given_a_complete_request();

		$envelope = $this->invoke( $method );

		$this->assertTrue( $envelope['success'] );
		$this->assertNotSame( array(), $this->reads );
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

		$content = "email\nperson@example.test\n";
		$tmp     = (string) tempnam( sys_get_temp_dir(), 'ffc' );
		file_put_contents( $tmp, $content );
		$_FILES['csv_file'] = array(
			'name'     => 'people.csv',
			'tmp_name' => $tmp,
			'size'     => strlen( $content ),
		);
		Functions\when( 'is_uploaded_file' )->justReturn( true );
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
		$this->with_job_owner( 99 );
		$_POST['job_id'] = 'job-1';

		$envelope = $this->invoke( $method );

		$this->assertFalse( $envelope['success'] );
		$this->assertSame( 'This import was started by another user.', $envelope['data']['message'] );
		$this->assertSame(
			array( 'SELECT * FROM wp_ffc_reregistration_import_jobs WHERE job_id = job-1 LIMIT 1' ),
			$this->reads,
			'The job must not be touched at all: its header is read to learn the owner, and nothing else.'
		);
		$this->assertSame( array(), $this->writes );
	}

	/**
	 * @dataProvider provide_fenced_phases
	 * @param string $method Handler method.
	 */
	public function test_a_phase_refuses_an_unknown_job( string $method ): void {
		$this->with_capability( true );
		$this->with_job_owner( null );
		$_POST['job_id'] = 'job-gone';

		$envelope = $this->invoke( $method );

		$this->assertFalse( $envelope['success'] );
		$this->assertSame( 'This import is no longer available. It may have expired — start again.', $envelope['data']['message'] );
		$this->assertSame( array(), $this->writes );
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
		$this->assertDatabaseUntouched( 'A crafted tmp_name must never be read.' );
	}

	public function test_start_refuses_a_file_that_is_not_a_csv(): void {
		$this->with_capability( true );
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
		$this->assertDatabaseUntouched();
	}

	public function test_start_refuses_a_file_over_the_cap(): void {
		$this->with_capability( true );
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
		$this->assertDatabaseUntouched();
	}

	public function test_start_refuses_without_a_campaign_and_audience(): void {
		$this->with_capability( true );

		$envelope = $this->invoke( 'ajax_start' );

		$this->assertFalse( $envelope['success'] );
		$this->assertDatabaseUntouched();
	}

	/**
	 * A complete upload is staged under the caller: the job header names them
	 * as its owner, and the rows go in with it.
	 */
	public function test_start_stages_the_file_under_the_caller(): void {
		$this->with_capability( true );
		$this->given_a_complete_request();

		$envelope = $this->invoke( 'ajax_start' );

		$this->assertTrue( $envelope['success'] );
		$this->assertSame(
			array(
				'jobId'   => 'job-new',
				'total'   => 1,
				'ignored' => array(),
			),
			$envelope['data']
		);

		$headers = array_values( array_filter( $this->writes, static fn( array $w ): bool => 'insert' === $w[0] ) );
		$this->assertCount( 1, $headers );
		$this->assertSame( 'wp_ffc_reregistration_import_jobs', $headers[0][1] );
		$this->assertSame( self::OWNER, $headers[0][2]['user_id'] );
		$this->assertContains( 'query INSERT INTO wp_ffc_reregistration_import_staging ', $this->written() );
	}

	/**
	 * The missing-column list arrives beside the code, not inside it, so the
	 * handler is what joins the two — and without that join the operator is
	 * told a column is missing but not which.
	 */
	public function test_start_names_the_columns_a_refused_file_lacks(): void {
		$this->with_capability( true );
		$this->fields = array(
			$this->field( 'cpf', 'text', true ),
			$this->field( 'nome_completo', 'text', true ),
			$this->field( 'email', 'text', false ),
		);
		$this->given_a_complete_request();

		$envelope = $this->invoke( 'ajax_start' );

		$this->assertFalse( $envelope['success'] );
		$this->assertSame(
			'The file has no column for these required fields: cpf, nome_completo.',
			$envelope['data']['message']
		);
		$this->assertSame( array(), $this->writes, 'A refused file stages nothing.' );
	}

	// ==================================================================
	// The happy paths
	// ==================================================================

	public function test_validate_renders_every_failure_through_the_presenter(): void {
		$this->with_capability( true );
		$this->fields = array(
			$this->field( 'email', 'text', false ),
			$this->field( 'idade', 'number', false ),
		);
		$this->staged = array(
			$this->staged_row( 1, 2, 'ana@example.test', array( 'email' => 'ana@example.test', 'idade' => '30' ) ),
			// No CPF, no RF, no e-mail: nobody to match or create.
			$this->staged_row( 2, 3, '', array( 'email' => '', 'idade' => '41' ) ),
			$this->staged_row( 3, 5, 'bia@example.test', array( 'email' => 'bia@example.test', 'idade' => 'forty' ) ),
		);
		$_POST['job_id'] = 'job-1';

		$envelope = $this->invoke( 'ajax_validate' );

		$this->assertTrue( $envelope['success'], 'A blocked report is still a report, not a transport failure.' );
		$this->assertFalse( $envelope['data']['ok'] );
		$this->assertSame( 'blocked', $envelope['data']['status'] );
		$this->assertSame( array( 3, 1, 0, 2 ), array( $envelope['data']['total'], $envelope['data']['ready'], $envelope['data']['skipped'], $envelope['data']['failed'] ) );
		$this->assertSame(
			array(
				'Line 3: No CPF, RF or e-mail, so there is nobody to match or create.',
				'Line 5: The value in column "idade" is not valid for that field.',
			),
			$envelope['data']['failures'],
			'A raw code on screen is the defect this presenter exists to prevent.'
		);
	}

	public function test_promote_passes_the_progress_through(): void {
		$this->with_capability( true );
		$this->job->processed_count = '49';
		$this->job->total           = '120';
		// A row validate marked `skipped`: counted as processed, written nowhere.
		$this->batch = array(
			(object) array(
				'id'             => '11',
				'line_no'        => '51',
				'payload'        => '{}',
				'cpf_normalized' => '',
				'rf_normalized'  => '',
				'email'          => 'done@example.test',
				'user_id'        => '40',
				'submission_id'  => '60',
				'row_status'     => 'skipped',
			),
		);
		$_POST['job_id'] = 'job-1';

		$envelope = $this->invoke( 'ajax_promote' );

		$this->assertTrue( $envelope['success'] );
		$this->assertSame( 50, $envelope['data']['processed'] );
		$this->assertSame( 120, $envelope['data']['total'] );
		$this->assertFalse( $envelope['data']['done'] );
		$this->assertSame(
			array( 'update wp_ffc_reregistration_import_staging', 'query UPDATE wp_ffc_reregistration_import_jobs SET processed_count = processed_count + 1, updated_at = ' ),
			array_map( static fn( string $w ): string => preg_replace( '/updated_at = .*/', 'updated_at = ', $w ), $this->written() )
		);
	}

	public function test_commit_reports_what_it_wrote(): void {
		$this->with_capability( true );
		$this->counts    = array(
			'unprocessed' => 0,
			'ready'       => 40,
			'skipped'     => 2,
		);
		$_POST['job_id'] = 'job-1';

		$envelope = $this->invoke( 'ajax_commit' );

		$this->assertTrue( $envelope['success'] );
		$this->assertSame( 40, $envelope['data']['promoted'] );
		$this->assertSame( 2, $envelope['data']['skipped'] );
		$this->assertSame(
			array( 'delete wp_ffc_reregistration_import_staging', 'delete wp_ffc_reregistration_import_jobs' ),
			$this->written(),
			'Committing drops the staged cleartext and the header.'
		);
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
