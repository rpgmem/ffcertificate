<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Core\Captcha\CaptchaProvider;
use FreeFormCertificate\Core\Encryption;
use FreeFormCertificate\Core\IpAddress;
use FreeFormCertificate\Core\SecurityService;
use FreeFormCertificate\Frontend\PublicCsvDownload;
use FreeFormCertificate\Frontend\PublicFormsExportSource;
use FreeFormCertificate\Repositories\SubmissionReader;
use FreeFormCertificate\Repositories\SubmissionRepository;
use FreeFormCertificate\Security\RateLimitChecker;
use FreeFormCertificate\Tests\Support\Identifiers;

/**
 * Tests for PublicFormsExportSource: the public download's layered
 * authorization (rate-limit + page nonce + honeypot/CAPTCHA + form-access hash
 * + CPF gate at start; job-scoped nonce + IP-hash fence per batch/download),
 * the owner fields / filters, the keyset page fetch, and the delivery audit
 * row. The job lifecycle it plugs into is tested in BatchedCsvExportTest.
 * Split from the former PublicCsvExporter monolith (issue #772).
 *
 * Every collaborator is the real class: RateLimiter (and its IP limiter),
 * RequestInput and ClientIpResolver, SecurityService with the math captcha,
 * PublicCsvDownload and CsvDownloadValidator, Geofence, SubmissionRepository
 * with its reader, PublicCsvRowFormatter, FilenameHelper, DateFormatter and
 * Encryption (with the bootstrap's key constants). Storage is stubbed: post
 * meta and transients live in arrays, nonces are a token per action, and a
 * `$wpdb` double answers each query by its SQL text, failing on any other.
 *
 * @covers \FreeFormCertificate\Frontend\PublicFormsExportSource
 */
class PublicFormsExportSourceTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private const IP = '203.0.113.9';

	private const FORM = 7;

	/** @var PublicFormsExportSource */
	private $source;

	/** @var array<int, array<string, mixed>> Post meta by post id and key. */
	private array $post_meta = array();

	/** @var array<int, string> Post types by post id. */
	private array $post_types = array();

	/** @var array<string, mixed> Transients by key. */
	private array $transients = array();

	/** @var array<string, string> The valid nonce token for each action. */
	private array $nonces = array();

	/** @var list<mixed> Every wp_send_json_error() payload, in order. */
	private array $json_errors = array();

	/** @var list<string> Every wp_die() message, in order. */
	private array $died = array();

	/** @var list<array<int, mixed>> Every do_action() call, as its argument list. */
	private array $actions = array();

	/** @var list<string> The name of every filter applied, in order. */
	private array $filters = array();

	/** @var int How many get_post_meta() calls ran. */
	private int $meta_reads = 0;

	/** @var list<array{0: string, 1: array<int, mixed>}> Every prepare() call: template and bound values. */
	private array $prepared = array();

	/** @var list<array<string, mixed>> Rows the export-batch query answers. */
	private array $batch_rows = array();

	/** @var list<array<string, mixed>> Rows the first key-scan page answers. */
	private array $key_rows = array();

	/** @var string|null What the submission COUNT(*) answers. */
	private ?string $count = null;

	/** @var bool Whether the `edited_at` column exists and holds data. */
	private bool $edited = false;

	/** @var mixed The `$wpdb` global an earlier test left, restored afterwards. */
	private $wpdb_backup;

	/** @var bool Whether `$wpdb` was set before this test. */
	private bool $had_wpdb = false;

	/** @var array<string, mixed> */
	private array $post_backup = array();

	/** @var array<string, mixed> */
	private array $get_backup = array();

	/** @var array<string, mixed> The `$_SERVER` keys the IP resolver reads, as they were. */
	private array $server_backup = array();

	private const SERVER_KEYS = array( 'HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED', 'HTTP_X_CLUSTER_CLIENT_IP', 'HTTP_FORWARDED_FOR', 'HTTP_FORWARDED', 'REMOTE_ADDR' );

	/**
	 * A CPF in its `ddd.ddd.ddd-dd` display form, built from the standard fixture.
	 *
	 * @param string $cpf Eleven digits.
	 * @return string
	 */
	private static function mask_cpf( string $cpf ): string {
		return substr( $cpf, 0, 3 ) . '.' . substr( $cpf, 3, 3 ) . '.' . substr( $cpf, 6, 3 ) . '-' . substr( $cpf, 9, 2 );
	}

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->post_backup = $_POST ?? array();
		$this->get_backup  = $_GET ?? array();
		$_POST             = array();
		$_GET              = array();
		foreach ( self::SERVER_KEYS as $key ) {
			if ( array_key_exists( $key, $_SERVER ) ) {
				$this->server_backup[ $key ] = $_SERVER[ $key ];
				unset( $_SERVER[ $key ] );
			}
		}
		$_SERVER['REMOTE_ADDR'] = self::IP;

		// Static memos the real classes keep across a process.
		$this->reset_static( RateLimitChecker::class, 'settings_cache', null );
		$this->reset_static( SubmissionReader::class, 'column_exists_cache', array() );
		CaptchaProvider::reset();

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'absint' )->alias( static fn( $v ) => abs( (int) $v ) );
		Functions\when( 'wp_parse_args' )->alias( static fn( $args, $defaults ) => array_merge( $defaults, (array) $args ) );
		// Also what Debug::is_enabled() would read; every option is unset here.
		Functions\when( 'get_option' )->alias( static fn( $key, $default = false ) => $default );
		Functions\when( 'wp_salt' )->alias( static fn( $scheme = 'auth' ) => 'test-salt-' . $scheme );
		Functions\when( 'wp_rand' )->alias( static fn( $min, $max ) => random_int( (int) $min, (int) $max ) );
		Functions\when( 'wp_timezone' )->alias( static fn() => new \DateTimeZone( 'UTC' ) );
		Functions\when( 'wp_date' )->alias( static fn( $format, $ts ) => gmdate( (string) $format, (int) $ts ) );
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		Functions\when( 'get_the_title' )->alias( static fn( $id ) => 3 === (int) $id || self::FORM === (int) $id ? 'My Form' : '' );

		$this->post_meta   = array();
		$this->post_types  = array();
		$this->transients  = array();
		$this->nonces      = array();
		$this->json_errors = array();
		$this->died        = array();
		$this->actions     = array();
		$this->filters     = array();
		$this->meta_reads  = 0;
		$this->prepared    = array();
		$this->batch_rows  = array();
		$this->key_rows    = array();
		$this->count       = null;
		$this->edited      = false;

		Functions\when( 'get_post_meta' )->alias(
			function ( $id, $key = '', $single = false ) {
				++$this->meta_reads;
				return $this->post_meta[ (int) $id ][ $key ] ?? '';
			}
		);
		Functions\when( 'update_post_meta' )->alias(
			function ( $id, $key, $value ) {
				$this->post_meta[ (int) $id ][ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'get_post_type' )->alias( fn( $id ) => $this->post_types[ (int) $id ] ?? false );
		Functions\when( 'get_transient' )->alias( fn( $key ) => $this->transients[ $key ] ?? false );
		Functions\when( 'set_transient' )->alias(
			function ( $key, $value ) {
				$this->transients[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'wp_verify_nonce' )->alias(
			fn( $nonce, $action ) => isset( $this->nonces[ $action ] ) && $this->nonces[ $action ] === $nonce ? 1 : false
		);
		Functions\when( 'wp_create_nonce' )->alias( static fn( $action ) => 'nonce-' . $action );
		Functions\when( 'apply_filters' )->alias(
			function ( $name, $value = null ) {
				$this->filters[] = (string) $name;
				return $value;
			}
		);
		Functions\when( 'do_action' )->alias(
			function ( ...$args ) {
				$this->actions[] = $args;
			}
		);
		// wp_send_json_error() and wp_die() end the request in production.
		Functions\when( 'wp_send_json_error' )->alias(
			function ( $payload = null ) {
				$this->json_errors[] = $payload;
				throw new \RuntimeException( 'json_error' );
			}
		);
		Functions\when( 'wp_die' )->alias(
			function ( $message = '' ) {
				$this->died[] = (string) $message;
				throw new \RuntimeException( 'wp_die' );
			}
		);

		$this->install_wpdb();

		$this->source = new PublicFormsExportSource( new SubmissionRepository() );
	}

	protected function tearDown(): void {
		if ( $this->had_wpdb ) {
			$GLOBALS['wpdb'] = $this->wpdb_backup;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		$_POST = $this->post_backup;
		$_GET  = $this->get_backup;
		foreach ( self::SERVER_KEYS as $key ) {
			unset( $_SERVER[ $key ] );
		}
		foreach ( $this->server_backup as $key => $value ) {
			$_SERVER[ $key ] = $value;
		}
		$this->server_backup = array();
		$this->reset_static( RateLimitChecker::class, 'settings_cache', null );
		$this->reset_static( SubmissionReader::class, 'column_exists_cache', array() );
		CaptchaProvider::reset();
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Set a private static property of a real class.
	 *
	 * @param string $class    Class name.
	 * @param string $property Property name.
	 * @param mixed  $value    Value.
	 */
	private function reset_static( string $class, string $property, $value ): void {
		$prop = new \ReflectionProperty( $class, $property );
		$prop->setAccessible( true );
		$prop->setValue( null, $value );
	}

	/**
	 * The `$wpdb` double: prepare() interpolates (so a query reads as SQL)
	 * and records its template and values; each read answers by its text.
	 */
	private function install_wpdb(): void {
		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			function ( $sql, ...$args ) {
				if ( 1 === count( $args ) && is_array( $args[0] ) ) {
					$args = $args[0];
				}
				$this->prepared[] = array( (string) $sql, array_values( $args ) );
				$values           = array_values( $args );
				return preg_replace_callback(
					'/%[ids]/',
					static function ( array $m ) use ( &$values ): string {
						$value = array_shift( $values );
						if ( '%i' === $m[0] ) {
							return '`' . $value . '`';
						}
						return '%d' === $m[0] ? (string) (int) $value : "'" . $value . "'";
					},
					(string) $sql
				);
			}
		);
		$wpdb->shouldReceive( 'get_var' )->andReturnUsing(
			function ( $sql ) {
				$sql = (string) $sql;
				if ( 0 === strpos( $sql, 'SELECT count FROM `wp_ffc_rate_limits`' ) ) {
					return null;
				}
				if ( false !== strpos( $sql, 'INFORMATION_SCHEMA.COLUMNS' ) ) {
					return $this->edited ? '1' : '0';
				}
				if ( 0 === strpos( $sql, 'SELECT 1 FROM `wp_ffc_submissions` WHERE edited_at IS NOT NULL' ) ) {
					return $this->edited ? '1' : null;
				}
				if ( 0 === strpos( $sql, 'SELECT COUNT(*) FROM `wp_ffc_submissions`' ) ) {
					return $this->count;
				}
				$this->fail( 'Unexpected get_var(): ' . $sql );
			}
		);
		$wpdb->shouldReceive( 'get_results' )->andReturnUsing(
			function ( $sql ) {
				$sql = (string) $sql;
				if ( 0 === strpos( $sql, 'SELECT id, data, data_encrypted FROM `wp_ffc_submissions`' ) ) {
					return false !== strpos( $sql, 'id < ' . PHP_INT_MAX ) ? $this->key_rows : array();
				}
				if ( 0 === strpos( $sql, 'SELECT * FROM `wp_ffc_submissions`' ) ) {
					return $this->batch_rows;
				}
				$this->fail( 'Unexpected get_results(): ' . $sql );
			}
		);

		$this->had_wpdb    = array_key_exists( 'wpdb', $GLOBALS );
		$this->wpdb_backup = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb']   = $wpdb;
	}

	/**
	 * The prepare() call whose template contains $needle.
	 *
	 * @return array{0: string, 1: array<int, mixed>}
	 */
	private function preparedWith( string $needle ): array {
		foreach ( $this->prepared as $call ) {
			if ( false !== strpos( $call[0], $needle ) ) {
				return $call;
			}
		}
		$this->fail( "No prepared query contains {$needle}" );
	}

	/**
	 * Run $act, which must end in wp_send_json_error(), and return its payload.
	 *
	 * @param callable $act The call under test.
	 * @return mixed
	 */
	private function json_error_of( callable $act ) {
		try {
			$act();
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'json_error', $e->getMessage() );
			$this->assertCount( 1, $this->json_errors, 'The request ends at the first refusal.' );
			return $this->json_errors[0];
		}
		$this->fail( 'Expected wp_send_json_error().' );
	}

	/**
	 * Run $act, which must end in wp_die(), and return its message.
	 *
	 * @param callable $act The call under test.
	 * @return string
	 */
	private function die_message_of( callable $act ): string {
		try {
			$act();
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'wp_die', $e->getMessage() );
			return $this->died[0];
		}
		$this->fail( 'Expected wp_die().' );
	}

	/**
	 * A solved math challenge, posted as the form posts it.
	 */
	private function post_solved_captcha(): void {
		$captcha                     = SecurityService::generate_simple_captcha();
		$_POST['ffc_captcha_ans']    = (string) $captcha['answer'];
		$_POST['ffc_captcha_hash']   = $captcha['hash'];
		$this->nonces[ PublicCsvDownload::NONCE_ACTION ] = 'page-nonce';
		$_POST['_ffc_pcd_nonce']     = 'page-nonce';
	}

	/**
	 * A form open to the public download: the right post type, the feature on,
	 * the access hash `abc`, ended in 2020, with a quota of 10.
	 *
	 * @param array<string, mixed> $meta Extra meta.
	 */
	private function public_form( array $meta = array() ): void {
		$this->post_types[ self::FORM ] = 'ffc_form';
		$this->post_meta[ self::FORM ]  = array_merge(
			array(
				PublicCsvDownload::META_ENABLED => '1',
				PublicCsvDownload::META_HASH    => 'abc',
				PublicCsvDownload::META_LIMIT   => 10,
				'_ffc_geofence_config'          => array( 'date_end' => '2020-01-01' ),
			),
			$meta
		);
	}

	/**
	 * Prime the shared authorize_start() gate up to the business-rule step:
	 * rate-limit ok, nonce ok, captcha solved, form_id + hash present.
	 */
	private function post_start_gate(): void {
		$this->post_solved_captcha();
		$_POST['form_id'] = (string) self::FORM;
		$_POST['hash']    = 'abc';
		$_POST['cpf']     = self::mask_cpf( Identifiers::CPF_A );
	}

	/**
	 * The download audit ring buffer of the form.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function audit_log(): array {
		$log = $this->post_meta[ self::FORM ][ PublicCsvDownload::META_DOWNLOAD_LOG ] ?? array();
		return is_array( $log ) ? array_values( $log ) : array();
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
		// The hour counter of this address is at the default limit of 5.
		$this->transients[ 'ffc_rate_ip_' . md5( IpAddress::fingerprint( self::IP ) ) . '_hour' ] = 5;
		$this->post_start_gate();

		$payload = $this->json_error_of( fn() => $this->source->authorize_start() );

		$this->assertSame(
			array(
				'message'    => 'Limit reached. Please wait 1 hour.',
				'rate_limit' => true,
			),
			$payload,
			'A denial carries its own message (#2153).'
		);
	}

	public function test_authorize_start_rejects_on_bad_nonce(): void {
		$this->post_start_gate();
		$_POST['_ffc_pcd_nonce'] = 'stale';

		$payload = $this->json_error_of( fn() => $this->source->authorize_start() );

		$this->assertSame( array( 'message' => 'Security check failed. Please refresh the page and try again.' ), $payload );
	}

	public function test_authorize_start_rejects_on_security_field_failure(): void {
		$this->post_start_gate();
		$_POST['ffc_honeypot_trap'] = 'bot';

		$payload = $this->json_error_of( fn() => $this->source->authorize_start() );

		$this->assertSame( 'Security Error: Request blocked (Honeypot).', $payload['message'] );
		$this->assertTrue( $payload['refresh_captcha'], 'A fresh challenge rides on the refusal.' );
		$this->assertNotEmpty( $payload['new_hash'] );
	}

	public function test_authorize_start_rejects_when_form_id_or_hash_empty(): void {
		$this->post_solved_captcha();
		// form_id present but hash empty → guard fires.
		$_POST['form_id'] = '5';

		$payload = $this->json_error_of( fn() => $this->source->authorize_start() );

		$this->assertSame( 'Please inform both the Form ID and the Access Hash.', $payload['message'] );
		$this->assertTrue( $payload['refresh_captcha'] );
	}

	public function test_authorize_start_rejects_on_form_access_error(): void {
		// The post exists but is not a form.
		$this->public_form();
		$this->post_types[ self::FORM ] = 'post';
		$this->post_start_gate();

		$payload = $this->json_error_of( fn() => $this->source->authorize_start() );

		$this->assertSame( 'Form not found.', $payload['message'] );
		$this->assertSame( '', $this->post_meta[ self::FORM ][ PublicCsvDownload::META_COUNT ] ?? '', 'No quota is spent.' );
	}

	public function test_authorize_start_rejects_on_cpf_error(): void {
		$this->public_form(
			array(
				PublicCsvDownload::META_CPF_MODE      => 'whitelist',
				PublicCsvDownload::META_CPF_WHITELIST => Identifiers::CPF_B,
			)
		);
		$this->post_start_gate();

		$payload = $this->json_error_of( fn() => $this->source->authorize_start() );

		$this->assertSame( 'This CPF is not authorized to download this CSV.', $payload['message'] );
		$this->assertSame( 'fail_match', $this->audit_log()[0]['result'] ?? null, 'The refusal is audited.' );
		$this->assertSame( '', $this->post_meta[ self::FORM ][ PublicCsvDownload::META_COUNT ] ?? '', 'No quota is spent.' );
	}

	public function test_authorize_start_bumps_quota_on_success(): void {
		$this->public_form( array( PublicCsvDownload::META_COUNT => 4 ) );
		$this->post_start_gate();

		// Should NOT throw — the gate passes end-to-end.
		$this->source->authorize_start();

		$this->assertSame( array(), $this->json_errors );
		$this->assertSame( 5, $this->post_meta[ self::FORM ][ PublicCsvDownload::META_COUNT ], 'quota incremented from 4 → 5' );
	}

	// ==================================================================
	// authorize_batch()
	// ==================================================================

	public function test_authorize_batch_rejects_on_bad_nonce(): void {
		$this->nonces['ffc_public_csv_batch_jid'] = 'batch-nonce';
		$_POST['job_id']                         = 'jid';
		$_POST['nonce_batch']                    = 'wrong';

		$payload = $this->json_error_of( fn() => $this->source->authorize_batch( array( 'ip_hash' => IpAddress::fingerprint( self::IP ) ) ) );

		$this->assertSame( 'Security check failed.', $payload );
	}

	public function test_authorize_batch_rejects_on_ip_mismatch(): void {
		$this->nonces['ffc_public_csv_batch_jid'] = 'batch-nonce';
		$_POST['job_id']                         = 'jid';
		$_POST['nonce_batch']                    = 'batch-nonce';

		$payload = $this->json_error_of( fn() => $this->source->authorize_batch( array( 'ip_hash' => IpAddress::fingerprint( '9.9.9.9' ) ) ) );

		$this->assertSame( 'Session mismatch.', $payload );
	}

	public function test_authorize_batch_passes_on_match(): void {
		$this->nonces['ffc_public_csv_batch_jid'] = 'batch-nonce';
		$_POST['job_id']                         = 'jid';
		$_POST['nonce_batch']                    = 'batch-nonce';

		$this->source->authorize_batch( array( 'ip_hash' => IpAddress::fingerprint( self::IP ) ) );

		$this->assertSame( array(), $this->json_errors, 'No refusal was sent.' );
	}

	// ==================================================================
	// authorize_download()
	// ==================================================================

	public function test_authorize_download_rejects_on_bad_nonce(): void {
		$this->nonces['ffc_public_csv_batch_jid'] = 'batch-nonce';
		$_GET['job_id']                          = 'jid';
		$_GET['nonce_batch']                     = 'wrong';

		$message = $this->die_message_of( fn() => $this->source->authorize_download( array( 'ip_hash' => IpAddress::fingerprint( self::IP ) ) ) );

		$this->assertSame( 'Security check failed.', $message );
	}

	public function test_authorize_download_rejects_on_ip_mismatch(): void {
		$this->nonces['ffc_public_csv_batch_jid'] = 'batch-nonce';
		$_GET['job_id']                          = 'jid';
		$_GET['nonce_batch']                     = 'batch-nonce';

		$message = $this->die_message_of( fn() => $this->source->authorize_download( array( 'ip_hash' => 'will-not-match' ) ) );

		$this->assertSame( 'Session mismatch.', $message );
	}

	// ==================================================================
	// job_owner_fields() / sanitize_filters()
	// ==================================================================

	public function test_job_owner_fields_hashes_ip_and_strips_cpf(): void {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.7';
		$_POST['cpf']           = self::mask_cpf( Identifiers::CPF_A );
		$_POST['form_id']       = '12';

		$fields = $this->source->job_owner_fields();

		$this->assertSame( IpAddress::fingerprint( '203.0.113.7' ), $fields['ip_hash'] );
		$this->assertSame( 12, $fields['form_id'] );
		$this->assertSame( Identifiers::CPF_A, $fields['cpf_digits'] );
	}

	public function test_sanitize_filters_wraps_single_form(): void {
		$_POST['form_id'] = '9';

		$filters = $this->source->sanitize_filters();

		$this->assertSame( 9, $filters['form_id'] );
		$this->assertSame( array( 9 ), $filters['form_ids'] );
		$this->assertSame( 'publish', $filters['status'] );
	}

	// ==================================================================
	// count()
	// ==================================================================

	public function test_count_delegates_to_repository(): void {
		$this->count = '17';

		$this->assertSame( 17, $this->source->count( array( 'form_id' => 3, 'status' => 'publish' ) ) );

		$this->assertSame( array( 'form_id', 3 ), $this->preparedWith( '%i = %s' )[1] );
		$this->assertSame( 'SELECT COUNT(*) FROM %i WHERE `form_id` = \'3\' AND `status` = \'publish\'', $this->preparedWith( 'SELECT COUNT(*)' )[0] );
	}

	// ==================================================================
	// build_context()
	// ==================================================================

	public function test_build_context_freezes_keys_and_edit_flag(): void {
		$this->edited   = true;
		$this->key_rows = array(
			array( 'id' => '8', 'data' => '{"name":"Ana","city":"SP"}', 'data_encrypted' => null ),
			array( 'id' => '6', 'data' => null, 'data_encrypted' => Encryption::encrypt( '{"city":"RJ"}' ) ),
		);

		$context = $this->source->build_context( array( 'form_ids' => array( 1 ), 'status' => 'publish' ) );

		$this->assertSame( array( 'name', 'city' ), $context['dynamic_keys'] );
		$this->assertTrue( $context['include_edit_columns'] );
		$scans = array_values( array_filter( $this->prepared, static fn( array $call ): bool => 0 === strpos( $call[0], 'SELECT id, data, data_encrypted' ) ) );
		$this->assertCount( 2, $scans, 'The scan stops at the first empty page.' );
		$this->assertSame( array( 'wp_ffc_submissions', 1, 'publish', 6, 500 ), $scans[1][1], 'The scan pages on after the last id it read.' );
	}

	// ==================================================================
	// header() / filename() — apply_filters passthrough
	// ==================================================================

	public function test_header_merges_fixed_and_dynamic_then_filters(): void {
		$header = $this->source->header(
			array( 'form_ids' => array( 1 ) ),
			array( 'dynamic_keys' => array( 'field_city' ), 'include_edit_columns' => false )
		);

		$this->assertSame( array( 'ID', 'Form', 'User ID', 'Submission Date', 'E-mail', 'CPF', 'RF', 'Auth Code' ), array_slice( $header, 0, 8 ) );
		// build_dynamic_headers (from CsvExportTrait) title-cases the snake key
		// verbatim (no prefix stripping): 'field_city' → 'Field City'.
		$this->assertSame( 'Field City', $header[8] );
		$this->assertCount( 9, $header );
		$this->assertSame( array( 'ffc_export_headers' ), $this->filters );
	}

	public function test_filename_uses_form_title_and_filters(): void {
		$name = $this->source->filename( array( 'form_id' => 3, 'form_ids' => array( 3 ), 'status' => 'publish' ), array() );

		// The real FilenameHelper lowercases and dashes the title.
		$this->assertMatchesRegularExpression( '/^my-form-\d{4}-\d{2}-\d{2}-\d{6}\.csv$/', $name );
		$this->assertSame( array( 'ffc_export_filename' ), $this->filters );
	}

	// ==================================================================
	// fetch_page() / cursor_of() / format_row()
	// ==================================================================

	public function test_fetch_page_delegates_and_filters(): void {
		$this->batch_rows = array( array( 'id' => 5 ), array( 'id' => 4 ) );

		$page = $this->source->fetch_page(
			array( 'form_ids' => array( 1 ), 'status' => 'publish' ),
			array(),
			10,
			50
		);

		$this->assertSame( $this->batch_rows, $page );
		$query = $this->preparedWith( 'SELECT * FROM %i' );
		$this->assertStringContainsString( 'id < %d ORDER BY id DESC LIMIT %d', $query[0], 'Keyset, never OFFSET.' );
		$this->assertSame( array( 'wp_ffc_submissions', 1, 'publish', 10, 50 ), $query[1] );
		$this->assertSame( array( 'ffc_export_data' ), $this->filters );
	}

	public function test_cursor_of_reads_id(): void {
		$this->assertSame( 42, $this->source->cursor_of( array( 'id' => 42 ) ) );
		$this->assertSame( 0, $this->source->cursor_of( array() ) );
	}

	public function test_format_row_delegates_to_formatter(): void {
		$out = $this->source->format_row(
			array(
				'id'              => 1,
				'form_id'         => 3,
				'submission_date' => 1767225600,
				'email_encrypted' => Encryption::encrypt( 'ana@example.org' ),
				'data'            => '{"city":"SP"}',
			),
			array( 'dynamic_keys' => array( 'city' ), 'include_edit_columns' => true )
		);

		$this->assertSame( 1, $out[0] );
		$this->assertSame( 'My Form', $out[1] );
		$this->assertSame( 'ana@example.org', $out[4], 'The e-mail is decrypted.' );
		$this->assertSame( array( '', '', '' ), array_slice( $out, 8, 3 ), 'An unedited row has empty edit columns.' );
		$this->assertSame( 'SP', $out[11] );
		$this->assertCount( 12, $out );
	}

	// ==================================================================
	// extra_start_response() / on_complete()
	// ==================================================================

	public function test_extra_start_response_returns_batch_nonce(): void {
		$extra = $this->source->extra_start_response( 'job-1', array() );
		$this->assertSame( 'nonce-ffc_public_csv_batch_job-1', $extra['nonce_batch'] );
	}

	public function test_on_complete_fires_completed_action(): void {
		$job = array( 'file' => '/tmp/x.csv', 'processed' => 12 );
		$this->source->on_complete( 'job-9', $job );

		$this->assertCount( 1, $this->actions );
		$captured = $this->actions[0];
		$this->assertSame( 'ffc_export_completed', $captured[0] );
		$this->assertSame( 'job-9', $captured[1] );
		$this->assertSame( '/tmp/x.csv', $captured[2] );
		$this->assertSame( 12, $captured[3] );
		$this->assertSame( 'public-batch', $captured[4]['mode'] );
	}

	// ==================================================================
	// on_before_download()
	// ==================================================================

	public function test_on_before_download_no_op_when_no_form_id(): void {
		// #1030: past the guard the method builds a CsvDownloadValidator and
		// reads the form's CPF mode through get_post_meta(), so that read never
		// happening is the proof it returned at the guard.
		$this->source->on_before_download( array() );

		$this->assertSame( 0, $this->meta_reads );
		$this->assertSame( array(), $this->post_meta );
	}

	public function test_on_before_download_writes_delivery_audit_row(): void {
		$this->post_meta[ self::FORM ][ PublicCsvDownload::META_CPF_MODE ] = 'voluntary';

		$this->source->on_before_download(
			array( 'form_id' => self::FORM, 'cpf_digits' => Identifiers::CPF_A )
		);

		$log = $this->audit_log();
		$this->assertCount( 1, $log );
		$this->assertSame( 'voluntary', $log[0]['mode'] );
		$this->assertSame( 'download_delivered', $log[0]['result'] );
		$this->assertSame( Identifiers::CPF_A, Encryption::decrypt( $log[0]['cpf_encrypted'] ) );
		$this->assertSame( self::IP, Encryption::decrypt( $log[0]['ip_encrypted'] ) );
	}
}
