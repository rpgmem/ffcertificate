<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FreeFormCertificate\Core\Encryption;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\SelfScheduling\AppointmentsListTable;
use FreeFormCertificate\Tests\Support\CallLog;

/**
 * Tests for the self-scheduling appointments WP_List_Table.
 *
 * Focus: the column data/formatting logic, prepare_items() query assembly, and
 * the filter dropdown nav — including column_id()'s row-action assembly (the
 * bootstrap WP_List_Table stub does provide row_actions()).
 *
 * Everything behind the table runs for real — AppointmentRepository (with its
 * reader and writer), CalendarRepository, Capabilities, MagicLinkHelper,
 * AppointmentReceiptHandler, Encryption, DocumentFormatter, PiiAccessPolicy,
 * PiiReveal, DateFormatter and RequestInput — over a `$wpdb` double that
 * answers each query by its SQL text from the rows a test stores, and
 * records every query it ran. A query the double does not know fails the
 * test.
 *
 * @covers \FreeFormCertificate\SelfScheduling\AppointmentsListTable
 */
class AppointmentsListTableTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private const APPOINTMENTS = '`wp_ffc_self_scheduling_appointments`';

	private const CALENDARS = '`wp_ffc_self_scheduling_calendars`';

	/** @var array<int, array<string, mixed>> Calendar rows by id. */
	private array $calendars = array();

	/** @var array<string, string>|null What `ffc_settings` answers, when a test chooses it. */
	private ?array $settings_override = null;

	/** @var list<array<string, mixed>> Rows the appointment list query answers. */
	private array $appointment_rows = array();

	/** @var int What the appointment COUNT(*) answers. */
	private int $appointment_count = 0;

	/** @var list<string> Every SQL string the double ran (get_row, get_results, get_var). */
	private array $ran = array();

	/** @var list<string> Queries the double did not recognise. */
	private array $unexpected = array();

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
		Functions\when( 'esc_html_e' )->alias( function ( $t ) { echo $t; } );
		Functions\when( 'esc_attr__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'admin_url' )->alias( fn( $p = '' ) => 'https://example.com/wp-admin/' . $p );
		Functions\when( 'home_url' )->alias( fn( $p = '' ) => 'https://example.com' . $p );
		Functions\when( 'add_query_arg' )->alias(
			static function ( ...$args ) {
				if ( is_array( $args[0] ) ) {
					$query = $args[0];
					$url   = (string) ( $args[1] ?? '' );
				} else {
					$query = array( $args[0] => $args[1] );
					$url   = (string) ( $args[2] ?? '' );
				}
				return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . http_build_query( $query );
			}
		);
		Functions\when( 'absint' )->alias( fn( $v ) => abs( (int) $v ) );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'is_email' )->alias( static fn( $e ) => false !== filter_var( $e, FILTER_VALIDATE_EMAIL ) );
		Functions\when( 'selected' )->justReturn( '' );
		Functions\when( 'submit_button' )->justReturn( null );
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( 'user_can' )->justReturn( false );
		Functions\when( 'get_current_user_id' )->justReturn( 0 );
		Functions\when( 'get_user_by' )->justReturn( false );
		Functions\when( 'wp_create_nonce' )->justReturn( 'test_nonce' );
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		Functions\when( 'wp_date' )->alias(
			static fn( $format, $ts = null, $tz = null ) => ( new \DateTimeImmutable( '@' . (int) $ts ) )->setTimezone( $tz ?? new \DateTimeZone( 'UTC' ) )->format( $format )
		);
		// Stubbed explicitly so DateFormatter and MagicLinkHelper read the
		// same world whatever ran before: the time format, and no
		// verification page stored (the magic link falls back to /valid/).
		$this->settings_override = null;
		Functions\when( 'get_option' )->alias(
			function ( $key, $default = false ) {
				if ( 'ffc_settings' === $key ) {
					return $this->settings_override ?? array( 'time_format' => 'H:i' );
				}
				return $default;
			}
		);

		$this->calendars         = array();
		$this->appointment_rows  = array();
		$this->appointment_count = 0;
		$this->ran               = array();
		$this->unexpected        = array();

		$this->had_wpdb    = array_key_exists( 'wpdb', $GLOBALS );
		$this->wpdb_backup = $this->had_wpdb ? $GLOBALS['wpdb'] : null;
		$GLOBALS['wpdb']   = $this->wpdb_double();
	}

	protected function tearDown(): void {
		unset( $_GET['calendar_id'], $_GET['status'], $_GET['orderby'], $_GET['order'] );
		if ( $this->had_wpdb ) {
			$GLOBALS['wpdb'] = $this->wpdb_backup;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		Monkey\tearDown();
		parent::tearDown();
	}

	protected function assertPostConditions(): void {
		$this->assertSame( array(), $this->unexpected, 'The $wpdb double received a query it does not know.' );
	}

	/**
	 * A `$wpdb` double: prepare() substitutes the bound values into the SQL
	 * text, and each read answers from the rows the test stored.
	 *
	 * @return Mockery\MockInterface
	 */
	private function wpdb_double() {
		$wpdb             = Mockery::mock( 'wpdb' );
		$wpdb->prefix     = 'wp_';
		$wpdb->last_error = '';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			static function ( $sql, ...$args ) {
				$args = 1 === count( $args ) && is_array( $args[0] ) ? $args[0] : $args;
				$i    = 0;
				return preg_replace_callback(
					'/%[ids]/',
					static function ( $m ) use ( &$i, $args ) {
						$value = $args[ $i++ ];
						if ( '%i' === $m[0] ) {
							return '`' . $value . '`';
						}
						if ( '%d' === $m[0] ) {
							return (string) (int) $value;
						}
						return "'" . addslashes( (string) $value ) . "'";
					},
					$sql
				);
			}
		);
		$wpdb->shouldReceive( 'get_row' )->andReturnUsing(
			function ( $sql ) {
				$this->ran[] = $sql;
				if ( preg_match( '/^SELECT \* FROM ' . preg_quote( self::CALENDARS, '/' ) . ' WHERE id = (\d+)$/', $sql, $m ) ) {
					return $this->calendars[ (int) $m[1] ] ?? null;
				}
				return $this->unexpected_query( $sql );
			}
		);
		$wpdb->shouldReceive( 'get_results' )->andReturnUsing(
			function ( $sql ) {
				$this->ran[] = $sql;
				if ( 0 === strpos( $sql, 'SELECT * FROM ' . self::APPOINTMENTS . ' ' ) ) {
					return $this->appointment_rows;
				}
				if ( "SELECT * FROM " . self::CALENDARS . " WHERE `status` = 'active' ORDER BY created_at DESC" === $sql ) {
					return array_values(
						array_filter( $this->calendars, static fn( $c ) => 'active' === ( $c['status'] ?? '' ) )
					);
				}
				return $this->unexpected_query( $sql );
			}
		);
		$wpdb->shouldReceive( 'get_var' )->andReturnUsing(
			function ( $sql ) {
				$this->ran[] = $sql;
				if ( 0 === strpos( $sql, 'SELECT COUNT(*) FROM ' . self::APPOINTMENTS ) ) {
					return (string) $this->appointment_count;
				}
				return $this->unexpected_query( $sql );
			}
		);
		return $wpdb;
	}

	/**
	 * Record a query the double does not know, and fail.
	 *
	 * @param string $sql The query.
	 * @return never
	 */
	private function unexpected_query( string $sql ) {
		$this->unexpected[] = $sql;
		$this->fail( 'Unexpected query: ' . $sql );
	}

	private function call_protected( AppointmentsListTable $table, string $method, array $args = array() ) {
		$ref = new \ReflectionMethod( $table, $method );
		$ref->setAccessible( true );
		return $ref->invokeArgs( $table, $args );
	}

	public function test_get_columns_declares_expected_keys(): void {
		$table = new AppointmentsListTable();
		$cols  = $table->get_columns();

		$this->assertSame(
			array( 'cb', 'id', 'calendar', 'name', 'email', 'appointment_date', 'time', 'status', 'created_at' ),
			array_keys( $cols )
		);
	}

	public function test_get_sortable_columns_declares_sortable_set(): void {
		$table    = new AppointmentsListTable();
		$sortable = $table->get_sortable_columns();

		$this->assertSame(
			array( 'id', 'calendar', 'appointment_date', 'status', 'created_at' ),
			array_keys( $sortable )
		);
		$this->assertSame( array( 'id', true ), $sortable['id'] );
	}

	public function test_column_default_returns_value_or_dash(): void {
		$table = new AppointmentsListTable();

		$this->assertSame( 'abc', $table->column_default( array( 'foo' => 'abc' ), 'foo' ) );
		$this->assertSame( '-', $table->column_default( array(), 'missing' ) );
	}

	public function test_column_cb_emits_checkbox_with_id(): void {
		$table = new AppointmentsListTable();
		$html  = $table->column_cb( array( 'id' => 33 ) );

		$this->assertStringContainsString( 'name="appointment[]"', $html );
		$this->assertStringContainsString( 'value="33"', $html );
	}

	public function test_column_id_pending_editable_builds_all_actions(): void {
		Functions\when( 'current_user_can' )->justReturn( true ); // manage → can edit.
		Functions\when( 'wp_nonce_url' )->alias( fn( $u, $a = '' ) => $u . '&_n=1' );

		$table = new AppointmentsListTable();
		$html  = $table->column_id( array( 'id' => 7, 'status' => 'pending', 'confirmation_token' => 'tok' ) );

		$this->assertStringContainsString( '#7', $html );
		$this->assertStringContainsString( 'Confirm', $html );
		$this->assertStringContainsString( 'ffc-appointment-cancel', $html );
		$this->assertStringContainsString( '>View<', $html, 'A manager is offered the manage-gated detail view.' );
		$this->assertStringContainsString( 'appointment=7', $html );
		// The real MagicLinkHelper: no verification page stored, so /valid/.
		$this->assertStringContainsString( 'https://example.com/valid/#token=tok', $html );
	}

	public function test_column_id_readonly_cancelled_shows_no_row_action(): void {
		Functions\when( 'current_user_can' )->justReturn( false ); // read-only viewer.

		$table = new AppointmentsListTable();
		$html  = $table->column_id( array( 'id' => 9, 'status' => 'cancelled', 'confirmation_token' => 'tok' ) );

		$this->assertStringContainsString( '#9', $html );
		$this->assertStringNotContainsString( 'Confirm', $html );
		$this->assertStringNotContainsString( 'ffc-appointment-cancel', $html );
		// The detail view requires ffc_manage_appointments, so a read-only
		// viewer is not offered it (#1769).
		$this->assertStringNotContainsString( '>View<', $html );
		$this->assertStringNotContainsString( 'appointment=9', $html );
		$this->assertStringNotContainsString( 'View Receipt', $html ); // cancelled → no receipt.
	}

	public function test_column_id_receipt_falls_back_to_handler_when_token_empty(): void {
		Functions\when( 'current_user_can' )->justReturn( false );

		$table = new AppointmentsListTable();
		$html  = $table->column_id( array( 'id' => 11, 'status' => 'confirmed', 'confirmation_token' => '' ) );

		// The real AppointmentReceiptHandler::get_receipt_url( 11, '' ).
		$this->assertStringContainsString( 'https://example.com?ffc_appointment_receipt=11', $html );
		$this->assertStringNotContainsString( '#token=', $html );
	}

	public function test_column_calendar_links_to_post_when_found(): void {
		$this->calendars[9] = array( 'id' => 9, 'post_id' => 100, 'title' => 'Clinic', 'status' => 'active' );

		$table = new AppointmentsListTable();
		$out   = $table->column_calendar( array( 'calendar_id' => 9 ) );

		$this->assertStringContainsString( 'post=100', $out );
		$this->assertStringContainsString( 'Clinic', $out );
		$this->assertSame( array( 'SELECT * FROM ' . self::CALENDARS . ' WHERE id = 9' ), $this->ran );
	}

	public function test_column_calendar_deleted_when_missing(): void {
		$table = new AppointmentsListTable();
		$this->assertSame( '(Deleted)', $table->column_calendar( array( 'calendar_id' => 9 ) ) );
		$this->assertSame( array( 'SELECT * FROM ' . self::CALENDARS . ' WHERE id = 9' ), $this->ran );
	}

	public function test_column_name_prefers_user_display_name(): void {
		Functions\when( 'get_user_by' )->justReturn( (object) array( 'display_name' => 'Alice' ) );

		$table = new AppointmentsListTable();
		$this->assertSame( 'Alice', $table->column_name( array( 'user_id' => 5, 'name' => 'Fallback' ) ) );
	}

	public function test_column_name_falls_back_to_name_field(): void {
		$table = new AppointmentsListTable();
		$this->assertSame( 'Guest Joe', $table->column_name( array( 'user_id' => 0, 'name' => 'Guest Joe' ) ) );
	}

	public function test_column_name_guest_placeholder_when_no_name(): void {
		$table = new AppointmentsListTable();
		$this->assertSame( '(Guest)', $table->column_name( array( 'user_id' => 0 ) ) );
	}

	/**
	 * Put the current user (id 7) in one tier of the real PiiAccessPolicy for
	 * the appointments domain, through the WordPress functions it reads. The
	 * address is decrypted by the real Encryption and masked by the real
	 * DocumentFormatter.
	 *
	 * @param string $tier unmasked (manage_options) | admin_role
	 *                     (ffc_appointments_admin) | reveal (the PII cap) | masked.
	 */
	private function driveEmailColumn( string $tier ): void {
		Functions\when( 'get_current_user_id' )->justReturn( 7 );
		Functions\when( 'get_user_by' )->justReturn(
			(object) array( 'roles' => 'admin_role' === $tier ? array( 'ffc_appointments_admin' ) : array( 'ffc_appointments_viewer' ) )
		);
		Functions\when( 'user_can' )->alias(
			static function ( $uid, $cap ) use ( $tier ) {
				if ( 'manage_options' === $cap ) {
					return 'unmasked' === $tier;
				}
				return 'ffc_view_appointments_pii' === $cap && 'reveal' === $tier;
			}
		);
	}

	private function emailCellFor( string $tier ): string {
		$this->driveEmailColumn( $tier );
		$table = new AppointmentsListTable();
		return $table->column_email( array( 'id' => 42, 'user_id' => 99, 'email_encrypted' => Encryption::encrypt( 'a@b.com' ) ) );
	}

	private function assertMaskedWithReveal( string $out ): void {
		$this->assertStringNotContainsString( 'a@b.com', $out, 'The clear address must not reach the list HTML.' );
		$this->assertStringContainsString( '<span class="ffc-pii-value" data-field="email">a***@b.com</span>', $out );
		$this->assertStringContainsString( 'class="ffc-btn ffc-btn-neutral ffc-icon-eye ffc-reveal-pii"', $out );
		$this->assertStringContainsString( 'data-type="appointment" data-field="email" data-submission-id="42" data-nonce="test_nonce"', $out );
	}

	public function test_column_email_unmasked_tier_is_masked_with_reveal_button(): void {
		$this->assertMaskedWithReveal( $this->emailCellFor( 'unmasked' ) );
	}

	public function test_column_email_appointments_admin_role_without_the_pii_cap_gets_no_reveal_button(): void {
		// The role alone unmasks nothing since #1863: its PII cap is what
		// reveals, and this user holds the role without it.
		$out = $this->emailCellFor( 'admin_role' );
		$this->assertStringContainsString( '<span class="ffc-pii-value" data-field="email">a***@b.com</span>', $out );
		$this->assertStringNotContainsString( 'ffc-reveal-pii', $out );
	}

	public function test_column_email_reveal_tier_is_masked_with_reveal_button(): void {
		$this->assertMaskedWithReveal( $this->emailCellFor( 'reveal' ) );
	}

	public function test_column_email_masked_tier_masks_without_button(): void {
		$out = $this->emailCellFor( 'masked' );
		$this->assertSame( '<span class="ffc-pii-value" data-field="email">a***@b.com</span>', $out );
		$this->assertStringNotContainsString( 'ffc-reveal-pii', $out );
	}

	public function test_column_email_owner_gets_reveal_button(): void {
		$this->driveEmailColumn( 'masked' );
		$table = new AppointmentsListTable();
		$this->assertMaskedWithReveal(
			$table->column_email( array( 'id' => 42, 'user_id' => 7, 'email_encrypted' => Encryption::encrypt( 'a@b.com' ) ) )
		);
	}

	public function test_column_email_empty_renders_dash_without_button(): void {
		$this->driveEmailColumn( 'unmasked' );
		$table = new AppointmentsListTable();
		$this->assertSame( '-', $table->column_email( array( 'id' => 42 ) ) );
	}

	public function test_reveal_nonce_is_created_once_per_render(): void {
		$this->driveEmailColumn( 'unmasked' );
		$nonces = CallLog::record( 'wp_create_nonce', 'test_nonce' );
		$table  = new AppointmentsListTable();

		$table->column_email( array( 'id' => 1, 'email_encrypted' => Encryption::encrypt( 'a@b.com' ) ) );
		$table->column_email( array( 'id' => 2, 'email_encrypted' => Encryption::encrypt( 'c@d.com' ) ) );

		$this->assertSame( 1, $nonces->count() );
	}

	public function test_column_time_formats_range(): void {
		$table = new AppointmentsListTable();
		$this->assertSame(
			'09:00 - 10:00',
			$table->column_time( array( 'start_time' => '09:00:00', 'end_time' => '10:00:00' ) )
		);
	}

	public function test_date_and_created_columns_follow_the_site_formats(): void {
		$this->settings_override = array( 'date_format' => 'custom', 'date_format_custom' => 'd-m-Y', 'time_format' => 'H\hi' );
		$table                   = new AppointmentsListTable();

		$this->assertSame( '21-10-2026', $table->column_appointment_date( array( 'appointment_date' => '2026-10-21' ) ) );
		$this->assertSame( '01-10-2026 14h05', $table->column_created_at( array( 'created_at' => '2026-10-01 14:05:00' ) ) );
		$this->assertSame( '-', $table->column_appointment_date( array( 'appointment_date' => '' ) ) );
		$this->assertSame( '-', $table->column_created_at( array() ) );
	}

	public function test_column_status_known_label_and_fallback(): void {
		$table = new AppointmentsListTable();

		$this->assertStringContainsString( 'ffc-appointment-status-confirmed', $table->column_status( array( 'status' => 'confirmed' ) ) );
		// Unknown status → escaped raw value.
		$this->assertSame( 'weird', $table->column_status( array( 'status' => 'weird' ) ) );
	}

	public function test_prepare_items_builds_conditions_and_pagination(): void {
		$_GET['calendar_id'] = '9';
		$_GET['status']      = 'confirmed';

		$this->appointment_rows  = array( array( 'id' => 1 ) );
		$this->appointment_count = 5;

		$table = new AppointmentsListTable();
		$table->prepare_items();

		$where = "WHERE `calendar_id` = '9' AND `status` = 'confirmed'";
		$this->assertSame(
			array(
				'SELECT * FROM ' . self::APPOINTMENTS . " {$where} ORDER BY appointment_date DESC, start_time DESC, id DESC LIMIT 20 OFFSET 0",
				'SELECT COUNT(*) FROM ' . self::APPOINTMENTS . " {$where}",
			),
			$this->ran
		);

		$pagination = new \ReflectionProperty( $table, '_pagination_args' );
		$pagination->setAccessible( true );
		$this->assertSame(
			array(
				'total_items' => 5,
				'per_page'    => 20,
				'total_pages' => 1,
			),
			$pagination->getValue( $table )
		);
	}

	/**
	 * Run prepare_items() under the given query string and return the ORDER
	 * BY clause the list query carried (#1770, #2288).
	 *
	 * @param array<string, string> $query Request query vars.
	 * @return string
	 */
	private function order_for( array $query ): string {
		unset( $_GET['orderby'], $_GET['order'] );
		foreach ( $query as $key => $value ) {
			$_GET[ $key ] = $value;
		}
		$this->ran = array();

		$table = new AppointmentsListTable();
		$table->prepare_items();

		$this->assertMatchesRegularExpression( '/ ORDER BY (.+) LIMIT 20 OFFSET 0$/', $this->ran[0] );
		preg_match( '/ ORDER BY (.+) LIMIT 20 OFFSET 0$/', $this->ran[0], $m );
		return $m[1];
	}

	public function test_prepare_items_default_order_is_appointment_date_then_time_newest_first(): void {
		$this->assertSame( 'appointment_date DESC, start_time DESC, id DESC', $this->order_for( array() ) );
	}

	public function test_a_date_sort_breaks_ties_on_the_time_in_the_same_direction(): void {
		$this->assertSame(
			'appointment_date ASC, start_time ASC, id ASC',
			$this->order_for( array( 'orderby' => 'appointment_date', 'order' => 'asc' ) )
		);
		$this->assertSame(
			'appointment_date DESC, start_time DESC, id DESC',
			$this->order_for( array( 'orderby' => 'appointment_date', 'order' => 'desc' ) )
		);
	}

	/**
	 * Every sortable column the table declares must reach the query, in the
	 * direction the header link asks for (#1770).
	 */
	public function test_prepare_items_sorts_by_every_declared_sortable_column(): void {
		$table   = new AppointmentsListTable();
		$orderby = array_map(
			static fn( $spec ) => $spec[0],
			array_values( $table->get_sortable_columns() )
		);

		$this->assertNotEmpty( $orderby );
		foreach ( $orderby as $column ) {
			$this->assertStringStartsWith(
				"{$column} ASC",
				$this->order_for( array( 'orderby' => $column, 'order' => 'asc' ) ),
				"orderby={$column}&order=asc must sort by {$column} ASC."
			);
			$this->assertStringStartsWith(
				"{$column} DESC",
				$this->order_for( array( 'orderby' => $column, 'order' => 'desc' ) ),
				"orderby={$column}&order=desc must sort by {$column} DESC."
			);
		}
	}

	public function test_prepare_items_ignores_an_unlisted_orderby(): void {
		$this->assertSame(
			'appointment_date DESC, start_time DESC, id DESC',
			$this->order_for( array( 'orderby' => 'email_encrypted', 'order' => 'asc' ) )
		);
		$this->assertSame(
			'appointment_date DESC, start_time DESC, id DESC',
			$this->order_for( array( 'orderby' => 'id; DROP TABLE x', 'order' => 'asc' ) )
		);
	}

	public function test_column_status_labels_waitlist(): void {
		$table = new AppointmentsListTable();
		$html  = $table->column_status( array( 'status' => 'waitlist' ) );

		$this->assertStringContainsString( 'ffc-appointment-status-waitlist', $html );
		$this->assertStringContainsString( '>Waitlist<', $html );
	}

	public function test_column_id_waitlist_editable_offers_cancel_but_not_confirm(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_nonce_url' )->alias( fn( $u, $a = '' ) => $u . '&_n=' . $a );

		$table = new AppointmentsListTable();
		$html  = $table->column_id( array( 'id' => 21, 'status' => 'waitlist', 'confirmation_token' => 'tok' ) );

		$this->assertStringContainsString( 'ffc-appointment-cancel', $html );
		$this->assertStringContainsString( 'ffc_cancel_appointment_21', $html );
		// confirm() does not recount the slot, so it would overbook a full one.
		$this->assertStringNotContainsString( 'Confirm', $html );
	}

	public function test_column_id_confirmed_on_or_after_its_date_offers_both_attendance_marks(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_nonce_url' )->alias( fn( $u, $a = '' ) => $u . '&_n=' . $a );
		Functions\when( 'wp_date' )->justReturn( '2026-05-10' );

		$table = new AppointmentsListTable();
		foreach ( array( '2026-05-10', '2026-05-01' ) as $date ) {
			$html = $table->column_id( array( 'id' => 31, 'status' => 'confirmed', 'appointment_date' => $date, 'confirmation_token' => 'tok' ) );

			$this->assertStringContainsString( '>Attended<', $html, $date );
			$this->assertStringContainsString( 'ffc_attended_appointment_31', $html, $date );
			$this->assertStringContainsString( '>No-show<', $html, $date );
			$this->assertStringContainsString( 'ffc_no_show_appointment_31', $html, $date );
			$this->assertStringNotContainsString( 'Undo attendance', $html, $date );
		}
	}

	public function test_column_id_offers_no_attendance_mark_before_the_date_or_without_one(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_nonce_url' )->alias( fn( $u, $a = '' ) => $u . '&_n=' . $a );
		Functions\when( 'wp_date' )->justReturn( '2026-05-10' );

		$table = new AppointmentsListTable();
		foreach ( array( array( 'appointment_date' => '2026-05-11' ), array() ) as $extra ) {
			$html = $table->column_id( array( 'id' => 31, 'status' => 'confirmed', 'confirmation_token' => 'tok' ) + $extra );

			$this->assertStringNotContainsString( 'ffc_attended_appointment_31', $html );
			$this->assertStringNotContainsString( 'ffc_no_show_appointment_31', $html );
			// Cancel stays available on a confirmed appointment.
			$this->assertStringContainsString( 'ffc_cancel_appointment_31', $html );
		}
	}

	public function test_column_id_attendance_mark_offers_only_undo(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_nonce_url' )->alias( fn( $u, $a = '' ) => $u . '&_n=' . $a );
		Functions\when( 'wp_date' )->justReturn( '2026-05-10' );

		$table = new AppointmentsListTable();
		foreach ( array( 'completed', 'no_show' ) as $status ) {
			$html = $table->column_id( array( 'id' => 32, 'status' => $status, 'appointment_date' => '2026-05-01', 'confirmation_token' => 'tok' ) );

			$this->assertStringContainsString( '>Undo attendance<', $html, $status );
			$this->assertStringContainsString( 'ffc_undo_attendance_appointment_32', $html, $status );
			$this->assertStringNotContainsString( 'ffc_attended_appointment_32', $html, $status );
			$this->assertStringNotContainsString( 'ffc_cancel_appointment_32', $html, $status );
		}
	}

	public function test_column_id_readonly_viewer_gets_no_attendance_action(): void {
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( 'wp_date' )->justReturn( '2026-05-10' );

		$table = new AppointmentsListTable();
		foreach ( array( 'confirmed', 'completed' ) as $status ) {
			$html = $table->column_id( array( 'id' => 33, 'status' => $status, 'appointment_date' => '2026-05-01', 'confirmation_token' => 'tok' ) );

			$this->assertStringNotContainsString( 'Attended', $html, $status );
			$this->assertStringNotContainsString( 'Undo attendance', $html, $status );
		}
	}

	public function test_column_id_waitlist_readonly_offers_no_mutation(): void {
		Functions\when( 'current_user_can' )->justReturn( false );

		$table = new AppointmentsListTable();
		$html  = $table->column_id( array( 'id' => 21, 'status' => 'waitlist', 'confirmation_token' => 'tok' ) );

		$this->assertStringNotContainsString( 'ffc-appointment-cancel', $html );
		$this->assertStringNotContainsString( 'Confirm', $html );
	}

	public function test_extra_tablenav_offers_waitlist_status_filter(): void {
		$table = new AppointmentsListTable();

		ob_start();
		$this->call_protected( $table, 'extra_tablenav', array( 'top' ) );
		$out = ob_get_clean();

		$this->assertStringContainsString( '<option value="waitlist"', $out );
	}

	public function test_extra_tablenav_returns_early_when_not_top(): void {
		$table = new AppointmentsListTable();

		ob_start();
		$this->call_protected( $table, 'extra_tablenav', array( 'bottom' ) );
		$out = ob_get_clean();

		$this->assertSame( '', $out );
		$this->assertSame( array(), $this->ran, 'The bottom nav reads no calendars.' );
	}

	public function test_extra_tablenav_renders_calendar_options(): void {
		$this->calendars[1] = array( 'id' => 1, 'title' => 'Cal One', 'status' => 'active' );
		$this->calendars[2] = array( 'id' => 2, 'title' => 'Cal Archived', 'status' => 'inactive' );

		$table = new AppointmentsListTable();

		ob_start();
		$this->call_protected( $table, 'extra_tablenav', array( 'top' ) );
		$out = ob_get_clean();

		$this->assertStringContainsString( 'Cal One', $out );
		$this->assertStringNotContainsString( 'Cal Archived', $out, 'Only active calendars are offered.' );
		$this->assertStringContainsString( 'All Statuses', $out );
		// The filter submits under the name it always had, as a family button (#1904).
		$this->assertStringContainsString( '<button type="submit" class="ffc-btn ffc-btn-neutral ffc-icon-filter" name="filter_action" value="Filter">Filter</button>', $out );
	}
}
