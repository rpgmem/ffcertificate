<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\API\UserCertificatesRestController;
use FreeFormCertificate\Core\Encryption;
use FreeFormCertificate\Tests\Support\ActivityLogBuffer;

/**
 * Tests for UserCertificatesRestController: route registration, permission checks,
 * and get_user_certificates success/error paths.
 *
 * Encryption (with the bootstrap's key constants), MagicLinkHelper,
 * ActivityLog, Debug, PiiAccessPolicy, DocumentFormatter and DateFormatter
 * are the real classes. The `$wpdb` double records every prepared statement
 * and answers only the list query and the reveal query, so any other query
 * fails the test; an audited reveal is read from ActivityLog's write buffer.
 */
class UserCertificatesRestControllerTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var array Captured route registrations */
	private array $registered_routes = [];

	/**
	 * Every prepared statement, in order: [ sql, args ].
	 *
	 * @var list<array{0: string, 1: array<int, mixed>}>
	 */
	private array $prepared = array();

	/** @var list<array<string, mixed>>|\Exception What the list query answers, or throws. */
	private $rows = array();

	/** @var array<string, mixed>|null What the reveal query answers. */
	private ?array $row = null;

	/**
	 * Every reveal read: [ sql, args, output ].
	 *
	 * @var list<array{0: string, 1: array<int, mixed>, 2: mixed}>
	 */
	private array $row_reads = array();

	/**
	 * Queries the double did not expect. Recorded rather than failed on the
	 * spot, because the list endpoint catches every exception, a failed
	 * assertion included; assertPostConditions() fails on any.
	 *
	 * @var list<string>
	 */
	private array $unexpected = array();

	/** @var mixed The `$wpdb` global an earlier test left, restored afterwards. */
	private $wpdb_backup;

	/** @var bool Whether `$wpdb` was set before this test. */
	private bool $had_wpdb = false;

	/** @var string The stored ciphertext of the certificate e-mail. */
	private string $email_encrypted = '';

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->registered_routes = [];
		$this->prepared          = array();
		$this->rows              = array();
		$this->row               = null;
		$this->row_reads         = array();
		$this->unexpected        = array();

		ActivityLogBuffer::reset();
		ActivityLogBuffer::stub_functions();

		Functions\when( 'register_rest_route' )->alias( function( $namespace, $route, $args ) {
			$this->registered_routes[] = array(
				'namespace' => $namespace,
				'route'     => $route,
				'args'      => $args,
			);
		});

		Functions\when( '__' )->returnArg();
		Functions\when( 'absint' )->alias( function( $val ) { return abs( intval( $val ) ); } );
		Functions\when( 'rest_ensure_response' )->alias( function( $data ) { return $data; } );
		Functions\when( 'is_wp_error' )->alias( function( $thing ) { return $thing instanceof \WP_Error; } );
		Functions\when( 'get_current_user_id' )->justReturn( 0 );
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( 'user_can' )->justReturn( false );
		// The activity log is on (so an audited reveal reaches the buffer),
		// every debug area is off, and the verification page id is unset.
		Functions\when( 'get_option' )->alias( function( $key, $default = false ) {
			return 'ffc_settings' === $key ? ActivityLogBuffer::ENABLED_SETTINGS : $default;
		});
		Functions\when( 'home_url' )->alias( function( $path = '' ) {
			return 'https://example.com' . $path;
		});
		Functions\when( 'date_i18n' )->alias( function( $format, $timestamp = false ) {
			return date( $format, $timestamp ?: time() );
		});
		Functions\when( 'wp_date' )->alias( function( $format, $timestamp = null, $tz = null ) {
			return date( $format, $timestamp ?? time() );
		});
		Functions\when( 'wp_timezone' )->alias( function() {
			return new \DateTimeZone( 'UTC' );
		});
		// DocumentFormatter::mask_email() calls is_email().
		Functions\when( 'is_email' )->justReturn( true );

		$this->email_encrypted = (string) Encryption::encrypt( 'john@example.com' );

		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$wpdb->posts  = 'wp_posts';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing( function( $sql, ...$args ) {
			$this->prepared[] = array( (string) $sql, $args );
			return 'Q' . ( count( $this->prepared ) - 1 );
		});
		$wpdb->shouldReceive( 'get_results' )->andReturnUsing( function( $query, $output = null ) {
			[ $sql ] = $this->statement( $query );
			if ( false === strpos( $sql, 'WHERE s.user_id = %d' ) || ARRAY_A !== $output ) {
				$this->unexpected[] = 'get_results(): ' . $sql;
				return array();
			}
			if ( $this->rows instanceof \Exception ) {
				throw $this->rows;
			}
			return $this->rows;
		});
		$wpdb->shouldReceive( 'get_row' )->andReturnUsing( function( $query, $output = null ) {
			[ $sql, $args ] = $this->statement( $query );
			if ( false === strpos( $sql, 'WHERE id = %d AND user_id = %d' ) ) {
				$this->unexpected[] = 'get_row(): ' . $sql;
				return null;
			}
			$this->row_reads[] = array( $sql, $args, $output );
			return $this->row;
		});

		$this->had_wpdb    = array_key_exists( 'wpdb', $GLOBALS );
		$this->wpdb_backup = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb']   = $wpdb;
	}

	protected function assertPostConditions(): void {
		$this->assertSame( array(), $this->unexpected, 'The controller ran a query the test did not expect.' );
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
	 * The prepared statement behind a token the double handed out.
	 *
	 * @param mixed $query Token returned by prepare().
	 * @return array{0: string, 1: array<int, mixed>}
	 */
	private function statement( $query ): array {
		$index = (int) substr( (string) $query, 1 );
		return $this->prepared[ $index ] ?? array( (string) $query, array() );
	}

	/**
	 * Helper: create a mock WP_REST_Request with given params.
	 */
	private function make_request( array $params = [] ): object {
		$request = Mockery::mock( 'WP_REST_Request' );
		$request->shouldReceive( 'get_param' )->andReturnUsing( function( $key ) use ( $params ) {
			return $params[ $key ] ?? null;
		});
		return $request;
	}

	/**
	 * A stored submission row, its e-mail encrypted as the writer stores it.
	 *
	 * @param array<string, mixed> $overrides Columns to override.
	 * @return array<string, mixed>
	 */
	private function submission( array $overrides = array() ): array {
		return array_merge(
			array(
				'id'              => '10',
				'form_id'         => '3',
				'form_title'      => 'Test Form',
				'submission_date' => '2025-06-15 10:30:00',
				'consent_given'   => '1',
				'email'           => '',
				'email_encrypted' => $this->email_encrypted,
				'auth_code'       => 'ABCDEFGHIJKL',
				'magic_token'     => 'token123',
			),
			$overrides
		);
	}

	// ------------------------------------------------------------------
	// Route registration
	// ------------------------------------------------------------------

	public function test_register_routes_creates_the_list_and_the_reveal_endpoints(): void {
		$ctrl = new UserCertificatesRestController( 'ffc/v1' );
		$ctrl->register_routes();

		$this->assertCount( 2, $this->registered_routes );
		$this->assertSame( '/user/certificates/reveal', $this->registered_routes[1]['route'] );
		$this->assertSame( 'POST', $this->registered_routes[1]['args']['methods'] );
		$this->assertSame( 'is_user_logged_in', $this->registered_routes[1]['args']['permission_callback'] );
	}

	public function test_certificates_route_path(): void {
		$ctrl = new UserCertificatesRestController( 'ffc/v1' );
		$ctrl->register_routes();

		$this->assertSame( '/user/certificates', $this->registered_routes[0]['route'] );
	}

	public function test_certificates_route_requires_authentication(): void {
		$ctrl = new UserCertificatesRestController( 'ffc/v1' );
		$ctrl->register_routes();

		$this->assertSame(
			'is_user_logged_in',
			$this->registered_routes[0]['args']['permission_callback']
		);
	}

	public function test_certificates_route_uses_correct_namespace(): void {
		$ctrl = new UserCertificatesRestController( 'ffc/v1' );
		$ctrl->register_routes();

		$this->assertSame( 'ffc/v1', $this->registered_routes[0]['namespace'] );
	}

	// ------------------------------------------------------------------
	// get_user_certificates — error paths
	// ------------------------------------------------------------------

	public function test_get_user_certificates_returns_error_when_not_logged_in(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 0 );
		Functions\when( 'current_user_can' )->justReturn( false );

		$ctrl    = new UserCertificatesRestController( 'ffc/v1' );
		$request = $this->make_request();
		$result  = $ctrl->get_user_certificates( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'not_logged_in', $result->get_error_code() );
		$this->assertSame( array(), $this->prepared, 'Nothing is queried for a visitor.' );
	}

	public function test_get_user_certificates_returns_error_when_capability_denied(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( 'user_can' )->justReturn( false );

		$ctrl    = new UserCertificatesRestController( 'ffc/v1' );
		$request = $this->make_request();
		$result  = $ctrl->get_user_certificates( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'capability_denied', $result->get_error_code() );
		$this->assertSame( array(), $this->prepared, 'Nothing is queried without the capability.' );
	}

	// ------------------------------------------------------------------
	// get_user_certificates — success paths
	// ------------------------------------------------------------------

	public function test_get_user_certificates_returns_empty_when_no_submissions(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'current_user_can' )->justReturn( true );

		$this->rows = array();

		$ctrl    = new UserCertificatesRestController( 'ffc/v1' );
		$request = $this->make_request();
		$result  = $ctrl->get_user_certificates( $request );

		$this->assertIsArray( $result );
		$this->assertSame( 0, $result['total'] );
		$this->assertEmpty( $result['certificates'] );
	}

	/**
	 * #1744 — the list leaves out trash and quiz attempts that did not pass,
	 * through prepared placeholders.
	 */
	public function test_get_user_certificates_hides_statuses_that_are_not_certificates(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'current_user_can' )->justReturn( true );

		( new UserCertificatesRestController( 'ffc/v1' ) )->get_user_certificates( $this->make_request() );

		$this->assertCount( 1, $this->prepared, 'One list query is prepared.' );
		[ $sql, $args ] = $this->prepared[0];
		$this->assertMatchesRegularExpression( '/status NOT IN \(%s, %s, %s, %s\)/', $sql );
		$this->assertSame(
			array( 'wp_ffc_submissions', 'wp_posts', 5, 'trash', 'quiz_in_progress', 'quiz_failed', 'quiz_pending' ),
			$args
		);
	}

	public function test_get_user_certificates_returns_formatted_certificate_data(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'current_user_can' )->justReturn( true );

		$this->rows = array( $this->submission() );

		$ctrl    = new UserCertificatesRestController( 'ffc/v1' );
		$request = $this->make_request();
		$result  = $ctrl->get_user_certificates( $request );

		$this->assertIsArray( $result );
		$this->assertSame( 1, $result['total'] );
		$this->assertCount( 1, $result['certificates'] );

		$cert = $result['certificates'][0];
		$this->assertSame( 10, $cert['id'] );
		$this->assertSame( 3, $cert['form_id'] );
		$this->assertSame( 'Test Form', $cert['form_title'] );
		$this->assertTrue( $cert['consent_given'] );
		$this->assertSame( 'j***@example.com', $cert['email'] );
		$this->assertTrue( $result['can_reveal'], 'The owner may reveal their own e-mail (#1897).' );
		$this->assertStringNotContainsString( 'john@example.com', (string) json_encode( $result ) );
		$this->assertNotEmpty( $cert['auth_code'] );
		$this->assertSame( 'https://example.com/valid/#token=token123', $cert['magic_link'], 'The link is built from the stored token, on the fallback verification page.' );
		$this->assertSame( $cert['magic_link'], $cert['pdf_url'] );
	}

	public function test_get_user_certificates_hides_magic_link_when_download_denied(): void {
		// current_user_can returns true for 'view_own_certificates' but false for 'download_own_certificates'
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'current_user_can' )->alias( function( $cap ) {
			if ( $cap === 'manage_options' ) {
				return false;
			}
			if ( $cap === 'ffc_view_own_certificates' ) {
				return true;
			}
			// deny download_own_certificates, view_certificate_history
			return false;
		});

		$this->rows = array( $this->submission() );

		$ctrl    = new UserCertificatesRestController( 'ffc/v1' );
		$request = $this->make_request();
		$result  = $ctrl->get_user_certificates( $request );

		$this->assertIsArray( $result );
		$cert = $result['certificates'][0];
		$this->assertSame( '', $cert['magic_link'] );
		$this->assertSame( '', $cert['pdf_url'] );
	}

	public function test_get_user_certificates_filters_to_most_recent_per_form_when_history_denied(): void {
		// Allow view_own_certificates and download, deny view_certificate_history
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'current_user_can' )->alias( function( $cap ) {
			if ( $cap === 'manage_options' ) {
				return false;
			}
			if ( $cap === 'ffc_view_own_certificates' || $cap === 'ffc_download_own_certificates' ) {
				return true;
			}
			// deny view_certificate_history
			return false;
		});

		$this->rows = array(
			$this->submission( array( 'id' => '20', 'form_title' => 'Form A', 'submission_date' => '2025-07-01 10:00:00', 'auth_code' => 'AAAAAAAAAAAA', 'magic_token' => 't1' ) ),
			$this->submission( array( 'id' => '15', 'form_title' => 'Form A', 'submission_date' => '2025-06-01 10:00:00', 'auth_code' => 'BBBBBBBBBBBB', 'magic_token' => 't2' ) ),
			$this->submission( array( 'id' => '25', 'form_id' => '5', 'form_title' => 'Form B', 'submission_date' => '2025-08-01 10:00:00', 'consent_given' => '0', 'auth_code' => 'CCCCCCCCCCCC', 'magic_token' => 't3' ) ),
		);

		$ctrl    = new UserCertificatesRestController( 'ffc/v1' );
		$request = $this->make_request();
		$result  = $ctrl->get_user_certificates( $request );

		$this->assertIsArray( $result );
		// Should keep only the first certificate per form_id (most recent because sorted DESC)
		$this->assertSame( 2, $result['total'] );
		$this->assertSame( 20, $result['certificates'][0]['id'] ); // Form A (first occurrence)
		$this->assertSame( 25, $result['certificates'][1]['id'] ); // Form B
	}

	public function test_get_user_certificates_returns_all_when_history_allowed(): void {
		// Allow all capabilities
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'current_user_can' )->justReturn( true );

		$this->rows = array(
			$this->submission( array( 'id' => '20', 'form_title' => 'Form A', 'submission_date' => '2025-07-01 10:00:00', 'auth_code' => 'AAAAAAAAAAAA', 'magic_token' => 't1' ) ),
			$this->submission( array( 'id' => '15', 'form_title' => 'Form A', 'submission_date' => '2025-06-01 10:00:00', 'auth_code' => 'BBBBBBBBBBBB', 'magic_token' => 't2' ) ),
		);

		$ctrl    = new UserCertificatesRestController( 'ffc/v1' );
		$request = $this->make_request();
		$result  = $ctrl->get_user_certificates( $request );

		$this->assertIsArray( $result );
		$this->assertSame( 2, $result['total'] );
	}

	public function test_get_user_certificates_wraps_exception(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'current_user_can' )->justReturn( true );
		$this->rows = new \RuntimeException( 'boom' );

		$result = ( new UserCertificatesRestController( 'ffc/v1' ) )->get_user_certificates( $this->make_request() );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'get_certificates_error', $result->get_error_code() );
	}

	// ------------------------------------------------------------------
	// reveal_certificate_email (#1897)
	// ------------------------------------------------------------------

	/**
	 * Store the row the reveal query returns.
	 *
	 * @param array|null $row The row, or null for none.
	 * @return void
	 */
	private function store_row( ?array $row ): void {
		$this->row = $row;
	}

	/**
	 * Assert the reveal query ran once, scoped to the given owner.
	 *
	 * @param int $owner The user id the query must be scoped to.
	 * @return void
	 */
	private function assert_row_read_for( int $owner ): void {
		$this->assertCount( 1, $this->row_reads, 'The certificate is read once.' );
		[ , $args, $output ] = $this->row_reads[0];
		$this->assertSame( array( 'wp_ffc_submissions', 10, $owner ), $args );
		$this->assertSame( ARRAY_A, $output );
	}

	public function test_the_owner_reveals_their_own_certificate_email_without_an_audit_row(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'current_user_can' )->alias( static fn( $cap ) => 'ffc_view_own_certificates' === $cap );
		$this->store_row( array( 'id' => '10', 'user_id' => '5', 'status' => 'publish', 'email_encrypted' => $this->email_encrypted ) );

		$result = ( new UserCertificatesRestController( 'ffc/v1' ) )->reveal_certificate_email( $this->make_request( array( 'id' => 10 ) ) );

		$this->assert_row_read_for( 5 );
		$this->assertSame( array( 'id' => 10, 'value' => 'john@example.com' ), $result );
		$this->assertSame( array(), ActivityLogBuffer::entries(), 'The owner reveals without an audit row.' );
	}

	public function test_the_owner_path_does_not_find_someone_elses_certificate(): void {
		// The query is scoped to the current user, so another owner's row
		// never comes back.
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'current_user_can' )->alias( static fn( $cap ) => 'ffc_view_own_certificates' === $cap );
		$this->store_row( null );

		$result = ( new UserCertificatesRestController( 'ffc/v1' ) )->reveal_certificate_email( $this->make_request( array( 'id' => 10 ) ) );

		$this->assert_row_read_for( 5 );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ffc_certificate_not_found', $result->get_error_code() );
		$this->assertSame( array(), ActivityLogBuffer::entries() );
	}

	public function test_a_non_admin_naming_another_account_is_refused(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 9 );
		Functions\when( 'current_user_can' )->alias( static fn( $cap ) => 'ffc_view_own_certificates' === $cap );

		$result = ( new UserCertificatesRestController( 'ffc/v1' ) )->reveal_certificate_email( $this->make_request( array( 'id' => 10, 'viewAsUserId' => 5 ) ) );

		$this->assertSame( 'ffc_reveal_denied', $result->get_error_code() );
		$this->assertSame( array(), $this->row_reads, 'Nothing is read for a refused reveal.' );
		$this->assertSame( array(), ActivityLogBuffer::entries() );
	}

	public function test_a_certificate_that_is_not_one_is_not_revealed(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'current_user_can' )->alias( static fn( $cap ) => 'ffc_view_own_certificates' === $cap );
		$this->store_row( array( 'id' => '10', 'user_id' => '5', 'status' => 'trash', 'email_encrypted' => $this->email_encrypted ) );

		$result = ( new UserCertificatesRestController( 'ffc/v1' ) )->reveal_certificate_email( $this->make_request( array( 'id' => 10 ) ) );

		$this->assert_row_read_for( 5 );
		$this->assertSame( 'ffc_certificate_not_found', $result->get_error_code() );
	}

	public function test_view_as_reveal_is_audited_once(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'current_user_can' )->alias( static fn( $cap ) => 'manage_options' === $cap );
		Functions\when( 'user_can' )->alias( static fn( $uid, $cap ) => ( 1 === $uid && 'manage_options' === $cap ) || ( 5 === $uid && 'ffc_view_own_certificates' === $cap ) );
		$this->store_row( array( 'id' => '10', 'user_id' => '5', 'status' => 'publish', 'email_encrypted' => $this->email_encrypted ) );

		$result = ( new UserCertificatesRestController( 'ffc/v1' ) )->reveal_certificate_email( $this->make_request( array( 'id' => 10, 'viewAsUserId' => 5 ) ) );

		$this->assert_row_read_for( 5 );
		$this->assertSame( 'john@example.com', $result['value'] );

		$entries = ActivityLogBuffer::entries();
		$this->assertCount( 1, $entries, 'One audit row names the reveal.' );
		$this->assertSame( 1, $entries[0]['user_id'] );
		$this->assertSame( 10, $entries[0]['submission_id'] );
		$this->assertSame(
			array(
				array(
					'action'  => 'submission_pii_revealed',
					'level'   => 'info',
					'context' => array( 'field_key' => 'email', 'view_as' => true, 'subject_user_id' => 5 ),
				),
			),
			ActivityLogBuffer::contexts()
		);
	}

	public function test_view_as_reveal_refuses_a_viewer_the_policy_denies(): void {
		// An administrator by view-as gate, but without the reveal answer.
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'current_user_can' )->alias( static fn( $cap ) => 'manage_options' === $cap );
		Functions\when( 'user_can' )->alias( static fn( $uid, $cap ) => 5 === $uid && 'ffc_view_own_certificates' === $cap );

		$result = ( new UserCertificatesRestController( 'ffc/v1' ) )->reveal_certificate_email( $this->make_request( array( 'id' => 10, 'viewAsUserId' => 5 ) ) );

		$this->assertSame( 'ffc_reveal_denied', $result->get_error_code() );
		$this->assertSame( array(), $this->row_reads, 'Nothing is read for a refused reveal.' );
		$this->assertSame( array(), ActivityLogBuffer::entries() );
	}
}
