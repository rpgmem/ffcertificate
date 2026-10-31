<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Audience\AudienceCsvImporter;
use FreeFormCertificate\Core\Encryption;
use FreeFormCertificate\Tests\Support\CallLog;
use FreeFormCertificate\Tests\Support\Identifiers;
use FreeFormCertificate\UserDashboard\CapabilityManager;

/**
 * Tests for AudienceCsvImporter: CSV validation, sample generation, and import logic.
 *
 * The import paths run the real collaborators -- `UserCreator`,
 * `SensitiveFieldRegistry` (with the bootstrap's key constants),
 * `AudienceReader` / `AudienceWriter`, `CapabilityManager` and
 * `EmailHandler` -- over a `$wpdb` double that keeps the identity index, the
 * audiences and their members in memory, records every insert and fails on
 * any query it was not taught. WordPress's user API is stubbed at its edge
 * (`get_user_by`, `wp_create_user`, `get_userdata`, `wp_new_user_notification`).
 *
 * @preserveGlobalState disabled
 */
class AudienceCsvImporterTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var string */
	private string $temp_dir;

	/** @var mixed The `$wpdb` global an earlier test left, restored afterwards. */
	private $wpdb_backup;

	/** @var bool Whether `$wpdb` was set before this test. */
	private bool $had_wpdb = false;

	/**
	 * The `ffc_settings` option the code under test reads.
	 *
	 * @var array<string, mixed>
	 */
	private array $settings = array();

	/**
	 * Identity stores, as `table => [ column => [ hash => user_id ] ]`.
	 *
	 * @var array<string, array<string, array<string, int>>>
	 */
	private array $identity = array();

	/**
	 * Every identity lookup, as `[ table, [ column => hash ] ]`, in order.
	 *
	 * @var list<array{0: string, 1: array<string, string>}>
	 */
	private array $lookups = array();

	/**
	 * Audiences, keyed by id.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $audiences = array();

	/**
	 * Memberships, as `[ audience_id, user_id ]`.
	 *
	 * @var list<array{0: int, 1: int}>
	 */
	private array $members = array();

	/**
	 * Every `$wpdb->insert()`, as `[ table, data ]`.
	 *
	 * @var list<array{0: string, 1: array<string, mixed>}>
	 */
	private array $inserts = array();

	/** @var int The id the next insert receives. */
	private int $next_id = 10;

	/**
	 * Accounts WordPress knows, by id.
	 *
	 * @var array<int, \WP_User>
	 */
	private array $users = array();

	/**
	 * Every `wp_create_user()` call, as `[ username, password, email ]`.
	 *
	 * @var list<array<int, mixed>>
	 */
	private array $created = array();

	/**
	 * Every `wp_update_user()` payload.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $user_updates = array();

	/**
	 * Every `wp_new_user_notification()` call.
	 *
	 * @var list<array<int, mixed>>
	 */
	private array $notifications = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->had_wpdb    = array_key_exists( 'wpdb', $GLOBALS );
		$this->wpdb_backup = $GLOBALS['wpdb'] ?? null;

		// Several methods this class reaches are deprecated (#2089) and emit
		// the notice; stub it so each test chooses the branch rather than
		// inheriting it from an earlier class.
		\Brain\Monkey\Functions\when( '_deprecated_function' )->justReturn( null );

		$this->temp_dir = sys_get_temp_dir() . '/ffc_csv_test_' . uniqid();
		mkdir( $this->temp_dir, 0777, true );

		// Mock common WordPress functions
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'sanitize_email' )->alias( function ( $email ) {
			return trim( strtolower( $email ) );
		} );
		Functions\when( 'sanitize_text_field' )->alias( 'trim' );
		Functions\when( 'sanitize_hex_color' )->alias( function ( $color ) {
			return preg_match( '/^#[0-9a-fA-F]{6}$/', $color ) ? $color : '';
		} );
		Functions\when( 'is_email' )->alias( function ( $email ) {
			return (bool) filter_var( $email, FILTER_VALIDATE_EMAIL );
		} );
		Functions\when( 'absint' )->alias( function ( $val ) {
			return abs( intval( $val ) );
		} );
		Functions\when( 'sanitize_user' )->alias( function ( $user ) {
			return preg_replace( '/[^a-z0-9_.-]/i', '', $user );
		} );

		// What the real collaborators reach. Settings read empty unless a test
		// says otherwise: e-mails enabled, activity log and debug off.
		$this->settings = array();
		Functions\when( 'get_option' )->alias(
			function ( $key, $default = false ) {
				return 'ffc_settings' === $key ? $this->settings : $default;
			}
		);
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'current_time' )->justReturn( '2026-10-29 12:00:00' );
		Functions\when( 'wp_parse_args' )->alias(
			static function ( $args, $defaults = array() ) {
				return array_merge( $defaults, (array) $args );
			}
		);
		Functions\when( 'sanitize_sql_orderby' )->returnArg();
		Functions\when( 'is_wp_error' )->alias( static fn ( $thing ) => $thing instanceof \WP_Error );
		Functions\when( 'remove_accents' )->returnArg();
		Functions\when( 'username_exists' )->justReturn( false );
		Functions\when( 'wp_generate_password' )->justReturn( 'generated-password' );
		Functions\when( 'update_user_meta' )->justReturn( true );

		$this->users         = array();
		$this->created       = array();
		$this->user_updates  = array();
		$this->notifications = array();
		Functions\when( 'get_userdata' )->alias( fn ( $id ) => $this->users[ (int) $id ] ?? false );
		Functions\when( 'wp_create_user' )->alias(
			function ( $username, $password, $email ) {
				$this->created[]          = array( $username, $password, $email );
				$user                     = new \WP_User( 77 );
				$user->user_login         = $username;
				$user->user_email         = $email;
				$this->users[ $user->ID ] = $user;
				return $user->ID;
			}
		);
		Functions\when( 'wp_update_user' )->alias(
			function ( $data ) {
				$this->user_updates[] = $data;
				return $data['ID'];
			}
		);
		Functions\when( 'wp_new_user_notification' )->alias(
			function ( ...$args ) {
				$this->notifications[] = $args;
			}
		);

		$this->identity  = array();
		$this->lookups   = array();
		$this->audiences = array();
		$this->members   = array();
		$this->inserts   = array();
		$this->next_id   = 10;

		$GLOBALS['wpdb'] = $this->database();
	}

	protected function tearDown(): void {
		// Clean up temp files
		$files = glob( $this->temp_dir . '/*' );
		if ( $files ) {
			foreach ( $files as $file ) {
				unlink( $file );
			}
		}
		if ( is_dir( $this->temp_dir ) ) {
			rmdir( $this->temp_dir );
		}

		if ( $this->had_wpdb ) {
			$GLOBALS['wpdb'] = $this->wpdb_backup;
		} else {
			unset( $GLOBALS['wpdb'] );
		}

		Monkey\tearDown();
		parent::tearDown();
	}

	// ------------------------------------------------------------------
	// Helper: create a temp CSV file
	// ------------------------------------------------------------------

	private function create_csv( string $content ): string {
		$path = $this->temp_dir . '/test_' . uniqid() . '.csv';
		file_put_contents( $path, $content );
		return $path;
	}

	// ------------------------------------------------------------------
	// Helper: the `$wpdb` double
	// ------------------------------------------------------------------

	/**
	 * A database answering what the importer and its collaborators ask, from
	 * the in-memory stores above. Anything else fails the test.
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
				return (string) preg_replace_callback(
					'/%[ids]/',
					static function ( $m ) use ( &$values ) {
						$value = array_shift( $values );
						if ( '%d' === $m[0] ) {
							return (string) (int) $value;
						}
						return '%s' === $m[0] ? "'" . $value . "'" : (string) $value;
					},
					(string) $sql
				);
			}
		);

		$wpdb->shouldReceive( 'get_var' )->andReturnUsing(
			function ( $sql ) {
				if ( str_starts_with( $sql, 'SHOW TABLES LIKE ' ) ) {
					return null; // No optional table exists on this install.
				}
				if ( preg_match( '/^SELECT user_id FROM (\S+) WHERE \((.*)\) AND user_id IS NOT NULL LIMIT 1$/', $sql, $m ) ) {
					preg_match_all( "/(cpf_hash|rf_hash) = '([^']*)'/", $m[2], $pairs, PREG_SET_ORDER );
					$asked = array();
					foreach ( $pairs as $pair ) {
						$asked[ $pair[1] ] = $pair[2];
					}
					$this->lookups[] = array( $m[1], $asked );
					foreach ( $asked as $column => $hash ) {
						if ( isset( $this->identity[ $m[1] ][ $column ][ $hash ] ) ) {
							return (string) $this->identity[ $m[1] ][ $column ][ $hash ];
						}
					}
					return null;
				}
				if ( preg_match( '/^SELECT COUNT\(\*\) FROM wp_ffc_audience_members WHERE audience_id = (\d+) AND user_id = (\d+)$/', $sql, $m ) ) {
					return (string) count( array_keys( $this->members, array( (int) $m[1], (int) $m[2] ), true ) );
				}
				if ( preg_match( "/^SELECT id FROM wp_ffc_audiences WHERE name = '(.*)' LIMIT 1$/", $sql, $m ) ) {
					foreach ( $this->audiences as $id => $row ) {
						if ( $row['name'] === $m[1] ) {
							return (string) $id;
						}
					}
					return null;
				}
				$this->fail( 'Unexpected get_var: ' . $sql );
			}
		);

		$wpdb->shouldReceive( 'get_row' )->andReturnUsing(
			function ( $sql ) {
				if ( preg_match( '/^SELECT \* FROM wp_ffc_audiences WHERE id = (\d+)$/', $sql, $m ) ) {
					return isset( $this->audiences[ (int) $m[1] ] ) ? (object) $this->audiences[ (int) $m[1] ] : null;
				}
				$this->fail( 'Unexpected get_row: ' . $sql );
			}
		);

		$wpdb->shouldReceive( 'get_results' )->andReturnUsing(
			function ( $sql ) {
				if ( preg_match( '/^SELECT \* FROM wp_ffc_audiences WHERE parent_id = (\d+) ORDER BY/', $sql, $m ) ) {
					$rows = array();
					foreach ( $this->audiences as $row ) {
						if ( (int) $row['parent_id'] === (int) $m[1] ) {
							$rows[] = (object) $row;
						}
					}
					return $rows;
				}
				$this->fail( 'Unexpected get_results: ' . $sql );
			}
		);

		$wpdb->shouldReceive( 'insert' )->andReturnUsing(
			function ( $table, $data ) use ( $wpdb ) {
				$this->inserts[] = array( $table, $data );
				$id              = $this->next_id++;
				if ( 'wp_ffc_audience_members' === $table ) {
					$this->members[] = array( (int) $data['audience_id'], (int) $data['user_id'] );
				} elseif ( 'wp_ffc_audiences' === $table ) {
					$this->audiences[ $id ] = array_merge( array( 'id' => $id ), $data );
				} else {
					$this->fail( 'Unexpected insert into ' . $table );
				}
				$wpdb->insert_id = $id;
				return 1;
			}
		);

		foreach ( array( 'get_col', 'update', 'query', 'delete', 'replace' ) as $method ) {
			$wpdb->shouldReceive( $method )->andReturnUsing(
				function ( ...$args ) use ( $method ) {
					$this->fail( 'Unexpected ' . $method . ': ' . print_r( $args, true ) );
				}
			);
		}

		return $wpdb;
	}

	/**
	 * The rows inserted into one table, in order.
	 *
	 * @param string $table Full table name.
	 * @return list<array<string, mixed>>
	 */
	private function inserted_into( string $table ): array {
		$out = array();
		foreach ( $this->inserts as $insert ) {
			if ( $table === $insert[0] ) {
				$out[] = $insert[1];
			}
		}
		return $out;
	}

	/**
	 * Record a user in the identity index.
	 *
	 * @param string $column `cpf_hash` or `rf_hash`.
	 * @param string $hash   The stored hash.
	 * @param int    $user   The user it belongs to.
	 */
	private function index_identity( string $column, string $hash, int $user ): void {
		$this->identity['wp_ffc_user_profiles'][ $column ][ $hash ] = $user;
	}

	// ==================================================================
	// get_sample_csv()
	// ==================================================================

	public function test_get_sample_csv_members_has_email_column(): void {
		$csv = AudienceCsvImporter::get_sample_csv( 'members' );
		$lines = explode( "\n", trim( $csv ) );
		$header = $lines[0];

		$this->assertStringContainsString( 'email', $header );
		$this->assertStringContainsString( 'name', $header );
		$this->assertGreaterThan( 1, count( $lines ), 'Sample should have header + data rows' );
	}

	public function test_get_sample_csv_audiences_has_name_column(): void {
		$csv = AudienceCsvImporter::get_sample_csv( 'audiences' );
		$lines = explode( "\n", trim( $csv ) );
		$header = $lines[0];

		$this->assertStringContainsString( 'name', $header );
		$this->assertStringContainsString( 'color', $header );
		$this->assertStringContainsString( 'parent', $header );
	}

	public function test_get_sample_csv_audiences_has_parent_child_structure(): void {
		$csv = AudienceCsvImporter::get_sample_csv( 'audiences' );

		$this->assertStringContainsString( 'Group A', $csv );
		$this->assertStringContainsString( 'Subgroup A1', $csv );
		$this->assertStringContainsString( '#3788d8', $csv );
	}

	public function test_get_sample_csv_members_contains_example_emails(): void {
		$csv = AudienceCsvImporter::get_sample_csv( 'members' );

		$this->assertStringContainsString( 'john@example.com', $csv );
		$this->assertStringContainsString( 'jane@example.com', $csv );
	}

	// ==================================================================
	// validate_csv()
	// ==================================================================

	public function test_validate_csv_nonexistent_file(): void {
		$result = AudienceCsvImporter::validate_csv( '/nonexistent/file.csv', 'members' );

		$this->assertFalse( $result['valid'] );
		$this->assertNotEmpty( $result['errors'] );
	}

	public function test_validate_csv_empty_file(): void {
		$path = $this->create_csv( '' );
		$result = AudienceCsvImporter::validate_csv( $path, 'members' );

		$this->assertFalse( $result['valid'] );
		$this->assertNotEmpty( $result['errors'] );
	}

	public function test_validate_csv_members_missing_email_column(): void {
		$path = $this->create_csv( "name,audience_id\nJohn,1\n" );
		$result = AudienceCsvImporter::validate_csv( $path, 'members' );

		$this->assertFalse( $result['valid'] );
		$this->assertStringContainsString( 'email', $result['errors'][0] );
	}

	public function test_validate_csv_members_valid(): void {
		$path = $this->create_csv( "email,name,audience_id\ntest@example.com,Test User,1\ntest2@example.com,Test User 2,2\n" );
		$result = AudienceCsvImporter::validate_csv( $path, 'members' );

		$this->assertTrue( $result['valid'] );
		$this->assertSame( 2, $result['rows'] );
		$this->assertEmpty( $result['errors'] );
	}

	public function test_validate_csv_audiences_missing_name_column(): void {
		$path = $this->create_csv( "color,parent\n#ff0000,\n" );
		$result = AudienceCsvImporter::validate_csv( $path, 'audiences' );

		$this->assertFalse( $result['valid'] );
		$this->assertStringContainsString( 'name', $result['errors'][0] );
	}

	public function test_validate_csv_audiences_valid(): void {
		$path = $this->create_csv( "name,color,parent\nGroup A,#3788d8,\nSub A1,#ff0000,Group A\n" );
		$result = AudienceCsvImporter::validate_csv( $path, 'audiences' );

		$this->assertTrue( $result['valid'] );
		$this->assertSame( 2, $result['rows'] );
	}

	public function test_validate_csv_skips_empty_rows(): void {
		$path = $this->create_csv( "email,name\ntest@example.com,Test\n\n\ntest2@example.com,Test2\n" );
		$result = AudienceCsvImporter::validate_csv( $path, 'members' );

		$this->assertTrue( $result['valid'] );
		$this->assertSame( 2, $result['rows'], 'Empty rows should not be counted' );
	}

	public function test_validate_csv_normalizes_header_case(): void {
		$path = $this->create_csv( "EMAIL,Name,AUDIENCE_ID\ntest@example.com,Test,1\n" );
		$result = AudienceCsvImporter::validate_csv( $path, 'members' );

		$this->assertTrue( $result['valid'], 'Header normalization should make EMAIL match email' );
	}

	// ==================================================================
	// import_members()
	// ==================================================================

	public function test_import_members_nonexistent_file(): void {
		$result = AudienceCsvImporter::import_members( '/nonexistent/file.csv' );

		$this->assertFalse( $result['success'] );
		$this->assertNotEmpty( $result['errors'] );
	}

	public function test_import_members_empty_file(): void {
		$path = $this->create_csv( '' );
		$result = AudienceCsvImporter::import_members( $path );

		$this->assertFalse( $result['success'] );
	}

	public function test_import_members_missing_email_column(): void {
		$path = $this->create_csv( "name,audience_id\nJohn,1\n" );
		$result = AudienceCsvImporter::import_members( $path );

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'email', $result['errors'][0] );
	}

	public function test_import_members_no_audience_specified(): void {
		$path = $this->create_csv( "email,name\ntest@example.com,Test\n" );
		$result = AudienceCsvImporter::import_members( $path, 0 );

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'audience', strtolower( $result['errors'][0] ) );
	}

	public function test_import_members_invalid_email_skipped(): void {
		$path = $this->create_csv( "email,name\nnot-an-email,Test\n" );

		$mock_user = new \WP_User( 1 );
		$mock_user->display_name = 'Test';
		$mock_user->user_email = 'test@example.com';

		Functions\when( 'get_user_by' )->justReturn( null );

		$result = AudienceCsvImporter::import_members( $path, 5 );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 0, $result['imported'] );
		$this->assertSame( 1, $result['skipped'] );
		$this->assertStringContainsString( 'Row 2', $result['errors'][0] );
	}

	public function test_import_members_user_not_found_without_create(): void {
		$path = $this->create_csv( "email,name\ntest@example.com,Test User\n" );

		Functions\when( 'get_user_by' )->justReturn( false );

		$result = AudienceCsvImporter::import_members( $path, 5, false );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 0, $result['imported'] );
		$this->assertSame( 1, $result['skipped'] );
		$this->assertStringContainsString( 'not found', $result['errors'][0] );
	}

	public function test_import_members_successful_with_existing_user(): void {
		$path = $this->create_csv( "email,name\ntest@example.com,Test User\n" );

		$mock_user = new \WP_User( 42 );
		$mock_user->user_email = 'test@example.com';
		$mock_user->display_name = 'Test User';

		Functions\when( 'get_user_by' )->justReturn( $mock_user );

		$result = AudienceCsvImporter::import_members( $path, 5 );

		$this->assertSame(
			array( array( 5, 42 ) ),
			$this->members,
			'The member is recorded exactly once, in the audience passed in.'
		);
		$this->assertTrue( $result['success'] );
		$this->assertSame( 1, $result['imported'] );
		$this->assertSame( 0, $result['skipped'] );
	}

	/**
	 * Creation goes through `UserCreator`, the plugin's one creation path.
	 *
	 * This was the only `wp_insert_user()` outside it (#1313 PR 5), and the
	 * two had drifted: this one generated a 12-character password with no
	 * special characters against `UserCreator`'s 24 with, and it created no
	 * `ffc_user_profiles` row at all, so an imported member was invisible to
	 * the identity index from birth.
	 *
	 * The capability context is `CONTEXT_AUDIENCE` since 6.26.0 (#1313 PR 7).
	 * PR 5 left it at `CONTEXT_CERTIFICATE` -- what the flow granted before --
	 * rather than decide a product question in passing, and this is that
	 * decision: the person is being imported into an audience, so that is what
	 * the context says. It grants them nothing they need, because the
	 * capability that shows a member their own bookings is granted where it
	 * belongs, by `AudienceWriter::add_member()` (#1302), and nothing is taken
	 * away either -- every grant path adds what the holder lacks and no path
	 * removes a capability, so the certificate flow grants the certificate
	 * capabilities if this person ever submits one.
	 *
	 * The real `UserCreator` runs: the account comes from `wp_create_user()`,
	 * the name reaches it, the context shows in the capabilities the new
	 * account holds (audience, never certificate), and `$notify = false`
	 * shows in no notification at all on an install whose `submission`
	 * notification -- the one `UserCreator` would send -- defaults on.
	 */
	public function test_import_members_creates_through_user_creator(): void {
		$path = $this->create_csv( "email,name\nnew@example.com,New Person\n" );

		Functions\when( 'get_user_by' )->justReturn( false );

		$result = AudienceCsvImporter::import_members( $path, 5, true );

		$this->assertSame( array( array( 'new', 'generated-password', 'new@example.com' ) ), $this->created, 'The account is created once, through UserCreator.' );
		$this->assertSame( 'New Person', $this->user_updates[0]['display_name'] ?? null, 'The name column reaches the creator.' );
		$caps = array_keys( array_filter( $this->users[77]->caps ) );
		$this->assertContains( 'ffc_view_own_audience_bookings', $caps );
		$this->assertSame( array(), array_intersect( CapabilityManager::CERTIFICATE_CAPABILITIES, $caps ), 'The context is the audience one, not the certificate one.' );
		$this->assertSame( array(), $this->notifications, 'UserCreator must be told not to notify; the csv_import notification is off by default.' );
		$this->assertSame( array( array( 5, 77 ) ), $this->members );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 1, $result['imported'] );
	}

	/**
	 * The CSV-import notification survives the move, under its own setting.
	 *
	 * `UserCreator` would send the `submission` one, and the two are separate
	 * keys in Settings → SMTP whose defaults are OPPOSITE: submission is on,
	 * csv_import is off. Letting it send would start mailing every row of a
	 * bulk import on installs that never asked for it, so this flow passes
	 * `$notify = false` and sends its own.
	 *
	 * The real `EmailHandler` decides by the setting: with csv_import on and
	 * submission off, exactly one notification reaches WordPress, so it was
	 * sent under csv_import. The default install is the next test.
	 */
	public function test_import_members_sends_the_csv_import_notification(): void {
		$path = $this->create_csv( "email,name\nnew@example.com,New Person\n" );

		Functions\when( 'get_user_by' )->justReturn( false );
		$this->settings = array(
			'send_wp_user_email_csv_import' => 1,
			'send_wp_user_email_submission' => 0,
		);

		AudienceCsvImporter::import_members( $path, 5, true );

		$this->assertSame(
			array( array( 77, null, 'user' ) ),
			$this->notifications,
			'The imported user must be told under the csv_import setting, which defaults OFF — not under submission, which defaults on.'
		);
	}

	/**
	 * On an install that never touched Settings → SMTP, an import mails
	 * nobody: csv_import defaults off, and submission -- which defaults on --
	 * is not the setting this flow answers to.
	 */
	public function test_import_members_on_a_default_install_sends_no_notification(): void {
		$path = $this->create_csv( "email,name\nnew@example.com,New Person\n" );

		Functions\when( 'get_user_by' )->justReturn( false );

		AudienceCsvImporter::import_members( $path, 5, true );

		$this->assertCount( 1, $this->created, 'The account is created.' );
		$this->assertSame( array(), $this->notifications );
	}

	/**
	 * A `cpf` column answers WHO the row is about, and the answer wins.
	 *
	 * Before #1313 PR 11 this import asked `get_user_by( 'email', … )` and
	 * nothing else, so a person already in the system under a different
	 * address was given a SECOND account -- the outcome the identity index
	 * exists to make impossible. The column is optional and the hash is
	 * computed through `SensitiveFieldRegistry`, so the question this module
	 * asks is byte-for-byte the one every other module asks.
	 *
	 * The e-mail here deliberately belongs to nobody (`get_user_by` returns
	 * false), so the only thing that can resolve this row is the identifier.
	 * The CPF is written punctuated, so the hash the index is asked for is the
	 * registry's canonical one -- the digits, hashed -- and the resolver never
	 * reaches the e-mail it would try second.
	 */
	public function test_import_members_resolves_an_existing_user_by_cpf(): void {
		$cpf     = Identifiers::CPF_A;
		$written = substr( $cpf, 0, 3 ) . '.' . substr( $cpf, 3, 3 ) . '.' . substr( $cpf, 6, 3 ) . '-' . substr( $cpf, 9, 2 );
		$path    = $this->create_csv( "email,name,cpf\nother@example.com,Same Person," . $written . "\n" );

		$by_email = CallLog::record( 'get_user_by', false );
		$hash     = Encryption::hash( $cpf );
		$this->index_identity( 'cpf_hash', (string) $hash, 91 );

		$result = AudienceCsvImporter::import_members( $path, 5, true );

		$this->assertSame(
			array( array( 'wp_ffc_user_profiles', array( 'cpf_hash' => $hash ) ) ),
			$this->lookups,
			'The CPF must reach the identity index as the registry hash of its digits, and only the CPF column is asked.'
		);
		$this->assertSame( 0, $by_email->count(), 'A row the identifier resolves never falls back to the e-mail.' );
		$this->assertSame( array(), $this->created, 'No second account.' );
		$this->assertSame(
			array( array( 5, 91 ) ),
			$this->members,
			'The person the identifier names is the one added to the audience -- no second account.'
		);
		$this->assertSame( 1, $result['imported'] );
	}

	/**
	 * With creation OFF, an identifier the system knows is no longer a skip.
	 *
	 * This is the half that loses data silently: an operator importing into an
	 * audience without `create_users` got "User not found" for every person
	 * whose address had changed, and the row was dropped. Resolving by hash
	 * first means the membership is recorded for the account that already
	 * exists, and creation is still never reached.
	 */
	public function test_import_members_without_creation_still_imports_a_known_identifier(): void {
		$path = $this->create_csv( "email,name,rf\nchanged@example.com,Known Person," . Identifiers::RF_A . "\n" );

		Functions\when( 'get_user_by' )->justReturn( false );
		$hash = Encryption::hash( Identifiers::RF_A );
		$this->index_identity( 'rf_hash', (string) $hash, 64 );

		$result = AudienceCsvImporter::import_members( $path, 5, false );

		$this->assertSame( array( array( 'wp_ffc_user_profiles', array( 'rf_hash' => $hash ) ) ), $this->lookups, 'Only the RF is asked, as its registry hash.' );
		$this->assertSame( array(), $this->created, 'Creation is never reached.' );
		$this->assertSame( array( array( 5, 64 ) ), $this->members );
		$this->assertSame( 1, $result['imported'] );
		$this->assertSame( 0, $result['skipped'] );
		$this->assertSame( array(), $result['errors'], 'A row the index can resolve must not be reported as "User not found".' );
	}

	public function test_import_members_already_member_counted_as_skipped(): void {
		$path = $this->create_csv( "email,name\ntest@example.com,Test\n" );

		$mock_user = new \WP_User( 42 );
		$mock_user->user_email = 'test@example.com';
		Functions\when( 'get_user_by' )->justReturn( $mock_user );
		$this->members = array( array( 5, 42 ) );

		$result = AudienceCsvImporter::import_members( $path, 5 );

		$this->assertSame( array(), $this->inserted_into( 'wp_ffc_audience_members' ), 'An existing membership is not written again.' );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 0, $result['imported'] );
		$this->assertSame( 1, $result['skipped'] );
	}

	// ==================================================================
	// import_audiences()
	// ==================================================================

	public function test_import_audiences_nonexistent_file(): void {
		$result = AudienceCsvImporter::import_audiences( '/nonexistent/file.csv' );

		$this->assertFalse( $result['success'] );
		$this->assertNotEmpty( $result['errors'] );
	}

	public function test_import_audiences_missing_name_column(): void {
		$path = $this->create_csv( "color,parent\n#ff0000,\n" );
		$result = AudienceCsvImporter::import_audiences( $path );

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'name', $result['errors'][0] );
	}

	public function test_import_audiences_empty_name_skipped(): void {
		$path = $this->create_csv( "name,color,parent\n,#ff0000,\n" );

		$result = AudienceCsvImporter::import_audiences( $path );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 0, $result['imported'] );
		$this->assertSame( 1, $result['skipped'] );
	}

	/**
	 * The parent is created first even when the sheet lists the child first,
	 * and the child is then created under it.
	 *
	 * The earlier version of this test stubbed the name lookup to miss
	 * forever, so the child could never find the parent it had just watched
	 * being created and the test expected one import. The real lookup sees
	 * the new row, so the deferred child resolves on the second pass.
	 */
	public function test_import_audiences_creates_parents_first(): void {
		$path = $this->create_csv( "name,color,parent\nChild A1,#ff0000,Parent A\nParent A,#3788d8,\n" );

		$result = AudienceCsvImporter::import_audiences( $path );

		$created = $this->inserted_into( 'wp_ffc_audiences' );
		$this->assertSame( array( 'Parent A', 'Child A1' ), array_column( $created, 'name' ), 'Parent A is created before its child.' );
		$this->assertNull( $created[0]['parent_id'] );
		$this->assertSame( 10, $created[1]['parent_id'], 'The child hangs from the parent just created.' );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 2, $result['imported'] );
	}

	public function test_import_audiences_skips_existing(): void {
		$path = $this->create_csv( "name,color\nExisting Group,#ff0000\n" );

		$this->audiences = array(
			5 => array(
				'id'        => 5,
				'name'      => 'Existing Group',
				'color'     => '#ff0000',
				'parent_id' => null,
			),
		);

		$result = AudienceCsvImporter::import_audiences( $path );

		$this->assertSame( array(), $this->inserted_into( 'wp_ffc_audiences' ), 'An existing audience is not created again.' );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 0, $result['imported'] );
		$this->assertSame( 1, $result['skipped'] );
	}

	public function test_import_audiences_default_color_when_invalid(): void {
		$path = $this->create_csv( "name,color\nTest Group,not-a-color\n" );

		$result = AudienceCsvImporter::import_audiences( $path );

		$created = $this->inserted_into( 'wp_ffc_audiences' );
		$this->assertCount( 1, $created );
		$this->assertSame( '#3788d8', $created[0]['color'], 'An invalid colour falls back to the default.' );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 1, $result['imported'] );
	}
}
