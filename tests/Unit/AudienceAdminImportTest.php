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

/**
 * @covers \FreeFormCertificate\Audience\AudienceAdminImport
 * @covers \FreeFormCertificate\Audience\AudienceMembersExportSource
 * @covers \FreeFormCertificate\Audience\AudienceAudiencesExportSource
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class AudienceAdminImportTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
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
		Functions\when( 'settings_errors' )->justReturn( '' );
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

		if ( ! defined( 'ABSPATH' ) ) {
			define( 'ABSPATH', '/tmp/' );
		}

		global $wpdb;
		$wpdb = Mockery::mock( 'wpdb' )->makePartial();
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing( function () { return func_get_arg(0); } )->byDefault();
		$wpdb->shouldReceive( 'get_results' )->andReturn( array() )->byDefault();
		$wpdb->shouldReceive( 'get_var' )->andReturn( '0' )->byDefault();
		$wpdb->shouldReceive( 'get_row' )->andReturn( null )->byDefault();
	}

	protected function tearDown(): void {
		unset( $_POST['ffc_import_action'], $_POST['_wpnonce'] );
		Monkey\tearDown();
		parent::tearDown();
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
		$this->assertSame( 2, substr_count( $output, '<div class="ffc-import-sections">' ) );
		$this->assertStringNotContainsString( 'nav-tab', $output );
		$this->assertStringNotContainsString( 'display: none', $output );
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

		Functions\expect( 'wp_verify_nonce' )->never();

		$page = new AudienceAdminImport( 'ffc-scheduling' );
		$page->handle_csv_import();
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

		$reader = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceReader' );
		$reader->shouldReceive( 'get_all' )->andReturn(
			array( (object) array( 'id' => 1, 'name' => 'Turma A' ) )
		);
		$reader->shouldReceive( 'get_members' )->with( 1 )->andReturn( array( 10, 11 ) );

		Mockery::mock( 'alias:FreeFormCertificate\Core\FilenameHelper' )
			->shouldReceive( 'get_export_filename' )->andReturn( 'members-export.csv' );

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

		$child  = (object) array( 'name' => 'Child', 'color' => null );
		$parent = (object) array(
			'name'     => 'Parent',
			'color'    => '#abcdef',
			'children' => array( $child ),
		);
		Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceReader' )
			->shouldReceive( 'get_hierarchical' )->andReturn( array( $parent ) );
		Mockery::mock( 'alias:FreeFormCertificate\Core\FilenameHelper' )
			->shouldReceive( 'get_export_filename' )->andReturn( 'audiences-export.csv' );

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
		$child  = (object) array( 'id' => 21, 'name' => 'Class 1B' );
		$parent = (object) array( 'id' => 20, 'name' => 'Grade 1', 'children' => array( $child ) );
		Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceReader' )
			->shouldReceive( 'get_hierarchical' )->andReturn( array( $parent ) );

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

		$reader = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceReader' );
		$reader->shouldReceive( 'get_all' )->andReturn(
			array( (object) array( 'id' => 7, 'name' => 'Seven' ), (object) array( 'id' => 8, 'name' => 'Eight' ) )
		);
		$reader->shouldReceive( 'get_members' )->once()->with( 7 )->andReturn( array() );
		Mockery::mock( 'alias:FreeFormCertificate\Core\FilenameHelper' )
			->shouldReceive( 'get_export_filename' )->andReturn( 'members.csv' );

		( new AudienceAdminImport( 'ffc-scheduling', new CsvStreamer( $download ) ) )->handle_csv_import();
		unset( $_POST['ffc_action'], $_POST['export_audience_id'] );

		$this->assertTrue( $download->finished );
		$this->assertStringContainsString( 'email;name;audience_name', $download->output );
	}

	public function test_export_audiences_through_the_handler_streams(): void {
		$this->grant( array( 'ffc_export_audiences' ) );
		$_POST['ffc_action'] = 'export_audiences';
		$download            = $this->buffered_download();

		Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceReader' )
			->shouldReceive( 'get_hierarchical' )->andReturn( array() );
		Mockery::mock( 'alias:FreeFormCertificate\Core\FilenameHelper' )
			->shouldReceive( 'get_export_filename' )->andReturn( 'audiences.csv' );

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
	 * @dataProvider import_actions
	 */
	public function test_import_refuses_a_file_php_did_not_upload( string $action, string $file_key ): void {
		$this->grant( array( 'ffc_import_audiences' ) );
		$notices = $this->capture_notices();
		Functions\when( 'is_uploaded_file' )->justReturn( false );
		Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceCsvImporter' )
			->shouldNotReceive( 'import_members', 'import_audiences' );
		$_POST['ffc_action'] = $action;
		$_FILES[ $file_key ] = array( 'error' => UPLOAD_ERR_OK, 'tmp_name' => '/etc/passwd' );

		( new AudienceAdminImport( 'ffc-scheduling' ) )->handle_csv_import();
		unset( $_POST['ffc_action'], $_FILES[ $file_key ] );

		$this->assertSame( array( array( 'Invalid file upload.', 'error' ) ), $notices->getArrayCopy() );
	}

	/**
	 * @dataProvider import_actions
	 */
	public function test_import_success_reports_counts_and_the_first_five_errors( string $action, string $file_key ): void {
		$this->grant( array( 'ffc_import_audiences' ) );
		$notices = $this->capture_notices();
		Functions\when( 'is_uploaded_file' )->justReturn( true );
		$result   = array(
			'success'  => true,
			'imported' => 3,
			'skipped'  => 1,
			'errors'   => array( 'e1', 'e2', 'e3', 'e4', 'e5', 'e6' ),
		);
		$importer = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceCsvImporter' );
		if ( 'import_members' === $action ) {
			$importer->shouldReceive( 'import_members' )->once()->with( '/tmp/upload.csv', 12, true )->andReturn( $result );
			$_POST['import_audience_id'] = '12';
			$_POST['create_users']       = '1';
		} else {
			$importer->shouldReceive( 'import_audiences' )->once()->with( '/tmp/upload.csv' )->andReturn( $result );
		}
		$_POST['ffc_action'] = $action;
		$_FILES[ $file_key ] = array( 'error' => UPLOAD_ERR_OK, 'tmp_name' => '/tmp/upload.csv' );

		( new AudienceAdminImport( 'ffc-scheduling' ) )->handle_csv_import();
		unset( $_POST['ffc_action'], $_POST['import_audience_id'], $_POST['create_users'], $_FILES[ $file_key ] );

		$this->assertSame(
			array(
				array( 'Import completed. 3 imported, 1 skipped. 6 errors occurred.', 'success' ),
				array( 'e1', 'warning' ),
				array( 'e2', 'warning' ),
				array( 'e3', 'warning' ),
				array( 'e4', 'warning' ),
				array( 'e5', 'warning' ),
			),
			$notices->getArrayCopy()
		);
	}

	/**
	 * @dataProvider import_actions
	 */
	public function test_import_success_without_errors_reports_only_counts( string $action, string $file_key ): void {
		$this->grant( array( 'manage_options' ) );
		$notices = $this->capture_notices();
		Functions\when( 'is_uploaded_file' )->justReturn( true );
		$result = array( 'success' => true, 'imported' => 2, 'skipped' => 0, 'errors' => array() );
		Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceCsvImporter' )
			->shouldReceive( 'import_members', 'import_audiences' )->andReturn( $result );
		$_POST['ffc_action'] = $action;
		$_FILES[ $file_key ] = array( 'error' => UPLOAD_ERR_OK, 'tmp_name' => '/tmp/upload.csv' );

		( new AudienceAdminImport( 'ffc-scheduling' ) )->handle_csv_import();
		unset( $_POST['ffc_action'], $_FILES[ $file_key ] );

		$this->assertSame(
			array( array( 'Import completed. 2 imported, 0 skipped.', 'success' ) ),
			$notices->getArrayCopy()
		);
	}

	/**
	 * @dataProvider import_actions
	 */
	public function test_import_failure_joins_errors_into_one_notice( string $action, string $file_key ): void {
		$this->grant( array( 'ffc_import_audiences' ) );
		$notices = $this->capture_notices();
		Functions\when( 'is_uploaded_file' )->justReturn( true );
		$result = array( 'success' => false, 'imported' => 0, 'skipped' => 0, 'errors' => array( 'Missing column.', 'Empty file.' ) );
		Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceCsvImporter' )
			->shouldReceive( 'import_members', 'import_audiences' )->andReturn( $result );
		$_POST['ffc_action'] = $action;
		$_FILES[ $file_key ] = array( 'error' => UPLOAD_ERR_OK, 'tmp_name' => '/tmp/upload.csv' );

		( new AudienceAdminImport( 'ffc-scheduling' ) )->handle_csv_import();
		unset( $_POST['ffc_action'], $_FILES[ $file_key ] );

		$this->assertSame( array( array( 'Missing column. Empty file.', 'error' ) ), $notices->getArrayCopy() );
	}
}
