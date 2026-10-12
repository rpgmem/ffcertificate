<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Reregistration\ReregistrationFrontend;

/**
 * Tests for the parts of ReregistrationFrontend's AJAX endpoints that run
 * past the guards: the form render, the import of the previous approved
 * cycle (decryption with the SOURCE campaign's fields, intersection with the
 * current one), the honeypot, validation errors and a successful submit.
 *
 * The repositories, the renderer and the data processor are aliased, so each
 * test runs in its own process. The JSON responders throw to stand in for
 * the `die()` WordPress performs.
 *
 * @covers \FreeFormCertificate\Reregistration\ReregistrationFrontend
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class ReregistrationFrontendFlowTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var array{type: string, data: mixed}|null */
	private ?array $response = null;

	/** @var Mockery\MockInterface */
	private $campaigns;

	/** @var Mockery\MockInterface */
	private $submissions;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( '__' )->returnArg();
		Functions\when( 'absint' )->alias( static fn ( $v ) => abs( (int) $v ) );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'check_ajax_referer' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 5 );

		$this->response = null;
		$response       = &$this->response;
		Functions\when( 'wp_send_json_error' )->alias( static function ( $data = null ) use ( &$response ) {
			$response = array( 'type' => 'error', 'data' => $data );
			throw new \RuntimeException( 'json' );
		} );
		Functions\when( 'wp_send_json_success' )->alias( static function ( $data = null ) use ( &$response ) {
			$response = array( 'type' => 'success', 'data' => $data );
			throw new \RuntimeException( 'json' );
		} );

		$this->campaigns   = Mockery::mock( 'alias:FreeFormCertificate\Reregistration\ReregistrationRepository' );
		$this->submissions = Mockery::mock( 'alias:FreeFormCertificate\Reregistration\ReregistrationSubmissionReader' );
	}

	protected function tearDown(): void {
		$_POST = array();
		Monkey\tearDown();
		parent::tearDown();
	}

	private function call( string $method ): void {
		try {
			ReregistrationFrontend::$method();
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'json', $e->getMessage() );
		}
		$this->assertNotNull( $this->response, "{$method}() must answer with JSON." );
	}

	private function activeCampaign( int $id = 3 ): object {
		return (object) array( 'id' => $id, 'status' => 'active', 'title' => 'Census' );
	}

	private function ownSubmission( string $status = 'pending' ): object {
		return (object) array( 'id' => 70, 'status' => $status, 'user_id' => 5 );
	}

	// ==================================================================
	// ajax_get_form()
	// ==================================================================

	public function test_get_form_returns_the_rendered_form(): void {
		$_POST['reregistration_id'] = '3';
		$rereg                      = $this->activeCampaign();
		$submission                 = $this->ownSubmission( 'in_progress' );
		$this->campaigns->shouldReceive( 'get_by_id' )->with( 3 )->andReturn( $rereg );
		$this->submissions->shouldReceive( 'get_by_reregistration_and_user' )->with( 3, 5 )->andReturn( $submission );
		Mockery::mock( 'alias:FreeFormCertificate\Reregistration\ReregistrationFormRenderer' )
			->shouldReceive( 'render' )->once()->with( $rereg, $submission, 5 )->andReturn( '<form>' );

		$this->call( 'ajax_get_form' );

		$this->assertSame( array( 'type' => 'success', 'data' => array( 'html' => '<form>' ) ), $this->response );
	}

	// ==================================================================
	// ajax_import_previous()
	// ==================================================================

	public function test_import_previous_refuses_an_inactive_campaign(): void {
		$_POST['reregistration_id'] = '3';
		$this->campaigns->shouldReceive( 'get_by_id' )->andReturn( (object) array( 'id' => 3, 'status' => 'closed' ) );
		$this->submissions->shouldNotReceive( 'get_latest_approved_for_user' );

		$this->call( 'ajax_import_previous' );

		$this->assertSame( 'Reregistration not found or not active.', $this->response['data']['message'] );
	}

	public function test_import_previous_without_an_earlier_approval_says_so(): void {
		$_POST['reregistration_id'] = '3';
		$this->campaigns->shouldReceive( 'get_by_id' )->andReturn( $this->activeCampaign() );
		$this->submissions->shouldReceive( 'get_by_reregistration_and_user' )->andReturn( $this->ownSubmission() );
		$this->submissions->shouldReceive( 'get_latest_approved_for_user' )->once()->with( 5, 3 )->andReturn( null );

		$this->call( 'ajax_import_previous' );

		$this->assertSame( 'No previous approved reregistration to import.', $this->response['data']['message'] );
	}

	public function test_import_previous_decrypts_with_the_source_fields_and_keeps_only_current_keys(): void {
		$_POST['reregistration_id'] = '3';
		$current                    = $this->activeCampaign( 3 );
		$previous                   = (object) array( 'id' => 2, 'status' => 'closed' );
		$this->campaigns->shouldReceive( 'get_by_id' )->with( 3 )->andReturn( $current );
		$this->campaigns->shouldReceive( 'get_by_id' )->with( 2 )->andReturn( $previous );
		$this->submissions->shouldReceive( 'get_by_reregistration_and_user' )->andReturn( $this->ownSubmission() );
		$this->submissions->shouldReceive( 'get_latest_approved_for_user' )->andReturn(
			(object) array(
				'reregistration_id'    => '2',
				'reregistration_title' => 'Census 2025',
				'data'                 => json_encode( array( 'fields' => array( 'cpf' => 'CIPHER', 'phone' => '', 'retired' => 'x', 'name' => 'Ana' ) ) ),
			)
		);
		$source_fields  = array( (object) array( 'field_key' => 'cpf', 'is_sensitive' => 1 ) );
		$current_fields = array(
			(object) array( 'field_key' => 'cpf' ),
			(object) array( 'field_key' => 'phone' ),
			(object) array( 'field_key' => 'name' ),
		);
		$generator      = Mockery::mock( 'alias:FreeFormCertificate\Reregistration\RecordGenerator' );
		$generator->shouldReceive( 'get_custom_fields_for_reregistration' )->with( $previous )->andReturn( $source_fields );
		$generator->shouldReceive( 'get_custom_fields_for_reregistration' )->with( $current )->andReturn( $current_fields );
		$generator->shouldReceive( 'decrypt_field_values' )->once()
			->with( $source_fields, array( 'cpf' => 'CIPHER', 'phone' => '', 'retired' => 'x', 'name' => 'Ana' ) )
			->andReturn( array( 'cpf' => '529.982.247-25', 'phone' => '', 'retired' => 'x', 'name' => 'Ana' ) );

		$this->call( 'ajax_import_previous' );

		$this->assertSame(
			array(
				'type' => 'success',
				'data' => array(
					'fields' => array( 'cpf' => '529.982.247-25', 'name' => 'Ana' ),
					'source' => array( 'title' => 'Census 2025' ),
				),
			),
			$this->response,
			'Empty values and keys the current campaign lacks are dropped.'
		);
	}

	public function test_import_previous_skips_decryption_when_the_source_campaign_is_gone(): void {
		$_POST['reregistration_id'] = '3';
		$current                    = $this->activeCampaign( 3 );
		$this->campaigns->shouldReceive( 'get_by_id' )->with( 3 )->andReturn( $current );
		$this->campaigns->shouldReceive( 'get_by_id' )->with( 2 )->andReturn( null );
		$this->submissions->shouldReceive( 'get_by_reregistration_and_user' )->andReturn( $this->ownSubmission() );
		$this->submissions->shouldReceive( 'get_latest_approved_for_user' )->andReturn(
			(object) array( 'reregistration_id' => '2', 'data' => null )
		);
		$generator = Mockery::mock( 'alias:FreeFormCertificate\Reregistration\RecordGenerator' );
		$generator->shouldNotReceive( 'decrypt_field_values' );
		$generator->shouldReceive( 'get_custom_fields_for_reregistration' )->once()->with( $current )->andReturn( array() );

		$this->call( 'ajax_import_previous' );

		$this->assertSame( array( 'fields' => array(), 'source' => array( 'title' => '' ) ), $this->response['data'] );
	}

	// ==================================================================
	// ajax_submit()
	// ==================================================================

	public function test_submit_trips_the_honeypot_before_reading_anything(): void {
		$_POST = array( 'ffc_honeypot_trap' => 'bot', 'reregistration_id' => '3' );
		$this->campaigns->shouldNotReceive( 'get_by_id' );

		$this->call( 'ajax_submit' );

		$this->assertSame( 'Invalid submission.', $this->response['data']['message'] );
	}

	public function test_submit_refuses_a_user_outside_the_campaign(): void {
		$_POST['reregistration_id'] = '3';
		$this->campaigns->shouldReceive( 'get_by_id' )->andReturn( $this->activeCampaign() );
		$this->campaigns->shouldReceive( 'get_active_for_user' )->with( 5 )->andReturn( array( (object) array( 'id' => 8 ) ) );
		$this->submissions->shouldReceive( 'get_by_reregistration_and_user' )->andReturn( null );
		Mockery::mock( 'alias:FreeFormCertificate\Reregistration\ReregistrationSubmissionWriter' )->shouldNotReceive( 'create' );

		$this->call( 'ajax_submit' );

		$this->assertSame( 'You are not part of this reregistration.', $this->response['data']['message'] );
	}

	public function test_submit_returns_the_validation_errors_without_processing(): void {
		$_POST['reregistration_id'] = '3';
		$rereg                      = $this->activeCampaign();
		$this->campaigns->shouldReceive( 'get_by_id' )->andReturn( $rereg );
		$this->submissions->shouldReceive( 'get_by_reregistration_and_user' )->andReturn( $this->ownSubmission() );
		$processor = Mockery::mock( 'alias:FreeFormCertificate\Reregistration\ReregistrationDataProcessor' );
		$processor->shouldReceive( 'collect_form_data' )->with( $rereg )->andReturn( array( 'fields' => array() ) );
		$processor->shouldReceive( 'validate_submission' )->with( array( 'fields' => array() ), $rereg, 5 )->andReturn( array( 'cpf' => 'Required.' ) );
		$processor->shouldNotReceive( 'process_submission' );

		$this->call( 'ajax_submit' );

		$this->assertSame(
			array( 'type' => 'error', 'data' => array( 'message' => 'Please fix the errors below.', 'errors' => array( 'cpf' => 'Required.' ) ) ),
			$this->response
		);
	}

	public function test_submit_processes_a_valid_submission(): void {
		$_POST['reregistration_id'] = '3';
		$rereg                      = $this->activeCampaign();
		$submission                 = $this->ownSubmission();
		$this->campaigns->shouldReceive( 'get_by_id' )->andReturn( $rereg );
		$this->submissions->shouldReceive( 'get_by_reregistration_and_user' )->andReturn( $submission );
		$processor = Mockery::mock( 'alias:FreeFormCertificate\Reregistration\ReregistrationDataProcessor' );
		$processor->shouldReceive( 'collect_form_data' )->andReturn( array( 'fields' => array( 'name' => 'Ana' ) ) );
		$processor->shouldReceive( 'validate_submission' )->andReturn( array() );
		$processor->shouldReceive( 'process_submission' )->once()->with( $submission, $rereg, array( 'fields' => array( 'name' => 'Ana' ) ), 5 );

		$this->call( 'ajax_submit' );

		$this->assertSame( array( 'type' => 'success', 'data' => array( 'message' => 'Reregistration submitted successfully!' ) ), $this->response );
	}

	// ==================================================================
	// ajax_save_draft()
	// ==================================================================

	public function test_save_draft_trips_the_honeypot(): void {
		$_POST = array( 'ffc_honeypot_trap' => 'bot', 'reregistration_id' => '3' );
		$this->campaigns->shouldNotReceive( 'get_by_id' );

		$this->call( 'ajax_save_draft' );

		$this->assertSame( 'Invalid submission.', $this->response['data']['message'] );
	}
}
