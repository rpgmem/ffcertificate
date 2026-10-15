<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use FreeFormCertificate\Core\RequestInput;

use Brain\Monkey;
use Brain\Monkey\Functions;
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
 * @covers \FreeFormCertificate\SelfScheduling\AppointmentsListTable
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class AppointmentsListTableTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var Mockery\MockInterface */
	private $appt_repo;

	/** @var Mockery\MockInterface */
	private $cal_repo;

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
		Functions\when( 'absint' )->alias( fn( $v ) => abs( (int) $v ) );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'selected' )->justReturn( '' );
		Functions\when( 'submit_button' )->justReturn( null );

		// Constructor instantiates both repositories with `new`.
		$this->appt_repo = Mockery::mock( 'overload:FreeFormCertificate\Repositories\AppointmentRepository' );
		$this->appt_repo->shouldReceive( 'findAll' )->andReturn( array() )->byDefault();
		$this->appt_repo->shouldReceive( 'count' )->andReturn( 0 )->byDefault();
		$this->cal_repo = Mockery::mock( 'overload:FreeFormCertificate\Repositories\CalendarRepository' );
		$this->cal_repo->shouldReceive( 'findById' )->andReturn( null )->byDefault();
		$this->cal_repo->shouldReceive( 'getActiveCalendars' )->andReturn( array() )->byDefault();
	}

	protected function tearDown(): void {
		unset( $_GET['calendar_id'], $_GET['status'], $_GET['orderby'], $_GET['order'] );
		Monkey\tearDown();
		parent::tearDown();
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
		Functions\when( 'add_query_arg' )->alias( fn( $a, $u = '' ) => $u . '?' . http_build_query( (array) $a ) );
		Functions\when( 'wp_nonce_url' )->alias( fn( $u, $a = '' ) => $u . '&_n=1' );
		Mockery::mock( 'alias:FreeFormCertificate\Generators\MagicLinkHelper' )
			->shouldReceive( 'generate_magic_link' )->andReturn( 'https://example.com/magic' );

		$table = new AppointmentsListTable();
		$html  = $table->column_id( array( 'id' => 7, 'status' => 'pending', 'confirmation_token' => 'tok' ) );

		$this->assertStringContainsString( '#7', $html );
		$this->assertStringContainsString( 'Confirm', $html );
		$this->assertStringContainsString( 'ffc-appointment-cancel', $html );
		$this->assertStringContainsString( '>View<', $html, 'A manager is offered the manage-gated detail view.' );
		$this->assertStringContainsString( 'appointment=7', $html );
		$this->assertStringContainsString( 'https://example.com/magic', $html );
	}

	public function test_column_id_readonly_cancelled_shows_no_row_action(): void {
		Functions\when( 'current_user_can' )->justReturn( false ); // read-only viewer.
		Functions\when( 'add_query_arg' )->alias( fn( $a, $u = '' ) => $u . '?' . http_build_query( (array) $a ) );

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
		Functions\when( 'add_query_arg' )->alias( fn( $a, $u = '' ) => $u . '?' . http_build_query( (array) $a ) );
		Mockery::mock( 'alias:FreeFormCertificate\SelfScheduling\AppointmentReceiptHandler' )
			->shouldReceive( 'get_receipt_url' )->andReturn( 'https://example.com/receipt' );

		$table = new AppointmentsListTable();
		$html  = $table->column_id( array( 'id' => 11, 'status' => 'confirmed', 'confirmation_token' => '' ) );

		$this->assertStringContainsString( 'https://example.com/receipt', $html );
	}

	public function test_column_calendar_links_to_post_when_found(): void {
		$this->cal_repo->shouldReceive( 'findById' )->with( 9 )->andReturn(
			array( 'post_id' => 100, 'title' => 'Clinic' )
		);

		$table = new AppointmentsListTable();
		$out   = $table->column_calendar( array( 'calendar_id' => 9 ) );

		$this->assertStringContainsString( 'post=100', $out );
		$this->assertStringContainsString( 'Clinic', $out );
	}

	public function test_column_calendar_deleted_when_missing(): void {
		$this->cal_repo->shouldReceive( 'findById' )->with( 9 )->andReturn( null );

		$table = new AppointmentsListTable();
		$this->assertSame( '(Deleted)', $table->column_calendar( array( 'calendar_id' => 9 ) ) );
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
	 * the appointments domain, through the WordPress functions it reads, and
	 * stub the email decryption, the mask and the nonce around column_email().
	 *
	 * @param string $tier unmasked (manage_options) | admin_role
	 *                     (ffc_appointments_admin) | reveal (the PII cap) | masked.
	 * @param string $email Decrypted address decrypt_field() returns.
	 */
	private function driveEmailColumn( string $tier, string $email = 'a@b.com' ): void {
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
		Functions\when( 'wp_create_nonce' )->justReturn( 'test_nonce' );

		Mockery::mock( 'alias:FreeFormCertificate\Core\Encryption' )
			->shouldReceive( 'decrypt_field' )->andReturn( $email );
		Mockery::mock( 'alias:FreeFormCertificate\Core\DocumentFormatter' )
			->shouldReceive( 'mask_email' )->andReturnUsing( fn( $e ) => 'MASKED:' . (string) $e );
	}

	private function emailCellFor( string $tier ): string {
		$this->driveEmailColumn( $tier );
		$table = new AppointmentsListTable();
		return $table->column_email( array( 'id' => 42, 'user_id' => 99, 'email_encrypted' => 'X' ) );
	}

	private function assertMaskedWithReveal( string $out ): void {
		$this->assertStringNotContainsString( 'a@b.com', str_replace( 'MASKED:a@b.com', '', $out ), 'The clear address must not reach the list HTML.' );
		$this->assertStringContainsString( '<span class="ffc-pii-value" data-field="email">MASKED:a@b.com</span>', $out );
		$this->assertStringContainsString( 'class="button button-small ffc-reveal-pii ffc-icon-eye"', $out );
		$this->assertStringContainsString( 'data-type="appointment" data-field="email" data-submission-id="42" data-nonce="test_nonce"', $out );
	}

	public function test_column_email_unmasked_tier_is_masked_with_reveal_button(): void {
		$this->assertMaskedWithReveal( $this->emailCellFor( 'unmasked' ) );
	}

	public function test_column_email_appointments_admin_role_is_masked_with_reveal_button(): void {
		$this->assertMaskedWithReveal( $this->emailCellFor( 'admin_role' ) );
	}

	public function test_column_email_reveal_tier_is_masked_with_reveal_button(): void {
		$this->assertMaskedWithReveal( $this->emailCellFor( 'reveal' ) );
	}

	public function test_column_email_masked_tier_masks_without_button(): void {
		$out = $this->emailCellFor( 'masked' );
		$this->assertSame( '<span class="ffc-pii-value" data-field="email">MASKED:a@b.com</span>', $out );
		$this->assertStringNotContainsString( 'ffc-reveal-pii', $out );
	}

	public function test_column_email_owner_gets_reveal_button(): void {
		$this->driveEmailColumn( 'masked' );
		$table = new AppointmentsListTable();
		$this->assertMaskedWithReveal( $table->column_email( array( 'id' => 42, 'user_id' => 7, 'email_encrypted' => 'X' ) ) );
	}

	public function test_column_email_empty_renders_dash_without_button(): void {
		$this->driveEmailColumn( 'unmasked', '' );
		$table = new AppointmentsListTable();
		$this->assertSame( '-', $table->column_email( array( 'id' => 42 ) ) );
	}

	public function test_reveal_nonce_is_created_once_per_render(): void {
		$this->driveEmailColumn( 'unmasked' );
		$nonces = CallLog::record( 'wp_create_nonce', 'test_nonce' );
		$table  = new AppointmentsListTable();

		$table->column_email( array( 'id' => 1, 'email_encrypted' => 'X' ) );
		$table->column_email( array( 'id' => 2, 'email_encrypted' => 'Y' ) );

		$this->assertSame( 1, $nonces->count() );
	}

	public function test_column_time_formats_range(): void {
		Mockery::mock( 'alias:FreeFormCertificate\Core\DateFormatter' )
			->shouldReceive( 'format_wallclock_time' )->andReturn( '09:00', '10:00' );

		$table = new AppointmentsListTable();
		$this->assertSame(
			'09:00 - 10:00',
			$table->column_time( array( 'start_time' => '09:00:00', 'end_time' => '10:00:00' ) )
		);
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

		Functions\when( 'get_user_by' )->justReturn( false );

		$captured = array();
		$this->appt_repo->shouldReceive( 'findAll' )->andReturnUsing(
			function ( $conditions ) use ( &$captured ) {
				$captured = $conditions;
				return array( array( 'id' => 1 ) );
			}
		);
		$this->appt_repo->shouldReceive( 'count' )->andReturn( 5 );

		// RequestInput::get_get_string for the status filter.
		Mockery::mock( 'alias:FreeFormCertificate\Core\RequestInput' )
			->shouldReceive( 'get_get_string' )->andReturn( 'confirmed' )
			->shouldReceive( 'get_get_key' )->andReturnUsing( static fn( $key, $default = '' ) => isset( $_GET[ $key ] ) ? (string) $_GET[ $key ] : $default )
			->shouldReceive( 'get_get_int' )->andReturnUsing( static fn( $key, $default = 0 ) => isset( $_GET[ $key ] ) ? (int) $_GET[ $key ] : $default )
			->shouldReceive( 'has_get' )->andReturnUsing( static fn( $key ) => isset( $_GET[ $key ] ) );

		$table = new AppointmentsListTable();
		$table->prepare_items();

		$this->assertSame( 9, $captured['calendar_id'] );
		$this->assertSame( 'confirmed', $captured['status'] );
	}

	/**
	 * ORDER BY column and direction the last prepare_items() handed to
	 * findAll() (#1770).
	 *
	 * @var array<int, string>
	 */
	private $captured_order = array();

	/**
	 * Mock the repository and the request once, so prepare_items() can run
	 * repeatedly in one test with a different query string each time.
	 */
	private function capture_order(): void {
		$this->appt_repo->shouldReceive( 'findAll' )->andReturnUsing(
			function ( $conditions, $order_by, $order ) {
				$this->captured_order = array( $order_by, $order );
				return array();
			}
		);

		Mockery::mock( 'alias:FreeFormCertificate\Core\RequestInput' )
			->shouldReceive( 'get_get_string' )->andReturnUsing( static fn( $key, $default = '' ) => isset( $_GET[ $key ] ) ? (string) $_GET[ $key ] : $default )
			->shouldReceive( 'get_get_int' )->andReturnUsing( static fn( $key, $default = 0 ) => isset( $_GET[ $key ] ) ? (int) $_GET[ $key ] : $default );
	}

	/**
	 * Run prepare_items() under the given query string.
	 *
	 * @param array<string, string> $query Request query vars.
	 * @return array<int, string>
	 */
	private function order_for( array $query ): array {
		unset( $_GET['orderby'], $_GET['order'] );
		foreach ( $query as $key => $value ) {
			$_GET[ $key ] = $value;
		}
		$this->captured_order = array();

		$table = new AppointmentsListTable();
		$table->prepare_items();

		return $this->captured_order;
	}

	public function test_prepare_items_default_order_is_created_at_desc(): void {
		$this->capture_order();
		$this->assertSame( array( 'created_at', 'DESC' ), $this->order_for( array() ) );
	}

	/**
	 * Every sortable column the table declares must reach the query, in the
	 * direction the header link asks for (#1770).
	 */
	public function test_prepare_items_sorts_by_every_declared_sortable_column(): void {
		$this->capture_order();
		$table   = new AppointmentsListTable();
		$orderby = array_map(
			static fn( $spec ) => $spec[0],
			array_values( $table->get_sortable_columns() )
		);

		$this->assertNotEmpty( $orderby );
		foreach ( $orderby as $column ) {
			$this->assertSame(
				array( $column, 'ASC' ),
				$this->order_for( array( 'orderby' => $column, 'order' => 'asc' ) ),
				"orderby={$column}&order=asc must sort by {$column} ASC."
			);
			$this->assertSame(
				array( $column, 'DESC' ),
				$this->order_for( array( 'orderby' => $column, 'order' => 'desc' ) ),
				"orderby={$column}&order=desc must sort by {$column} DESC."
			);
		}
	}

	public function test_prepare_items_ignores_an_unlisted_orderby(): void {
		$this->capture_order();
		$this->assertSame(
			array( 'created_at', 'DESC' ),
			$this->order_for( array( 'orderby' => 'email_encrypted', 'order' => 'asc' ) )
		);
		$this->assertSame(
			array( 'created_at', 'DESC' ),
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
		Functions\when( 'add_query_arg' )->alias( fn( $a, $u = '' ) => $u . '?' . http_build_query( (array) $a ) );
		Functions\when( 'wp_nonce_url' )->alias( fn( $u, $a = '' ) => $u . '&_n=' . $a );
		Mockery::mock( 'alias:FreeFormCertificate\Generators\MagicLinkHelper' )
			->shouldReceive( 'generate_magic_link' )->andReturn( 'https://example.com/magic' );

		$table = new AppointmentsListTable();
		$html  = $table->column_id( array( 'id' => 21, 'status' => 'waitlist', 'confirmation_token' => 'tok' ) );

		$this->assertStringContainsString( 'ffc-appointment-cancel', $html );
		$this->assertStringContainsString( 'ffc_cancel_appointment_21', $html );
		// confirm() does not recount the slot, so it would overbook a full one.
		$this->assertStringNotContainsString( 'Confirm', $html );
	}

	public function test_column_id_waitlist_readonly_offers_no_mutation(): void {
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( 'add_query_arg' )->alias( fn( $a, $u = '' ) => $u . '?' . http_build_query( (array) $a ) );
		Mockery::mock( 'alias:FreeFormCertificate\Generators\MagicLinkHelper' )
			->shouldReceive( 'generate_magic_link' )->andReturn( 'https://example.com/magic' );

		$table = new AppointmentsListTable();
		$html  = $table->column_id( array( 'id' => 21, 'status' => 'waitlist', 'confirmation_token' => 'tok' ) );

		$this->assertStringNotContainsString( 'ffc-appointment-cancel', $html );
		$this->assertStringNotContainsString( 'Confirm', $html );
	}

	public function test_extra_tablenav_offers_waitlist_status_filter(): void {
		Mockery::mock( 'alias:FreeFormCertificate\Core\RequestInput' )
			->shouldReceive( 'get_get_string' )->andReturn( '' )
			->shouldReceive( 'get_get_int' )->andReturn( 0 );

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
	}

	public function test_extra_tablenav_renders_calendar_options(): void {
		Mockery::mock( 'alias:FreeFormCertificate\Core\RequestInput' )
			->shouldReceive( 'get_get_string' )->andReturn( '' )
			->shouldReceive( 'get_get_key' )->andReturnUsing( static fn( $key, $default = '' ) => isset( $_GET[ $key ] ) ? (string) $_GET[ $key ] : $default )
			->shouldReceive( 'get_get_int' )->andReturnUsing( static fn( $key, $default = 0 ) => isset( $_GET[ $key ] ) ? (int) $_GET[ $key ] : $default )
			->shouldReceive( 'has_get' )->andReturnUsing( static fn( $key ) => isset( $_GET[ $key ] ) );
		$this->cal_repo->shouldReceive( 'getActiveCalendars' )->andReturn(
			array( array( 'id' => 1, 'title' => 'Cal One' ) )
		);

		$table = new AppointmentsListTable();

		ob_start();
		$this->call_protected( $table, 'extra_tablenav', array( 'top' ) );
		$out = ob_get_clean();

		$this->assertStringContainsString( 'Cal One', $out );
		$this->assertStringContainsString( 'All Statuses', $out );
	}
}
