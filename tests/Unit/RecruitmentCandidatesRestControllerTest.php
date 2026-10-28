<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Core\DocumentFormatter;
use FreeFormCertificate\Core\Encryption;
use FreeFormCertificate\Recruitment\RecruitmentCandidatesRestController;
use FreeFormCertificate\Recruitment\RecruitmentPcdHasher;
use FreeFormCertificate\Tests\Support\ActivityLogBuffer;
use FreeFormCertificate\Tests\Support\Identifiers;

/**
 * Tests for the recruitment candidates REST controller: route registration,
 * the reads, the update and delete writes, the audited PII reveal and the
 * candidate's own dashboard payload.
 *
 * Everything behind the controller is the real class: the candidate reader
 * and writer, the classification, notice and call stores, the delete service,
 * the PII access policy, SensitiveFieldRegistry, DataSanitizer,
 * DocumentFormatter, the PCD hasher, the error catalogue, Encryption (with the
 * bootstrap's keys) and ActivityLog (observed through its buffer). They run
 * over a `$wpdb` double that holds rows per table, answers each statement by
 * its interpolated SQL text and records every write; a statement it does not
 * expect fails the test.
 *
 * By default the caller is staff holding the PII capability (`user_can`
 * answers true), not the candidate, whose account is {@see self::OWNER}.
 *
 * @covers \FreeFormCertificate\Recruitment\RecruitmentCandidatesRestController
 */
class RecruitmentCandidatesRestControllerTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** Table names, with the double's `wp_` prefix. */
	private const T_CANDIDATE      = 'wp_ffc_recruitment_candidate';
	private const T_CLASSIFICATION = 'wp_ffc_recruitment_classification';
	private const T_NOTICE         = 'wp_ffc_recruitment_notice';
	private const T_CALL           = 'wp_ffc_recruitment_call';

	/** The candidate's own account. */
	private const OWNER = 12;

	/** The candidate's e-mail. */
	private const EMAIL = 'jane@example.com';

	private RecruitmentCandidatesRestController $controller;

	/** @var array<int, array{namespace: string, route: string, args: array}> */
	private array $registered_routes = array();

	/**
	 * Stored rows, by table and id.
	 *
	 * @var array<string, array<int, object>>
	 */
	private array $rows = array();

	/** @var list<object> What the call-history read answers. */
	private array $history = array();

	/** @var int What the "classifications of this candidate" count answers. */
	private int $classification_count = 0;

	/** @var int|false What `update()` answers. */
	private $update_result = 1;

	/** @var list<array{0: string, 1: string, 2: array<string, mixed>, 3: array<string, mixed>}> Every write, as `[ op, table, data, where ]`. */
	private array $writes = array();

	/** @var list<string> Every read statement, as interpolated. */
	private array $reads = array();

	/** @var list<string> Statements the double does not answer. */
	private array $unexpected = array();

	/** @var mixed The `$wpdb` global an earlier test left, restored afterwards. */
	private $wpdb_backup;

	/** @var bool Whether `$wpdb` was set before this test. */
	private bool $had_wpdb = false;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->had_wpdb    = array_key_exists( 'wpdb', $GLOBALS );
		$this->wpdb_backup = $GLOBALS['wpdb'] ?? null;

		$this->rows                 = array();
		$this->history              = array();
		$this->classification_count = 0;
		$this->update_result        = 1;
		$this->writes               = array();
		$this->reads                = array();
		$this->unexpected           = array();

		ActivityLogBuffer::reset();
		ActivityLogBuffer::stub_functions();

		// Candidate responses ask the PII policy (#1863); by default the
		// caller is staff with the PII cap, not the candidate.
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'user_can' )->justReturn( true );
		Functions\when( 'is_email' )->alias( static fn( $v ) => is_string( $v ) && 1 === substr_count( $v, '@' ) );

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( 'is_user_logged_in' )->justReturn( false );
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		Functions\when( 'wp_salt' )->justReturn( 'test-salt' );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		// The activity log is on, so an audited reveal reaches the buffer.
		Functions\when( 'get_option' )->alias(
			static fn( $key, $default = false ) => 'ffc_settings' === $key ? ActivityLogBuffer::ENABLED_SETTINGS : $default
		);

		$this->registered_routes = array();
		Functions\when( 'register_rest_route' )->alias(
			function ( $namespace, $route, $args ) {
				$this->registered_routes[] = compact( 'namespace', 'route', 'args' );
				return true;
			}
		);

		$GLOBALS['wpdb'] = $this->database();

		$this->controller = new RecruitmentCandidatesRestController();
	}

	protected function assertPostConditions(): void {
		$this->assertSame( array(), $this->unexpected, 'The controller ran a statement the test did not expect.' );
		parent::assertPostConditions();
	}

	protected function tearDown(): void {
		ActivityLogBuffer::reset();
		if ( $this->had_wpdb ) {
			$GLOBALS['wpdb'] = $this->wpdb_backup;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * A `$wpdb` double over the rows a test stores.
	 *
	 * @return Mockery\MockInterface
	 */
	private function database() {
		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';

		// Interpolates, so every read below dispatches on its text.
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			static function ( $sql, ...$args ) {
				$values = ( 1 === count( $args ) && is_array( $args[0] ) ) ? $args[0] : $args;
				foreach ( $values as $v ) {
					$sql = (string) preg_replace_callback( '/%[ids]/', static fn() => (string) $v, (string) $sql, 1 );
				}
				return $sql;
			}
		);

		$wpdb->shouldReceive( 'get_row' )->andReturnUsing(
			function ( $sql ) {
				$sql           = (string) $sql;
				$this->reads[] = $sql;
				if ( 1 === preg_match( '/^SELECT \* FROM (wp_\w+) WHERE id = (\d+)$/', $sql, $m ) ) {
					return $this->rows[ $m[1] ][ (int) $m[2] ] ?? null;
				}
				if ( 1 === preg_match( '/^SELECT \* FROM ' . self::T_CANDIDATE . ' WHERE (cpf|rf)_hash = (\S+) LIMIT 1$/', $sql, $m ) ) {
					foreach ( $this->rows[ self::T_CANDIDATE ] ?? array() as $row ) {
						if ( ( $row->{$m[1] . '_hash'} ?? null ) === $m[2] ) {
							return $row;
						}
					}
					return null;
				}
				$this->unexpected[] = 'get_row: ' . $sql;
				return null;
			}
		);

		$wpdb->shouldReceive( 'get_results' )->andReturnUsing(
			function ( $sql ) {
				$sql           = (string) $sql;
				$this->reads[] = $sql;
				if ( 1 === preg_match( '/^SELECT \* FROM ' . self::T_CANDIDATE . ' WHERE user_id = (\d+)$/', $sql, $m ) ) {
					return array_values(
						array_filter(
							$this->rows[ self::T_CANDIDATE ] ?? array(),
							static fn( $row ) => (int) $row->user_id === (int) $m[1]
						)
					);
				}
				if ( 1 === preg_match( '/^SELECT \* FROM ' . self::T_CLASSIFICATION . ' WHERE candidate_id = (\d+) ORDER BY/', $sql, $m ) ) {
					return array_values(
						array_filter(
							$this->rows[ self::T_CLASSIFICATION ] ?? array(),
							static fn( $row ) => (int) $row->candidate_id === (int) $m[1]
						)
					);
				}
				if ( 0 === strpos( $sql, 'SELECT * FROM ' . self::T_CALL . ' WHERE classification_id IN (' ) ) {
					return $this->history;
				}
				$this->unexpected[] = 'get_results: ' . $sql;
				return array();
			}
		);

		$wpdb->shouldReceive( 'get_var' )->andReturnUsing(
			function ( $sql ) {
				$sql           = (string) $sql;
				$this->reads[] = $sql;
				if ( 1 === preg_match( '/^SELECT COUNT\(\*\) FROM ' . self::T_CLASSIFICATION . ' WHERE candidate_id = \d+$/', $sql ) ) {
					return (string) $this->classification_count;
				}
				$this->unexpected[] = 'get_var: ' . $sql;
				return null;
			}
		);

		$wpdb->shouldReceive( 'update' )->andReturnUsing(
			function ( $table, $data, $where ) {
				$this->writes[] = array( 'update', (string) $table, $data, $where );
				return $this->update_result;
			}
		);
		$wpdb->shouldReceive( 'delete' )->andReturnUsing(
			function ( $table, $where ) {
				$this->writes[] = array( 'delete', (string) $table, array(), $where );
				return 1;
			}
		);

		return $wpdb;
	}

	private function make_request( array $params ): \WP_REST_Request {
		$req = Mockery::mock( 'WP_REST_Request' );
		$req->shouldReceive( 'get_param' )->andReturnUsing( fn( $k ) => $params[ $k ] ?? null );
		$req->shouldReceive( 'get_params' )->andReturn( $params );
		return $req;
	}

	/**
	 * Store a candidate row in the CandidateRow shape, its identifiers
	 * encrypted and hashed as the writer stores them, and a PCD hash that
	 * says "PCD".
	 *
	 * @param array<string,mixed> $overrides Field overrides.
	 * @return object The stored row.
	 */
	private function store_candidate( array $overrides = array() ): object {
		$row = (object) array_merge(
			array(
				'id'              => 5,
				'user_id'         => self::OWNER,
				'name'            => 'Jane Doe',
				'cpf_encrypted'   => Encryption::encrypt( Identifiers::CPF_A ),
				'cpf_hash'        => Encryption::hash( Identifiers::CPF_A ),
				'rf_encrypted'    => Encryption::encrypt( Identifiers::RF_A ),
				'rf_hash'         => Encryption::hash( Identifiers::RF_A ),
				'email_encrypted' => Encryption::encrypt( self::EMAIL ),
				'phone'           => '11999998888',
				'notes'           => 'a note',
				'pcd_hash'        => RecruitmentPcdHasher::compute( 5, true ),
				'created_at'      => '2026-01-01 00:00:00',
				'updated_at'      => '2026-01-02 00:00:00',
			),
			$overrides
		);
		$this->rows[ self::T_CANDIDATE ][ (int) $row->id ] = $row;
		return $row;
	}

	/**
	 * The buffered activity-log entries for one action, as their decoded contexts.
	 *
	 * @param string $action Action.
	 * @return list<mixed>
	 */
	private function logged( string $action ): array {
		$out = array();
		foreach ( ActivityLogBuffer::contexts() as $entry ) {
			if ( $action === $entry['action'] ) {
				$out[] = $entry['context'];
			}
		}
		return $out;
	}

	// ------------------------------------------------------------------
	// register_routes()
	// ------------------------------------------------------------------

	public function test_register_routes_registers_candidate_endpoints(): void {
		$this->controller->register_routes();

		$routes = array_column( $this->registered_routes, 'route' );
		$this->assertContains( '/recruitment/candidates', $routes );
		$this->assertContains( '/recruitment/candidates/(?P<id>\d+)', $routes );
	}

	public function test_register_routes_includes_reveal_pii_endpoint(): void {
		$this->controller->register_routes();

		$routes = array_column( $this->registered_routes, 'route' );
		$this->assertContains( '/recruitment/candidates/(?P<id>\d+)/reveal-pii', $routes );

		// Permission gate must be `check_logged_in` (the policy decides
		// per-request whether the owner-clause applies; the route can't
		// reject upfront on caps alone).
		$reveal_entry = null;
		foreach ( $this->registered_routes as $entry ) {
			if ( '/recruitment/candidates/(?P<id>\d+)/reveal-pii' === $entry['route'] ) {
				$reveal_entry = $entry;
				break;
			}
		}
		$this->assertNotNull( $reveal_entry );
		$perm = $reveal_entry['args']['permission_callback'] ?? null;
		$this->assertIsArray( $perm );
		$this->assertSame( 'check_logged_in', $perm[1] );
	}

	public function test_candidate_reads_accept_view_tier(): void {
		// #739 §2.3 — list + single-item GET are gated on the view tier, so an
		// ffc_view_recruitment operator (not just a manager) can read them.
		$this->controller->register_routes();

		$list = null;
		$item = null;
		foreach ( $this->registered_routes as $entry ) {
			if ( '/recruitment/candidates' === $entry['route'] ) {
				$list = $entry;
			}
			if ( '/recruitment/candidates/(?P<id>\d+)' === $entry['route'] ) {
				$item = $entry;
			}
		}
		$this->assertNotNull( $list );
		$this->assertSame( 'check_can_view_recruitment', $list['args']['permission_callback'][1] );

		$this->assertNotNull( $item );
		$get_perm = null;
		foreach ( $item['args'] as $method_entry ) {
			if ( isset( $method_entry['callback'][1] ) && 'get_candidate' === $method_entry['callback'][1] ) {
				$get_perm = $method_entry['permission_callback'];
			}
		}
		$this->assertNotNull( $get_perm );
		$this->assertSame( 'check_can_view_recruitment', $get_perm[1] );
	}

	public function test_register_routes_includes_the_me_self_endpoint(): void {
		$this->controller->register_routes();

		$routes = array_column( $this->registered_routes, 'route' );
		// The /me/recruitment route gates on is_user_logged_in instead of admin cap.
		$this->assertContains( '/recruitment/me/recruitment', $routes );
	}

	public function test_me_endpoint_uses_check_logged_in_permission_callback(): void {
		$this->controller->register_routes();

		$me_entry = null;
		foreach ( $this->registered_routes as $entry ) {
			if ( '/recruitment/me/recruitment' === $entry['route'] ) {
				$me_entry = $entry;
				break;
			}
		}
		$this->assertNotNull( $me_entry );

		// Walk the args (it's a single endpoint, not a collection).
		$perm = $me_entry['args']['permission_callback'] ?? null;
		$this->assertSame( $this->controller, $perm[0] );
		$this->assertSame( 'check_logged_in', $perm[1] );
	}

	// ------------------------------------------------------------------
	// get_candidate()
	// ------------------------------------------------------------------

	public function test_get_candidate_returns_404_when_not_found(): void {
		// No candidate 999 is stored.
		$result = $this->controller->get_candidate( $this->make_request( array( 'id' => 999 ) ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'recruitment_candidate_not_found', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
	}

	// ------------------------------------------------------------------
	// list_candidates()
	// ------------------------------------------------------------------

	public function test_list_candidates_returns_400_when_no_filter_provided(): void {
		$result = $this->controller->list_candidates( $this->make_request( array() ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'recruitment_candidate_list_requires_filter', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
	}

	// ------------------------------------------------------------------
	// get_candidate() — success
	// ------------------------------------------------------------------

	public function test_get_candidate_returns_shaped_admin_payload(): void {
		$this->store_candidate();

		$response = $this->controller->get_candidate( $this->make_request( array( 'id' => 5 ) ) );

		$this->assertNotInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 5, $data['id'] );
		$this->assertSame( self::OWNER, $data['user_id'] );
		$this->assertSame( 'Jane Doe', $data['name'] );
		// Staff get the identifiers masked and reveal them one at a time
		// through reveal-pii, audited (#1863).
		$this->assertSame( DocumentFormatter::mask_any( 'cpf', Identifiers::CPF_A ), $data['cpf'] );
		$this->assertSame( DocumentFormatter::mask_any( 'rf', Identifiers::RF_A ), $data['rf'] );
		$this->assertNotSame( Identifiers::CPF_A, $data['cpf'] );
		$this->assertNotSame( Identifiers::RF_A, $data['rf'] );
		$this->assertSame( DocumentFormatter::mask_email( self::EMAIL ), $data['email'] );
		$this->assertNotSame( self::EMAIL, $data['email'] );
		$this->assertSame( DocumentFormatter::mask_email( self::EMAIL ), $data['email_masked'] );
		$this->assertTrue( $data['pii_masked'] );
		$this->assertSame( '11999998888', $data['phone'] );
		$this->assertTrue( $data['is_pcd'] );
	}

	public function test_get_candidate_gives_the_candidate_their_own_data_in_clear(): void {
		Functions\when( 'get_current_user_id' )->justReturn( self::OWNER );
		$this->store_candidate();

		$data = $this->controller->get_candidate( $this->make_request( array( 'id' => 5 ) ) )->get_data();

		$this->assertSame( Identifiers::CPF_A, $data['cpf'] );
		$this->assertSame( Identifiers::RF_A, $data['rf'] );
		$this->assertSame( self::EMAIL, $data['email'] );
		$this->assertFalse( $data['pii_masked'] );
	}

	public function test_get_candidate_shapes_null_user_and_empty_encrypted_fields(): void {
		$this->store_candidate(
			array(
				'user_id'         => null,
				'cpf_encrypted'   => '',
				'rf_encrypted'    => null,
				'email_encrypted' => '',
			)
		);

		$data = $this->controller->get_candidate( $this->make_request( array( 'id' => 5 ) ) )->get_data();

		$this->assertNull( $data['user_id'] );
		$this->assertNull( $data['cpf'] );
		$this->assertNull( $data['rf'] );
		$this->assertNull( $data['email'] );
		$this->assertNull( $data['email_masked'] );
	}

	// ------------------------------------------------------------------
	// list_candidates() — cpf / rf filter branches
	// ------------------------------------------------------------------

	public function test_list_candidates_by_cpf_returns_single_shaped_match(): void {
		$this->store_candidate();

		// A formatted CPF is normalised to its digits before it is hashed.
		$response = $this->controller->list_candidates(
			$this->make_request( array( 'cpf' => DocumentFormatter::format_cpf( Identifiers::CPF_A ) ) )
		);

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertCount( 1, $data );
		$this->assertSame( 5, $data[0]['id'] );
	}

	public function test_list_candidates_by_cpf_returns_empty_array_when_no_match(): void {
		$response = $this->controller->list_candidates(
			$this->make_request( array( 'cpf' => Identifiers::CPF_A ) )
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array(), $response->get_data() );
		$this->assertSame(
			array( 'SELECT * FROM ' . self::T_CANDIDATE . ' WHERE cpf_hash = ' . Encryption::hash( Identifiers::CPF_A ) . ' LIMIT 1' ),
			$this->reads
		);
	}

	public function test_list_candidates_by_rf_returns_single_shaped_match(): void {
		$this->store_candidate();

		$response = $this->controller->list_candidates(
			$this->make_request( array( 'rf' => Identifiers::RF_A ) )
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 1, $response->get_data() );
	}

	public function test_list_candidates_falls_through_to_400_when_cpf_normalizes_empty(): void {
		$result = $this->controller->list_candidates(
			$this->make_request( array( 'cpf' => '---' ) )
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'recruitment_candidate_list_requires_filter', $result->get_error_code() );
		$this->assertSame( array(), $this->reads );
	}

	// ------------------------------------------------------------------
	// update_candidate()
	// ------------------------------------------------------------------

	public function test_update_candidate_returns_400_when_no_writable_fields(): void {
		$result = $this->controller->update_candidate(
			$this->make_request( array( 'id' => 5, 'irrelevant' => 'x' ) )
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'recruitment_candidate_update_no_writable_fields', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
		$this->assertSame( array(), $this->writes );
	}

	public function test_update_candidate_returns_409_when_writer_fails(): void {
		$this->update_result = false;

		$result = $this->controller->update_candidate(
			$this->make_request( array( 'id' => 5, 'name' => 'New Name' ) )
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'recruitment_candidate_update_failed', $result->get_error_code() );
		$this->assertSame( 409, $result->get_error_data()['status'] );
		$this->assertCount( 1, $this->writes );
		$this->assertSame( array( 'id' => 5 ), $this->writes[0][3] );
	}

	public function test_update_candidate_success_returns_reshaped_row(): void {
		$this->store_candidate();

		$response = $this->controller->update_candidate(
			$this->make_request( array( 'id' => 5, 'name' => 'New Name', 'phone' => '123', 'notes' => 'n' ) )
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 5, $response->get_data()['id'] );
		// Only the writable plain fields make it through (no cpf/rf/email here).
		$this->assertCount( 1, $this->writes );
		$written = $this->writes[0][2];
		unset( $written['updated_at'] );
		$this->assertSame( array( 'name' => 'New Name', 'phone' => '123', 'notes' => 'n' ), $written );
	}

	public function test_update_candidate_encrypts_sensitive_fields(): void {
		$this->store_candidate();

		$response = $this->controller->update_candidate(
			$this->make_request(
				array(
					'id'    => 5,
					'cpf'   => DocumentFormatter::format_cpf( Identifiers::CPF_A ),
					'rf'    => DocumentFormatter::format_rf( Identifiers::RF_A ),
					'email' => '  Jane@Example.COM ',
				)
			)
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 1, $this->writes );
		$written = $this->writes[0][2];
		// Normalised before encryption: digits only, and the e-mail lowercased.
		$this->assertSame( Identifiers::CPF_A, Encryption::decrypt( $written['cpf_encrypted'] ) );
		$this->assertSame( Identifiers::RF_A, Encryption::decrypt( $written['rf_encrypted'] ) );
		$this->assertSame( self::EMAIL, Encryption::decrypt( $written['email_encrypted'] ) );
		$this->assertSame( Encryption::hash( Identifiers::CPF_A ), $written['cpf_hash'] );
		$this->assertSame( Encryption::hash( Identifiers::RF_A ), $written['rf_hash'] );
		$this->assertSame( Encryption::hash( self::EMAIL ), $written['email_hash'] );
	}

	public function test_update_candidate_success_with_null_row_returns_null_data(): void {
		// No candidate 5 is stored, so the re-read after the write finds nothing.
		$response = $this->controller->update_candidate(
			$this->make_request( array( 'id' => 5, 'name' => 'X' ) )
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertNull( $response->get_data() );
	}

	// ------------------------------------------------------------------
	// delete_candidate()
	// ------------------------------------------------------------------

	public function test_delete_candidate_returns_409_with_blocked_by_on_failure(): void {
		$this->store_candidate();
		$this->classification_count = 3;

		$result = $this->controller->delete_candidate( $this->make_request( array( 'id' => 5 ) ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'recruitment_candidate_has_classifications', $result->get_error_code() );
		$this->assertSame( 409, $result->get_error_data()['status'] );
		$this->assertSame( array( 'classifications' => 3 ), $result->get_error_data()['blocked_by'] );
		$this->assertSame( array(), $this->writes );
	}

	public function test_delete_candidate_returns_200_envelope_on_success(): void {
		$this->store_candidate();

		$response = $this->controller->delete_candidate( $this->make_request( array( 'id' => 5 ) ) );

		$this->assertNotInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			array(
				'success' => true,
				'errors'  => array(),
			),
			$response->get_data()
		);
		$this->assertSame( array( array( 'delete', self::T_CANDIDATE, array(), array( 'id' => 5 ) ) ), $this->writes );
	}

	// ------------------------------------------------------------------
	// reveal_pii()
	// ------------------------------------------------------------------

	public function test_reveal_pii_returns_400_for_unsupported_field(): void {
		$result = $this->controller->reveal_pii(
			$this->make_request( array( 'id' => 5, 'field' => 'salary' ) )
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'recruitment_pii_field_unsupported', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
	}

	public function test_reveal_pii_returns_404_when_candidate_missing(): void {
		$result = $this->controller->reveal_pii(
			$this->make_request( array( 'id' => 999, 'field' => 'cpf' ) )
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'recruitment_candidate_not_found', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
	}

	public function test_reveal_pii_returns_403_when_tier_masked(): void {
		// Neither the owner nor a holder of the PII capability.
		Functions\when( 'user_can' )->justReturn( false );
		$this->store_candidate();

		$result = $this->controller->reveal_pii(
			$this->make_request( array( 'id' => 5, 'field' => 'cpf' ) )
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'recruitment_pii_access_denied', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );
		$this->assertSame( array(), $this->logged( 'recruitment_pii_revealed' ) );
	}

	public function test_reveal_pii_returns_404_when_encrypted_column_empty(): void {
		$this->store_candidate( array( 'cpf_encrypted' => '' ) );

		$result = $this->controller->reveal_pii(
			$this->make_request( array( 'id' => 5, 'field' => 'cpf' ) )
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'recruitment_pii_value_missing', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
	}

	public function test_reveal_pii_returns_500_when_decrypt_fails(): void {
		$this->store_candidate( array( 'cpf_encrypted' => 'not-a-ciphertext' ) );

		$result = $this->controller->reveal_pii(
			$this->make_request( array( 'id' => 5, 'field' => 'cpf' ) )
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'recruitment_pii_decrypt_failed', $result->get_error_code() );
		$this->assertSame( 500, $result->get_error_data()['status'] );
		$this->assertSame( array(), $this->logged( 'recruitment_pii_revealed' ) );
	}

	public function test_reveal_pii_cpf_success_formats_and_audits(): void {
		$this->store_candidate();

		$response = $this->controller->reveal_pii(
			$this->make_request( array( 'id' => 5, 'field' => 'cpf' ) )
		);

		$this->assertNotInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			array(
				'field' => 'cpf',
				'value' => DocumentFormatter::format_cpf( Identifiers::CPF_A ),
			),
			$response->get_data()
		);
		$this->assertSame(
			array(
				array(
					'candidate_id' => 5,
					'field_key'    => 'cpf',
				),
			),
			$this->logged( 'recruitment_pii_revealed' )
		);
	}

	public function test_reveal_pii_rf_success_without_audit(): void {
		// The candidate reading their own record: revealed, and not audited.
		Functions\when( 'get_current_user_id' )->justReturn( self::OWNER );
		$this->store_candidate();

		$response = $this->controller->reveal_pii(
			$this->make_request( array( 'id' => 5, 'field' => 'rf' ) )
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			array(
				'field' => 'rf',
				'value' => DocumentFormatter::format_rf( Identifiers::RF_A ),
			),
			$response->get_data()
		);
		$this->assertSame( array(), $this->logged( 'recruitment_pii_revealed' ) );
	}

	public function test_reveal_pii_email_returns_plain_value(): void {
		Functions\when( 'get_current_user_id' )->justReturn( self::OWNER );
		$this->store_candidate();

		$response = $this->controller->reveal_pii(
			$this->make_request( array( 'id' => 5, 'field' => 'email' ) )
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			array(
				'field' => 'email',
				'value' => self::EMAIL,
			),
			$response->get_data()
		);
	}

	// ------------------------------------------------------------------
	// get_my_recruitment()
	// ------------------------------------------------------------------

	public function test_get_my_recruitment_returns_empty_when_not_logged_in(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 0 );

		$response = $this->controller->get_my_recruitment();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array(), $response->get_data() );
		$this->assertSame( array(), $this->reads );
	}

	public function test_get_my_recruitment_returns_empty_when_no_candidates(): void {
		Functions\when( 'get_current_user_id' )->justReturn( self::OWNER );

		$response = $this->controller->get_my_recruitment();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array(), $response->get_data() );
		$this->assertSame( array( 'SELECT * FROM ' . self::T_CANDIDATE . ' WHERE user_id = ' . self::OWNER ), $this->reads );
	}

	public function test_get_my_recruitment_groups_classifications_by_notice(): void {
		Functions\when( 'get_current_user_id' )->justReturn( self::OWNER );
		$this->store_candidate();

		$classifications = array(
			array( 100, 7, 'definitive', 1, '9.5' ),
			// A draft-notice classification that must be filtered out.
			array( 101, 8, 'preview', 2, '8.0' ),
			// A classification whose notice is missing entirely.
			array( 102, 9, 'preview', 3, '7.0' ),
		);
		foreach ( $classifications as [ $id, $notice_id, $list_type, $rank, $score ] ) {
			$this->rows[ self::T_CLASSIFICATION ][ $id ] = (object) array(
				'id'           => $id,
				'candidate_id' => 5,
				'notice_id'    => $notice_id,
				'adjutancy_id' => 3,
				'list_type'    => $list_type,
				'rank'         => $rank,
				'score'        => $score,
				'status'       => 'classified',
			);
		}
		$this->rows[ self::T_NOTICE ][7] = (object) array(
			'id'           => 7,
			'code'         => 'N-7',
			'name'         => 'Notice 7',
			'status'       => 'open',
			'was_reopened' => '1',
		);
		$this->rows[ self::T_NOTICE ][8] = (object) array(
			'id'           => 8,
			'code'         => 'N-8',
			'name'         => 'Draft',
			'status'       => 'draft',
			'was_reopened' => '0',
		);
		$this->history = array(
			(object) array( 'id' => 900, 'classification_id' => 100 ),
			(object) array( 'id' => 901, 'classification_id' => 102 ),
		);

		$response = $this->controller->get_my_recruitment();

		// The history comes in ONE batched query, not one per ranking
		// (#1234), and that query names the COMPLETE list of ids: a return to
		// the singular would read the call table once per ranking.
		//
		// The ids 101 and 102 enter the batch even though their notices are
		// discarded afterwards (draft and absent). It is the deliberate
		// consequence of collecting the ids before the filter: one query with a
		// few extra rows instead of three queries.
		$history_reads = array_values(
			array_filter( $this->reads, static fn( $sql ) => 0 === strpos( $sql, 'SELECT * FROM ' . self::T_CALL ) )
		);
		$this->assertSame(
			array( 'SELECT * FROM ' . self::T_CALL . ' WHERE classification_id IN (100,101,102) ORDER BY called_at DESC' ),
			$history_reads
		);

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertCount( 1, $data ); // only notice 7 survived
		$this->assertSame( 7, $data[0]['notice']['id'] );
		$this->assertTrue( $data[0]['notice']['was_reopened'] );
		$this->assertCount( 1, $data[0]['classifications'] );
		$this->assertSame( 100, $data[0]['classifications'][0]['id'] );
		// Ranking 102's row exists in the batch's return but does not reach here:
		// its call was discarded. Ranking 100's does, grouped.
		$this->assertCount( 1, $data[0]['classifications'][0]['calls'] );
		$this->assertSame( 900, $data[0]['classifications'][0]['calls'][0]->id );
	}

	// ------------------------------------------------------------------
	// permission callbacks
	// ------------------------------------------------------------------

	public function test_check_admin_cap_reflects_current_user_can(): void {
		Functions\when( 'current_user_can' )->alias( fn( $cap ) => 'ffc_manage_recruitment' === $cap );
		$this->assertTrue( $this->controller->check_admin_cap() );
	}

	public function test_check_admin_cap_denies_without_cap(): void {
		Functions\when( 'current_user_can' )->justReturn( false );
		$this->assertFalse( $this->controller->check_admin_cap() );
	}

	public function test_check_can_delete_recruitment_gate(): void {
		Functions\when( 'current_user_can' )->alias( fn( $cap ) => 'ffc_delete_recruitment' === $cap );
		$this->assertTrue( $this->controller->check_can_delete_recruitment() );

		Functions\when( 'current_user_can' )->justReturn( false );
		$this->assertFalse( $this->controller->check_can_delete_recruitment() );
	}

	public function test_check_logged_in_gate(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		$this->assertTrue( $this->controller->check_logged_in() );

		Functions\when( 'is_user_logged_in' )->justReturn( false );
		$this->assertFalse( $this->controller->check_logged_in() );
	}
}
