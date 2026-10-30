<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Reregistration\ReregistrationAdmin;
use FreeFormCertificate\Reregistration\ReregistrationAdminRenderer;

/**
 * The campaign screen's handlers run the real repository, submission reader
 * and writer, AudienceReader, Capabilities, RequestInput and DateFormatter:
 * the `$wpdb` double below answers the reads by their SQL text and bound
 * values, from the arrays a test sets, and records every write in order.
 *
 * @covers \FreeFormCertificate\Reregistration\ReregistrationAdmin
 * @covers \FreeFormCertificate\Reregistration\ReregistrationAdminRenderer
 */
class ReregistrationAdminTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var array<int, array{type: string, data: mixed}> */
	private array $json_responses = array();

	/** @var list<array<int, mixed>> Every prepare() call, as its flattened argument list. */
	private array $prepared = array();

	/** @var list<array<int, mixed>> Every write the double received, in order: [ op, table or SQL, data, where ]. */
	private array $writes = array();

	/** @var array<int, object> Campaign rows by id, answered by ReregistrationRepository::get_by_id(). */
	private array $campaigns = array();

	/** @var array<int, list<int>> Members by audience id, answered by AudienceReader::get_members(). */
	private array $members = array();

	/** @var list<object> Rows the submission-statistics query answers. */
	private array $stats = array();

	/** @var mixed The `$wpdb` global an earlier test left, restored afterwards. */
	private $wpdb_backup;

	/** @var bool Whether `$wpdb` was set before this test. */
	private bool $had_wpdb = false;

	/** @var array<string, mixed> `$_GET` as an earlier test left it. */
	private array $get_backup = array();

	/** @var array<string, mixed> `$_POST` as an earlier test left it. */
	private array $post_backup = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->get_backup  = $_GET ?? array();
		$this->post_backup = $_POST ?? array();
		$this->had_wpdb    = array_key_exists( 'wpdb', $GLOBALS );
		$this->wpdb_backup = $this->had_wpdb ? $GLOBALS['wpdb'] : null;
		$this->install_wpdb();

		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		Functions\when( 'wp_parse_args' )->alias( static fn( $args, $defaults ) => array_merge( $defaults, $args ) );
		Functions\when( 'sanitize_sql_orderby' )->returnArg();
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'current_time' )->justReturn( '2026-03-01 10:00:00' );
		Functions\when( 'wp_timezone' )->alias( static fn() => new \DateTimeZone( 'UTC' ) );
		Functions\when( 'wp_date' )->alias( static fn( $format, $ts ) => gmdate( $format, $ts ) );
		// Shared scripts are enqueued through AssetRegistry, which registers
		// them first; false is WordPress's answer for a handle already
		// registered, so nothing is localized twice (#1931).
		Functions\when( 'wp_register_script' )->justReturn( false );

		// Preload the extracted view collaborator so pcov attributes the
		// coverage this test drives through render_page delegation — pcov does
		// not record lines for files first autoloaded mid-test-method (#589 E1).
		class_exists( '\\FreeFormCertificate\\Reregistration\\ReregistrationAdminRenderer' );

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'esc_html_e' )->alias( function ( $text ) { echo $text; } );
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'add_menu_page' )->justReturn( 'hook' );
		Functions\when( 'add_submenu_page' )->justReturn( 'hook' );
		Functions\when( 'admin_url' )->alias( function ( $path = '' ) {
			return 'https://example.com/wp-admin/' . $path;
		} );
		Functions\when( 'absint' )->alias( function ( $v ) { return abs( (int) $v ); } );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'wp_create_nonce' )->justReturn( 'test_nonce' );
		Functions\when( 'wp_enqueue_style' )->justReturn( true );
		Functions\when( 'wp_enqueue_script' )->justReturn( true );
		Functions\when( 'wp_localize_script' )->justReturn( true );
		Functions\when( 'get_option' )->justReturn( 'Y-m-d' );

		$this->json_responses = array();
		$responses = &$this->json_responses;

		Functions\when( 'wp_send_json_success' )->alias( function ( $data = null ) use ( &$responses ) {
			$responses[] = array( 'type' => 'success', 'data' => $data );
			throw new \RuntimeException( 'wp_send_json_success' );
		} );
		Functions\when( 'wp_send_json_error' )->alias( function ( $data = null ) use ( &$responses ) {
			$responses[] = array( 'type' => 'error', 'data' => $data );
			throw new \RuntimeException( 'wp_send_json_error' );
		} );
	}

	protected function tearDown(): void {
		$_GET  = $this->get_backup;
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
	 * A `$wpdb` double answering the queries the campaign handlers reach. An
	 * unexpected read fails the test; a method it does not know throws.
	 */
	private function install_wpdb(): void {
		$wpdb            = Mockery::mock( 'wpdb' );
		$wpdb->prefix    = 'wp_';
		$wpdb->insert_id = 0;

		$this->prepared = array();
		$this->writes   = array();
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			function ( ...$args ) {
				$flat             = 2 === count( $args ) && is_array( $args[1] ) ? array_merge( array( $args[0] ), $args[1] ) : $args;
				$this->prepared[] = $flat;
				return 'Q' . ( count( $this->prepared ) - 1 ) . ':' . $flat[0];
			}
		);
		$wpdb->shouldReceive( 'get_row' )->andReturnUsing(
			function ( $sql ) {
				$bound = $this->bound_to( (string) $sql );
				if ( str_contains( (string) $sql, 'SELECT * FROM %i WHERE id = %d' ) && 'wp_ffc_reregistrations' === $bound[1] ) {
					return $this->campaigns[ (int) $bound[2] ] ?? null;
				}
				$this->fail( 'Unexpected get_row(): ' . $sql );
			}
		);
		$wpdb->shouldReceive( 'get_results' )->andReturnUsing(
			function ( $sql ) {
				$bound = $this->bound_to( (string) $sql );
				if ( str_contains( (string) $sql, 'parent_id = %d' ) ) {
					// AudienceReader::get_children(): no audience here has children.
					return array();
				}
				if ( str_contains( (string) $sql, 'SELECT status, COUNT(*)' ) && 'wp_ffc_reregistration_submissions' === $bound[1] ) {
					return $this->stats;
				}
				if ( str_contains( (string) $sql, 'JOIN %i a ON ra.audience_id = a.id' ) ) {
					// ReregistrationRepository::get_audiences(): no audience linked.
					return array();
				}
				$this->fail( 'Unexpected get_results(): ' . $sql );
			}
		);
		$wpdb->shouldReceive( 'get_col' )->andReturnUsing(
			function ( $sql ) {
				$bound = $this->bound_to( (string) $sql );
				if ( str_contains( (string) $sql, 'SELECT DISTINCT user_id FROM %i WHERE audience_id IN' ) && 'wp_ffc_audience_members' === $bound[1] ) {
					$users = array();
					foreach ( array_slice( $bound, 2 ) as $audience_id ) {
						$users = array_merge( $users, $this->members[ (int) $audience_id ] ?? array() );
					}
					return array_map( 'strval', $users );
				}
				$this->fail( 'Unexpected get_col(): ' . $sql );
			}
		);
		$wpdb->shouldReceive( 'insert' )->andReturnUsing(
			function ( $table, $data ) use ( $wpdb ) {
				$this->writes[] = array( 'insert', $table, $data, null );
				if ( 'wp_ffc_reregistrations' === $table ) {
					$wpdb->insert_id = 55;
				}
				return 1;
			}
		);
		$wpdb->shouldReceive( 'update' )->andReturnUsing(
			function ( $table, $data, $where ) {
				$this->writes[] = array( 'update', $table, $data, $where );
				return 1;
			}
		);
		$wpdb->shouldReceive( 'delete' )->andReturnUsing(
			function ( $table, $where ) {
				$this->writes[] = array( 'delete', $table, null, $where );
				return 1;
			}
		);
		$wpdb->shouldReceive( 'query' )->andReturnUsing(
			function ( $sql ) {
				$this->writes[] = array( 'query', (string) $sql, $this->bound_to( (string) $sql ), null );
				return 1;
			}
		);
		$GLOBALS['wpdb'] = $wpdb;
	}

	/**
	 * The argument list prepare() received for a prepared statement.
	 *
	 * @param string $sql What prepare() returned.
	 * @return array<int, mixed>
	 */
	private function bound_to( string $sql ): array {
		if ( 1 !== preg_match( '/^Q(\d+):/', $sql, $m ) ) {
			$this->fail( 'Not a prepared statement: ' . $sql );
		}
		return $this->prepared[ (int) $m[1] ];
	}

	/**
	 * The writes the double received on one table, in order.
	 *
	 * @param string $table Table name.
	 * @return list<array<int, mixed>>
	 */
	private function writes_to( string $table ): array {
		return array_values( array_filter( $this->writes, static fn( array $w ): bool => $table === $w[1] ) );
	}

	/**
	 * The runs of a raw query whose SQL contains a fragment, as their bound arguments.
	 *
	 * @param string $fragment SQL fragment.
	 * @return list<array<int, mixed>>
	 */
	private function queries_with( string $fragment ): array {
		$found = array();
		foreach ( $this->writes as $w ) {
			if ( 'query' === $w[0] && str_contains( $w[1], $fragment ) ) {
				$found[] = $w[2];
			}
		}
		return $found;
	}

	// ==================================================================
	// Constants
	// ==================================================================

	public function test_menu_slug_constant(): void {
		$this->assertSame( 'ffc-reregistration', ReregistrationAdmin::MENU_SLUG );
	}

	// ==================================================================
	// init()
	// ==================================================================

	public function test_init_registers_hooks(): void {
		$admin = new ReregistrationAdmin();
		$admin->init();
		$this->assertInstanceOf( ReregistrationAdmin::class, $admin );
	}

	// ==================================================================
	// add_menu()
	// ==================================================================

	public function test_add_menu_registers_menu_pages(): void {
		// #1030: this asserted nothing and called that "the menus were
		// registered" — it passed for an add_menu() that registered none.
		Functions\when( 'current_user_can' )->justReturn( true );
		$registered = array();
		Functions\when( 'add_menu_page' )->alias(
			function () use ( &$registered ) {
				$registered[] = func_get_arg( 3 ); // menu slug
				return 'toplevel_page_' . func_get_arg( 3 );
			}
		);
		Functions\when( 'add_submenu_page' )->alias(
			function () use ( &$registered ) {
				$registered[] = func_get_arg( 4 ); // submenu slug
				return 'admin_page_' . func_get_arg( 4 );
			}
		);

		$admin = new ReregistrationAdmin();
		$admin->add_menu();

		$this->assertNotEmpty( $registered, 'add_menu() registered no menu at all' );
		$this->assertContains( ReregistrationAdmin::MENU_SLUG, $registered );
	}

	// ==================================================================
	// enqueue_assets() — wrong page
	// ==================================================================

	public function test_enqueue_assets_returns_early_on_wrong_hook(): void {
		$enqueued = array();
		Functions\when( 'wp_enqueue_style' )->alias( function () use ( &$enqueued ) {
			$enqueued[] = func_get_arg( 0 );
		} );

		$admin = new ReregistrationAdmin();
		$admin->enqueue_assets( 'edit.php' );

		$this->assertEmpty( $enqueued );
	}

	// ==================================================================
	// enqueue_assets() — correct page
	// ==================================================================

	public function test_enqueue_assets_enqueues_on_correct_hook(): void {
		$enqueued_styles = array();
		$enqueued_scripts = array();
		Functions\when( 'wp_enqueue_style' )->alias( function () use ( &$enqueued_styles ) {
			$enqueued_styles[] = func_get_arg( 0 );
		} );
		Functions\when( 'wp_enqueue_script' )->alias( function () use ( &$enqueued_scripts ) {
			$enqueued_scripts[] = func_get_arg( 0 );
		} );

		$admin = new ReregistrationAdmin();
		$admin->enqueue_assets( 'toplevel_page_ffc-reregistration' );

		$this->assertContains( 'ffc-reregistration-admin', $enqueued_styles );
		$this->assertContains( 'ffc-reregistration-admin', $enqueued_scripts );
	}

	// ==================================================================
	// enqueue_assets() — submissions view enqueues PDF libs
	// ==================================================================

	public function test_enqueue_assets_loads_pdf_libs_on_submissions_view(): void {
		$_GET['view'] = 'submissions';

		$enqueued_scripts = array();
		Functions\when( 'wp_enqueue_script' )->alias( function () use ( &$enqueued_scripts ) {
			$enqueued_scripts[] = func_get_arg( 0 );
		} );

		$admin = new ReregistrationAdmin();
		$admin->enqueue_assets( 'toplevel_page_ffc-reregistration' );

		$this->assertContains( 'html2canvas', $enqueued_scripts );
		$this->assertContains( 'jspdf', $enqueued_scripts );
		$this->assertContains( 'ffc-pdf-generator', $enqueued_scripts );
	}

	// ==================================================================
	// render_page() — permission denied
	// ==================================================================

	public function test_render_page_dies_without_capability(): void {
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( 'wp_die' )->alias( function ( $msg ) {
			throw new \RuntimeException( $msg );
		} );

		$admin = new ReregistrationAdmin();
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Permission denied.' );
		$admin->render_page();
	}

	// ==================================================================
	// handle_actions() — returns early without capability
	// ==================================================================

	public function test_handle_actions_returns_early_without_capability(): void {
		// #1030: asserting "no error" proved nothing — the guard could be
		// deleted and this still passed. Put the request in a state where the
		// method demonstrably WOULD act (right page, a message to render), so
		// that nothing happening is evidence the capability check held.
		Functions\when( 'current_user_can' )->justReturn( false );
		$_GET['page']    = ReregistrationAdmin::MENU_SLUG;
		$_GET['message'] = 'created';

		Functions\expect( 'add_settings_error' )->never();

		$admin = new ReregistrationAdmin();
		$admin->handle_actions();
	}

	// ==================================================================
	// handle_actions() — returns early on wrong page
	// ==================================================================

	public function test_handle_actions_returns_early_on_wrong_page(): void {
		// The capability passes here, so only the page check can stop it — and
		// a renderable message is queued so that acting would be visible.
		Functions\when( 'current_user_can' )->justReturn( true );
		$_GET['page']    = 'other-page';
		$_GET['message'] = 'created';

		Functions\expect( 'add_settings_error' )->never();

		$admin = new ReregistrationAdmin();
		$admin->handle_actions();
	}

	// ==================================================================
	// handle_actions() — shows message from query string
	// ==================================================================

	public function test_handle_actions_shows_message_from_query_string(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		$_GET['page'] = 'ffc-reregistration';
		$_GET['message'] = 'created';

		$settings_errors = array();
		Functions\when( 'add_settings_error' )->alias( function ( $setting, $code, $message, $type ) use ( &$settings_errors ) {
			$settings_errors[] = array( 'setting' => $setting, 'type' => $type, 'message' => $message );
		} );

		$admin = new ReregistrationAdmin();
		$admin->handle_actions();

		$this->assertCount( 1, $settings_errors );
		$this->assertSame( 'success', $settings_errors[0]['type'] );
	}

	// ==================================================================
	// ajax_generate_record() — permission denied
	// ==================================================================

	public function test_ajax_generate_record_returns_error_without_capability(): void {
		Functions\when( 'check_ajax_referer' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( false );

		$admin = new ReregistrationAdmin();
		try {
			$admin->ajax_generate_record();
		} catch ( \RuntimeException $e ) {
			// Expected
		}

		$this->assertSame( 'error', $this->json_responses[0]['type'] );
		$this->assertStringContainsString( 'Permission denied', $this->json_responses[0]['data']['message'] );
	}

	// ==================================================================
	// ajax_generate_record() — missing submission ID
	// ==================================================================

	public function test_ajax_generate_record_returns_error_for_missing_id(): void {
		Functions\when( 'check_ajax_referer' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( true );
		$_POST['submission_id'] = 0;

		$admin = new ReregistrationAdmin();
		try {
			$admin->ajax_generate_record();
		} catch ( \RuntimeException $e ) {
			// Expected
		}

		$this->assertSame( 'error', $this->json_responses[0]['type'] );
		$this->assertStringContainsString( 'Invalid submission', $this->json_responses[0]['data']['message'] );
	}

	// ==================================================================
	// ajax_count_members() — permission denied
	// ==================================================================

	public function test_ajax_count_members_returns_error_without_capability(): void {
		Functions\when( 'check_ajax_referer' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( false );

		$admin = new ReregistrationAdmin();
		try {
			$admin->ajax_count_members();
		} catch ( \RuntimeException $e ) {
			// Expected
		}

		$this->assertSame( 'error', $this->json_responses[0]['type'] );
	}

	// ==================================================================
	// ajax_count_members() — empty audience IDs
	// ==================================================================

	public function test_ajax_count_members_returns_zero_for_empty_audiences(): void {
		Functions\when( 'check_ajax_referer' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( true );
		$_POST['audience_ids'] = array();

		$admin = new ReregistrationAdmin();
		try {
			$admin->ajax_count_members();
		} catch ( \RuntimeException $e ) {
			// Expected
		}

		$this->assertSame( 'success', $this->json_responses[0]['type'] );
		$this->assertSame( 0, $this->json_responses[0]['data']['count'] );
	}

	// ==================================================================
	// handle_save() / handle_delete()
	// ==================================================================

	private function invoke_private( ReregistrationAdmin $admin, string $method, array $args = array() ) {
		$ref = new \ReflectionMethod( ReregistrationAdmin::class, $method );
		$ref->setAccessible( true );
		return $ref->invokeArgs( $admin, $args );
	}

	/**
	 * Run handle_save() with one `rereg_reminder_days` value and return the
	 * `reminder_days` the repository was asked to store.
	 *
	 * @param string $posted Raw field value, as the browser would send it.
	 * @return int
	 */
	private function saved_reminder_days( string $posted ): int {
		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		Functions\when( 'wp_safe_redirect' )->alias( fn() => throw new \RuntimeException( 'redirected' ) );

		$_POST['ffc_action']          = 'save_reregistration';
		$_POST['reregistration_id']   = 0;
		$_POST['rereg_title']         = 'C';
		$_POST['rereg_start_date']    = '2026-01-01';
		$_POST['rereg_end_date']      = '2026-12-31';
		$_POST['rereg_status']        = 'draft';
		$_POST['rereg_reminder_days'] = $posted;

		$admin = new ReregistrationAdmin();
		try {
			$this->invoke_private( $admin, 'handle_save' );
		} catch ( \RuntimeException $e ) {
			// The redirect is how handle_save() ends; the write already happened.
			unset( $e );
		}

		$created = $this->writes_to( 'wp_ffc_reregistrations' );
		$this->assertCount( 1, $created, 'exactly one campaign row is written' );
		$this->assertSame( 'insert', $created[0][0] );

		return $created[0][2]['reminder_days'];
	}

	/**
	 * Reactivating a campaign reopens the previous cycle (#1125).
	 *
	 * The wiring is the whole fix, and it had no test: the smoke found that a
	 * reactivated campaign was silent on both ends, because
	 * `create_for_audience_members()` skips a user who already has a
	 * submission — so everyone from the previous cycle stayed at `expired`.
	 *
	 * The ORDER is what is pinned here, not just the call: reopen, then seed.
	 * Since #1762 nothing is mailed here -- the daily run sends the
	 * invitations -- and the redirect asks the screen to say so.
	 *
	 * The campaign links audience 3, whose one member is user 21, so the seed
	 * reaches the database and its place in the order can be read there.
	 */
	public function test_handle_save_reopens_expired_submissions_when_reactivating(): void {
		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		$redirect = '';
		Functions\when( 'wp_safe_redirect' )->alias(
			function ( $url ) use ( &$redirect ) {
				$redirect = $url;
				throw new \RuntimeException( 'redirected' );
			}
		);

		$_POST['ffc_action']             = 'save_reregistration';
		$_POST['reregistration_id']      = 9;
		$_POST['rereg_email_invitation'] = '1';
		$_POST['rereg_title']            = 'C';
		$_POST['rereg_start_date']       = '2026-01-01';
		$_POST['rereg_end_date']         = '2026-12-31';
		// The transition under test: the campaign was expired and the
		// administrator is switching it back on.
		$_POST['rereg_status']       = 'active';
		$_POST['rereg_audience_ids'] = array( '3' );

		$this->campaigns[9] = (object) array(
			'id'       => 9,
			'status'   => 'expired',
			'end_date' => '2026-12-31',
		);
		$this->members[3]   = array( 21 );
		Functions\expect( 'wp_mail' )->never();

		$admin = new ReregistrationAdmin();
		try {
			$this->invoke_private( $admin, 'handle_save' );
		} catch ( \RuntimeException $e ) {
			unset( $e );
		}

		$calls = array();
		foreach ( $this->writes as $w ) {
			if ( 'query' !== $w[0] ) {
				continue;
			}
			if ( str_contains( $w[1], "SET status = 'pending'" ) && "WHERE reregistration_id = %d AND status = 'expired'" === trim( substr( $w[2][0], (int) strpos( $w[2][0], 'WHERE' ) ) ) ) {
				$this->assertSame( array( 'wp_ffc_reregistration_submissions', '2026-03-01 10:00:00', 9 ), array_slice( $w[2], 1 ), 'the campaign\'s expired submissions are reopened' );
				$calls[] = 'reopen';
			} elseif ( str_contains( $w[1], 'INSERT IGNORE INTO %i' ) ) {
				$this->assertSame( array( 'wp_ffc_reregistration_submissions', 9, 21, 'pending' ), array_slice( $w[2], 1 ), 'the member is seeded' );
				$calls[] = 'seed';
			}
		}

		$this->assertSame( array( 'reopen', 'seed' ), $calls, 'Reopening must precede the seeding.' );
		$this->assertStringContainsString( '&message=updated&invitations=scheduled', $redirect );
	}

	/**
	 * Saving a campaign that is already active seeds its audiences (#2242):
	 * an audience added to it gets its rows now, without reopening anything
	 * and without the invitations notice of an activation.
	 */
	public function test_handle_save_seeds_an_already_active_campaign(): void {
		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		$redirect = '';
		Functions\when( 'wp_safe_redirect' )->alias(
			function ( $url ) use ( &$redirect ) {
				$redirect = $url;
				throw new \RuntimeException( 'redirected' );
			}
		);

		$_POST['ffc_action']             = 'save_reregistration';
		$_POST['reregistration_id']      = 9;
		$_POST['rereg_email_invitation'] = '1';
		$_POST['rereg_title']            = 'C';
		$_POST['rereg_start_date']       = '2026-01-01';
		$_POST['rereg_end_date']         = '2026-12-31';
		$_POST['rereg_status']           = 'active';
		$_POST['rereg_audience_ids']     = array( '3' );

		$this->campaigns[9] = (object) array(
			'id'       => 9,
			'status'   => 'active',
			'end_date' => '2026-12-31',
		);
		$this->members[3]   = array( 21 );

		$admin = new ReregistrationAdmin();
		try {
			$this->invoke_private( $admin, 'handle_save' );
		} catch ( \RuntimeException $e ) {
			unset( $e );
		}

		$calls = array();
		foreach ( $this->writes as $w ) {
			if ( 'query' !== $w[0] ) {
				continue;
			}
			if ( str_contains( $w[1], "SET status = 'pending'" ) ) {
				$calls[] = 'reopen';
			} elseif ( str_contains( $w[1], 'INSERT IGNORE INTO %i' ) ) {
				$this->assertSame( array( 'wp_ffc_reregistration_submissions', 9, 21, 'pending' ), array_slice( $w[2], 1 ) );
				$calls[] = 'seed';
			}
		}

		$this->assertSame( array( 'seed' ), $calls, 'Seeded, and nothing reopened: it was not a reactivation.' );
		$this->assertStringNotContainsString( 'invitations=scheduled', $redirect );
	}

	/**
	 * A cleared field must not write zero (#1117).
	 *
	 * `absint( '' )` is 0, and the reminder sweep reads
	 * `DATEDIFF(end_date, CURDATE()) <= reminder_days`, so a 0 collapses
	 * every reminder onto the campaign's last day — too late to be a
	 * reminder, and nothing on screen says the value changed.
	 */
	public function test_handle_save_keeps_the_default_when_reminder_days_is_cleared(): void {
		$this->assertSame( 7, $this->saved_reminder_days( '' ) );
	}

	/**
	 * A typed 0 lands on the floor the field itself declares (`min="1"`).
	 */
	public function test_handle_save_floors_a_typed_zero_on_the_fields_own_min(): void {
		$this->assertSame( 1, $this->saved_reminder_days( '0' ) );
	}

	/**
	 * A real value still round-trips.
	 */
	public function test_handle_save_stores_a_supplied_reminder_days(): void {
		$this->assertSame( 14, $this->saved_reminder_days( '14' ) );
	}

	public function test_handle_save_creates_active_campaign_and_redirects(): void {
		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		$redirect = '';
		Functions\when( 'wp_safe_redirect' )->alias(
			function ( $url ) use ( &$redirect ) {
				$redirect = $url;
				throw new \RuntimeException( 'redirected' );
			}
		);

		$_POST['ffc_action']         = 'save_reregistration';
		$_POST['reregistration_id']  = 0;
		$_POST['rereg_title']        = 'My Campaign';
		$_POST['rereg_start_date']   = '2026-01-01';
		$_POST['rereg_end_date']     = '2026-12-31';
		$_POST['rereg_status']       = 'active';
		$_POST['rereg_audience_ids'] = array( '3', '4' );
		$this->members[3]            = array( 21 );
		$this->members[4]            = array( 22 );

		// Invitations off: the campaign is created and seeded, nothing is
		// mailed (#1762), and there is nothing to announce.
		Functions\expect( 'wp_mail' )->never();

		$admin = new ReregistrationAdmin();
		try {
			$this->invoke_private( $admin, 'handle_save' );
		} catch ( \RuntimeException $e ) {
			unset( $e );
		}

		$created = $this->writes_to( 'wp_ffc_reregistrations' );
		$this->assertCount( 1, $created, 'one campaign is created' );
		$this->assertSame( array( 'insert', 'My Campaign', 'active' ), array( $created[0][0], $created[0][2]['title'], $created[0][2]['status'] ) );
		$this->assertSame(
			array(
				array( 'delete', 'wp_ffc_reregistration_audiences', null, array( 'reregistration_id' => 55 ) ),
				array( 'insert', 'wp_ffc_reregistration_audiences', array( 'reregistration_id' => 55, 'audience_id' => 3 ), null ),
				array( 'insert', 'wp_ffc_reregistration_audiences', array( 'reregistration_id' => 55, 'audience_id' => 4 ), null ),
			),
			$this->writes_to( 'wp_ffc_reregistration_audiences' ),
			'the new campaign is linked to audiences 3 and 4'
		);
		$seeded = $this->queries_with( 'INSERT IGNORE INTO %i' );
		$this->assertCount( 1, $seeded, 'the members of audiences 3 and 4 are seeded once' );
		$this->assertSame( array( 'wp_ffc_reregistration_submissions', 55, 21, 'pending', 55, 22, 'pending' ), array_slice( $seeded[0], 1 ) );
		$this->assertStringEndsWith( '&id=55&message=created', $redirect );
	}

	public function test_handle_save_updates_existing_draft_campaign(): void {
		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		Functions\when( 'wp_safe_redirect' )->alias( fn() => throw new \RuntimeException( 'redirected' ) );

		$_POST['ffc_action']        = 'save_reregistration';
		$_POST['reregistration_id'] = 9;
		$_POST['rereg_title']       = 'Updated';
		$_POST['rereg_start_date']  = '2026-01-01';
		$_POST['rereg_end_date']    = '2026-12-31';
		$_POST['rereg_status']      = 'draft';

		// Existing campaign already draft → no membership/invitation side effects.
		$this->campaigns[9] = (object) array(
			'id'       => 9,
			'status'   => 'draft',
			'end_date' => '2026-12-31',
		);

		$admin = new ReregistrationAdmin();
		try {
			$this->invoke_private( $admin, 'handle_save' );
			$this->fail( 'handle_save() ends in a redirect' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'redirected', $e->getMessage() );
		}

		$updated = $this->writes_to( 'wp_ffc_reregistrations' );
		$this->assertCount( 1, $updated, 'the campaign is updated once' );
		$this->assertSame( array( 'update', 'Updated', 'draft', array( 'id' => 9 ) ), array( $updated[0][0], $updated[0][2]['title'], $updated[0][2]['status'], $updated[0][3] ) );
		$this->assertSame(
			array( array( 'delete', 'wp_ffc_reregistration_audiences', null, array( 'reregistration_id' => 9 ) ) ),
			$this->writes_to( 'wp_ffc_reregistration_audiences' ),
			'its audience links are replaced by the empty posted set'
		);
		$this->assertSame( array(), $this->queries_with( '' ), 'no submission is reopened or seeded' );
	}

	/**
	 * A campaign whose end is not after its start is a window nobody can
	 * answer in (#2242): it is refused before any write, and says so.
	 *
	 * @dataProvider inverted_window_provider
	 */
	public function test_handle_save_refuses_an_end_that_is_not_after_the_start( string $start, string $end ): void {
		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		Functions\when( 'wp_safe_redirect' )->alias( fn() => throw new \RuntimeException( 'redirected' ) );
		$errors = array();
		Functions\when( 'add_settings_error' )->alias(
			function ( $setting, $code, $message, $type ) use ( &$errors ) {
				$errors[] = array( $code, $type );
			}
		);

		$_POST['ffc_action']        = 'save_reregistration';
		$_POST['reregistration_id'] = 0;
		$_POST['rereg_title']       = 'C';
		$_POST['rereg_start_date']  = $start;
		$_POST['rereg_end_date']    = $end;
		$_POST['rereg_status']      = 'active';

		$this->invoke_private( new ReregistrationAdmin(), 'handle_save' );

		$this->assertSame( array( array( 'ffc_window', 'error' ) ), $errors );
		$this->assertSame( array(), $this->writes_to( 'wp_ffc_reregistrations' ), 'Nothing is saved.' );
	}

	/** @return array<string, array{string, string}> */
	public static function inverted_window_provider(): array {
		return array(
			'end before start' => array( '2026-12-01T08:00', '2026-11-30T18:00' ),
			'end equals start' => array( '2026-12-01T08:00', '2026-12-01T08:00' ),
			'missing end'      => array( '2026-12-01T08:00', '' ),
			'unreadable start' => array( 'tomorrow', '2026-12-31T18:00' ),
		);
	}

	public function test_handle_save_returns_early_without_action(): void {
		unset( $_POST['ffc_action'] );

		// The statement immediately after the guard is the nonce check, so a
		// nonce that is never verified is the proof that the guard returned.
		Functions\expect( 'wp_verify_nonce' )->never();

		$admin = new ReregistrationAdmin();
		$this->invoke_private( $admin, 'handle_save' );
	}

	public function test_handle_save_returns_early_on_bad_nonce(): void {
		$_POST['ffc_action']        = 'save_reregistration';
		$_POST['reregistration_id'] = 9;
		$_POST['rereg_title']       = 'C';
		$verified                   = array();
		Functions\when( 'wp_verify_nonce' )->alias(
			static function ( $nonce, $action ) use ( &$verified ) {
				$verified[] = array( $nonce, $action );
				return false;
			}
		);

		$admin = new ReregistrationAdmin();
		$this->invoke_private( $admin, 'handle_save' );

		$this->assertSame( array( array( '', 'save_reregistration' ) ), $verified, 'the missing nonce is checked against the save action' );
		$this->assertSame( array(), $this->prepared, 'nothing is read' );
		$this->assertSame( array(), $this->writes, 'nothing is written' );
	}

	public function test_handle_delete_returns_early_without_action(): void {
		unset( $_GET['action'] );

		// Past this guard the method reaches the destructive-capability check
		// and wp_die()s without it, then verifies a nonce. Neither may happen.
		Functions\expect( 'wp_die' )->never();
		Functions\expect( 'wp_verify_nonce' )->never();

		$admin = new ReregistrationAdmin();
		$this->invoke_private( $admin, 'handle_delete' );
	}

	public function test_handle_delete_dies_without_delete_cap(): void {
		$_GET['action'] = 'delete';
		$_GET['id']     = '5';
		Functions\when( 'wp_die' )->alias( fn( $msg ) => throw new \RuntimeException( $msg ) );
		$asked = array();
		Functions\when( 'current_user_can' )->alias(
			static function ( $cap ) use ( &$asked ) {
				$asked[] = $cap;
				return false;
			}
		);

		$admin = new ReregistrationAdmin();
		try {
			$this->invoke_private( $admin, 'handle_delete' );
			$this->fail( 'handle_delete() dies without the delete capability' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'permission to delete', $e->getMessage() );
		}

		$this->assertSame( array( 'manage_options', 'ffc_delete_reregistration' ), $asked, 'the destructive capability is the one checked' );
		$this->assertSame( array(), $this->writes, 'nothing is deleted' );
	}

	public function test_handle_delete_deletes_and_redirects(): void {
		$_GET['action']   = 'delete';
		$_GET['id']       = '5';
		$_GET['_wpnonce'] = 'good';
		Functions\when( 'current_user_can' )->justReturn( true );
		$verified = array();
		Functions\when( 'wp_verify_nonce' )->alias(
			static function ( $nonce, $action ) use ( &$verified ) {
				$verified[] = array( $nonce, $action );
				return true;
			}
		);
		Functions\when( 'wp_safe_redirect' )->alias( fn() => throw new \RuntimeException( 'redirected' ) );

		$admin = new ReregistrationAdmin();
		try {
			$this->invoke_private( $admin, 'handle_delete' );
			$this->fail( 'handle_delete() ends in a redirect' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'redirected', $e->getMessage() );
		}

		$this->assertSame( array( array( 'good', 'delete_reregistration_5' ) ), $verified );
		$this->assertSame(
			array(
				array( 'delete', 'wp_ffc_reregistration_submissions', null, array( 'reregistration_id' => 5 ) ),
				array( 'delete', 'wp_ffc_reregistration_audiences', null, array( 'reregistration_id' => 5 ) ),
				array( 'delete', 'wp_ffc_reregistrations', null, array( 'id' => 5 ) ),
			),
			$this->writes,
			'campaign 5 is deleted with its submissions and audience links'
		);
	}

	public function test_handle_delete_returns_early_on_bad_nonce(): void {
		$_GET['action']   = 'delete';
		$_GET['id']       = '5';
		$_GET['_wpnonce'] = 'bad';
		Functions\when( 'current_user_can' )->justReturn( true );
		$verified = array();
		Functions\when( 'wp_verify_nonce' )->alias(
			static function ( $nonce, $action ) use ( &$verified ) {
				$verified[] = array( $nonce, $action );
				return false;
			}
		);

		$admin = new ReregistrationAdmin();
		$this->invoke_private( $admin, 'handle_delete' );

		$this->assertSame( array( array( 'bad', 'delete_reregistration_5' ) ), $verified );
		$this->assertSame( array(), $this->writes, 'nothing is deleted' );
	}

	// ==================================================================
	// ReregistrationAdminRenderer — view layer (extracted #589 E1)
	// ==================================================================

	public function test_renderer_audience_options_emits_hierarchical_options(): void {
		Functions\when( 'selected' )->justReturn( '' );

		$audiences = array(
			(object) array(
				'id'       => 1,
				'name'     => 'Parent A',
				'children' => array(
					(object) array( 'id' => 2, 'name' => 'Child A1' ),
				),
			),
			(object) array( 'id' => 3, 'name' => 'Parent B', 'children' => array() ),
		);

		ob_start();
		ReregistrationAdminRenderer::render_audience_options( $audiences, 2 );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Parent A', $html );
		$this->assertStringContainsString( '&mdash; Child A1', $html );
		$this->assertStringContainsString( 'Parent B', $html );
	}

	public function test_renderer_list_row_renders_campaign_row(): void {
		Functions\when( 'wp_nonce_url' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		// The settings option holds no date format, so the default applies.
		Functions\when( 'get_option' )->justReturn( array() );

		$this->stats = array(
			(object) array( 'status' => 'approved', 'count' => '2' ),
			(object) array( 'status' => 'pending', 'count' => '3' ),
		);

		$item = (object) array(
			'id'           => 7,
			'title'        => 'My Campaign',
			'status'       => 'active',
			'start_date'   => '2026-01-01 00:00:00',
			'end_date'     => '2026-12-31 00:00:00',
			'auto_approve' => 1,
		);

		ob_start();
		ReregistrationAdminRenderer::render_list_row( 'ffc-reregistration', $item, true );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'My Campaign', $html );
		$this->assertStringContainsString( 'column-actions', $html );
		$this->assertStringContainsString( 'delete-link', $html );
		$this->assertStringContainsString( 'Active', $html );
		$this->assertStringContainsString( '2 / 5', $html, 'approved over total, from the statistics query' );
	}
}
