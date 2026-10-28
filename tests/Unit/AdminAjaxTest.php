<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Admin\AdminAjax;
use FreeFormCertificate\Core\Encryption;
use FreeFormCertificate\Tests\Support\ActivityLogBuffer;
use FreeFormCertificate\Tests\Support\CallLog;
use FreeFormCertificate\Tests\Support\Identifiers;

/**
 * Tests for AdminAjax: constructor hook registration, the search_user AJAX
 * handler and the PII reveal endpoints.
 *
 * Everything below the handler runs for real — AjaxTrait, Capabilities,
 * RequestInput, PiiAccessPolicy, DocumentFormatter, Encryption (with the
 * bootstrap's key constants), ActivityLog, SettingsReader, SubmissionHandler,
 * AppointmentRepository, the reregistration, audience and custom-field
 * readers, AdminUserCustomFields, DateMessagesAdminPage, UserManager,
 * ActivityLogQuery and RateLimitStats — over a `$wpdb` double that answers
 * each query by its SQL text from the rows a test stores, and fails on any
 * query it does not know. The viewer is one account: its capabilities are a
 * list both `current_user_can()` and `user_can()` read, so a policy tier is
 * chosen by granting (or not) the module's PII capability, and the owner
 * tier by making the viewer the record's owner. The audit is the real
 * ActivityLog's write buffer, with the activity log switched on.
 *
 * The four search tests that reach `new \WP_User_Query` run in their own
 * process: that WordPress class is not in the bootstrap, so each overloads it.
 *
 * @covers \FreeFormCertificate\Admin\AdminAjax
 */
class AdminAjaxTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** The viewer's account id. */
	private const VIEWER = 4;

	/** Capabilities every test's viewer holds: each reveal type's surface, and no PII cap. */
	private const SURFACE_CAPS = array(
		'ffc_edit_certificates',
		'ffc_view_certificates',
		'ffc_view_appointments',
		'ffc_view_reregistration',
		'ffc_view_audiences',
		'edit_users',
		'ffc_view_date_messages',
		'ffc_view_activity_log',
		'ffc_view_settings',
	);

	/** @var int The account get_current_user_id() answers. */
	private int $current_user = self::VIEWER;

	/** @var list<string> Capabilities the viewer holds. */
	private array $viewer_caps = array();

	/** @var list<int> Accounts the viewer may edit (`edit_user`). */
	private array $editable_users = array();

	/** @var list<string> Every capability current_user_can() was asked about. */
	private array $cap_checks = array();

	/** @var list<string> Every capability user_can() was asked about. */
	private array $policy_checks = array();

	/** @var array<int, \WP_User> Accounts get_userdata() answers. */
	private array $users = array();

	/** @var array<int, array<string, mixed>> User meta, by account and key. */
	private array $user_meta = array();

	/** @var array<string, array<int, array<string, mixed>>> Stored rows, by table and id. */
	private array $tables = array();

	/** @var list<int> Accounts listed as members of an audience. */
	private array $audience_members = array();

	/** @var array<string, string> `cpf_hash` / `rf_hash` value => user_id stored on a submission. */
	private array $identifier_owners = array();

	/** @var list<string> Every SQL string the double ran. */
	private array $queries = array();

	/** @var array<string, mixed> The `$_POST` an earlier test left, restored afterwards. */
	private array $post_backup = array();

	/** @var mixed The `$wpdb` global an earlier test left, restored afterwards. */
	private $wpdb_backup;

	/** @var bool Whether `$wpdb` was set before this test. */
	private bool $had_wpdb = false;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->post_backup = $_POST ?? array();
		$_POST             = array();

		$this->current_user      = self::VIEWER;
		$this->viewer_caps       = self::SURFACE_CAPS;
		$this->editable_users    = array();
		$this->cap_checks        = array();
		$this->policy_checks     = array();
		$this->users             = array();
		$this->user_meta         = array();
		$this->tables            = array();
		$this->audience_members  = array();
		$this->identifier_owners = array();
		$this->queries           = array();

		// The real ActivityLog, logging into its buffer (never flushed).
		ActivityLogBuffer::reset();
		ActivityLogBuffer::stub_functions();
		Functions\when( 'get_option' )->alias(
			static fn( $key, $default = false ) => 'ffc_settings' === $key ? ActivityLogBuffer::ENABLED_SETTINGS : $default
		);
		Functions\when( 'wp_cache_delete' )->justReturn( true );

		Functions\when( '__' )->returnArg();
		Functions\when( 'add_action' )->justReturn( true );

		// Stub wp_send_json_error to throw so we can detect "die" behavior.
		Functions\when( 'wp_send_json_error' )->alias(
			static function ( $data = null ) {
				$message = is_array( $data ) && isset( $data['message'] ) ? $data['message'] : 'wp_send_json_error';
				throw new \RuntimeException( 'wp_send_json_error: ' . $message );
			}
		);
		// Stub wp_send_json_success to throw so we can capture success responses.
		Functions\when( 'wp_send_json_success' )->alias(
			static function ( $data = null ) {
				throw new AdminAjaxSuccessException( $data );
			}
		);

		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		Functions\when( 'current_user_can' )->alias(
			function ( $cap, ...$args ) {
				$this->cap_checks[] = (string) $cap;
				if ( 'edit_user' === $cap ) {
					return in_array( (int) ( $args[0] ?? 0 ), $this->editable_users, true );
				}
				return in_array( $cap, $this->viewer_caps, true );
			}
		);
		Functions\when( 'user_can' )->alias(
			function ( $user_id, $cap ) {
				$this->policy_checks[] = (string) $cap;
				return (int) $user_id === $this->current_user && in_array( $cap, $this->viewer_caps, true );
			}
		);
		Functions\when( 'get_current_user_id' )->alias( fn() => $this->current_user );
		Functions\when( 'get_userdata' )->alias( fn( $id ) => $this->users[ (int) $id ] ?? false );
		Functions\when( 'get_user_meta' )->alias(
			fn( $user_id, $key = '', $single = false ) => $this->user_meta[ (int) $user_id ][ $key ] ?? ''
		);
		Functions\when( 'get_avatar_url' )->justReturn( 'https://example.com/avatar.jpg' );
		// The user picker masks the addresses it lists (#1863).
		Functions\when( 'is_email' )->alias( static fn( $v ) => is_string( $v ) && false !== strpos( $v, '@' ) );

		$this->had_wpdb    = array_key_exists( 'wpdb', $GLOBALS );
		$this->wpdb_backup = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb']   = $this->database();
	}

	protected function tearDown(): void {
		ActivityLogBuffer::reset();
		$_POST = $this->post_backup;
		if ( $this->had_wpdb ) {
			$GLOBALS['wpdb'] = $this->wpdb_backup;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * A `$wpdb` double reading the rows this test stored.
	 *
	 * `prepare()` interpolates, so each read dispatches on the query it ran.
	 *
	 * @return Mockery\MockInterface
	 */
	private function database() {
		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			static function ( $sql, ...$args ) {
				$values = ( 1 === count( $args ) && is_array( $args[0] ) ) ? $args[0] : $args;
				return (string) preg_replace_callback(
					'/%[ids]/',
					static function () use ( &$values ) {
						return (string) array_shift( $values );
					},
					(string) $sql
				);
			}
		);
		$wpdb->shouldReceive( 'get_row' )->andReturnUsing(
			function ( $sql, $output = OBJECT ) {
				$this->queries[] = $sql;
				if ( 1 === preg_match( '/FROM (\S+) WHERE id = (\d+)$/', $sql, $m ) ) {
					$row = $this->tables[ $m[1] ][ (int) $m[2] ] ?? null;
					if ( null === $row ) {
						return null;
					}
					return ARRAY_A === $output ? $row : (object) $row;
				}
				$this->fail( 'Unexpected get_row(): ' . $sql );
			}
		);
		$wpdb->shouldReceive( 'get_var' )->andReturnUsing(
			function ( $sql ) {
				$this->queries[] = $sql;
				if ( 0 === strpos( $sql, 'SHOW TABLES LIKE ' ) ) {
					// No profile table: UserManager falls back to the account.
					return null;
				}
				if ( 1 === preg_match( '/^SELECT COUNT\(\*\) FROM wp_ffc_audience_members WHERE user_id = (\d+)$/', $sql, $m ) ) {
					return in_array( (int) $m[1], $this->audience_members, true ) ? '1' : '0';
				}
				if ( 1 === preg_match( '/^SELECT COUNT\(\*\) FROM wp_ffc_audience_schedule_permissions WHERE user_id = \d+$/', $sql ) ) {
					return '0';
				}
				if ( 1 === preg_match( '/^SELECT user_id FROM wp_ffc_submissions WHERE (cpf_hash|rf_hash) = (\S+) AND user_id IS NOT NULL LIMIT 1$/', $sql, $m ) ) {
					return $this->identifier_owners[ $m[1] . '=' . $m[2] ] ?? null;
				}
				$this->fail( 'Unexpected get_var(): ' . $sql );
			}
		);
		$wpdb->shouldReceive( 'get_results' )->andReturnUsing(
			function ( $sql ) {
				$this->queries[] = $sql;
				if ( 1 === preg_match( '/INNER JOIN wp_ffc_audience_members m ON a.id = m.audience_id\s+WHERE m.user_id = (\d+)/', $sql, $m ) ) {
					$rows = array();
					foreach ( $this->tables['wp_ffc_audiences'] ?? array() as $audience ) {
						if ( in_array( (int) $m[1], $audience['members'], true ) ) {
							$rows[] = (object) $audience;
						}
					}
					return $rows;
				}
				if ( 1 === preg_match( '/^SELECT \* FROM wp_ffc_custom_fields WHERE audience_id = (\d+) AND is_active = 1/', $sql, $m ) ) {
					$rows = array();
					foreach ( $this->tables['wp_ffc_custom_fields'] ?? array() as $field ) {
						if ( (int) $m[1] === (int) $field['audience_id'] ) {
							$rows[] = (object) $field;
						}
					}
					return $rows;
				}
				$this->fail( 'Unexpected get_results(): ' . $sql );
			}
		);
		return $wpdb;
	}

	/**
	 * Helper: set up POST data for a valid search_user request.
	 */
	private function setup_valid_search_user( string $term = 'john' ): void {
		$_POST['nonce']  = 'valid_nonce';
		$_POST['search'] = $term;
	}

	/**
	 * Post a single reveal.
	 *
	 * @param string      $type  Reveal type; null posts none (the default, submission).
	 * @param int         $id    Record id.
	 * @param string      $field Field.
	 */
	private function post_reveal( ?string $type, int $id, string $field ): void {
		$_POST['nonce'] = 'n';
		if ( null !== $type ) {
			$_POST['type'] = $type;
		}
		$_POST['submission_id'] = (string) $id;
		$_POST['field']         = $field;
	}

	/**
	 * Store a row.
	 *
	 * @param string               $table Table, with prefix.
	 * @param int                  $id    Row id.
	 * @param array<string, mixed> $row   Columns.
	 */
	private function store( string $table, int $id, array $row ): void {
		$this->tables[ $table ][ $id ] = array( 'id' => (string) $id ) + $row;
	}

	/**
	 * An account get_userdata() answers.
	 *
	 * @param int    $id           Account id.
	 * @param string $email        Email.
	 * @param string $display_name Display name.
	 */
	private function account( int $id, string $email, string $display_name = '' ): void {
		$user               = new \WP_User( $id );
		$user->user_email   = $email;
		$user->display_name = $display_name;
		$this->users[ $id ] = $user;
	}

	/**
	 * Run reveal_pii() to its success response.
	 *
	 * @return mixed The response data.
	 */
	private function reveal() {
		$ajax = new AdminAjax();
		try {
			$ajax->reveal_pii();
		} catch ( AdminAjaxSuccessException $e ) {
			return $e->getData();
		}
		$this->fail( 'Expected AdminAjaxSuccessException' );
	}

	/**
	 * The one audit row a served reveal wrote.
	 *
	 * @param string               $action    Expected action.
	 * @param array<string, mixed> $context   Expected context.
	 * @param int                  $user_id   Expected actor.
	 * @param int                  $object_id Expected record id.
	 */
	private function assertAuditedOnce( string $action, array $context, int $user_id, int $object_id ): void {
		$entries = ActivityLogBuffer::entries();
		$this->assertCount( 1, $entries, 'Exactly one audit row.' );
		$this->assertSame(
			array(
				'action'  => $action,
				'level'   => 'info',
				'context' => $context,
			),
			ActivityLogBuffer::contexts()[0]
		);
		$this->assertSame( $user_id, $entries[0]['user_id'] );
		$this->assertSame( $object_id, $entries[0]['submission_id'] );
	}

	/**
	 * Prove the record's owner reached the policy: the same reveal, asked by
	 * that owner, resolves to the owner tier, which checks no capability.
	 *
	 * @param int $owner The owner the record carries.
	 */
	private function assertOwnerTierFor( int $owner ): void {
		$this->current_user  = $owner;
		$this->policy_checks = array();
		$this->reveal();
		$this->assertSame( array(), $this->policy_checks, 'The owner reveals without a capability check.' );
	}

	/**
	 * `Identifiers::CPF_A` in the `xxx.xxx.xxx-xx` shape `format_cpf()` returns.
	 */
	private static function masked_cpf_a(): string {
		$cpf = Identifiers::CPF_A;
		return substr( $cpf, 0, 3 ) . '.' . substr( $cpf, 3, 3 ) . '.' . substr( $cpf, 6, 3 ) . '-' . substr( $cpf, 9 );
	}

	// ==================================================================
	// Constructor
	// ==================================================================

	public function test_constructor_registers_ajax_hooks(): void {
		$registered = array();
		Functions\when( 'add_action' )->alias(
			function ( $hook, $callback ) use ( &$registered ) {
				$registered[] = $hook;
			}
		);

		new AdminAjax();

		$this->assertContains( 'wp_ajax_ffc_search_user', $registered );
	}

	// ==================================================================
	// search_user()
	// ==================================================================

	public function test_search_user_denied_without_certificates_edit_cap(): void {
		// #739 escape closed: the user search backs the `ffc_edit_certificates`
		// submission-edit page, so it is gated on that cap (via admin-or), not
		// the trait's `manage_options` default. A caller lacking the cap and
		// manage_options is denied.
		$this->setup_valid_search_user( 'jane' );
		$this->viewer_caps = array_values( array_diff( $this->viewer_caps, array( 'ffc_edit_certificates' ) ) );

		$ajax = new AdminAjax();

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/Permission denied/' );

		$ajax->search_user();
	}

	public function test_search_user_sends_error_for_short_term(): void {
		$this->setup_valid_search_user( 'a' ); // Only 1 character.

		$ajax = new AdminAjax();

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/at least 2 characters/' );

		$ajax->search_user();
	}

	/**
	 * Its own process: it overloads WP_User_Query, which the bootstrap does not declare.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_search_user_finds_by_numeric_id(): void {
		$this->setup_valid_search_user( '42' );
		$this->account( 42, 'found@example.com', 'Found User' );
		Mockery::mock( 'overload:WP_User_Query' )->shouldReceive( 'get_results' )->andReturn( array() );

		$ajax = new AdminAjax();

		try {
			$ajax->search_user();
			$this->fail( 'Expected AdminAjaxSuccessException to be thrown' );
		} catch ( AdminAjaxSuccessException $e ) {
			$data = $e->getData();
			$this->assertArrayHasKey( 'users', $data );
			$this->assertCount( 1, $data['users'] );
			$this->assertSame( 42, $data['users'][0]['id'] );
			$this->assertSame( 'Found User', $data['users'][0]['display_name'] );
		}
	}

	/**
	 * Its own process: it overloads WP_User_Query, which the bootstrap does not declare.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_search_user_finds_by_name_email(): void {
		$this->setup_valid_search_user( 'jane' );

		$user               = new \WP_User( 55 );
		$user->display_name = 'Jane Doe';
		$user->user_email   = 'jane@example.com';
		Mockery::mock( 'overload:WP_User_Query' )->shouldReceive( 'get_results' )->andReturn( array( $user ) );

		$ajax = new AdminAjax();

		try {
			$ajax->search_user();
			$this->fail( 'Expected AdminAjaxSuccessException to be thrown' );
		} catch ( AdminAjaxSuccessException $e ) {
			$data = $e->getData();
			$this->assertArrayHasKey( 'users', $data );
			$this->assertCount( 1, $data['users'] );
			$this->assertSame( 55, $data['users'][0]['id'] );
			$this->assertSame( 'Jane Doe', $data['users'][0]['display_name'] );
			$this->assertSame( 'j***@example.com', $data['users'][0]['email'], 'The picker lists the address masked (#1863).' );
		}
	}

	/**
	 * Its own process: it overloads WP_User_Query, which the bootstrap does not declare.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_search_user_returns_error_when_none_found(): void {
		$this->setup_valid_search_user( 'nonexistent' );
		Mockery::mock( 'overload:WP_User_Query' )->shouldReceive( 'get_results' )->andReturn( array() );

		$ajax = new AdminAjax();

		try {
			$ajax->search_user();
			$this->fail( 'Expected an error response' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'No users found', $e->getMessage() );
		}
		// The term carries no digits, so the CPF/RF fallback never queries.
		$this->assertSame( array(), $this->queries );
	}

	/**
	 * Its own process: it overloads WP_User_Query, which the bootstrap does not declare.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_search_user_falls_back_to_cpf_search(): void {
		// A CPF, so the numeric-id lookup and the name search find nobody.
		$this->setup_valid_search_user( Identifiers::CPF_A );
		Mockery::mock( 'overload:WP_User_Query' )->shouldReceive( 'get_results' )->andReturn( array() );

		// A submission carries that CPF's hash, linked to account 77.
		$this->identifier_owners[ 'cpf_hash=' . Encryption::hash( Identifiers::CPF_A ) ] = '77';
		$this->account( 77, 'cpf@example.com', 'CPF User' );

		$ajax = new AdminAjax();

		try {
			$ajax->search_user();
			$this->fail( 'Expected AdminAjaxSuccessException to be thrown' );
		} catch ( AdminAjaxSuccessException $e ) {
			$data = $e->getData();
			$this->assertArrayHasKey( 'users', $data );
			$this->assertCount( 1, $data['users'] );
			$this->assertSame( 77, $data['users'][0]['id'] );
			$this->assertSame( 'CPF User', $data['users'][0]['display_name'] );
		}
	}

	// ==================================================================
	// reveal_pii (#739 §3.3)
	// ==================================================================

	public function test_reveal_pii_rejects_unsupported_field(): void {
		$this->post_reveal( null, 5, 'ssn' );

		$ajax = new AdminAjax();
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/Unsupported field/' );
		$ajax->reveal_pii();
	}

	public function test_reveal_pii_denied_for_masked_tier(): void {
		$this->post_reveal( null, 5, 'cpf' );
		$this->store(
			'wp_ffc_submissions',
			5,
			array(
				'cpf_encrypted' => Encryption::encrypt( Identifiers::CPF_A ),
				'user_id'       => '9',
			)
		);

		$ajax = new AdminAjax();
		try {
			$ajax->reveal_pii();
			$this->fail( 'Expected a 403' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'permission to reveal', $e->getMessage() );
		}
		$this->assertContains( 'ffc_view_certificates_pii', $this->policy_checks );
		$this->assertSame( array(), ActivityLogBuffer::entries(), 'A refused reveal is not audited.' );
	}

	public function test_reveal_pii_cpf_success_formats_and_audits(): void {
		$this->post_reveal( null, 5, 'cpf' );
		$this->store(
			'wp_ffc_submissions',
			5,
			array(
				'cpf_encrypted' => Encryption::encrypt( Identifiers::CPF_A ),
				'user_id'       => '9',
			)
		);
		$this->viewer_caps[] = 'ffc_view_certificates_pii';

		$this->assertSame(
			array(
				'field' => 'cpf',
				'value' => self::masked_cpf_a(),
			),
			$this->reveal()
		);
		$this->assertAuditedOnce( 'submission_pii_revealed', array( 'field_key' => 'cpf' ), self::VIEWER, 5 );
	}

	public function test_reveal_pii_unmasked_tier_returns_value_and_audits(): void {
		$this->post_reveal( null, 5, 'cpf' );
		$this->store(
			'wp_ffc_submissions',
			5,
			array(
				'cpf_encrypted' => Encryption::encrypt( Identifiers::CPF_A ),
				'user_id'       => '9',
			)
		);
		// The owner of the record: the policy's unmasked tier.
		$this->current_user = 9;

		// Every served reveal reaches the audit (#1655): the unmasked tier
		// calls here from the submission edit page and the appointment detail
		// page, which mask for it.
		$this->assertSame( self::masked_cpf_a(), $this->reveal()['value'] );
		$this->assertSame( array(), $this->policy_checks, 'The owner tier checks no capability.' );
		$this->assertAuditedOnce( 'submission_pii_revealed', array( 'field_key' => 'cpf' ), 9, 5 );
	}

	public function test_reveal_pii_ip_returns_the_stored_ip_and_audits(): void {
		$this->post_reveal( null, 5, 'ip' );
		$this->store(
			'wp_ffc_submissions',
			5,
			array(
				'user_ip_encrypted' => Encryption::encrypt( '203.0.113.7' ),
				'user_id'           => '9',
			)
		);
		$this->viewer_caps[] = 'ffc_view_certificates_pii';

		$this->assertSame(
			array(
				'field' => 'ip',
				'value' => '203.0.113.7',
			),
			$this->reveal()
		);
		// The IP reveal goes through the same audit as email and CPF (#1760).
		$this->assertAuditedOnce( 'submission_pii_revealed', array( 'field_key' => 'ip' ), self::VIEWER, 5 );
	}

	public function test_reveal_pii_ip_denied_for_masked_tier(): void {
		$this->post_reveal( null, 5, 'ip' );
		$this->store(
			'wp_ffc_submissions',
			5,
			array(
				'user_ip_encrypted' => Encryption::encrypt( '203.0.113.7' ),
				'user_id'           => '9',
			)
		);

		$ajax = new AdminAjax();
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/permission to reveal/' );
		$ajax->reveal_pii();
	}

	public function test_reveal_pii_appointment_cpf_reveal_tier_returns_value(): void {
		$this->post_reveal( 'appointment', 7, 'cpf' );
		$this->store(
			'wp_ffc_self_scheduling_appointments',
			7,
			array(
				'cpf_encrypted' => Encryption::encrypt( Identifiers::CPF_A ),
				'user_id'       => '3',
			)
		);
		$this->viewer_caps[] = 'ffc_view_appointments_pii';

		$this->assertSame(
			array(
				'field' => 'cpf',
				'value' => self::masked_cpf_a(),
			),
			$this->reveal()
		);
		$this->assertAuditedOnce( 'appointment_pii_revealed', array( 'field_key' => 'cpf' ), self::VIEWER, 7 );
	}

	public function test_reveal_pii_appointment_unmasked_tier_returns_value_and_audits(): void {
		$this->post_reveal( 'appointment', 7, 'email' );
		$this->store(
			'wp_ffc_self_scheduling_appointments',
			7,
			array(
				'email_encrypted' => Encryption::encrypt( 'alice@example.com' ),
				'user_id'         => '3',
			)
		);
		$this->current_user = 3;

		// The appointment detail page masks for the unmasked tier too (#1813),
		// so this call is how that tier sees the value, and it is audited.
		$this->assertSame(
			array(
				'field' => 'email',
				'value' => 'alice@example.com',
			),
			$this->reveal()
		);
		$this->assertAuditedOnce( 'appointment_pii_revealed', array( 'field_key' => 'email' ), 3, 7 );
	}

	public function test_reveal_pii_appointment_masked_tier_denied(): void {
		$this->post_reveal( 'appointment', 7, 'email' );
		$this->store(
			'wp_ffc_self_scheduling_appointments',
			7,
			array(
				'email_encrypted' => Encryption::encrypt( 'x@y.com' ),
				'user_id'         => '3',
			)
		);

		$ajax = new AdminAjax();
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/permission to reveal/' );
		$ajax->reveal_pii();
	}

	// ==================================================================
	// reveal_pii() — reregistration (#1815)
	// ==================================================================

	public function test_reveal_pii_reregistration_email_comes_from_the_account_and_audits(): void {
		$this->post_reveal( 'reregistration', 11, 'email' );
		$this->store(
			'wp_ffc_reregistration_submissions',
			11,
			array(
				'user_id' => '3',
				'data'    => null,
			)
		);
		$this->account( 3, 'maria@example.org' );
		$this->viewer_caps[] = 'ffc_view_reregistration_pii';

		$this->assertSame(
			array(
				'field' => 'email',
				'value' => 'maria@example.org',
			),
			$this->reveal()
		);
		$this->assertSame( array( 'ffc_view_reregistration_pii' ), array_values( array_diff( $this->policy_checks, array( 'manage_options' ) ) ) );
		$this->assertAuditedOnce( 'reregistration_pii_revealed', array( 'field_key' => 'email' ), self::VIEWER, 11 );
		$this->assertOwnerTierFor( 3 );
	}

	public function test_reveal_pii_reregistration_decrypts_the_cpf_from_the_data_json(): void {
		$this->post_reveal( 'reregistration', 11, 'cpf' );
		$this->store(
			'wp_ffc_reregistration_submissions',
			11,
			array(
				'user_id' => '3',
				'data'    => (string) json_encode( array( 'fields' => array( 'cpf' => Encryption::encrypt( Identifiers::CPF_A ) ) ) ),
			)
		);
		$this->viewer_caps[] = 'ffc_view_reregistration_pii';

		$this->assertSame(
			array(
				'field' => 'cpf',
				'value' => self::masked_cpf_a(),
			),
			$this->reveal()
		);
		$this->assertCount( 1, ActivityLogBuffer::entries() );
	}

	public function test_reveal_pii_reregistration_masked_tier_denied(): void {
		$this->post_reveal( 'reregistration', 11, 'email' );
		$this->store(
			'wp_ffc_reregistration_submissions',
			11,
			array(
				'user_id' => '3',
				'data'    => null,
			)
		);
		$this->account( 3, 'maria@example.org' );

		$ajax = new AdminAjax();
		try {
			$ajax->reveal_pii();
			$this->fail( 'Expected a 403' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'permission to reveal', $e->getMessage() );
		}
		$this->assertSame( array(), ActivityLogBuffer::entries(), 'A refused reveal is not audited.' );
	}

	public function test_reveal_pii_reregistration_requires_the_surface_cap(): void {
		$this->post_reveal( 'reregistration', 11, 'email' );
		$this->viewer_caps = array_values( array_diff( $this->viewer_caps, array( 'ffc_view_reregistration' ) ) );

		$ajax = new AdminAjax();
		try {
			$ajax->reveal_pii();
			$this->fail( 'Expected a 403' );
		} catch ( \RuntimeException $e ) {
			$this->assertMatchesRegularExpression( '/You do not have permission\./', $e->getMessage() );
		}
		$this->assertSame( array(), $this->queries, 'The submission is never read.' );
	}

	public function test_reveal_pii_reregistration_reports_an_unknown_submission(): void {
		$this->post_reveal( 'reregistration', 11, 'email' );

		$ajax = new AdminAjax();
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/Record not found/' );
		$ajax->reveal_pii();
	}

	// ==================================================================
	// Account emails (#1824): the linked account of a submission and the
	// people on the audience screens.
	// ==================================================================

	public function test_reveal_pii_account_email_returns_the_linked_accounts_email_and_audits(): void {
		$this->post_reveal( null, 5, 'account_email' );
		$this->store(
			'wp_ffc_submissions',
			5,
			array(
				'email_encrypted' => Encryption::encrypt( 'typed@x.com' ),
				'user_id'         => '9',
			)
		);
		$this->account( 9, 'account@x.com' );
		// The submission's own gate, with its linked account as owner.
		$this->viewer_caps[] = 'ffc_view_certificates_pii';

		$this->assertSame(
			array(
				'field' => 'account_email',
				'value' => 'account@x.com',
			),
			$this->reveal(),
			'the account email, not the email typed on the submission'
		);
		$this->assertContains( 'ffc_view_certificates_pii', $this->policy_checks );
		$this->assertAuditedOnce( 'submission_pii_revealed', array( 'field_key' => 'account_email' ), self::VIEWER, 5 );
		$this->assertOwnerTierFor( 9 );
	}

	public function test_reveal_pii_account_email_denied_for_masked_tier(): void {
		$this->post_reveal( null, 5, 'account_email' );
		$this->store( 'wp_ffc_submissions', 5, array( 'user_id' => '9' ) );
		$this->account( 9, 'account@x.com' );

		$ajax = new AdminAjax();
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/permission to reveal/' );
		$ajax->reveal_pii();
	}

	public function test_reveal_pii_account_email_of_an_unlinked_submission_has_no_value(): void {
		$this->post_reveal( null, 5, 'account_email' );
		// No linked account; the viewer may reveal, so only the missing value refuses.
		$this->store( 'wp_ffc_submissions', 5, array( 'email_encrypted' => Encryption::encrypt( 'typed@x.com' ) ) );
		$this->viewer_caps[] = 'ffc_view_certificates_pii';

		$ajax = new AdminAjax();
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/No value to reveal/' );
		$ajax->reveal_pii();
	}

	public function test_reveal_pii_account_email_is_a_submission_field_only(): void {
		$this->post_reveal( 'appointment', 7, 'account_email' );

		$ajax = new AdminAjax();
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/Unsupported field/' );
		$ajax->reveal_pii();
	}

	public function test_reveal_pii_audience_account_returns_the_email_and_audits_under_its_own_action(): void {
		$this->post_reveal( 'audience_account', 7, 'email' );
		$this->audience_members = array( 7 );
		$this->account( 7, 'member@x.com' );
		// The audience module's own gate (#1863), with the listed account as owner.
		$this->viewer_caps[] = 'ffc_view_audiences_pii';

		$this->assertSame(
			array(
				'field' => 'email',
				'value' => 'member@x.com',
			),
			$this->reveal()
		);
		$this->assertSame( 1, count( array_keys( $this->cap_checks, 'ffc_view_audiences', true ) ), 'The surface cap is checked once.' );
		$this->assertContains( 'ffc_view_audiences_pii', $this->policy_checks );
		$this->assertAuditedOnce( 'audience_account_email_revealed', array( 'field_key' => 'email' ), self::VIEWER, 7 );
		$this->assertOwnerTierFor( 7 );
	}

	public function test_reveal_pii_audience_account_denied_for_masked_tier(): void {
		$this->post_reveal( 'audience_account', 7, 'email' );
		$this->audience_members = array( 7 );
		$this->account( 7, 'member@x.com' );

		$ajax = new AdminAjax();
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/permission to reveal/' );
		$ajax->reveal_pii();
	}

	public function test_reveal_pii_audience_account_refuses_an_account_no_audience_screen_lists(): void {
		$this->post_reveal( 'audience_account', 1, 'email' );
		$log = CallLog::record( 'get_userdata', false );

		$ajax = new AdminAjax();
		try {
			$ajax->reveal_pii();
			$this->fail( 'Expected a 404' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'Record not found', $e->getMessage() );
		}
		$this->assertSame( 0, $log->count(), 'the account is not even loaded' );
	}

	public function test_reveal_pii_audience_account_serves_only_the_email(): void {
		$this->post_reveal( 'audience_account', 7, 'cpf' );

		$ajax = new AdminAjax();
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/Unsupported field/' );
		$ajax->reveal_pii();
	}

	/**
	 * @return array<string, array{0: string, 1: string, 2: string, 3: string, 4: string, 5: string}>
	 */
	public function address_reveal_types(): array {
		return array(
			'activity log entry'   => array( 'activity_log', 'ffc_view_activity_log', 'ffc_view_activity_log_pii', 'activity_log_ip_revealed', 'wp_ffc_activity_log', 'user_ip_encrypted' ),
			'rate limit log entry' => array( 'rate_limit_log', 'ffc_view_settings', 'ffc_view_settings_pii', 'rate_limit_ip_revealed', 'wp_ffc_rate_limit_logs', 'ip_address_encrypted' ),
		);
	}

	/**
	 * A client address with no owner reveals through its screen's own cap,
	 * audited under its own action (#2109).
	 *
	 * @dataProvider address_reveal_types
	 */
	public function test_reveal_pii_an_address_reveals_through_its_screen_cap_and_audits( string $type, string $surface, string $pii_cap, string $action, string $table, string $column ): void {
		$this->post_reveal( $type, 31, 'ip' );
		$this->store( $table, 31, array( $column => Encryption::encrypt( '2804:14c::9' ) ) );
		$this->viewer_caps[] = $pii_cap;

		$this->assertSame(
			array(
				'field' => 'ip',
				'value' => '2804:14c::9',
			),
			$this->reveal()
		);
		$this->assertSame( 1, count( array_keys( $this->cap_checks, $surface, true ) ), 'The surface cap is checked once.' );
		$this->assertCount( 1, $this->queries, 'The entry is read once.' );
		$this->assertStringContainsString( "FROM {$table} WHERE id = 31", $this->queries[0] );
		$this->assertAuditedOnce( $action, array( 'field_key' => 'ip' ), self::VIEWER, 31 );

		// A log line has no owner, so the owner tier can never apply: even
		// the account whose id equals the entry's goes through the capability.
		$this->current_user  = 31;
		$this->viewer_caps   = array( $surface );
		$this->policy_checks = array();
		$ajax                = new AdminAjax();
		try {
			$ajax->reveal_pii();
			$this->fail( 'Expected a 403' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'permission to reveal', $e->getMessage() );
		}
		$this->assertContains( $pii_cap, $this->policy_checks );
	}

	/**
	 * @dataProvider address_reveal_types
	 */
	public function test_reveal_pii_an_address_is_refused_without_the_reveal_cap( string $type, string $surface, string $pii_cap, string $action, string $table, string $column ): void {
		unset( $surface, $pii_cap, $action );
		$this->post_reveal( $type, 31, 'ip' );
		$this->store( $table, 31, array( $column => Encryption::encrypt( '203.0.113.9' ) ) );

		$ajax = new AdminAjax();
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/permission to reveal/' );
		$ajax->reveal_pii();
	}

	/**
	 * @dataProvider address_reveal_types
	 */
	public function test_reveal_pii_an_entry_without_an_address_is_not_found( string $type, string $surface, string $pii_cap, string $action, string $table, string $column ): void {
		unset( $surface, $pii_cap, $action, $column );
		$this->post_reveal( $type, 31, 'ip' );
		// The entry exists and carries no address.
		$this->store( $table, 31, array( 'identifier' => 'form:3' ) );

		$ajax = new AdminAjax();
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/Record not found/' );
		$ajax->reveal_pii();
	}

	/**
	 * @dataProvider address_reveal_types
	 */
	public function test_reveal_pii_an_address_type_serves_only_the_address( string $type ): void {
		$this->post_reveal( $type, 31, 'email' );

		$ajax = new AdminAjax();
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/Unsupported field/' );
		$ajax->reveal_pii();
	}

	// ==================================================================
	// reveal_pii_page (#1863)
	// ==================================================================

	/**
	 * Post a page reveal of these items.
	 *
	 * @param array<int, array<string, mixed>> $items Items.
	 * @return void
	 */
	private function post_page( array $items ): void {
		$_POST['nonce'] = 'n';
		$_POST['items'] = (string) json_encode( $items );
	}

	public function test_reveal_pii_page_registers_its_action(): void {
		$registered = array();
		Functions\when( 'add_action' )->alias(
			static function ( $hook ) use ( &$registered ) {
				$registered[] = $hook;
				return true;
			}
		);

		new AdminAjax();

		$this->assertContains( 'wp_ajax_ffc_reveal_pii_page', $registered );
	}

	public function test_reveal_pii_page_reveals_every_allowed_value_with_one_audit_row(): void {
		$this->post_page(
			array(
				array( 'type' => 'submission', 'id' => 5, 'field' => 'email' ),
				array( 'type' => 'submission', 'id' => 6, 'field' => 'email' ),
			)
		);
		foreach ( array( 5, 6 ) as $id ) {
			$this->store(
				'wp_ffc_submissions',
				$id,
				array(
					'email_encrypted' => Encryption::encrypt( 'p' . $id . '@x.com' ),
					'user_id'         => (string) ( 90 + $id ),
				)
			);
		}
		$this->viewer_caps[] = 'ffc_view_certificates_pii';

		$ajax = new AdminAjax();
		try {
			$ajax->reveal_pii_page();
			$this->fail( 'Expected AdminAjaxSuccessException' );
		} catch ( AdminAjaxSuccessException $e ) {
			$this->assertSame(
				array(
					'values' => array(
						array( 'type' => 'submission', 'id' => 5, 'field' => 'email', 'value' => 'p5@x.com' ),
						array( 'type' => 'submission', 'id' => 6, 'field' => 'email', 'value' => 'p6@x.com' ),
					),
				),
				$e->getData()
			);
		}

		// One audit row for the page, naming every record.
		$this->assertAuditedOnce(
			'submission_pii_revealed',
			array(
				'field_key'  => 'page',
				'fields'     => array( 'email' ),
				'record_ids' => array( 5, 6 ),
			),
			self::VIEWER,
			0
		);
	}

	public function test_reveal_pii_page_skips_what_the_policy_refuses_and_logs_one_row_per_domain(): void {
		$this->post_page(
			array(
				array( 'type' => 'submission', 'id' => 5, 'field' => 'email' ),
				array( 'type' => 'audience_account', 'id' => 7, 'field' => 'email' ),
				array( 'type' => 'submission', 'id' => 6, 'field' => 'ssn' ),
				array( 'type' => 'nope', 'id' => 1, 'field' => 'email' ),
			)
		);
		$this->store(
			'wp_ffc_submissions',
			5,
			array(
				'email_encrypted' => Encryption::encrypt( 'p@x.com' ),
				'user_id'         => '9',
			)
		);
		$this->audience_members = array( 7 );
		$this->account( 7, 'member@x.com' );
		// The certificates PII cap, and not the audiences one.
		$this->viewer_caps[] = 'ffc_view_certificates_pii';

		$ajax = new AdminAjax();
		try {
			$ajax->reveal_pii_page();
			$this->fail( 'Expected AdminAjaxSuccessException' );
		} catch ( AdminAjaxSuccessException $e ) {
			$data = $e->getData();
			$this->assertCount( 1, $data['values'], 'The audience email is masked for this user, the field and type unknown.' );
			$this->assertSame( 'p@x.com', $data['values'][0]['value'] );
		}

		$this->assertCount( 1, ActivityLogBuffer::entries() );
		$this->assertSame( 'submission_pii_revealed', ActivityLogBuffer::contexts()[0]['action'] );
	}

	public function test_reveal_pii_page_skips_a_domain_whose_surface_cap_is_missing(): void {
		$this->post_page( array( array( 'type' => 'submission', 'id' => 5, 'field' => 'email' ) ) );
		$this->viewer_caps = array_values( array_diff( $this->viewer_caps, array( 'ffc_view_certificates' ) ) );
		$log               = CallLog::record( 'get_userdata', false );

		$ajax = new AdminAjax();
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/permission to reveal these values/' );
		try {
			$ajax->reveal_pii_page();
		} finally {
			$this->assertSame( 0, $log->count() );
			$this->assertSame( array(), $this->queries );
		}
	}

	public function test_reveal_pii_page_refuses_an_empty_request(): void {
		$_POST['nonce'] = 'n';
		$_POST['items'] = 'not json';

		$ajax = new AdminAjax();
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/Nothing to reveal/' );
		$ajax->reveal_pii_page();
	}

	public function test_reveal_pii_page_refuses_more_than_a_page(): void {
		$this->post_page( array_fill( 0, 501, array( 'type' => 'submission', 'id' => 5, 'field' => 'email' ) ) );

		$ajax = new AdminAjax();
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/Too many values/' );
		$ajax->reveal_pii_page();
	}

	public function test_reveal_pii_page_checks_the_nonce(): void {
		$this->post_page( array( array( 'type' => 'submission', 'id' => 5, 'field' => 'email' ) ) );
		Functions\when( 'wp_verify_nonce' )->justReturn( false );

		$ajax = new AdminAjax();
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/Security check failed/' );
		$ajax->reveal_pii_page();
	}

	// ==================================================================
	// New reveal types (#1863)
	// ==================================================================

	public function test_reveal_pii_user_profile_serves_a_sensitive_field_to_whoever_may_edit_the_user(): void {
		$this->post_reveal( 'user_profile', 12, 'cf_3' );
		$this->editable_users = array( 12 );
		$this->account( 12, 'person@x.com' );
		// Account 12 is in audience 2, whose custom field 3 is sensitive free text.
		$this->tables['wp_ffc_audiences'][2]     = array(
			'id'        => '2',
			'name'      => 'Teachers',
			'parent_id' => null,
			'status'    => 'active',
			'members'   => array( 12 ),
		);
		$this->tables['wp_ffc_custom_fields'][3] = array(
			'id'                => '3',
			'audience_id'       => '2',
			'field_type'        => 'text',
			'is_sensitive'      => '1',
			'field_profile_key' => '',
		);
		$this->user_meta[12]['ffc_custom_fields_data'] = array( 'field_3' => Identifiers::RF_A );
		$this->viewer_caps[]                            = 'ffc_view_audiences_pii';

		$this->assertSame(
			array(
				'field' => 'cf_3',
				'value' => Identifiers::RF_A,
			),
			$this->reveal()
		);
		$this->assertContains( 'ffc_view_audiences_pii', $this->policy_checks );
		$this->assertAuditedOnce( 'user_profile_pii_revealed', array( 'field_key' => 'cf_3' ), self::VIEWER, 12 );
		$this->editable_users[] = 12;
		$this->assertOwnerTierFor( 12 );
	}

	public function test_reveal_pii_user_profile_refuses_a_user_the_viewer_cannot_edit(): void {
		$this->post_reveal( 'user_profile', 12, 'cf_3' );
		$this->account( 12, 'person@x.com' );

		$ajax = new AdminAjax();
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/Record not found/' );
		$ajax->reveal_pii();
	}

	public function test_reveal_pii_user_profile_serves_only_custom_field_keys(): void {
		$this->post_reveal( 'user_profile', 12, 'email' );

		$ajax = new AdminAjax();
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/Unsupported field/' );
		$ajax->reveal_pii();
	}

	public function test_reveal_pii_date_messages_person_serves_the_name_of_a_listed_person(): void {
		$this->post_reveal( 'date_messages_person', 15, 'name' );
		$this->account( 15, 'maria@x.com', 'Maria da Silva' );
		// A birth date on the profile is what lists a person.
		$this->user_meta[15]['ffc_user_birth_date'] = (string) Encryption::encrypt( '1990-05-20' );
		$this->viewer_caps[]                        = 'ffc_view_date_messages_pii';

		$this->assertSame( 'Maria da Silva', $this->reveal()['value'] );
		$this->assertContains( 'ffc_view_date_messages_pii', $this->policy_checks );
		$this->assertAuditedOnce( 'date_messages_pii_revealed', array( 'field_key' => 'name' ), self::VIEWER, 15 );
		$this->assertOwnerTierFor( 15 );
	}

	public function test_reveal_pii_date_messages_person_refuses_someone_the_panel_cannot_list(): void {
		$this->post_reveal( 'date_messages_person', 15, 'name' );
		// No birth date: the upcoming-dates panel never lists this account.
		$this->account( 15, 'maria@x.com', 'Maria' );

		$ajax = new AdminAjax();
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/Record not found/' );
		$ajax->reveal_pii();
	}
}

/**
 * Custom exception to capture wp_send_json_success data in tests.
 */
class AdminAjaxSuccessException extends \RuntimeException {

	/** @var mixed */
	private $data;

	/**
	 * @param mixed $data The success response data.
	 */
	public function __construct( $data = null ) {
		$this->data = $data;
		parent::__construct( 'wp_send_json_success' );
	}

	/**
	 * @return mixed
	 */
	public function getData() {
		return $this->data;
	}
}
