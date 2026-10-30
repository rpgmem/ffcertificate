<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Reregistration\ReregistrationAdmin;

/**
 * Tests for ReregistrationAdmin's page routing, its capability tiers, the
 * campaign save transitions and the editor-only import assets.
 *
 * Every collaborator is the real class -- ReregistrationAdminRenderer and its
 * templates, ReregistrationRepository, ReregistrationSubmissionWriter and
 * Reader, ReregistrationSubmissionActions, AudienceReader, PluginAreas and
 * DateFormatter -- over a `$wpdb` double that answers each query by its SQL
 * text and the values bound to it, from the arrays a test sets, and records
 * every prepared statement and every write. A query the double does not know
 * fails the test. A save ends in `exit` after its redirect, so the redirect
 * throws to stand in for it.
 *
 * @covers \FreeFormCertificate\Reregistration\ReregistrationAdmin
 */
class ReregistrationAdminPageTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var list<string> */
	private array $redirects = array();

	/** @var list<array<int, mixed>> Every prepare() call, as its flattened argument list. */
	private array $prepared = array();

	/**
	 * Every write, in order: [ method, table or SQL, data, where ].
	 *
	 * @var list<array{0: string, 1: string, 2: mixed, 3: mixed}>
	 */
	private array $writes = array();

	/** @var array<int, object> Campaigns by id, answered by get_by_id(). */
	private array $campaigns = array();

	/** @var array<int, list<int>> Member ids by audience id. */
	private array $members = array();

	/** @var int|false What inserting a campaign answers. */
	private $create_result = 1;

	/** @var list<string> Recipients `wp_mail()` was called with. */
	private array $mailed = array();

	/** @var mixed The `$wpdb` global an earlier test left, restored afterwards. */
	private $wpdb_backup;

	/** @var bool Whether `$wpdb` was set before this test. */
	private bool $had_wpdb = false;

	/** @var array<string, mixed> The `$_GET` an earlier test left, restored afterwards. */
	private array $get_backup = array();

	/** @var array<string, mixed> The `$_POST` an earlier test left, restored afterwards. */
	private array $post_backup = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->get_backup  = $_GET ?? array();
		$this->post_backup = $_POST ?? array();
		$_GET              = array();
		$_POST             = array();

		// Shared scripts are enqueued through AssetRegistry, which registers
		// them first; false is WordPress's answer for a handle already
		// registered, so nothing is localized twice (#1931).
		Functions\when( 'wp_register_script' )->justReturn( false );

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_attr__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'esc_html_e' )->alias( function ( $text ) { echo $text; } );
		Functions\when( 'esc_attr_e' )->alias( function ( $text ) { echo $text; } );
		Functions\when( 'absint' )->alias( function ( $v ) { return abs( (int) $v ); } );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'admin_url' )->alias( function ( $path = '' ) {
			return 'https://example.com/wp-admin/' . $path;
		} );
		Functions\when( 'wp_create_nonce' )->justReturn( 'nonce' );
		Functions\when( 'wp_nonce_url' )->alias( function ( $url, $action ) {
			return $url . '&_wpnonce=' . $action;
		} );
		Functions\when( 'wp_nonce_field' )->alias( function ( $action ) {
			echo '<input type="hidden" data-nonce="' . $action . '">';
		} );
		Functions\when( 'selected' )->justReturn( '' );
		Functions\when( 'get_settings_errors' )->justReturn( array() );
		Functions\when( 'wp_parse_args' )->alias( static fn ( $args, $defaults ) => array_merge( $defaults, $args ) );
		Functions\when( 'sanitize_sql_orderby' )->returnArg();
		Functions\when( 'wp_json_encode' )->alias( static fn ( $data ) => json_encode( $data ) );
		Functions\when( 'number_format_i18n' )->alias( static fn ( $n ) => number_format( (float) $n ) );
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		// DateFormatter reads the plugin settings, so the formats are chosen
		// here; emails, the activity log and debug keep their defaults.
		Functions\when( 'get_option' )->alias(
			static fn ( $key, $default = false ) => 'ffc_settings' === $key
				? array( 'date_format' => 'd/m/Y', 'time_format' => 'H:i' )
				: $default
		);
		Functions\when( 'wp_timezone' )->alias( static fn () => new \DateTimeZone( 'UTC' ) );
		Functions\when( 'wp_date' )->alias(
			static fn ( $format, $ts, $tz = null ) => ( new \DateTimeImmutable( '@' . (int) $ts ) )
				->setTimezone( $tz instanceof \DateTimeZone ? $tz : new \DateTimeZone( 'UTC' ) )
				->format( $format )
		);
		Functions\when( 'current_time' )->justReturn( '2026-10-28 10:00:00' );
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'user_can' )->justReturn( false );
		Functions\when( 'get_user_by' )->justReturn( false );
		Functions\when( 'wp_die' )->alias( function ( $message ) {
			throw new \RuntimeException( 'wp_die: ' . $message );
		} );

		$this->redirects = array();
		$redirects       = &$this->redirects;
		Functions\when( 'wp_safe_redirect' )->alias( function ( $url ) use ( &$redirects ) {
			$redirects[] = $url;
			throw new \RuntimeException( 'redirected' );
		} );

		$this->mailed = array();
		$mailed       = &$this->mailed;
		Functions\when( 'wp_mail' )->alias( static function ( $to ) use ( &$mailed ) {
			$mailed[] = $to;
			return true;
		} );

		$this->prepared      = array();
		$this->writes        = array();
		$this->campaigns     = array();
		$this->members       = array();
		$this->create_result = 1;

		$this->had_wpdb    = array_key_exists( 'wpdb', $GLOBALS );
		$this->wpdb_backup = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb']   = $this->wpdbDouble();
	}

	protected function tearDown(): void {
		if ( $this->had_wpdb ) {
			$GLOBALS['wpdb'] = $this->wpdb_backup;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		$_GET  = $this->get_backup;
		$_POST = $this->post_backup;
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * A `$wpdb` double answering the queries the screen, the save and the
	 * submission handlers run.
	 *
	 * @return Mockery\MockInterface
	 */
	private function wpdbDouble() {
		$wpdb            = Mockery::mock( 'wpdb' );
		$wpdb->prefix    = 'wp_';
		$wpdb->users     = 'wp_users';
		$wpdb->insert_id = 0;

		$wpdb->shouldReceive( 'esc_like' )->andReturnUsing( static fn ( string $text ): string => addcslashes( $text, '_%\\' ) );
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			function ( ...$args ) {
				$flat             = 2 === count( $args ) && is_array( $args[1] ) ? array_merge( array( $args[0] ), $args[1] ) : $args;
				$this->prepared[] = $flat;
				return 'Q' . ( count( $this->prepared ) - 1 ) . ':' . $flat[0];
			}
		);
		$wpdb->shouldReceive( 'get_row' )->andReturnUsing(
			function ( $sql ) {
				$bound = $this->boundTo( (string) $sql );
				if ( false !== strpos( (string) $sql, 'SELECT * FROM %i WHERE id = %d' ) && 'wp_ffc_reregistrations' === $bound[1] ) {
					return $this->campaigns[ (int) $bound[2] ] ?? null;
				}
				$this->fail( 'Unexpected get_row(): ' . $sql . ' with ' . var_export( $bound, true ) );
			}
		);
		$wpdb->shouldReceive( 'get_col' )->andReturnUsing(
			function ( $sql ) {
				$bound = $this->boundTo( (string) $sql );
				if ( false !== strpos( (string) $sql, 'SELECT DISTINCT user_id FROM %i WHERE audience_id IN' ) && 'wp_ffc_audience_members' === $bound[1] ) {
					$users = array();
					foreach ( array_slice( $bound, 2 ) as $audience_id ) {
						$users = array_merge( $users, $this->members[ (int) $audience_id ] ?? array() );
					}
					return array_map( 'strval', $users );
				}
				$this->fail( 'Unexpected get_col(): ' . $sql . ' with ' . var_export( $bound, true ) );
			}
		);
		$wpdb->shouldReceive( 'get_results' )->andReturnUsing(
			function ( $sql ) {
				$bound = $this->boundTo( (string) $sql );
				$text  = (string) $sql;
				if ( false !== strpos( $text, 'SELECT DISTINCT r.*' ) ) {
					return array();
				}
				if ( false !== strpos( $text, 'SELECT status, COUNT(*)' ) ) {
					return array();
				}
				if ( false !== strpos( $text, 'LEFT JOIN %i u ON s.user_id = u.ID' ) ) {
					return array();
				}
				if ( false !== strpos( $text, 'SELECT audience_id, user_id FROM %i' ) ) {
					// AudienceReader::get_member_ids_by_audience(): nobody, so no get_users().
					return array();
				}
				if ( 'wp_ffc_audiences' === ( $bound[1] ?? null ) && false !== strpos( $text, 'SELECT * FROM %i' ) ) {
					// No audience exists, and none has children.
					return array();
				}
				$this->fail( 'Unexpected get_results(): ' . $sql . ' with ' . var_export( $bound, true ) );
			}
		);
		$wpdb->shouldReceive( 'query' )->andReturnUsing(
			function ( $sql ) {
				$bound          = $this->boundTo( (string) $sql );
				$text           = (string) ( $bound[0] ?? $sql );
				$this->writes[] = array( 'query', $text, array_slice( $bound, 1 ), null );
				if ( 0 === strpos( $text, 'INSERT IGNORE INTO %i (reregistration_id, user_id, status)' ) ) {
					return intdiv( count( $bound ) - 2, 3 );
				}
				if ( 0 === strpos( $text, 'UPDATE %i SET status = \'pending\'' ) ) {
					return 0;
				}
				$this->fail( 'Unexpected query(): ' . $text );
			}
		);
		$wpdb->shouldReceive( 'update' )->andReturnUsing(
			function ( $table, $data, $where ) {
				$this->writes[] = array( 'update', $table, $data, $where );
				if ( 'wp_ffc_reregistrations' === $table ) {
					return 1;
				}
				$this->fail( 'Unexpected update() on ' . $table );
			}
		);
		$wpdb->shouldReceive( 'insert' )->andReturnUsing(
			function ( $table, $data ) use ( $wpdb ) {
				$this->writes[] = array( 'insert', $table, $data, null );
				if ( 'wp_ffc_reregistrations' === $table ) {
					$wpdb->insert_id = false === $this->create_result ? 0 : 41;
					return $this->create_result;
				}
				if ( 'wp_ffc_reregistration_audiences' === $table ) {
					return 1;
				}
				$this->fail( 'Unexpected insert() on ' . $table );
			}
		);
		$wpdb->shouldReceive( 'delete' )->andReturnUsing(
			function ( $table, $where ) {
				$this->writes[] = array( 'delete', $table, null, $where );
				if ( 'wp_ffc_reregistration_audiences' === $table ) {
					return 1;
				}
				$this->fail( 'Unexpected delete() on ' . $table );
			}
		);

		return $wpdb;
	}

	/**
	 * The prepare() argument list behind a prepared query string.
	 *
	 * @return array<int, mixed>
	 */
	private function boundTo( string $sql ): array {
		if ( preg_match( '/^Q(\d+):/', $sql, $match ) ) {
			return $this->prepared[ (int) $match[1] ] ?? array();
		}
		return array( $sql );
	}

	/**
	 * Every prepare() call whose SQL contains $needle.
	 *
	 * @return list<array<int, mixed>>
	 */
	private function preparedWith( string $needle ): array {
		return array_values(
			array_filter( $this->prepared, static fn ( array $call ): bool => false !== strpos( (string) $call[0], $needle ) )
		);
	}

	/**
	 * The writes of one kind on one table, or the queries whose SQL starts with it.
	 *
	 * @return list<array{0: string, 1: string, 2: mixed, 3: mixed}>
	 */
	private function writesOf( string $method, string $target ): array {
		return array_values(
			array_filter(
				$this->writes,
				static fn ( array $w ): bool => $method === $w[0] && ( 'query' === $method ? 0 === strpos( $w[1], $target ) : $target === $w[1] )
			)
		);
	}

	/**
	 * A stored campaign whose deadline is the one postSave() posts, so the
	 * update is not a deadline extension.
	 */
	private function campaign( int $id, string $status ): object {
		return (object) array(
			'id'         => $id,
			'title'      => 'Census',
			'status'     => $status,
			'start_date' => '2026-01-01 08:00:00',
			'end_date'   => '2026-02-01 18:00:00',
		);
	}

	/**
	 * @param list<string> $caps Capabilities held (never manage_options unless listed).
	 */
	private function grant( array $caps ): void {
		Functions\when( 'current_user_can' )->alias( static fn ( $cap ) => in_array( $cap, $caps, true ) );
	}

	private function invoke( ReregistrationAdmin $admin, string $method ): void {
		$ref = new \ReflectionMethod( ReregistrationAdmin::class, $method );
		$ref->setAccessible( true );
		try {
			$ref->invoke( $admin );
		} catch ( \RuntimeException $e ) {
			if ( 'redirected' !== $e->getMessage() ) {
				throw $e;
			}
		}
	}

	// ==================================================================
	// render_page() — routing and tiers
	// ==================================================================

	private function renderPage(): string {
		ob_start();
		try {
			( new ReregistrationAdmin() )->render_page();
		} finally {
			$html = (string) ob_get_clean();
		}
		return $html;
	}

	public function test_a_viewer_cannot_open_the_campaign_editor(): void {
		$this->grant( array( 'ffc_view_reregistration' ) );
		$_GET['view'] = 'edit';
		$_GET['id']   = '7';

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'wp_die: Permission denied.' );

		try {
			$this->renderPage();
		} finally {
			$this->assertSame( array(), $this->prepared, 'The editor is never drawn: no campaign is read.' );
		}
	}

	public function test_a_viewer_gets_the_read_only_list(): void {
		$this->grant( array( 'ffc_view_reregistration' ) );

		$html = $this->renderPage();

		$this->assertStringStartsWith( '<div class="wrap ffc-admin-page ffc-page-reregistration ">', $html );
		$this->assertStringEndsWith( '</div>', $html );
		$this->assertCount( 1, $this->preparedWith( 'SELECT DISTINCT r.*' ), 'The campaign list is drawn.' );
		$this->assertStringContainsString( 'No reregistrations found.', $html );
		$this->assertStringNotContainsString( 'view=new', $html, 'A read-only viewer gets no Add New.' );
	}

	public function test_a_manager_gets_the_boxed_editor_for_a_new_campaign(): void {
		// Manage alone opens the screen: the 3-state model does not ask a
		// manage role to hold the view cap as well (#1698).
		$this->grant( array( 'ffc_manage_reregistration' ) );
		$_GET['view'] = 'new';

		$html = $this->renderPage();

		$this->assertStringContainsString( 'ffc-page-reregistration ffc-boxed', $html );
		$this->assertStringContainsString( '<h1>New Reregistration</h1>', $html, 'The editor is drawn for a new campaign.' );
		$this->assertSame( array(), $this->preparedWith( 'WHERE id = %d' ), 'Campaign 0 is never read.' );
	}

	public function test_a_manage_only_role_gets_the_editable_list(): void {
		$this->grant( array( 'ffc_manage_reregistration' ) );

		$html = $this->renderPage();

		$this->assertCount( 1, $this->preparedWith( 'SELECT DISTINCT r.*' ), 'The campaign list is drawn.' );
		$this->assertStringContainsString( 'admin.php?page=ffc-reregistration&view=new', $html, 'The edit tier gets Add New.' );
	}

	public function test_a_role_with_neither_tier_is_refused(): void {
		$this->grant( array( 'ffc_view_audiences' ) );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'wp_die: Permission denied.' );

		try {
			$this->renderPage();
		} finally {
			$this->assertSame( array(), $this->prepared, 'No list is drawn.' );
		}
	}

	/**
	 * @return array<string, array{0: list<string>, 1: string}>
	 */
	public static function menu_capability_cases(): array {
		return array(
			'view only'   => array( array( 'ffc_view_reregistration' ), 'ffc_view_reregistration' ),
			'manage only' => array( array( 'ffc_manage_reregistration' ), 'ffc_manage_reregistration' ),
			'both'        => array( array( 'ffc_view_reregistration', 'ffc_manage_reregistration' ), 'ffc_view_reregistration' ),
			'neither'     => array( array(), 'ffc_view_reregistration' ),
		);
	}

	/**
	 * WordPress gates a menu on one capability, so the menu names whichever
	 * tier the user holds; otherwise a manage-only role never reaches the page.
	 *
	 * @dataProvider menu_capability_cases
	 *
	 * @param list<string> $caps     Capabilities held.
	 * @param string       $expected Capability the campaign menu is registered with.
	 */
	public function test_the_menu_is_registered_under_the_tier_the_user_holds( array $caps, string $expected ): void {
		$this->grant( $caps );
		$registered = array();
		$titles     = array();
		Functions\when( 'add_menu_page' )->alias(
			function () use ( &$registered, &$titles ) {
				$registered[ func_get_arg( 3 ) ] = func_get_arg( 2 );
				$titles[]                        = func_get_arg( 0 );
				return 'hook';
			}
		);
		Functions\when( 'add_submenu_page' )->alias(
			function () use ( &$registered ) {
				$registered[ 'sub:' . func_get_arg( 4 ) ] = func_get_arg( 3 );
				return 'hook';
			}
		);
		( new ReregistrationAdmin() )->add_menu();

		$this->assertSame( array( 'Reregistration' ), $titles, 'The top-level menu carries the area\'s name.' );
		$this->assertSame( $expected, $registered['ffc-reregistration'] );
		$this->assertSame( $expected, $registered['sub:ffc-reregistration'] );
		$this->assertSame( 'ffc_manage_reregistration', $registered['sub:ffc-custom-fields'] );
	}

	public function test_the_submissions_view_carries_the_id_and_the_edit_tier(): void {
		$this->grant( array( 'manage_options' ) );
		$_GET['view']       = 'submissions';
		$_GET['id']         = '12';
		$this->campaigns[12] = $this->campaign( 12, 'active' );

		$html = $this->renderPage();

		$query = $this->preparedWith( 'LEFT JOIN %i u' );
		$this->assertCount( 1, $query, 'The submissions are drawn.' );
		$this->assertSame( 12, $query[0][3], 'They are read for the campaign in the request.' );
		$this->assertStringContainsString( 'name="bulk_action"', $html, 'The edit tier gets the bulk actions.' );
		$this->assertStringNotContainsString( 'ffc-boxed', $html );
	}

	// ==================================================================
	// enqueue_assets() — palette registration and the import client
	// ==================================================================

	/**
	 * @return \ArrayObject<string, list<string>>
	 */
	private function recordEnqueues(): \ArrayObject {
		$log  = new \ArrayObject( array( 'registered' => array(), 'styles' => array(), 'scripts' => array(), 'localized' => array() ) );
		$push = static function ( string $key, string $value ) use ( $log ): void {
			$list       = $log[ $key ];
			$list[]     = $value;
			$log[ $key ] = $list;
		};
		Functions\when( 'wp_register_style' )->alias( static function ( $handle ) use ( $push ) { $push( 'registered', $handle ); } );
		Functions\when( 'wp_enqueue_style' )->alias( static function ( $handle ) use ( $push ) { $push( 'styles', $handle ); } );
		Functions\when( 'wp_enqueue_script' )->alias( static function ( $handle ) use ( $push ) { $push( 'scripts', $handle ); } );
		Functions\when( 'wp_localize_script' )->alias( static function ( $handle, $name ) use ( $push ) { $push( 'localized', $name ); return true; } );
		return $log;
	}

	public function test_the_editor_enqueues_the_palette_and_loads_the_import_client(): void {
		$log          = $this->recordEnqueues();
		$_GET['view'] = 'edit';

		( new ReregistrationAdmin() )->enqueue_assets( 'toplevel_page_ffc-reregistration' );

		$this->assertSame( array(), $log['registered'] );
		$this->assertContains( 'ffc-common', $log['styles'] );
		$this->assertContains( 'ffc-reregistration-import', $log['scripts'] );
		$this->assertSame( array( 'ffcRecordAutosave', 'ffcReregistrationAdmin', 'ffcReregImport' ), $log['localized'] );
		$this->assertNotContains( 'jspdf', $log['scripts'] );
	}

	public function test_the_custom_fields_screen_gets_the_assets_without_the_import_client(): void {
		$log = $this->recordEnqueues();

		( new ReregistrationAdmin() )->enqueue_assets( 'audience_page_ffc-custom-fields' );

		$this->assertContains( 'ffc-reregistration-admin', $log['scripts'] );
		$this->assertNotContains( 'ffc-reregistration-import', $log['scripts'] );
	}

	// ==================================================================
	// handle_actions() — messages and delegation
	// ==================================================================

	/**
	 * Each submission handler is reached: a request naming its action gets as
	 * far as that handler's own nonce check. The nonces are refused, so
	 * nothing is written and nobody is redirected.
	 */
	public function test_handle_actions_reports_a_known_message_and_delegates_the_workflow(): void {
		$this->grant( array( 'ffc_manage_reregistration' ) );
		$notices = array();
		Functions\when( 'add_settings_error' )->alias(
			static function ( $setting, $code, $message, $type ) use ( &$notices ) {
				$notices[] = array( $message, $type );
			}
		);
		$nonces = array();
		Functions\when( 'wp_verify_nonce' )->alias(
			static function ( $nonce, $action ) use ( &$nonces ) {
				$nonces[] = $action;
				return false;
			}
		);

		$_GET  = array(
			'page'     => 'ffc-reregistration',
			'message'  => 'reminders_sent',
			'action'   => 'approve',
			'sub_id'   => '3',
			'id'       => '4',
			'_wpnonce' => 'bad',
		);
		$_POST = array(
			'ffc_action'        => 'bulk_submissions',
			'reregistration_id' => '4',
			'ffc_bulk_nonce'    => 'bad',
		);
		( new ReregistrationAdmin() )->handle_actions();

		// Reject and Return to Draft post the review modal (#2242).
		$_GET  = array( 'page' => 'ffc-reregistration' );
		$_POST = array(
			'ffc_action'        => 'review_decision',
			'reregistration_id' => '4',
			'ffc_review_nonce'  => 'bad',
			'decision'          => 'reject',
		);
		( new ReregistrationAdmin() )->handle_actions();

		$this->assertSame( array( array( 'Reminder emails sent.', 'success' ) ), $notices );
		$this->assertSame(
			array( 'approve_submission_3', 'bulk_submissions_4', 'review_decision_4' ),
			$nonces,
			'Approve, bulk and the review decision each reach their handler.'
		);
		$this->assertSame( array(), $this->writes );
		$this->assertSame( array(), $this->redirects );
	}

	public function test_handle_actions_ignores_an_unknown_message(): void {
		$this->grant( array( 'ffc_manage_reregistration' ) );
		$_GET['page']    = 'ffc-reregistration';
		$_GET['message'] = 'forged';
		$notices         = array();
		Functions\when( 'add_settings_error' )->alias(
			static function () use ( &$notices ) {
				$notices[] = func_get_args();
			}
		);

		( new ReregistrationAdmin() )->handle_actions();

		$this->assertSame( array(), $notices );
		$this->assertSame( array(), $this->writes, 'No handler acts on a request that names no action.' );
	}

	/**
	 * After an activation the screen says nobody was mailed yet and names the
	 * daily run that will do it (#1762).
	 */
	public function test_handle_actions_announces_the_next_daily_run_after_activation(): void {
		$this->grant( array( 'ffc_manage_reregistration' ) );
		$_GET['page']        = 'ffc-reregistration';
		$_GET['invitations'] = 'scheduled';
		$notices             = array();
		Functions\when( 'add_settings_error' )->alias(
			static function ( $setting, $code, $message, $type ) use ( &$notices ) {
				$notices[] = array( $code, $message, $type );
			}
		);
		Functions\when( 'wp_next_scheduled' )->alias( static fn( $hook ) => 'ffcertificate_reregistration_expire_hook' === $hook ? 1800000000 : false );

		( new ReregistrationAdmin() )->handle_actions();

		$this->assertCount( 1, $notices );
		$this->assertSame( 'ffc_invitations_scheduled', $notices[0][0] );
		// 1800000000 is 2027-01-15 08:00 UTC, in the d/m/Y H:i the settings ask for.
		$this->assertStringContainsString( 'on 15/01/2027 08:00', $notices[0][1] );
		$this->assertSame( 'info', $notices[0][2] );
	}

	/**
	 * With the daily event missing, the notice says so instead of naming a
	 * time nobody scheduled.
	 */
	public function test_the_invitations_notice_says_when_the_daily_run_is_not_scheduled(): void {
		Functions\when( 'wp_next_scheduled' )->justReturn( false );

		$message = ReregistrationAdmin::invitations_scheduled_message();

		$this->assertStringContainsString( 'not scheduled', $message );
		$this->assertStringContainsString( 'Settings → Scheduled Tasks', $message );
	}

	// ==================================================================
	// handle_save() — status transitions
	// ==================================================================

	/**
	 * @param array<string, mixed> $post Extra POST fields.
	 */
	private function postSave( int $id, string $status, array $post = array() ): void {
		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		$_POST = array_merge(
			array(
				'ffc_action'               => 'save_reregistration',
				'ffc_reregistration_nonce' => 'n',
				'reregistration_id'        => (string) $id,
				'rereg_title'              => 'Census',
				'rereg_start_date'         => '2026-01-01T08:00',
				'rereg_end_date'           => '2026-02-01T18:00',
				'rereg_status'             => $status,
				'rereg_reminder_days'      => '3',
				'rereg_audience_ids'       => array( '4', '5' ),
			),
			$post
		);
	}

	public function test_saving_a_draft_campaign_as_active_reopens_and_seeds_in_order_without_mailing(): void {
		$this->postSave( 9, 'active', array( 'rereg_auto_approve' => '1', 'rereg_email_reminder' => '1' ) );
		$this->campaigns[9] = $this->campaign( 9, 'draft' );
		$this->members      = array(
			4 => array( 50 ),
			5 => array( 51 ),
		);

		$this->invoke( new ReregistrationAdmin(), 'handle_save' );

		$updates = $this->writesOf( 'update', 'wp_ffc_reregistrations' );
		$this->assertCount( 1, $updates );
		$this->assertSame( array( 'id' => 9 ), $updates[0][3] );
		$this->assertSame( 1, $updates[0][2]['auto_approve'] );
		$this->assertSame( 0, $updates[0][2]['email_invitation_enabled'] );
		$this->assertSame( 1, $updates[0][2]['email_reminder_enabled'] );
		$this->assertSame( 3, $updates[0][2]['reminder_days'] );
		$this->assertSame( 'active', $updates[0][2]['status'] );
		$this->assertSame(
			array( array( 'reregistration_id' => 9, 'audience_id' => 4 ), array( 'reregistration_id' => 9, 'audience_id' => 5 ) ),
			array_column( $this->writesOf( 'insert', 'wp_ffc_reregistration_audiences' ), 2 )
		);

		// Expired submissions are reopened before the members are seeded.
		$order = array();
		foreach ( $this->writesOf( 'query', '' ) as $query ) {
			$order[] = 0 === strpos( $query[1], 'UPDATE' ) ? 'reopen' : 'seed';
		}
		$this->assertSame( array( 'reopen', 'seed' ), $order );
		$reopen = $this->writesOf( 'query', 'UPDATE' )[0];
		$this->assertSame( array( 'wp_ffc_reregistration_submissions', '2026-10-28 10:00:00', 9 ), $reopen[2] );
		$seed = $this->writesOf( 'query', 'INSERT IGNORE' )[0];
		$this->assertSame( array( 'wp_ffc_reregistration_submissions', 9, 50, 'pending', 9, 51, 'pending' ), $seed[2], 'Audiences 4 and 5 are seeded into campaign 9.' );

		// Invitations are off in this post, so there is nothing to announce.
		$this->assertSame( array(), $this->mailed, 'Nobody is mailed on save.' );
		$this->assertSame( array( 'https://example.com/wp-admin/admin.php?page=ffc-reregistration&view=edit&id=9&message=updated' ), $this->redirects );
	}

	public function test_saving_an_already_active_campaign_does_not_invite_again(): void {
		$this->postSave( 9, 'active' );
		$this->campaigns[9] = $this->campaign( 9, 'active' );

		$this->invoke( new ReregistrationAdmin(), 'handle_save' );

		$this->assertCount( 1, $this->writesOf( 'update', 'wp_ffc_reregistrations' ) );
		$this->assertCount( 1, $this->writesOf( 'delete', 'wp_ffc_reregistration_audiences' ), 'The audiences are linked again.' );
		$this->assertSame( array(), $this->writesOf( 'query', '' ), 'Nothing is reopened or seeded.' );
		$this->assertSame( array(), $this->mailed );
		$this->assertCount( 1, $this->redirects );
	}

	public function test_creating_an_active_campaign_seeds_and_announces_the_daily_invitations(): void {
		$this->postSave( 0, 'active', array( 'rereg_email_invitation' => '1' ) );
		$this->members = array(
			4 => array( 50 ),
			5 => array( 51 ),
		);

		$this->invoke( new ReregistrationAdmin(), 'handle_save' );

		$this->assertSame( array(), $this->preparedWith( 'WHERE id = %d' ), 'A new campaign has no previous status to read.' );
		$this->assertCount( 1, $this->writesOf( 'insert', 'wp_ffc_reregistrations' ) );
		$this->assertSame(
			array( array( 'reregistration_id' => 41, 'audience_id' => 4 ), array( 'reregistration_id' => 41, 'audience_id' => 5 ) ),
			array_column( $this->writesOf( 'insert', 'wp_ffc_reregistration_audiences' ), 2 )
		);
		$seed = $this->writesOf( 'query', 'INSERT IGNORE' );
		$this->assertCount( 1, $seed );
		$this->assertSame( array( 'wp_ffc_reregistration_submissions', 41, 50, 'pending', 41, 51, 'pending' ), $seed[0][2] );
		$this->assertSame( array(), $this->mailed, 'The invitations wait for the daily run.' );
		$this->assertSame( array( 'https://example.com/wp-admin/admin.php?page=ffc-reregistration&view=edit&id=41&message=created&invitations=scheduled' ), $this->redirects );
	}

	public function test_a_failed_create_neither_links_audiences_nor_redirects(): void {
		$this->postSave( 0, 'draft' );
		$this->create_result = false;

		$this->invoke( new ReregistrationAdmin(), 'handle_save' );

		$this->assertCount( 1, $this->writesOf( 'insert', 'wp_ffc_reregistrations' ) );
		$this->assertSame( array(), $this->writesOf( 'delete', 'wp_ffc_reregistration_audiences' ) );
		$this->assertSame( array(), $this->writesOf( 'insert', 'wp_ffc_reregistration_audiences' ) );
		$this->assertSame( array(), $this->redirects );
	}

	// ==================================================================
	// AJAX delegators
	// ==================================================================

	public function test_ajax_view_submission_details_is_delegated_to_the_handler(): void {
		Functions\when( 'check_ajax_referer' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( false );
		$errors = array();
		Functions\when( 'wp_send_json_error' )->alias(
			static function ( $data ) use ( &$errors ) {
				$errors[] = $data;
				throw new \RuntimeException( 'json' );
			}
		);

		try {
			( new ReregistrationAdmin() )->ajax_view_submission_details();
		} catch ( \RuntimeException $e ) {
			unset( $e );
		}

		$this->assertCount( 1, $errors, 'The handler refuses a caller without the capability.' );
	}
}
