<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FreeFormCertificate\Core\Encryption;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Tests\Support\Identifiers;

/**
 * Tests for includes/self-scheduling/views/appointments-list.php.
 *
 * The view is a procedural admin template: a permission gate, the
 * confirm/cancel mutation handlers (redirect + exit), the single-appointment
 * detail branch (data prep + render), and the default list branch. We include
 * the file under different $_GET states with the WP surface stubbed, asserting
 * the branch-selecting logic — pure markup rows are incidental.
 *
 * Everything the view reaches runs for real — AppointmentRepository (with
 * its reader and writer), CalendarRepository, AppointmentsListTable,
 * Capabilities, RequestInput, Encryption, DocumentFormatter,
 * PiiAccessPolicy, PiiReveal and AdminNotice — over a `$wpdb` double that
 * answers each query by its SQL text from the rows a test stores and records
 * every write. A query the double does not know fails the test.
 * `wp_safe_redirect()` and `wp_die()` throw, which is how the mutation
 * branches stop before their `exit`.
 */
class AppointmentsListViewTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private const VIEW = '/includes/self-scheduling/views/appointments-list.php';

	private const APPOINTMENTS_TABLE = 'wp_ffc_self_scheduling_appointments';

	private const APPOINTMENTS = '`wp_ffc_self_scheduling_appointments`';

	private const CALENDARS = '`wp_ffc_self_scheduling_calendars`';

	/** @var array<int, array<string, mixed>> Appointment rows by id. */
	private array $appointments = array();

	/** @var array<int, array<string, mixed>> Calendar rows by id. */
	private array $calendars = array();

	/** @var int|false What `$wpdb->update()` answers. */
	private $update_result = 1;

	/** @var list<array{0: string, 1: array<string, mixed>, 2: array<string, mixed>}> Every update() the double received. */
	private array $updates = array();

	/** @var list<array<int, mixed>> Arguments of every do_action() call. */
	private array $actions = array();

	/** @var list<array<int, mixed>> Arguments of every set_transient() call. */
	private array $transients = array();

	/** @var list<array<string, string>> What the close-out count reads (#2285). */
	private array $closeout_due = array();

	/** @var list<string> Queries the double did not recognise. */
	private array $unexpected = array();

	/** @var mixed The `$wpdb` global an earlier test left, restored afterwards. */
	private $wpdb_backup;

	/** @var bool Whether `$wpdb` was set before this test. */
	private bool $had_wpdb = false;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'wp_admin_notice' )->alias(
			static function ( $message, $args = array() ) {
				$ffc_type = isset( $args['type'] ) ? $args['type'] : 'info';
				$ffc_cls  = 'notice notice-' . $ffc_type;
				if ( ! empty( $args['dismissible'] ) ) { $ffc_cls .= ' is-dismissible'; }
				if ( ! empty( $args['additional_classes'] ) ) { $ffc_cls .= ' ' . implode( ' ', $args['additional_classes'] ); }
				$ffc_wrap = ! array_key_exists( 'paragraph_wrap', $args ) || $args['paragraph_wrap'];
				echo '<div class="' . $ffc_cls . '">' . ( $ffc_wrap ? '<p>' . $message . '</p>' : $message ) . '</div>';
			}
		);

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'esc_html_e' )->alias( function ( $t ) { echo $t; } );
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_attr__' )->returnArg();
		Functions\when( 'admin_url' )->alias( fn( $p = '' ) => 'https://example.com/wp-admin/' . $p );
		Functions\when( 'add_query_arg' )->justReturn( 'https://example.com/wp-admin/admin.php?page=ffc-appointments' );
		Functions\when( 'absint' )->alias( fn( $v ) => abs( (int) $v ) );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'is_email' )->alias( static fn( $e ) => false !== filter_var( $e, FILTER_VALIDATE_EMAIL ) );
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'user_can' )->justReturn( false );
		Functions\when( 'get_user_by' )->justReturn( false );
		Functions\when( 'current_time' )->justReturn( '2026-05-01 10:00:00' );
		Functions\when( 'wp_timezone' )->justReturn( new \DateTimeZone( 'UTC' ) );
		Functions\when( 'wp_date' )->alias(
			static fn( $format, $ts = null, $tz = null ) => ( new \DateTimeImmutable( '@' . (int) ( $ts ?? time() ) ) )->setTimezone( $tz ?? new \DateTimeZone( 'UTC' ) )->format( $format )
		);
		Functions\when( 'wp_create_nonce' )->justReturn( 'nonce123' );
		Functions\when( 'wp_nonce_field' )->justReturn( '' );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->alias(
			function ( ...$args ) {
				$this->transients[] = $args;
				return true;
			}
		);
		Functions\when( 'delete_transient' )->justReturn( true );
		Functions\when( 'do_action' )->alias(
			function ( ...$args ) {
				$this->actions[] = $args;
			}
		);
		Functions\when( 'selected' )->justReturn( '' );
		Functions\when( 'submit_button' )->justReturn( null );
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		// Stubbed explicitly so the Debug toggles the view's error branch
		// reads do not depend on what ran earlier in the process.
		Functions\when( 'get_option' )->alias( static fn( $key, $default = false ) => $default );

		$this->appointments  = array();
		$this->calendars     = array();
		$this->update_result = 1;
		$this->updates       = array();
		$this->unexpected    = array();
		$this->closeout_due  = array();
		$this->actions       = array();
		$this->transients    = array();

		$this->had_wpdb    = array_key_exists( 'wpdb', $GLOBALS );
		$this->wpdb_backup = $this->had_wpdb ? $GLOBALS['wpdb'] : null;
		$GLOBALS['wpdb']   = $this->wpdb_double();
	}

	protected function tearDown(): void {
		unset( $_GET['appointment'], $_GET['ffc_action'], $_GET['reason'] );
		if ( $this->had_wpdb ) {
			$GLOBALS['wpdb'] = $this->wpdb_backup;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		Monkey\tearDown();
		parent::tearDown();
	}

	protected function assertPostConditions(): void {
		// The detail branch catches every Throwable, a failed assertion
		// included, so an unknown query is also checked here.
		$this->assertSame( array(), $this->unexpected, 'The $wpdb double received a query it does not know.' );
	}

	/**
	 * A `$wpdb` double: prepare() substitutes the bound values into the SQL
	 * text, reads answer from the stored rows, and update() is recorded.
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
				if ( preg_match( '/^SELECT \* FROM ' . preg_quote( self::APPOINTMENTS, '/' ) . ' WHERE id = (\d+)$/', $sql, $m ) ) {
					return $this->appointments[ (int) $m[1] ] ?? null;
				}
				if ( preg_match( '/^SELECT \* FROM ' . preg_quote( self::CALENDARS, '/' ) . ' WHERE id = (\d+)$/', $sql, $m ) ) {
					return $this->calendars[ (int) $m[1] ] ?? null;
				}
				return $this->unexpected_query( $sql );
			}
		);
		$wpdb->shouldReceive( 'get_results' )->andReturnUsing(
			function ( $sql ) {
				// An empty WHERE leaves its separating space behind.
				if ( 'SELECT * FROM ' . self::APPOINTMENTS . '  ORDER BY appointment_date DESC, start_time DESC, id DESC LIMIT 20 OFFSET 0' === $sql ) {
					return array_values( $this->appointments );
				}
				if ( str_starts_with( $sql, 'SELECT status, COUNT(*) AS total FROM ' . self::APPOINTMENTS ) ) {
					return $this->closeout_due;
				}
				return $this->unexpected_query( $sql );
			}
		);
		$wpdb->shouldReceive( 'get_var' )->andReturnUsing(
			function ( $sql ) {
				// An empty WHERE leaves its separating space behind.
				if ( 'SELECT COUNT(*) FROM ' . self::APPOINTMENTS . ' ' === $sql ) {
					return (string) count( $this->appointments );
				}
				return $this->unexpected_query( $sql );
			}
		);
		$wpdb->shouldReceive( 'update' )->andReturnUsing(
			function ( $table, $data, $where ) {
				$this->updates[] = array( $table, $data, $where );
				return $this->update_result;
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

	private function include_view(): void {
		include FFC_PLUGIN_DIR . self::VIEW;
	}

	/**
	 * Include the view expecting it to stop with the given exception message
	 * (the throwing wp_safe_redirect / wp_die stub).
	 *
	 * @param string $message Expected message fragment.
	 */
	private function include_view_expecting_stop( string $message ): void {
		try {
			$this->include_view();
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( $message, $e->getMessage() );
			return;
		}
		$this->fail( 'The view was expected to stop with: ' . $message );
	}

	// ==================================================================
	// Permission gate
	// ==================================================================

	public function test_view_dies_without_permission_on_specific_appointment(): void {
		$_GET['appointment'] = '5';
		Functions\when( 'wp_die' )->alias( fn( $msg ) => throw new \RuntimeException( $msg ) );
		Functions\when( 'current_user_can' )->justReturn( false );

		$this->include_view_expecting_stop( 'do not have permission' );
		$this->assertSame( array(), $this->updates );
	}

	// ==================================================================
	// Confirm mutation
	// ==================================================================

	public function test_view_confirm_success_redirects(): void {
		$_GET['appointment'] = '5';
		$_GET['ffc_action']  = 'confirm';
		Functions\when( 'check_admin_referer' )->justReturn( true );
		Functions\when( 'wp_safe_redirect' )->alias( fn() => throw new \RuntimeException( 'redirected' ) );

		$this->appointments[5] = array( 'id' => 5, 'calendar_id' => 7 );
		$this->calendars[7]    = array( 'id' => 7, 'title' => 'Cal' );

		$this->include_view_expecting_stop( 'redirected' );

		// The real writer confirmed appointment 5 on behalf of user 1.
		$this->assertCount( 1, $this->updates );
		$this->assertSame( self::APPOINTMENTS_TABLE, $this->updates[0][0] );
		$this->assertSame( array( 'id' => 5 ), $this->updates[0][2] );
		$this->assertSame( 'confirmed', $this->updates[0][1]['status'] );
		$this->assertSame( 1, $this->updates[0][1]['approved_by'] );

		$this->assertSame(
			array(
				array(
					'ffcertificate_self_scheduling_appointment_confirmed_email',
					array( 'id' => 5, 'calendar_id' => 7 ),
					array( 'id' => 7, 'title' => 'Cal' ),
				),
			),
			$this->actions
		);
		$this->assertSame( 'success', $this->transients[0][1]['type'] );
	}

	public function test_view_confirm_failure_redirects(): void {
		$_GET['appointment'] = '5';
		$_GET['ffc_action']  = 'confirm';
		Functions\when( 'check_admin_referer' )->justReturn( true );
		Functions\when( 'wp_safe_redirect' )->alias( fn() => throw new \RuntimeException( 'redirected' ) );

		// The stored write fails, so confirm() reports false.
		$this->update_result = false;

		$this->include_view_expecting_stop( 'redirected' );

		$this->assertCount( 1, $this->updates );
		$this->assertSame( array( 'id' => 5 ), $this->updates[0][2] );
		$this->assertSame( 'confirmed', $this->updates[0][1]['status'] );
		$this->assertSame( 1, $this->updates[0][1]['approved_by'] );
		$this->assertSame(
			array(
				'type'    => 'error',
				'message' => 'Failed to confirm appointment.',
			),
			$this->transients[0][1]
		);
	}

	// ==================================================================
	// Cancel mutation
	// ==================================================================

	public function test_view_cancel_success_redirects(): void {
		$_GET['appointment'] = '5';
		$_GET['ffc_action']  = 'cancel';
		$_GET['reason']      = 'Admin closed slot';
		Functions\when( 'check_admin_referer' )->justReturn( true );
		Functions\when( 'sanitize_textarea_field' )->returnArg();
		Functions\when( 'wp_safe_redirect' )->alias( fn() => throw new \RuntimeException( 'redirected' ) );

		// The waitlist promoter (#941 phase 2) snapshots the row before cancel and
		// fires ffcertificate_appointment_cancelled with it.
		$row                   = array( 'status' => 'confirmed', 'calendar_id' => 1, 'appointment_date' => '2026-01-01', 'start_time' => '09:00:00' );
		$this->appointments[5] = $row;

		$this->include_view_expecting_stop( 'redirected' );

		$this->assertCount( 1, $this->updates );
		$this->assertSame( self::APPOINTMENTS_TABLE, $this->updates[0][0] );
		$this->assertSame( array( 'id' => 5 ), $this->updates[0][2] );
		$this->assertSame( 'cancelled', $this->updates[0][1]['status'] );
		$this->assertSame( 1, $this->updates[0][1]['cancelled_by'] );
		$this->assertSame( 'Admin closed slot', $this->updates[0][1]['cancellation_reason'] );

		$this->assertSame(
			array( array( 'ffcertificate_appointment_cancelled', 5, $row, 'Admin closed slot', 1 ) ),
			$this->actions
		);
	}

	// ==================================================================
	// Attendance marks
	// ==================================================================

	/**
	 * Run an attendance action on appointment 5 and return the notice it left.
	 *
	 * @param string $action The ffc_action.
	 * @return array<string, string>
	 */
	private function run_attendance( string $action ): array {
		$_GET['appointment'] = '5';
		$_GET['ffc_action']  = $action;
		$referers            = array();
		Functions\when( 'check_admin_referer' )->alias(
			static function ( $nonce_action ) use ( &$referers ) {
				$referers[] = $nonce_action;
				return true;
			}
		);
		Functions\when( 'wp_date' )->justReturn( '2026-05-10' );
		Functions\when( 'wp_safe_redirect' )->alias( fn() => throw new \RuntimeException( 'redirected' ) );

		$this->include_view_expecting_stop( 'redirected' );

		$this->assertSame( array( 'ffc_' . $action . '_appointment_5' ), $referers );
		$this->assertCount( 1, $this->transients );
		$this->assertSame( 'ffc_admin_notice_1', $this->transients[0][0] );
		return $this->transients[0][1];
	}

	/**
	 * @return array<string, array{0: string, 1: string, 2: string}>
	 */
	public static function attendance_marks(): array {
		return array(
			'attended' => array( 'attended', 'completed', 'Appointment marked as attended.' ),
			'no-show'  => array( 'no_show', 'no_show', 'Appointment marked as no-show.' ),
		);
	}

	/**
	 * @dataProvider attendance_marks
	 */
	public function test_attendance_mark_writes_only_over_a_confirmed_row( string $action, string $status, string $message ): void {
		$this->appointments[5] = array( 'id' => 5, 'status' => 'confirmed', 'appointment_date' => '2026-05-10' );

		$notice = $this->run_attendance( $action );

		$this->assertCount( 1, $this->updates );
		$this->assertSame( self::APPOINTMENTS_TABLE, $this->updates[0][0] );
		$this->assertSame( $status, $this->updates[0][1]['status'] );
		$this->assertSame( array( 'id' => 5, 'status' => 'confirmed' ), $this->updates[0][2] );
		$this->assertSame( array( 'type' => 'success', 'message' => $message ), $notice );
		$this->assertSame( array(), $this->actions, 'An attendance mark sends no e-mail and fires no hook.' );
	}

	/**
	 * @dataProvider attendance_marks
	 */
	public function test_attendance_mark_before_the_date_writes_nothing( string $action ): void {
		$this->appointments[5] = array( 'id' => 5, 'status' => 'confirmed', 'appointment_date' => '2026-05-11' );

		$notice = $this->run_attendance( $action );

		$this->assertSame( array(), $this->updates );
		$this->assertSame(
			array( 'type' => 'error', 'message' => 'Attendance can only be marked from the appointment date on.' ),
			$notice
		);
	}

	public function test_attendance_mark_on_a_missing_appointment_writes_nothing(): void {
		$notice = $this->run_attendance( 'attended' );

		$this->assertSame( array(), $this->updates );
		$this->assertSame( array( 'type' => 'error', 'message' => 'Appointment not found.' ), $notice );
	}

	public function test_attendance_mark_on_a_row_that_moved_meanwhile_reports_an_error(): void {
		$this->appointments[5] = array( 'id' => 5, 'status' => 'confirmed', 'appointment_date' => '2026-05-01' );
		// The conditional UPDATE matched no row: another request changed it.
		$this->update_result = 0;

		$notice = $this->run_attendance( 'no_show' );

		$this->assertCount( 1, $this->updates );
		$this->assertSame( 'error', $notice['type'] );
		$this->assertStringContainsString( 'may have changed meanwhile', $notice['message'] );
	}

	public function test_undo_attendance_puts_the_row_back_to_confirmed(): void {
		$this->appointments[5] = array( 'id' => 5, 'status' => 'no_show', 'appointment_date' => '2026-05-01' );

		$notice = $this->run_attendance( 'undo_attendance' );

		$this->assertCount( 1, $this->updates );
		$this->assertSame( 'confirmed', $this->updates[0][1]['status'] );
		$this->assertSame( array( 'id' => 5, 'status' => 'no_show' ), $this->updates[0][2] );
		$this->assertSame(
			array( 'type' => 'success', 'message' => 'Attendance mark undone. The appointment is confirmed again.' ),
			$notice
		);
	}

	public function test_undo_attendance_refuses_a_row_without_an_attendance_mark(): void {
		foreach ( array( 'cancelled', 'pending', 'confirmed' ) as $status ) {
			$this->appointments[5] = array( 'id' => 5, 'status' => $status, 'appointment_date' => '2026-05-01' );
			$this->transients      = array();

			$notice = $this->run_attendance( 'undo_attendance' );

			$this->assertSame( array(), $this->updates, $status );
			$this->assertSame( 'error', $notice['type'], $status );
		}
	}

	// ==================================================================
	// Detail view
	// ==================================================================

	public function test_view_renders_appointment_detail(): void {
		$_GET['appointment'] = '5';
		// #739 §3.3 — the detail view asks whether the viewer may reveal PII.
		Functions\when( 'user_can' )->justReturn( true );

		$this->appointments[5] = array(
			'id'               => 5,
			'status'           => 'confirmed',
			'calendar_id'      => 7,
			'appointment_date' => '2026-05-20',
			'start_time'       => '09:00:00',
			'end_time'         => '10:00:00',
			'name'             => 'Alice',
			'created_at'       => '2026-05-01 14:05:00',
			'custom_data'      => '{"field_a":"v","field_b":["x","y"]}',
		);
		$this->calendars[7]    = array( 'id' => 7, 'title' => 'Clinic' );

		ob_start();
		$this->include_view();
		$out = ob_get_clean();

		// Dates and times in the site's formats, not the stored ones (#2288).
		$this->assertStringContainsString( '<td>20/05/2026</td>', $out );
		$this->assertStringContainsString( '<td>09:00 - 10:00</td>', $out );
		$this->assertStringContainsString( '<td>01/05/2026 14:05</td>', $out );
		$this->assertStringNotContainsString( '2026-05-20', $out );
		$this->assertStringContainsString( 'Appointment Details', $out );
		$this->assertStringContainsString( 'Clinic', $out );
		$this->assertStringContainsString( 'Alice', $out );
		$this->assertStringNotContainsString( 'Error loading appointment', $out );
	}

	/**
	 * Render the detail view of appointment 5 under the REAL PII policy and
	 * the REAL mask helpers, with the viewer's tier chosen through `user_can`.
	 * The e-mail is stored encrypted and decrypted by the real Encryption;
	 * CPF and RF are stored as the plain columns decrypt_appointment()
	 * passes through, since a row decrypts to one of the two, never both.
	 *
	 * @param string               $tier unmasked | reveal | masked.
	 * @param array<string, mixed> $pii  The stored email (plain, encrypted here) / cpf / rf.
	 * @return string The rendered HTML.
	 */
	private function render_detail_as( string $tier, array $pii ): string {
		$_GET['appointment'] = '5';
		// manage_options and the domain PII cap are both the reveal tier;
		// neither is the masked tier (PiiAccessPolicy::resolve).
		Functions\when( 'user_can' )->alias(
			static function ( $uid, $cap ) use ( $tier ) {
				if ( 'manage_options' === $cap ) {
					return 'unmasked' === $tier;
				}
				return 'ffc_view_appointments_pii' === $cap && 'reveal' === $tier;
			}
		);

		if ( isset( $pii['email'] ) && '' !== $pii['email'] ) {
			$pii['email_encrypted'] = Encryption::encrypt( $pii['email'] );
			unset( $pii['email'] );
		}

		$this->appointments[5] = array_merge(
			array(
				'id'               => 5,
				'status'           => 'confirmed',
				'calendar_id'      => 7,
				'appointment_date' => '2026-05-20',
				'start_time'       => '09:00:00',
				'end_time'         => '10:00:00',
				'name'             => 'Alice',
				'created_at'       => '2026-05-01',
				'user_id'          => 42,
			),
			$pii
		);
		$this->calendars[7]    = array( 'id' => 7, 'title' => 'Clinic' );

		ob_start();
		$this->include_view();
		return (string) ob_get_clean();
	}

	/**
	 * @return array<string, array{0: string, 1: bool}>
	 */
	public function tier_provider(): array {
		return array(
			'unmasked tier: masked, with a button' => array( 'unmasked', true ),
			'reveal tier: masked, with a button'   => array( 'reveal', true ),
			'masked tier: masked, no button'       => array( 'masked', false ),
		);
	}

	/**
	 * #1813 — every tier sees the masked value, the unmasked one included;
	 * whoever may reveal gets the button, and the clear value never reaches
	 * the HTML.
	 *
	 * @dataProvider tier_provider
	 */
	public function test_view_appointment_detail_masks_pii_for_every_tier( string $tier, bool $has_button ): void {
		$out = $this->render_detail_as(
			$tier,
			array(
				'email' => 'alice@example.com',
				'cpf'   => Identifiers::CPF_A,
				'rf'    => Identifiers::RF_A,
			)
		);

		// The existing masked formats, from the real DocumentFormatter.
		$this->assertStringContainsString( '<span class="ffc-pii-value" data-field="email">a***@example.com</span>', $out );
		$this->assertStringContainsString( '<span class="ffc-pii-value" data-field="cpf">518.***.***-80</span>', $out );
		$this->assertStringContainsString( '<span class="ffc-pii-value" data-field="rf">518.***-0</span>', $out );

		// The clear value is absent, raw or formatted.
		$this->assertStringNotContainsString( 'alice@example.com', $out );
		$this->assertStringNotContainsString( Identifiers::CPF_A, $out );
		$this->assertStringNotContainsString( '518.178.420-80', $out );
		$this->assertStringNotContainsString( Identifiers::RF_A, $out );

		preg_match_all( '/<button[^>]*class="ffc-btn ffc-btn-neutral ffc-icon-eye ffc-reveal-pii"[^>]*>/', $out, $buttons );
		if ( $has_button ) {
			$this->assertCount( 3, $buttons[0] );
			foreach ( array( 'email', 'cpf', 'rf' ) as $field ) {
				$this->assertStringContainsString(
					'class="ffc-btn ffc-btn-neutral ffc-icon-eye ffc-reveal-pii" data-type="appointment" data-field="' . $field . '" data-submission-id="5" data-nonce="nonce123"',
					$out
				);
			}
		} else {
			$this->assertSame( array(), $buttons[0] );
			$this->assertStringNotContainsString( 'ffc-reveal-pii', $out );
			$this->assertStringNotContainsString( 'nonce123', $out );
		}
	}

	/**
	 * An empty value renders a dash and offers nothing to reveal, even to a
	 * viewer who may reveal; absent CPF and RF rows are not drawn at all.
	 */
	public function test_view_appointment_detail_empty_pii_shows_dash_without_button(): void {
		$out = $this->render_detail_as( 'unmasked', array( 'email' => '' ) );

		$this->assertStringContainsString( '<tr><th>E-mail</th><td>-</td></tr>', $out );
		$this->assertStringNotContainsString( 'ffc-reveal-pii', $out );
		$this->assertStringNotContainsString( 'ffc-pii-value', $out );
		$this->assertStringNotContainsString( '<tr><th>CPF</th>', $out );
		$this->assertStringNotContainsString( '<tr><th>RF</th>', $out );
	}

	public function test_view_detail_not_found_shows_notice(): void {
		$_GET['appointment'] = '5';

		ob_start();
		$this->include_view();
		$out = ob_get_clean();

		$this->assertStringContainsString( 'Appointment not found.', $out );
	}

	// ==================================================================
	// List view (default, no specific appointment)
	// ==================================================================

	public function test_view_renders_list_table_by_default(): void {
		// The list's "Reveal this page" asks the policy (#1863); this viewer
		// holds the appointments PII cap.
		Functions\when( 'user_can' )->alias( static fn( $uid, $cap ) => 'ffc_view_appointments_pii' === $cap );
		Functions\when( 'wp_create_nonce' )->justReturn( 'page-nonce' );

		ob_start();
		$this->include_view();
		$out = ob_get_clean();

		$this->assertStringContainsString( 'Appointments', $out );
		$this->assertStringContainsString( 'Export CSV', $out );
		$this->assertStringContainsString( 'class="ffc-btn ffc-btn-neutral ffc-icon-eye ffc-reveal-pii-page" data-nonce="page-nonce"', $out );
		// The header's Export CSV is a family button with the hook the script binds (#1904).
		$this->assertStringContainsString( '<button type="button" class="ffc-btn ffc-btn-neutral ffc-icon-download" id="ffc-appointments-export-btn"', $out );
		$this->assertStringNotContainsString( 'page-title-action', $out );
	}

	public function test_the_list_offers_no_close_out_when_nothing_is_due(): void {
		ob_start();
		$this->include_view();
		$out = ob_get_clean();

		$this->assertStringNotContainsString( 'Close old appointments now', $out );
	}

	public function test_the_list_offers_the_close_out_with_the_due_counts(): void {
		Functions\when( 'wp_nonce_url' )->alias( static fn( $url, $action ) => $url . '&_wpnonce=' . $action );
		Functions\when( '_n' )->alias( static fn( $one, $many, $n ) => 1 === $n ? $one : $many );
		$this->closeout_due = array(
			array( 'status' => 'confirmed', 'total' => '3' ),
			array( 'status' => 'pending', 'total' => '1' ),
		);

		ob_start();
		$this->include_view();
		$out = ob_get_clean();

		$this->assertStringContainsString( 'dated 7 or more days ago are still open: 3 confirmed (will be marked completed) and 1 pending (will be cancelled)', $out );
		$this->assertStringContainsString( 'class="ffc-btn ffc-btn-caution ffc-icon-calendar-check"', $out );
		$this->assertStringContainsString( '_wpnonce=ffc_close_old_appointments', $out );
		$this->assertStringContainsString( 'data-confirm="Close 4 old appointments? This cannot be undone in bulk."', $out );
	}

	public function test_a_read_only_viewer_is_not_offered_the_close_out(): void {
		Functions\when( 'current_user_can' )->justReturn( false );
		$this->closeout_due = array( array( 'status' => 'confirmed', 'total' => '3' ) );

		ob_start();
		$this->include_view();
		$out = ob_get_clean();

		$this->assertStringNotContainsString( 'Close old appointments now', $out );
	}

	public function test_close_old_runs_the_close_out_and_reports_the_counts(): void {
		$_GET['ffc_action'] = 'close_old';
		$referers           = array();
		Functions\when( 'check_admin_referer' )->alias(
			static function ( $action ) use ( &$referers ) {
				$referers[] = $action;
				return true;
			}
		);
		Functions\when( 'wp_safe_redirect' )->alias( fn() => throw new \RuntimeException( 'redirected' ) );
		Functions\when( 'wp_cache_supports' )->justReturn( true );
		Functions\when( 'wp_cache_flush_group' )->justReturn( true );
		$queries = array();
		$GLOBALS['wpdb']->shouldReceive( 'query' )->andReturnUsing(
			static function ( $sql ) use ( &$queries ) {
				$queries[] = $sql;
				return 1 === count( $queries ) ? 2 : 1;
			}
		);

		$this->include_view_expecting_stop( 'redirected' );

		$this->assertSame( array( 'ffc_close_old_appointments' ), $referers );
		$this->assertCount( 2, $queries, 'One short batch each: confirmed, then pending.' );
		$this->assertSame(
			array( 'type' => 'success', 'message' => 'Old appointments closed: 2 marked completed, 1 pending cancelled.' ),
			$this->transients[0][1]
		);
	}

	public function test_close_old_without_the_capability_dies_before_anything_runs(): void {
		$_GET['ffc_action'] = 'close_old';
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( 'wp_die' )->alias( fn( $m ) => throw new \RuntimeException( 'died: ' . $m ) );
		Functions\expect( 'check_admin_referer' )->never();

		$this->include_view_expecting_stop( 'died' );
		$this->assertSame( array(), $this->transients );
	}

	public function test_the_list_offers_no_page_reveal_without_the_pii_cap(): void {
		Functions\when( 'user_can' )->justReturn( false );

		ob_start();
		$this->include_view();
		$out = ob_get_clean();

		$this->assertStringNotContainsString( 'ffc-reveal-pii-page', $out );
	}
}
