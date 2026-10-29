<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Audience\AudienceShortcode;

/**
 * Tests for AudienceShortcode: init registration, render with various
 * login/access states, private visibility message, and helper methods.
 *
 * AudienceScheduleRepository, AudienceEnvironmentRepository, AudienceReader
 * and AssetHelper are the real classes. The `$wpdb` double below holds the
 * schedules, their permissions and environments, the audiences and their
 * members in arrays a test sets, answers each query by its SQL text and the
 * values bound to it, and fails the test on a query it does not know.
 * Whether the current user may bypass is current_user_can()'s answer, as the
 * schedule repository asks it (#1859).
 *
 * @covers \FreeFormCertificate\Audience\AudienceShortcode
 */
class AudienceShortcodeTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private const SCHEDULES    = 'wp_ffc_audience_schedules';
	private const PERMISSIONS  = 'wp_ffc_audience_schedule_permissions';
	private const ENVIRONMENTS = 'wp_ffc_audience_environments';
	private const AUDIENCES    = 'wp_ffc_audiences';
	private const MEMBERS      = 'wp_ffc_audience_members';

	/** @var bool What current_user_can() answers: the current user's audience bypass. */
	private bool $bypass = false;

	/** @var bool What user_can() answers for any capability of a given user. */
	private bool $user_can = false;

	/** @var array<string, array<int, object>> Stored rows by table, then by id. */
	private array $rows = array();

	/** @var array<int, list<int>> Member user ids by audience id. */
	private array $members = array();

	/** @var list<array<int, mixed>> Every prepare() call, as its argument list. */
	private array $prepared = array();

	/** @var list<array{0: string, 1: string, 2: array<int, mixed>}> Every read, as [SQL, table, bound values]. */
	private array $ran = array();

	/** @var mixed The `$wpdb` global an earlier test left, restored afterwards. */
	private $wpdb_backup;

	/** @var bool Whether `$wpdb` was set before this test. */
	private bool $had_wpdb = false;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		// Shared scripts are enqueued through AssetRegistry, which registers
		// them first; false is WordPress's answer for a handle already
		// registered, so nothing is localized twice (#1931).
		Functions\when( 'wp_register_script' )->justReturn( false );
		Functions\when( 'wp_enqueue_style' )->justReturn( null );
		Functions\when( 'wp_enqueue_script' )->justReturn( null );

		Functions\when('__')->returnArg();
		Functions\when('esc_html__')->returnArg();
		Functions\when('esc_html')->returnArg();
		Functions\when('esc_attr')->returnArg();
		Functions\when('esc_attr_e')->alias(function ($text) { echo $text; });
		Functions\when('esc_html_e')->alias(function ($text) { echo $text; });
		Functions\when('wp_kses_post')->returnArg();
		Functions\when('absint')->alias(function ($val) {
			return abs(intval($val));
		});
		Functions\when('sanitize_text_field')->returnArg();
		// `AssetHelper::enqueue_dark_mode()` reads the dark-mode setting to decide
		// whether to enqueue the toggle (#1126): no settings, so it stays off.
		Functions\when('get_option')->justReturn(false);
		Functions\when( 'current_user_can' )->alias( fn() => $this->bypass );
		Functions\when( 'user_can' )->alias( fn() => $this->user_can );
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'wp_parse_args' )->alias( static fn( $args, $defaults = array() ) => array_merge( $defaults, (array) $args ) );
		Functions\when( 'sanitize_sql_orderby' )->returnArg();
		// Every member's account exists.
		Functions\when( 'get_users' )->alias( static fn( $args ) => array_map( 'intval', (array) $args['include'] ) );

		$this->installDatabase();
	}

	protected function tearDown(): void {
		if ( $this->had_wpdb ) {
			$GLOBALS['wpdb'] = $this->wpdb_backup;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		Monkey\tearDown();
		parent::tearDown();
	}

	// ==================================================================
	// The `$wpdb` double
	// ==================================================================

	/**
	 * Put the `$wpdb` double in place.
	 */
	private function installDatabase(): void {
		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			function ( ...$args ) {
				$flat             = 2 === count( $args ) && is_array( $args[1] ) ? array_merge( array( $args[0] ), $args[1] ) : $args;
				$this->prepared[] = $flat;
				return 'Q' . ( count( $this->prepared ) - 1 ) . ':' . $flat[0];
			}
		);
		foreach ( array( 'get_row', 'get_results' ) as $method ) {
			$wpdb->shouldReceive( $method )->andReturnUsing( fn( $sql, $output = 'OBJECT' ) => $this->answer( $method, (string) $sql, $output ) );
		}

		$this->had_wpdb    = array_key_exists( 'wpdb', $GLOBALS );
		$this->wpdb_backup = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb']   = $wpdb;
	}

	/**
	 * Answer one read from the arrays the test set.
	 *
	 * @param mixed $output The output type asked for.
	 * @return mixed
	 */
	private function answer( string $method, string $sql, $output ) {
		$bound = array( $sql );
		if ( preg_match( '/^Q(\d+):/', $sql, $match ) ) {
			$bound = $this->prepared[ (int) $match[1] ];
		}
		$text        = (string) $bound[0];
		$table       = (string) ( $bound[1] ?? '' );
		$args        = array_slice( $bound, 2 );
		$this->ran[] = array( $text, $table, $args );

		if ( 'get_results' === $method && 0 === strpos( $text, 'SELECT DISTINCT s.* FROM %i s' ) ) {
			// AudienceScheduleRepository::get_by_user_access(): active, and
			// public or granted to the user.
			$user_id = (int) $args[1];
			$granted = array_map(
				static fn( $p ) => (int) $p->schedule_id,
				array_filter( $this->rows[ self::PERMISSIONS ] ?? array(), static fn( $p ) => (int) $p->user_id === $user_id )
			);
			$out = array();
			foreach ( $this->rows[ self::SCHEDULES ] ?? array() as $row ) {
				if ( 'active' === $row->status && ( 'public' === $row->visibility || in_array( (int) $row->id, $granted, true ) ) ) {
					$out[] = clone $row;
				}
			}
			usort( $out, static fn( $a, $b ) => strcmp( (string) $a->name, (string) $b->name ) );
			return $out;
		}
		if ( 'get_results' === $method && self::MEMBERS === $table && 'SELECT audience_id, user_id FROM %i' === $text && ARRAY_A === $output ) {
			$out = array();
			foreach ( $this->members as $audience_id => $users ) {
				foreach ( $users as $user_id ) {
					$out[] = array( 'audience_id' => (string) $audience_id, 'user_id' => (string) $user_id );
				}
			}
			return $out;
		}
		if ( 'get_results' === $method && self::AUDIENCES === $table && 0 === strpos( $text, 'SELECT a.* FROM %i a' ) ) {
			// AudienceReader::get_user_audiences(): the active audiences the user is in.
			$user_id = (int) $args[1];
			$out     = array();
			foreach ( $this->rows[ self::AUDIENCES ] ?? array() as $id => $row ) {
				if ( 'active' === $row->status && in_array( $user_id, $this->members[ $id ] ?? array(), true ) ) {
					$out[] = clone $row;
				}
			}
			usort( $out, static fn( $a, $b ) => strcmp( (string) $a->name, (string) $b->name ) );
			return $out;
		}
		if ( 'get_results' === $method && self::AUDIENCES === $table && preg_match( "/^SELECT \\* FROM %i WHERE id IN \\([%d,]+\\) AND status = 'active'$/", $text ) ) {
			$out = array();
			foreach ( $args as $id ) {
				$row = $this->rows[ self::AUDIENCES ][ (int) $id ] ?? null;
				if ( null !== $row && 'active' === $row->status ) {
					$out[] = clone $row;
				}
			}
			return $out;
		}
		if ( 0 === strpos( $text, 'SELECT * FROM %i' ) && in_array( $table, array( self::SCHEDULES, self::PERMISSIONS, self::ENVIRONMENTS, self::AUDIENCES ), true ) ) {
			$rows = $this->select( $this->rows[ $table ] ?? array(), $text, $args );
			return 'get_row' === $method ? ( $rows[0] ?? null ) : $rows;
		}
		$this->fail( "Unexpected {$method}(): {$text} with " . var_export( $bound, true ) );
	}

	/**
	 * The rows matching a `SELECT * FROM %i [WHERE col = %s|%d AND …] [ORDER BY name …]`.
	 *
	 * @param array<int, object> $rows Stored rows.
	 * @param string             $sql  The query, placeholders unreplaced.
	 * @param array<int, mixed>  $args The values bound after the table.
	 * @return list<object>
	 */
	private function select( array $rows, string $sql, array $args ): array {
		preg_match_all( '/\b(\w+) = %[sd]/', $sql, $columns );
		$this->assertSame( count( $columns[1] ), count( $args ), 'Every condition binds one value: ' . $sql );
		$out = array();
		foreach ( $rows as $row ) {
			if ( false !== strpos( $sql, 'parent_id IS NULL' ) && ! empty( $row->parent_id ) ) {
				continue;
			}
			foreach ( $columns[1] as $i => $column ) {
				if ( (string) ( $row->$column ?? '' ) !== (string) $args[ $i ] ) {
					continue 2;
				}
			}
			$out[] = clone $row;
		}
		if ( false !== strpos( $sql, 'ORDER BY name' ) ) {
			usort( $out, static fn( $a, $b ) => strcmp( (string) $a->name, (string) $b->name ) );
		}
		return $out;
	}

	/**
	 * Store a row.
	 *
	 * @param array<string, mixed> $fields Its columns, `id` included.
	 */
	private function row( string $table, array $fields ): void {
		$this->rows[ $table ][ (int) $fields['id'] ] = (object) $fields;
	}

	/**
	 * Store an active schedule.
	 *
	 * @param array<string, mixed> $fields Columns beyond the defaults.
	 */
	private function schedule( int $id, string $name, string $visibility, array $fields = array() ): void {
		$this->row(
			self::SCHEDULES,
			array_merge(
				array(
					'id'                => (string) $id,
					'name'              => $name,
					'status'            => 'active',
					'visibility'        => $visibility,
					'environment_label' => 'Room',
				),
				$fields
			)
		);
	}

	/**
	 * Store an audience.
	 */
	private function audience( int $id, string $name, string $color, ?int $parent_id = null, string $status = 'active' ): void {
		$this->row(
			self::AUDIENCES,
			array(
				'id'        => (string) $id,
				'name'      => $name,
				'color'     => $color,
				'parent_id' => null === $parent_id ? null : (string) $parent_id,
				'status'    => $status,
			)
		);
	}

	/**
	 * The reads of one table whose SQL starts with $prefix.
	 *
	 * @return list<array{0: string, 1: string, 2: array<int, mixed>}>
	 */
	private function readsOf( string $table, string $prefix ): array {
		return array_values(
			array_filter( $this->ran, static fn( array $r ): bool => $table === $r[1] && 0 === strpos( $r[0], $prefix ) )
		);
	}

	// ==================================================================
	// init()
	// ==================================================================

	public function test_init_registers_shortcode(): void {
		Functions\expect('add_shortcode')
			->once()
			->with('ffc_audience', Mockery::type('array'));

		AudienceShortcode::init();
	}

	// ==================================================================
	// render() — non-array atts normalised
	// ==================================================================

	public function test_render_normalises_string_atts_to_array(): void {
		Functions\when('shortcode_atts')->alias(function ($defaults, $atts) {
			if (!is_array($atts)) {
				$atts = array();
			}
			return array_merge($defaults, $atts);
		});
		$this->stubNotLoggedInWithNoPublicSchedules();

		$result = AudienceShortcode::render('');

		$this->assertIsString($result);
	}

	// ==================================================================
	// render() — not logged in, private schedule
	// ==================================================================

	public function test_render_returns_private_message_for_private_schedule_when_not_logged_in(): void {
		Functions\when('shortcode_atts')->alias(function ($defaults, $atts) {
			return array_merge($defaults, is_array($atts) ? $atts : array());
		});
		Functions\when('is_user_logged_in')->justReturn(false);

		$this->schedule( 1, 'Test Calendar', 'private' );

		Functions\when('get_option')->alias(function ($key, $default = false) {
			if ($key === 'ffc_aud_private_display_mode') return 'show_message';
			if ($key === 'ffc_aud_visibility_message') return 'Please log in.';
			return $default;
		});
		Functions\when('wp_login_url')->justReturn('https://example.com/login');
		Functions\when('get_permalink')->justReturn('https://example.com/page');

		$result = AudienceShortcode::render(array('schedule_id' => 1));

		$this->assertStringContainsString('ffc-visibility-restricted', $result);
		$this->assertStringContainsString('ffc-notice ffc-notice-warning', $result);
	}

	// ==================================================================
	// render() — not logged in, display_mode = hide
	// ==================================================================

	public function test_render_returns_empty_when_private_display_mode_is_hide(): void {
		Functions\when('shortcode_atts')->alias(function ($defaults, $atts) {
			return array_merge($defaults, is_array($atts) ? $atts : array());
		});
		Functions\when('is_user_logged_in')->justReturn(false);

		$this->schedule( 1, 'Private Cal', 'private' );

		Functions\when('get_option')->alias(function ($key, $default = false) {
			if ($key === 'ffc_aud_private_display_mode') return 'hide';
			return $default;
		});
		Functions\when('wp_login_url')->justReturn('https://example.com/login');
		Functions\when('get_permalink')->justReturn('https://example.com/page');

		$result = AudienceShortcode::render(array('schedule_id' => 1));

		$this->assertSame('', $result);
	}

	// ==================================================================
	// render() — not logged in, inactive schedule
	// ==================================================================

	public function test_render_returns_empty_for_inactive_schedule_when_not_logged_in(): void {
		Functions\when('shortcode_atts')->alias(function ($defaults, $atts) {
			return array_merge($defaults, is_array($atts) ? $atts : array());
		});
		Functions\when('is_user_logged_in')->justReturn(false);

		$this->schedule( 5, 'Inactive Calendar', 'public', array( 'status' => 'inactive' ) );

		$result = AudienceShortcode::render(array('schedule_id' => 5));

		$this->assertSame('', $result);
	}

	// ==================================================================
	// render() — logged in, no access
	// ==================================================================

	public function test_render_returns_no_access_message_when_user_has_no_schedules(): void {
		Functions\when('shortcode_atts')->alias(function ($defaults, $atts) {
			return array_merge($defaults, is_array($atts) ? $atts : array());
		});
		Functions\when('is_user_logged_in')->justReturn(true);
		Functions\when('get_current_user_id')->justReturn(42);
		Functions\when('nocache_headers')->justReturn(null);

		// A private schedule user 42 holds no permission on.
		$this->schedule( 3, 'Staff Only', 'private' );

		$result = AudienceShortcode::render(array());

		$this->assertStringContainsString('<div class="ffc-notice ffc-notice-info ffc-calendar-notice" role="note" data-ffc-notice="audience-no-access">', $result);
		$this->assertStringContainsString('You do not have access to any calendars.', $result);
		$this->assertSame(
			array( array( self::PERMISSIONS, 42 ) ),
			array_column( $this->readsOf( self::SCHEDULES, 'SELECT DISTINCT s.*' ), 2 ),
			'the access query ran once, for user 42'
		);
	}

	// ==================================================================
	// render() — not logged in, no schedule_id, no public schedules
	// ==================================================================

	public function test_render_shows_private_message_when_no_public_schedules_found(): void {
		Functions\when('shortcode_atts')->alias(function ($defaults, $atts) {
			return array_merge($defaults, is_array($atts) ? $atts : array());
		});

		$this->stubNotLoggedInWithNoPublicSchedules();

		$result = AudienceShortcode::render(array());

		$this->assertStringContainsString('ffc-visibility-restricted', $result);
	}

	// ==================================================================
	// render() — not logged in, public schedule (read-only calendar)
	// ==================================================================

	public function test_render_shows_readonly_calendar_for_public_schedule(): void {
		Functions\when('shortcode_atts')->alias(function ($defaults, $atts) {
			return array_merge($defaults, is_array($atts) ? $atts : array());
		});
		Functions\when('wp_localize_script')->justReturn(true);
		Functions\when('admin_url')->justReturn('https://example.com/wp-admin/admin-ajax.php');
		Functions\when('rest_url')->justReturn('https://example.com/wp-json/ffc/v1/audience/');
		Functions\when('wp_create_nonce')->justReturn('nonce123');
		Functions\when('get_locale')->justReturn('en_US');
		Functions\when('wp_login_url')->justReturn('https://example.com/login');
		Functions\when('get_permalink')->justReturn('https://example.com/page');
		Functions\when('wp_json_encode')->alias('json_encode');
		Functions\when('selected')->justReturn('');
		Functions\when('is_user_logged_in')->justReturn(false);

		$this->schedule( 2, 'Public Calendar', 'public', array(
			'show_event_list' => '0', 'event_list_position' => 'side',
			'audience_badge_format' => 'name', 'future_days_limit' => null,
			'booking_label_singular' => null, 'booking_label_plural' => null,
			'environment_label' => null,
		) );
		$this->audience( 4, 'Teachers', '#222222' );
		$this->members = array( 4 => array( 50, 51 ) );

		Functions\when('get_option')->alias(function ($key, $default = false) {
			if ($key === 'ffc_aud_scheduling_message') return 'Please log in to book.';
			if ($key === 'date_format') return 'Y-m-d';
			if ($key === 'time_format') return 'H:i';
			if ($key === 'start_of_week') return 0;
			if ($key === 'ffc_aud_multiple_audiences_color') return '';
			return $default;
		});

		$result = AudienceShortcode::render(array('schedule_id' => 2));

		$this->assertStringContainsString('ffc-audience-calendar', $result);
		$this->assertStringContainsString('ffc-calendar-grid', $result);
		// Read-only: no booking modal
		$this->assertStringNotContainsString('ffc-booking-modal', $result);
		// #951 a11y: the environment filter <select> carries an accessible name
		// (it has no visible <label>), so screen-reader users know its purpose.
		$this->assertStringContainsString('ffc-environment-select', $result);
		$this->assertStringContainsString('aria-label="Filter by', $result);
		// A visitor who is not logged in gets no member counts (#2008).
		$this->assertSame( array(), $this->readsOf( self::MEMBERS, 'SELECT audience_id, user_id' ), 'no member query runs' );
	}

	// ==================================================================
	// render() — show_title_message displays calendar name
	// ==================================================================

	public function test_render_private_visibility_show_title_message_includes_schedule_name(): void {
		Functions\when('shortcode_atts')->alias(function ($defaults, $atts) {
			return array_merge($defaults, is_array($atts) ? $atts : array());
		});
		Functions\when('is_user_logged_in')->justReturn(false);

		$this->schedule( 3, 'VIP Calendar', 'private' );

		Functions\when('get_option')->alias(function ($key, $default = false) {
			if ($key === 'ffc_aud_private_display_mode') return 'show_title_message';
			if ($key === 'ffc_aud_visibility_message') return 'Log in to see this.';
			return $default;
		});
		Functions\when('wp_login_url')->justReturn('https://example.com/login');
		Functions\when('get_permalink')->justReturn('https://example.com/page');

		$result = AudienceShortcode::render(array('schedule_id' => 3));

		$this->assertStringContainsString('ffc-calendar-title', $result);
		$this->assertStringContainsString('VIP Calendar', $result);
	}

	// ==================================================================
	// render() — schedule not found returns empty
	// ==================================================================

	public function test_render_returns_empty_when_schedule_not_found(): void {
		Functions\when('shortcode_atts')->alias(function ($defaults, $atts) {
			return array_merge($defaults, is_array($atts) ? $atts : array());
		});
		Functions\when('is_user_logged_in')->justReturn(false);

		$result = AudienceShortcode::render(array('schedule_id' => 99));

		$this->assertSame('', $result);
		$this->assertSame( array( array( 99 ) ), array_column( $this->readsOf( self::SCHEDULES, 'SELECT * FROM %i WHERE id = %d' ), 2 ) );
	}

	// ==================================================================
	// render() — logged in: full calendar, bypass notice, booking modal
	// ==================================================================

	/**
	 * Stubs shared by every logged-in render that reaches the calendar.
	 *
	 * @param array<string, mixed> $localized Receives the `ffcAudience` payload.
	 */
	private function stubLoggedInCalendarEnvironment( array &$localized ): void {
		Functions\when('shortcode_atts')->alias(function ($defaults, $atts) {
			return array_merge($defaults, is_array($atts) ? $atts : array());
		});
		Functions\when('wp_localize_script')->alias(function ($handle, $name, $data) use (&$localized) {
			$localized = $data;
			return true;
		});
		Functions\when('admin_url')->justReturn('https://example.com/wp-admin/admin-ajax.php');
		Functions\when('rest_url')->justReturn('https://example.com/wp-json/ffc/v1/audience/');
		Functions\when('wp_create_nonce')->justReturn('nonce123');
		Functions\when('get_locale')->justReturn('en_US');
		Functions\when('wp_json_encode')->alias('json_encode');
		Functions\when('selected')->justReturn('');
		Functions\when('nocache_headers')->justReturn(null);
		Functions\when('is_user_logged_in')->justReturn(true);
		Functions\when('get_current_user_id')->justReturn(7);
		// The JSON config travels in an attribute, so it must really be escaped.
		Functions\when('esc_attr')->alias(function ($text) {
			return htmlspecialchars((string) $text, ENT_QUOTES);
		});
	}

	/**
	 * Decodes the `data-config` attribute the calendar carries.
	 *
	 * @return array<string, mixed>
	 */
	private function configOf( string $html ): array {
		$this->assertSame(1, preg_match('/data-config="([^"]*)"/', $html, $m), 'The calendar must carry its data-config.');
		$config = json_decode(html_entity_decode($m[1], ENT_QUOTES), true);
		$this->assertIsArray($config);
		return $config;
	}

	public function test_render_logged_in_admin_with_bypass_renders_full_calendar(): void {
		$localized = array();
		$this->stubLoggedInCalendarEnvironment($localized);
		$this->user_can = true;

		// The audience bypass is its own capability since #1859.
		$this->bypass = true;

		// The private schedule sorts first by name.
		$this->schedule( 5, 'Meeting Rooms', 'private', array(
			'show_event_list' => '1', 'event_list_position' => 'below',
			'audience_badge_format' => 'parent_name', 'future_days_limit' => '30',
			'booking_label_singular' => 'reservation', 'booking_label_plural' => 'reservations',
		) );
		$this->schedule( 6, 'Open Rooms', 'public', array(
			'show_event_list' => '0', 'event_list_position' => '',
			'audience_badge_format' => '', 'booking_label_singular' => '', 'booking_label_plural' => '',
		) );
		$this->row( self::ENVIRONMENTS, array( 'id' => '11', 'schedule_id' => '5', 'name' => 'Hall', 'color' => null, 'status' => 'active' ) );
		$this->row( self::ENVIRONMENTS, array( 'id' => '12', 'schedule_id' => '6', 'name' => 'Lab', 'color' => '#ff0000', 'status' => 'active' ) );
		$this->row( self::ENVIRONMENTS, array( 'id' => '13', 'schedule_id' => '6', 'name' => 'Closed lab', 'color' => '#00ff00', 'status' => 'inactive' ) );

		$this->audience( 2, 'Teachers', '#222222' );
		$this->audience( 3, 'Class A', '#111111', 2 );
		// Teacher 50 is in both audiences: the parent reaches two people,
		// not three (#2008).
		$this->members = array(
			2 => array(50, 51),
			3 => array(50),
		);

		$result = AudienceShortcode::render(array());

		$this->assertStringContainsString('data-ffc-notice="admin-bypass"', $result);
		// The event list sits below, so the notice takes the calendar's
		// width, not the wrapper's (#2200).
		$this->assertStringContainsString('<div class="ffc-notice ffc-notice-info ffc-calendar-notice" role="note" data-ffc-notice="admin-bypass">', $result);
		$this->assertStringContainsString('ffc-icon-unlock', $result);
		$this->assertStringContainsString('ffc-badge-private', $result);
		$this->assertStringContainsString('ffc-schedule-select', $result, 'Two schedules must render the calendar selector.');
		$this->assertStringContainsString('ffc-event-list-panel', $result);
		$this->assertStringContainsString('ffc-event-list-below', $result);
		$this->assertStringContainsString('ffc-booking-modal', $result);
		$this->assertStringContainsString('ffc-new-booking-btn', $result);

		// The dialog of #2008: one button that checks, then creates; the
		// span and the type as segmented controls; the audience picker.
		$this->assertMatchesRegularExpression('/<button[^>]*id="ffc-booking-submit"[^>]*data-step="check"/', $result);
		$this->assertStringNotContainsString('ffc-check-conflicts-btn', $result);
		$this->assertStringNotContainsString('ffc-create-booking-btn', $result);
		$this->assertStringContainsString('name="booking_span" value="single" checked', $result);
		$this->assertStringContainsString('name="booking_type" value="audience" checked', $result);
		$this->assertStringContainsString('name="time_mode" value="daily" checked', $result);
		$this->assertStringContainsString('id="booking-audiences" role="group"', $result);
		$this->assertStringContainsString('id="booking-audience-summary"', $result);
		$this->assertStringContainsString('<div id="ffc-conflict-error" hidden></div>', $result);
		$dialog = substr( $result, (int) strpos( $result, 'ffc-booking-dialog' ) );
		$dialog = substr( $dialog, 0, (int) strpos( $dialog, 'ffc-day-modal' ) );
		$this->assertStringNotContainsString('style=', $dialog, 'The dialog takes its look from the sheet, never inline.');
		// `ffc-booking-form` is the personal calendar's form: on a page with
		// both shortcodes its sheet would restyle this dialog (#2013).
		$this->assertStringContainsString('<form id="ffc-booking-form" class="ffc-audience-booking-form">', $dialog);
		$this->assertDoesNotMatchRegularExpression('/class="[^"]*\bffc-booking-form\b/', $dialog);

		$config = $this->configOf($result);
		$this->assertTrue($config['canBook']);
		$this->assertSame('parent_name', $config['audienceBadgeFormat']);
		$this->assertSame('below', $config['eventListPosition']);
		$this->assertSame(30, $config['schedules'][0]['futureDaysLimit']);
		$this->assertNull($config['schedules'][1]['futureDaysLimit']);
		$this->assertSame('#3788d8', $config['schedules'][0]['environments'][0]['color'], 'A missing colour falls back to the default.');
		$this->assertSame(array('Lab'), array_column($config['schedules'][1]['environments'], 'name'), 'Only the active environments are listed.');
		$this->assertSame('#ff0000', $config['schedules'][1]['environments'][0]['color']);
		$this->assertSame('Class A', $config['audiences'][0]['children'][0]['name']);
		$this->assertNull($config['audiences'][0]['parent_id']);
		$this->assertSame(2, $config['audiences'][0]['members'], 'A person in both audiences is counted once.');
		$this->assertSame(1, $config['audiences'][0]['children'][0]['members']);
		$this->assertCount(1, $this->readsOf( self::MEMBERS, 'SELECT audience_id, user_id' ), 'The members are read once for the whole tree.');
		// An administrator books without the stored permissions being asked.
		$this->assertSame(array(), $this->readsOf( self::PERMISSIONS, 'SELECT * FROM %i' ));

		$this->assertSame('reservation', $localized['strings']['booking']);
		$this->assertSame('reservations', $localized['strings']['bookings']);
	}

	public function test_bypass_notice_follows_the_wrapper_when_the_event_list_is_beside(): void {
		$localized = array();
		$this->stubLoggedInCalendarEnvironment($localized);
		$this->user_can = true;
		$this->bypass   = true;

		$this->schedule( 5, 'Meeting Rooms', 'private', array(
			'show_event_list' => '1', 'event_list_position' => 'side',
			'audience_badge_format' => '', 'booking_label_singular' => '', 'booking_label_plural' => '',
		) );

		$result = AudienceShortcode::render(array('schedule_id' => 5));

		$this->assertStringContainsString('<div class="ffc-notice ffc-notice-info ffc-calendar-notice ffc-calendar-notice-wide" role="note" data-ffc-notice="admin-bypass">', $result);
		$this->assertStringContainsString('ffc-event-list-side', $result);
	}

	public function test_render_logged_in_bypass_without_private_schedule_prints_no_notice(): void {
		$localized = array();
		$this->stubLoggedInCalendarEnvironment($localized);
		$this->user_can = true;

		// The audience bypass is its own capability since #1859.
		$this->bypass = true;

		$this->schedule( 8, 'Open', 'public' );

		$result = AudienceShortcode::render(array('schedule_id' => 8));

		$this->assertStringNotContainsString('data-ffc-notice="admin-bypass"', $result);
		$this->assertStringNotContainsString('ffc-schedule-select', $result, 'One schedule needs no selector.');
		$this->assertStringNotContainsString('ffc-event-list-panel', $result);
		$this->assertSame('booking', $localized['strings']['booking'], 'No custom label keeps the default.');
	}

	public function test_render_logged_in_admin_with_unknown_schedule_shows_no_access(): void {
		$localized = array();
		$this->stubLoggedInCalendarEnvironment($localized);
		$this->user_can = true;

		// The audience bypass is its own capability since #1859.
		$this->bypass = false;

		$result = AudienceShortcode::render(array('schedule_id' => 404));

		$this->assertStringContainsString('You do not have access to any calendars.', $result);
		$this->assertSame(array(), $localized, 'No script is localized when nothing is accessible.');
	}

	public function test_render_logged_in_member_filters_schedules_and_books_per_schedule(): void {
		$localized = array();
		$this->stubLoggedInCalendarEnvironment($localized);
		$this->user_can = false;

		// The audience bypass is its own capability since #1859.
		$this->bypass = false;

		$this->schedule( 1, 'A', 'public' );
		$this->schedule( 2, 'B', 'public' );
		$this->row( self::PERMISSIONS, array( 'id' => '1', 'schedule_id' => '2', 'user_id' => '7', 'can_book' => '1', 'can_override_conflicts' => '0' ) );

		// User 7 is in "Mine", under an audience that is no longer active.
		$this->audience( 4, 'Retired', '#999999', null, 'inactive' );
		$this->audience( 9, 'Mine', '#000000', 4 );
		$this->members = array( 9 => array( 7, 70, 71 ) );

		$result = AudienceShortcode::render(array('schedule_id' => 2));

		$config = $this->configOf($result);
		$this->assertCount(1, $config['schedules'], 'Only the requested schedule survives the filter.');
		$this->assertSame(2, $config['schedules'][0]['id']);
		$this->assertTrue($config['canBook']);
		$this->assertSame( array( array( 2, 7 ) ), array_values( array_unique( array_column( $this->readsOf( self::PERMISSIONS, 'SELECT * FROM %i' ), 2 ), SORT_REGULAR ) ), 'Only the requested schedule is asked for user 7.' );
		$this->assertCount(1, $config['audiences'], 'The inactive parent is not offered.');
		$this->assertSame('4', $config['audiences'][0]['parent_id']);
		$this->assertSame(3, $config['audiences'][0]['members']);
		// A member books for the audiences they are in, not the whole tree.
		$this->assertSame(array(), $this->readsOf( self::AUDIENCES, 'SELECT * FROM %i WHERE status = %s ORDER BY' ));
	}

	public function test_render_logged_in_member_without_booking_rights_cannot_book(): void {
		$localized = array();
		$this->stubLoggedInCalendarEnvironment($localized);
		$this->user_can = false;

		// The audience bypass is its own capability since #1859.
		$this->bypass = false;

		$this->schedule( 1, 'A', 'public' );
		$this->row( self::PERMISSIONS, array( 'id' => '1', 'schedule_id' => '1', 'user_id' => '7', 'can_book' => '0', 'can_override_conflicts' => '0' ) );

		$result = AudienceShortcode::render(array());

		$this->assertFalse($this->configOf($result)['canBook']);
	}

	public function test_render_public_listing_shows_read_only_calendar_with_login_link(): void {
		$localized = array();
		$this->stubLoggedInCalendarEnvironment($localized);
		Functions\when('is_user_logged_in')->justReturn(false);
		Functions\when('wp_login_url')->justReturn('https://example.com/login');
		Functions\when('get_permalink')->justReturn(false);

		// The audience bypass is its own capability since #1859.
		$this->bypass = false;

		$this->schedule( 1, 'A', 'public', array( 'show_event_list' => '1' ) );
		// A private schedule a visitor is not shown.
		$this->schedule( 3, 'Staff', 'private' );

		Functions\when('get_option')->alias(function ($key, $default = false) {
			if ('ffc_aud_scheduling_message' === $key) {
				return 'Go <a href="%login_url%">here</a>';
			}
			return $default;
		});

		$result = AudienceShortcode::render(array());

		$config = $this->configOf($result);
		$this->assertTrue($config['readOnly']);
		$this->assertFalse($config['canBook']);
		$this->assertSame(array(1), array_column($config['schedules'], 'id'));
		$this->assertSame('side', $config['eventListPosition'], 'An enabled list with no position falls back to side.');
		$this->assertSame('Go <a href="https://example.com/login">here</a>', $config['schedulingMessage']);
		$this->assertStringContainsString('ffc-event-list-side', $result);
	}

	// ==================================================================
	// Helper: stub for "not logged in, no public schedules"
	// ==================================================================

	private function stubNotLoggedInWithNoPublicSchedules(): void {
		Functions\when('is_user_logged_in')->justReturn(false);

		// The audience bypass is its own capability since #1859.
		$this->bypass = false;
		// Only a private schedule exists.
		$this->schedule( 3, 'Staff', 'private' );

		Functions\when('get_option')->alias(function ($key, $default = false) {
			if ($key === 'ffc_aud_private_display_mode') return 'show_message';
			if ($key === 'ffc_aud_visibility_message') return 'Please log in.';
			return $default;
		});
		Functions\when('wp_login_url')->justReturn('https://example.com/login');
		Functions\when('get_permalink')->justReturn('https://example.com/page');
	}
}
