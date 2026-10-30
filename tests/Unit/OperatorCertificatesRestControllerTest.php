<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\API\OperatorCertificatesRestController;
use FreeFormCertificate\Core\Encryption;
use FreeFormCertificate\Repositories\FormRepository;
use FreeFormCertificate\Tests\Support\Identifiers;

/**
 * Tests for OperatorCertificatesRestController (#858): authenticated operator
 * issuance (A1) + PDF (A2).
 *
 * The collaborators run for real -- SubmissionHandler with its repository,
 * PdfGenerator with its renderer, RateLimiter, Capabilities, DataSanitizer,
 * SettingsReader, ActivityLog and Encryption (the bootstrap's key constants)
 * -- over a `$wpdb` double that answers the submission reads from the row a
 * test stores, records every INSERT, and fails on any other query. The rate
 * limiter's counters live in an in-test transient store.
 *
 * @covers \FreeFormCertificate\API\OperatorCertificatesRestController
 */
class OperatorCertificatesRestControllerTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private const OPERATOR = 7;

	private const SUBMISSION = 5;

	/** @var array<int, array<string, mixed>> */
	private array $registered_routes = array();

	/** @var \Mockery\MockInterface&FormRepository */
	private $form_repo;

	/** @var list<string> Capabilities the current user holds. */
	private array $caps = array( 'ffc_manage_certificates' );

	/** @var array<string, mixed> Transients the rate limiter reads and writes. */
	private array $transients = array();

	/** @var list<array<string, mixed>> Rows inserted into the submissions table. */
	private array $inserted = array();

	/** @var list<array<int, mixed>> Arguments of every scheduled single event. */
	private array $scheduled = array();

	/**
	 * What each successive read of the submission row answers: a row, null
	 * for a missing one, or a Throwable to throw. The last entry repeats.
	 *
	 * @var list<array<string, mixed>|\Throwable|null>
	 */
	private array $row_reads = array();

	/** @var int How many times the submission row was read. */
	private int $row_read_count = 0;

	/** @var bool|\Throwable What the submissions INSERT answers: true succeeds, false fails, a Throwable is thrown. */
	private $insert_outcome = true;

	/** @var mixed The `$wpdb` global an earlier test left, restored afterwards. */
	private $wpdb_backup;

	/** @var bool Whether `$wpdb` was set before this test. */
	private bool $had_wpdb = false;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->registered_routes = array();
		$this->caps              = array( 'ffc_manage_certificates' );
		$this->transients        = array();
		$this->inserted          = array();
		$this->scheduled         = array();
		$this->row_reads         = array( $this->stored_row( self::OPERATOR ) );
		$this->row_read_count    = 0;
		$this->insert_outcome    = true;

		Functions\when( 'register_rest_route' )->alias(
			function ( $namespace, $route, $args ) {
				$this->registered_routes[] = array(
					'namespace' => $namespace,
					'route'     => $route,
					'args'      => $args,
				);
			}
		);

		Functions\when( '__' )->returnArg();
		Functions\when( '_x' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'rest_ensure_response' )->returnArg();
		Functions\when( 'is_wp_error' )->alias( static fn( $t ) => $t instanceof \WP_Error );
		Functions\when( 'is_email' )->alias( static fn( $e ) => (bool) filter_var( $e, FILTER_VALIDATE_EMAIL ) );
		Functions\when( 'sanitize_email' )->returnArg();
		Functions\when( 'sanitize_key' )->alias( static fn( $k ) => strtolower( (string) preg_replace( '/[^a-zA-Z0-9_\-]/', '', (string) $k ) ) );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'sanitize_hex_color' )->justReturn( null );
		Functions\when( 'wp_kses' )->alias( static fn( $v ) => (string) $v );
		Functions\when( 'wp_unslash' )->alias( static fn( $v ) => is_string( $v ) ? stripslashes( $v ) : $v );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'wp_rand' )->alias( static fn( $min = 0, $max = 0 ) => random_int( (int) $min, (int) $max ) );
		Functions\when( 'absint' )->alias( static fn( $v ) => abs( (int) $v ) );
		Functions\when( 'home_url' )->alias( static fn( $p = '' ) => 'https://example.com' . $p );
		Functions\when( 'site_url' )->alias( static fn( $p = '' ) => 'https://example.com/' . ltrim( (string) $p, '/' ) );
		Functions\when( 'get_home_url' )->justReturn( 'https://example.com' );
		Functions\when( 'untrailingslashit' )->alias( static fn( $u ) => rtrim( (string) $u, '/' ) );
		Functions\when( 'trailingslashit' )->alias( static fn( $u ) => rtrim( (string) $u, '/' ) . '/' );
		Functions\when( 'wp_parse_url' )->alias( static fn( $url, $component = -1 ) => parse_url( $url, $component ) );
		Functions\when( 'rest_url' )->alias( static fn( $p = '' ) => 'https://example.com/wp-json/' . ltrim( (string) $p, '/' ) );
		Functions\when( 'get_bloginfo' )->justReturn( 'Test Site' );
		Functions\when( 'wp_normalize_path' )->alias( static fn( $p ) => str_replace( '\\', '/', (string) $p ) );
		Functions\when( 'get_template_directory' )->justReturn( '/tmp/theme' );
		Functions\when( 'get_stylesheet_directory' )->justReturn( '/tmp/theme' );
		Functions\when( '_n' )->alias( static fn( $single, $plural, $number ) => 1 === (int) $number ? $single : $plural );
		Functions\when( 'number_format_i18n' )->alias( static fn( $n ) => (string) $n );
		Functions\when( 'wp_timezone' )->alias( static fn() => new \DateTimeZone( 'UTC' ) );
		Functions\when( 'wp_date' )->alias( static fn( $format, $ts = null, $tz = null ) => gmdate( $format, $ts ?? time() ) );
		Functions\when( 'get_current_user_id' )->justReturn( self::OPERATOR );
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( 'current_user_can' )->alias( fn( $cap ) => in_array( $cap, $this->caps, true ) );
		Functions\when( 'get_post' )->justReturn( null );
		Functions\when( 'get_the_title' )->justReturn( 'Field Form' );
		Functions\when( 'get_post_meta' )->alias(
			static function ( $id, $key ) {
				if ( '_ffc_form_config' === $key ) {
					return array( 'pdf_layout' => '<p>{{name}} {{email}}</p>' );
				}
				return '';
			}
		);
		// Debug::is_enabled(), SettingsReader and DateFormatter read options:
		// nothing stored, so debug logging and the activity log are off.
		Functions\when( 'get_option' )->alias( static fn( $key, $default = false ) => 'ffc_settings' === $key ? array() : $default );
		Functions\when( 'get_transient' )->alias( fn( $key ) => $this->transients[ $key ] ?? false );
		Functions\when( 'set_transient' )->alias(
			function ( $key, $value ) {
				$this->transients[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_transient' )->alias(
			function ( $key ) {
				unset( $this->transients[ $key ] );
				return true;
			}
		);
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		Functions\when( 'wp_cache_flush' )->justReturn( true );
		// clear_cache() with no key asks the backend first (#2241); without
		// group flushing it falls back to wp_cache_flush(), stubbed above.
		Functions\when( 'wp_cache_supports' )->justReturn( false );
		// Guarded by function_exists() in the handler: stubbed so the branch is
		// chosen here, never inherited from an earlier test.
		Functions\when( 'wp_schedule_single_event' )->alias(
			function ( $ts, $hook, $args ) {
				$this->scheduled[] = $args;
				return true;
			}
		);

		$_SERVER['REMOTE_ADDR'] = '203.0.113.9';

		$this->form_repo = Mockery::mock( FormRepository::class );
		$this->form_repo->shouldReceive( 'getConfig' )->andReturn( array() )->byDefault();
		$this->form_repo->shouldReceive( 'getFields' )->andReturn( array() )->byDefault();

		$this->had_wpdb    = array_key_exists( 'wpdb', $GLOBALS );
		$this->wpdb_backup = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb']   = $this->wpdb_double();
	}

	protected function tearDown(): void {
		unset( $_SERVER['REMOTE_ADDR'] );
		if ( $this->had_wpdb ) {
			$GLOBALS['wpdb'] = $this->wpdb_backup;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * A `$wpdb` that answers the auth-code uniqueness checks with "free", the
	 * submission read from `$row_reads`, records the submissions INSERT, and
	 * fails the test on any other query.
	 *
	 * @return \Mockery\MockInterface
	 */
	private function wpdb_double() {
		$wpdb             = Mockery::mock( 'wpdb' );
		$wpdb->prefix     = 'wp_';
		$wpdb->insert_id  = 0;
		$wpdb->last_error = '';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			static function ( $sql, ...$args ) {
				$flat = 1 === count( $args ) && is_array( $args[0] ) ? $args[0] : $args;
				return array(
					'sql'  => $sql,
					'args' => $flat,
				);
			}
		);
		$wpdb->shouldReceive( 'get_var' )->andReturnUsing(
			function ( $q ) {
				if ( 'SELECT id FROM %i WHERE auth_code = %s LIMIT 1' === $q['sql']
					|| 'SELECT id FROM %i WHERE validation_code = %s LIMIT 1' === $q['sql'] ) {
					// No other record holds the drawn code.
					return null;
				}
				$this->fail( 'Unexpected get_var(): ' . $q['sql'] );
			}
		);
		$wpdb->shouldReceive( 'get_row' )->andReturnUsing(
			function ( $q ) {
				if ( 'SELECT * FROM %i WHERE id = %d' === $q['sql'] && 'wp_ffc_submissions' === $q['args'][0] ) {
					$index  = min( $this->row_read_count, count( $this->row_reads ) - 1 );
					$answer = $this->row_reads[ $index ];
					++$this->row_read_count;
					if ( $answer instanceof \Throwable ) {
						throw $answer;
					}
					return null !== $answer && self::SUBMISSION === $q['args'][1] ? $answer : null;
				}
				$this->fail( 'Unexpected get_row(): ' . $q['sql'] );
			}
		);
		$wpdb->shouldReceive( 'insert' )->andReturnUsing(
			function ( $table, $data ) use ( $wpdb ) {
				$this->assertSame( 'wp_ffc_submissions', $table, 'Only the submissions table is written.' );
				if ( $this->insert_outcome instanceof \Throwable ) {
					throw $this->insert_outcome;
				}
				if ( false === $this->insert_outcome ) {
					return false;
				}
				$this->inserted[] = $data;
				$wpdb->insert_id  = self::SUBMISSION;
				return 1;
			}
		);
		return $wpdb;
	}

	/**
	 * The stored submission row, its e-mail and extra data encrypted the way
	 * the handler writes them.
	 *
	 * @param int $owner The user the certificate belongs to.
	 * @return array<string, mixed>
	 */
	private function stored_row( int $owner ): array {
		return array(
			'id'              => (string) self::SUBMISSION,
			'form_id'         => '10',
			'user_id'         => (string) $owner,
			'auth_code'       => 'ABCD2345EFGH',
			'submission_date' => '1700000000',
			'status'          => 'publish',
			'magic_token'     => 'mtok',
			'email_encrypted' => Encryption::encrypt( 'maria@example.com' ),
			'data_encrypted'  => Encryption::encrypt( (string) json_encode( array( 'name' => 'Maria' ) ) ),
			'data'            => null,
		);
	}

	/**
	 * Fill the hourly issuance counter for the operator, for this hour and the
	 * next, so the limit holds even when the hour turns during the test.
	 */
	private function exhaust_hourly_issue_limit(): void {
		foreach ( array( time(), time() + 3600 ) as $ts ) {
			$this->transients[ 'ffc_rate_user_' . self::OPERATOR . '_operator_issue_cert_hour_' . gmdate( 'YmdH', $ts ) ] = 300;
		}
	}

	private function controller(): OperatorCertificatesRestController {
		return new OperatorCertificatesRestController( 'ffc/v1', $this->form_repo );
	}

	/**
	 * @param array<string, mixed> $json  JSON body params.
	 * @param array<string, mixed> $query Route/query params.
	 */
	private function request( array $json = array(), array $query = array() ): object {
		$req = Mockery::mock( 'WP_REST_Request' );
		$req->shouldReceive( 'get_json_params' )->andReturn( $json )->byDefault();
		$req->shouldReceive( 'get_param' )->andReturnUsing(
			static function ( $key ) use ( $json, $query ) {
				return $query[ $key ] ?? $json[ $key ] ?? null;
			}
		);
		return $req;
	}

	private function publishedForm(): object {
		return (object) array(
			'ID'          => 10,
			'post_type'   => 'ffc_form',
			'post_status' => 'publish',
			'post_title'  => 'Field Form',
		);
	}

	// ------------------------------------------------------------------
	// Routes + permission callbacks
	// ------------------------------------------------------------------

	public function test_register_routes_registers_issue_and_pdf(): void {
		$this->controller()->register_routes();

		$routes = array_column( $this->registered_routes, 'route' );
		$this->assertContains( '/operator/certificates', $routes );
		$this->assertContains( '/operator/certificates/(?P<id>\d+)/pdf', $routes );

		foreach ( $this->registered_routes as $r ) {
			$this->assertSame( 'ffc/v1', $r['namespace'] );
			$this->assertIsCallable( $r['args']['permission_callback'] );
		}
	}

	public function test_permission_issue_requires_capability(): void {
		$this->caps = array();
		$this->assertFalse( $this->controller()->permission_issue() );

		// The issuance capability alone opens it; so does manage_options.
		$this->caps = array( 'ffc_manage_certificates' );
		$this->assertTrue( $this->controller()->permission_issue() );
		$this->caps = array( 'manage_options' );
		$this->assertTrue( $this->controller()->permission_issue() );
	}

	public function test_permission_read_pdf_requires_login(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( false );
		$this->assertFalse( $this->controller()->permission_read_pdf() );
	}

	// ------------------------------------------------------------------
	// issue_certificate (A1)
	// ------------------------------------------------------------------

	public function test_issue_certificate_happy_path(): void {
		Functions\when( 'get_post' )->justReturn( $this->publishedForm() );

		$resp = $this->controller()->issue_certificate(
			$this->request( array( 'form_id' => 10, 'name' => 'Maria', 'email' => 'maria@example.com' ) )
		);

		$this->assertIsArray( $resp );
		$this->assertTrue( $resp['success'] );
		$this->assertSame( 5, $resp['submission_id'] );
		$this->assertArrayHasKey( 'auth_code', $resp );
		$this->assertStringContainsString( '/operator/certificates/5/pdf', $resp['pdf_endpoint'] );

		// One row written, carrying the code the response announces, the
		// e-mail encrypted, and the async pipeline scheduled for that id.
		$this->assertCount( 1, $this->inserted );
		$this->assertSame( 10, $this->inserted[0]['form_id'] );
		$this->assertSame( 'C-' . implode( '-', str_split( $this->inserted[0]['auth_code'], 4 ) ), $resp['auth_code'] );
		$this->assertSame( 'maria@example.com', Encryption::decrypt( $this->inserted[0]['email_encrypted'] ) );
		$this->assertSame( array( array( 5 ) ), $this->scheduled );
	}

	public function test_issue_certificate_rejects_empty_body(): void {
		$resp = $this->controller()->issue_certificate( $this->request( array() ) );
		$this->assertInstanceOf( \WP_Error::class, $resp );
		$this->assertSame( 'no_data', $resp->get_error_code() );
	}

	public function test_issue_certificate_form_repo_unavailable(): void {
		Functions\when( 'get_post' )->justReturn( $this->publishedForm() );
		// Constructed without a form repository → the null-repo guard trips.
		$ctrl = new OperatorCertificatesRestController( 'ffc/v1', null );
		$resp = $ctrl->issue_certificate( $this->request( array( 'form_id' => 10 ) ) );
		$this->assertInstanceOf( \WP_Error::class, $resp );
		$this->assertSame( 'form_repo_unavailable', $resp->get_error_code() );
	}

	public function test_issue_certificate_validation_failed_for_missing_required_field(): void {
		Functions\when( 'get_post' )->justReturn( $this->publishedForm() );
		$this->form_repo->shouldReceive( 'getFields' )->andReturn(
			array( array( 'name' => 'documento', 'required' => true, 'label' => 'Documento' ) )
		);

		$resp = $this->controller()->issue_certificate( $this->request( array( 'form_id' => 10, 'name' => 'Maria' ) ) );

		$this->assertInstanceOf( \WP_Error::class, $resp );
		$this->assertSame( 'validation_failed', $resp->get_error_code() );
	}

	public function test_issue_certificate_wrong_length_cpf_rf(): void {
		Functions\when( 'get_post' )->justReturn( $this->publishedForm() );
		$resp = $this->controller()->issue_certificate(
			$this->request( array( 'form_id' => 10, 'cpf_rf' => '123' ) )
		);
		$this->assertInstanceOf( \WP_Error::class, $resp );
		$this->assertSame( 'invalid_cpf_rf', $resp->get_error_code() );
	}

	public function test_issue_certificate_accepts_valid_cpf(): void {
		Functions\when( 'get_post' )->justReturn( $this->publishedForm() );
		// 52998224725 is a checksum-valid CPF.
		$cpf  = Identifiers::CPF_A;
		$resp = $this->controller()->issue_certificate(
			$this->request(
				array(
					'form_id' => 10,
					// Punctuated, as an operator types it.
					'cpf_rf'  => substr( $cpf, 0, 3 ) . '.' . substr( $cpf, 3, 3 ) . '.' . substr( $cpf, 6, 3 ) . '-' . substr( $cpf, 9, 2 ),
				)
			)
		);
		$this->assertIsArray( $resp );
		$this->assertTrue( $resp['success'] );
		$this->assertSame( $cpf, Encryption::decrypt( $this->inserted[0]['cpf_encrypted'] ) );
	}

	public function test_issue_certificate_bubbles_process_submission_db_error(): void {
		Functions\when( 'get_post' )->justReturn( $this->publishedForm() );
		// The INSERT fails, so the real handler answers with its WP_Error.
		$this->insert_outcome = false;

		$resp = $this->controller()->issue_certificate( $this->request( array( 'form_id' => 10 ) ) );

		$this->assertInstanceOf( \WP_Error::class, $resp );
		$this->assertSame( 'db_error', $resp->get_error_code() );
	}

	public function test_issue_certificate_wraps_unexpected_exception(): void {
		Functions\when( 'get_post' )->justReturn( $this->publishedForm() );
		// The database layer throws from inside process_submission().
		$this->insert_outcome = new \RuntimeException( 'kaboom' );

		$resp = $this->controller()->issue_certificate( $this->request( array( 'form_id' => 10 ) ) );

		$this->assertInstanceOf( \WP_Error::class, $resp );
		$this->assertSame( 'ffc_internal_error', $resp->get_error_code() );
	}

	public function test_issue_certificate_rejects_missing_form_id(): void {
		$resp = $this->controller()->issue_certificate( $this->request( array( 'name' => 'x' ) ) );
		$this->assertInstanceOf( \WP_Error::class, $resp );
		$this->assertSame( 'missing_form_id', $resp->get_error_code() );
	}

	public function test_issue_certificate_form_not_found(): void {
		Functions\when( 'get_post' )->justReturn( null );
		$resp = $this->controller()->issue_certificate( $this->request( array( 'form_id' => 10 ) ) );
		$this->assertInstanceOf( \WP_Error::class, $resp );
		$this->assertSame( 'form_not_found', $resp->get_error_code() );
	}

	public function test_issue_certificate_form_not_published(): void {
		$draft              = $this->publishedForm();
		$draft->post_status = 'draft';
		Functions\when( 'get_post' )->justReturn( $draft );
		$resp = $this->controller()->issue_certificate( $this->request( array( 'form_id' => 10 ) ) );
		$this->assertInstanceOf( \WP_Error::class, $resp );
		$this->assertSame( 'form_not_published', $resp->get_error_code() );
	}

	public function test_issue_certificate_rate_limited(): void {
		Functions\when( 'get_post' )->justReturn( $this->publishedForm() );
		$this->exhaust_hourly_issue_limit();

		$resp = $this->controller()->issue_certificate( $this->request( array( 'form_id' => 10 ) ) );
		$this->assertInstanceOf( \WP_Error::class, $resp );
		$this->assertSame( 'rate_limited', $resp->get_error_code() );
		$this->assertSame( array(), $this->inserted, 'A limited request writes nothing.' );
	}

	public function test_issue_certificate_invalid_email(): void {
		Functions\when( 'get_post' )->justReturn( $this->publishedForm() );
		$resp = $this->controller()->issue_certificate(
			$this->request( array( 'form_id' => 10, 'email' => 'not-an-email' ) )
		);
		$this->assertInstanceOf( \WP_Error::class, $resp );
		$this->assertSame( 'invalid_email', $resp->get_error_code() );
	}

	public function test_issue_certificate_invalid_cpf(): void {
		Functions\when( 'get_post' )->justReturn( $this->publishedForm() );
		// 11 digits that fail the CPF checksum.
		$resp = $this->controller()->issue_certificate(
			// INVALID ON PURPOSE, which is the case's whole point: one digit off
			// person A's CPF, so it reaches the check-digit arithmetic and fails
			// there rather than being refused on width or repetition.
			$this->request( array( 'form_id' => 10, 'cpf_rf' => Identifiers::CPF_BAD_CHECK_DIGIT ) )
		);
		$this->assertInstanceOf( \WP_Error::class, $resp );
		$this->assertSame( 'invalid_cpf', $resp->get_error_code() );
	}

	// ------------------------------------------------------------------
	// get_certificate_pdf (A2)
	// ------------------------------------------------------------------

	public function test_pdf_returned_for_capability_holder(): void {
		$resp = $this->controller()->get_certificate_pdf( $this->request( array(), array( 'id' => 5 ) ) );

		$this->assertIsArray( $resp );
		$this->assertTrue( $resp['success'] );
		$this->assertSame( 5, $resp['submission_id'] );
		$this->assertArrayHasKey( 'pdf_data', $resp );
		// The real generator rendered the stored, decrypted submission.
		$this->assertStringContainsString( 'Maria', $resp['pdf_data']['html'] );
		$this->assertStringContainsString( 'maria@example.com', $resp['pdf_data']['html'] );
	}

	public function test_pdf_allowed_for_owner_without_capability(): void {
		$this->caps = array();
		// Owner: submission user_id (7) === get_current_user_id() (7).
		$resp = $this->controller()->get_certificate_pdf( $this->request( array(), array( 'id' => 5 ) ) );
		$this->assertIsArray( $resp );
		$this->assertTrue( $resp['success'] );
	}

	public function test_pdf_forbidden_for_non_owner_without_capability(): void {
		$this->caps      = array();
		$this->row_reads = array( $this->stored_row( 999 ) );

		$resp = $this->controller()->get_certificate_pdf( $this->request( array(), array( 'id' => 5 ) ) );
		$this->assertInstanceOf( \WP_Error::class, $resp );
		$this->assertSame( 'forbidden', $resp->get_error_code() );
	}

	public function test_pdf_not_found(): void {
		$this->row_reads = array( null );
		$resp = $this->controller()->get_certificate_pdf( $this->request( array(), array( 'id' => 5 ) ) );
		$this->assertInstanceOf( \WP_Error::class, $resp );
		$this->assertSame( 'certificate_not_found', $resp->get_error_code() );
	}

	public function test_pdf_rejects_invalid_id(): void {
		$resp = $this->controller()->get_certificate_pdf( $this->request( array(), array( 'id' => 0 ) ) );
		$this->assertInstanceOf( \WP_Error::class, $resp );
		$this->assertSame( 'invalid_id', $resp->get_error_code() );
	}

	public function test_pdf_bubbles_generator_error(): void {
		// The row is gone by the time the generator reads it again, so the
		// real generator refuses and its WP_Error reaches the caller.
		$this->row_reads = array( $this->stored_row( self::OPERATOR ), null );
		$resp            = $this->controller()->get_certificate_pdf( $this->request( array(), array( 'id' => 5 ) ) );
		$this->assertInstanceOf( \WP_Error::class, $resp );
		$this->assertSame( 'submission_not_found', $resp->get_error_code() );
	}

	public function test_pdf_wraps_unexpected_exception(): void {
		$this->row_reads = array( new \RuntimeException( 'kaboom' ) );
		$resp = $this->controller()->get_certificate_pdf( $this->request( array(), array( 'id' => 5 ) ) );
		$this->assertInstanceOf( \WP_Error::class, $resp );
		$this->assertSame( 'ffc_internal_error', $resp->get_error_code() );
	}
}
