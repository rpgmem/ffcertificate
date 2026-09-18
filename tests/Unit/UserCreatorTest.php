<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\UserDashboard\CapabilityManager;
use FreeFormCertificate\UserDashboard\UserCreator;

/**
 * Tests for UserCreator: user creation, orphan linking, username generation.
 */
class UserCreatorTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		global $wpdb;
		$wpdb = Mockery::mock( 'wpdb' )->makePartial();
		$wpdb->prefix = 'wp_';
		$wpdb->posts  = 'wp_posts';
		$wpdb->last_error = '';

		Functions\when( 'current_time' )->justReturn( '2026-02-17 12:00:00' );
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( '__' )->returnArg();
		Functions\when( 'absint' )->alias( function( $val ) { return abs( intval( $val ) ); } );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'sanitize_key' )->alias( function( $key ) { return strtolower( preg_replace( '/[^a-zA-Z0-9_\-]/', '', $key ) ); } );
		Functions\when( 'get_option' )->justReturn( 0 );
		Functions\when( 'do_action' )->justReturn( null );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'is_wp_error' )->alias( function( $thing ) { return $thing instanceof \WP_Error; } );

		// The identity index (#1313 PR 4) writes through UserProfileRepository,
		// whose AbstractRepository invalidates the object cache after a write.
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		Functions\when( 'wp_cache_flush' )->justReturn( true );

		// Namespaced stubs: prevent "is not defined" errors when Sprint 27 tests run first.
		// Core namespace (Debug calls get_option/get_current_user_id).
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// ------------------------------------------------------------------
	// generate_username() — public, easy to test in isolation
	// ------------------------------------------------------------------

	public function test_generate_username_from_email_prefix(): void {
		Functions\when( 'sanitize_user' )->returnArg();
		Functions\when( 'remove_accents' )->returnArg();
		Functions\when( 'username_exists' )->justReturn( false );

		$username = UserCreator::generate_username(
			'alice@example.com',
			array( 'nome_completo' => 'Alice Silva' )
		);

		// Email prefix wins over name per the plugin-wide convention.
		$this->assertSame( 'alice', $username );
	}

	public function test_generate_username_falls_back_to_name_when_email_is_empty(): void {
		Functions\when( 'sanitize_user' )->returnArg();
		Functions\when( 'remove_accents' )->returnArg();
		Functions\when( 'username_exists' )->justReturn( false );

		$username = UserCreator::generate_username(
			'',
			array( 'nome' => 'Bob Santos' )
		);

		$this->assertSame( 'bobsantos', $username );
	}

	public function test_generate_username_falls_back_to_name_when_email_prefix_too_short(): void {
		Functions\when( 'sanitize_user' )->returnArg();
		Functions\when( 'remove_accents' )->returnArg();
		Functions\when( 'username_exists' )->justReturn( false );

		$username = UserCreator::generate_username(
			'a@example.com',
			array( 'name' => 'Carol Oliveira' )
		);

		$this->assertSame( 'carololiveira', $username );
	}

	public function test_generate_username_increments_on_collision(): void {
		Functions\when( 'sanitize_user' )->returnArg();
		Functions\when( 'remove_accents' )->returnArg();

		$call_count = 0;
		Functions\when( 'username_exists' )->alias( function( $name ) use ( &$call_count ) {
			$call_count++;
			return $call_count <= 2;
		} );

		$username = UserCreator::generate_username(
			'dave.costa@example.com',
			array( 'nome_completo' => 'Dave Costa' )
		);

		// 'dave.costa' taken (call 1), 'dave.costa.2' taken (call 2), 'dave.costa.3' OK (call 3).
		$this->assertSame( 'dave.costa.3', $username );
	}

	public function test_generate_username_falls_back_to_random_when_email_and_name_too_short(): void {
		Functions\when( 'sanitize_user' )->returnArg();
		Functions\when( 'remove_accents' )->returnArg();
		Functions\when( 'username_exists' )->justReturn( false );
		Functions\when( 'wp_generate_password' )->justReturn( 'abcd1234' );

		$username = UserCreator::generate_username(
			'x@example.com',
			array( 'nome_completo' => 'ab' )
		);

		$this->assertStringStartsWith( 'ffc_', $username );
	}

	public function test_generate_username_falls_back_to_random_when_no_data(): void {
		Functions\when( 'sanitize_user' )->returnArg();
		Functions\when( 'remove_accents' )->returnArg();
		Functions\when( 'username_exists' )->justReturn( false );
		Functions\when( 'wp_generate_password' )->justReturn( 'xyz78901' );

		$username = UserCreator::generate_username( '', array() );

		$this->assertStringStartsWith( 'ffc_', $username );
		$this->assertSame( 'ffc_xyz78901', $username );
	}

	public function test_generate_username_strips_special_characters(): void {
		Functions\when( 'sanitize_user' )->returnArg();
		Functions\when( 'remove_accents' )->alias( function( $s ) { return $s; } );
		Functions\when( 'username_exists' )->justReturn( false );

		// Email prefix already lacks accents, so this just confirms the
		// non-alphanumeric strip on the prefix path.
		$username = UserCreator::generate_username(
			'joão.silva@example.com',
			array()
		);

		// Accents removed by remove_accents stub (no-op here); the
		// [^a-z0-9._-] strip removes the accented chars in input.
		// Accent stripped by the regex (`ã` outside [a-z0-9._-] → dropped).
		$this->assertSame( 'joo.silva', $username );
	}

	// ------------------------------------------------------------------
	// get_or_create_user() — step 1: existing user_id found via CPF hash
	// ------------------------------------------------------------------

	public function test_get_or_create_user_returns_existing_user_when_cpf_matched(): void {
		global $wpdb;

		// Allow flexible calls to prepare and get_var
		$wpdb->shouldReceive( 'prepare' )->andReturn( 'QUERY' );
		$wpdb->shouldReceive( 'get_var' )->andReturn( '42' );
		$wpdb->shouldReceive( 'query' )->andReturn( 0 );

		// grant_context_capabilities → get_userdata returns null (skip grant)
		Functions\when( 'get_userdata' )->justReturn( null );

		$result = UserCreator::get_or_create_user( 'hash123', 'test@example.com', array() );

		$this->assertSame( 42, $result );
	}

	public function test_get_or_create_user_links_to_existing_wp_user_by_email(): void {
		global $wpdb;

		$wpdb->shouldReceive( 'prepare' )->andReturn( 'QUERY' );
		$wpdb->shouldReceive( 'get_var' )->andReturn( null );
		$wpdb->shouldReceive( 'query' )->andReturn( 0 );

		$mock_user = Mockery::mock( 'WP_User' );
		$mock_user->ID = 55;
		$mock_user->display_name = 'Existing User';
		$mock_user->user_login = 'existinguser';
		$mock_user->shouldReceive( 'add_role' )->with( 'ffc_end_user' )->once();

		Functions\when( 'get_user_by' )->justReturn( $mock_user );
		Functions\when( 'get_userdata' )->justReturn( null );
		Functions\when( 'wp_update_user' )->justReturn( 55 );
		Functions\when( 'update_user_meta' )->justReturn( true );

		$result = UserCreator::get_or_create_user( 'newhash', 'existing@example.com', array() );

		$this->assertSame( 55, $result );
	}

	public function test_get_or_create_user_creates_new_user_when_email_not_found(): void {
		global $wpdb;

		$wpdb->shouldReceive( 'prepare' )->andReturn( 'QUERY' );
		$wpdb->shouldReceive( 'get_var' )->andReturn( null );
		$wpdb->shouldReceive( 'query' )->andReturn( 0 );
		$wpdb->shouldReceive( 'get_results' )->andReturn( array() );

		Functions\when( 'get_user_by' )->justReturn( false );
		Functions\when( 'wp_generate_password' )->justReturn( 'SecurePass123!' );
		Functions\when( 'sanitize_user' )->returnArg();
		Functions\when( 'remove_accents' )->returnArg();
		Functions\when( 'username_exists' )->justReturn( false );
		Functions\when( 'wp_create_user' )->justReturn( 100 );
		Functions\when( 'get_userdata' )->justReturn( null );
		Functions\when( 'wp_update_user' )->justReturn( 100 );
		Functions\when( 'update_user_meta' )->justReturn( true );
		Functions\when( 'wp_new_user_notification' )->justReturn( null );

		$result = UserCreator::get_or_create_user(
			'brandhash',
			'brand@new.com',
			array( 'nome_completo' => 'Brand New User' )
		);

		$this->assertSame( 100, $result );
	}

	public function test_get_or_create_user_returns_wp_error_on_create_failure(): void {
		global $wpdb;

		$wpdb->shouldReceive( 'prepare' )->andReturn( 'QUERY' );
		$wpdb->shouldReceive( 'get_var' )->andReturn( null );

		Functions\when( 'get_user_by' )->justReturn( false );
		Functions\when( 'wp_generate_password' )->justReturn( 'Pass123!' );
		Functions\when( 'sanitize_user' )->returnArg();
		Functions\when( 'remove_accents' )->returnArg();
		Functions\when( 'username_exists' )->justReturn( false );

		$wp_error = new \WP_Error( 'create_failed', 'User creation failed' );
		Functions\when( 'wp_create_user' )->justReturn( $wp_error );

		$result = UserCreator::get_or_create_user(
			'failhash',
			'fail@example.com',
			array( 'nome_completo' => 'Fail User' )
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	// ------------------------------------------------------------------
	// get_or_create_user() — capability granting via context
	// ------------------------------------------------------------------

	public function test_get_or_create_user_grants_appointment_capabilities_for_appointment_context(): void {
		global $wpdb;

		$wpdb->shouldReceive( 'prepare' )->andReturn( 'QUERY' );
		$wpdb->shouldReceive( 'get_var' )->andReturn( '42' );
		$wpdb->shouldReceive( 'query' )->andReturn( 0 );

		$mock_user = Mockery::mock( 'WP_User' );
		$mock_user->shouldReceive( 'has_cap' )->andReturn( false );
		$mock_user->shouldReceive( 'add_cap' )->times( 3 ); // 3 appointment caps
		$mock_user->ID = 42;
		$mock_user->user_email = 'test@example.com';
		$mock_user->display_name = 'Test User';

		Functions\when( 'get_userdata' )->justReturn( $mock_user );

		$result = UserCreator::get_or_create_user( 'hash123', 'test@example.com', array(), 'appointment' );

		$this->assertSame( 42, $result );
	}

	// ------------------------------------------------------------------
	// get_or_create_user() — identifier_type parameter
	// ------------------------------------------------------------------

	public function test_get_or_create_user_accepts_cpf_identifier_type(): void {
		global $wpdb;

		$wpdb->shouldReceive( 'prepare' )->andReturn( 'QUERY' );
		$wpdb->shouldReceive( 'get_var' )->andReturn( '77' );
		$wpdb->shouldReceive( 'query' )->andReturn( 0 );

		Functions\when( 'get_userdata' )->justReturn( null );

		$result = UserCreator::get_or_create_user( 'cpfhash', 'cpf@example.com', array(), 'certificate', UserCreator::TYPE_CPF );

		$this->assertSame( 77, $result );
	}

	public function test_get_or_create_user_accepts_rf_identifier_type(): void {
		global $wpdb;

		$wpdb->shouldReceive( 'prepare' )->andReturn( 'QUERY' );
		$wpdb->shouldReceive( 'get_var' )->andReturn( '88' );
		$wpdb->shouldReceive( 'query' )->andReturn( 0 );

		Functions\when( 'get_userdata' )->justReturn( null );

		$result = UserCreator::get_or_create_user( 'rfhash', 'rf@example.com', array(), 'certificate', UserCreator::TYPE_RF );

		$this->assertSame( 88, $result );
	}

	// ------------------------------------------------------------------
	// build_hash_where_clause / build_hash_params — via reflection
	// ------------------------------------------------------------------

	public function test_build_hash_where_clause_cpf(): void {
		$method = new \ReflectionMethod( UserCreator::class, 'build_hash_where_clause' );
		$method->setAccessible( true );

		$clause = $method->invoke( null, UserCreator::TYPE_CPF );
		$this->assertSame( 'cpf_hash = %s', $clause );
	}

	public function test_build_hash_where_clause_rf(): void {
		$method = new \ReflectionMethod( UserCreator::class, 'build_hash_where_clause' );
		$method->setAccessible( true );

		$clause = $method->invoke( null, UserCreator::TYPE_RF );
		$this->assertSame( 'rf_hash = %s', $clause );
	}

	public function test_build_hash_where_clause_auto(): void {
		$method = new \ReflectionMethod( UserCreator::class, 'build_hash_where_clause' );
		$method->setAccessible( true );

		$clause = $method->invoke( null, UserCreator::TYPE_AUTO );
		$this->assertSame( 'cpf_hash = %s OR rf_hash = %s', $clause );
	}

	public function test_build_hash_params_cpf_returns_one(): void {
		$method = new \ReflectionMethod( UserCreator::class, 'build_hash_params' );
		$method->setAccessible( true );

		$params = $method->invoke( null, 'myhash', UserCreator::TYPE_CPF );
		$this->assertCount( 1, $params );
		$this->assertSame( array( 'myhash' ), $params );
	}

	public function test_build_hash_params_auto_returns_two(): void {
		$method = new \ReflectionMethod( UserCreator::class, 'build_hash_params' );
		$method->setAccessible( true );

		$params = $method->invoke( null, 'myhash', UserCreator::TYPE_AUTO );
		$this->assertCount( 2, $params );
		$this->assertSame( array( 'myhash', 'myhash' ), $params );
	}

	// ------------------------------------------------------------------
	// get_or_create_user_dual() — recruitment-style two-hash entry point
	// ------------------------------------------------------------------

	public function test_dual_returns_wp_error_when_everything_empty(): void {
		$result = UserCreator::get_or_create_user_dual( null, null, '' );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ffc_user_no_identifier', $result->get_error_code() );
	}

	public function test_dual_sends_both_hashes_to_prepare_when_both_supplied(): void {
		global $wpdb;
		$captured = array();
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			function ( $sql, ...$args ) use ( &$captured ) {
				$captured[] = array( 'sql' => $sql, 'args' => $args );
				return 'QUERY';
			}
		);
		$wpdb->shouldReceive( 'get_var' )->andReturn( '321' );
		$wpdb->shouldReceive( 'query' )->andReturn( 0 );
		Functions\when( 'get_userdata' )->justReturn( null );

		$result = UserCreator::get_or_create_user_dual( 'CPF-HASH', 'RF-HASH', 'who@cares.com' );
		$this->assertSame( 321, $result );

		// SELECT step must reference both columns AND both placeholder values.
		// The table is the identity index: since #1313 PR 4 it is asked first,
		// and a hit there means `ffc_submissions` is never queried at all.
		$lookup = $captured[0];
		$this->assertStringContainsString( 'cpf_hash = %s', (string) $lookup['sql'] );
		$this->assertStringContainsString( 'rf_hash = %s', (string) $lookup['sql'] );
		// Args after the %i table name are the two hash values, in order.
		$this->assertSame( array( 'wp_ffc_user_profiles', 'CPF-HASH', 'RF-HASH' ), $lookup['args'] );
	}

	public function test_dual_omits_missing_hash_from_lookup(): void {
		global $wpdb;
		$captured = array();
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			function ( $sql, ...$args ) use ( &$captured ) {
				$captured[] = array( 'sql' => $sql, 'args' => $args );
				return 'QUERY';
			}
		);
		$wpdb->shouldReceive( 'get_var' )->andReturn( '7' );
		$wpdb->shouldReceive( 'query' )->andReturn( 0 );
		Functions\when( 'get_userdata' )->justReturn( null );

		UserCreator::get_or_create_user_dual( null, 'ONLY-RF', 'x@y.com' );

		$lookup = $captured[0];
		$this->assertStringContainsString( 'rf_hash = %s', (string) $lookup['sql'] );
		$this->assertStringNotContainsString( 'cpf_hash', (string) $lookup['sql'] );
		$this->assertSame( array( 'wp_ffc_user_profiles', 'ONLY-RF' ), $lookup['args'] );
	}

	/**
	 * Linking a record to a user feeds that user's identity index (#1313 PR 4).
	 *
	 * THE INVARIANT. Without it the index only ever learns about someone who
	 * edits a profile field — a subset nobody can state — and the resolver
	 * above could never collapse to one query, because the fallback would keep
	 * finding people the index had never heard of.
	 */
	public function test_linking_a_record_feeds_the_identity_index(): void {
		global $wpdb;
		$writes = array();
		$wpdb->shouldReceive( 'prepare' )->andReturn( 'QUERY' );
		$wpdb->shouldReceive( 'get_var' )->andReturn( '321' );
		$wpdb->shouldReceive( 'get_row' )->andReturn( null );
		$wpdb->shouldReceive( 'query' )->andReturn( 0 );
		$wpdb->shouldReceive( 'update' )->andReturnUsing(
			function ( $table, $data, $where ) use ( &$writes ) {
				$writes[] = array(
					'table' => $table,
					'data'  => $data,
					'where' => $where,
				);
				return 1;
			}
		);
		Functions\when( 'get_userdata' )->justReturn( null );

		UserCreator::get_or_create_user_dual( 'CPF-HASH', 'RF-HASH', 'who@cares.com' );

		$index = array_values(
			array_filter(
				$writes,
				static fn( array $w ): bool => 'wp_ffc_user_profiles' === $w['table']
			)
		);

		$this->assertCount( 1, $index, 'The identity index was not written, so the user stays invisible to the indexed lookup.' );
		$this->assertSame( 'CPF-HASH', $index[0]['data']['cpf_hash'] );
		$this->assertSame( 'RF-HASH', $index[0]['data']['rf_hash'] );
		$this->assertSame( 321, (int) $index[0]['where']['user_id'] );
	}

	/**
	 * The index FILLS; it never overwrites a value already there.
	 *
	 * A column holding a different hash is a person with two identifiers on
	 * record, and the honest answer is not to pick one silently — it is the
	 * conflict #1313 wants counted before anyone designs a merge policy.
	 * Overwriting would destroy that evidence and leave the index asserting
	 * whichever write happened last.
	 */
	public function test_the_index_is_filled_but_never_overwritten(): void {
		global $wpdb;
		$writes = array();
		$wpdb->shouldReceive( 'prepare' )->andReturn( 'QUERY' );
		$wpdb->shouldReceive( 'get_var' )->andReturn( '321' );
		$wpdb->shouldReceive( 'get_row' )->andReturn(
			array(
				'user_id'  => '321',
				'cpf_hash' => 'ALREADY-ON-RECORD',
				'rf_hash'  => null,
			)
		);
		$wpdb->shouldReceive( 'query' )->andReturn( 0 );
		$wpdb->shouldReceive( 'update' )->andReturnUsing(
			function ( $table, $data ) use ( &$writes ) {
				$writes[] = array(
					'table' => $table,
					'data'  => $data,
				);
				return 1;
			}
		);
		Functions\when( 'get_userdata' )->justReturn( null );

		UserCreator::get_or_create_user_dual( 'A-DIFFERENT-CPF', 'RF-HASH', 'who@cares.com' );

		$index = array_values(
			array_filter(
				$writes,
				static fn( array $w ): bool => 'wp_ffc_user_profiles' === $w['table']
			)
		);

		$this->assertCount( 1, $index );
		$this->assertArrayNotHasKey(
			'cpf_hash',
			$index[0]['data'],
			'A CPF already on record was overwritten, which destroys the very conflict the index exists to surface.'
		);
		$this->assertSame( 'RF-HASH', $index[0]['data']['rf_hash'], 'The empty column should still have been filled.' );
	}

	/**
	 * The index is asked first and `ffc_submissions` is the fallback (#1313).
	 *
	 * Reading the index ALONE is what the issue proposed and what measurement
	 * refused: a certificate submission writes `ffc_submissions.cpf_hash` and
	 * never touches the profile, so a user known only through a submission has
	 * no profile row. Dropping the fallback would stop finding them and create
	 * a duplicate — the exact defect this work exists to remove.
	 *
	 * Both halves are asserted: the ORDER, because a submissions-first lookup
	 * would never exercise the index; and that the fallback runs at all, which
	 * is what the collapse to one query would remove.
	 */
	public function test_dual_falls_back_to_submissions_when_the_index_misses(): void {
		global $wpdb;
		$captured = array();
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			function ( $sql, ...$args ) use ( &$captured ) {
				$captured[] = array( 'sql' => $sql, 'args' => $args );
				return 'QUERY';
			}
		);
		// The index misses, the submissions table answers.
		$wpdb->shouldReceive( 'get_var' )->andReturnValues( array( null, '55' ) );
		$wpdb->shouldReceive( 'query' )->andReturn( 0 );
		Functions\when( 'get_userdata' )->justReturn( null );

		$result = UserCreator::get_or_create_user_dual( 'CPF-HASH', null, 'who@cares.com' );

		$this->assertSame( 55, $result );
		$this->assertSame( 'wp_ffc_user_profiles', $captured[0]['args'][0] );
		$this->assertSame( 'wp_ffc_submissions', $captured[1]['args'][0] );
	}

	public function test_dual_falls_back_to_email_when_no_submission_hash_match(): void {
		global $wpdb;
		$wpdb->shouldReceive( 'prepare' )->andReturn( 'QUERY' );
		$wpdb->shouldReceive( 'get_var' )->andReturn( null );
		$wpdb->shouldReceive( 'query' )->andReturn( 0 );

		$mock_user               = Mockery::mock( 'WP_User' );
		$mock_user->ID           = 91;
		$mock_user->display_name = 'Already Has Email';
		$mock_user->user_login   = 'alreadyhasemail';
		$mock_user->shouldReceive( 'add_role' )->with( 'ffc_end_user' )->once();

		Functions\when( 'get_user_by' )->justReturn( $mock_user );
		Functions\when( 'get_userdata' )->justReturn( null );
		Functions\when( 'wp_update_user' )->justReturn( 91 );
		Functions\when( 'update_user_meta' )->justReturn( true );

		$result = UserCreator::get_or_create_user_dual( 'UNSEEN-CPF', 'UNSEEN-RF', 'known@user.com' );
		$this->assertSame( 91, $result );
	}

	public function test_dual_creates_new_user_when_nothing_matches(): void {
		global $wpdb;
		$wpdb->shouldReceive( 'prepare' )->andReturn( 'QUERY' );
		$wpdb->shouldReceive( 'get_var' )->andReturn( null );
		$wpdb->shouldReceive( 'query' )->andReturn( 0 );
		$wpdb->shouldReceive( 'get_results' )->andReturn( array() );

		Functions\when( 'get_user_by' )->justReturn( false );
		Functions\when( 'wp_generate_password' )->justReturn( 'pw' );
		Functions\when( 'sanitize_user' )->returnArg();
		Functions\when( 'remove_accents' )->returnArg();
		Functions\when( 'username_exists' )->justReturn( false );
		Functions\when( 'wp_create_user' )->justReturn( 555 );
		Functions\when( 'get_userdata' )->justReturn( null );
		Functions\when( 'wp_update_user' )->justReturn( 555 );
		Functions\when( 'update_user_meta' )->justReturn( true );
		Functions\when( 'wp_new_user_notification' )->justReturn( null );

		$result = UserCreator::get_or_create_user_dual( 'NEW-CPF', 'NEW-RF', 'brand@new.com', array( 'name' => 'Brand New' ) );
		$this->assertSame( 555, $result );
	}

	/**
	 * The bulk-import path (#1214) passes `$notify = false`, because creating
	 * 500 accounts otherwise means 500 synchronous account notifications from
	 * inside a batch loop. The user is created either way; only the mail goes.
	 *
	 * **`overload:` is right here and `alias:` is not** — the opposite of the
	 * reregistration confirmation, which is static. `create_ffc_user()` reaches
	 * the notification through `new EmailHandler()`, and `overload:` is what
	 * intercepts a constructor.
	 *
	 * @dataProvider provide_notify_flag
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 * @param bool $notify   What the caller asks for.
	 * @param int  $expected How many notifications should go out.
	 */
	public function test_dual_creation_mails_only_when_asked( bool $notify, int $expected ): void {
		global $wpdb;
		$wpdb->shouldReceive( 'prepare' )->andReturn( 'QUERY' );
		$wpdb->shouldReceive( 'get_var' )->andReturn( null );
		$wpdb->shouldReceive( 'query' )->andReturn( 0 );
		$wpdb->shouldReceive( 'get_results' )->andReturn( array() );

		Functions\when( 'get_user_by' )->justReturn( false );
		Functions\when( 'wp_generate_password' )->justReturn( 'pw' );
		Functions\when( 'sanitize_user' )->returnArg();
		Functions\when( 'remove_accents' )->returnArg();
		Functions\when( 'username_exists' )->justReturn( false );
		Functions\when( 'wp_create_user' )->justReturn( 556 );
		Functions\when( 'get_userdata' )->justReturn( null );
		Functions\when( 'wp_update_user' )->justReturn( 556 );
		Functions\when( 'update_user_meta' )->justReturn( true );
		Functions\when( 'wp_new_user_notification' )->justReturn( null );

		$handler = Mockery::mock( 'overload:FreeFormCertificate\Integrations\EmailHandler' );
		$handler->shouldReceive( 'send_wp_user_notification' )->times( $expected );

		$result = UserCreator::get_or_create_user_dual(
			'BULK-CPF',
			'BULK-RF',
			'bulk@new.com',
			array( 'name' => 'Bulk New' ),
			CapabilityManager::CONTEXT_REREGISTRATION,
			$notify
		);

		$this->assertSame( 556, $result, 'The account is created either way.' );
	}

	/** @return array<string, array{bool, int}> */
	public function provide_notify_flag(): array {
		return array(
			'default behaviour mails' => array( true, 1 ),
			'bulk import does not'    => array( false, 0 ),
		);
	}
}
