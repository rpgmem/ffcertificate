<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Audience\AudienceAjaxController;

/**
 * Tests for AudienceAjaxController — the admin-ajax endpoints extracted from
 * AudienceLoader (frontend-audit Item 3). Covers hook registration, a couple
 * of representative handler behaviours through the AjaxTrait + wp_send_json
 * path, and the custom-fields helpers (moved verbatim from the loader, where
 * they were the only previously-tested part of this surface).
 *
 * @covers \FreeFormCertificate\Audience\AudienceAjaxController
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class AudienceAjaxControllerTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var array<int, array{ok: bool, data: mixed}> */
	private array $responses = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( '__' )->returnArg();
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'absint' )->alias( static fn( $v ) => abs( (int) $v ) );
		Functions\when( 'do_action' )->justReturn( null );
		Functions\when( 'sanitize_textarea_field' )->returnArg();

		$this->responses = array();
		Functions\when( 'wp_send_json_success' )->alias( function ( $data = null ) {
			$this->responses[] = array( 'ok' => true, 'data' => $data );
			throw new \RuntimeException( 'wp_send_json_success' );
		} );
		Functions\when( 'wp_send_json_error' )->alias( function ( $data = null ) {
			$this->responses[] = array( 'ok' => false, 'data' => $data );
			throw new \RuntimeException( 'wp_send_json_error' );
		} );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		$_POST = array();
		parent::tearDown();
	}

	/** Stub the Utils statics the AjaxTrait helpers delegate to. */
	private function mockUtils(): void {
		$utils = Mockery::mock( 'alias:FreeFormCertificate\Core\Utils' );
		$utils->shouldReceive( 'current_user_can_manage' )->andReturn( true )->byDefault();
		$utils->shouldReceive( 'get_post_string' )->andReturnUsing(
			static fn( $k, $d = '' ) => isset( $_POST[ $k ] ) ? (string) $_POST[ $k ] : $d
		)->byDefault();
		$utils->shouldReceive( 'get_post_int' )->andReturnUsing(
			static fn( $k, $d = 0 ) => isset( $_POST[ $k ] ) ? (int) $_POST[ $k ] : $d
		)->byDefault();
		$utils->shouldReceive( 'get_post_array' )->andReturnUsing(
			static fn( $k ) => isset( $_POST[ $k ] ) ? (array) $_POST[ $k ] : array()
		)->byDefault();
	}

	/** Run a handler, swallowing the wp_send_json short-circuit exception. */
	private function dispatch( callable $fn ): void {
		try {
			$fn();
		} catch ( \RuntimeException $e ) {
			// wp_send_json_* throws in the stub to mimic wp_die().
		}
	}

	public function test_register_adds_every_ajax_hook(): void {
		$hooks = array(
			'wp_ajax_ffc_audience_check_conflicts',
			'wp_ajax_ffc_audience_create_booking',
			'wp_ajax_ffc_audience_cancel_booking',
			'wp_ajax_ffc_audience_get_booking',
			'wp_ajax_ffc_audience_get_schedule_slots',
			'wp_ajax_ffc_search_users',
			'wp_ajax_ffc_audience_get_environments',
			'wp_ajax_ffc_audience_add_user_permission',
			'wp_ajax_ffc_audience_update_user_permission',
			'wp_ajax_ffc_audience_remove_user_permission',
			'wp_ajax_ffc_save_custom_fields',
			'wp_ajax_ffc_delete_custom_field',
			'wp_ajax_ffc_replicate_field_options',
		);
		foreach ( $hooks as $h ) {
			Actions\expectAdded( $h );
		}

		( new AudienceAjaxController() )->register();
	}

	public function test_create_booking_reports_not_implemented(): void {
		$this->mockUtils();
		$_POST = array( 'nonce' => 'n' );

		$this->dispatch( fn() => ( new AudienceAjaxController() )->ajax_create_booking() );

		$this->assertFalse( $this->responses[0]['ok'] );
		$this->assertStringContainsString( 'Not implemented', $this->responses[0]['data']['message'] );
	}

	public function test_check_conflicts_rejects_missing_params(): void {
		$this->mockUtils();
		$_POST = array( 'nonce' => 'n' ); // no environment_id / date / times.

		$this->dispatch( fn() => ( new AudienceAjaxController() )->ajax_check_conflicts() );

		$this->assertFalse( $this->responses[0]['ok'] );
		$this->assertStringContainsString( 'Missing required parameters', $this->responses[0]['data']['message'] );
	}

	public function test_check_conflicts_returns_conflicts_from_service(): void {
		$this->mockUtils();
		$_POST = array(
			'nonce'          => 'n',
			'environment_id' => '5',
			'booking_date'   => '2026-05-20',
			'start_time'     => '09:00',
			'end_time'       => '10:00',
			'audience_ids'   => array( '2', '3' ),
			'user_ids'       => array( '7' ),
		);

		$service = Mockery::mock( 'overload:FreeFormCertificate\Audience\AudienceConflictService' );
		$service->shouldReceive( 'check_conflicts' )
			->once()
			->with( 5, '2026-05-20', '09:00', '10:00', array( 2, 3 ), array( 7 ) )
			->andReturn( array( 'overlap' ) );

		$this->dispatch( fn() => ( new AudienceAjaxController() )->ajax_check_conflicts() );

		$this->assertTrue( $this->responses[0]['ok'] );
		$this->assertSame( array( 'overlap' ), $this->responses[0]['data']['conflicts'] );
	}

	public function test_check_conflicts_handles_exception(): void {
		$this->mockUtils();
		Mockery::mock( 'alias:FreeFormCertificate\Core\Debug' )->shouldIgnoreMissing();
		$_POST = array(
			'nonce'          => 'n',
			'environment_id' => '5',
			'booking_date'   => '2026-05-20',
			'start_time'     => '09:00',
			'end_time'       => '10:00',
		);

		$service = Mockery::mock( 'overload:FreeFormCertificate\Audience\AudienceConflictService' );
		$service->shouldReceive( 'check_conflicts' )->andThrow( new \RuntimeException( 'boom' ) );

		$this->dispatch( fn() => ( new AudienceAjaxController() )->ajax_check_conflicts() );

		$this->assertFalse( $this->responses[0]['ok'] );
		$this->assertSame( 'ffc_internal_error', $this->responses[0]['data']['code'] );
	}

	// ── cancel_booking ─────────────────────────────────────────────────

	public function test_cancel_booking_rejects_invalid_id(): void {
		$this->mockUtils();
		$_POST = array( 'nonce' => 'n' );

		$this->dispatch( fn() => ( new AudienceAjaxController() )->ajax_cancel_booking() );

		$this->assertFalse( $this->responses[0]['ok'] );
		$this->assertStringContainsString( 'Invalid booking ID', $this->responses[0]['data']['message'] );
	}

	public function test_cancel_booking_reports_not_found(): void {
		$this->mockUtils();
		Functions\when( 'sanitize_textarea_field' )->returnArg();
		$_POST = array( 'nonce' => 'n', 'booking_id' => '9' );

		$repo = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceBookingRepository' );
		$repo->shouldReceive( 'get_by_id' )->with( 9 )->andReturn( null );

		$this->dispatch( fn() => ( new AudienceAjaxController() )->ajax_cancel_booking() );

		$this->assertFalse( $this->responses[0]['ok'] );
		$this->assertStringContainsString( 'Booking not found', $this->responses[0]['data']['message'] );
	}

	public function test_cancel_booking_rejects_already_cancelled(): void {
		$this->mockUtils();
		Functions\when( 'sanitize_textarea_field' )->returnArg();
		$_POST = array( 'nonce' => 'n', 'booking_id' => '9' );

		$repo = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceBookingRepository' );
		$repo->shouldReceive( 'get_by_id' )->with( 9 )->andReturn( (object) array( 'status' => 'cancelled' ) );

		$this->dispatch( fn() => ( new AudienceAjaxController() )->ajax_cancel_booking() );

		$this->assertFalse( $this->responses[0]['ok'] );
		$this->assertStringContainsString( 'already cancelled', $this->responses[0]['data']['message'] );
	}

	public function test_cancel_booking_reports_failure(): void {
		$this->mockUtils();
		Functions\when( 'sanitize_textarea_field' )->returnArg();
		$_POST = array( 'nonce' => 'n', 'booking_id' => '9', 'reason' => 'x' );

		$repo = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceBookingRepository' );
		$repo->shouldReceive( 'get_by_id' )->with( 9 )->andReturn( (object) array( 'status' => 'active' ) );
		$repo->shouldReceive( 'cancel' )->with( 9, 'x' )->andReturn( false );

		$this->dispatch( fn() => ( new AudienceAjaxController() )->ajax_cancel_booking() );

		$this->assertFalse( $this->responses[0]['ok'] );
		$this->assertStringContainsString( 'Failed to cancel', $this->responses[0]['data']['message'] );
	}

	public function test_cancel_booking_succeeds(): void {
		$this->mockUtils();
		Functions\when( 'sanitize_textarea_field' )->returnArg();
		Functions\when( 'do_action' )->justReturn( null );
		$_POST = array( 'nonce' => 'n', 'booking_id' => '9', 'reason' => 'x' );

		$repo = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceBookingRepository' );
		$repo->shouldReceive( 'get_by_id' )->with( 9 )->andReturn( (object) array( 'status' => 'active' ) );
		$repo->shouldReceive( 'cancel' )->with( 9, 'x' )->andReturn( true );

		$this->dispatch( fn() => ( new AudienceAjaxController() )->ajax_cancel_booking() );

		$this->assertTrue( $this->responses[0]['ok'] );
		$this->assertStringContainsString( 'cancelled successfully', $this->responses[0]['data']['message'] );
	}

	// ── get_booking ────────────────────────────────────────────────────

	public function test_get_booking_rejects_invalid_id(): void {
		$this->mockUtils();
		$_POST = array( 'nonce' => 'n' );

		$this->dispatch( fn() => ( new AudienceAjaxController() )->ajax_get_booking() );

		$this->assertFalse( $this->responses[0]['ok'] );
		$this->assertStringContainsString( 'Invalid booking ID', $this->responses[0]['data']['message'] );
	}

	public function test_get_booking_reports_not_found(): void {
		$this->mockUtils();
		$_POST = array( 'nonce' => 'n', 'booking_id' => '4' );

		$repo = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceBookingRepository' );
		$repo->shouldReceive( 'get_by_id' )->with( 4 )->andReturn( null );

		$this->dispatch( fn() => ( new AudienceAjaxController() )->ajax_get_booking() );

		$this->assertFalse( $this->responses[0]['ok'] );
		$this->assertStringContainsString( 'Booking not found', $this->responses[0]['data']['message'] );
	}

	public function test_get_booking_maps_full_payload(): void {
		$this->mockUtils();
		$_POST = array( 'nonce' => 'n', 'booking_id' => '4' );

		$booking = (object) array(
			'id'               => 4,
			'created_by'       => 11,
			'booking_date'     => '2026-05-20',
			'start_time'       => '09:00',
			'end_time'         => '10:00',
			'is_all_day'       => 1,
			'environment_name' => 'Room A',
			'description'      => 'desc',
			'booking_type'     => 'meeting',
			'status'           => 'active',
			'created_at'       => '2026-05-01',
			'audiences'        => array(
				(object) array( 'audience_id' => 2, 'name' => 'Aud2' ),
			),
			'users'            => array( 7 ),
		);

		$repo = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceBookingRepository' );
		$repo->shouldReceive( 'get_by_id' )->with( 4 )->andReturn( $booking );

		Functions\when( 'get_userdata' )->alias(
			static function ( $id ) {
				if ( 11 === $id ) {
					return (object) array( 'ID' => 11, 'display_name' => 'Creator', 'user_email' => 'c@e.com' );
				}
				return (object) array( 'ID' => 7, 'display_name' => 'Member', 'user_email' => 'm@e.com' );
			}
		);

		$this->dispatch( fn() => ( new AudienceAjaxController() )->ajax_get_booking() );

		$this->assertTrue( $this->responses[0]['ok'] );
		$data = $this->responses[0]['data'];
		$this->assertSame( 4, $data['id'] );
		$this->assertSame( 'Creator', $data['created_by'] );
		$this->assertSame( 1, $data['is_all_day'] );
		$this->assertSame( array( array( 'id' => 2, 'name' => 'Aud2' ) ), $data['audiences'] );
		$this->assertSame( 7, $data['users'][0]['id'] );
		$this->assertSame( 'm@e.com', $data['users'][0]['email'] );
	}

	public function test_get_booking_unknown_creator_and_empty_relations(): void {
		$this->mockUtils();
		$_POST = array( 'nonce' => 'n', 'booking_id' => '4' );

		$booking = (object) array(
			'id'               => 4,
			'created_by'       => 0,
			'booking_date'     => '2026-05-20',
			'start_time'       => '09:00',
			'end_time'         => '10:00',
			'environment_name' => 'Room A',
			'description'      => '',
			'booking_type'     => 'meeting',
			'status'           => 'active',
			'created_at'       => '2026-05-01',
			'audiences'        => array(),
			'users'            => array(),
		);

		$repo = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceBookingRepository' );
		$repo->shouldReceive( 'get_by_id' )->with( 4 )->andReturn( $booking );

		Functions\when( 'get_userdata' )->justReturn( false );

		$this->dispatch( fn() => ( new AudienceAjaxController() )->ajax_get_booking() );

		$this->assertTrue( $this->responses[0]['ok'] );
		$data = $this->responses[0]['data'];
		$this->assertSame( 'Unknown', $data['created_by'] );
		$this->assertSame( 0, $data['is_all_day'] );
		$this->assertSame( array(), $data['audiences'] );
		$this->assertSame( array(), $data['users'] );
	}

	// ── get_schedule_slots ─────────────────────────────────────────────

	public function test_get_schedule_slots_reports_not_implemented(): void {
		$this->mockUtils();
		$_POST = array( 'nonce' => 'n' );

		$this->dispatch( fn() => ( new AudienceAjaxController() )->ajax_get_schedule_slots() );

		$this->assertFalse( $this->responses[0]['ok'] );
		$this->assertStringContainsString( 'Not implemented', $this->responses[0]['data']['message'] );
	}

	// ── search_users ───────────────────────────────────────────────────

	public function test_search_users_short_query_returns_empty(): void {
		$this->mockUtils();
		$_POST = array( 'nonce' => 'n', 'query' => 'a' );

		$this->dispatch( fn() => ( new AudienceAjaxController() )->ajax_search_users() );

		$this->assertTrue( $this->responses[0]['ok'] );
		$this->assertSame( array(), $this->responses[0]['data'] );
	}

	public function test_search_users_maps_results(): void {
		$this->mockUtils();
		$_POST = array( 'nonce' => 'n', 'query' => 'john' );

		Functions\when( 'get_users' )->justReturn(
			array(
				(object) array( 'ID' => 3, 'display_name' => 'John', 'user_email' => 'j@e.com' ),
			)
		);

		$this->dispatch( fn() => ( new AudienceAjaxController() )->ajax_search_users() );

		$this->assertTrue( $this->responses[0]['ok'] );
		$this->assertSame(
			array( array( 'id' => 3, 'name' => 'John', 'email' => 'j@e.com' ) ),
			$this->responses[0]['data']
		);
	}

	// ── get_environments ───────────────────────────────────────────────

	public function test_get_environments_empty_for_invalid_schedule(): void {
		$this->mockUtils();
		$_POST = array( 'nonce' => 'n', 'schedule_id' => '0' );

		$this->dispatch( fn() => ( new AudienceAjaxController() )->ajax_get_environments() );

		$this->assertTrue( $this->responses[0]['ok'] );
		$this->assertSame( array(), $this->responses[0]['data'] );
	}

	public function test_get_environments_maps_results(): void {
		$this->mockUtils();
		$_POST = array( 'nonce' => 'n', 'schedule_id' => '5' );

		$repo = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceEnvironmentRepository' );
		$repo->shouldReceive( 'get_by_schedule' )->with( 5 )->andReturn(
			array( (object) array( 'id' => 8, 'name' => 'Room' ) )
		);

		$this->dispatch( fn() => ( new AudienceAjaxController() )->ajax_get_environments() );

		$this->assertTrue( $this->responses[0]['ok'] );
		$this->assertSame( array( array( 'id' => 8, 'name' => 'Room' ) ), $this->responses[0]['data'] );
	}

	// ── add_user_permission ────────────────────────────────────────────

	public function test_add_user_permission_rejects_missing_params(): void {
		$this->mockUtils();
		$_POST = array( '_wpnonce' => 'n' );

		$this->dispatch( fn() => ( new AudienceAjaxController() )->ajax_add_user_permission() );

		$this->assertFalse( $this->responses[0]['ok'] );
		$this->assertStringContainsString( 'Missing required parameters', $this->responses[0]['data']['message'] );
	}

	public function test_add_user_permission_reports_calendar_not_found(): void {
		$this->mockUtils();
		$_POST = array( '_wpnonce' => 'n', 'schedule_id' => '5', 'user_id' => '7' );

		$repo = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceScheduleRepository' );
		$repo->shouldReceive( 'get_by_id' )->with( 5 )->andReturn( null );

		$this->dispatch( fn() => ( new AudienceAjaxController() )->ajax_add_user_permission() );

		$this->assertFalse( $this->responses[0]['ok'] );
		$this->assertStringContainsString( 'Calendar not found', $this->responses[0]['data']['message'] );
	}

	public function test_add_user_permission_reports_user_not_found(): void {
		$this->mockUtils();
		$_POST = array( '_wpnonce' => 'n', 'schedule_id' => '5', 'user_id' => '7' );

		$repo = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceScheduleRepository' );
		$repo->shouldReceive( 'get_by_id' )->with( 5 )->andReturn( (object) array( 'id' => 5 ) );
		Functions\when( 'get_userdata' )->justReturn( false );

		$this->dispatch( fn() => ( new AudienceAjaxController() )->ajax_add_user_permission() );

		$this->assertFalse( $this->responses[0]['ok'] );
		$this->assertStringContainsString( 'User not found', $this->responses[0]['data']['message'] );
	}

	public function test_add_user_permission_rejects_existing_access(): void {
		$this->mockUtils();
		$_POST = array( '_wpnonce' => 'n', 'schedule_id' => '5', 'user_id' => '7' );

		$repo = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceScheduleRepository' );
		$repo->shouldReceive( 'get_by_id' )->with( 5 )->andReturn( (object) array( 'id' => 5 ) );
		$repo->shouldReceive( 'get_user_permissions' )->with( 5, 7 )->andReturn( (object) array( 'can_book' => 1 ) );
		Functions\when( 'get_userdata' )->justReturn( (object) array( 'ID' => 7, 'display_name' => 'U', 'user_email' => 'u@e.com' ) );

		$this->dispatch( fn() => ( new AudienceAjaxController() )->ajax_add_user_permission() );

		$this->assertFalse( $this->responses[0]['ok'] );
		$this->assertStringContainsString( 'already has access', $this->responses[0]['data']['message'] );
	}

	public function test_add_user_permission_reports_set_failure(): void {
		$this->mockUtils();
		$_POST = array( '_wpnonce' => 'n', 'schedule_id' => '5', 'user_id' => '7' );

		$repo = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceScheduleRepository' );
		$repo->shouldReceive( 'get_by_id' )->with( 5 )->andReturn( (object) array( 'id' => 5 ) );
		$repo->shouldReceive( 'get_user_permissions' )->with( 5, 7 )->andReturn( null );
		$repo->shouldReceive( 'set_user_permissions' )->andReturn( false );
		Functions\when( 'get_userdata' )->justReturn( (object) array( 'ID' => 7, 'display_name' => 'U', 'user_email' => 'u@e.com' ) );

		$this->dispatch( fn() => ( new AudienceAjaxController() )->ajax_add_user_permission() );

		$this->assertFalse( $this->responses[0]['ok'] );
		$this->assertStringContainsString( 'Error adding user permissions', $this->responses[0]['data']['message'] );
	}

	public function test_add_user_permission_succeeds_with_html(): void {
		$this->mockUtils();
		$_POST = array( '_wpnonce' => 'n', 'schedule_id' => '5', 'user_id' => '7' );

		$repo = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceScheduleRepository' );
		$repo->shouldReceive( 'get_by_id' )->with( 5 )->andReturn( (object) array( 'id' => 5 ) );
		$repo->shouldReceive( 'get_user_permissions' )->with( 5, 7 )->andReturn( null );
		$repo->shouldReceive( 'set_user_permissions' )->andReturn( true );
		Functions\when( 'get_userdata' )->justReturn( (object) array( 'ID' => 7, 'display_name' => 'Jane', 'user_email' => 'jane@e.com' ) );
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_html_e' )->alias( static function ( $t ) { echo $t; } );

		$this->dispatch( fn() => ( new AudienceAjaxController() )->ajax_add_user_permission() );

		$this->assertTrue( $this->responses[0]['ok'] );
		$this->assertStringContainsString( 'Jane', $this->responses[0]['data']['html'] );
		$this->assertStringContainsString( 'jane@e.com', $this->responses[0]['data']['html'] );
	}

	// ── update_user_permission ─────────────────────────────────────────

	public function test_update_user_permission_rejects_missing_params(): void {
		$this->mockUtils();
		$_POST = array( '_wpnonce' => 'n', 'schedule_id' => '5' );

		$this->dispatch( fn() => ( new AudienceAjaxController() )->ajax_update_user_permission() );

		$this->assertFalse( $this->responses[0]['ok'] );
		$this->assertStringContainsString( 'Missing required parameters', $this->responses[0]['data']['message'] );
	}

	public function test_update_user_permission_rejects_invalid_permission(): void {
		$this->mockUtils();
		$_POST = array( '_wpnonce' => 'n', 'schedule_id' => '5', 'user_id' => '7', 'permission' => 'bad' );

		$this->dispatch( fn() => ( new AudienceAjaxController() )->ajax_update_user_permission() );

		$this->assertFalse( $this->responses[0]['ok'] );
		$this->assertStringContainsString( 'Invalid permission', $this->responses[0]['data']['message'] );
	}

	public function test_update_user_permission_reports_no_access(): void {
		$this->mockUtils();
		$_POST = array( '_wpnonce' => 'n', 'schedule_id' => '5', 'user_id' => '7', 'permission' => 'can_book' );

		$repo = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceScheduleRepository' );
		$repo->shouldReceive( 'get_user_permissions' )->with( 5, 7 )->andReturn( null );

		$this->dispatch( fn() => ( new AudienceAjaxController() )->ajax_update_user_permission() );

		$this->assertFalse( $this->responses[0]['ok'] );
		$this->assertStringContainsString( 'does not have access', $this->responses[0]['data']['message'] );
	}

	public function test_update_user_permission_succeeds(): void {
		$this->mockUtils();
		$_POST = array(
			'_wpnonce'    => 'n',
			'schedule_id' => '5',
			'user_id'     => '7',
			'permission'  => 'can_cancel_others',
			'value'       => '1',
		);

		$repo = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceScheduleRepository' );
		$repo->shouldReceive( 'get_user_permissions' )->with( 5, 7 )->andReturn(
			(object) array( 'can_book' => 1, 'can_cancel_others' => 0, 'can_override_conflicts' => 0 )
		);
		$repo->shouldReceive( 'set_user_permissions' )
			->with( 5, 7, array( 'can_book' => 1, 'can_cancel_others' => 1, 'can_override_conflicts' => 0 ) )
			->andReturn( true );

		$this->dispatch( fn() => ( new AudienceAjaxController() )->ajax_update_user_permission() );

		$this->assertTrue( $this->responses[0]['ok'] );
	}

	public function test_update_user_permission_reports_set_failure(): void {
		$this->mockUtils();
		$_POST = array(
			'_wpnonce'    => 'n',
			'schedule_id' => '5',
			'user_id'     => '7',
			'permission'  => 'can_book',
			'value'       => '0',
		);

		$repo = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceScheduleRepository' );
		$repo->shouldReceive( 'get_user_permissions' )->with( 5, 7 )->andReturn(
			(object) array( 'can_book' => 1, 'can_cancel_others' => 0, 'can_override_conflicts' => 0 )
		);
		$repo->shouldReceive( 'set_user_permissions' )->andReturn( false );

		$this->dispatch( fn() => ( new AudienceAjaxController() )->ajax_update_user_permission() );

		$this->assertFalse( $this->responses[0]['ok'] );
		$this->assertStringContainsString( 'Error updating permission', $this->responses[0]['data']['message'] );
	}

	// ── remove_user_permission ─────────────────────────────────────────

	public function test_remove_user_permission_rejects_missing_params(): void {
		$this->mockUtils();
		$_POST = array( '_wpnonce' => 'n' );

		$this->dispatch( fn() => ( new AudienceAjaxController() )->ajax_remove_user_permission() );

		$this->assertFalse( $this->responses[0]['ok'] );
		$this->assertStringContainsString( 'Missing required parameters', $this->responses[0]['data']['message'] );
	}

	public function test_remove_user_permission_reports_failure(): void {
		$this->mockUtils();
		$_POST = array( '_wpnonce' => 'n', 'schedule_id' => '5', 'user_id' => '7' );

		$repo = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceScheduleRepository' );
		$repo->shouldReceive( 'remove_user_permissions' )->with( 5, 7 )->andReturn( false );

		$this->dispatch( fn() => ( new AudienceAjaxController() )->ajax_remove_user_permission() );

		$this->assertFalse( $this->responses[0]['ok'] );
		$this->assertStringContainsString( 'Error removing user access', $this->responses[0]['data']['message'] );
	}

	public function test_remove_user_permission_succeeds(): void {
		$this->mockUtils();
		$_POST = array( '_wpnonce' => 'n', 'schedule_id' => '5', 'user_id' => '7' );

		$repo = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceScheduleRepository' );
		$repo->shouldReceive( 'remove_user_permissions' )->with( 5, 7 )->andReturn( true );

		$this->dispatch( fn() => ( new AudienceAjaxController() )->ajax_remove_user_permission() );

		$this->assertTrue( $this->responses[0]['ok'] );
	}

	// ── delete_custom_field ────────────────────────────────────────────

	public function test_delete_custom_field_rejects_invalid_id(): void {
		$this->mockUtils();
		Functions\when( 'current_user_can' )->justReturn( true );
		$_POST = array( 'nonce' => 'n' );

		$this->dispatch( fn() => ( new AudienceAjaxController() )->ajax_delete_custom_field() );

		$this->assertFalse( $this->responses[0]['ok'] );
		$this->assertStringContainsString( 'Invalid field ID', $this->responses[0]['data']['message'] );
	}

	public function test_delete_custom_field_reports_not_found(): void {
		$this->mockUtils();
		Functions\when( 'current_user_can' )->justReturn( true );
		$_POST = array( 'nonce' => 'n', 'field_id' => '3' );

		$repo = Mockery::mock( 'alias:FreeFormCertificate\Reregistration\CustomFieldRepository' );
		$repo->shouldReceive( 'get_by_id' )->with( 3 )->andReturn( null );

		$this->dispatch( fn() => ( new AudienceAjaxController() )->ajax_delete_custom_field() );

		$this->assertFalse( $this->responses[0]['ok'] );
		$this->assertStringContainsString( 'Field not found', $this->responses[0]['data']['message'] );
	}

	public function test_delete_custom_field_blocks_standard(): void {
		$this->mockUtils();
		Functions\when( 'current_user_can' )->justReturn( true );
		$_POST = array( 'nonce' => 'n', 'field_id' => '3' );

		$repo = Mockery::mock( 'alias:FreeFormCertificate\Reregistration\CustomFieldRepository' );
		$repo->shouldReceive( 'get_by_id' )->with( 3 )->andReturn( (object) array( 'field_source' => 'standard' ) );

		$this->dispatch( fn() => ( new AudienceAjaxController() )->ajax_delete_custom_field() );

		$this->assertFalse( $this->responses[0]['ok'] );
		$this->assertStringContainsString( 'cannot be deleted', $this->responses[0]['data']['message'] );
	}

	public function test_delete_custom_field_reports_failure(): void {
		$this->mockUtils();
		Functions\when( 'current_user_can' )->justReturn( true );
		$_POST = array( 'nonce' => 'n', 'field_id' => '3' );

		$repo = Mockery::mock( 'alias:FreeFormCertificate\Reregistration\CustomFieldRepository' );
		$repo->shouldReceive( 'get_by_id' )->with( 3 )->andReturn( (object) array( 'field_source' => 'custom' ) );
		$repo->shouldReceive( 'delete' )->with( 3 )->andReturn( false );

		$this->dispatch( fn() => ( new AudienceAjaxController() )->ajax_delete_custom_field() );

		$this->assertFalse( $this->responses[0]['ok'] );
		$this->assertStringContainsString( 'Failed to delete', $this->responses[0]['data']['message'] );
	}

	public function test_delete_custom_field_succeeds(): void {
		$this->mockUtils();
		Functions\when( 'current_user_can' )->justReturn( true );
		$_POST = array( 'nonce' => 'n', 'field_id' => '3' );

		$repo = Mockery::mock( 'alias:FreeFormCertificate\Reregistration\CustomFieldRepository' );
		$repo->shouldReceive( 'get_by_id' )->with( 3 )->andReturn( (object) array( 'field_source' => 'custom' ) );
		$repo->shouldReceive( 'delete' )->with( 3 )->andReturn( true );

		$this->dispatch( fn() => ( new AudienceAjaxController() )->ajax_delete_custom_field() );

		$this->assertTrue( $this->responses[0]['ok'] );
		$this->assertStringContainsString( 'deleted successfully', $this->responses[0]['data']['message'] );
	}

	// ── replicate_field_options ────────────────────────────────────────

	public function test_replicate_field_options_rejects_invalid_id(): void {
		$this->mockUtils();
		Functions\when( 'current_user_can' )->justReturn( true );
		$_POST = array( 'nonce' => 'n' );

		$this->dispatch( fn() => ( new AudienceAjaxController() )->ajax_replicate_field_options() );

		$this->assertFalse( $this->responses[0]['ok'] );
		$this->assertStringContainsString( 'Invalid data', $this->responses[0]['data']['message'] );
	}

	public function test_replicate_field_options_reports_audience_not_found(): void {
		$this->mockUtils();
		Functions\when( 'current_user_can' )->justReturn( true );
		$_POST = array( 'nonce' => 'n', 'audience_id' => '4' );

		$repo = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceRepository' );
		$repo->shouldReceive( 'get_by_id' )->with( 4 )->andReturn( null );

		$this->dispatch( fn() => ( new AudienceAjaxController() )->ajax_replicate_field_options() );

		$this->assertFalse( $this->responses[0]['ok'] );
		$this->assertStringContainsString( 'Audience not found', $this->responses[0]['data']['message'] );
	}

	public function test_replicate_field_options_succeeds(): void {
		$this->mockUtils();
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( '_n' )->alias( static fn( $s, $p, $n ) => $n === 1 ? $s : $p );
		$_POST = array( 'nonce' => 'n', 'audience_id' => '4' );

		$repo = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceRepository' );
		$repo->shouldReceive( 'get_by_id' )->with( 4 )->andReturn( (object) array( 'id' => 4 ) );

		$seeder = Mockery::mock( 'alias:FreeFormCertificate\Reregistration\ReregistrationStandardFieldsSeeder' );
		$seeder->shouldReceive( 'replicate_field_options_to_descendants' )->with( 4 )->andReturn( 2 );

		$this->dispatch( fn() => ( new AudienceAjaxController() )->ajax_replicate_field_options() );

		$this->assertTrue( $this->responses[0]['ok'] );
		$this->assertSame( 2, $this->responses[0]['data']['updated'] );
	}

	// ── save_custom_fields ─────────────────────────────────────────────

	public function test_save_custom_fields_rejects_invalid_json(): void {
		$this->mockUtils();
		Functions\when( 'current_user_can' )->justReturn( true );
		Mockery::mock( 'alias:FreeFormCertificate\Core\Debug' )->shouldIgnoreMissing();
		Functions\when( 'wp_unslash' )->returnArg();
		$_POST = array( 'nonce' => 'n', 'audience_id' => '4', 'fields' => 'not-json' );

		$this->dispatch( fn() => ( new AudienceAjaxController() )->ajax_save_custom_fields() );

		$this->assertFalse( $this->responses[0]['ok'] );
		$this->assertStringContainsString( 'Invalid field data format', $this->responses[0]['data']['message'] );
	}

	public function test_save_custom_fields_rejects_missing_audience(): void {
		$this->mockUtils();
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_unslash' )->returnArg();
		$_POST = array( 'nonce' => 'n', 'audience_id' => '0', 'fields' => '[]' );

		$this->dispatch( fn() => ( new AudienceAjaxController() )->ajax_save_custom_fields() );

		$this->assertFalse( $this->responses[0]['ok'] );
		$this->assertStringContainsString( 'Invalid data', $this->responses[0]['data']['message'] );
	}

	public function test_save_custom_fields_reports_audience_not_found(): void {
		$this->mockUtils();
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_unslash' )->returnArg();
		$_POST = array( 'nonce' => 'n', 'audience_id' => '4', 'fields' => '[]' );

		$repo = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceRepository' );
		$repo->shouldReceive( 'get_by_id' )->with( 4 )->andReturn( null );

		$this->dispatch( fn() => ( new AudienceAjaxController() )->ajax_save_custom_fields() );

		$this->assertFalse( $this->responses[0]['ok'] );
		$this->assertStringContainsString( 'Audience not found', $this->responses[0]['data']['message'] );
	}

	public function test_save_custom_fields_creates_new_field(): void {
		$this->mockUtils();
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'sanitize_key' )->returnArg();
		$fields = json_encode(
			array(
				array(
					'id'      => 'new_1',
					'label'   => 'My Field',
					'key'     => 'my_field',
					'type'    => 'text',
					'choices' => array( 'A', '', 'B' ),
				),
			)
		);
		$_POST  = array( 'nonce' => 'n', 'audience_id' => '4', 'fields' => $fields );

		$repo = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceRepository' );
		$repo->shouldReceive( 'get_by_id' )->with( 4 )->andReturn( (object) array( 'id' => 4 ) );

		$cfr = Mockery::mock( 'alias:FreeFormCertificate\Reregistration\CustomFieldRepository' );
		$cfr->shouldReceive( 'create' )->once()->andReturn( 99 );

		$this->dispatch( fn() => ( new AudienceAjaxController() )->ajax_save_custom_fields() );

		$this->assertTrue( $this->responses[0]['ok'] );
		$this->assertSame( array( 99 ), $this->responses[0]['data']['saved_ids'] );
	}

	public function test_save_custom_fields_reports_label_error(): void {
		$this->mockUtils();
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'sanitize_key' )->returnArg();
		$fields = json_encode(
			array(
				array( 'id' => 'new_1', 'label' => '', 'type' => 'text' ),
			)
		);
		$_POST  = array( 'nonce' => 'n', 'audience_id' => '4', 'fields' => $fields );

		$repo = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceRepository' );
		$repo->shouldReceive( 'get_by_id' )->with( 4 )->andReturn( (object) array( 'id' => 4 ) );
		Mockery::mock( 'alias:FreeFormCertificate\Reregistration\CustomFieldRepository' );

		$this->dispatch( fn() => ( new AudienceAjaxController() )->ajax_save_custom_fields() );

		$this->assertFalse( $this->responses[0]['ok'] );
		$this->assertStringContainsString( 'label is required', $this->responses[0]['data']['message'] );
	}

	public function test_save_custom_fields_updates_existing_field(): void {
		$this->mockUtils();
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'sanitize_key' )->returnArg();
		$fields = json_encode(
			array(
				array( 'id' => 12, 'label' => 'Updated', 'type' => 'text' ),
			)
		);
		$_POST  = array( 'nonce' => 'n', 'audience_id' => '4', 'fields' => $fields );

		$repo = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceRepository' );
		$repo->shouldReceive( 'get_by_id' )->with( 4 )->andReturn( (object) array( 'id' => 4 ) );

		$cfr = Mockery::mock( 'alias:FreeFormCertificate\Reregistration\CustomFieldRepository' );
		$cfr->shouldReceive( 'get_by_id' )->with( 12 )->andReturn( (object) array( 'field_source' => 'custom' ) );
		$cfr->shouldReceive( 'update' )->with( 12, Mockery::type( 'array' ) )->andReturn( 1 );

		$this->dispatch( fn() => ( new AudienceAjaxController() )->ajax_save_custom_fields() );

		$this->assertTrue( $this->responses[0]['ok'] );
		$this->assertSame( array( 12 ), $this->responses[0]['data']['saved_ids'] );
	}

	public function test_save_custom_fields_locks_standard_field(): void {
		$this->mockUtils();
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'sanitize_key' )->returnArg();
		$fields = json_encode(
			array(
				array(
					'id'      => 12,
					'label'   => 'Std',
					'type'    => 'text',
					'key'     => 'should_be_dropped',
					'choices' => array( 'X' ),
				),
			)
		);
		$_POST  = array( 'nonce' => 'n', 'audience_id' => '4', 'fields' => $fields );

		$repo = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceRepository' );
		$repo->shouldReceive( 'get_by_id' )->with( 4 )->andReturn( (object) array( 'id' => 4 ) );

		$cfr      = Mockery::mock( 'alias:FreeFormCertificate\Reregistration\CustomFieldRepository' );
		$existing = (object) array( 'field_source' => 'standard', 'field_options' => null );
		$cfr->shouldReceive( 'get_by_id' )->with( 12 )->andReturn( $existing );
		$cfr->shouldReceive( 'update' )->once()->with(
			12,
			Mockery::on(
				static function ( $data ) {
					// Locked standard field: key must be stripped, label retained.
					return ! array_key_exists( 'field_key', $data )
						&& array_key_exists( 'field_label', $data )
						&& array_key_exists( 'field_options', $data );
				}
			)
		)->andReturn( 1 );

		$this->dispatch( fn() => ( new AudienceAjaxController() )->ajax_save_custom_fields() );

		$this->assertTrue( $this->responses[0]['ok'] );
	}

	public function test_save_custom_fields_reports_create_failure(): void {
		$this->mockUtils();
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'sanitize_key' )->returnArg();
		$fields = json_encode(
			array(
				array( 'id' => 'new_1', 'label' => 'Fail', 'type' => 'text' ),
			)
		);
		$_POST  = array( 'nonce' => 'n', 'audience_id' => '4', 'fields' => $fields );

		$repo = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceRepository' );
		$repo->shouldReceive( 'get_by_id' )->with( 4 )->andReturn( (object) array( 'id' => 4 ) );

		$cfr = Mockery::mock( 'alias:FreeFormCertificate\Reregistration\CustomFieldRepository' );
		$cfr->shouldReceive( 'create' )->andReturn( 0 );

		$this->dispatch( fn() => ( new AudienceAjaxController() )->ajax_save_custom_fields() );

		$this->assertFalse( $this->responses[0]['ok'] );
		$this->assertStringContainsString( 'Failed to create', $this->responses[0]['data']['message'] );
	}

	// ── Custom-fields helpers (moved verbatim from AudienceLoader) ──────

	/**
	 * @param array<int, mixed> $args
	 * @return mixed
	 */
	private function invoke_controller( string $method, array $args ) {
		$ctrl = new AudienceAjaxController();
		$ref  = new \ReflectionMethod( AudienceAjaxController::class, $method );
		$ref->setAccessible( true );
		return $ref->invokeArgs( $ctrl, $args );
	}

	public function test_sanitize_dependent_groups_dedups_and_drops_empty(): void {
		Functions\when( 'sanitize_text_field' )->alias( 'trim' );

		$raw = array(
			'Div A' => array( 'S1', 'S1', 'S2' ), // dedup.
			''      => array( 'Orphan' ),          // empty division dropped.
			'Div B' => 'not-an-array',             // non-array sectors dropped.
			'Div C' => array( 'X', '', 'Y' ),      // empty sector dropped.
		);

		$result = $this->invoke_controller( 'sanitize_dependent_groups', array( $raw ) );

		$this->assertSame(
			array(
				'Div A' => array( 'S1', 'S2' ),
				'Div C' => array( 'X', 'Y' ),
			),
			$result
		);
	}

	public function test_preserve_dependent_labels_keeps_existing_labels(): void {
		$existing = (object) array(
			'field_options' => json_encode(
				array(
					'groups'       => array( 'Old' => array( 'x' ) ),
					'parent_label' => 'Division',
					'child_label'  => 'Department',
				)
			),
		);
		$options  = array( 'groups' => array( 'New' => array( 'y' ) ) );

		$result = $this->invoke_controller( 'preserve_dependent_labels', array( $existing, $options ) );

		$this->assertSame( 'Division', $result['parent_label'] );
		$this->assertSame( 'Department', $result['child_label'] );
		$this->assertSame( array( 'New' => array( 'y' ) ), $result['groups'] );
	}

	public function test_preserve_dependent_labels_noop_without_groups(): void {
		$existing = (object) array( 'field_options' => json_encode( array( 'choices' => array( 'a' ) ) ) );
		$options  = array( 'choices' => array( 'b' ) );

		$result = $this->invoke_controller( 'preserve_dependent_labels', array( $existing, $options ) );

		$this->assertSame( $options, $result );
	}
}
