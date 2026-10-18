<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
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
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class AppointmentsListViewTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private const VIEW = '/includes/self-scheduling/views/appointments-list.php';

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
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'wp_create_nonce' )->justReturn( 'nonce123' );
		Functions\when( 'wp_nonce_field' )->justReturn( '' );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'delete_transient' )->justReturn( true );
		Functions\when( 'do_action' )->justReturn( null );
		Functions\when( 'selected' )->justReturn( '' );
		Functions\when( 'submit_button' )->justReturn( null );

	}

	protected function tearDown(): void {
		unset( $_GET['appointment'], $_GET['ffc_action'], $_GET['reason'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	private function include_view(): void {
		include FFC_PLUGIN_DIR . self::VIEW;
	}

	// ==================================================================
	// Permission gate
	// ==================================================================

	public function test_view_dies_without_permission_on_specific_appointment(): void {
		$_GET['appointment'] = '5';
		Functions\when( 'wp_die' )->alias( fn( $msg ) => throw new \RuntimeException( $msg ) );

		Mockery::mock( 'alias:FreeFormCertificate\Core\Capabilities' )
			->shouldReceive( 'current_user_can_admin_or' )->andReturn( false );
		Mockery::mock( 'alias:FreeFormCertificate\Core\RequestInput' )
			->shouldReceive( 'get_get_string' )->andReturn( '' )
			->shouldReceive( 'get_get_key' )->andReturnUsing( static fn( $key, $default = '' ) => isset( $_GET[ $key ] ) ? (string) $_GET[ $key ] : $default )
			->shouldReceive( 'get_get_int' )->andReturnUsing( static fn( $key, $default = 0 ) => isset( $_GET[ $key ] ) ? (int) $_GET[ $key ] : $default )
			->shouldReceive( 'has_get' )->andReturnUsing( static fn( $key ) => isset( $_GET[ $key ] ) );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'do not have permission' );
		$this->include_view();
	}

	// ==================================================================
	// Confirm mutation
	// ==================================================================

	public function test_view_confirm_success_redirects(): void {
		$_GET['appointment'] = '5';
		$_GET['ffc_action']  = 'confirm';
		Functions\when( 'check_admin_referer' )->justReturn( true );
		Functions\when( 'wp_safe_redirect' )->alias( fn() => throw new \RuntimeException( 'redirected' ) );

		Mockery::mock( 'alias:FreeFormCertificate\Core\Capabilities' )
			->shouldReceive( 'current_user_can_admin_or' )->andReturn( true );
		Mockery::mock( 'alias:FreeFormCertificate\Core\RequestInput' )
			->shouldReceive( 'get_get_string' )->andReturnUsing( fn( $k ) => 'ffc_action' === $k ? 'confirm' : '' )
			->shouldReceive( 'get_get_key' )->andReturnUsing( static fn( $key, $default = '' ) => isset( $_GET[ $key ] ) ? (string) $_GET[ $key ] : $default )
			->shouldReceive( 'get_get_int' )->andReturnUsing( static fn( $key, $default = 0 ) => isset( $_GET[ $key ] ) ? (int) $_GET[ $key ] : $default )
			->shouldReceive( 'has_get' )->andReturnUsing( static fn( $key ) => isset( $_GET[ $key ] ) );

		$appt_repo = Mockery::mock( 'overload:FreeFormCertificate\Repositories\AppointmentRepository' );
		$appt_repo->shouldReceive( 'confirm' )->with( 5, 1 )->andReturn( true );
		$appt_repo->shouldReceive( 'findById' )->with( 5 )->andReturn(
			array( 'id' => 5, 'calendar_id' => 7 )
		);

		$cal_repo = Mockery::mock( 'overload:FreeFormCertificate\Repositories\CalendarRepository' );
		$cal_repo->shouldReceive( 'findById' )->with( 7 )->andReturn( array( 'id' => 7, 'title' => 'Cal' ) );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'redirected' );
		$this->include_view();
	}

	public function test_view_confirm_failure_redirects(): void {
		$_GET['appointment'] = '5';
		$_GET['ffc_action']  = 'confirm';
		Functions\when( 'check_admin_referer' )->justReturn( true );
		Functions\when( 'wp_safe_redirect' )->alias( fn() => throw new \RuntimeException( 'redirected' ) );

		Mockery::mock( 'alias:FreeFormCertificate\Core\Capabilities' )
			->shouldReceive( 'current_user_can_admin_or' )->andReturn( true );
		Mockery::mock( 'alias:FreeFormCertificate\Core\RequestInput' )
			->shouldReceive( 'get_get_string' )->andReturnUsing( fn( $k ) => 'ffc_action' === $k ? 'confirm' : '' )
			->shouldReceive( 'get_get_key' )->andReturnUsing( static fn( $key, $default = '' ) => isset( $_GET[ $key ] ) ? (string) $_GET[ $key ] : $default )
			->shouldReceive( 'get_get_int' )->andReturnUsing( static fn( $key, $default = 0 ) => isset( $_GET[ $key ] ) ? (int) $_GET[ $key ] : $default )
			->shouldReceive( 'has_get' )->andReturnUsing( static fn( $key ) => isset( $_GET[ $key ] ) );

		$appt_repo = Mockery::mock( 'overload:FreeFormCertificate\Repositories\AppointmentRepository' );
		$appt_repo->shouldReceive( 'confirm' )->with( 5, 1 )->andReturn( false );
		Mockery::mock( 'overload:FreeFormCertificate\Repositories\CalendarRepository' );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'redirected' );
		$this->include_view();
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

		Mockery::mock( 'alias:FreeFormCertificate\Core\Capabilities' )
			->shouldReceive( 'current_user_can_admin_or' )->andReturn( true );
		Mockery::mock( 'alias:FreeFormCertificate\Core\RequestInput' )
			->shouldReceive( 'get_get_string' )->andReturnUsing( fn( $k ) => 'ffc_action' === $k ? 'cancel' : '' )
			->shouldReceive( 'get_get_key' )->andReturnUsing( static fn( $key, $default = '' ) => isset( $_GET[ $key ] ) ? (string) $_GET[ $key ] : $default )
			->shouldReceive( 'get_get_int' )->andReturnUsing( static fn( $key, $default = 0 ) => isset( $_GET[ $key ] ) ? (int) $_GET[ $key ] : $default )
			->shouldReceive( 'has_get' )->andReturnUsing( static fn( $key ) => isset( $_GET[ $key ] ) );

		Functions\when( 'do_action' )->justReturn( null );

		$appt_repo = Mockery::mock( 'overload:FreeFormCertificate\Repositories\AppointmentRepository' );
		// The waitlist promoter (#941 phase 2) snapshots the row before cancel and
		// fires ffcertificate_appointment_cancelled with it.
		$appt_repo->shouldReceive( 'findById' )->with( 5 )->andReturn(
			array( 'status' => 'confirmed', 'calendar_id' => 1, 'appointment_date' => '2026-01-01', 'start_time' => '09:00:00' )
		);
		$appt_repo->shouldReceive( 'cancel' )->with( 5, 1, 'Admin closed slot' )->andReturn( true );
		Mockery::mock( 'overload:FreeFormCertificate\Repositories\CalendarRepository' );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'redirected' );
		$this->include_view();
	}

	// ==================================================================
	// Detail view
	// ==================================================================

	public function test_view_renders_appointment_detail(): void {
		$_GET['appointment'] = '5';
		Functions\when( 'get_user_by' )->justReturn( false );

		Mockery::mock( 'alias:FreeFormCertificate\Core\Capabilities' )
			->shouldReceive( 'current_user_can_admin_or' )->andReturn( true );
		Mockery::mock( 'alias:FreeFormCertificate\Core\RequestInput' )
			->shouldReceive( 'get_get_string' )->andReturn( '' )
			->shouldReceive( 'get_get_key' )->andReturnUsing( static fn( $key, $default = '' ) => isset( $_GET[ $key ] ) ? (string) $_GET[ $key ] : $default )
			->shouldReceive( 'get_get_int' )->andReturnUsing( static fn( $key, $default = 0 ) => isset( $_GET[ $key ] ) ? (int) $_GET[ $key ] : $default )
			->shouldReceive( 'has_get' )->andReturnUsing( static fn( $key ) => isset( $_GET[ $key ] ) );

		// #739 §3.3 — the detail view asks whether the viewer may reveal PII.
		Mockery::mock( 'alias:FreeFormCertificate\Core\PiiAccessPolicy' )
			->shouldReceive( 'can_reveal' )->andReturn( true );

		$appt = array(
			'id'               => 5,
			'status'           => 'confirmed',
			'calendar_id'      => 7,
			'appointment_date' => '2026-05-20',
			'start_time'       => '09:00:00',
			'end_time'         => '10:00:00',
			'name'             => 'Alice',
			'created_at'       => '2026-05-01',
			'custom_data'      => '{"field_a":"v","field_b":["x","y"]}',
		);
		$appt_repo = Mockery::mock( 'overload:FreeFormCertificate\Repositories\AppointmentRepository' );
		$appt_repo->shouldReceive( 'findById' )->with( 5 )->andReturn( $appt );

		$cal_repo = Mockery::mock( 'overload:FreeFormCertificate\Repositories\CalendarRepository' );
		$cal_repo->shouldReceive( 'findById' )->with( 7 )->andReturn( array( 'id' => 7, 'title' => 'Clinic' ) );

		Mockery::mock( 'alias:FreeFormCertificate\Core\Encryption' )
			->shouldReceive( 'decrypt_appointment' )->andReturnUsing( fn( $a ) => $a );

		ob_start();
		$this->include_view();
		$out = ob_get_clean();

		$this->assertStringContainsString( 'Appointment Details', $out );
		$this->assertStringContainsString( 'Clinic', $out );
		$this->assertStringContainsString( 'Alice', $out );
	}

	/**
	 * Render the detail view of appointment 5 under the REAL PII policy and
	 * the REAL mask helpers, with the viewer's tier chosen through `user_can`.
	 *
	 * @param string               $tier unmasked | reveal | masked.
	 * @param array<string, mixed> $pii  The decrypted email / cpf / rf.
	 * @return string The rendered HTML.
	 */
	private function render_detail_as( string $tier, array $pii ): string {
		$_GET['appointment'] = '5';
		Functions\when( 'get_user_by' )->justReturn( false );
		Functions\when( 'is_email' )->alias( static fn( $e ) => false !== filter_var( $e, FILTER_VALIDATE_EMAIL ) );
		// manage_options is the unmasked tier; the domain PII cap alone is
		// the reveal tier; neither is the masked tier (PiiAccessPolicy::resolve).
		Functions\when( 'user_can' )->alias(
			static function ( $uid, $cap ) use ( $tier ) {
				if ( 'manage_options' === $cap ) {
					return 'unmasked' === $tier;
				}
				return 'ffc_view_appointments_pii' === $cap && 'reveal' === $tier;
			}
		);

		Mockery::mock( 'alias:FreeFormCertificate\Core\Capabilities' )
			->shouldReceive( 'current_user_can_admin_or' )->andReturn( true );
		Mockery::mock( 'alias:FreeFormCertificate\Core\RequestInput' )
			->shouldReceive( 'get_get_string' )->andReturn( '' )
			->shouldReceive( 'get_get_key' )->andReturnUsing( static fn( $key, $default = '' ) => isset( $_GET[ $key ] ) ? (string) $_GET[ $key ] : $default )
			->shouldReceive( 'get_get_int' )->andReturnUsing( static fn( $key, $default = 0 ) => isset( $_GET[ $key ] ) ? (int) $_GET[ $key ] : $default )
			->shouldReceive( 'has_get' )->andReturnUsing( static fn( $key ) => isset( $_GET[ $key ] ) );

		$appt = array_merge(
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
		$appt_repo = Mockery::mock( 'overload:FreeFormCertificate\Repositories\AppointmentRepository' );
		$appt_repo->shouldReceive( 'findById' )->with( 5 )->andReturn( $appt );

		$cal_repo = Mockery::mock( 'overload:FreeFormCertificate\Repositories\CalendarRepository' );
		$cal_repo->shouldReceive( 'findById' )->with( 7 )->andReturn( array( 'id' => 7, 'title' => 'Clinic' ) );

		Mockery::mock( 'alias:FreeFormCertificate\Core\Encryption' )
			->shouldReceive( 'decrypt_appointment' )->andReturnUsing( fn( $a ) => $a );

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
		$this->assertStringContainsString( '<span class="ffc-pii-value" data-field="rf">518.***.0</span>', $out );

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

		Mockery::mock( 'alias:FreeFormCertificate\Core\Capabilities' )
			->shouldReceive( 'current_user_can_admin_or' )->andReturn( true );
		Mockery::mock( 'alias:FreeFormCertificate\Core\RequestInput' )
			->shouldReceive( 'get_get_string' )->andReturn( '' )
			->shouldReceive( 'get_get_key' )->andReturnUsing( static fn( $key, $default = '' ) => isset( $_GET[ $key ] ) ? (string) $_GET[ $key ] : $default )
			->shouldReceive( 'get_get_int' )->andReturnUsing( static fn( $key, $default = 0 ) => isset( $_GET[ $key ] ) ? (int) $_GET[ $key ] : $default )
			->shouldReceive( 'has_get' )->andReturnUsing( static fn( $key ) => isset( $_GET[ $key ] ) );

		$appt_repo = Mockery::mock( 'overload:FreeFormCertificate\Repositories\AppointmentRepository' );
		$appt_repo->shouldReceive( 'findById' )->with( 5 )->andReturn( null );
		Mockery::mock( 'overload:FreeFormCertificate\Repositories\CalendarRepository' );

		ob_start();
		$this->include_view();
		$out = ob_get_clean();

		$this->assertStringContainsString( 'Appointment not found.', $out );
	}

	// ==================================================================
	// List view (default, no specific appointment)
	// ==================================================================

	public function test_view_renders_list_table_by_default(): void {
		Mockery::mock( 'alias:FreeFormCertificate\Core\Capabilities' )
			->shouldReceive( 'current_user_can_admin_or' )->andReturn( true );
		Mockery::mock( 'alias:FreeFormCertificate\Core\RequestInput' )
			->shouldReceive( 'get_get_string' )->andReturn( '' )
			->shouldReceive( 'get_get_key' )->andReturnUsing( static fn( $key, $default = '' ) => isset( $_GET[ $key ] ) ? (string) $_GET[ $key ] : $default )
			->shouldReceive( 'get_get_int' )->andReturnUsing( static fn( $key, $default = 0 ) => isset( $_GET[ $key ] ) ? (int) $_GET[ $key ] : $default )
			->shouldReceive( 'has_get' )->andReturnUsing( static fn( $key ) => isset( $_GET[ $key ] ) );

		$appt_repo = Mockery::mock( 'overload:FreeFormCertificate\Repositories\AppointmentRepository' );
		$appt_repo->shouldReceive( 'findAll' )->andReturn( array() );
		$appt_repo->shouldReceive( 'count' )->andReturn( 0 );

		$cal_repo = Mockery::mock( 'overload:FreeFormCertificate\Repositories\CalendarRepository' );
		$cal_repo->shouldReceive( 'getActiveCalendars' )->andReturn( array() );

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

	public function test_the_list_offers_no_page_reveal_without_the_pii_cap(): void {
		Mockery::mock( 'alias:FreeFormCertificate\Core\Capabilities' )
			->shouldReceive( 'current_user_can_admin_or' )->andReturn( true );
		Mockery::mock( 'alias:FreeFormCertificate\Core\RequestInput' )
			->shouldReceive( 'get_get_string' )->andReturn( '' )
			->shouldReceive( 'get_get_key' )->andReturnUsing( static fn( $key, $default = '' ) => isset( $_GET[ $key ] ) ? (string) $_GET[ $key ] : $default )
			->shouldReceive( 'get_get_int' )->andReturnUsing( static fn( $key, $default = 0 ) => isset( $_GET[ $key ] ) ? (int) $_GET[ $key ] : $default )
			->shouldReceive( 'has_get' )->andReturnUsing( static fn( $key ) => isset( $_GET[ $key ] ) );
		$appt_repo = Mockery::mock( 'overload:FreeFormCertificate\Repositories\AppointmentRepository' );
		$appt_repo->shouldReceive( 'findAll' )->andReturn( array() );
		$appt_repo->shouldReceive( 'count' )->andReturn( 0 );
		Mockery::mock( 'overload:FreeFormCertificate\Repositories\CalendarRepository' )
			->shouldReceive( 'getActiveCalendars' )->andReturn( array() );
		Functions\when( 'user_can' )->justReturn( false );

		ob_start();
		$this->include_view();
		$out = ob_get_clean();

		$this->assertStringNotContainsString( 'ffc-reveal-pii-page', $out );
	}
}
