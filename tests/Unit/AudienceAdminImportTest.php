<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Audience\AudienceAdminImport;
use FreeFormCertificate\Core\CsvStreamer;
use FreeFormCertificate\Core\CsvDownloadInterface;
use FreeFormCertificate\Tests\Support\CallLog;

/**
 * The exports and imports run the real `AudienceReader`, `AudienceWriter`,
 * `AudienceCsvImporter`, `UserCreator` and `FilenameHelper` over a `$wpdb`
 * double that keeps audiences and memberships in memory, records every
 * query and insert, and fails on any query it was not taught.
 *
 * @covers \FreeFormCertificate\Audience\AudienceAdminImport
 * @covers \FreeFormCertificate\Audience\AudienceMembersExportSource
 * @covers \FreeFormCertificate\Audience\AudienceAudiencesExportSource
 * @preserveGlobalState disabled
 */
class AudienceAdminImportTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var mixed The `$wpdb` global an earlier test left, restored afterwards. */
	private $wpdb_backup;

	/** @var bool Whether `$wpdb` was set before this test. */
	private bool $had_wpdb = false;

	/** @var array<string, mixed> */
	private array $post_backup = array();

	/** @var array<string, mixed> */
	private array $get_backup = array();

	/** @var array<string, mixed> */
	private array $files_backup = array();

	/**
	 * Audiences, keyed by id.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $audiences = array();

	/**
	 * Members of each audience, as `audience_id => list<user_id>`.
	 *
	 * @var array<int, list<int>>
	 */
	private array $members = array();

	/**
	 * Every read query the double answered, in order.
	 *
	 * @var list<string>
	 */
	private array $queries = array();

	/**
	 * Every `$wpdb->insert()`, as `[ table, data ]`.
	 *
	 * @var list<array{0: string, 1: array<string, mixed>}>
	 */
	private array $inserts = array();

	/** @var int The id the next insert receives. */
	private int $next_id = 100;

	/**
	 * Uploaded CSV files this test wrote, removed in tearDown.
	 *
	 * @var list<string>
	 */
	private array $files = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->had_wpdb     = array_key_exists( 'wpdb', $GLOBALS );
		$this->wpdb_backup  = $GLOBALS['wpdb'] ?? null;
		$this->post_backup  = $_POST ?? array();
		$this->get_backup   = $_GET ?? array();
		$this->files_backup = $_FILES ?? array();

		// The PII export rule (#1863): the exporter holds the PII cap unless a
		// test says otherwise, and a masked cell asks is_email().
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'user_can' )->justReturn( true );
		Functions\when( 'is_email' )->alias( static fn( $v ) => is_string( $v ) && 1 === substr_count( $v, '@' ) );
		// Preload so pcov attributes the coverage these tests drive through the
		// export sources; pcov skips a class first autoloaded mid-test (#772).
		class_exists( '\\FreeFormCertificate\Audience\AudienceMembersExportSource' );
		class_exists( '\\FreeFormCertificate\Audience\AudienceAudiencesExportSource' );

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'esc_html_e' )->alias( function ( $text ) { echo $text; } );
		Functions\when( 'esc_attr_e' )->alias( function ( $text ) { echo $text; } );
		Functions\when( 'absint' )->alias( function ( $v ) { return abs( (int) $v ); } );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'admin_url' )->justReturn( 'https://example.com/wp-admin/' );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_settings_errors' )->justReturn( array() );
		Functions\when( 'wp_nonce_field' )->justReturn( '' );
		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		Functions\when( 'wp_nonce_url' )->justReturn( '/' );
		Functions\when( 'selected' )->justReturn( '' );
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'wp_parse_args' )->alias( function ( $args, $defaults = array() ) {
			return array_merge( $defaults, (array) $args );
		} );
		Functions\when( 'sanitize_sql_orderby' )->returnArg();
		Functions\when( 'sanitize_key' )->returnArg();
		Functions\when( 'submit_button' )->justReturn( '' );
		// render_content() now enqueues the tab-switch asset instead of
		// echoing an inline <script>; stub the enqueue so the render path
		// doesn't fatal under Brain\Monkey.
		Functions\when( 'wp_enqueue_script' )->justReturn( null );

		$this->audiences = array();
		$this->members   = array();
		$this->queries   = array();
		$this->inserts   = array();
		$this->next_id   = 100;
		$this->files     = array();

		$GLOBALS['wpdb'] = $this->database();
	}

	protected function tearDown(): void {
		unset( $_POST['ffc_import_action'], $_POST['_wpnonce'] );
		foreach ( $this->files as $file ) {
			if ( is_file( $file ) ) {
				unlink( $file );
			}
		}
		$_POST  = $this->post_backup;
		$_GET   = $this->get_backup;
		$_FILES = $this->files_backup;
		if ( $this->had_wpdb ) {
			$GLOBALS['wpdb'] = $this->wpdb_backup;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * A database answering what the export sources and the importer ask, from
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

		$wpdb->shouldReceive( 'get_results' )->andReturnUsing(
			function ( $sql ) {
				$this->queries[] = $sql;
				if ( preg_match( '/^SELECT \* FROM wp_ffc_audiences\s+ORDER BY /', $sql ) ) {
					return array_map( static fn ( $row ) => (object) $row, array_values( $this->audiences ) );
				}
				$this->fail( 'Unexpected get_results: ' . $sql );
			}
		);

		$wpdb->shouldReceive( 'get_col' )->andReturnUsing(
			function ( $sql ) {
				$this->queries[] = $sql;
				if ( preg_match( '/^SELECT DISTINCT user_id FROM wp_ffc_audience_members WHERE audience_id IN \((\d+)\)$/', $sql, $m ) ) {
					return array_map( 'strval', $this->members[ (int) $m[1] ] ?? array() );
				}
				$this->fail( 'Unexpected get_col: ' . $sql );
			}
		);

		$wpdb->shouldReceive( 'get_var' )->andReturnUsing(
			function ( $sql ) {
				$this->queries[] = $sql;
				if ( str_starts_with( $sql, 'SHOW TABLES LIKE ' ) ) {
					return null; // No optional table exists on this install.
				}
				if ( preg_match( '/^SELECT COUNT\(\*\) FROM wp_ffc_audience_members WHERE audience_id = (\d+) AND user_id = (\d+)$/', $sql, $m ) ) {
					return in_array( (int) $m[2], $this->members[ (int) $m[1] ] ?? array(), true ) ? '1' : '0';
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

		$wpdb->shouldReceive( 'insert' )->andReturnUsing(
			function ( $table, $data ) use ( $wpdb ) {
				$this->inserts[] = array( $table, $data );
				$id              = $this->next_id++;
				if ( 'wp_ffc_audience_members' === $table ) {
					$this->members[ (int) $data['audience_id'] ][] = (int) $data['user_id'];
				} elseif ( 'wp_ffc_audiences' === $table ) {
					$this->audiences[ $id ] = array_merge( array( 'id' => $id ), $data );
				} else {
					$this->fail( 'Unexpected insert into ' . $table );
				}
				$wpdb->insert_id = $id;
				return 1;
			}
		);

		foreach ( array( 'get_row', 'update', 'query', 'delete', 'replace' ) as $method ) {
			$wpdb->shouldReceive( $method )->andReturnUsing(
				function ( ...$args ) use ( $method ) {
					$this->fail( 'Unexpected ' . $method . ': ' . print_r( $args, true ) );
				}
			);
		}

		return $wpdb;
	}

	/**
	 * Store an audience.
	 *
	 * @param int         $id        Audience id.
	 * @param string      $name      Name.
	 * @param string|null $color     Colour, or null for none.
	 * @param int|null    $parent_id Parent, or null for a top-level audience.
	 */
	private function audience( int $id, string $name, ?string $color = '#3788d8', ?int $parent_id = null ): void {
		$this->audiences[ $id ] = array(
			'id'        => $id,
			'name'      => $name,
			'color'     => $color,
			'parent_id' => $parent_id,
		);
	}

	/**
	 * Write an uploaded CSV and return its path.
	 *
	 * @param string $content File content.
	 * @return string
	 */
	private function upload( string $content ): string {
		$path = (string) tempnam( sys_get_temp_dir(), 'ffc_admin_import_' );
		file_put_contents( $path, $content );
		$this->files[] = $path;
		return $path;
	}

	/**
	 * What the real importer reaches below the database: WordPress's user API
	 * at its edge (accounts 10 and 11 exist; a created one is 77), the object
	 * cache and the option writes.
	 *
	 * @return \ArrayObject<int, array<int, mixed>> Every wp_create_user() call.
	 */
	private function stub_import_path(): \ArrayObject {
		$users = array(
			'a@example.com' => 10,
			'b@example.com' => 11,
		);
		Functions\when( 'get_user_by' )->alias(
			static function ( $field, $value ) use ( $users ) {
				return isset( $users[ $value ] ) ? new \WP_User( $users[ $value ] ) : false;
			}
		);
		Functions\when( 'sanitize_email' )->alias( static fn ( $v ) => trim( strtolower( (string) $v ) ) );
		Functions\when( 'sanitize_user' )->alias( static fn ( $v ) => preg_replace( '/[^a-z0-9_.-]/i', '', (string) $v ) );
		Functions\when( 'remove_accents' )->returnArg();
		Functions\when( 'username_exists' )->justReturn( false );
		Functions\when( 'wp_generate_password' )->justReturn( 'generated-password' );
		Functions\when( 'is_wp_error' )->alias( static fn ( $thing ) => $thing instanceof \WP_Error );
		Functions\when( 'get_userdata' )->justReturn( false );
		Functions\when( 'wp_update_user' )->justReturn( 77 );
		Functions\when( 'update_user_meta' )->justReturn( true );
		Functions\when( 'current_time' )->justReturn( '2026-10-29 12:00:00' );
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( '_deprecated_function' )->justReturn( null );
		$created = new \ArrayObject();
		Functions\when( 'wp_create_user' )->alias(
			static function ( ...$args ) use ( $created ) {
				$created[] = $args;
				return 77;
			}
		);
		return $created;
	}

	// ==================================================================
	// Constructor
	// ==================================================================

	public function test_constructor_creates_instance(): void {
		$page = new AudienceAdminImport( 'ffc-scheduling' );
		$this->assertInstanceOf( AudienceAdminImport::class, $page );
	}

	// ==================================================================
	// render_content() — renders import/export tabs
	// ==================================================================

	public function test_render_page_renders_interface(): void {
		$page = new AudienceAdminImport( 'ffc-scheduling' );
		ob_start();
		$page->render_content();
		$output = ob_get_clean();

		// Import and export share the screen (#1631): both halves render, with
		// no second row of tabs to hide one of them.
		$this->assertStringContainsString( '<h2 class="ffc-icon-upload">Import Members</h2>', $output );
		$this->assertStringContainsString( '<h2 class="ffc-icon-download">Export Members</h2>', $output );
		// Each section is the shared card, two rows of two (#1761).
		$this->assertSame( 2, substr_count( $output, '<div class="ffc-import-export-cards">' ) );
		$this->assertSame( 4, substr_count( $output, '<div class="card">' ) );
		$this->assertStringNotContainsString( 'nav-tab', $output );
		$this->assertStringNotContainsString( 'display: none', $output );
	}

	public function test_download_sample_links_point_at_the_settings_tab(): void {
		// The legacy `page=ffc-scheduling-import` is 301-redirected before any
		// handler runs, so a link there never reached the download (#1771).
		$urls = array();
		Functions\when( 'admin_url' )->alias( static fn( $p = '' ) => 'https://example.com/wp-admin/' . $p );
		Functions\when( 'wp_nonce_url' )->alias(
			static function ( $url, $action ) use ( &$urls ) {
				$urls[] = array( $url, $action );
				return $url . '&_wpnonce=n';
			}
		);

		$page = new AudienceAdminImport( 'ffc-scheduling' );
		ob_start();
		$page->render_content();
		ob_end_clean();

		$samples = array_values(
			array_filter( $urls, static fn( $u ) => false !== strpos( $u[0], 'download_sample=' ) )
		);
		$this->assertSame(
			array(
				array( 'https://example.com/wp-admin/admin.php?page=ffc-scheduling-settings&tab=import&download_sample=members', 'download_sample' ),
				array( 'https://example.com/wp-admin/admin.php?page=ffc-scheduling-settings&tab=import&download_sample=audiences', 'download_sample' ),
			),
			$samples
		);
	}

	public function test_render_content_leaves_the_notices_to_the_hosting_settings_page(): void {
		// The Settings page prints `ffc_audience` notices once for every tab;
		// printing them here too would show each one twice (#1771).
		$log = CallLog::record( 'get_settings_errors', array() );

		$page = new AudienceAdminImport( 'ffc-scheduling' );
		ob_start();
		$page->render_content();
		ob_end_clean();

		$this->assertSame( 0, $log->count() );
	}

	// ==================================================================
	// handle_csv_import() — no action
	// ==================================================================

	public function test_handle_csv_import_does_nothing_without_action(): void {
		unset( $_POST['ffc_import_action'] );

		// #1030: handle_csv_import() reads its three capability tiers up front,
		// before any action branch, so a capability check is NOT evidence of
		// anything here. Every branch that acts verifies a nonce first — that
		// is the marker for "no branch was entered".

		$wp_verify_nonce = CallLog::record( 'wp_verify_nonce', true ); // expect()->never() cannot see past setUp's stub (#1699).

		$page = new AudienceAdminImport( 'ffc-scheduling' );
		$page->handle_csv_import();

		$this->assertSame( 0, $wp_verify_nonce->count() );
	}

	// ==================================================================
	// export_members_csv() / export_audiences_csv() — streaming path
	// ==================================================================

	/**
	 * A CsvDownloadInterface that captures the export bytes instead of writing
	 * to php://output / calling exit.
	 */
	private function buffered_download(): CsvDownloadInterface {
		return new class() implements CsvDownloadInterface {
			public bool $finished = false;
			public string $output = '';
			/** @var resource|null */
			private $stream = null;

			public function send_headers( string $filename ): void {
				unset( $filename );
			}

			public function open_stream() {
				if ( ! is_resource( $this->stream ) ) {
					$this->stream = fopen( 'php://memory', 'w+' );
				}
				return $this->stream;
			}

			public function finish(): void {
				$this->finished = true;
				if ( is_resource( $this->stream ) ) {
					rewind( $this->stream );
					$this->output = (string) stream_get_contents( $this->stream );
				}
			}
		};
	}

	public function test_export_members_csv_streams_header_and_rows(): void {
		$download = $this->buffered_download();

		$this->audience( 1, 'Turma A' );
		$this->members[1] = array( 10, 11 );

		$u10 = (object) array( 'user_email' => 'a@example.com', 'display_name' => 'Ana' );
		$u11 = (object) array( 'user_email' => 'b@example.com', 'display_name' => 'Bruno' );
		Functions\when( 'get_user_by' )->alias(
			static fn ( $field, $id ) => 10 === $id ? $u10 : ( 11 === $id ? $u11 : false )
		);

		$page = new AudienceAdminImport( 'ffc-scheduling', new CsvStreamer( $download ) );
		$ref  = new \ReflectionMethod( AudienceAdminImport::class, 'export_members_csv' );
		$ref->setAccessible( true );
		$ref->invoke( $page );

		$this->assertTrue( $download->finished, 'stream finished' );
		$this->assertStringContainsString( 'email;name;audience_name', $download->output, 'header' );
		// Assert on substrings that do not cross fputcsv's RFC-4180 quoting so
		// the test is robust to whether the audience name gets enclosed.
		$this->assertStringContainsString( 'a@example.com;Ana;', $download->output );
		$this->assertStringContainsString( 'b@example.com;Bruno;', $download->output );
		$this->assertStringContainsString( 'Turma A', $download->output, 'audience name row present' );
	}

	public function test_export_audiences_csv_streams_hierarchical_rows(): void {
		$download = $this->buffered_download();

		$this->audience( 1, 'Parent', '#abcdef' );
		$this->audience( 2, 'Child', null, 1 );

		$page = new AudienceAdminImport( 'ffc-scheduling', new CsvStreamer( $download ) );
		$ref  = new \ReflectionMethod( AudienceAdminImport::class, 'export_audiences_csv' );
		$ref->setAccessible( true );
		$ref->invoke( $page );

		$this->assertTrue( $download->finished, 'stream finished' );
		$this->assertStringContainsString( 'name;color;parent', $download->output, 'header' );
		$this->assertStringContainsString( 'Parent;#abcdef;', $download->output, 'parent row (no parent col)' );
		$this->assertStringContainsString( 'Child;#3788d8;Parent', $download->output, 'child row (default color, parent set)' );
	}

	// ==================================================================
	// render_content() — child audiences in both selects
	// ==================================================================

	public function test_render_content_lists_child_audiences_in_both_selects(): void {
		$this->audience( 20, 'Grade 1' );
		$this->audience( 21, 'Class 1B', '#3788d8', 20 );

		$page = new AudienceAdminImport( 'ffc-scheduling' );
		ob_start();
		$page->render_content();
		$output = ob_get_clean();

		$this->assertSame( 2, substr_count( $output, '<option value="20">' ), 'Parent offered on import and export.' );
		$this->assertSame( 2, substr_count( $output, '<option value="21">' ), 'Child offered on import and export.' );
		$this->assertStringContainsString( '&nbsp;&nbsp;&nbsp;Class 1B', $output );
	}

	// ==================================================================
	// handle_csv_import() — capability tiers, nonces, uploads
	// ==================================================================

	/**
	 * Grants only the listed capabilities (never `manage_options`).
	 *
	 * @param list<string> $caps Capabilities the current user holds.
	 */
	private function grant( array $caps ): void {
		Functions\when( 'current_user_can' )->alias(
			static fn ( $cap ) => in_array( $cap, $caps, true )
		);
	}

	/**
	 * Records add_settings_error() calls as [message, type] pairs.
	 *
	 * @return \ArrayObject<int, array{0: string, 1: string}>
	 */
	private function capture_notices(): \ArrayObject {
		$notices = new \ArrayObject();
		Functions\when( 'add_settings_error' )->alias(
			static function ( $setting, $code, $message, $type ) use ( $notices ) {
				$notices[] = array( $message, $type );
			}
		);
		return $notices;
	}

	/**
	 * Records every wp_verify_nonce() call as [nonce, action] and answers $valid.
	 *
	 * `Functions\expect()` cannot override the `when()` stub setUp() installs,
	 * so the calls are counted through a second `when()` instead.
	 *
	 * @return \ArrayObject<int, array{0: string, 1: string}>
	 */
	private function record_nonce_checks( bool $valid ): \ArrayObject {
		$calls = new \ArrayObject();
		Functions\when( 'wp_verify_nonce' )->alias(
			static function ( $nonce, $action ) use ( $calls, $valid ) {
				$calls[] = array( $nonce, $action );
				return $valid;
			}
		);
		return $calls;
	}

	public function test_handle_csv_import_returns_when_no_tier_is_held(): void {
		$this->grant( array() );
		$_POST['ffc_action'] = 'import_members';
		$checks              = $this->record_nonce_checks( true );

		( new AudienceAdminImport( 'ffc-scheduling' ) )->handle_csv_import();
		unset( $_POST['ffc_action'] );

		$this->assertCount( 0, $checks, 'No branch may be entered without a capability tier.' );
	}

	public function test_sample_download_streams_the_requested_template(): void {
		$this->grant( array( 'ffc_import_audiences' ) );
		$_GET['download_sample'] = 'audiences';
		$_GET['_wpnonce']        = 'n';
		$download                = $this->buffered_download();

		( new AudienceAdminImport( 'ffc-scheduling', new CsvStreamer( $download ) ) )->handle_csv_import();
		unset( $_GET['download_sample'], $_GET['_wpnonce'] );

		$this->assertTrue( $download->finished );
		$this->assertStringContainsString( 'name;color;parent', $download->output );
		$this->assertStringContainsString( '"Subgroup A1";#dc3545;"Group A"', $download->output );
	}

	public function test_sample_download_with_bad_nonce_streams_nothing(): void {
		$this->grant( array( 'ffc_manage_audiences' ) );
		Functions\when( 'wp_verify_nonce' )->justReturn( false );
		$_GET['download_sample'] = 'members';
		$_GET['_wpnonce']        = 'bad';
		$download                = $this->buffered_download();

		( new AudienceAdminImport( 'ffc-scheduling', new CsvStreamer( $download ) ) )->handle_csv_import();
		unset( $_GET['download_sample'], $_GET['_wpnonce'] );

		$this->assertFalse( $download->finished );
	}

	public function test_sample_download_is_refused_to_an_export_only_role(): void {
		$this->grant( array( 'ffc_export_audiences' ) );
		$_GET['download_sample'] = 'members';
		$_GET['_wpnonce']        = 'n';
		$checks                  = $this->record_nonce_checks( true );
		$download                = $this->buffered_download();

		( new AudienceAdminImport( 'ffc-scheduling', new CsvStreamer( $download ) ) )->handle_csv_import();
		unset( $_GET['download_sample'], $_GET['_wpnonce'] );

		$this->assertCount( 0, $checks );
		$this->assertFalse( $download->finished );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public function export_actions(): array {
		return array(
			'members'   => array( 'export_members' ),
			'audiences' => array( 'export_audiences' ),
		);
	}

	/**
	 * @dataProvider export_actions
	 */
	public function test_export_is_refused_without_the_export_tier( string $action ): void {
		$this->grant( array( 'ffc_import_audiences', 'ffc_manage_audiences' ) );
		$_POST['ffc_action'] = $action;
		$checks              = $this->record_nonce_checks( true );
		$download            = $this->buffered_download();

		( new AudienceAdminImport( 'ffc-scheduling', new CsvStreamer( $download ) ) )->handle_csv_import();
		unset( $_POST['ffc_action'] );

		$this->assertCount( 0, $checks );
		$this->assertFalse( $download->finished );
	}

	/**
	 * @dataProvider export_actions
	 */
	public function test_export_with_bad_nonce_streams_nothing( string $action ): void {
		$this->grant( array( 'ffc_export_audiences' ) );
		$checks              = $this->record_nonce_checks( false );
		$_POST['ffc_action'] = $action;
		$download            = $this->buffered_download();

		( new AudienceAdminImport( 'ffc-scheduling', new CsvStreamer( $download ) ) )->handle_csv_import();
		unset( $_POST['ffc_action'] );

		$this->assertSame( array( array( '', 'ffc_' . $action ) ), $checks->getArrayCopy() );
		$this->assertFalse( $download->finished );
	}

	public function test_export_members_through_the_handler_filters_by_audience(): void {
		$this->grant( array( 'ffc_export_audiences' ) );
		$_POST['ffc_action']         = 'export_members';
		$_POST['export_audience_id'] = '7';
		$download                    = $this->buffered_download();

		$this->audience( 7, 'Seven' );
		$this->audience( 8, 'Eight' );
		$this->members[8] = array( 10 );
		Functions\when( 'get_user_by' )->justReturn( false );

		( new AudienceAdminImport( 'ffc-scheduling', new CsvStreamer( $download ) ) )->handle_csv_import();
		unset( $_POST['ffc_action'], $_POST['export_audience_id'] );

		$member_reads = array_values( array_filter( $this->queries, static fn ( $q ) => str_contains( $q, 'wp_ffc_audience_members' ) ) );
		$this->assertSame(
			array( 'SELECT DISTINCT user_id FROM wp_ffc_audience_members WHERE audience_id IN (7)' ),
			$member_reads,
			'Only the chosen audience\'s members are read, once.'
		);
		$this->assertTrue( $download->finished );
		$this->assertStringContainsString( 'email;name;audience_name', $download->output );
	}

	public function test_export_audiences_through_the_handler_streams(): void {
		$this->grant( array( 'ffc_export_audiences' ) );
		$_POST['ffc_action'] = 'export_audiences';
		$download            = $this->buffered_download();

		( new AudienceAdminImport( 'ffc-scheduling', new CsvStreamer( $download ) ) )->handle_csv_import();
		unset( $_POST['ffc_action'] );

		$this->assertTrue( $download->finished );
		$this->assertStringContainsString( 'name;color;parent', $download->output );
	}

	/**
	 * @return array<string, array{0: string, 1: string, 2: string}>
	 */
	public function import_actions(): array {
		return array(
			'members'   => array( 'import_members', 'members_csv', 'ffc_import_members_nonce' ),
			'audiences' => array( 'import_audiences', 'audiences_csv', 'ffc_import_audiences_nonce' ),
		);
	}

	/**
	 * @dataProvider import_actions
	 */
	public function test_import_is_refused_without_the_import_tier( string $action ): void {
		$this->grant( array( 'ffc_export_audiences', 'ffc_manage_audiences' ) );
		$_POST['ffc_action'] = $action;
		$checks              = $this->record_nonce_checks( true );
		$notices             = $this->capture_notices();

		( new AudienceAdminImport( 'ffc-scheduling' ) )->handle_csv_import();
		unset( $_POST['ffc_action'] );

		$this->assertCount( 0, $checks );
		$this->assertCount( 0, $notices );
	}

	/**
	 * @dataProvider import_actions
	 */
	public function test_import_with_bad_nonce_reports_nothing( string $action, string $file_key, string $nonce_key ): void {
		$this->grant( array( 'ffc_import_audiences' ) );
		$_POST['ffc_action'] = $action;
		$_POST[ $nonce_key ] = 'forged';
		$checks              = $this->record_nonce_checks( false );
		$notices             = $this->capture_notices();

		( new AudienceAdminImport( 'ffc-scheduling' ) )->handle_csv_import();
		unset( $_POST['ffc_action'], $_POST[ $nonce_key ] );

		$this->assertSame( array( array( 'forged', 'ffc_' . $action ) ), $checks->getArrayCopy() );
		$this->assertCount( 0, $notices );
	}

	/**
	 * @dataProvider import_actions
	 */
	public function test_import_reports_a_failed_upload( string $action, string $file_key ): void {
		$this->grant( array( 'ffc_import_audiences' ) );
		$notices             = $this->capture_notices();
		$_POST['ffc_action'] = $action;
		$_FILES[ $file_key ] = array( 'error' => UPLOAD_ERR_NO_FILE );

		( new AudienceAdminImport( 'ffc-scheduling' ) )->handle_csv_import();
		unset( $_POST['ffc_action'], $_FILES[ $file_key ] );

		$this->assertSame( array( array( 'File upload failed.', 'error' ) ), $notices->getArrayCopy() );
	}

	/**
	 * A sheet PHP did not upload is never read: the file below is a valid
	 * import, so had the importer run, it would have asked the database
	 * about the row (and written it).
	 *
	 * @dataProvider import_actions
	 */
	public function test_import_refuses_a_file_php_did_not_upload( string $action, string $file_key ): void {
		$this->grant( array( 'ffc_import_audiences' ) );
		$notices = $this->capture_notices();
		$this->stub_import_path();
		Functions\when( 'is_uploaded_file' )->justReturn( false );
		$file = $this->upload(
			'import_members' === $action ? "email,audience_id\na@example.com,4\n" : "name\nNew Group\n"
		);
		$_POST['ffc_action'] = $action;
		$_FILES[ $file_key ] = array( 'error' => UPLOAD_ERR_OK, 'tmp_name' => $file );

		( new AudienceAdminImport( 'ffc-scheduling' ) )->handle_csv_import();
		unset( $_POST['ffc_action'], $_FILES[ $file_key ] );

		$this->assertSame( array( array( 'Invalid file upload.', 'error' ) ), $notices->getArrayCopy() );
		$this->assertSame( array(), $this->queries, 'The importer never ran.' );
		$this->assertSame( array(), $this->inserts );
	}

	/**
	 * Three rows import and six are refused: the counts go in one notice and
	 * only the first five errors follow it. The members sheet also proves the
	 * form's audience and create-users choices reach the importer: every
	 * membership lands in audience 12, and the unknown address is created.
	 *
	 * @dataProvider import_actions
	 */
	public function test_import_success_reports_counts_and_the_first_five_errors( string $action, string $file_key ): void {
		$this->grant( array( 'ffc_import_audiences' ) );
		$notices = $this->capture_notices();
		$created = $this->stub_import_path();
		Functions\when( 'is_uploaded_file' )->justReturn( true );
		if ( 'import_members' === $action ) {
			$file   = $this->upload(
				"email,name\na@example.com,Ana\n" . str_repeat( "not-an-email,Bad\n", 6 ) . "b@example.com,Bruno\nnew@example.com,New Person\n"
			);
			$errors = array_map( static fn ( $row ) => 'Row ' . $row . ': Invalid email address.', range( 3, 7 ) );
			$_POST['import_audience_id'] = '12';
			$_POST['create_users']       = '1';
		} else {
			$file   = $this->upload( "name,color\nOne,\n" . str_repeat( ",#ff0000\n", 6 ) . "Two,\nThree,\n" );
			$errors = array_map( static fn ( $row ) => 'Row ' . $row . ': Empty name.', range( 3, 7 ) );
		}
		$_POST['ffc_action'] = $action;
		$_FILES[ $file_key ] = array( 'error' => UPLOAD_ERR_OK, 'tmp_name' => $file );

		( new AudienceAdminImport( 'ffc-scheduling' ) )->handle_csv_import();
		unset( $_POST['ffc_action'], $_POST['import_audience_id'], $_POST['create_users'], $_FILES[ $file_key ] );

		$expected = array( array( 'Import completed. 3 imported, 6 skipped. 6 errors occurred.', 'success' ) );
		foreach ( $errors as $error ) {
			$expected[] = array( $error, 'warning' );
		}
		$this->assertSame( $expected, $notices->getArrayCopy() );
		if ( 'import_members' === $action ) {
			$this->assertSame( array( 12 => array( 10, 11, 77 ) ), $this->members, 'Every membership lands in the audience the form chose.' );
			$this->assertCount( 1, $created, 'create_users reached the importer: the unknown address got an account.' );
		} else {
			$this->assertSame( array( 'One', 'Two', 'Three' ), array_column( $this->audiences, 'name' ) );
		}
	}

	/**
	 * @dataProvider import_actions
	 */
	public function test_import_success_without_errors_reports_only_counts( string $action, string $file_key ): void {
		$this->grant( array( 'manage_options' ) );
		$notices = $this->capture_notices();
		$this->stub_import_path();
		Functions\when( 'is_uploaded_file' )->justReturn( true );
		$file = $this->upload(
			'import_members' === $action
				? "email,name,audience_id\na@example.com,Ana,4\nb@example.com,Bruno,4\n"
				: "name,color\nOne,#112233\nTwo,#445566\n"
		);
		$_POST['ffc_action'] = $action;
		$_FILES[ $file_key ] = array( 'error' => UPLOAD_ERR_OK, 'tmp_name' => $file );

		( new AudienceAdminImport( 'ffc-scheduling' ) )->handle_csv_import();
		unset( $_POST['ffc_action'], $_FILES[ $file_key ] );

		$this->assertSame(
			array( array( 'Import completed. 2 imported, 0 skipped.', 'success' ) ),
			$notices->getArrayCopy()
		);
	}

	/**
	 * A refused import is reported as one error notice carrying the
	 * importer's message.
	 *
	 * The earlier version had a mocked importer return two errors and proved
	 * they were joined. The real importer stops at its first failure, so a
	 * failed result carries one error; what this proves now is that it
	 * reaches the operator as an error rather than as a success.
	 *
	 * @dataProvider import_actions
	 */
	public function test_import_failure_reports_the_error_in_one_notice( string $action, string $file_key ): void {
		$this->grant( array( 'ffc_import_audiences' ) );
		$notices = $this->capture_notices();
		$this->stub_import_path();
		Functions\when( 'is_uploaded_file' )->justReturn( true );
		$file = $this->upload( "unrelated\nvalue\n" );
		$_POST['ffc_action'] = $action;
		$_FILES[ $file_key ] = array( 'error' => UPLOAD_ERR_OK, 'tmp_name' => $file );

		( new AudienceAdminImport( 'ffc-scheduling' ) )->handle_csv_import();
		unset( $_POST['ffc_action'], $_FILES[ $file_key ] );

		$column = 'import_members' === $action ? 'email' : 'name';
		$this->assertSame(
			array( array( 'Required column "' . $column . '" not found in CSV.', 'error' ) ),
			$notices->getArrayCopy()
		);
		$this->assertSame( array(), $this->inserts );
	}
}
