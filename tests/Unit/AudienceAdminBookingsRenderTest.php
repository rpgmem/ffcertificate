<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Audience\AudienceAdminBookings;

/**
 * Render tests for AudienceAdminBookings::render_page() that exercise the
 * populated-table path (export button, environment filter, and the per-row
 * loop with active/cancelled variants). The empty-list smoke test lives in
 * the sibling AudienceAdminBookingsTest.
 *
 * The real collaborators run: AudienceBookingReader, AudienceScheduleRepository,
 * AudienceEnvironmentRepository, AudienceBookingRules, DateFormatter,
 * Capabilities, AudienceAdminAttendance, AdminUI / Button and AdminNotice.
 * Their storage is a `$wpdb` double that answers each query by its SQL text
 * from the rows a test sets and records every query it ran; a query it does
 * not recognise fails the test. Capabilities are the ones `current_user_can()`
 * is told the user holds.
 *
 * @covers \FreeFormCertificate\Audience\AudienceAdminBookings
 */
class AudienceAdminBookingsRenderTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var list<object> Booking rows the bookings query answers. */
	private array $bookings = array();

	/** @var list<object> Environment rows the per-schedule query answers. */
	private array $environments = array();

	/** @var list<string> Capabilities the current user holds. */
	private array $held_caps = array();

	/** @var list<string> Every prepared SQL string the double ran. */
	private array $ran = array();

	/** @var mixed The `$wpdb` global an earlier test left, restored afterwards. */
	private $wpdb_backup;

	/** @var bool Whether `$wpdb` was set before this test. */
	private bool $had_wpdb = false;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html_e' )->alias( static function ( $t ) { echo $t; } );
		Functions\when( 'esc_attr_e' )->alias( static function ( $t ) { echo $t; } );
		Functions\when( 'esc_attr__' )->returnArg();
		Functions\when( 'absint' )->alias( static fn( $v ) => abs( (int) $v ) );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'sanitize_key' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'admin_url' )->justReturn( 'https://example.com/wp-admin/' );
		Functions\when( 'add_query_arg' )->alias( static fn( array $args, string $url ): string => $url . '?' . http_build_query( $args ) );
		Functions\when( 'selected' )->justReturn( '' );
		Functions\when( 'get_settings_errors' )->justReturn( array() );
		Functions\when( 'wp_trim_words' )->returnArg();
		Functions\when( 'get_option' )->alias( static fn( $key, $default = false ) => $default );
		Functions\when( 'wp_date' )->alias(
			static fn( string $format, int $ts, ?\DateTimeZone $tz = null ): string => ( new \DateTimeImmutable( '@' . $ts ) )->setTimezone( $tz ?? new \DateTimeZone( 'UTC' ) )->format( $format )
		);
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'wp_parse_args' )->alias( static fn( $args, $defaults = array() ) => array_merge( $defaults, (array) $args ) );
		Functions\when( 'sanitize_sql_orderby' )->returnArg();
		Functions\when( 'current_user_can' )->alias( fn( string $cap ): bool => in_array( $cap, $this->held_caps, true ) );

		$this->had_wpdb = array_key_exists( 'wpdb', $GLOBALS );
		if ( $this->had_wpdb ) {
			$this->wpdb_backup = $GLOBALS['wpdb'];
		}
		$GLOBALS['wpdb'] = $this->wpdb_double();
	}

	protected function tearDown(): void {
		unset( $_GET['schedule_id'], $_GET['environment_id'], $_GET['status'], $_GET['date_from'], $_GET['date_to'] );
		if ( $this->had_wpdb ) {
			$GLOBALS['wpdb'] = $this->wpdb_backup;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * A `$wpdb` that answers the page's four reads by SQL text.
	 *
	 * `prepare()` binds the arguments into the string (identifiers in
	 * backticks), so the reads dispatch on the table and the bound ids.
	 *
	 * @return \Mockery\MockInterface
	 */
	private function wpdb_double() {
		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			static function ( string $sql, ...$args ): string {
				if ( 1 === count( $args ) && is_array( $args[0] ) ) {
					$args = $args[0];
				}
				return (string) preg_replace_callback(
					'/%[ids]/',
					static function ( array $m ) use ( &$args ): string {
						$value = array_shift( $args );
						if ( '%i' === $m[0] ) {
							return '`' . $value . '`';
						}
						return '%d' === $m[0] ? (string) (int) $value : "'" . $value . "'";
					},
					$sql
				);
			}
		);
		$wpdb->shouldReceive( 'get_results' )->andReturnUsing(
			function ( string $sql ): array {
				$this->ran[] = $sql;
				if ( str_contains( $sql, 'FROM `wp_ffc_audience_bookings` b' ) ) {
					return $this->bookings;
				}
				if ( str_contains( $sql, 'FROM `wp_ffc_audience_schedules`' ) ) {
					return array( (object) array( 'id' => 5, 'name' => 'My Calendar' ) );
				}
				if ( str_contains( $sql, 'FROM `wp_ffc_audience_environments` WHERE schedule_id = 5' ) ) {
					return $this->environments;
				}
				$this->fail( 'Unexpected get_results: ' . $sql );
			}
		);
		$wpdb->shouldReceive( 'get_row' )->andReturnUsing(
			function ( string $sql ) {
				$this->ran[] = $sql;
				if ( str_contains( $sql, 'FROM `wp_ffc_audience_schedules` WHERE id = 5' ) ) {
					return (object) array( 'id' => 5, 'name' => 'My Calendar', 'environment_label' => 'Rooms' );
				}
				$this->fail( 'Unexpected get_row: ' . $sql );
			}
		);
		return $wpdb;
	}

	private function active_booking(): object {
		return (object) array(
			'id'               => 101,
			'created_by'       => 2,
			'booking_date'     => '2026-05-20',
			'start_time'       => '09:00:00',
			'end_time'         => '10:00:00',
			'environment_name' => 'Room A',
			'description'      => 'Weekly sync',
			'booking_type'     => 'audience',
			'status'           => 'active',
		);
	}

	private function cancelled_booking(): object {
		return (object) array(
			'id'               => 102,
			'created_by'       => 99, // not in the creators map → "Unknown"
			'booking_date'     => '2026-05-21',
			'start_time'       => '11:00:00',
			'end_time'         => '12:00:00',
			'environment_name' => 'Room B',
			'description'      => 'Cancelled thing',
			'booking_type'     => 'mystery', // unknown type → raw value
			'status'           => 'cancelled',
		);
	}

	/**
	 * Store the rows the bookings and environment queries answer.
	 *
	 * @param list<object> $bookings          Booking rows.
	 * @param bool         $with_environments Whether schedule 5 has an environment.
	 */
	private function store( array $bookings, bool $with_environments ): void {
		$this->bookings     = $bookings;
		$this->environments = $with_environments ? array( (object) array( 'id' => 33, 'name' => 'Room A' ) ) : array();
	}

	/**
	 * Hold every capability (the site administrator) or none.
	 *
	 * @param bool $granted Whether the user is an administrator.
	 */
	private function grant_all( bool $granted ): void {
		$this->held_caps = $granted ? array( 'manage_options' ) : array();
	}

	public function test_render_populated_table_with_export_button_and_env_filter(): void {
		$_GET['schedule_id'] = '5'; // drives the environment-filter branch
		$this->store( array( $this->active_booking(), $this->cancelled_booking() ), true );
		$this->grant_all( true );

		Functions\when( 'get_users' )->justReturn(
			array( (object) array( 'ID' => 2, 'display_name' => 'Ana Creator' ) )
		);

		$page = new AudienceAdminBookings( 'ffc-scheduling' );
		ob_start();
		$page->render_page();
		$output = ob_get_clean();

		// Export button (capability granted).
		$this->assertStringContainsString( 'id="ffc-bookings-export-btn"', $output );
		// Both rows rendered.
		$this->assertStringContainsString( 'data-booking-id="101"', $output );
		$this->assertStringContainsString( 'data-booking-id="102"', $output );
		// Creator resolved from the batch map; missing one falls back to Unknown.
		$this->assertStringContainsString( 'Ana Creator', $output );
		$this->assertStringContainsString( 'Unknown', $output );
		// Active row shows the Cancel link; cancelled row does not add a second one.
		$this->assertSame( 1, substr_count( $output, 'ffc-cancel-booking' ) );
		$this->assertSame( 2, substr_count( $output, 'ffc-view-booking' ) );
		// Type labels: known 'audience' mapped, unknown 'mystery' echoed raw.
		$this->assertStringContainsString( 'Audience', $output );
		$this->assertStringContainsString( 'mystery', $output );
		// Environment filter option rendered (schedule_id > 0), and the
		// bookings query was narrowed to that schedule.
		$this->assertStringContainsString( 'value="33"', $output );
		$booking_queries = array_values( array_filter( $this->ran, static fn( string $sql ): bool => str_contains( $sql, 'FROM `wp_ffc_audience_bookings` b' ) ) );
		$this->assertCount( 1, $booking_queries );
		$this->assertStringContainsString( 'e.schedule_id = 5', $booking_queries[0] );
		// Total line.
		$this->assertStringContainsString( 'Total:', $output );
	}

	public function test_render_hides_export_button_without_capability(): void {
		$this->store( array( $this->active_booking() ), false );
		$this->grant_all( false );

		Functions\when( 'get_users' )->justReturn(
			array( (object) array( 'ID' => 2, 'display_name' => 'Ana' ) )
		);

		$page = new AudienceAdminBookings( 'ffc-scheduling' );
		ob_start();
		$page->render_page();
		$output = ob_get_clean();

		$this->assertStringNotContainsString( 'id="ffc-bookings-export-btn"', $output );
		// Table still renders the row.
		$this->assertStringContainsString( '<td>101</td>', $output );
	}

	/**
	 * View and Cancel call AJAX handlers gated on `ffc_manage_audiences`, so
	 * they render for that tier only (#1769).
	 *
	 * @return array<string, array{0: list<string>, 1: bool}>
	 */
	public function booking_action_tiers(): array {
		return array(
			'view-only tier' => array( array( 'ffc_view_audiences' ), false ),
			'manage tier'    => array( array( 'ffc_view_audiences', 'ffc_manage_audiences' ), true ),
		);
	}

	/**
	 * @dataProvider booking_action_tiers
	 *
	 * @param list<string> $caps       Capabilities held.
	 * @param bool         $sees_links Whether View / Cancel render.
	 */
	public function test_view_and_cancel_render_only_for_the_manage_tier( array $caps, bool $sees_links ): void {
		$this->store( array( $this->active_booking() ), false );
		$this->held_caps = $caps;
		Functions\when( 'get_users' )->justReturn( array() );

		$page = new AudienceAdminBookings( 'ffc-scheduling' );
		ob_start();
		$page->render_page();
		$output = ob_get_clean();

		$this->assertStringContainsString( '<td>101</td>', $output, 'The row renders for both tiers.' );
		$this->assertSame( $sees_links ? 1 : 0, substr_count( $output, 'ffc-view-booking' ) );
		$this->assertSame( $sees_links ? 1 : 0, substr_count( $output, 'ffc-cancel-booking' ) );
	}

	/**
	 * Attendance opens the booking's check-in screen (#1947): only for the
	 * holder of the check-in capability, and only on an active booking.
	 */
	public function test_attendance_link_renders_for_the_checkin_cap_on_active_rows(): void {
		$this->store( array( $this->active_booking(), $this->cancelled_booking() ), false );
		$this->held_caps = array( 'ffc_checkin_audiences' );
		Functions\when( 'get_users' )->justReturn( array() );

		$page = new AudienceAdminBookings( 'ffc-scheduling' );
		ob_start();
		$page->render_page();
		$output = ob_get_clean();

		$this->assertSame( 1, substr_count( $output, 'ffc-attendance-link' ) );
		$this->assertStringContainsString( 'page=ffc-scheduling-attendance&booking_id=101', $output );
		$this->assertSame( 0, substr_count( $output, 'ffc-view-booking' ), 'The check-in tier alone does not manage bookings.' );
	}

	/**
	 * The title matches the menu item, the status filter says what the other
	 * lists say, and every header carries a `column-*` class so the shared
	 * Scheduling column rules apply (#1787).
	 */
	public function test_title_filter_label_and_header_classes_match_the_other_lists(): void {
		$this->store( array( $this->active_booking() ), false );
		$this->grant_all( false );
		Functions\when( 'get_users' )->justReturn( array() );

		$page = new AudienceAdminBookings( 'ffc-scheduling' );
		ob_start();
		$page->render_page();
		$output = ob_get_clean();

		$this->assertStringContainsString( '<h1 class="wp-heading-inline">Audience Bookings</h1>', $output );
		$this->assertStringContainsString( '<option value="">All Statuses</option>', $output );
		$this->assertStringNotContainsString( 'All Status<', $output );

		preg_match_all( '#<th scope="col" class="([^"]*)"#', $output, $headers );
		$this->assertSame(
			array(
				'manage-column column-id',
				'manage-column column-date',
				'manage-column column-time',
				'manage-column column-environment',
				'manage-column column-description',
				'manage-column column-type',
				'manage-column column-status',
				'manage-column column-created_by',
				'manage-column column-actions',
			),
			$headers[1],
			'Every header names its column; status and actions are the ones the shared width rules read.'
		);
	}

	public function test_individual_booking_type_shows_the_modal_label(): void {
		$booking                = $this->active_booking();
		$booking->booking_type  = 'individual';
		$this->store( array( $booking ), false );
		$this->grant_all( false );

		Functions\when( 'get_users' )->justReturn( array() );

		$page = new AudienceAdminBookings( 'ffc-scheduling' );
		ob_start();
		$page->render_page();
		$output = ob_get_clean();

		// The stored enum is `individual`; the list must label it the way the
		// booking modal does, never echo the raw word.
		$this->assertMatchesRegularExpression( '#<td>\s*Custom Users\s*</td>#', $output );
		$this->assertDoesNotMatchRegularExpression( '#<td>\s*individual\s*</td>#', $output );
	}
}
