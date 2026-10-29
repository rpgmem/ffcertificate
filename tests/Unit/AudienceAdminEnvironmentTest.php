<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Audience\AudienceAdminEnvironment;

/**
 * The Environments screen of Audience Calendars: its list, its form and the
 * actions it handles.
 *
 * AudienceEnvironmentRepository and AudienceScheduleRepository are the real
 * classes, over a `$wpdb` double that answers each read by its SQL text from
 * the environment and calendar rows a test stores, records every write, and
 * fails the test on any query it does not know. Every redirect ends in
 * `exit`; the `wp_safe_redirect()` stub throws to stand in for it.
 *
 * @covers \FreeFormCertificate\Audience\AudienceAdminEnvironment
 */
class AudienceAdminEnvironmentTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private const ENV_TABLE = 'wp_ffc_audience_environments';

	private const SCHEDULE_TABLE = 'wp_ffc_audience_schedules';

	/** @var array<int, array<string, mixed>> Stored environments, by id, in insertion order. */
	private array $environments = array();

	/** @var array<int, array<string, mixed>> Stored calendars, by id, in insertion order. */
	private array $schedules = array();

	/**
	 * Every SELECT the double answered, as `[ sql, args ]`.
	 *
	 * @var list<array{0: string, 1: array<int, mixed>}>
	 */
	private array $reads = array();

	/**
	 * Every write the double received, as `[ op, table, data, where ]`.
	 *
	 * @var list<array{0: string, 1: string, 2: array<string, mixed>, 3: array<string, mixed>}>
	 */
	private array $writes = array();

	/** @var mixed The `$wpdb` global an earlier test left, restored afterwards. */
	private $wpdb_backup;

	/** @var bool Whether `$wpdb` was set before this test. */
	private bool $had_wpdb = false;

	/** @var array<string, mixed> The `$_GET` an earlier test left, restored afterwards. */
	private array $get_backup = array();

	/** @var array<int, int> Bookings per environment id. */
	private array $bookings = array();

	/** @var array<string, mixed> The `$_POST` an earlier test left, restored afterwards. */
	private array $post_backup = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->get_backup  = $_GET ?? array();
		$this->post_backup = $_POST ?? array();
		$_GET              = array();
		$_POST             = array();
		// handle_actions() acts only on its own screen (#1771).
		$_GET['page'] = 'ffc-scheduling-environments';

		$this->environments = array();
		$this->bookings     = array();
		$this->schedules    = array();
		$this->reads        = array();
		$this->writes       = array();

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_attr__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'esc_textarea' )->returnArg();
		Functions\when( 'esc_html_e' )->alias( function ( $text ) { echo $text; } );
		Functions\when( 'esc_attr_e' )->alias( function ( $text ) { echo $text; } );
		Functions\when( 'absint' )->alias( function ( $v ) { return abs( (int) $v ); } );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'sanitize_textarea_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'admin_url' )->justReturn( 'https://example.com/wp-admin/' );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_settings_errors' )->justReturn( array() );
		Functions\when( 'wp_nonce_url' )->justReturn( '/' );
		Functions\when( 'wp_trim_words' )->returnArg();
		Functions\when( 'selected' )->justReturn( '' );
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		Functions\when( 'wp_json_encode' )->alias( static fn( $data ) => json_encode( $data ) );
		Functions\when( 'wp_parse_args' )->alias( function ( $args, $defaults = array() ) {
			return array_merge( $defaults, (array) $args );
		} );
		Functions\when( 'sanitize_sql_orderby' )->returnArg();
		Functions\when( 'sanitize_key' )->returnArg();
		Functions\when( 'submit_button' )->justReturn( '' );
		// CacheVersion reads and bumps its counter on every repository write.
		Functions\when( 'get_option' )->alias( static fn( $key, $default = false ) => $default );
		Functions\when( 'update_option' )->justReturn( true );

		$this->had_wpdb    = array_key_exists( 'wpdb', $GLOBALS );
		$this->wpdb_backup = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb']   = $this->wpdb_double();
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
	 * A `$wpdb` that answers the two repositories' queries from the stored
	 * rows: `SELECT *` (filtered by its `col = %x` conditions), the by-id
	 * `get_row()`, and records insert / update / delete.
	 *
	 * @return Mockery\MockInterface
	 */
	private function wpdb_double() {
		$wpdb            = Mockery::mock( 'wpdb' );
		$wpdb->prefix    = 'wp_';
		$wpdb->insert_id = 0;
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			static function ( $sql, ...$args ) {
				$flat = 1 === count( $args ) && is_array( $args[0] ) ? $args[0] : $args;
				return array(
					'sql'  => $sql,
					'args' => array_values( $flat ),
				);
			}
		);
		$wpdb->shouldReceive( 'get_results' )->andReturnUsing(
			function ( $q ) {
				$this->reads[] = array( $q['sql'], $q['args'] );
				if ( 1 === preg_match( '/^SELECT \* FROM %i\s*(?:WHERE (.+?))?\s*ORDER BY /', $q['sql'], $m ) ) {
					return $this->select( $q['args'], $m[1] ?? '' );
				}
				$this->fail( 'Unexpected get_results(): ' . $q['sql'] );
			}
		);
		$wpdb->shouldReceive( 'get_row' )->andReturnUsing(
			function ( $q ) {
				$this->reads[] = array( $q['sql'], $q['args'] );
				if ( 'SELECT * FROM %i WHERE id = %d' === $q['sql'] ) {
					$rows = $this->select( $q['args'], 'id = %d' );
					return $rows[0] ?? null;
				}
				$this->fail( 'Unexpected get_row(): ' . $q['sql'] );
			}
		);
		// The booking count the delete guard asks (#2229): bookings per
		// environment id, none unless a test stores some.
		$wpdb->shouldReceive( 'get_var' )->andReturnUsing(
			function ( $q ) {
				$this->reads[] = array( $q['sql'], $q['args'] );
				if ( 'SELECT COUNT(*) FROM %i WHERE environment_id = %d' === $q['sql'] && 'wp_ffc_audience_bookings' === $q['args'][0] ) {
					return (string) ( $this->bookings[ (int) $q['args'][1] ] ?? 0 );
				}
				$this->fail( 'Unexpected get_var(): ' . $q['sql'] );
			}
		);
		$wpdb->shouldReceive( 'insert' )->andReturnUsing(
			function ( $table, $data ) use ( $wpdb ) {
				$this->writes[]  = array( 'insert', $table, $data, array() );
				$wpdb->insert_id = 42;
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
				$this->writes[] = array( 'delete', $table, array(), $where );
				return 1;
			}
		);
		return $wpdb;
	}

	/**
	 * The stored rows of the table in `$args[0]` matching every `col = %x`
	 * condition of `$where`, bound to `$args[1..]` in order.
	 *
	 * @param array<int, mixed> $args  Bound arguments, the table first.
	 * @param string            $where The WHERE conditions.
	 * @return list<object>
	 */
	private function select( array $args, string $where ): array {
		$table = array_shift( $args );
		if ( self::ENV_TABLE === $table ) {
			$rows = $this->environments;
		} elseif ( self::SCHEDULE_TABLE === $table ) {
			$rows = $this->schedules;
		} else {
			$this->fail( 'Unexpected table: ' . (string) $table );
		}
		preg_match_all( '/(\w+) = %[ds]/', $where, $columns );
		$out = array();
		foreach ( $rows as $row ) {
			foreach ( $columns[1] as $i => $column ) {
				if ( (string) ( $row[ $column ] ?? '' ) !== (string) $args[ $i ] ) {
					continue 2;
				}
			}
			$out[] = (object) $row;
		}
		return $out;
	}

	/**
	 * Store an environment row.
	 *
	 * @param array<string, mixed> $row Columns; `id` required.
	 */
	private function environment( array $row ): void {
		$this->environments[ (int) $row['id'] ] = $row + array(
			'schedule_id'   => 1,
			'name'          => 'Env',
			'description'   => '',
			'color'         => '#3788d8',
			'status'        => 'active',
			'working_hours' => '',
		);
	}

	/**
	 * Store a calendar row.
	 *
	 * @param array<string, mixed> $row Columns; `id` required.
	 */
	private function schedule( array $row ): void {
		$this->schedules[ (int) $row['id'] ] = $row + array(
			'name'              => 'Cal',
			'status'            => 'active',
			'environment_label' => null,
		);
	}

	/**
	 * The writes the double received of one kind.
	 *
	 * @param string $op `insert`, `update` or `delete`.
	 * @return list<array{0: string, 1: string, 2: array<string, mixed>, 3: array<string, mixed>}>
	 */
	private function writes_of( string $op ): array {
		return array_values( array_filter( $this->writes, static fn( $w ) => $op === $w[0] ) );
	}

	/**
	 * The SELECTs the double ran against one table.
	 *
	 * @param string $table Table name.
	 * @return list<array{0: string, 1: array<int, mixed>}>
	 */
	private function reads_of( string $table ): array {
		return array_values( array_filter( $this->reads, static fn( $r ) => $table === ( $r[1][0] ?? null ) ) );
	}

	/**
	 * Run the handler until its redirect and return the URL.
	 *
	 * @param AudienceAdminEnvironment $page The page.
	 * @return string
	 */
	private function redirect_of( AudienceAdminEnvironment $page ): string {
		Functions\when( 'wp_safe_redirect' )->alias( static function ( $url ) { throw new \RuntimeException( 'redirect:' . $url ); } );
		try {
			$page->handle_actions();
		} catch ( \RuntimeException $e ) {
			$this->assertStringStartsWith( 'redirect:', $e->getMessage() );
			return substr( $e->getMessage(), strlen( 'redirect:' ) );
		}
		$this->fail( 'handle_actions() did not redirect.' );
	}

	// ==================================================================
	// Constructor
	// ==================================================================

	public function test_constructor_creates_instance(): void {
		$page = new AudienceAdminEnvironment( 'ffc-scheduling' );
		$this->assertInstanceOf( AudienceAdminEnvironment::class, $page );
	}

	// ==================================================================
	// handle_actions() — no permission
	// ==================================================================

	public function test_handle_actions_returns_early_without_permission(): void {
		// #1030: this asserted nothing, and with no action queued the method
		// would have done nothing whether or not the guard existed. Queue the
		// page's real save action so the capability is the only thing that can
		// stop it, then assert the nonce — which every acting branch verifies
		// immediately after matching the action — was never checked.
		Functions\when( 'current_user_can' )->justReturn( false );
		$_POST = array(
			'ffc_action'   => 'save_environment',
			'ffc_environment_nonce' => 'n',
		);

		Functions\expect( 'wp_verify_nonce' )->never();

		$page = new AudienceAdminEnvironment( 'ffc-scheduling' );
		$page->handle_actions();
		$this->assertSame( array(), $this->writes );
	}

	// ==================================================================
	// handle_actions() — with message
	// ==================================================================

	public function test_handle_actions_shows_feedback_message(): void {
		$_GET['message'] = 'created';
		$_GET['page'] = 'ffc-scheduling-environments';

		// #1030: the name claims a message is shown and nothing checked it —
		// a handler that rendered no feedback at all would have passed.
		$notices = array();
		Functions\when( 'add_settings_error' )->alias(
			static function ( $setting, $code, $message ) use ( &$notices ) {
				$notices[] = $message;
				return true;
			}
		);

		$page = new AudienceAdminEnvironment( 'ffc-scheduling' );
		$page->handle_actions();

		$this->assertNotEmpty( $notices, 'handle_actions() surfaced no feedback message' );
	}

	// ==================================================================
	// render_view() — default list
	// ==================================================================

	public function test_render_view_renders_list_by_default(): void {
		$page = new AudienceAdminEnvironment( 'ffc-scheduling' );
		ob_start();
		$page->render_view();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'ffc-environments-table', $output );
		// The hosting page prints the wrap (#1761), never the view.
		$this->assertStringNotContainsString( 'class="wrap', $output );
	}

	// ==================================================================
	// render_list() — with environments + calendars (data-prep / usort)
	// ==================================================================

	public function test_render_list_offers_delete_only_on_an_inactive_environment_without_bookings(): void {
		$this->environment( array( 'id' => 1, 'name' => 'Empty', 'status' => 'inactive' ) );
		$this->environment( array( 'id' => 2, 'name' => 'Booked', 'status' => 'inactive' ) );
		$this->bookings[2] = 1;
		$this->schedule( array( 'id' => 1, 'name' => 'Cal One' ) );
		$this->grant( array( 'ffc_view_audiences', 'ffc_manage_audiences', 'ffc_delete_audiences' ) );
		Functions\when( 'wp_nonce_url' )->alias( static fn( $url ) => $url );
		Functions\when( 'admin_url' )->returnArg();

		ob_start();
		( new AudienceAdminEnvironment( 'ffc-scheduling' ) )->render_view();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'action=delete&id=1', $output );
		$this->assertStringNotContainsString( 'action=delete&id=2', $output );
		$this->assertStringContainsString( 'Has bookings, kept', $output );
	}

	public function test_render_list_sorts_by_calendar_then_name(): void {
		// Stored Zeta first, so only the screen's own sort can put Alpha first.
		$this->environment( array( 'id' => 1, 'name' => 'Zeta', 'schedule_id' => 2, 'status' => 'active', 'color' => '#fff', 'description' => 'd' ) );
		$this->environment( array( 'id' => 2, 'name' => 'Alpha', 'schedule_id' => 1, 'status' => 'inactive', 'color' => '#000', 'description' => '' ) );
		$this->schedule( array( 'id' => 1, 'name' => 'Cal One' ) );
		$this->schedule( array( 'id' => 2, 'name' => 'Cal Two' ) );

		$page = new AudienceAdminEnvironment( 'ffc-scheduling' );
		ob_start();
		$page->render_view();
		$output = (string) ob_get_clean();

		// Alpha (Cal One) sorts before Zeta (Cal Two).
		$this->assertLessThan( strpos( $output, 'Zeta' ), strpos( $output, 'Alpha' ) );
		$this->assertStringContainsString( 'Cal One', $output );
	}

	public function test_render_list_prints_each_environments_shortcode_pinned_to_its_calendar(): void {
		// Real escaping, so the test sees what the browser would.
		$escape = static fn( $text ) => htmlspecialchars( (string) $text, ENT_QUOTES );
		Functions\when( 'esc_html' )->alias( $escape );
		Functions\when( 'esc_attr' )->alias( $escape );
		$this->environment( array( 'id' => 9, 'name' => 'Room A', 'schedule_id' => 3, 'status' => 'active', 'color' => '#fff', 'description' => '' ) );
		$this->schedule( array( 'id' => 3, 'name' => 'Cal Three' ) );

		ob_start();
		( new AudienceAdminEnvironment( 'ffc-scheduling' ) )->render_view();
		$output = (string) ob_get_clean();

		// #1814: the same column as the calendar list.
		$this->assertStringContainsString( '<th scope="col" class="column-shortcode">Shortcode</th>', $output );
		$this->assertMatchesRegularExpression(
			'#<td class="column-shortcode">\s*<span class="ffc-shortcode-cell"><code class="ffc-shortcode-code">\[ffc_audience schedule_id=&quot;3&quot; environment_id=&quot;9&quot;\]</code>'
			. '<button type="button" class="ffc-copy-shortcode" data-shortcode="\[ffc_audience schedule_id=&quot;3&quot; environment_id=&quot;9&quot;\]" title="Copy shortcode" aria-label="Copy shortcode">#',
			$output
		);
	}

	public function test_empty_environment_list_spans_every_column(): void {
		ob_start();
		( new AudienceAdminEnvironment( 'ffc-scheduling' ) )->render_view();
		$output = (string) ob_get_clean();

		$this->assertSame( 6, substr_count( $output, '<th scope="col"' ) );
		$this->assertStringContainsString( '<td colspan="6">', $output );
	}

	/**
	 * Row actions by tier (#1769): Edit / Deactivate need
	 * `ffc_manage_audiences`, Delete needs `ffc_delete_audiences`.
	 *
	 * @return array<string, array{0: list<string>, 1: bool, 2: bool}>
	 */
	public function row_action_tiers(): array {
		return array(
			'view-only tier'       => array( array( 'ffc_view_audiences' ), false, false ),
			'manage tier'          => array( array( 'ffc_view_audiences', 'ffc_manage_audiences' ), true, false ),
			'manage + delete tier' => array( array( 'ffc_view_audiences', 'ffc_manage_audiences', 'ffc_delete_audiences' ), true, true ),
		);
	}

	/**
	 * Grant exactly the given capabilities.
	 *
	 * @param list<string> $caps Capabilities held.
	 */
	private function grant( array $caps ): void {
		Functions\when( 'current_user_can' )->alias( static fn( string $cap ): bool => in_array( $cap, $caps, true ) );
	}

	/**
	 * @dataProvider row_action_tiers
	 *
	 * @param list<string> $caps       Capabilities held.
	 * @param bool         $can_manage Whether Edit / Deactivate render.
	 * @param bool         $can_delete Whether Delete renders.
	 */
	public function test_row_actions_render_only_for_the_cap_their_handler_checks( array $caps, bool $can_manage, bool $can_delete ): void {
		$this->grant( $caps );
		$this->environment( array( 'id' => 1, 'name' => 'Active Room', 'schedule_id' => 1, 'status' => 'active', 'color' => '#fff', 'description' => '' ) );
		$this->environment( array( 'id' => 2, 'name' => 'Inactive Room', 'schedule_id' => 1, 'status' => 'inactive', 'color' => '#000', 'description' => '' ) );
		$this->schedule( array( 'id' => 1, 'name' => 'Cal One' ) );

		$page = new AudienceAdminEnvironment( 'ffc-scheduling' );
		ob_start();
		$page->render_view();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'Active Room', $output, 'The rows render for every tier.' );
		$this->assertSame( $can_manage ? 2 : 0, substr_count( $output, 'Edit</a>' ) );
		$this->assertSame( $can_manage, str_contains( $output, 'Deactivate' ) );
		$this->assertSame( $can_delete, str_contains( $output, 'Delete' ) );
	}

	public function test_render_list_filters_by_schedule(): void {
		$_GET['schedule_id'] = '3';
		// The filtered calendar names its environments "Rooms"; the view
		// switch names the views with the default label (#1761).
		$this->schedule( array( 'id' => 3, 'name' => 'Cal Three', 'environment_label' => 'Rooms' ) );
		$this->environment( array( 'id' => 5, 'name' => 'Elsewhere', 'schedule_id' => 4 ) );

		$page = new AudienceAdminEnvironment( 'ffc-scheduling' );
		ob_start();
		$page->render_view();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'Rooms', $output );
		$env_reads = $this->reads_of( self::ENV_TABLE );
		$this->assertCount( 1, $env_reads, 'the environments are read once' );
		$this->assertStringContainsString( 'WHERE schedule_id = %d', $env_reads[0][0] );
		$this->assertSame( array( self::ENV_TABLE, 3 ), $env_reads[0][1] );
		$this->assertStringNotContainsString( 'Elsewhere', $output );
	}

	// ==================================================================
	// render_form()
	// ==================================================================

	public function test_render_form_new_shows_create_title(): void {
		$_GET['action'] = 'new';
		Functions\when( 'wp_nonce_field' )->justReturn( '' );
		$this->schedule( array( 'id' => 1, 'name' => 'Cal' ) );

		$page = new AudienceAdminEnvironment( 'ffc-scheduling' );
		ob_start();
		$page->render_view();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'Cal', $output );
		$this->assertStringContainsString( 'environment_name', $output );
	}

	public function test_render_form_edit_loads_existing_with_working_hours(): void {
		$_GET['action'] = 'edit';
		$_GET['id']     = '7';
		Functions\when( 'wp_nonce_field' )->justReturn( '' );

		$this->environment(
			array(
				'id'            => 7,
				'schedule_id'   => 2,
				'name'          => 'My Room',
				'description'   => 'desc',
				'color'         => '#abcdef',
				'status'        => 'active',
				'working_hours' => json_encode( array( 'mon' => array( 'closed' => true, 'start' => '09:00', 'end' => '17:00' ) ) ),
			)
		);
		$this->schedule( array( 'id' => 2, 'name' => 'Cal', 'environment_label' => 'Room' ) );

		$page = new AudienceAdminEnvironment( 'ffc-scheduling' );
		ob_start();
		$page->render_view();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'My Room', $output );
		$this->assertStringContainsString( '#abcdef', $output );

		// #1627: the form sits in one box headed by the calendar's own label.
		// The `.ffc-boxed` wrap is the hosting calendars page's (#1761).
		$this->assertMatchesRegularExpression( '/<div class="card">\s*<h2 class="ffc-icon-building">Room<\/h2>/', $output );
		$this->assertSame( substr_count( $output, '<div' ), substr_count( $output, '</div>' ), 'every box is closed' );
	}

	public function test_render_form_edit_missing_environment_dies(): void {
		$_GET['action'] = 'edit';
		$_GET['id']     = '99';
		Functions\when( 'wp_die' )->alias( static function ( $m ) { throw new \RuntimeException( (string) $m ); } );

		$page = new AudienceAdminEnvironment( 'ffc-scheduling' );
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Environment not found' );
		// The view may echo markup before it detects the missing record and
		// calls wp_die(); capture and discard that output so the markup
		// doesn't leak into PHPUnit's stdout (the assertion is on the wp_die
		// exception, not the buffer).
		ob_start();
		try {
			$page->render_view();
		} finally {
			ob_end_clean();
		}
	}

	// ==================================================================
	// handle_actions() — save (create + update)
	// ==================================================================

	public function test_handle_actions_creates_environment_and_redirects(): void {
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		Functions\when( 'admin_url' )->returnArg();

		$_POST = array(
			'ffc_action'            => 'save_environment',
			'ffc_environment_nonce' => 'n',
			'environment_id'        => '0',
			'environment_schedule'  => '3',
			'environment_name'      => 'New Env',
			'environment_color'     => '#112233',
			'environment_status'    => 'active',
			'working_hours'         => array(
				'mon' => array( 'start' => '08:00', 'end' => '12:00' ),
				'tue' => array( 'closed' => '1' ),
			),
		);

		$page = new AudienceAdminEnvironment( 'ffc-scheduling' );
		$this->assertStringContainsString( 'message=created', $this->redirect_of( $page ) );

		$inserts = $this->writes_of( 'insert' );
		$this->assertCount( 1, $inserts );
		$this->assertSame( self::ENV_TABLE, $inserts[0][1] );
		$this->assertSame( 3, $inserts[0][2]['schedule_id'] );
		$this->assertSame( 'New Env', $inserts[0][2]['name'] );
		$hours = json_decode( (string) $inserts[0][2]['working_hours'], true );
		$this->assertTrue( $hours['tue']['closed'] );
		$this->assertSame( '08:00', $hours['mon']['start'] );
	}

	public function test_handle_actions_updates_environment(): void {
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );

		$_POST = array(
			'ffc_action'            => 'save_environment',
			'ffc_environment_nonce' => 'n',
			'environment_id'        => '5',
			'environment_schedule'  => '2',
			'environment_name'      => 'Edited',
			'environment_color'     => '#222222',
			'environment_status'    => 'inactive',
		);

		Functions\when( 'add_settings_error' )->justReturn( true );

		$page = new AudienceAdminEnvironment( 'ffc-scheduling' );
		$page->handle_actions();

		$updates = $this->writes_of( 'update' );
		$this->assertCount( 1, $updates );
		$this->assertSame( self::ENV_TABLE, $updates[0][1] );
		$this->assertSame( array( 'id' => 5 ), $updates[0][3] );
		$this->assertSame( 'Edited', $updates[0][2]['name'] );
	}

	public function test_render_form_keeps_an_inactive_calendar_selected(): void {
		// The select listed active calendars only, so an environment on a
		// deactivated calendar showed "Select a calendar" and could not be
		// saved past the required check (#1771).
		$_GET['action'] = 'edit';
		$_GET['id']     = '7';
		Functions\when( 'wp_nonce_field' )->justReturn( '' );
		Functions\when( 'selected' )->alias(
			static function ( $a, $b ) {
				echo (string) $a === (string) $b ? ' selected="selected"' : '';
			}
		);

		$this->environment(
			array(
				'id'            => 7,
				'schedule_id'   => 4,
				'name'          => 'Old Room',
				'description'   => '',
				'color'         => '#abcdef',
				'status'        => 'active',
				'working_hours' => '',
			)
		);
		$this->schedule( array( 'id' => 2, 'name' => 'Live Cal', 'status' => 'active', 'environment_label' => 'Room' ) );
		$this->schedule( array( 'id' => 4, 'name' => 'Archived Cal', 'status' => 'inactive', 'environment_label' => 'Room' ) );

		ob_start();
		try {
			( new AudienceAdminEnvironment( 'ffc-scheduling' ) )->render_view();
		} finally {
			$output = (string) ob_get_clean();
		}

		$this->assertMatchesRegularExpression( '#<option value="4"\s+selected="selected">\s*Archived Cal \(inactive\)\s*</option>#', $output );
		$this->assertMatchesRegularExpression( '#<option value="2"\s*>\s*Live Cal\s*</option>#', $output );
	}

	/**
	 * Render the environment form for the given query, with `selected()`
	 * printing its attribute and two active calendars stored: 2 ("Cal Two")
	 * and 3 ("Cal Three", whose environments are "Room").
	 *
	 * @param array<string, string> $get Query arguments.
	 */
	private function render_form_for( array $get ): string {
		$_GET = array_merge( $_GET, $get );
		Functions\when( 'wp_nonce_field' )->justReturn( '' );
		Functions\when( 'selected' )->alias(
			static function ( $a, $b ) {
				echo (string) $a === (string) $b ? ' selected="selected"' : '';
			}
		);
		$this->schedule( array( 'id' => 2, 'name' => 'Cal Two', 'status' => 'active' ) );
		$this->schedule( array( 'id' => 3, 'name' => 'Cal Three', 'status' => 'active', 'environment_label' => 'Room' ) );

		ob_start();
		try {
			( new AudienceAdminEnvironment( 'ffc-scheduling' ) )->render_view();
		} finally {
			$output = (string) ob_get_clean();
		}
		return $output;
	}

	public function test_render_form_new_preselects_the_requested_calendar(): void {
		// The calendar editor's Environments tab opens Add New with its
		// calendar (#1796).
		$output = $this->render_form_for( array( 'action' => 'new', 'schedule_id' => '3' ) );

		$this->assertMatchesRegularExpression( '#<option value="3"\s+selected="selected">\s*Cal Three\s*</option>#', $output );
		$this->assertMatchesRegularExpression( '#<option value="2"\s*>\s*Cal Two\s*</option>#', $output );
		$this->assertMatchesRegularExpression( '#<option value=""\s*>\s*Select a calendar\s*</option>#', $output );
		$this->assertStringContainsString( 'name="environment_id" value="0"', $output, 'the form still creates' );
		$this->assertStringContainsString( '<h1>Add New Room</h1>', $output, 'the title takes the calendar\'s label' );
	}

	public function test_render_form_new_ignores_an_unknown_calendar(): void {
		$output = $this->render_form_for( array( 'action' => 'new', 'schedule_id' => '99' ) );

		$this->assertStringNotContainsString( 'selected="selected"', $output );
		$this->assertMatchesRegularExpression( '#<option value=""\s*>\s*Select a calendar\s*</option>#', $output );
		$this->assertStringNotContainsString( 'value="99"', $output );
		$this->assertStringContainsString( '<h1>Add New Environment</h1>', $output );
	}

	public function test_render_form_new_ignores_a_value_that_is_not_an_id(): void {
		$output = $this->render_form_for( array( 'action' => 'new', 'schedule_id' => 'abc' ) );

		$this->assertStringNotContainsString( 'selected="selected"', $output );
	}

	public function test_render_form_edit_keeps_the_saved_calendar_over_the_query(): void {
		$this->environment(
			array(
				'id'            => 7,
				'schedule_id'   => 2,
				'name'          => 'My Room',
				'description'   => '',
				'color'         => '#abcdef',
				'status'        => 'active',
				'working_hours' => '',
			)
		);

		$output = $this->render_form_for( array( 'action' => 'edit', 'id' => '7', 'schedule_id' => '3' ) );

		$this->assertMatchesRegularExpression( '#<option value="2"\s+selected="selected">\s*Cal Two\s*</option>#', $output );
		$this->assertMatchesRegularExpression( '#<option value="3"\s*>\s*Cal Three\s*</option>#', $output );
	}

	public function test_calendar_tab_add_new_carries_the_calendar(): void {
		Functions\when( 'admin_url' )->returnArg();
		$this->schedule( array( 'id' => 4, 'name' => 'Cal Four', 'environment_label' => 'Rooms' ) );

		ob_start();
		( new AudienceAdminEnvironment( 'ffc-scheduling' ) )->render_calendar_tab( 4 );
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'href="admin.php?page=ffc-scheduling-calendars&view=environments&action=new&schedule_id=4" class="ffc-btn ffc-btn-create ffc-icon-plus">Add New</a>', $output );
		$env_reads = $this->reads_of( self::ENV_TABLE );
		$this->assertCount( 1, $env_reads );
		$this->assertSame( array( self::ENV_TABLE, 4 ), $env_reads[0][1], 'the tab lists only this calendar\'s environments' );
	}

	/**
	 * The list's own Add New carries the calendar filter it applies, and
	 * nothing when no filter is active (#1801).
	 *
	 * @return array<string, array{0: array<string, string>, 1: string}>
	 */
	public function list_add_new_filters(): array {
		return array(
			'filtered'   => array( array( 'schedule_id' => '4' ), '&action=new&schedule_id=4' ),
			'unfiltered' => array( array(), '&action=new' ),
			'not an id'  => array( array( 'schedule_id' => 'abc' ), '&action=new' ),
		);
	}

	/**
	 * @dataProvider list_add_new_filters
	 *
	 * @param array<string, string> $get      Query arguments of the list.
	 * @param string                $expected Query the Add New link ends with.
	 */
	public function test_list_add_new_carries_the_active_filter( array $get, string $expected ): void {
		$_GET = array_merge( $_GET, $get );
		Functions\when( 'admin_url' )->returnArg();

		ob_start();
		( new AudienceAdminEnvironment( 'ffc-scheduling' ) )->render_view();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'href="admin.php?page=ffc-scheduling-calendars&view=environments' . $expected . '" class="ffc-btn ffc-btn-create ffc-icon-plus">Add New</a>', $output );
	}

	/**
	 * Run a save of environment 5 with the given working hours and return
	 * the working hours that reached the database.
	 *
	 * @param array<string, array<string, string>> $posted Posted `working_hours`.
	 * @param string                               $stored Stored `working_hours` JSON.
	 * @return array<string, mixed>
	 */
	private function save_working_hours( array $posted, string $stored ): array {
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		Functions\when( 'add_settings_error' )->justReturn( true );
		$_POST = array(
			'ffc_action'            => 'save_environment',
			'ffc_environment_nonce' => 'n',
			'environment_id'        => '5',
			'environment_schedule'  => '2',
			'environment_name'      => 'Room',
			'working_hours'         => $posted,
		);
		$this->environment(
			array(
				'id'            => 5,
				'schedule_id'   => 2,
				'working_hours' => $stored,
			)
		);

		( new AudienceAdminEnvironment( 'ffc-scheduling' ) )->handle_actions();

		$updates = $this->writes_of( 'update' );
		$this->assertCount( 1, $updates );
		$saved = json_decode( (string) $updates[0][2]['working_hours'], true );
		return is_array( $saved ) ? $saved : array();
	}

	public function test_save_keeps_the_stored_times_of_a_closed_day(): void {
		// A closed day's time inputs are disabled, so only `closed` is posted;
		// the save used to reset the day to 08:00-18:00 (#1771).
		$saved = $this->save_working_hours(
			array(
				'mon' => array( 'closed' => '1' ),
				'tue' => array( 'start' => '10:00', 'end' => '16:00' ),
			),
			(string) json_encode(
				array(
					'mon' => array( 'closed' => false, 'start' => '07:30', 'end' => '12:15' ),
					'tue' => array( 'closed' => false, 'start' => '09:00', 'end' => '17:00' ),
				)
			)
		);

		$this->assertSame(
			array(
				'mon' => array( 'closed' => true, 'start' => '07:30', 'end' => '12:15' ),
				'tue' => array( 'closed' => false, 'start' => '10:00', 'end' => '16:00' ),
			),
			$saved
		);
	}

	public function test_save_stores_the_default_for_an_empty_time(): void {
		// The field says "Leave times empty to use default (08:00 - 18:00)",
		// and an empty string is what was stored (#1771).
		$saved = $this->save_working_hours(
			array(
				'wed' => array( 'start' => '', 'end' => '' ),
				'thu' => array( 'closed' => '1' ),
			),
			''
		);

		$this->assertSame(
			array(
				'wed' => array( 'closed' => false, 'start' => '08:00', 'end' => '18:00' ),
				'thu' => array( 'closed' => true, 'start' => '08:00', 'end' => '18:00' ),
			),
			$saved
		);
	}

	public function test_handle_actions_does_not_save_on_another_screen(): void {
		// The coordinator runs this handler on every ffc-scheduling* screen;
		// the save branch was the one that did not check which (#1771).
		$_GET['page'] = 'ffc-scheduling-settings';
		$nonce        = \FreeFormCertificate\Tests\Support\CallLog::record( 'wp_verify_nonce', 1 );
		$_POST        = array(
			'ffc_action'            => 'save_environment',
			'ffc_environment_nonce' => 'n',
			'environment_id'        => '5',
		);

		( new AudienceAdminEnvironment( 'ffc-scheduling' ) )->handle_actions();
		$this->assertSame( 0, $nonce->count() );
		$this->assertSame( array(), $this->writes );
	}

	public function test_handle_actions_save_aborts_on_bad_nonce(): void {
		Functions\when( 'wp_verify_nonce' )->justReturn( false );
		$_POST = array( 'ffc_action' => 'save_environment', 'ffc_environment_nonce' => 'bad' );

		// Must return before any persistence.
		$page = new AudienceAdminEnvironment( 'ffc-scheduling' );
		$page->handle_actions();
		$this->assertSame( array(), $this->writes );
	}

	// ==================================================================
	// handle_actions() — deactivate
	// ==================================================================

	public function test_handle_actions_deactivates_environment(): void {
		$_GET = array(
			'action'   => 'deactivate',
			'id'       => '8',
			'page'     => 'ffc-scheduling-environments',
			'_wpnonce' => 'n',
		);
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		Functions\when( 'admin_url' )->returnArg();

		$page = new AudienceAdminEnvironment( 'ffc-scheduling' );
		$this->assertStringContainsString( 'message=deactivated', $this->redirect_of( $page ) );
		$this->assertSame( array( array( 'update', self::ENV_TABLE, array( 'status' => 'inactive' ), array( 'id' => 8 ) ) ), $this->writes );
	}

	// ==================================================================
	// handle_actions() — delete
	// ==================================================================

	public function test_handle_actions_deletes_inactive_environment(): void {
		$_GET = array(
			'action'   => 'delete',
			'id'       => '9',
			'page'     => 'ffc-scheduling-environments',
			'_wpnonce' => 'n',
		);
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		Functions\when( 'admin_url' )->returnArg();
		$this->environment( array( 'id' => 9, 'status' => 'inactive' ) );

		$page = new AudienceAdminEnvironment( 'ffc-scheduling' );
		$this->assertStringContainsString( 'message=deleted', $this->redirect_of( $page ) );
		$this->assertSame( array( array( 'delete', self::ENV_TABLE, array(), array( 'id' => 9 ) ) ), $this->writes );
	}

	public function test_handle_actions_keeps_an_environment_that_has_bookings(): void {
		$_GET = array(
			'action'   => 'delete',
			'id'       => '9',
			'page'     => 'ffc-scheduling-environments',
			'_wpnonce' => 'n',
		);
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		Functions\when( 'admin_url' )->returnArg();
		$this->environment( array( 'id' => 9, 'schedule_id' => 2, 'status' => 'inactive' ) );
		$this->bookings[9] = 3;

		$page = new AudienceAdminEnvironment( 'ffc-scheduling' );
		$this->assertStringContainsString( 'message=has_bookings&calendar_id=2', $this->redirect_of( $page ) );
		$this->assertSame( array(), $this->writes, 'A booking keeps its environment.' );
	}

	public function test_the_refusal_explains_itself(): void {
		$_GET = array(
			'page'    => 'ffc-scheduling-environments',
			'message' => 'has_bookings',
		);
		$errors = array();
		Functions\when( 'add_settings_error' )->alias( function ( $setting, $code, $message, $type ) use ( &$errors ) {
			$errors[] = array( $message, $type );
		});

		( new AudienceAdminEnvironment( 'ffc-scheduling' ) )->handle_actions();

		$this->assertCount( 1, $errors );
		$this->assertSame( 'error', $errors[0][1] );
		$this->assertStringContainsString( 'Not deleted: bookings use it', $errors[0][0] );
	}

	public function test_handle_actions_delete_skips_active_environment(): void {
		$_GET = array(
			'action'   => 'delete',
			'id'       => '9',
			'page'     => 'ffc-scheduling-environments',
			'_wpnonce' => 'n',
		);
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		$redirects = \FreeFormCertificate\Tests\Support\CallLog::record( 'wp_safe_redirect' );
		$this->environment( array( 'id' => 9, 'status' => 'active' ) );

		$page = new AudienceAdminEnvironment( 'ffc-scheduling' );
		$page->handle_actions(); // no redirect — active item can't be deleted.
		$this->assertSame( array(), $this->writes );
		$this->assertSame( 0, $redirects->count() );
	}

	public function test_handle_actions_delete_denied_without_cap(): void {
		$_GET = array(
			'action' => 'delete',
			'id'     => '9',
			'page'   => 'ffc-scheduling-environments',
		);
		// Top gate admin_or('ffc_manage_audiences') passes via the granular cap;
		// not a site admin and the delete cap is denied -> wp_die.
		Functions\when( 'current_user_can' )->alias(
			static fn( $cap ) => 'ffc_manage_audiences' === $cap
		);
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		Functions\when( 'wp_die' )->alias( static function ( $m ) { throw new \RuntimeException( (string) $m ); } );

		$page = new AudienceAdminEnvironment( 'ffc-scheduling' );
		try {
			$page->handle_actions();
			$this->fail( 'handle_actions() did not die.' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'permission to delete', $e->getMessage() );
		}
		$this->assertSame( array(), $this->writes );
	}

	// ==================================================================
	// #1761 — the screen is a view of Audience Calendars
	// ==================================================================

	/**
	 * Where an environment request can arrive: the view on the calendars
	 * page, and the previous slug (still registered, hidden).
	 *
	 * @return array<string, array{0: array<string, string>}>
	 */
	public function locations(): array {
		return array(
			'Environments view' => array( array( 'page' => 'ffc-scheduling-calendars', 'view' => 'environments' ) ),
			'previous slug'     => array( array( 'page' => 'ffc-scheduling-environments' ) ),
		);
	}

	public function test_view_url_names_the_calendars_page_and_the_view(): void {
		Functions\when( 'admin_url' )->returnArg();
		$this->assertSame( 'admin.php?page=ffc-scheduling-calendars&view=environments', AudienceAdminEnvironment::view_url( 'ffc-scheduling' ) );
		$this->assertSame( 'admin.php?page=ffc-scheduling-calendars&view=environments&action=new', AudienceAdminEnvironment::view_url( 'ffc-scheduling', '&action=new' ) );
	}

	/**
	 * @dataProvider locations
	 *
	 * @param array<string, string> $location The page (and view) of the request.
	 */
	public function test_is_own_request_at_either_location( array $location ): void {
		$_GET = $location;
		$this->assertTrue( ( new AudienceAdminEnvironment( 'ffc-scheduling' ) )->is_own_request() );
	}

	public function test_is_not_own_request_on_the_calendars_view_or_a_sibling(): void {
		$page = new AudienceAdminEnvironment( 'ffc-scheduling' );
		$_GET = array( 'page' => 'ffc-scheduling-calendars' );
		$this->assertFalse( $page->is_own_request(), 'the Calendars view' );
		$_GET = array(
			'page' => 'ffc-scheduling-audiences',
			'view' => 'environments',
		);
		$this->assertFalse( $page->is_own_request(), 'a sibling page carrying the view' );
	}

	/**
	 * @dataProvider locations
	 *
	 * @param array<string, string> $location The page (and view) of the request.
	 */
	public function test_deactivate_works_from_either_location_and_lands_on_the_view( array $location ): void {
		$_GET = $location + array(
			'action'   => 'deactivate',
			'id'       => '8',
			'_wpnonce' => 'n',
		);
		Functions\expect( 'wp_verify_nonce' )->once()->with( 'n', 'deactivate_environment_8' )->andReturn( 1 );
		Functions\when( 'admin_url' )->returnArg();
		$this->environment( array( 'id' => 8, 'schedule_id' => 2, 'status' => 'active' ) );

		$this->assertSame(
			'admin.php?page=ffc-scheduling-calendars&view=environments&message=deactivated&calendar_id=2',
			$this->redirect_of( new AudienceAdminEnvironment( 'ffc-scheduling' ) )
		);
		$this->assertSame( array( array( 'update', self::ENV_TABLE, array( 'status' => 'inactive' ), array( 'id' => 8 ) ) ), $this->writes );
	}

	/**
	 * @dataProvider locations
	 *
	 * @param array<string, string> $location The page (and view) of the request.
	 */
	public function test_delete_works_from_either_location_and_lands_on_the_view( array $location ): void {
		$_GET = $location + array(
			'action'   => 'delete',
			'id'       => '9',
			'_wpnonce' => 'n',
		);
		Functions\expect( 'wp_verify_nonce' )->once()->with( 'n', 'delete_environment_9' )->andReturn( 1 );
		Functions\when( 'admin_url' )->returnArg();
		$this->environment( array( 'id' => 9, 'schedule_id' => 2, 'status' => 'inactive' ) );

		$this->assertSame(
			'admin.php?page=ffc-scheduling-calendars&view=environments&message=deleted&calendar_id=2',
			$this->redirect_of( new AudienceAdminEnvironment( 'ffc-scheduling' ) )
		);
		$this->assertSame( array( array( 'delete', self::ENV_TABLE, array(), array( 'id' => 9 ) ) ), $this->writes );
	}

	/**
	 * @dataProvider locations
	 *
	 * @param array<string, string> $location The page (and view) of the request.
	 */
	public function test_create_works_from_either_location_and_opens_the_new_record_in_the_view( array $location ): void {
		$_GET  = $location + array( 'action' => 'new' );
		$_POST = array(
			'ffc_action'            => 'save_environment',
			'ffc_environment_nonce' => 'n',
			'environment_id'        => '0',
			'environment_schedule'  => '3',
			'environment_name'      => 'New Env',
			'environment_status'    => 'active',
		);
		Functions\expect( 'wp_verify_nonce' )->once()->with( 'n', 'save_environment' )->andReturn( 1 );
		Functions\when( 'admin_url' )->returnArg();

		// The double answers every insert with id 42.
		$this->assertSame(
			'admin.php?page=ffc-scheduling-calendars&view=environments&action=edit&id=42&message=created&calendar_id=3',
			$this->redirect_of( new AudienceAdminEnvironment( 'ffc-scheduling' ) )
		);
		$inserts = $this->writes_of( 'insert' );
		$this->assertCount( 1, $inserts );
		$this->assertSame( 3, $inserts[0][2]['schedule_id'] );
		$this->assertSame( 'New Env', $inserts[0][2]['name'] );
	}

	/**
	 * @dataProvider locations
	 *
	 * @param array<string, string> $location The page (and view) of the request.
	 */
	public function test_update_works_from_either_location( array $location ): void {
		$_GET  = $location + array(
			'action' => 'edit',
			'id'     => '5',
		);
		$_POST = array(
			'ffc_action'            => 'save_environment',
			'ffc_environment_nonce' => 'n',
			'environment_id'        => '5',
			'environment_schedule'  => '2',
			'environment_name'      => 'Edited',
			'environment_status'    => 'inactive',
		);
		Functions\expect( 'wp_verify_nonce' )->once()->with( 'n', 'save_environment' )->andReturn( 1 );
		$notices = array();
		Functions\when( 'add_settings_error' )->alias(
			static function ( $setting, $code, $message ) use ( &$notices ) {
				$notices[] = $message;
				return true;
			}
		);
		// The environment's calendar names its environments "Room".
		$this->schedule( array( 'id' => 2, 'name' => 'Cal Two', 'environment_label' => 'Room' ) );

		( new AudienceAdminEnvironment( 'ffc-scheduling' ) )->handle_actions();
		$updates = $this->writes_of( 'update' );
		$this->assertCount( 1, $updates );
		$this->assertSame( array( 'id' => 5 ), $updates[0][3] );
		$this->assertSame( 'Edited', $updates[0][2]['name'] );
		$this->assertSame( 'inactive', $updates[0][2]['status'] );
		$this->assertSame( array( 'Room updated successfully.' ), $notices );
	}

	/**
	 * @dataProvider locations
	 *
	 * @param array<string, string> $location The page (and view) of the request.
	 */
	public function test_redirect_feedback_shows_at_either_location( array $location ): void {
		$_GET    = $location + array(
			'message'     => 'deleted',
			'calendar_id' => '2',
		);
		$notices = array();
		Functions\when( 'add_settings_error' )->alias(
			static function ( $setting, $code, $message ) use ( &$notices ) {
				$notices[] = $message;
				return true;
			}
		);
		// The redirect names the environment's calendar, so the notice uses
		// that calendar's label (#2176).
		$this->schedule( array( 'id' => 2, 'name' => 'Cal Two', 'environment_label' => 'Room' ) );

		( new AudienceAdminEnvironment( 'ffc-scheduling' ) )->handle_actions();
		$this->assertSame( array( 'Room deleted successfully.' ), $notices );
	}

	/**
	 * A redirect that names no calendar uses the default label and reads
	 * no calendar to find it (#2176).
	 */
	public function test_redirect_feedback_without_a_calendar_uses_the_default_label_unread(): void {
		$_GET    = array(
			'page'    => 'ffc-scheduling-calendars',
			'view'    => 'environments',
			'message' => 'created',
		);
		$notices = array();
		Functions\when( 'add_settings_error' )->alias(
			static function ( $setting, $code, $message ) use ( &$notices ) {
				$notices[] = $message;
				return true;
			}
		);
		$this->schedule( array( 'id' => 2, 'name' => 'Cal Two', 'environment_label' => 'Room' ) );

		( new AudienceAdminEnvironment( 'ffc-scheduling' ) )->handle_actions();
		$this->assertSame( array( 'Environment created successfully.' ), $notices );
		$this->assertSame( array(), $this->reads_of( self::SCHEDULE_TABLE ), 'no calendar was read for the label' );
	}

	public function test_actions_are_left_alone_on_the_calendars_view(): void {
		// The calendars list shares the page; its `action=deactivate` and
		// `message` belong to AudienceAdminCalendar.
		$_GET = array(
			'page'     => 'ffc-scheduling-calendars',
			'action'   => 'deactivate',
			'id'       => '8',
			'_wpnonce' => 'n',
			'message'  => 'deleted',
		);
		$nonces  = \FreeFormCertificate\Tests\Support\CallLog::record( 'wp_verify_nonce', 1 );
		$notices = \FreeFormCertificate\Tests\Support\CallLog::record( 'add_settings_error', true );

		( new AudienceAdminEnvironment( 'ffc-scheduling' ) )->handle_actions();
		$this->assertSame( 0, $nonces->count(), 'no environment action was attempted' );
		$this->assertSame( 0, $notices->count(), 'no environment notice was raised' );
		$this->assertSame( array(), $this->writes, 'nothing was written' );
	}

	public function test_the_list_links_and_filter_point_at_the_view(): void {
		Functions\when( 'admin_url' )->returnArg();
		Functions\when( 'wp_nonce_url' )->alias( static fn( $url, $action ) => $url . '&_wpnonce=' . $action );
		$_GET = array(
			'page'        => 'ffc-scheduling-calendars',
			'view'        => 'environments',
			'schedule_id' => '1',
		);

		$this->environment( array( 'id' => 1, 'name' => 'Active Room', 'schedule_id' => 1, 'status' => 'active', 'color' => '#fff', 'description' => '' ) );
		$this->environment( array( 'id' => 2, 'name' => 'Inactive Room', 'schedule_id' => 1, 'status' => 'inactive', 'color' => '#000', 'description' => '' ) );
		$this->schedule( array( 'id' => 1, 'name' => 'Cal One' ) );
		$this->schedule( array( 'id' => 2, 'name' => 'Cal Two' ) );

		ob_start();
		( new AudienceAdminEnvironment( 'ffc-scheduling' ) )->render_view();
		$output = (string) ob_get_clean();

		$env_reads = $this->reads_of( self::ENV_TABLE );
		$this->assertCount( 1, $env_reads );
		$this->assertSame( array( self::ENV_TABLE, 1 ), $env_reads[0][1], 'the list reads the filtered calendar\'s environments' );

		$view = 'admin.php?page=ffc-scheduling-calendars&view=environments';
		$this->assertStringContainsString( 'href="' . $view . '&action=new&schedule_id=1" class="ffc-btn ffc-btn-create ffc-icon-plus">Add New</a>', $output, 'the filtered list\'s Add New carries the calendar (#1801)' );
		$this->assertSame( 2, substr_count( $output, 'href="' . $view . '&action=edit&id=1"' ), 'the name link and the Edit action' );
		$this->assertStringContainsString( 'href="' . $view . '&action=deactivate&id=1&_wpnonce=deactivate_environment_1"', $output );
		$this->assertStringContainsString( 'href="' . $view . '&action=delete&id=2&_wpnonce=delete_environment_2"', $output );
		$this->assertStringNotContainsString( 'ffc-scheduling-environments', $output, 'no link is left on the previous slug' );

		// The filter: a GET form that stays on the view, the calendar select and its Filter button.
		$this->assertMatchesRegularExpression( '/<div class="tablenav top">\s*<form method="get">\s*<input type="hidden" name="page" value="ffc-scheduling-calendars">\s*<input type="hidden" name="view" value="environments">\s*<select name="schedule_id">/', $output );
		$this->assertStringContainsString( '<option value="">All Calendars</option>', $output );
		$this->assertStringContainsString( '<button type="submit" class="ffc-btn ffc-btn-neutral ffc-icon-filter" name="filter" value="Filter">Filter</button>', $output );

		// The same columns as before the move, plus the shortcode (#1814).
		preg_match_all( '/<th scope="col" class="(column-[a-z]+)">/', $output, $columns );
		$this->assertSame( array( 'column-color', 'column-name', 'column-shortcode', 'column-calendar', 'column-status', 'column-actions' ), $columns[1] );

		// The view switch, with Environments current.
		$this->assertMatchesRegularExpression( '/<ul class="subsubsub">.*href="admin\.php\?page=ffc-scheduling-calendars">Calendars<\/a>.*href="' . preg_quote( $view, '/' ) . '" class="current" aria-current="page">Environments<\/a>/s', $output );
	}

	/**
	 * Edit-screen tiers (#1777): view-only renders read-only, manage edits.
	 *
	 * @return array<string, array{0: list<string>, 1: bool}>
	 */
	public function edit_screen_tiers(): array {
		return array(
			'view-only tier' => array( array( 'ffc_view_audiences' ), false ),
			'manage tier'    => array( array( 'ffc_view_audiences', 'ffc_manage_audiences' ), true ),
		);
	}


	/**
	 * @dataProvider edit_screen_tiers
	 *
	 * @param list<string> $caps       Capabilities held.
	 * @param bool         $can_manage Whether the screen is editable.
	 */
	public function test_edit_form_renders_read_only_without_manage( array $caps, bool $can_manage ): void {
		$this->grant( $caps );
		$_GET['action'] = 'edit';
		$_GET['id']     = '7';
		Functions\when( 'wp_nonce_field' )->justReturn( '' );

		$this->environment(
			array(
				'id'            => 7,
				'schedule_id'   => 2,
				'name'          => 'My Room',
				'description'   => 'desc',
				'color'         => '#abcdef',
				'status'        => 'active',
				'working_hours' => '',
			)
		);
		$this->schedule( array( 'id' => 2, 'name' => 'Cal', 'status' => 'active', 'environment_label' => 'Room' ) );

		$page = new AudienceAdminEnvironment( 'ffc-scheduling' );
		ob_start();
		$page->render_view();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'My Room', $output, 'every tier sees the record' );
		$this->assertSame( ! $can_manage, str_contains( $output, 'Read-only — you can view this page' ) );
		$this->assertSame( $can_manage ? 0 : 1, substr_count( $output, '<fieldset disabled class="ffc-readonly-lock">' ) );
		$this->assertSame( $can_manage ? 0 : 1, substr_count( $output, '</fieldset>' ) );
		$this->assertSame( $can_manage, str_contains( $output, 'name="submit" value="Update Room">Update Room</button>' ) );
		if ( ! $can_manage ) {
			$open  = (int) strpos( $output, '<fieldset disabled' );
			$close = (int) strpos( $output, '</fieldset>' );
			foreach ( array( 'name="environment_schedule"', 'name="environment_name"', 'name="working_hours[sun][closed]"', 'name="environment_status"' ) as $field ) {
				$at = (int) strpos( $output, $field );
				$this->assertTrue( $at > $open && $at < $close, $field . ' sits inside the lock' );
			}
		}
		$this->assertSame( substr_count( $output, '<div' ), substr_count( $output, '</div>' ), 'every box is closed' );
	}
}
