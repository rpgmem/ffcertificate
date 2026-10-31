<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Core\Encryption;
use FreeFormCertificate\SelfScheduling\SelfSchedulingCPT;

/**
 * Tests for SelfSchedulingCPT.
 *
 * The calendar and appointment repositories, Debug, Encryption, DateFormatter
 * and the email pipeline are the real classes, over a `$wpdb` double that
 * answers the calendar and appointment reads, records every write and fails
 * on any query it was not taught. The duplication handler ends in `exit` after
 * its redirect; the redirect throws to stand in for it.
 *
 * @covers \FreeFormCertificate\SelfScheduling\SelfSchedulingCPT
 */
class SelfSchedulingCPTTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private const CALENDARS    = 'wp_ffc_self_scheduling_calendars';
	private const APPOINTMENTS = 'wp_ffc_self_scheduling_appointments';

	/**
	 * Calendar rows the double answers, keyed by post id.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $calendars = array();

	/**
	 * Appointment rows the double answers, keyed by calendar id.
	 *
	 * @var array<int, list<array<string, mixed>>>
	 */
	private array $appointments = array();

	/** @var list<string> Every prepared SQL the double was asked to run. */
	private array $queries = array();

	/** @var list<array{0: string, 1: array<string, mixed>}> Every insert, as `[ table, data ]`. */
	private array $inserts = array();

	/** @var list<array{0: string, 1: array<string, mixed>, 2: array<string, mixed>}> Every update, as `[ table, data, where ]`. */
	private array $updates = array();

	/** @var list<array{0: string, 1: array<string, mixed>}> Every delete, as `[ table, where ]`. */
	private array $deletes = array();

	/** @var mixed The `$wpdb` global an earlier test left, restored afterwards. */
	private $wpdb_backup;

	/** @var bool Whether `$wpdb` was set before this test. */
	private bool $had_wpdb = false;

	/** @var array<string, mixed> The `$_GET` an earlier test left, restored afterwards. */
	private array $get_backup = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->had_wpdb    = array_key_exists( 'wpdb', $GLOBALS );
		$this->wpdb_backup = $GLOBALS['wpdb'] ?? null;
		$this->get_backup  = $_GET ?? array();

		Functions\when( '__' )->returnArg();
		Functions\when( '_x' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_attr__' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'add_action' )->justReturn( true );
		// RequestInput::get_get_int() runs for real here (no alias mock), so its
		// WordPress dependencies need stubs.
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'absint' )->alias( static fn( $v ): int => abs( (int) $v ) );
		Functions\when( 'add_filter' )->justReturn( true );
		// #673: EmailService::send derives a text/plain alternative for HTML
		// messages — stub the WP glue that derivation touches.
		Functions\when( 'remove_action' )->justReturn( true );
		Functions\when( 'wp_strip_all_tags' )->alias(
			static function ( $s ) {
				return trim( (string) strip_tags( (string) $s ) );
			}
		);
		Functions\when( 'register_post_type' )->justReturn( true );
		Functions\when( 'absint' )->alias( function ( $v ) { return abs( (int) $v ); } );
		Functions\when( 'admin_url' )->justReturn( 'https://example.com/wp-admin/' );
		Functions\when( 'wp_nonce_url' )->justReturn( '/?_wpnonce=test' );
		Functions\when( 'wp_safe_redirect' )->justReturn( true );
		Functions\when( 'wp_is_post_revision' )->justReturn( false );
		Functions\when( 'sanitize_text_field' )->alias( 'trim' );
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		Functions\when( 'wp_cache_flush' )->justReturn( true );
		// clear_cache() with no key asks the backend first (#2241); without
		// group flushing it falls back to wp_cache_flush(), stubbed above.
		Functions\when( 'wp_cache_supports' )->justReturn( false );
		// Debug areas off and emails enabled: the settings of an install nobody
		// configured. Stubbed explicitly so Debug::is_enabled() does not depend
		// on whether an earlier test defined get_option().
		Functions\when( 'get_option' )->alias(
			static function ( $key, $default = false ) {
				return in_array( $key, array( 'ffc_settings', 'ffc_email_template' ), true ) ? array() : $default;
			}
		);

		$this->calendars    = array();
		$this->appointments = array();
		$this->queries      = array();
		$this->inserts      = array();
		$this->updates      = array();
		$this->deletes      = array();
		$GLOBALS['wpdb']    = $this->database();
	}

	protected function tearDown(): void {
		$_GET = $this->get_backup;
		if ( $this->had_wpdb ) {
			$GLOBALS['wpdb'] = $this->wpdb_backup;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * A database answering the calendar lookup by post id and the future
	 * appointments of a calendar from the fixtures above, recording every
	 * write. Any other read fails the test.
	 *
	 * @return Mockery\MockInterface
	 */
	private function database() {
		$wpdb             = Mockery::mock( 'wpdb' );
		$wpdb->prefix     = 'wp_';
		$wpdb->insert_id  = 0;
		$wpdb->last_error = '';

		// Interpolates, so the reads below can dispatch on the query's intent.
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			static function ( $sql, ...$args ) {
				$values = ( 1 === count( $args ) && is_array( $args[0] ) ) ? $args[0] : $args;
				foreach ( $values as $v ) {
					$sql = (string) preg_replace( '/%[ids]/', (string) $v, (string) $sql, 1 );
				}
				return $sql;
			}
		);

		$wpdb->shouldReceive( 'get_row' )->andReturnUsing(
			function ( $sql ) {
				$this->queries[] = $sql;
				if ( preg_match( '/^SELECT \* FROM ' . self::CALENDARS . ' WHERE post_id = (\d+)$/', $sql, $m ) ) {
					return $this->calendars[ (int) $m[1] ] ?? null;
				}
				throw new \RuntimeException( 'Unexpected get_row: ' . $sql );
			}
		);

		$wpdb->shouldReceive( 'get_results' )->andReturnUsing(
			function ( $sql ) {
				$this->queries[] = $sql;
				if ( preg_match( '/^SELECT \* FROM ' . self::APPOINTMENTS . ' WHERE calendar_id = (\d+) AND /', $sql, $m ) ) {
					return $this->appointments[ (int) $m[1] ] ?? array();
				}
				throw new \RuntimeException( 'Unexpected get_results: ' . $sql );
			}
		);

		$wpdb->shouldReceive( 'insert' )->andReturnUsing(
			function ( $table, $data ) use ( $wpdb ) {
				$this->inserts[] = array( $table, $data );
				$wpdb->insert_id = 500 + count( $this->inserts );
				return 1;
			}
		);

		$wpdb->shouldReceive( 'update' )->andReturnUsing(
			function ( $table, $data, $where ) {
				$this->updates[] = array( $table, $data, $where );
				return 1;
			}
		);

		$wpdb->shouldReceive( 'delete' )->andReturnUsing(
			function ( $table, $where ) {
				$this->deletes[] = array( $table, $where );
				return 1;
			}
		);

		return $wpdb;
	}

	/**
	 * The guards returned before any repository was consulted: no read and
	 * no write reached the database.
	 */
	private function assertDatabaseUntouched(): void {
		$this->assertSame( array(), $this->queries, 'No query may run past the guard.' );
		$this->assertSame( array(), $this->inserts, 'No row may be inserted past the guard.' );
		$this->assertSame( array(), $this->updates, 'No row may be updated past the guard.' );
		$this->assertSame( array(), $this->deletes, 'No row may be deleted past the guard.' );
	}

	// ==================================================================
	// Constructor
	// ==================================================================

	public function test_constructor_creates_instance(): void {
		$cpt = new SelfSchedulingCPT();
		$this->assertInstanceOf( SelfSchedulingCPT::class, $cpt );
	}

	// ==================================================================
	// register_calendar_cpt()
	// ==================================================================

	public function test_register_calendar_cpt_registers_post_type(): void {
		// #1030: the name claims a post type is registered; nothing checked it.
		$registered = array();
		Functions\when( 'register_post_type' )->alias(
			static function ( $slug ) use ( &$registered ) {
				$registered[] = $slug;
				return (object) array( 'name' => $slug );
			}
		);

		$cpt = new SelfSchedulingCPT();
		$cpt->register_calendar_cpt();

		$this->assertNotEmpty( $registered, 'register_calendar_cpt() registered no post type' );
	}

	// ==================================================================
	// add_duplicate_link() — wrong post type
	// ==================================================================

	public function test_add_duplicate_link_returns_unchanged_for_wrong_type(): void {
		$cpt = new SelfSchedulingCPT();
		$post = (object) array( 'post_type' => 'post', 'ID' => 1 );
		$actions = array( 'edit' => 'Edit' );

		$result = $cpt->add_duplicate_link( $actions, $post );
		$this->assertSame( $actions, $result );
	}

	// ==================================================================
	// add_duplicate_link() — no permission
	// ==================================================================

	public function test_add_duplicate_link_returns_unchanged_without_permission(): void {
		Functions\when( 'current_user_can' )->justReturn( false );

		$cpt = new SelfSchedulingCPT();
		$post = (object) array( 'post_type' => 'ffc_self_scheduling', 'ID' => 1 );
		$actions = array( 'edit' => 'Edit' );

		$result = $cpt->add_duplicate_link( $actions, $post );
		$this->assertSame( $actions, $result );
	}

	// ==================================================================
	// add_duplicate_link() — adds link
	// ==================================================================

	public function test_add_duplicate_link_adds_duplicate_action(): void {
		Functions\when( 'current_user_can' )->justReturn( true );

		$cpt = new SelfSchedulingCPT();
		$post = (object) array( 'post_type' => 'ffc_self_scheduling', 'ID' => 1 );
		$actions = array( 'edit' => 'Edit' );

		$result = $cpt->add_duplicate_link( $actions, $post );
		$this->assertArrayHasKey( 'duplicate', $result );
		$this->assertStringContainsString( 'Duplicate', $result['duplicate'] );
	}

	public function test_permanent_delete_link_states_how_many_appointments_it_cancels(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'current_time' )->justReturn( '2026-01-01' );
		Functions\when( '_n' )->alias( static fn( $one, $many, $n ) => 1 === $n ? $one : $many );
		$this->calendars[20]   = array( 'id' => 5, 'post_id' => 20, 'title' => 'Cal A' );
		$this->appointments[5] = array( array( 'id' => 1 ), array( 'id' => 2 ) );

		$cpt    = new SelfSchedulingCPT();
		$result = $cpt->add_duplicate_link(
			array( 'delete' => '<a href="post.php?action=delete" class="submitdelete">Delete Permanently</a>' ),
			(object) array( 'post_type' => 'ffc_self_scheduling', 'ID' => 20, 'post_status' => 'trash' )
		);

		$this->assertSame(
			'<a data-confirm="Deleting this calendar cancels 2 open appointments (pending, confirmed or waitlisted) and e-mails each person. Delete it permanently?" href="post.php?action=delete" class="submitdelete">Delete Permanently</a>',
			$result['delete']
		);
		$this->assertStringContainsString( 'status IN (pending,confirmed,waitlist)', $this->queries[1] );
	}

	public function test_permanent_delete_link_is_left_alone_with_nothing_to_cancel_or_outside_the_trash(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'current_time' )->justReturn( '2026-01-01' );
		$this->calendars[20] = array( 'id' => 5, 'post_id' => 20, 'title' => 'Cal A' );
		$link                = '<a href="post.php?action=delete">Delete Permanently</a>';
		$cpt                 = new SelfSchedulingCPT();

		$empty = $cpt->add_duplicate_link( array( 'delete' => $link ), (object) array( 'post_type' => 'ffc_self_scheduling', 'ID' => 20, 'post_status' => 'trash' ) );
		$this->assertSame( $link, $empty['delete'] );

		$this->appointments[5] = array( array( 'id' => 1 ) );
		$published             = $cpt->add_duplicate_link( array( 'delete' => $link ), (object) array( 'post_type' => 'ffc_self_scheduling', 'ID' => 20, 'post_status' => 'publish' ) );
		$this->assertSame( $link, $published['delete'] );
	}

	/**
	 * Duplicating creates a calendar, so the link and its handler both take
	 * the post type's write cap, never the bookings' one (#1769).
	 *
	 * @return array<string, array{0: list<string>, 1: bool}>
	 */
	public function duplicate_tiers(): array {
		return array(
			'calendars view-only tier'      => array( array( 'ffc_view_calendars' ), false ),
			'appointments manage tier only' => array( array( 'ffc_view_calendars', 'ffc_manage_appointments' ), false ),
			'calendars manage tier'         => array( array( 'ffc_view_calendars', 'ffc_manage_calendars' ), true ),
		);
	}

	/**
	 * @dataProvider duplicate_tiers
	 *
	 * @param list<string> $caps    Capabilities held.
	 * @param bool         $allowed Whether the tier may duplicate.
	 */
	public function test_duplicate_link_renders_only_for_the_calendars_write_cap( array $caps, bool $allowed ): void {
		Functions\when( 'current_user_can' )->alias( static fn( string $cap ): bool => in_array( $cap, $caps, true ) );

		$cpt    = new SelfSchedulingCPT();
		$result = $cpt->add_duplicate_link( array( 'edit' => 'Edit' ), (object) array( 'post_type' => 'ffc_self_scheduling', 'ID' => 1 ) );

		$this->assertSame( $allowed, isset( $result['duplicate'] ) );
	}

	/**
	 * @dataProvider duplicate_tiers
	 *
	 * @param list<string> $caps    Capabilities held.
	 * @param bool         $allowed Whether the tier may duplicate.
	 */
	public function test_duplication_handler_checks_the_same_cap_as_the_link( array $caps, bool $allowed ): void {
		Functions\when( 'current_user_can' )->alias( static fn( string $cap ): bool => in_array( $cap, $caps, true ) );
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'wp_die' )->alias(
			static function (): void {
				throw new \RuntimeException( 'refused' );
			}
		);
		// The nonce check is the step after the capability gate.
		Functions\when( 'check_admin_referer' )->alias(
			static function (): void {
				throw new \RuntimeException( 'passed the capability gate' );
			}
		);

		$cpt = new SelfSchedulingCPT();
		$this->expectExceptionMessage( $allowed ? 'passed the capability gate' : 'refused' );
		$cpt->handle_calendar_duplication();
	}

	// ==================================================================
	// handle_calendar_duplication() — no permission
	// ==================================================================

	public function test_handle_calendar_duplication_dies_without_permission(): void {
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'wp_die' )->alias( function ( $msg ) {
			throw new \RuntimeException( $msg );
		} );

		$cpt = new SelfSchedulingCPT();
		$this->expectException( \RuntimeException::class );
		$cpt->handle_calendar_duplication();
	}

	// ==================================================================
	// sync_calendar_data() — autosave skip
	// ==================================================================

	/**
	 * Its own process: it defines DOING_AUTOSAVE, a constant no later test may inherit.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_sync_calendar_data_skips_autosave(): void {
		if ( ! defined( 'DOING_AUTOSAVE' ) ) {
			define( 'DOING_AUTOSAVE', true );
		}

		$cpt = new SelfSchedulingCPT();
		$post = (object) array( 'post_status' => 'publish', 'post_title' => 'Test' );
		$cpt->sync_calendar_data( 1, $post, true );

		// #1030: this asserted nothing. Past the guards sync_calendar_data()
		// looks the calendar up by post id before creating or updating it, so
		// a database never consulted is the proof the guard returned.
		$this->assertDatabaseUntouched();
	}

	// ==================================================================
	// cleanup_calendar_data() — wrong post type
	// ==================================================================

	public function test_cleanup_calendar_data_skips_wrong_post_type(): void {
		$cpt = new SelfSchedulingCPT();
		$post = (object) array( 'post_type' => 'post' );
		$cpt->cleanup_calendar_data( 1, $post );

		// Past the post-type guard cleanup_calendar_data() looks the calendar up
		// before deleting it — the sibling no-record test asserts on the same
		// lookup.
		$this->assertDatabaseUntouched();
	}

	// ==================================================================
	// handle_calendar_duplication() — invalid calendar
	// ==================================================================

	public function test_handle_calendar_duplication_dies_for_invalid_calendar(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'check_admin_referer' )->justReturn( true );
		Functions\when( 'get_post' )->justReturn( null );
		Functions\when( 'wp_die' )->alias( function ( $msg ) {
			throw new \RuntimeException( $msg );
		} );

		$_GET['post'] = '5';

		$cpt = new SelfSchedulingCPT();
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Invalid calendar.' );
		$cpt->handle_calendar_duplication();
	}

	// ==================================================================
	// handle_calendar_duplication() — success copies metadata + redirects
	// ==================================================================

	public function test_handle_calendar_duplication_copies_metadata_and_redirects(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 7 );
		Functions\when( 'check_admin_referer' )->justReturn( true );
		Functions\when( 'get_post' )->justReturn(
			(object) array( 'ID' => 5, 'post_type' => 'ffc_self_scheduling', 'post_title' => 'My Cal' )
		);
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_insert_post' )->justReturn( 99 );

		$meta = array(
			'_ffc_self_scheduling_config'        => array( 'slot_duration' => 30 ),
			'_ffc_self_scheduling_working_hours' => array( 'mon' => '9-5' ),
			'_ffc_self_scheduling_email_config'  => array( 'enabled' => 1 ),
			'_ffc_self_scheduling_names'         => array( 'cell' => array( 'closed' => 'Fechado\\' ) ),
		);
		Functions\when( 'get_post_meta' )->alias( function ( $id, $key ) use ( $meta ) {
			return $meta[ $key ] ?? '';
		} );
		Functions\when( 'wp_slash' )->alias( static fn( $value ) => is_array( $value ) ? array_map( static fn( $v ) => is_array( $v ) ? array_map( 'addslashes', $v ) : addslashes( $v ), $value ) : addslashes( $value ) );

		$updated = array();
		$values  = array();
		Functions\when( 'update_post_meta' )->alias( function ( $id, $key, $val ) use ( &$updated, &$values ) {
			$updated[]      = $key;
			$values[ $key ] = $val;
			return true;
		} );

		// wp_safe_redirect → exit; throw to stop before exit.
		Functions\when( 'wp_safe_redirect' )->alias( function () {
			throw new \RuntimeException( 'redirected' );
		} );

		$_GET['post'] = '5';

		$cpt = new SelfSchedulingCPT();
		try {
			$cpt->handle_calendar_duplication();
			$this->fail( 'Expected the redirect.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'redirected', $e->getMessage() );
		}

		$this->assertSame(
			array( '_ffc_self_scheduling_config', '_ffc_self_scheduling_working_hours', '_ffc_self_scheduling_email_config', '_ffc_self_scheduling_names' ),
			$updated,
			'The copy must carry the original calendar\'s metadata, its own names included (#2223).'
		);
		$this->assertSame( 'Fechado\\\\', $values['_ffc_self_scheduling_names']['cell']['closed'], 'The names are slashed once, for the unslash update_post_meta() applies.' );
	}

	public function test_handle_calendar_duplication_copies_custom_blocks(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 7 );
		Functions\when( 'check_admin_referer' )->justReturn( true );
		Functions\when( 'get_post' )->justReturn(
			(object) array( 'ID' => 5, 'post_type' => 'ffc_self_scheduling', 'post_title' => 'My Cal' )
		);
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_insert_post' )->justReturn( 99 );
		Functions\when( 'wp_slash' )->returnArg();

		// A legacy JSON string with one malformed row: the copy stores the
		// normalised array, the same shape the save handler writes (#1770).
		$meta = array(
			'_ffc_self_scheduling_config'       => array( 'schedule_type' => 'custom' ),
			'_ffc_self_scheduling_custom_slots' => json_encode(
				array(
					array( 'date' => '2026-11-03', 'start' => '09:00', 'end' => '10:00', 'capacity' => 3, 'label' => 'Morning' ),
					array( 'date' => '', 'start' => '11:00', 'end' => '12:00' ),
				)
			),
		);
		Functions\when( 'get_post_meta' )->alias(
			function ( $id, $key ) use ( $meta ) {
				return 5 === $id ? ( $meta[ $key ] ?? '' ) : '';
			}
		);

		$updated = array();
		Functions\when( 'update_post_meta' )->alias(
			function ( $id, $key, $val ) use ( &$updated ) {
				$updated[ $id ][ $key ] = $val;
				return true;
			}
		);

		Functions\when( 'wp_safe_redirect' )->alias(
			function () {
				throw new \RuntimeException( 'redirected' );
			}
		);

		$_GET['post'] = '5';

		$cpt = new SelfSchedulingCPT();
		try {
			$cpt->handle_calendar_duplication();
			$this->fail( 'Expected the redirect.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'redirected', $e->getMessage() );
		}

		$this->assertSame(
			array(
				array( 'date' => '2026-11-03', 'start' => '09:00', 'end' => '10:00', 'capacity' => 3, 'label' => 'Morning' ),
			),
			$updated[99]['_ffc_self_scheduling_custom_slots'] ?? null,
			'The copy must carry the original calendar\'s custom blocks.'
		);
		$this->assertArrayNotHasKey( 5, $updated, 'Duplicating must never write to the original calendar.' );
	}

	// ==================================================================
	// handle_calendar_duplication() — insert failure dies with error
	// ==================================================================

	public function test_handle_calendar_duplication_dies_on_insert_error(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 7 );
		Functions\when( 'check_admin_referer' )->justReturn( true );
		Functions\when( 'get_post' )->justReturn(
			(object) array( 'ID' => 5, 'post_type' => 'ffc_self_scheduling', 'post_title' => 'My Cal' )
		);
		Functions\when( 'is_wp_error' )->justReturn( true );

		$error = Mockery::mock( 'WP_Error' );
		$error->shouldReceive( 'get_error_message' )->andReturn( 'insert failed' );
		Functions\when( 'wp_insert_post' )->justReturn( $error );

		Functions\when( 'wp_die' )->alias( function ( $msg ) {
			throw new \RuntimeException( $msg );
		} );

		$_GET['post'] = '5';

		$cpt = new SelfSchedulingCPT();
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'insert failed' );
		$cpt->handle_calendar_duplication();
	}

	// ==================================================================
	// sync_calendar_data() — revision skip
	// ==================================================================

	public function test_sync_calendar_data_skips_revision(): void {
		Functions\when( 'wp_is_post_revision' )->justReturn( true );

		$cpt  = new SelfSchedulingCPT();
		$post = (object) array( 'post_status' => 'publish', 'post_title' => 'X' );
		$cpt->sync_calendar_data( 1, $post, true );

		// #1030: this asserted nothing. Past the guards sync_calendar_data()
		// looks the calendar up by post id before creating or updating it, so
		// a database never consulted is the proof the guard returned.
		$this->assertDatabaseUntouched();
	}

	// ==================================================================
	// sync_calendar_data() — non-publish skip
	// ==================================================================

	public function test_sync_calendar_data_skips_non_publish(): void {
		Functions\when( 'wp_is_post_revision' )->justReturn( false );

		$cpt  = new SelfSchedulingCPT();
		$post = (object) array( 'post_status' => 'draft', 'post_title' => 'X' );
		$cpt->sync_calendar_data( 1, $post, true );

		// #1030: this asserted nothing. Past the guards sync_calendar_data()
		// looks the calendar up by post id before creating or updating it, so
		// a database never consulted is the proof the guard returned.
		$this->assertDatabaseUntouched();
	}

	// ==================================================================
	// sync_calendar_data() — creates a new record when none exists
	// ==================================================================

	public function test_sync_calendar_data_creates_new_record(): void {
		Functions\when( 'wp_is_post_revision' )->justReturn( false );
		Functions\when( 'current_time' )->justReturn( '2026-01-01 00:00:00' );
		Functions\when( 'get_current_user_id' )->justReturn( 3 );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		// createFromPost() defaults the title from the post; the synced data
		// must override it with the title being saved.
		Functions\when( 'get_the_title' )->justReturn( 'Stale title' );

		$config = wp_json_encode( array( 'description' => 'desc', 'slot_duration' => 45 ) );
		Functions\when( 'get_post_meta' )->alias( function ( $id, $key ) use ( $config ) {
			if ( '_ffc_self_scheduling_config' === $key ) {
				return $config;
			}
			if ( '_ffc_self_scheduling_working_hours' === $key ) {
				return array( 'mon' => '9-5' );
			}
			return array( 'enabled' => 1 );
		} );

		$cpt  = new SelfSchedulingCPT();
		$post = (object) array( 'post_status' => 'publish', 'post_title' => 'New Cal' );
		$cpt->sync_calendar_data( 12, $post, false );

		$this->assertSame(
			array( 'SELECT * FROM ' . self::CALENDARS . ' WHERE post_id = 12' ),
			$this->queries,
			'The calendar is looked up by post id first.'
		);
		$this->assertSame( array(), $this->updates, 'No existing record, so nothing is updated.' );
		$this->assertCount( 1, $this->inserts, 'A calendar with no record is created once.' );

		list( $table, $data ) = $this->inserts[0];
		$this->assertSame( self::CALENDARS, $table );
		$this->assertSame( 12, $data['post_id'] );
		$this->assertSame( 'desc', $data['description'] );
		$this->assertSame( 45, $data['slot_duration'] );
		$this->assertSame( 'New Cal', $data['title'] );
		$this->assertSame( '2026-01-01 00:00:00', $data['created_at'] );
		$this->assertSame( 3, $data['created_by'] );
	}

	// ==================================================================
	// sync_calendar_data() — updates an existing record
	// ==================================================================

	public function test_sync_calendar_data_updates_existing_record(): void {
		Functions\when( 'wp_is_post_revision' )->justReturn( false );
		Functions\when( 'current_time' )->justReturn( '2026-01-01 00:00:00' );
		Functions\when( 'get_current_user_id' )->justReturn( 3 );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'get_post_meta' )->justReturn( '' );

		$this->calendars[12] = array(
			'id'      => 88,
			'post_id' => 12,
			'title'   => 'Existing',
		);

		$cpt  = new SelfSchedulingCPT();
		$post = (object) array( 'post_status' => 'publish', 'post_title' => 'Existing' );
		$cpt->sync_calendar_data( 12, $post, true );

		$this->assertSame( array(), $this->inserts, 'An existing record is never created again.' );
		$this->assertCount( 1, $this->updates, 'The existing record is updated once.' );
		$this->assertSame( self::CALENDARS, $this->updates[0][0] );
		$this->assertSame( array( 'id' => 88 ), $this->updates[0][2] );
		$this->assertSame( 'Existing', $this->updates[0][1]['title'] );
	}

	// ==================================================================
	// cleanup_calendar_data() — deletes record + cancels appointments
	// ==================================================================

	public function test_cleanup_calendar_data_deletes_and_cancels_future(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 3 );
		Functions\when( 'current_time' )->alias(
			static function ( $type ) {
				return 'Y-m-d' === $type ? '2026-01-01' : '2026-01-01 00:00:00';
			}
		);
		Functions\when( 'is_email' )->justReturn( true );
		Functions\when( 'get_bloginfo' )->justReturn( 'Site' );
		$mails = array();
		Functions\when( 'wp_mail' )->alias(
			static function ( $to, $subj, $body ) use ( &$mails ) {
				$mails[] = compact( 'to', 'subj', 'body' );
				return true;
			}
		);
		// EmailService::send() reads the global kill-switch via SettingsReader
		// (#662); ffc_settings / ffc_email_template resolve to arrays (emails
		// enabled + chrome defaults), any other key (admin_email) to a string.
		Functions\when( 'get_option' )->alias(
			static function ( $key, $default = false ) {
				return in_array( $key, array( 'ffc_settings', 'ffc_email_template' ), true ) ? array() : '';
			}
		);
		// The cancellation email now renders through the shared chrome.
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'wp_kses_post' )->returnArg();
		Functions\when( 'home_url' )->justReturn( 'https://example.com' );
		Functions\when( 'wp_timezone' )->justReturn( new \DateTimeZone( 'UTC' ) );
		// DateFormatter runs for real; this formats the way wp_date() does.
		Functions\when( 'wp_date' )->alias(
			static function ( $format, $timestamp = null, $timezone = null ) {
				$tz = $timezone instanceof \DateTimeZone ? $timezone : new \DateTimeZone( 'UTC' );
				return ( new \DateTimeImmutable( '@' . ( $timestamp ?? time() ) ) )->setTimezone( $tz )->format( $format );
			}
		);

		$this->calendars[20]   = array(
			'id'      => 5,
			'post_id' => 20,
			'title'   => 'Cal A',
		);
		$this->appointments[5] = array(
			array(
				'id'               => 100,
				'calendar_id'      => 5,
				'status'           => 'confirmed',
				'appointment_date' => '2026-02-01',
				'start_time'       => '09:00:00',
				'email_encrypted'  => (string) Encryption::encrypt( 'user@example.com' ),
			),
			// A waitlisted person is told too (#2244).
			array(
				'id'               => 101,
				'calendar_id'      => 5,
				'status'           => 'waitlist',
				'appointment_date' => '2026-02-01',
				'start_time'       => '09:00:00',
				'email_encrypted'  => (string) Encryption::encrypt( 'queued@example.com' ),
			),
		);

		$cpt  = new SelfSchedulingCPT();
		$post = (object) array( 'post_type' => 'ffc_self_scheduling' );
		$cpt->cleanup_calendar_data( 20, $post );

		// Future appointments are read from the site's today onwards.
		$this->assertStringContainsString(
			'WHERE calendar_id = 5 AND appointment_date >= 2026-01-01',
			$this->queries[1] ?? '',
			'The cancellation reads the calendar\'s appointments from today on.'
		);

		$this->assertStringContainsString( "status IN (pending,confirmed,waitlist)", $this->queries[1] ?? '', 'Waitlisted rows are cancelled with the rest.' );

		// Both open appointments are cancelled, by the deleting user, each over
		// the status it was read with.
		$this->assertCount( 2, $this->updates );
		$this->assertSame( array( 'id' => 101, 'status' => 'waitlist' ), $this->updates[1][2] );
		list( $table, $data, $where ) = $this->updates[0];
		$this->assertSame( self::APPOINTMENTS, $table );
		$this->assertSame( array( 'id' => 100, 'status' => 'confirmed' ), $where );
		$this->assertSame( 'cancelled', $data['status'] );
		$this->assertSame( 3, $data['cancelled_by'] );
		$this->assertSame( 'Calendar "Cal A" was deleted', $data['cancellation_reason'] );

		// The calendar record is deleted.
		$this->assertSame( array( array( self::CALENDARS, array( 'id' => 5 ) ) ), $this->deletes );

		// The deletion email is now the tokenized global default (#965): its body
		// carries the calendar title + wall-clock date/time resolved through the
		// token map, and its subject the calendar-title token (#976 standard —
		// "Event: {{ref}}", no bracketed site-name prefix).
		$this->assertSame( array( 'user@example.com', 'queued@example.com' ), array_column( $mails, 'to' ) );
		$captured_mail = $mails[0];
		$this->assertStringContainsString( 'Cal A', $captured_mail['body'] );
		$this->assertStringContainsString( '01/02/2026', $captured_mail['body'] );
		$this->assertSame( 'Appointment cancelled: Cal A', $captured_mail['subj'] );
	}

	// ==================================================================
	// cleanup_calendar_data() — no calendar record found (no-op)
	// ==================================================================

	public function test_cleanup_calendar_data_noop_when_no_record(): void {
		$cpt  = new SelfSchedulingCPT();
		$post = (object) array( 'post_type' => 'ffc_self_scheduling' );
		$cpt->cleanup_calendar_data( 20, $post );

		$this->assertSame(
			array( 'SELECT * FROM ' . self::CALENDARS . ' WHERE post_id = 20' ),
			$this->queries,
			'Only the calendar lookup runs; no appointment is read.'
		);
		$this->assertSame( array(), $this->deletes, 'No record, so nothing is deleted.' );
		$this->assertSame( array(), $this->updates, 'No record, so nothing is cancelled.' );
	}
}
