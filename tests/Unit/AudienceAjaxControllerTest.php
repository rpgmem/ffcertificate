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
