<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Audience\AudienceAccountEmail;
use FreeFormCertificate\Audience\AudienceAdminCalendar;

/**
 * The Audience Calendars page: its list, the calendar editor, the
 * Environments view it hosts, and the actions it handles.
 *
 * AudienceScheduleRepository, AudienceEnvironmentRepository, PiiAccessPolicy
 * and DateFormatter are the real classes, over a `$wpdb` double that answers
 * each read by its SQL text from the calendar, environment, holiday and
 * permission rows a test stores, records every write, and fails the test on
 * any query it does not know. Every redirect ends in `exit`; the
 * `wp_safe_redirect()` stub throws to stand in for it.
 *
 * @covers \FreeFormCertificate\Audience\AudienceAdminCalendar
 */
class AudienceAdminCalendarTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private const SCHEDULE_TABLE = 'wp_ffc_audience_schedules';

	private const ENV_TABLE = 'wp_ffc_audience_environments';

	private const HOLIDAY_TABLE = 'wp_ffc_audience_holidays';

	private const PERMISSION_TABLE = 'wp_ffc_audience_schedule_permissions';

	/** The viewer: a member of staff, never the owner of a listed account. */
	private const VIEWER = 1;

	/** @var bool Whether the viewer holds the audiences PII cap, so the policy lets them reveal (#1824). */
	private $can_reveal = false;

	/**
	 * Stored rows per table, each by id in insertion order.
	 *
	 * @var array<string, array<int, array<string, mixed>>>
	 */
	private array $rows = array();

	/** @var list<object> Active bookings on the date a closed day is asked about (#2243). */
	private array $day_bookings = array();

	/** @var array<int, int> Bookings per calendar (#2243); a calendar not listed has none. */
	private array $bookings_by_schedule = array();

	/**
	 * Every query the double answered, as `[ sql, args ]`.
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

	/**
	 * Every `user_can()` question the policy asked, as `[ user, cap ]`.
	 *
	 * @var list<array{0: int, 1: string}>
	 */
	private array $user_can_asked = array();

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
		// handle_actions() acts only on its own screen (#1771).
		$_GET['page'] = 'ffc-scheduling-calendars';

		$this->rows           = array(
			self::SCHEDULE_TABLE   => array(),
			self::ENV_TABLE        => array(),
			self::HOLIDAY_TABLE    => array(),
			self::PERMISSION_TABLE => array(),
		);
		$this->reads          = array();
		$this->writes         = array();
		$this->user_can_asked = array();
		$this->can_reveal     = false;

		// AudienceAccountEmail keeps the reveal nonce for the request; start
		// every test without one, so the nonce printed is this test's stub.
		$nonce = new \ReflectionProperty( AudienceAccountEmail::class, 'nonce' );
		$nonce->setAccessible( true );
		$nonce->setValue( null, null );

		// The Post-event form tab lists the published certificate forms (#1948).
		Functions\when( 'get_posts' )->justReturn( array() );

		Functions\when( '__' )->returnArg();
		// The calendar names partial reads the default state names (#2223).
		Functions\when( '_x' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'esc_textarea' )->returnArg();
		Functions\when( 'esc_html_e' )->alias( function ( $text ) { echo $text; } );
		Functions\when( 'esc_attr_e' )->alias( function ( $text ) { echo $text; } );
		Functions\when( 'absint' )->alias( function ( $v ) { return abs( (int) $v ); } );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'admin_url' )->justReturn( 'https://example.com/wp-admin/' );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_settings_errors' )->justReturn( array() );
		Functions\when( 'wp_nonce_url' )->justReturn( '/' );
		Functions\when( 'wp_trim_words' )->returnArg();
		// Nothing stored in ffc_settings: DateFormatter takes its d/m/Y
		// default, and CacheVersion reads its counter's default.
		Functions\when( 'get_option' )->alias( static fn( $key, $default = false ) => 'ffc_settings' === $key ? array() : $default );
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'wp_date' )->alias(
			static fn( $format, $ts = null, $tz = null ) => ( new \DateTimeImmutable( '@' . (int) $ts ) )->setTimezone( $tz ?? new \DateTimeZone( 'UTC' ) )->format( $format )
		);
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		Functions\when( 'wp_json_encode' )->alias( static fn( $data ) => json_encode( $data ) );
		Functions\when( 'wp_parse_args' )->alias( function ( $args, $defaults = array() ) {
			return array_merge( $defaults, (array) $args );
		} );
		Functions\when( 'sanitize_sql_orderby' )->returnArg();
		Functions\when( 'sanitize_key' )->returnArg();

		// The account email is masked (#1824). Whether a row gets a Reveal
		// button is the real policy's answer: the viewer is never the owner
		// and is no site administrator, so it turns on the audiences PII cap,
		// set per test through $this->can_reveal; the tiers themselves are
		// AudienceAccountEmailTest's.
		Functions\when( 'is_email' )->justReturn( true );
		Functions\when( 'wp_create_nonce' )->justReturn( 'reveal-nonce' );
		Functions\when( 'get_current_user_id' )->justReturn( self::VIEWER );
		Functions\when( 'user_can' )->alias(
			function ( $user, $cap ) {
				$this->user_can_asked[] = array( (int) $user, (string) $cap );
				return $this->can_reveal && AudienceAccountEmail::PII_CAP === $cap;
			}
		);

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
	 * A `$wpdb` that answers the repositories' queries from the stored rows
	 * — `SELECT *` and `SELECT COUNT(*)` filtered by their `col = %x`
	 * conditions, and the by-id `get_row()` — and records insert / update /
	 * delete.
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
					'sql'  => trim( (string) $sql ),
					'args' => array_values( $flat ),
				);
			}
		);
		$wpdb->shouldReceive( 'esc_like' )->andReturnUsing( static fn( $text ) => $text );
		$wpdb->shouldReceive( 'get_results' )->andReturnUsing(
			function ( $q ) {
				$this->reads[] = array( $q['sql'], $q['args'] );
				if ( 0 === strpos( $q['sql'], 'SELECT b.*, e.name as environment_name' ) ) {
					// AudienceBookingReader::get_all() for one day of one calendar.
					return $this->day_bookings;
				}
				if ( 1 === preg_match( '/^SELECT \* FROM %i\s*(?:WHERE (.+?))?\s*(?:ORDER BY [\w ]+)?$/', $q['sql'], $m ) ) {
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
		$wpdb->shouldReceive( 'get_var' )->andReturnUsing(
			function ( $q ) {
				$this->reads[] = array( $q['sql'], $q['args'] );
				if ( false !== strpos( $q['sql'], 'INNER JOIN %i e ON b.environment_id = e.id' ) ) {
					// AudienceBookingReader::count_for_export() by calendar.
					return (string) ( $this->bookings_by_schedule[ (int) end( $q['args'] ) ] ?? 0 );
				}
				if ( 1 === preg_match( '/^SELECT COUNT\(\*\) FROM %i\s*(?:WHERE (.+))?$/', $q['sql'], $m ) ) {
					return (string) count( $this->select( $q['args'], $m[1] ?? '' ) );
				}
				$this->fail( 'Unexpected get_var(): ' . $q['sql'] );
			}
		);
		$wpdb->shouldReceive( 'insert' )->andReturnUsing(
			function ( $table, $data ) use ( $wpdb ) {
				$this->writes[]  = array( 'insert', $table, $data, array() );
				$wpdb->insert_id = 60;
				return 1;
			}
		);
		$wpdb->shouldReceive( 'update' )->andReturnUsing(
			function ( $table, $data, $where ) {
				$this->writes[] = array( 'update', $table, $data, $where );
				return 1;
			}
		);
		$wpdb->shouldReceive( 'query' )->andReturnUsing(
			function ( $q ) {
				// AudienceBookingWriter::cancel(): the conditional UPDATE.
				$this->writes[] = array( 'cancel', (string) $q['args'][0], array( 'reason' => $q['args'][3] ), array( 'id' => $q['args'][4] ) );
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
		$table = (string) array_shift( $args );
		if ( ! isset( $this->rows[ $table ] ) ) {
			$this->fail( 'Unexpected table: ' . $table );
		}
		preg_match_all( '/(\w+) = %[ds]/', $where, $columns );
		$out = array();
		foreach ( $this->rows[ $table ] as $row ) {
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
	 * Store a row.
	 *
	 * @param string               $table Table name.
	 * @param array<string, mixed> $row   Columns; `id` required.
	 */
	private function store( string $table, array $row ): void {
		$this->rows[ $table ][ (int) $row['id'] ] = $row;
	}

	/**
	 * Store a calendar row.
	 *
	 * @param array<string, mixed> $row Columns; `id` required.
	 */
	private function schedule( array $row ): void {
		$this->store(
			self::SCHEDULE_TABLE,
			$row + array(
				'name'              => 'Cal',
				'description'       => '',
				'visibility'        => 'public',
				'status'            => 'active',
				'environment_label' => null,
			)
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
	 * Run the handler until its redirect and return the URL.
	 *
	 * @param AudienceAdminCalendar $page The page.
	 * @return string
	 */
	private function redirect_of( AudienceAdminCalendar $page ): string {
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
		$page = new AudienceAdminCalendar( 'ffc-scheduling' );
		$this->assertInstanceOf( AudienceAdminCalendar::class, $page );
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
			'ffc_action'   => 'save_schedule',
			'ffc_schedule_nonce' => 'n',
		);

		Functions\expect( 'wp_verify_nonce' )->never();

		$page = new AudienceAdminCalendar( 'ffc-scheduling' );
		$page->handle_actions();
	}

	// ==================================================================
	// handle_actions() — with message
	// ==================================================================

	public function test_handle_actions_shows_feedback_message(): void {
		$_GET['message'] = 'created';
		$_GET['page'] = 'ffc-scheduling-calendars';

		// #1030: the name claims a message is shown and nothing checked it —
		// a handler that rendered no feedback at all would have passed.
		$notices = array();
		Functions\when( 'add_settings_error' )->alias(
			static function ( $setting, $code, $message ) use ( &$notices ) {
				$notices[] = $message;
				return true;
			}
		);

		$page = new AudienceAdminCalendar( 'ffc-scheduling' );
		$page->handle_actions();

		$this->assertNotEmpty( $notices, 'handle_actions() surfaced no feedback message' );
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function sibling_screen_messages(): array {
		return array(
			'environments created'  => array( 'ffc-scheduling-environments', 'created' ),
			'audiences deleted'     => array( 'ffc-scheduling-audiences', 'deleted' ),
			'settings holiday'      => array( 'ffc-scheduling-settings', 'holiday_deleted' ),
			'settings deactivated'  => array( 'ffc-scheduling-settings', 'deactivated' ),
		);
	}

	/**
	 * @dataProvider sibling_screen_messages
	 */
	public function test_handle_actions_shows_no_calendar_notice_on_a_sibling_screen( string $screen, string $message ): void {
		// The coordinator runs this handler on every ffc-scheduling* screen,
		// so "Calendar … successfully." and "Holiday deleted successfully."
		// leaked onto Environments, Audiences and Settings (#1771).
		$_GET['page']    = $screen;
		$_GET['message'] = $message;
		$log             = \FreeFormCertificate\Tests\Support\CallLog::record( 'add_settings_error', true );

		( new AudienceAdminCalendar( 'ffc-scheduling' ) )->handle_actions();

		$this->assertSame( 0, $log->count() );
	}

	public function test_handle_actions_ignores_a_sibling_screens_delete(): void {
		// An inactive environment's or audience's Delete link is
		// `action=delete&id=N` too; the calendar handler must not treat it as
		// a calendar deletion — nor refuse it with the calendar's wp_die().
		$_GET = array( 'page' => 'ffc-scheduling-audiences', 'action' => 'delete', 'id' => '5', '_wpnonce' => 'n' );
		Functions\when( 'current_user_can' )->alias(
			static fn( $cap ) => 'ffc_manage_audiences' === $cap
		);
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		$die = \FreeFormCertificate\Tests\Support\CallLog::record( 'wp_die' );
		$this->schedule( array( 'id' => 5, 'status' => 'inactive' ) );

		( new AudienceAdminCalendar( 'ffc-scheduling' ) )->handle_actions();

		$this->assertSame( 0, $die->count() );
		$this->assertSame( array(), $this->reads, 'no calendar was looked up' );
		$this->assertSame( array(), $this->writes, 'no calendar was deleted' );
	}

	// ==================================================================
	// render_page() — default list
	// ==================================================================

	public function test_render_page_renders_list_by_default(): void {
		$page = new AudienceAdminCalendar( 'ffc-scheduling' );
		ob_start();
		$page->render_page();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'wrap', $output );
		$this->assertStringNotContainsString( 'ffc-boxed', $output, 'the list stays a plain list-table screen' );
	}

	public function test_list_title_matches_the_menu_label(): void {
		$page = new AudienceAdminCalendar( 'ffc-scheduling' );
		ob_start();
		$page->render_page();
		$output = ob_get_clean();

		// The submenu item reads "Audience Calendars" (#1787).
		$this->assertStringContainsString( '<h1 class="wp-heading-inline">Audience Calendars</h1>', $output );
	}

	// ==================================================================
	// render_list() — with calendars
	// ==================================================================

	public function test_render_list_shows_calendars(): void {
		$this->schedule( array( 'id' => 1, 'name' => 'Public Cal', 'description' => 'A public one', 'visibility' => 'public', 'status' => 'active' ) );
		$this->schedule( array( 'id' => 2, 'name' => 'Private Cal', 'description' => '', 'visibility' => 'private', 'status' => 'inactive' ) );
		$this->store( self::ENV_TABLE, array( 'id' => 1, 'schedule_id' => 1 ) );
		$this->store( self::ENV_TABLE, array( 'id' => 2, 'schedule_id' => 1 ) );

		$page = new AudienceAdminCalendar( 'ffc-scheduling' );
		ob_start();
		$page->render_page();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Public Cal', $output );
		$this->assertStringContainsString( 'Private Cal', $output );
		$this->assertStringContainsString( 'Public', $output );
		$this->assertStringContainsString( 'Deactivate', $output ); // active calendar.
		$this->assertStringContainsString( 'Delete', $output );     // inactive calendar.
	}

	public function test_render_list_prints_each_calendars_shortcode_with_a_copy_button(): void {
		// Real escaping, so the test sees what the browser would.
		$escape = static fn( $text ) => htmlspecialchars( (string) $text, ENT_QUOTES );
		Functions\when( 'esc_html' )->alias( $escape );
		Functions\when( 'esc_attr' )->alias( $escape );
		$this->schedule( array( 'id' => 7, 'name' => 'Rooms Cal', 'description' => '', 'visibility' => 'public', 'status' => 'active' ) );

		ob_start();
		( new AudienceAdminCalendar( 'ffc-scheduling' ) )->render_page();
		$output = (string) ob_get_clean();

		// #1814: the column the forms list has, drawn by the same helper.
		$this->assertStringContainsString( '<th scope="col" class="column-shortcode">Shortcode</th>', $output );
		$this->assertLessThan( strpos( $output, 'class="column-visibility"' ), strpos( $output, 'class="column-shortcode"' ), 'the shortcode follows the name' );
		$this->assertMatchesRegularExpression(
			'#<td class="column-shortcode">\s*<span class="ffc-shortcode-cell"><code class="ffc-shortcode-code">\[ffc_audience schedule_id=&quot;7&quot;\]</code>'
			. '<button type="button" class="ffc-copy-shortcode" data-shortcode="\[ffc_audience schedule_id=&quot;7&quot;\]" title="Copy shortcode" aria-label="Copy shortcode">#',
			$output
		);
	}

	public function test_empty_calendar_list_spans_every_column(): void {
		ob_start();
		( new AudienceAdminCalendar( 'ffc-scheduling' ) )->render_page();
		$output = (string) ob_get_clean();

		$this->assertSame( 6, substr_count( $output, '<th scope="col"' ) );
		$this->assertStringContainsString( '<td colspan="6">No calendars found.</td>', $output );
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
		$this->schedule( array( 'id' => 1, 'name' => 'Active Cal', 'description' => '', 'visibility' => 'public', 'status' => 'active' ) );
		$this->schedule( array( 'id' => 2, 'name' => 'Inactive Cal', 'description' => '', 'visibility' => 'private', 'status' => 'inactive' ) );

		$page = new AudienceAdminCalendar( 'ffc-scheduling' );
		ob_start();
		$page->render_page();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'Active Cal', $output, 'The rows render for every tier.' );
		$this->assertSame( $can_manage ? 2 : 0, substr_count( $output, 'Edit</a>' ) );
		$this->assertSame( $can_manage, str_contains( $output, 'Deactivate' ) );
		$this->assertSame( $can_delete, str_contains( $output, 'Delete' ) );
	}

	// ==================================================================
	// render_form() — new + edit (full sections)
	// ==================================================================

	public function test_render_form_new_shows_create_button(): void {
		$_GET['action'] = 'new';
		Functions\when( 'wp_nonce_field' )->justReturn( '' );
		Functions\when( 'selected' )->justReturn( '' );
		Functions\when( 'submit_button' )->justReturn( '' );
		Functions\when( 'esc_attr__' )->returnArg();

		$page = new AudienceAdminCalendar( 'ffc-scheduling' );
		ob_start();
		$page->render_page();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'schedule_name', $output );
		$this->assertStringContainsString( 'Notifications', $output );
	}

	public function test_render_form_edit_with_permissions_and_holidays(): void {
		$_GET['action'] = 'edit';
		$_GET['id']     = '5';
		Functions\when( 'wp_nonce_field' )->justReturn( '' );
		Functions\when( 'selected' )->justReturn( '' );
		Functions\when( 'submit_button' )->justReturn( '' );
		Functions\when( 'esc_attr__' )->returnArg();
		Functions\when( 'get_userdata' )->alias(
			static fn( $id ) => (object) array( 'ID' => $id, 'display_name' => 'User' . $id, 'user_email' => $id . '@e.com' )
		);

		$this->schedule(
			array(
				'id'                     => 5,
				'name'                   => 'Cal Five',
				'description'            => 'desc',
				'environment_label'      => 'Rooms',
				'visibility'             => 'public',
				'future_days_limit'      => 30,
				'notify_on_booking'      => 1,
				'notify_on_cancellation' => 0,
				'include_ics'            => 1,
				'show_event_list'        => 1,
				'event_list_position'    => 'below',
				'audience_badge_format'  => 'parent_name',
				'booking_label_singular' => 'session',
				'booking_label_plural'   => 'sessions',
				'is_isolated'            => 1,
				'status'                 => 'active',
			)
		);
		$this->store( self::PERMISSION_TABLE, array( 'id' => 1, 'schedule_id' => 5, 'user_id' => 7, 'can_book' => 1, 'can_cancel_others' => 0, 'can_override_conflicts' => 1 ) );
		$this->store( self::PERMISSION_TABLE, array( 'id' => 2, 'schedule_id' => 6, 'user_id' => 8, 'can_book' => 1, 'can_cancel_others' => 0, 'can_override_conflicts' => 0 ) );
		// The Environments tab (#1761) lists none: the only one is another calendar's.
		$this->store( self::ENV_TABLE, array( 'id' => 1, 'schedule_id' => 6, 'name' => 'Not Here', 'status' => 'active', 'color' => '#fff', 'description' => '' ) );
		$this->store( self::HOLIDAY_TABLE, array( 'id' => 3, 'schedule_id' => 5, 'holiday_date' => '2026-12-25', 'description' => 'Christmas' ) );
		$this->store( self::HOLIDAY_TABLE, array( 'id' => 4, 'schedule_id' => 6, 'holiday_date' => '2026-01-01', 'description' => 'Elsewhere' ) );

		$page = new AudienceAdminCalendar( 'ffc-scheduling' );
		ob_start();
		$page->render_page();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Cal Five', $output );
		$this->assertStringContainsString( 'User7', $output );
		$this->assertStringContainsString( 'Christmas', $output );
		$this->assertStringContainsString( '25/12/2026', $output );
		foreach ( array( 'User8', 'Not Here', 'Elsewhere' ) as $other_calendars ) {
			$this->assertStringNotContainsString( $other_calendars, $output, 'only this calendar\'s rows are listed' );
		}

		// #1761: a boxed screen of vertical tabs, each panel titled with its
		// icon, and the Save and Shortcode cards beside them.
		$this->assertStringContainsString( 'ffc-page-scheduling-calendars ffc-boxed', $output );
		preg_match_all( '/<section id="ffc-tabpanel-([a-z-]+)"/', $output, $panels );
		$this->assertSame( array( 'general', 'notifications', 'event-list', 'post-event', 'environments', 'user-access', 'closed-dates' ), $panels[1] );
		preg_match_all( '/<a href="#ffc-tab-([a-z-]+)" id="ffc-tabnav-\1"/', $output, $nav );
		$this->assertSame( $panels[1], $nav[1], 'one tab per panel, in order' );
		foreach ( array( 'calendar" aria-hidden="true"></span>General', 'email" aria-hidden="true"></span>Notifications', 'list" aria-hidden="true"></span>Event list & badges', 'building" aria-hidden="true"></span>Rooms', 'users" aria-hidden="true"></span>User access', 'lock" aria-hidden="true"></span>Closed dates' ) as $title ) {
			$this->assertStringContainsString( '<h2 class="ffc-vtabs__panel-title"><span class="ffc-vtabs__icon ffc-icon-' . $title . '</h2>', $output );
		}
		$this->assertStringContainsString( '<section id="ffc-tabpanel-general" class="ffc-vtabs__panel is-active"', $output, 'General opens first' );
		$this->assertMatchesRegularExpression( '/<div class="ffc-aud-editor__side">\s*<div class="card">\s*<h2 class="ffc-icon-save">Save<\/h2>/', $output );
		$this->assertStringContainsString( '<h2 class="ffc-icon-code">Shortcode</h2>', $output );
		// The card draws the list column's cell, copy button included (#1814).
		$this->assertMatchesRegularExpression(
			'#<h2 class="ffc-icon-code">Shortcode</h2>\s*<span class="ffc-shortcode-cell"><code class="ffc-shortcode-code">\[ffc_audience schedule_id="5"\]</code>'
			. '<button type="button" class="ffc-copy-shortcode" data-shortcode="\[ffc_audience schedule_id="5"\]" title="Copy shortcode" aria-label="Copy shortcode">#',
			$output
		);

		// One form across the first three panels; the other three outside it.
		$form_open  = (int) strpos( $output, '<form method="post" action="" class="ffc-form" id="ffc-schedule-form">' );
		$form_close = (int) strpos( $output, '</form>', $form_open );
		foreach ( array( 'general', 'notifications', 'event-list' ) as $inside ) {
			$at = (int) strpos( $output, 'id="ffc-tabpanel-' . $inside . '"' );
			$this->assertTrue( $at > $form_open && $at < $form_close, $inside . ' sits inside the calendar form' );
		}
		foreach ( array( 'environments', 'user-access', 'closed-dates' ) as $outside ) {
			$this->assertGreaterThan( $form_close, (int) strpos( $output, 'id="ffc-tabpanel-' . $outside . '"' ), $outside . ' sits after it' );
		}
		$this->assertSame( substr_count( $output, '<div' ), substr_count( $output, '</div>' ), 'every box is closed' );
		$this->assertSame( substr_count( $output, '<section' ), substr_count( $output, '</section>' ), 'every panel is closed' );
		$this->assertStringNotContainsString( '<hr>', $output, 'boxes replace the separators' );
		unset( $_GET['action'], $_GET['id'] );
	}

	public function test_render_form_edit_no_permissions_no_holidays(): void {
		$_GET['action'] = 'edit';
		$_GET['id']     = '5';
		Functions\when( 'wp_nonce_field' )->justReturn( '' );
		Functions\when( 'selected' )->justReturn( '' );
		Functions\when( 'submit_button' )->justReturn( '' );
		Functions\when( 'esc_attr__' )->returnArg();

		$this->schedule( array( 'id' => 5, 'name' => 'Empty Cal', 'status' => 'active', 'environment_label' => 'Rooms' ) );

		$page = new AudienceAdminCalendar( 'ffc-scheduling' );
		ob_start();
		$page->render_page();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'No users have been granted access yet', $output );
		$this->assertStringContainsString( 'No holidays defined yet', $output );
		unset( $_GET['action'], $_GET['id'] );
	}

	public function test_render_form_missing_calendar_dies(): void {
		$_GET['action'] = 'edit';
		$_GET['id']     = '99';
		Functions\when( 'wp_die' )->alias( static function ( $m ) { throw new \RuntimeException( (string) $m ); } );

		$page = new AudienceAdminCalendar( 'ffc-scheduling' );
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Calendar not found' );
		// render_page() echoes the admin page wrapper before it detects the
		// missing record and calls wp_die(); capture and discard that output
		// so the markup doesn't leak into PHPUnit's stdout (the assertion is
		// on the wp_die exception, not the buffer).
		ob_start();
		try {
			$page->render_page();
		} finally {
			ob_end_clean();
			unset( $_GET['action'], $_GET['id'] );
		}
	}

	// ==================================================================
	// handle_actions() — save
	// ==================================================================

	public function test_handle_actions_creates_calendar_and_redirects(): void {
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		Functions\when( 'sanitize_textarea_field' )->returnArg();
		Functions\when( 'admin_url' )->returnArg();

		$_POST = array(
			'ffc_action'                => 'save_schedule',
			'ffc_schedule_nonce'        => 'n',
			'schedule_id'               => '0',
			'schedule_name'             => 'New Cal',
			'schedule_visibility'       => 'public',
			'schedule_event_list_position' => 'below',
			'schedule_audience_badge_format' => 'parent_name',
			'schedule_booking_label_singular' => 'evt',
			'schedule_notify_booking'   => '1',
			'schedule_is_isolated'      => '1',
			'schedule_status'           => 'active',
			'schedule_names'            => array(
				'legend'            => array( 'closed' => 'Sem expediente' ),
				'show_closed_label' => 'hide',
			),
		);
		Functions\when( 'wp_json_encode' )->alias( static fn( $value ) => json_encode( $value ) );

		// The double answers every insert with id 60.
		$page = new AudienceAdminCalendar( 'ffc-scheduling' );
		$this->assertSame( 'admin.php?page=ffc-scheduling-calendars&action=edit&id=60&message=created', $this->redirect_of( $page ) );

		$inserts = $this->writes_of( 'insert' );
		$this->assertCount( 1, $inserts );
		$this->assertSame( self::SCHEDULE_TABLE, $inserts[0][1] );
		$row = $inserts[0][2];
		$this->assertSame( 'New Cal', $row['name'] );
		$this->assertSame( 'public', $row['visibility'] );
		$this->assertSame( 1, $row['notify_on_booking'] );
		$this->assertSame( 0, $row['notify_on_cancellation'] );
		$this->assertSame( 1, $row['is_isolated'] );
		// The display settings reach the row on creation too (#2175).
		$this->assertSame( 0, $row['show_event_list'] );
		$this->assertSame( 'below', $row['event_list_position'] );
		$this->assertSame( 'parent_name', $row['audience_badge_format'] );
		$this->assertSame( 'evt', $row['booking_label_singular'] );
		$this->assertArrayHasKey( 'booking_label_plural', $row );
		// The calendar's own names are stored as JSON (#2223).
		$this->assertSame( '{"legend":{"closed":"Sem expediente"},"cell":[],"show_closed_label":"hide"}', $row['appearance_names'] );
	}

	public function test_handle_actions_updates_calendar(): void {
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		Functions\when( 'sanitize_textarea_field' )->returnArg();
		Functions\when( 'add_settings_error' )->justReturn( true );

		$_POST = array(
			'ffc_action'         => 'save_schedule',
			'ffc_schedule_nonce' => 'n',
			'schedule_id'        => '5',
			'schedule_name'      => 'Edited Cal',
			// omit optional toggles -> defaults exercised.
		);

		$page = new AudienceAdminCalendar( 'ffc-scheduling' );
		$page->handle_actions();

		$updates = $this->writes_of( 'update' );
		$this->assertCount( 1, $updates );
		$this->assertSame( self::SCHEDULE_TABLE, $updates[0][1] );
		$this->assertSame( array( 'id' => 5 ), $updates[0][3] );
		$row = $updates[0][2];
		$this->assertSame( 'Edited Cal', $row['name'] );
		$this->assertSame( 'side', $row['event_list_position'] );
		$this->assertSame( 'name', $row['audience_badge_format'] );
		$this->assertArrayHasKey( 'booking_label_singular', $row );
		$this->assertNull( $row['booking_label_singular'] );		$this->assertNull( $row['appearance_names'], 'No names posted, no override stored.' );
	}

	public function test_handle_actions_save_aborts_on_bad_nonce(): void {
		Functions\when( 'wp_verify_nonce' )->justReturn( false );
		$_POST = array( 'ffc_action' => 'save_schedule', 'ffc_schedule_nonce' => 'bad' );

		$page = new AudienceAdminCalendar( 'ffc-scheduling' );
		$page->handle_actions();
		$this->assertSame( array(), $this->writes );
	}

	// ==================================================================
	// handle_actions() — deactivate / delete
	// ==================================================================

	public function test_handle_actions_deactivates_calendar(): void {
		$_GET = array( 'page' => 'ffc-scheduling-calendars', 'action' => 'deactivate', 'id' => '5', '_wpnonce' => 'n' );
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		Functions\when( 'admin_url' )->returnArg();

		$page = new AudienceAdminCalendar( 'ffc-scheduling' );
		$this->assertStringContainsString( 'message=deactivated', $this->redirect_of( $page ) );
		$this->assertSame( array( array( 'update', self::SCHEDULE_TABLE, array( 'status' => 'inactive' ), array( 'id' => 5 ) ) ), $this->writes );
	}

	public function test_handle_actions_deletes_inactive_calendar(): void {
		$_GET = array( 'page' => 'ffc-scheduling-calendars', 'action' => 'delete', 'id' => '5', '_wpnonce' => 'n' );
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		Functions\when( 'admin_url' )->returnArg();
		$this->schedule( array( 'id' => 5, 'status' => 'inactive' ) );

		$page = new AudienceAdminCalendar( 'ffc-scheduling' );
		$this->assertStringContainsString( 'message=deleted', $this->redirect_of( $page ) );
		$this->assertSame( array( array( 'delete', self::SCHEDULE_TABLE, array(), array( 'id' => 5 ) ) ), $this->writes );
	}

	/**
	 * A calendar with spaces or bookings stays, as a space with bookings does (#2243).
	 */
	public function test_a_calendar_with_environments_or_bookings_is_not_deleted(): void {
		$_GET = array( 'page' => 'ffc-scheduling-calendars', 'action' => 'delete', 'id' => '5', '_wpnonce' => 'n' );
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		Functions\when( 'admin_url' )->returnArg();
		$this->schedule( array( 'id' => 5, 'status' => 'inactive' ) );
		$this->store( self::ENV_TABLE, array( 'id' => 9, 'schedule_id' => 5 ) );
		$this->bookings_by_schedule = array( 5 => 3 );

		$page     = new AudienceAdminCalendar( 'ffc-scheduling' );
		$redirect = $this->redirect_of( $page );

		$this->assertStringContainsString( 'message=in_use&environments=1&bookings=3', $redirect );
		$this->assertSame( array(), $this->writes );
	}

	public function test_a_calendar_with_only_bookings_is_not_deleted(): void {
		$_GET = array( 'page' => 'ffc-scheduling-calendars', 'action' => 'delete', 'id' => '5', '_wpnonce' => 'n' );
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		Functions\when( 'admin_url' )->returnArg();
		$this->schedule( array( 'id' => 5, 'status' => 'inactive' ) );
		$this->bookings_by_schedule = array( 5 => 1 );

		$this->assertStringContainsString( 'message=in_use&environments=0&bookings=1', $this->redirect_of( new AudienceAdminCalendar( 'ffc-scheduling' ) ) );
		$this->assertSame( array(), $this->writes );
	}

	public function test_handle_actions_delete_denied_without_cap(): void {
		$_GET = array( 'page' => 'ffc-scheduling-calendars', 'action' => 'delete', 'id' => '5' );
		Functions\when( 'current_user_can' )->alias(
			static fn( $cap ) => 'ffc_manage_audiences' === $cap
		);
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		Functions\when( 'wp_die' )->alias( static function ( $m ) { throw new \RuntimeException( (string) $m ); } );

		$page = new AudienceAdminCalendar( 'ffc-scheduling' );
		try {
			$page->handle_actions();
			$this->fail( 'handle_actions() did not die.' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'permission to delete', $e->getMessage() );
		}
		$this->assertSame( array(), $this->writes );
	}

	// ==================================================================
	// handle_actions() — holidays
	// ==================================================================

	public function test_handle_actions_adds_holiday(): void {
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		Functions\when( 'add_settings_error' )->justReturn( true );

		$_POST = array(
			'ffc_action'          => 'add_holiday',
			'ffc_holiday_nonce'   => 'n',
			'schedule_id'         => '5',
			'holiday_date'        => '2026-12-25',
			'holiday_description' => 'Xmas',
			'holiday_kind'        => 'optional',
		);

		$page = new AudienceAdminCalendar( 'ffc-scheduling' );
		$page->handle_actions();

		$this->assertSame(
			array(
				array(
					'insert',
					self::HOLIDAY_TABLE,
					array(
						'schedule_id'  => 5,
						'holiday_date' => '2026-12-25',
						'description'  => 'Xmas',
						'kind'         => 'optional',
						'created_by'   => self::VIEWER,
					),
					array(),
				),
			),
			$this->writes
		);
	}

	/**
	 * A closed date is posted to the calendar's Closed dates tab.
	 *
	 * @param array<string, string> $extra More POST fields.
	 */
	private function post_holiday( array $extra = array() ): void {
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		$_POST = array_merge(
			array(
				'ffc_action'          => 'add_holiday',
				'ffc_holiday_nonce'   => 'n',
				'schedule_id'         => '5',
				'holiday_date'        => '2026-12-25',
				'holiday_description' => 'Xmas',
				'holiday_kind'        => 'holiday',
			),
			$extra
		);
	}

	public function test_a_date_the_calendar_already_closes_is_refused_with_a_message(): void {
		$this->store( self::HOLIDAY_TABLE, array( 'id' => 3, 'schedule_id' => 5, 'holiday_date' => '2026-12-25' ) );
		$notices = array();
		Functions\when( 'add_settings_error' )->alias( static function ( ...$args ) use ( &$notices ) { $notices[] = $args; } );
		$this->post_holiday();

		( new AudienceAdminCalendar( 'ffc-scheduling' ) )->handle_actions();

		$this->assertSame( array(), $this->writes );
		$this->assertSame( 'error', $notices[0][3] );
		$this->assertStringContainsString( 'already closes', $notices[0][2] );
	}

	public function test_a_day_with_bookings_waits_for_confirmation_and_saves_nothing(): void {
		\FreeFormCertificate\Audience\AudienceClosedDay::reset();
		$this->day_bookings = array( (object) array( 'id' => 41, 'environment_id' => 9, 'booking_date' => '2026-12-25', 'start_time' => '09:00:00', 'end_time' => '10:00:00', 'environment_name' => 'Room', 'description' => 'Class' ) );
		$this->post_holiday();

		( new AudienceAdminCalendar( 'ffc-scheduling' ) )->handle_actions();

		$this->assertSame( array(), $this->writes, 'Nothing is saved or cancelled before the confirmation.' );
		$pending = \FreeFormCertificate\Audience\AudienceClosedDay::pending();
		$this->assertNotNull( $pending );
		$this->assertSame( '2026-12-25', $pending['date'] );
		$this->assertCount( 1, $pending['bookings'] );
		$this->assertSame( 'add_holiday', $pending['fields']['ffc_action'] );
		\FreeFormCertificate\Audience\AudienceClosedDay::reset();
	}

	public function test_the_confirmation_lists_the_bookings_and_posts_the_form_again(): void {
		Functions\when( '_n' )->alias( static fn( $single, $plural, $n ) => 1 === $n ? $single : $plural );
		Functions\when( 'wp_date' )->alias( static fn( $format, $ts ) => gmdate( $format, $ts ) );
		\FreeFormCertificate\Audience\AudienceClosedDay::hold(
			'2026-12-25',
			'holiday',
			'Xmas',
			array( (object) array( 'id' => 41, 'environment_id' => 9, 'booking_date' => '2026-12-25', 'start_time' => '09:00:00', 'end_time' => '10:00:00', 'environment_name' => 'Room A', 'description' => 'Weekly class' ) ),
			array( 'ffc_action' => 'add_holiday', 'holiday_date' => '2026-12-25' )
		);

		ob_start();
		\FreeFormCertificate\Audience\AudienceClosedDay::render_pending();
		$html = (string) ob_get_clean();
		\FreeFormCertificate\Audience\AudienceClosedDay::reset();

		$this->assertStringContainsString( 'will be cancelled and its participants e-mailed', $html );
		$this->assertStringContainsString( '09:00–10:00', $html );
		$this->assertStringContainsString( 'Room A', $html );
		$this->assertStringContainsString( 'name="ffc_action" value="add_holiday"', $html );
		$this->assertStringContainsString( 'name="ffc_confirm_close_day" value="1"', $html );
		$this->assertStringNotContainsString( 'style=', $html );
	}

	public function test_a_confirmed_closure_saves_the_date_and_cancels_each_booking(): void {
		\FreeFormCertificate\Audience\AudienceClosedDay::reset();
		$this->day_bookings = array(
			(object) array( 'id' => 41 ),
			(object) array( 'id' => 42 ),
		);
		$cancelled = \FreeFormCertificate\Tests\Support\CallLog::record( 'do_action' );
		$notices   = array();
		Functions\when( 'add_settings_error' )->alias( static function ( ...$args ) use ( &$notices ) { $notices[] = $args; } );
		Functions\when( 'current_time' )->justReturn( '2026-12-20 10:00:00' );
		Functions\when( '_n' )->alias( static fn( $single, $plural, $n ) => 1 === $n ? $single : $plural );
		$this->post_holiday( array( \FreeFormCertificate\Audience\AudienceClosedDay::CONFIRM_FIELD => '1' ) );

		( new AudienceAdminCalendar( 'ffc-scheduling' ) )->handle_actions();

		$this->assertSame( array( 'insert', 'cancel', 'cancel' ), array_column( $this->writes, 0 ) );
		$this->assertSame( array( 'reason' => 'Closed day: Xmas' ), $this->writes[1][2] );
		$this->assertSame( array( 'id' => 42 ), $this->writes[2][3] );
		$this->assertSame( 2, $cancelled->count(), 'Each cancelled booking fires the hook that e-mails its participants.' );
		$this->assertStringContainsString( 'were cancelled', $notices[0][2] );
	}

	public function test_handle_actions_deletes_holiday_and_redirects(): void {
		$_GET = array( 'page' => 'ffc-scheduling-calendars', 'delete_holiday' => '3', 'id' => '5', '_wpnonce' => 'n' );
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		Functions\when( 'admin_url' )->returnArg();

		$page = new AudienceAdminCalendar( 'ffc-scheduling' );
		$this->assertStringContainsString( 'message=holiday_deleted', $this->redirect_of( $page ) );
		$this->assertSame( array( array( 'delete', self::HOLIDAY_TABLE, array(), array( 'id' => 3 ) ) ), $this->writes );
	}

	/**
	 * Post the edit form of holiday 3 on calendar 5 (#2212).
	 *
	 * @param array<string, string> $fields
	 */
	private function post_holiday_edit( array $fields ): void {
		$_GET  = array( 'page' => 'ffc-scheduling-calendars' );
		$_POST = array_merge(
			array(
				'ffc_action'               => 'edit_holiday',
				'ffc_holiday_edit_nonce'   => 'n',
				'holiday_id'               => '3',
				'schedule_id'              => '5',
				'holiday_edit_date'        => '2026-12-24',
				'holiday_edit_description' => 'Eve',
				'holiday_edit_kind'        => 'optional',
			),
			$fields
		);
	}

	public function test_handle_actions_edits_a_holiday_and_lands_on_the_closed_dates_tab(): void {
		$this->store( self::HOLIDAY_TABLE, array( 'id' => 3, 'schedule_id' => 5, 'holiday_date' => '2026-12-25', 'description' => 'Christmas' ) );
		Functions\when( 'wp_verify_nonce' )->alias( static fn( $nonce, $action ) => 'edit_holiday_3' === $action ? 1 : false );
		Functions\when( 'admin_url' )->returnArg();
		$this->post_holiday_edit( array() );

		$page = new AudienceAdminCalendar( 'ffc-scheduling' );
		$this->assertStringContainsString( 'message=holiday_updated', $this->redirect_of( $page ) );
		$this->assertSame(
			array(
				array(
					'update',
					self::HOLIDAY_TABLE,
					array( 'holiday_date' => '2026-12-24', 'description' => 'Eve', 'kind' => 'optional' ),
					array( 'id' => 3, 'schedule_id' => 5 ),
				),
			),
			$this->writes
		);
	}

	public function test_handle_actions_does_not_edit_another_calendars_holiday(): void {
		$this->store( self::HOLIDAY_TABLE, array( 'id' => 3, 'schedule_id' => 9, 'holiday_date' => '2026-12-25', 'description' => 'Christmas' ) );
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		Functions\when( 'admin_url' )->returnArg();
		$this->post_holiday_edit( array() );

		$page = new AudienceAdminCalendar( 'ffc-scheduling' );
		$this->assertStringContainsString( 'message=holiday_not_updated', $this->redirect_of( $page ) );
		$this->assertSame( array(), $this->writes );
	}

	public function test_handle_actions_does_not_edit_a_holiday_without_its_nonce(): void {
		$this->store( self::HOLIDAY_TABLE, array( 'id' => 3, 'schedule_id' => 5, 'holiday_date' => '2026-12-25', 'description' => 'Christmas' ) );
		Functions\when( 'wp_verify_nonce' )->alias( static fn( $nonce, $action ) => 'edit_holiday_4' === $action ? 1 : false );
		$this->post_holiday_edit( array() );

		$page = new AudienceAdminCalendar( 'ffc-scheduling' );
		$page->handle_actions();

		$this->assertSame( array(), $this->writes );
	}

	// ==================================================================
	// #1761 — Environments as the page's second view
	// ==================================================================

	/**
	 * The page as the coordinator builds it: hosting the Environments view.
	 */
	private function page_with_environments(): AudienceAdminCalendar {
		return new AudienceAdminCalendar( 'ffc-scheduling', new \FreeFormCertificate\Audience\AudienceAdminEnvironment( 'ffc-scheduling' ) );
	}

	public function test_calendars_list_prints_the_view_switch_and_links_each_count(): void {
		Functions\when( 'admin_url' )->returnArg();
		Functions\when( 'esc_attr_e' )->justReturn( null );
		$_GET = array( 'page' => 'ffc-scheduling-calendars' );
		$this->schedule( array( 'id' => 4, 'name' => 'Rooms Cal', 'description' => '', 'visibility' => 'public', 'status' => 'active' ) );
		// Three environments on calendar 4, one on another calendar.
		foreach ( array( 1 => 4, 2 => 4, 3 => 4, 4 => 9 ) as $env_id => $schedule_id ) {
			$this->store( self::ENV_TABLE, array( 'id' => $env_id, 'schedule_id' => $schedule_id ) );
		}

		ob_start();
		$this->page_with_environments()->render_page();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( '<div class="wrap ffc-admin-page ffc-page-scheduling-calendars ">', $output );
		// The switch spans every calendar, so it names the view with the default label.
		$this->assertMatchesRegularExpression( '/<ul class="subsubsub">.*href="admin\.php\?page=ffc-scheduling-calendars" class="current" aria-current="page">Calendars<\/a> \|.*href="admin\.php\?page=ffc-scheduling-calendars&view=environments">Environments<\/a>/s', $output );
		$this->assertMatchesRegularExpression( '/<td class="column-environments">\s*<a href="admin\.php\?page=ffc-scheduling-calendars&view=environments&schedule_id=4">3<\/a>/', $output );
		$this->assertStringNotContainsString( 'ffc-environments-table', $output );
	}

	/**
	 * Requests that show the Environments view: the view itself, and the
	 * previous slug when it is rendered (a POST from a form opened before
	 * the move; a GET is redirected before it renders).
	 *
	 * @return array<string, array{0: array<string, string>}>
	 */
	public function environments_requests(): array {
		return array(
			'Environments view' => array( array( 'page' => 'ffc-scheduling-calendars', 'view' => 'environments' ) ),
			'previous slug'     => array( array( 'page' => 'ffc-scheduling-environments' ) ),
		);
	}

	/**
	 * @dataProvider environments_requests
	 *
	 * @param array<string, string> $location The page (and view) of the request.
	 */
	public function test_environments_list_renders_inside_the_calendars_wrap( array $location ): void {
		Functions\when( 'admin_url' )->returnArg();
		Functions\when( 'selected' )->justReturn( '' );
		Functions\when( 'submit_button' )->justReturn( null );
		$_GET = $location;

		ob_start();
		$this->page_with_environments()->render_page();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( '<div class="wrap ffc-admin-page ffc-page-scheduling-calendars ">', $output );
		$this->assertStringContainsString( 'ffc-environments-table', $output );
		$this->assertStringContainsString( 'href="admin.php?page=ffc-scheduling-calendars&view=environments" class="current" aria-current="page">Environments</a>', $output );
		$this->assertSame( 1, substr_count( $output, 'class="wrap' ), 'one wrap: the view prints none of its own' );
		$this->assertSame( substr_count( $output, '<div' ), substr_count( $output, '</div>' ), 'every box is closed' );
	}

	public function test_environment_form_is_a_boxed_screen_of_the_calendars_page(): void {
		Functions\when( 'admin_url' )->returnArg();
		Functions\when( 'selected' )->justReturn( '' );
		Functions\when( 'submit_button' )->justReturn( null );
		Functions\when( 'wp_nonce_field' )->justReturn( '' );
		$_GET = array(
			'page'   => 'ffc-scheduling-calendars',
			'view'   => 'environments',
			'action' => 'new',
		);
		$this->schedule( array( 'id' => 1, 'name' => 'Cal' ) );

		ob_start();
		$this->page_with_environments()->render_page();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( '<div class="wrap ffc-admin-page ffc-page-scheduling-calendars ffc-boxed">', $output );
		$this->assertStringContainsString( 'name="ffc_action" value="save_environment"', $output );
		$this->assertStringContainsString( 'href="admin.php?page=ffc-scheduling-calendars&view=environments">&larr;', $output, 'Back goes to the view' );
		$this->assertStringNotContainsString( 'schedule_name', $output, 'the calendar form is not drawn' );
	}

	public function test_handle_actions_leaves_the_environments_view_to_its_handler(): void {
		// `action=deactivate`, `id` and `message` mean an environment here.
		$_GET    = array(
			'page'     => 'ffc-scheduling-calendars',
			'view'     => 'environments',
			'action'   => 'deactivate',
			'id'       => '5',
			'_wpnonce' => 'n',
			'message'  => 'deleted',
		);
		$nonces  = \FreeFormCertificate\Tests\Support\CallLog::record( 'wp_verify_nonce', 1 );
		$notices = \FreeFormCertificate\Tests\Support\CallLog::record( 'add_settings_error', true );

		// The page's own handler only; the hosted view's handler is the
		// coordinator's to call, and it is AudienceAdminEnvironmentTest's.
		$this->page_with_environments()->handle_actions();
		$this->assertSame( 0, $nonces->count(), 'no calendar action was attempted' );
		$this->assertSame( 0, $notices->count(), 'no calendar notice was raised' );
		$this->assertSame( array(), $this->writes, 'no calendar was deactivated' );
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
		$_GET['id']     = '5';
		Functions\when( 'wp_nonce_field' )->justReturn( '' );
		Functions\when( 'selected' )->justReturn( '' );
		Functions\when( 'esc_attr__' )->returnArg();
		Functions\when( 'get_userdata' )->alias(
			static fn( $id ) => (object) array( 'ID' => $id, 'display_name' => 'User' . $id, 'user_email' => $id . '@e.com' )
		);

		$this->schedule( array( 'id' => 5, 'name' => 'Cal Five', 'status' => 'active', 'visibility' => 'public', 'environment_label' => 'Rooms' ) );
		$this->store( self::PERMISSION_TABLE, array( 'id' => 1, 'schedule_id' => 5, 'user_id' => 7, 'can_book' => '1', 'can_cancel_others' => '0', 'can_override_conflicts' => '1' ) );
		$this->store( self::HOLIDAY_TABLE, array( 'id' => 3, 'schedule_id' => 5, 'holiday_date' => '2026-12-25', 'description' => 'Christmas' ) );

		$page = new AudienceAdminCalendar( 'ffc-scheduling' );
		ob_start();
		$page->render_page();
		$output = (string) ob_get_clean();

		// Every tier sees the record, its grants and its holidays.
		$this->assertStringContainsString( 'Cal Five', $output );
		$this->assertStringContainsString( 'User7', $output );
		$this->assertStringContainsString( 'Christmas', $output );

		// The calendar form: fields locked and no save without manage.
		$this->assertSame( ! $can_manage, str_contains( $output, 'Read-only — you can view this page' ) );
		$this->assertSame( $can_manage ? 0 : 1, substr_count( $output, '<fieldset disabled class="ffc-readonly-lock">' ) );
		// The closed-date type controls are fieldsets of their own (#2202): the
		// add form's and each row's edit form (#2212), drawn only for the
		// manage tier; they are not the lock.
		// The calendar names' closed-label choice is a segmented fieldset inside
		// the form (#2223); it is not the lock either.
		$this->assertSame( 1, substr_count( $output, '<fieldset id="schedule_names_show_closed_label"' ) );
		$this->assertSame( $can_manage ? 0 : 1, substr_count( $output, '</fieldset>' ) - substr_count( $output, '<fieldset id="holiday_' ) - 1 );
		$this->assertSame( $can_manage, str_contains( $output, 'data-ffc-holiday-edit="cal-3"' ), 'Only the manage tier gets the edit row.' );
		$this->assertSame( $can_manage, str_contains( $output, 'value="Update Calendar" form="ffc-schedule-form">Update Calendar</button>' ) );
		if ( ! $can_manage ) {
			$open  = (int) strpos( $output, '<fieldset disabled' );
			// The lock's own close is the last one: the names fieldset nests inside it.
			$close = (int) strrpos( $output, '</fieldset>' );
			foreach ( array( 'name="schedule_name"', 'name="schedule_visibility"', 'name="schedule_notify_booking"', 'name="schedule_admin_emails"', 'name="schedule_status"' ) as $field ) {
				$at = (int) strpos( $output, $field );
				$this->assertTrue( $at > $open && $at < $close, $field . ' sits inside the lock' );
			}
		}

		// User Access & Permissions: no search, add, toggle wiring or Remove.
		foreach ( array( 'id="ffc-user-search"', 'id="ffc-add-user-btn"', 'id="ffc-permissions-table"', 'ffc-perm-toggle', 'ffc-remove-user-btn' ) as $control ) {
			$this->assertSame( $can_manage, str_contains( $output, $control ), $control );
		}
		$this->assertSame( ! $can_manage, str_contains( $output, 'ffc-permissions-read-only' ) );
		if ( ! $can_manage ) {
			$this->assertSame( 3, preg_match_all( '/name="ffc_perm_7_[a-z_]+" value="1"( checked)? disabled/', $output ), 'each grant is a disabled toggle' );
			$this->assertSame( 2, preg_match_all( '/name="ffc_perm_7_[a-z_]+" value="1" checked disabled/', $output ), 'the stored grants stay visible' );
		}

		// Holidays: no add form and no Delete.
		$this->assertSame( $can_manage, str_contains( $output, 'ffc-holiday-form' ) );
		$this->assertSame( $can_manage, str_contains( $output, 'name="submit" value="Add Holiday">Add Holiday</button>' ) );
		$this->assertSame( $can_manage, str_contains( $output, 'Delete this holiday?' ) );
		$this->assertSame( substr_count( $output, '<div' ), substr_count( $output, '</div>' ), 'every box is closed' );

		// The Save card (#1761) exists only where the button did.
		$this->assertSame( $can_manage, str_contains( $output, '<h2 class="ffc-icon-save">Save</h2>' ) );
		if ( ! $can_manage ) {
			// The lock spans the three panels of the calendar form.
			$open  = (int) strpos( $output, '<fieldset disabled' );
			// The lock's own close is the last one: the names fieldset nests inside it.
			$close = (int) strrpos( $output, '</fieldset>' );
			foreach ( array( 'general', 'notifications', 'event-list' ) as $panel ) {
				$at = (int) strpos( $output, 'id="ffc-tabpanel-' . $panel . '"' );
				$this->assertTrue( $at > $open && $at < $close, $panel . ' sits inside the lock' );
			}
		}
	}

	/**
	 * Which tab the editor opens: `?tab=` when it names a tab, Closed dates
	 * after a holiday is added or deleted, else General.
	 *
	 * @return array<string, array{0: array<string, string>, 1: array<string, string>, 2: string}>
	 */
	public function opening_tabs(): array {
		return array(
			'plain edit URL'           => array( array(), array(), 'general' ),
			'?tab= names a tab'        => array( array( 'tab' => 'user-access' ), array(), 'user-access' ),
			'?tab= names no tab'       => array( array( 'tab' => 'nope' ), array(), 'general' ),
			'holiday deleted redirect' => array( array( 'message' => 'holiday_deleted' ), array(), 'closed-dates' ),
			'add-holiday POST'         => array( array(), array( 'ffc_action' => 'add_holiday' ), 'closed-dates' ),
			'save POST'                => array( array(), array( 'ffc_action' => 'save_schedule' ), 'general' ),
		);
	}

	/**
	 * @dataProvider opening_tabs
	 *
	 * @param array<string, string> $get    Query arguments.
	 * @param array<string, string> $post   Posted fields.
	 * @param string                $opened Tab expected open.
	 */
	public function test_editor_opens_the_tab_the_request_names( array $get, array $post, string $opened ): void {
		$_GET  = array_merge( array( 'page' => 'ffc-scheduling-calendars', 'action' => 'edit', 'id' => '5' ), $get );
		$_POST = $post;
		Functions\when( 'wp_nonce_field' )->justReturn( '' );
		Functions\when( 'selected' )->justReturn( '' );
		Functions\when( 'submit_button' )->justReturn( '' );
		Functions\when( 'esc_attr__' )->returnArg();
		$this->schedule( array( 'id' => 5, 'name' => 'Cal', 'status' => 'active' ) );

		$page = new AudienceAdminCalendar( 'ffc-scheduling' );
		ob_start();
		$page->render_page();
		$output = (string) ob_get_clean();
		$_POST  = array();

		preg_match_all( '/<section id="ffc-tabpanel-([a-z-]+)" class="ffc-vtabs__panel is-active"/', $output, $open );
		$this->assertSame( array( $opened ), $open[1] );
		preg_match_all( '/aria-selected="true"/', $output, $selected );
		$this->assertCount( 1, $selected[0], 'one tab selected' );
	}

	public function test_new_calendar_has_the_four_form_tabs_and_a_save_card(): void {
		$_GET['action'] = 'new';
		Functions\when( 'wp_nonce_field' )->justReturn( '' );
		Functions\when( 'selected' )->justReturn( '' );
		Functions\when( 'esc_attr__' )->returnArg();

		$page = new AudienceAdminCalendar( 'ffc-scheduling' );
		ob_start();
		$page->render_page();
		$output = (string) ob_get_clean();

		// No calendar exists yet, so nothing is looked up: no label for an
		// Environments tab, no grants, no holidays.
		$this->assertSame( array(), $this->reads, 'the new-calendar form reads nothing from the database' );

		preg_match_all( '/<section id="ffc-tabpanel-([a-z-]+)"/', $output, $panels );
		$this->assertSame( array( 'general', 'notifications', 'event-list', 'post-event' ), $panels[1], 'the sections that need a saved calendar wait for one' );
		$this->assertStringContainsString( 'id="ffc-schedule-form"', $output );
		$this->assertStringContainsString( '<button type="submit" class="ffc-btn ffc-btn-primary ffc-icon-plus" name="submit" value="Create Calendar" form="ffc-schedule-form">Create Calendar</button>', $output, 'the side button posts the calendar form' );
		$this->assertStringNotContainsString( 'ffc-icon-code', $output, 'no shortcode before the calendar exists' );
		$this->assertStringNotContainsString( 'Calendar ID', $output );
	}

	/**
	 * @return array<string, array{0: list<string>, 1: bool}>
	 */
	public static function user_access_reveal_tiers(): array {
		return array(
			'manage table, may reveal'    => array( array( 'ffc_view_audiences', 'ffc_manage_audiences' ), true ),
			'manage table, masked tier'   => array( array( 'ffc_view_audiences', 'ffc_manage_audiences' ), false ),
			'read-only table, may reveal' => array( array( 'ffc_view_audiences' ), true ),
			'read-only table, masked'     => array( array( 'ffc_view_audiences' ), false ),
		);
	}

	/**
	 * Both User access tables mask each account email for every tier and
	 * draw the audited Reveal button only for a viewer the policy lets
	 * reveal: the real PiiAccessPolicy, asked with the audiences PII cap and
	 * the listed user as owner (#1824).
	 *
	 * @dataProvider user_access_reveal_tiers
	 *
	 * @param list<string> $caps       Capabilities held.
	 * @param bool         $can_reveal Whether the viewer holds the audiences PII cap.
	 */
	public function test_user_access_rows_mask_the_account_email_at_every_tier( array $caps, bool $can_reveal ): void {
		$this->grant( $caps );
		$this->can_reveal = $can_reveal;
		$_GET['action'] = 'edit';
		$_GET['id']     = '5';
		Functions\when( 'wp_nonce_field' )->justReturn( '' );
		Functions\when( 'selected' )->justReturn( '' );
		Functions\when( 'submit_button' )->justReturn( '' );
		Functions\when( 'esc_attr__' )->returnArg();
		Functions\when( 'get_userdata' )->alias(
			static fn( $id ) => (object) array( 'ID' => $id, 'display_name' => 'User' . $id, 'user_email' => 'person' . $id . '@e.com' )
		);

		$this->schedule( array( 'id' => 5, 'name' => 'Cal Five', 'status' => 'active', 'visibility' => 'public', 'environment_label' => 'Rooms' ) );
		$this->store( self::PERMISSION_TABLE, array( 'id' => 1, 'schedule_id' => 5, 'user_id' => 7, 'can_book' => '1', 'can_cancel_others' => '0', 'can_override_conflicts' => '0' ) );

		$page = new AudienceAdminCalendar( 'ffc-scheduling' );
		ob_start();
		$page->render_page();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'User7', $output );
		$this->assertStringNotContainsString( 'person7@e.com', $output, 'no tier receives the clear address' );
		$this->assertStringContainsString( '<span class="ffc-pii-value" data-field="email">p***@e.com</span>', $output );
		$this->assertSame(
			$can_reveal ? 1 : 0,
			substr_count( $output, '<button type="button" class="ffc-btn ffc-btn-neutral ffc-icon-eye ffc-reveal-pii" data-type="audience_account" data-field="email" data-submission-id="7" data-nonce="reveal-nonce">Reveal</button>' )
		);
		$this->assertSame( $can_reveal ? 1 : 0, substr_count( $output, 'ffc-reveal-pii' ) );
		$this->assertContains( array( self::VIEWER, AudienceAccountEmail::PII_CAP ), $this->user_can_asked, 'the policy asked about the audiences PII cap' );
	}
}
