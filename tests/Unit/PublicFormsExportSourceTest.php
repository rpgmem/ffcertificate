<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Frontend\PublicFormsExportSource;

/**
 * Tests for PublicFormsExportSource: the public download's layered
 * authorization (rate-limit + page nonce + honeypot/CAPTCHA + form-access hash
 * + CPF gate at start; job-scoped nonce + IP-hash fence per batch/download),
 * the owner fields / filters, the keyset page fetch, and the delivery audit
 * row. The job lifecycle it plugs into is tested in BatchedCsvExportTest.
 * Split from the former PublicCsvExporter monolith (issue #772).
 *
 * Runs in separate processes: the source resolves i18n / terminator helpers
 * unqualified inside the Frontend namespace, and once Brain Monkey defines a
 * namespaced function it cannot be undefined — isolating the process keeps
 * those stubs from leaking into sibling Frontend-namespace tests.
 *
 * @covers \FreeFormCertificate\Frontend\PublicFormsExportSource
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class PublicFormsExportSourceTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var PublicFormsExportSource */
	private $source;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		class_exists( '\\FreeFormCertificate\Frontend\PublicFormsExportSource' );

		Functions\when( '__' )->returnArg();
		Functions\when( 'FreeFormCertificate\Frontend\__' )->returnArg();
		Functions\when( 'FreeFormCertificate\Frontend\esc_html__' )->returnArg();

		$ref          = new \ReflectionClass( PublicFormsExportSource::class );
		$this->source = $ref->newInstanceWithoutConstructor();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		unset( $_POST['form_id'], $_SERVER['REMOTE_ADDR'] );
		parent::tearDown();
	}

	/** Inject a mock repository into the private `repository` property. */
	private function set_repository( $repo ): void {
		$prop = new \ReflectionProperty( PublicFormsExportSource::class, 'repository' );
		$prop->setAccessible( true );
		$prop->setValue( $this->source, $repo );
	}

	/** Inject a stub row formatter into the private `row_formatter` property. */
	private function set_row_formatter( $formatter ): void {
		$prop = new \ReflectionProperty( PublicFormsExportSource::class, 'row_formatter' );
		$prop->setAccessible( true );
		$prop->setValue( $this->source, $formatter );
	}

	/** Make wp_send_json_error / wp_die halt (as they do in production). */
	private function stub_terminators(): void {
		Functions\when( 'FreeFormCertificate\Frontend\wp_send_json_error' )->alias(
			static function () {
				throw new \RuntimeException( 'json_error' );
			}
		);
		Functions\when( 'FreeFormCertificate\Frontend\wp_die' )->alias(
			static function () {
				throw new \RuntimeException( 'wp_die' );
			}
		);
	}

	/**
	 * Define a lightweight real PublicCsvDownload shim (with the constants the
	 * source references) before the autoloader can pull in the real class.
	 * `$access`/`$cpf` control the two validators' return values.
	 */
	private function define_public_csv_download_shim( $access, $cpf ): void {
		if ( class_exists( '\\FreeFormCertificate\Frontend\PublicCsvDownload', false ) ) {
			return;
		}
		$GLOBALS['__pcd_access'] = $access;
		$GLOBALS['__pcd_cpf']    = $cpf;
		eval(
			'namespace FreeFormCertificate\Frontend;'
			. ' class PublicCsvDownload {'
			. ' const NONCE_ACTION = "ffc_public_csv_download";'
			. ' const META_COUNT = "_ffc_csv_public_count";'
			. ' public function validate_form_access( $f, $h ) { return $GLOBALS["__pcd_access"]; }'
			. ' public function validate_cpf_requirement( $f, $c ) { return $GLOBALS["__pcd_cpf"]; }'
			. ' }'
		);
	}

	// ==================================================================
	// type()
	// ==================================================================

	public function test_type_is_public_forms(): void {
		$this->assertSame( 'public_forms', $this->source->type() );
	}

	// ==================================================================
	// authorize_start()
	// ==================================================================

	public function test_authorize_start_rejects_when_rate_limited(): void {
		$this->stub_terminators();
		\Mockery::mock( 'alias:FreeFormCertificate\Security\RateLimiter' )
			->shouldReceive( 'check_ip_limit' )
			->andReturn( array( 'allowed' => false, 'message' => 'slow down' ) );
		\Mockery::mock( 'alias:FreeFormCertificate\Core\RequestInput' )
			->shouldReceive( 'get_user_ip' )->andReturn( '1.2.3.4' );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'json_error' );
		$this->source->authorize_start();
	}

	public function test_authorize_start_rejects_on_bad_nonce(): void {
		$this->stub_terminators();
		Functions\when( 'FreeFormCertificate\Frontend\wp_verify_nonce' )->justReturn( false );
		\Mockery::mock( 'alias:FreeFormCertificate\Security\RateLimiter' )
			->shouldReceive( 'check_ip_limit' )->andReturn( array( 'allowed' => true ) );
		\Mockery::mock( 'alias:FreeFormCertificate\Core\RequestInput' )
			->shouldReceive( 'get_user_ip' )->andReturn( '1.2.3.4' )
			->shouldReceive( 'get_post_string' )->andReturn( 'n' );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'json_error' );
		$this->source->authorize_start();
	}

	public function test_authorize_start_rejects_on_security_field_failure(): void {
		$this->stub_terminators();
		Functions\when( 'FreeFormCertificate\Frontend\wp_verify_nonce' )->justReturn( true );
		\Mockery::mock( 'alias:FreeFormCertificate\Security\RateLimiter' )
			->shouldReceive( 'check_ip_limit' )->andReturn( array( 'allowed' => true ) );
		\Mockery::mock( 'alias:FreeFormCertificate\Core\RequestInput' )
			->shouldReceive( 'get_user_ip' )->andReturn( '1.2.3.4' )
			->shouldReceive( 'get_post_string' )->andReturn( 'n' );
		\Mockery::mock( 'alias:FreeFormCertificate\Core\SecurityService' )
			->shouldReceive( 'validate_security_fields' )->andReturn( 'spam detected' );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'json_error' );
		$this->source->authorize_start();
	}

	public function test_authorize_start_rejects_when_form_id_or_hash_empty(): void {
		$this->stub_terminators();
		Functions\when( 'FreeFormCertificate\Frontend\wp_verify_nonce' )->justReturn( true );
		Functions\when( 'absint' )->alias( static function ( $v ) {
			return abs( (int) $v );
		} );
		Functions\when( 'wp_unslash' )->returnArg();
		\Mockery::mock( 'alias:FreeFormCertificate\Security\RateLimiter' )
			->shouldReceive( 'check_ip_limit' )->andReturn( array( 'allowed' => true ) );
		\Mockery::mock( 'alias:FreeFormCertificate\Core\SecurityService' )
			->shouldReceive( 'validate_security_fields' )->andReturn( true );
		// form_id present but hash empty → guard fires.
		\Mockery::mock( 'alias:FreeFormCertificate\Core\RequestInput' )
			->shouldReceive( 'get_user_ip' )->andReturn( '1.2.3.4' )
			->shouldReceive( 'get_post_string' )->andReturn( '' );
		$_POST['form_id'] = 5;

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'json_error' );
		$this->source->authorize_start();
	}

	public function test_authorize_start_rejects_on_form_access_error(): void {
		$this->define_public_csv_download_shim( 'Access denied', null );
		$this->stub_start_gate();

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'json_error' );
		$this->source->authorize_start();
	}

	public function test_authorize_start_rejects_on_cpf_error(): void {
		$this->define_public_csv_download_shim( null, 'CPF required' );
		$this->stub_start_gate();

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'json_error' );
		$this->source->authorize_start();
	}

	public function test_authorize_start_bumps_quota_on_success(): void {
		$this->define_public_csv_download_shim( null, null );
		$this->stub_start_gate();

		$captured = array();
		Functions\when( 'FreeFormCertificate\Frontend\get_post_meta' )->justReturn( 4 );
		Functions\when( 'FreeFormCertificate\Frontend\update_post_meta' )->alias(
			static function ( $id, $key, $value ) use ( &$captured ) {
				$captured = array( $id, $key, $value );
				return true;
			}
		);

		// Should NOT throw — the gate passes end-to-end.
		$this->source->authorize_start();

		$this->assertSame( array( 7, '_ffc_csv_public_count', 5 ), $captured, 'quota incremented from 4 → 5' );
	}

	/**
	 * Prime the shared authorize_start() gate up to the business-rule step:
	 * rate-limit ok, nonce ok, security ok, form_id + hash present.
	 */
	private function stub_start_gate(): void {
		$this->stub_terminators();
		Functions\when( 'FreeFormCertificate\Frontend\wp_verify_nonce' )->justReturn( true );
		Functions\when( 'absint' )->alias( static function ( $v ) {
			return abs( (int) $v );
		} );
		Functions\when( 'wp_unslash' )->returnArg();
		\Mockery::mock( 'alias:FreeFormCertificate\Security\RateLimiter' )
			->shouldReceive( 'check_ip_limit' )->andReturn( array( 'allowed' => true ) );
		\Mockery::mock( 'alias:FreeFormCertificate\Core\SecurityService' )
			->shouldReceive( 'validate_security_fields' )->andReturn( true );
		\Mockery::mock( 'alias:FreeFormCertificate\Core\RequestInput' )
			->shouldReceive( 'get_user_ip' )->andReturn( '203.0.113.9' )
			->shouldReceive( 'get_post_string' )->andReturnUsing(
				static function ( $key ) {
					$map = array( 'hash' => 'abc', 'cpf' => '123.456.789-00', '_ffc_pcd_nonce' => 'n' );
					return $map[ $key ] ?? '';
				}
			);
		$_POST['form_id'] = 7;
	}

	// ==================================================================
	// authorize_batch()
	// ==================================================================

	public function test_authorize_batch_rejects_on_bad_nonce(): void {
		$this->stub_terminators();
		Functions\when( 'FreeFormCertificate\Frontend\wp_verify_nonce' )->justReturn( false );
		\Mockery::mock( 'alias:FreeFormCertificate\Core\RequestInput' )
			->shouldReceive( 'get_post_string' )->andReturn( 'jid' )
			->shouldReceive( 'get_user_ip' )->andReturn( '1.2.3.4' );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'json_error' );
		$this->source->authorize_batch( array( 'ip_hash' => 'x' ) );
	}

	public function test_authorize_batch_rejects_on_ip_mismatch(): void {
		$this->stub_terminators();
		Functions\when( 'FreeFormCertificate\Frontend\wp_verify_nonce' )->justReturn( true );
		\Mockery::mock( 'alias:FreeFormCertificate\Core\RequestInput' )
			->shouldReceive( 'get_post_string' )->andReturn( 'jid' )
			->shouldReceive( 'get_user_ip' )->andReturn( '9.9.9.9' );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'json_error' );
		$this->source->authorize_batch( array( 'ip_hash' => 'no-match' ) );
	}

	public function test_authorize_batch_passes_on_match(): void {
		$this->stub_terminators();
		Functions\when( 'FreeFormCertificate\Frontend\wp_verify_nonce' )->justReturn( true );
		\Mockery::mock( 'alias:FreeFormCertificate\Core\RequestInput' )
			->shouldReceive( 'get_post_string' )->andReturn( 'jid' )
			->shouldReceive( 'get_user_ip' )->andReturn( '203.0.113.7' );

		// No throw → passed.
		$this->source->authorize_batch( array( 'ip_hash' => sha1( '203.0.113.7' ) ) );
		$this->assertTrue( true );
	}

	// ==================================================================
	// authorize_download()
	// ==================================================================

	public function test_authorize_download_rejects_on_bad_nonce(): void {
		$this->stub_terminators();
		Functions\when( 'FreeFormCertificate\Frontend\wp_verify_nonce' )->justReturn( false );
		\Mockery::mock( 'alias:FreeFormCertificate\Core\RequestInput' )
			->shouldReceive( 'get_get_string' )->andReturn( 'jid' )
			->shouldReceive( 'get_user_ip' )->andReturn( '1.2.3.4' );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'wp_die' );
		$this->source->authorize_download( array( 'ip_hash' => 'x' ) );
	}

	public function test_authorize_download_rejects_on_ip_mismatch(): void {
		$this->stub_terminators();
		Functions\when( 'FreeFormCertificate\Frontend\wp_verify_nonce' )->justReturn( true );
		\Mockery::mock( 'alias:FreeFormCertificate\Core\RequestInput' )
			->shouldReceive( 'get_get_string' )->andReturn( 'jid' )
			->shouldReceive( 'get_user_ip' )->andReturn( '9.9.9.9' );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'wp_die' );
		$this->source->authorize_download( array( 'ip_hash' => 'will-not-match' ) );
	}

	// ==================================================================
	// job_owner_fields() / sanitize_filters()
	// ==================================================================

	public function test_job_owner_fields_hashes_ip_and_strips_cpf(): void {
		Functions\when( 'absint' )->alias( static function ( $v ) {
			return abs( (int) $v );
		} );
		Functions\when( 'wp_unslash' )->returnArg();
		\Mockery::mock( 'alias:FreeFormCertificate\Core\RequestInput' )
			->shouldReceive( 'get_user_ip' )->andReturn( '203.0.113.7' )
			->shouldReceive( 'get_post_string' )->with( 'cpf' )->andReturn( '123.456.789-00' );
		$_POST['form_id'] = 12;

		$fields = $this->source->job_owner_fields();

		$this->assertSame( sha1( '203.0.113.7' ), $fields['ip_hash'] );
		$this->assertSame( 12, $fields['form_id'] );
		$this->assertSame( '12345678900', $fields['cpf_digits'] );
	}

	public function test_sanitize_filters_wraps_single_form(): void {
		Functions\when( 'absint' )->alias( static function ( $v ) {
			return abs( (int) $v );
		} );
		Functions\when( 'wp_unslash' )->returnArg();
		$_POST['form_id'] = 9;

		$filters = $this->source->sanitize_filters();

		$this->assertSame( 9, $filters['form_id'] );
		$this->assertSame( array( 9 ), $filters['form_ids'] );
		$this->assertSame( 'publish', $filters['status'] );
	}

	// ==================================================================
	// count()
	// ==================================================================

	public function test_count_delegates_to_repository(): void {
		$repo = \Mockery::mock( 'FreeFormCertificate\Repositories\SubmissionRepository' );
		$repo->shouldReceive( 'count' )->once()
			->with( array( 'form_id' => 3, 'status' => 'publish' ) )
			->andReturn( 17 );
		$this->set_repository( $repo );

		$this->assertSame( 17, $this->source->count( array( 'form_id' => 3, 'status' => 'publish' ) ) );
	}

	// ==================================================================
	// build_context()
	// ==================================================================

	public function test_build_context_freezes_keys_and_edit_flag(): void {
		$repo = \Mockery::mock( 'FreeFormCertificate\Repositories\SubmissionRepository' );
		$repo->shouldReceive( 'hasEditInfo' )->once()->andReturn( true );
		$this->set_repository( $repo );

		$formatter = \Mockery::mock( 'FreeFormCertificate\Frontend\Csv\PublicCsvRowFormatter' );
		$formatter->shouldReceive( 'scan_dynamic_keys' )->once()->andReturn( array( 'name', 'city' ) );
		$this->set_row_formatter( $formatter );

		$context = $this->source->build_context( array( 'form_ids' => array( 1 ), 'status' => 'publish' ) );

		$this->assertSame( array( 'name', 'city' ), $context['dynamic_keys'] );
		$this->assertTrue( $context['include_edit_columns'] );
	}

	// ==================================================================
	// header() / filename() — apply_filters passthrough
	// ==================================================================

	public function test_header_merges_fixed_and_dynamic_then_filters(): void {
		Functions\when( 'FreeFormCertificate\Frontend\apply_filters' )->alias(
			static function () {
				$a = func_get_args();
				return $a[1] ?? null;
			}
		);
		$formatter = \Mockery::mock( 'FreeFormCertificate\Frontend\Csv\PublicCsvRowFormatter' );
		$formatter->shouldReceive( 'get_fixed_headers' )->once()->with( false )->andReturn( array( 'ID', 'Form' ) );
		$this->set_row_formatter( $formatter );

		$header = $this->source->header(
			array( 'form_ids' => array( 1 ) ),
			array( 'dynamic_keys' => array( 'field_city' ), 'include_edit_columns' => false )
		);

		$this->assertSame( 'ID', $header[0] );
		$this->assertSame( 'Form', $header[1] );
		// build_dynamic_headers (from CsvExportTrait) title-cases the snake key
		// verbatim (no prefix stripping): 'field_city' → 'Field City'.
		$this->assertSame( 'Field City', $header[2] );
	}

	public function test_filename_uses_form_title_and_filters(): void {
		Functions\when( 'FreeFormCertificate\Frontend\get_the_title' )->justReturn( 'My Form' );
		Functions\when( 'FreeFormCertificate\Frontend\gmdate' )->justReturn( '2026-01-15-103000' );
		Functions\when( 'FreeFormCertificate\Frontend\apply_filters' )->alias(
			static function () {
				$a = func_get_args();
				return $a[1] ?? null;
			}
		);
		\Mockery::mock( 'alias:FreeFormCertificate\Core\FilenameHelper' )
			->shouldReceive( 'sanitize_filename' )->andReturnUsing(
				static function ( $s ) {
					return $s;
				}
			);

		$name = $this->source->filename( array( 'form_id' => 3, 'form_ids' => array( 3 ), 'status' => 'publish' ), array() );

		$this->assertStringContainsString( 'My Form', $name );
		$this->assertStringEndsWith( '.csv', $name );
	}

	// ==================================================================
	// fetch_page() / cursor_of() / format_row()
	// ==================================================================

	public function test_fetch_page_delegates_and_filters(): void {
		Functions\when( 'FreeFormCertificate\Frontend\apply_filters' )->alias(
			static function () {
				$a = func_get_args();
				return $a[1] ?? null;
			}
		);
		$rows = array( array( 'id' => 5 ), array( 'id' => 4 ) );
		$repo = \Mockery::mock( 'FreeFormCertificate\Repositories\SubmissionRepository' );
		$repo->shouldReceive( 'getExportBatch' )->once()
			->with( array( 1 ), 'publish', 10, 50 )
			->andReturn( $rows );
		$this->set_repository( $repo );

		$page = $this->source->fetch_page(
			array( 'form_ids' => array( 1 ), 'status' => 'publish' ),
			array(),
			10,
			50
		);
		$this->assertSame( $rows, $page );
	}

	public function test_cursor_of_reads_id(): void {
		$this->assertSame( 42, $this->source->cursor_of( array( 'id' => 42 ) ) );
		$this->assertSame( 0, $this->source->cursor_of( array() ) );
	}

	public function test_format_row_delegates_to_formatter(): void {
		$formatter = \Mockery::mock( 'FreeFormCertificate\Frontend\Csv\PublicCsvRowFormatter' );
		$formatter->shouldReceive( 'format_csv_row' )->once()
			->with( array( 'id' => 1 ), array( 'city' ), true )
			->andReturn( array( 1, 'SP' ) );
		$this->set_row_formatter( $formatter );

		$out = $this->source->format_row(
			array( 'id' => 1 ),
			array( 'dynamic_keys' => array( 'city' ), 'include_edit_columns' => true )
		);
		$this->assertSame( array( 1, 'SP' ), $out );
	}

	// ==================================================================
	// extra_start_response() / on_complete()
	// ==================================================================

	public function test_extra_start_response_returns_batch_nonce(): void {
		Functions\when( 'FreeFormCertificate\Frontend\wp_create_nonce' )->justReturn( 'nonce-batch-xyz' );

		$extra = $this->source->extra_start_response( 'job-1', array() );
		$this->assertSame( 'nonce-batch-xyz', $extra['nonce_batch'] );
	}

	public function test_on_complete_fires_completed_action(): void {
		$captured = array();
		Functions\when( 'FreeFormCertificate\Frontend\do_action' )->alias(
			static function () use ( &$captured ) {
				$captured = func_get_args();
			}
		);

		$job = array( 'file' => '/tmp/x.csv', 'processed' => 12 );
		$this->source->on_complete( 'job-9', $job );

		$this->assertSame( 'ffcertificate_csv_export_completed', $captured[0] );
		$this->assertSame( 'job-9', $captured[1] );
		$this->assertSame( '/tmp/x.csv', $captured[2] );
		$this->assertSame( 12, $captured[3] );
		$this->assertSame( 'public-batch', $captured[4]['mode'] );
	}

	// ==================================================================
	// on_before_download()
	// ==================================================================

	public function test_on_before_download_no_op_when_no_form_id(): void {
		// No form_id → returns early, never constructs the validator.
		$this->source->on_before_download( array() );
		$this->assertTrue( true );
	}

	public function test_on_before_download_writes_delivery_audit_row(): void {
		Functions\when( 'FreeFormCertificate\Frontend\get_post_meta' )->justReturn( 'voluntary' );

		$mock = \Mockery::mock( 'overload:FreeFormCertificate\Frontend\CsvDownloadValidator' );
		$mock->shouldReceive( 'record_download_log_entry' )->once()
			->with( 7, 'voluntary', '12345678900', 'download_delivered' )
			->andReturnNull();

		$this->source->on_before_download(
			array( 'form_id' => 7, 'cpf_digits' => '12345678900' )
		);
	}
}
