<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Audience\AudienceRecordAutosave;
use FreeFormCertificate\Audience\AudienceSelfJoinScope;
use FreeFormCertificate\Core\RecordAutosave;
use FreeFormCertificate\DateMessages\DateMessagesAdminPage;
use FreeFormCertificate\DateMessages\DateMessagesRecordAutosave;
use FreeFormCertificate\DateMessages\Runner;
use FreeFormCertificate\Recruitment\RecruitmentRecordAutosave;
use FreeFormCertificate\Reregistration\ReregistrationRecordAutosave;
use FreeFormCertificate\UrlShortener\UrlShortenerRecordAutosave;
use FreeFormCertificate\UrlShortener\UrlShortenerRepository;
use FreeFormCertificate\UrlShortener\UrlShortenerService;

/**
 * The entities each module registers with the record autosave (#1757):
 * which fields each one allows, and what its save callback writes.
 *
 * Everything below the entity is the real class: RecordAutosave keeps the
 * registry (read back from its static map, reset around each test), and the
 * save callbacks run the module's own readers and writers — the audience,
 * calendar and booking repositories, the reregistration repository, the
 * recruitment notice and reason writers, the date-message rule reader,
 * writer and Runner, the short URL service and repository. A `$wpdb` double
 * answers each read from the rows a test stores, by table and id, records
 * every UPDATE and raw query, and fails on any query it does not know.
 *
 * @covers \FreeFormCertificate\Audience\AudienceRecordAutosave
 * @covers \FreeFormCertificate\Reregistration\ReregistrationRecordAutosave
 * @covers \FreeFormCertificate\Recruitment\RecruitmentRecordAutosave
 * @covers \FreeFormCertificate\DateMessages\DateMessagesRecordAutosave
 * @covers \FreeFormCertificate\UrlShortener\UrlShortenerRecordAutosave
 */
class RecordAutosaveEntitiesTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var array<string, array<string, mixed>> Stored rows by `table:id`. */
	private array $rows = array();

	/** @var array<int, list<array<string, mixed>>> Child audience rows by parent id. */
	private array $children = array();

	/** @var list<array{table: string, data: array<string, mixed>, where: array<string, mixed>}> Every UPDATE the double received. */
	private array $updates = array();

	/** @var list<int|false> What the next update() calls answer, in order; 1 once empty. */
	private array $update_results = array();

	/** @var list<string> Every raw query() the double ran. */
	private array $queries = array();

	/** @var list<array<int, mixed>> Every wp_clear_scheduled_hook() call. */
	private array $cleared = array();

	/** @var list<array<int, mixed>> Every wp_schedule_single_event() call. */
	private array $scheduled = array();

	/** @var mixed The `$wpdb` global an earlier test left, restored afterwards. */
	private $wpdb_backup;

	/** @var bool Whether `$wpdb` was set before this test. */
	private bool $had_wpdb = false;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		RecordAutosave::reset();

		$this->rows           = array();
		$this->children       = array();
		$this->updates        = array();
		$this->update_results = array();
		$this->queries        = array();
		$this->cleared        = array();
		$this->scheduled      = array();

		Functions\when( '__' )->returnArg();
		Functions\when( 'is_wp_error' )->alias( static fn( $v ) => $v instanceof \WP_Error );
		Functions\when( 'wp_json_encode' )->alias( static fn( $v ) => json_encode( $v ) );
		Functions\when( 'sanitize_text_field' )->alias( static fn( $v ) => trim( (string) $v ) );
		Functions\when( 'sanitize_sql_orderby' )->returnArg();
		Functions\when( 'absint' )->alias( static fn( $v ) => abs( (int) $v ) );
		Functions\when( 'wp_parse_args' )->alias( static fn( $args, $defaults ) => array_merge( $defaults, (array) $args ) );
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		// Also what Debug::is_enabled() would read; every option is unset here.
		Functions\when( 'get_option' )->alias( static fn( $key, $default = false ) => $default );
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'current_time' )->justReturn( '2026-10-28 12:00:00' );
		Functions\when( 'do_action' )->justReturn( null );
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( 'get_current_user_id' )->justReturn( 7 );
		Functions\when( 'get_post' )->alias(
			static function ( $id ) {
				if ( 40 !== (int) $id && 41 !== (int) $id ) {
					return null;
				}
				$post              = new \WP_Post();
				$post->post_type   = 'ffc_form';
				$post->post_status = 'publish';
				return $post;
			}
		);
		Functions\when( 'wp_clear_scheduled_hook' )->alias(
			function ( ...$args ) {
				$this->cleared[] = $args;
				return 0;
			}
		);
		Functions\when( 'wp_schedule_single_event' )->alias(
			function ( ...$args ) {
				$this->scheduled[] = $args;
				return true;
			}
		);

		$this->install_wpdb();
	}

	protected function tearDown(): void {
		if ( $this->had_wpdb ) {
			$GLOBALS['wpdb'] = $this->wpdb_backup;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		RecordAutosave::reset();
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * The `$wpdb` double. prepare() interpolates, so each read is matched by
	 * its SQL text: a row by `FROM `table`` and `id = N`, an audience's
	 * children by `parent_id = N`. A stored row takes every successful UPDATE.
	 */
	private function install_wpdb(): void {
		$wpdb             = Mockery::mock( 'wpdb' );
		$wpdb->prefix     = 'wp_';
		$wpdb->last_error = '';
		$wpdb->insert_id  = 0;
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			static function ( $sql, ...$args ) {
				if ( 1 === count( $args ) && is_array( $args[0] ) ) {
					$args = $args[0];
				}
				$values = array_values( $args );
				return preg_replace_callback(
					'/%[ids]/',
					static function ( array $m ) use ( &$values ): string {
						$value = array_shift( $values );
						if ( '%i' === $m[0] ) {
							return '`' . $value . '`';
						}
						return '%d' === $m[0] ? (string) (int) $value : "'" . $value . "'";
					},
					(string) $sql
				);
			}
		);
		$wpdb->shouldReceive( 'get_row' )->andReturnUsing(
			function ( $sql, $output = OBJECT ) {
				$sql = (string) $sql;
				if ( ! preg_match( '/FROM `(\w+)`/', $sql, $table ) || ! preg_match( '/\bid = (\d+)$/', trim( $sql ), $id ) ) {
					$this->fail( 'Unexpected get_row(): ' . $sql );
				}
				$row = $this->rows[ $table[1] . ':' . $id[1] ] ?? null;
				if ( null === $row ) {
					return null;
				}
				return ARRAY_A === $output ? $row : (object) $row;
			}
		);
		$wpdb->shouldReceive( 'get_results' )->andReturnUsing(
			function ( $sql ) {
				$sql = (string) $sql;
				if ( false !== strpos( $sql, 'WHERE ba.booking_id = ' ) ) {
					return array();
				}
				if ( 0 === strpos( $sql, 'SELECT * FROM `wp_ffc_audiences` WHERE parent_id = ' ) && preg_match( '/parent_id = (\d+)/', $sql, $m ) ) {
					return array_map( static fn( array $row ) => (object) $row, $this->children[ (int) $m[1] ] ?? array() );
				}
				$this->fail( 'Unexpected get_results(): ' . $sql );
			}
		);
		$wpdb->shouldReceive( 'get_col' )->andReturnUsing(
			function ( $sql ) {
				$sql = (string) $sql;
				if ( 0 === strpos( $sql, 'SELECT user_id FROM `wp_ffc_audience_booking_users`' )
					|| 0 === strpos( $sql, 'SELECT DISTINCT user_id FROM `wp_ffc_audience_members`' ) ) {
					return array();
				}
				$this->fail( 'Unexpected get_col(): ' . $sql );
			}
		);
		$wpdb->shouldReceive( 'update' )->andReturnUsing(
			function ( $table, $data, $where ) {
				$this->updates[] = array(
					'table' => (string) $table,
					'data'  => $data,
					'where' => $where,
				);
				$result = array() === $this->update_results ? 1 : array_shift( $this->update_results );
				$key    = $table . ':' . ( $where['id'] ?? 0 );
				if ( false !== $result && isset( $this->rows[ $key ] ) ) {
					$this->rows[ $key ] = array_merge( $this->rows[ $key ], $data );
				}
				return $result;
			}
		);
		$wpdb->shouldReceive( 'query' )->andReturnUsing(
			function ( $sql ) {
				$this->queries[] = (string) $sql;
				return 1;
			}
		);

		$this->had_wpdb    = array_key_exists( 'wpdb', $GLOBALS );
		$this->wpdb_backup = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb']   = $wpdb;
	}

	/**
	 * What an entity registered, read from the real registry.
	 *
	 * @param string $entity Entity key.
	 * @return array<string, mixed>
	 */
	private function registered( string $entity ): array {
		$prop = new \ReflectionProperty( RecordAutosave::class, 'entities' );
		$prop->setAccessible( true );
		$entities = $prop->getValue();
		$this->assertArrayHasKey( $entity, $entities );
		return $entities[ $entity ];
	}

	/**
	 * The UPDATEs the double received on one table.
	 *
	 * @param string $table Table name, prefixed.
	 * @return list<array{table: string, data: array<string, mixed>, where: array<string, mixed>}>
	 */
	private function updates_of( string $table ): array {
		return array_values( array_filter( $this->updates, static fn( array $u ): bool => $table === $u['table'] ) );
	}

	/**
	 * Store an audience row.
	 *
	 * @param array<string, mixed> $row Columns.
	 */
	private function audience( array $row ): void {
		$this->rows[ 'wp_ffc_audiences:' . $row['id'] ] = $row;
	}

	// ------------------------------------------------------------------
	// Audience
	// ------------------------------------------------------------------

	public function test_the_calendar_and_audience_register_their_toggles(): void {
		AudienceRecordAutosave::register();

		$schedule = $this->registered( 'audience_schedule' );
		$this->assertSame( 'ffc_manage_audiences', $schedule['cap'] );
		$this->assertSame(
			array_merge( AudienceRecordAutosave::SCHEDULE_FLAGS, array( 'status', 'visibility' ) ),
			array_keys( $schedule['fields'] )
		);
		$this->assertSame( array( 1 => 'public', 0 => 'private' ), $schedule['fields']['visibility']['map'] );
		$this->assertSame( array( 'status', 'allow_self_join', 'self_join_scope', 'members_only', 'require_membership' ), array_keys( $this->registered( 'audience' )['fields'] ) );
		$this->assertSame(
			array(
				'type'    => 'enum',
				'options' => array( 'off', 'self', 'all', 'descendants' ),
			),
			$this->registered( 'audience' )['fields']['self_join_scope']
		);
	}

	public function test_the_booking_registers_its_attendance_and_post_event_fields(): void {
		AudienceRecordAutosave::register();

		$booking = $this->registered( 'audience_booking' );
		$this->assertSame( 'ffc_manage_audiences', $booking['cap'] );
		$this->assertSame(
			array( 'attendance_mode', 'attendance_min_percent', 'post_event_custom', 'post_event_form_id', 'post_event_window_days', 'post_event_attendees_only' ),
			array_keys( $booking['fields'] )
		);
		$this->assertSame( 1, $booking['fields']['attendance_min_percent']['min'] );
		$this->assertSame( 90, $booking['fields']['post_event_window_days']['max'] );
		$this->assertSame( array( AudienceRecordAutosave::class, 'booking_fragments' ), $booking['fragments'] );
	}

	/**
	 * The booking the reader answers, and the calendar the booking follows.
	 *
	 * @param array<string, mixed> $booking  Booking columns.
	 * @param array<string, mixed> $schedule Calendar columns.
	 */
	private function booking_world( array $booking, array $schedule = array() ): void {
		$this->rows['wp_ffc_audience_bookings:5']  = array_merge( array( 'id' => 5, 'schedule_id' => 3 ), $booking );
		$this->rows['wp_ffc_audience_schedules:3'] = array_merge(
			array(
				'id'                        => 3,
				'post_event_form_id'        => '40',
				'post_event_window_days'    => '10',
				'post_event_attendees_only' => '1',
			),
			$schedule
		);
	}

	public function test_the_booking_fragments_are_the_attendance_screen_s_for_the_stored_row(): void {
		// Four booked days at 75%: three are required. The calendar names no form.
		$this->booking_world(
			array(
				'attendance_mode'        => 'total',
				'attendance_min_percent' => '75',
				'booking_date'           => '2027-01-15',
				'end_date'               => '2027-01-18',
			),
			array( 'post_event_form_id' => '0' )
		);

		$this->assertSame(
			array(
				'ffc-attendance-rule-summary'      => 'As saved, 3 of the 4 booked day(s) are required.',
				'ffc-attendance-post-event-status' => 'Its calendar names no form, so this booking invites nobody.',
				'ffc-post-event-no-page'           => '',
			),
			AudienceRecordAutosave::booking_fragments( 5 )
		);
	}

	public function test_a_booking_gone_has_no_fragments(): void {
		$this->assertSame( array(), AudienceRecordAutosave::booking_fragments( 5 ) );
	}

	/**
	 * The one UPDATE a booking save issued: its columns, on booking 5.
	 *
	 * @return array<string, mixed>
	 */
	private function booking_write(): array {
		$writes = $this->updates_of( 'wp_ffc_audience_bookings' );
		$this->assertCount( 1, $writes );
		$this->assertSame( array( 'id' => 5 ), $writes[0]['where'] );
		return $writes[0]['data'];
	}

	public function test_choosing_share_of_days_keeps_the_stored_share_or_starts_from_the_default(): void {
		$this->booking_world( array( 'attendance_mode' => null, 'attendance_min_percent' => null ) );

		$this->assertTrue( AudienceRecordAutosave::save_booking( 5, 'attendance_mode', 'total' ) );
		$this->assertSame( array( 'attendance_mode' => 'total', 'attendance_min_percent' => 75 ), $this->booking_write() );
	}

	public function test_a_share_typed_saves_under_share_of_days(): void {
		$this->booking_world( array( 'attendance_mode' => 'total', 'attendance_min_percent' => '75' ) );

		$this->assertTrue( AudienceRecordAutosave::save_booking( 5, 'attendance_min_percent', 50 ) );
		$this->assertSame( array( 'attendance_mode' => 'total', 'attendance_min_percent' => 50 ), $this->booking_write() );
	}

	public function test_switching_to_own_settings_starts_from_the_calendar_s(): void {
		$this->booking_world( array( 'post_event_custom' => '0' ) );

		$this->assertTrue( AudienceRecordAutosave::save_booking( 5, 'post_event_custom', 1 ) );
		$this->assertSame(
			array(
				'post_event_custom'         => 1,
				'post_event_form_id'        => 40,
				'post_event_window_days'    => 10,
				'post_event_attendees_only' => 1,
			),
			$this->booking_write()
		);
	}

	public function test_one_own_setting_changes_and_the_others_keep_their_value(): void {
		$this->booking_world(
			array(
				'post_event_custom'         => '1',
				'post_event_form_id'        => '40',
				'post_event_window_days'    => '7',
				'post_event_attendees_only' => '0',
			)
		);

		$this->assertTrue( AudienceRecordAutosave::save_booking( 5, 'post_event_form_id', 41 ) );
		$this->assertSame(
			array(
				'post_event_custom'         => 1,
				'post_event_form_id'        => 41,
				'post_event_window_days'    => 7,
				'post_event_attendees_only' => 0,
			),
			$this->booking_write()
		);
	}

	public function test_a_form_that_is_not_a_published_form_is_refused_and_nothing_written(): void {
		$this->booking_world( array( 'post_event_custom' => '1' ) );

		$this->assertInstanceOf( \WP_Error::class, AudienceRecordAutosave::save_booking( 5, 'post_event_form_id', 99 ) );
		$this->assertSame( array(), $this->updates );
	}

	public function test_a_failed_booking_write_is_an_error(): void {
		$this->booking_world( array() );
		$this->update_results = array( false );

		$this->assertInstanceOf( \WP_Error::class, AudienceRecordAutosave::save_booking( 5, 'attendance_mode', 'single' ) );
		$this->assertCount( 1, $this->updates_of( 'wp_ffc_audience_bookings' ) );
	}

	public function test_a_calendar_field_is_a_one_column_update(): void {
		$this->assertTrue( AudienceRecordAutosave::save_schedule( 4, 'visibility', 'public' ) );

		$writes = $this->updates_of( 'wp_ffc_audience_schedules' );
		$this->assertCount( 1, $writes );
		$this->assertSame( array( 'visibility' => 'public' ), $writes[0]['data'] );
		$this->assertSame( array( 'id' => 4 ), $writes[0]['where'] );
	}

	public function test_a_failed_calendar_write_is_an_error(): void {
		$this->update_results = array( false );

		$this->assertInstanceOf( \WP_Error::class, AudienceRecordAutosave::save_schedule( 4, 'include_ics', 1 ) );
	}

	public function test_a_parent_s_own_self_join_no_longer_cascades(): void {
		$this->audience( array( 'id' => 9, 'parent_id' => null ) );
		$this->children[9] = array( array( 'id' => 12, 'parent_id' => 9 ) );

		$this->assertTrue( AudienceRecordAutosave::save_audience( 9, 'allow_self_join', 1 ) );

		$writes = $this->updates_of( 'wp_ffc_audiences' );
		$this->assertCount( 1, $writes );
		$this->assertSame( array( 'allow_self_join' => 1 ), $writes[0]['data'] );
		$this->assertSame( array( 'id' => 9 ), $writes[0]['where'] );
		$this->assertSame( array(), $this->queries, 'No cascade reached the child.' );
	}

	/**
	 * @return iterable<string, array{0: string}>
	 */
	public static function scope_choices(): iterable {
		foreach ( array( 'off', 'self', 'all', 'descendants' ) as $scope ) {
			yield $scope => array( $scope );
		}
	}

	/**
	 * The four-option choice (#1901) goes through the writer, which sets the
	 * audience's own flag and every descendant's.
	 *
	 * @dataProvider scope_choices
	 *
	 * @param string $scope The chosen option.
	 */
	public function test_the_scope_choice_is_applied_through_the_writer( string $scope ): void {
		$this->audience( array( 'id' => 9, 'parent_id' => null ) );
		$this->children[9] = array( array( 'id' => 12, 'parent_id' => 9 ) );
		$flags             = AudienceSelfJoinScope::flags( $scope );

		$this->assertTrue( AudienceRecordAutosave::save_audience( 9, 'self_join_scope', $scope ) );

		$writes = $this->updates_of( 'wp_ffc_audiences' );
		$this->assertCount( 1, $writes, 'The audience\'s own flag is the only column update.' );
		$this->assertSame( array( 'allow_self_join' => $flags[0] ), $writes[0]['data'] );
		$this->assertSame( array( 'id' => 9 ), $writes[0]['where'] );
		$this->assertSame(
			array( "UPDATE `wp_ffc_audiences` SET allow_self_join = {$flags[1]} WHERE id IN (12)" ),
			$this->queries,
			'The descendants take the second flag.'
		);
	}

	public function test_a_failed_scope_write_is_an_error(): void {
		$this->audience( array( 'id' => 9, 'parent_id' => null ) );
		$this->update_results = array( false );

		$this->assertInstanceOf( \WP_Error::class, AudienceRecordAutosave::save_audience( 9, 'self_join_scope', 'all' ) );
		$this->assertCount( 1, $this->updates_of( 'wp_ffc_audiences' ) );
		$this->assertSame( array(), $this->queries, 'A failed write does not cascade.' );
	}

	public function test_the_scope_choice_on_a_child_audience_is_refused(): void {
		$this->audience( array( 'id' => 9, 'parent_id' => 3 ) );

		$this->assertInstanceOf( \WP_Error::class, AudienceRecordAutosave::save_audience( 9, 'self_join_scope', 'all' ) );
		$this->assertSame( array(), $this->updates );
		$this->assertSame( array(), $this->queries );
	}

	public function test_self_join_on_a_child_audience_is_refused(): void {
		$this->audience( array( 'id' => 9, 'parent_id' => 3 ) );

		$this->assertInstanceOf( \WP_Error::class, AudienceRecordAutosave::save_audience( 9, 'allow_self_join', 1 ) );
		$this->assertSame( array(), $this->updates );
	}

	/**
	 * The one UPDATE a tree-access save issued on audience 9.
	 *
	 * @return array<string, mixed>
	 */
	private function audience_write(): array {
		$writes = $this->updates_of( 'wp_ffc_audiences' );
		$this->assertCount( 1, $writes );
		$this->assertSame( array( 'id' => 9 ), $writes[0]['where'] );
		return $writes[0]['data'];
	}

	/**
	 * Turning members-only on keeps the stored keep-at-least-one value; the
	 * writer stores the pair (#1918).
	 */
	public function test_members_only_is_written_with_the_stored_keep_flag(): void {
		$this->audience( array( 'id' => 9, 'parent_id' => null, 'members_only' => '0', 'require_membership' => '1' ) );

		$this->assertTrue( AudienceRecordAutosave::save_audience( 9, 'members_only', 1 ) );
		$this->assertSame( array( 'members_only' => 1, 'require_membership' => 1 ), $this->audience_write() );
	}

	public function test_turning_members_only_off_passes_through_the_writer(): void {
		$this->audience( array( 'id' => 9, 'parent_id' => null, 'members_only' => '1', 'require_membership' => '1' ) );

		$this->assertTrue( AudienceRecordAutosave::save_audience( 9, 'members_only', 0 ) );
		// The writer stores "keep one" off whenever members-only is off.
		$this->assertSame( array( 'members_only' => 0, 'require_membership' => 0 ), $this->audience_write() );
	}

	public function test_keep_one_group_saves_under_members_only(): void {
		$this->audience( array( 'id' => 9, 'parent_id' => null, 'members_only' => '1', 'require_membership' => '0' ) );

		$this->assertTrue( AudienceRecordAutosave::save_audience( 9, 'require_membership', 1 ) );
		$this->assertSame( array( 'members_only' => 1, 'require_membership' => 1 ), $this->audience_write() );
	}

	public function test_keep_one_group_without_members_only_is_refused(): void {
		$this->audience( array( 'id' => 9, 'parent_id' => null, 'members_only' => '0', 'require_membership' => '0' ) );

		$this->assertInstanceOf( \WP_Error::class, AudienceRecordAutosave::save_audience( 9, 'require_membership', 1 ) );
		$this->assertSame( array(), $this->updates );
	}

	public function test_tree_access_on_a_child_audience_is_refused(): void {
		$this->audience( array( 'id' => 9, 'parent_id' => 3 ) );

		$this->assertInstanceOf( \WP_Error::class, AudienceRecordAutosave::save_audience( 9, 'members_only', 1 ) );
		$this->assertSame( array(), $this->updates );
	}

	public function test_an_audience_status_does_not_cascade(): void {
		$this->children[9] = array( array( 'id' => 12, 'parent_id' => 9 ) );

		$this->assertTrue( AudienceRecordAutosave::save_audience( 9, 'status', 'inactive' ) );

		$this->assertSame( array( 'status' => 'inactive' ), $this->audience_write() );
		$this->assertSame( array(), $this->queries, 'No self-join cascade ran.' );
	}

	// ------------------------------------------------------------------
	// Reregistration
	// ------------------------------------------------------------------

	public function test_the_campaign_registers_its_flags_and_never_its_status(): void {
		ReregistrationRecordAutosave::register();

		$fields = $this->registered( 'reregistration' )['fields'];
		$this->assertSame( ReregistrationRecordAutosave::FLAGS, array_keys( $fields ) );
		$this->assertArrayNotHasKey( 'status', $fields, 'Moving a campaign to active reopens and seeds submissions; that stays on Save.' );
	}

	public function test_a_campaign_flag_is_a_one_column_update(): void {
		$this->assertTrue( ReregistrationRecordAutosave::save( 5, 'auto_approve', 0 ) );

		$writes = $this->updates_of( 'wp_ffc_reregistrations' );
		$this->assertCount( 1, $writes );
		$this->assertSame( array( 'auto_approve' => 0 ), $writes[0]['data'] );
		$this->assertSame( array( 'id' => 5 ), $writes[0]['where'] );
	}

	// ------------------------------------------------------------------
	// Recruitment
	// ------------------------------------------------------------------

	public function test_the_notice_registers_every_column_but_the_fixed_ones(): void {
		RecruitmentRecordAutosave::register();

		$fields = array_keys( $this->registered( 'recruitment_notice' )['fields'] );
		$this->assertContains( 'preview_reason', $fields );
		$this->assertContains( 'cpf_masked', $fields );
		$this->assertNotContains( 'rank', $fields );
		$this->assertNotContains( 'name', $fields );
		$this->assertSame( 'list', $this->registered( 'recruitment_reason' )['fields']['applies_to']['type'] );
	}

	public function test_a_notice_column_merges_into_the_stored_config(): void {
		$this->rows['wp_ffc_recruitment_notice:3'] = array(
			'id'                    => 3,
			'public_columns_config' => '{"rank":false,"score":true}',
		);

		$this->assertTrue( RecruitmentRecordAutosave::save_notice( 3, 'cpf_masked', 1 ) );

		$writes = $this->updates_of( 'wp_ffc_recruitment_notice' );
		$this->assertCount( 1, $writes );
		$this->assertSame( array( 'id' => 3 ), $writes[0]['where'] );
		$written = json_decode( $writes[0]['data']['public_columns_config'], true );
		$this->assertTrue( $written['cpf_masked'], 'The switched column.' );
		$this->assertTrue( $written['score'], 'A stored column the request did not touch.' );
		$this->assertTrue( $written['rank'], 'A fixed column is forced back on.' );
		$this->assertTrue( $written['adjutancy'], 'A default the stored JSON did not carry.' );
	}

	public function test_a_reason_saves_the_ticked_set(): void {
		$this->assertTrue( RecruitmentRecordAutosave::save_reason( 2, 'applies_to', array( 'denied' ) ) );

		$writes = $this->updates_of( 'wp_ffc_recruitment_reason' );
		$this->assertCount( 1, $writes );
		$this->assertSame( 'denied', $writes[0]['data']['applies_to'] );
		$this->assertSame( array( 'id' => 2 ), $writes[0]['where'] );
	}

	// ------------------------------------------------------------------
	// Date Messages
	// ------------------------------------------------------------------

	/**
	 * Store a dated rule, as the rules table holds it.
	 */
	private function store_rule(): void {
		$this->rows['wp_ffc_date_message_rules:6'] = array(
			'id'              => '6',
			'name'            => 'Birthdays',
			'source'          => 'birthday',
			'offset_days'     => '0',
			'audience_ids'    => '[]',
			'subject'         => 'Happy birthday',
			'body'            => '<p>Hi</p>',
			'send_to_user'    => '1',
			'digest_enabled'  => '0',
			'digest_mode'     => 'summary',
			'digest_user_ids' => '[]',
			'is_active'       => '1',
			'appearance'      => '{"position":"left","text_width":60}',
			'kind'            => 'dated',
			'send_at'         => '0',
			'respect_opt_out' => '1',
		);
	}

	/**
	 * The one UPDATE a rule save issued on rule 6.
	 *
	 * @return array<string, mixed>
	 */
	private function rule_write(): array {
		$writes = $this->updates_of( 'wp_ffc_date_message_rules' );
		$this->assertCount( 1, $writes );
		$this->assertSame( array( 'id' => 6 ), $writes[0]['where'] );
		return $writes[0]['data'];
	}

	public function test_the_rule_registers_its_flags_and_appearance(): void {
		DateMessagesRecordAutosave::register();

		$fields = $this->registered( 'date_message_rule' )['fields'];
		$this->assertSame( array( 'send_to_user', 'is_active', 'digest_enabled', 'appearance_position', 'appearance_text_width' ), array_keys( $fields ) );
		$this->assertSame( array( '50', '60', '70' ), $fields['appearance_text_width']['options'] );
	}

	public function test_a_rule_flag_rebuilds_the_rule_and_saves_it(): void {
		$this->store_rule();

		$this->assertTrue( DateMessagesRecordAutosave::save( 6, 'is_active', 0 ) );

		$saved = $this->rule_write();
		$this->assertSame( 0, $saved['is_active'] );
		$this->assertSame( 'Birthdays', $saved['name'], 'Every other field survives the rebuild.' );
		$this->assertSame( 'left', json_decode( $saved['appearance'], true )['position'] );
	}

	public function test_a_rule_appearance_field_changes_only_that_part(): void {
		$this->store_rule();

		$this->assertTrue( DateMessagesRecordAutosave::save( 6, 'appearance_text_width', '70' ) );

		$saved      = $this->rule_write();
		$appearance = json_decode( $saved['appearance'], true );
		$this->assertSame( 70, $appearance['text_width'] );
		$this->assertSame( 'left', $appearance['position'] );
		$this->assertSame( 1, $saved['is_active'] );
	}

	public function test_a_missing_rule_is_an_error(): void {
		$this->assertInstanceOf( \WP_Error::class, DateMessagesRecordAutosave::save( 6, 'is_active', 1 ) );
		$this->assertSame( array(), $this->updates );
	}

	public function test_a_failed_rule_write_is_an_error(): void {
		$this->store_rule();
		$this->update_results = array( false );

		$this->assertInstanceOf( \WP_Error::class, DateMessagesRecordAutosave::save( 6, 'send_to_user', 0 ) );
		$this->assertCount( 1, $this->updates_of( 'wp_ffc_date_message_rules' ) );
	}

	/**
	 * Store an announcement with a send time an hour ahead.
	 *
	 * @param bool $active Whether it is active.
	 * @return int The send time.
	 */
	private function store_announcement( bool $active ): int {
		$send_at = time() + 3600;
		$this->rows['wp_ffc_date_message_rules:6'] = array(
			'id'        => '6',
			'name'      => 'Closing',
			'kind'      => 'announcement',
			'subject'   => 'Closed',
			'body'      => '<p>x</p>',
			'send_at'   => (string) $send_at,
			'is_active' => $active ? '1' : '0',
		);
		return $send_at;
	}

	public function test_switching_an_announcement_on_schedules_its_send(): void {
		Functions\when( 'current_user_can' )->alias( static fn( $cap ) => 'ffc_send_date_messages' === $cap );
		$send_at = $this->store_announcement( false );

		$this->assertTrue( DateMessagesRecordAutosave::save( 6, 'is_active', 1 ) );

		$this->assertSame( 1, $this->rule_write()['is_active'] );
		$this->assertSame( array( array( Runner::ANNOUNCE_HOOK, array( 6 ) ) ), $this->cleared, 'The earlier event is cleared once.' );
		$this->assertSame( array( array( $send_at, Runner::ANNOUNCE_HOOK, array( 6 ) ) ), $this->scheduled );
	}

	public function test_switching_an_announcement_on_needs_the_right_to_send(): void {
		$this->store_announcement( false );

		$result = DateMessagesRecordAutosave::save( 6, 'is_active', 1 );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( DateMessagesAdminPage::send_refusal(), $result->get_error_message() );
		$this->assertSame( array(), $this->updates );
		$this->assertSame( array(), $this->scheduled );
	}

	public function test_switching_an_announcement_off_clears_its_send(): void {
		$this->store_announcement( true );

		$this->assertTrue( DateMessagesRecordAutosave::save( 6, 'is_active', 0 ) );

		$this->assertSame( 0, $this->rule_write()['is_active'] );
		$this->assertSame( array( array( Runner::ANNOUNCE_HOOK, array( 6 ) ) ), $this->cleared );
		$this->assertSame( array(), $this->scheduled );
	}

	// ------------------------------------------------------------------
	// Short URL
	// ------------------------------------------------------------------

	/**
	 * A real service whose repository's table holds one row.
	 *
	 * @param int                       $id  Row id.
	 * @param array<string, mixed>|null $row The stored row, null for none.
	 */
	private function short_url_service( int $id, ?array $row ): UrlShortenerService {
		unset( $this->rows[ 'wp_ffc_short_urls:' . $id ] );
		if ( null !== $row ) {
			$this->rows[ 'wp_ffc_short_urls:' . $id ] = array_merge( array( 'id' => (string) $id ), $row );
		}

		return new UrlShortenerService( new UrlShortenerRepository() );
	}

	public function test_the_short_url_registers_its_status_as_a_two_state_map(): void {
		UrlShortenerRecordAutosave::register( $this->short_url_service( 3, null ) );

		$def = $this->registered( 'short_url' );
		$this->assertSame( 'ffc_manage_url_shortener', $def['cap'] );
		$this->assertSame(
			array(
				'status' => array(
					'type' => 'map',
					'map'  => array(
						1 => 'active',
						0 => 'disabled',
					),
				),
			),
			$def['fields']
		);
	}

	public function test_a_trashed_or_missing_short_url_has_no_toggle_to_save(): void {
		UrlShortenerRecordAutosave::register( $this->short_url_service( 3, array( 'status' => 'trashed' ) ) );
		$this->assertFalse( ( $this->registered( 'short_url' )['exists'] )( 3 ) );

		UrlShortenerRecordAutosave::register( $this->short_url_service( 3, null ) );
		$this->assertFalse( ( $this->registered( 'short_url' )['exists'] )( 3 ) );

		UrlShortenerRecordAutosave::register( $this->short_url_service( 3, array( 'status' => 'disabled' ) ) );
		$this->assertTrue( ( $this->registered( 'short_url' )['exists'] )( 3 ) );
	}

	public function test_the_short_url_toggle_sets_the_status_it_means(): void {
		UrlShortenerRecordAutosave::register( $this->short_url_service( 5, array( 'status' => 'active' ) ) );
		$save = $this->registered( 'short_url' )['save'];
		// The first write succeeds, the second fails.
		$this->update_results = array( 1, false );

		$this->assertTrue( $save( 5, 'status', 'disabled' ) );
		$this->assertInstanceOf( \WP_Error::class, $save( 5, 'status', 'active' ) );

		$writes = $this->updates_of( 'wp_ffc_short_urls' );
		$this->assertCount( 2, $writes );
		$this->assertSame( 'disabled', $writes[0]['data']['status'] );
		$this->assertSame( 'active', $writes[1]['data']['status'] );
		$this->assertSame( array( 'id' => 5 ), $writes[1]['where'] );
	}
}
