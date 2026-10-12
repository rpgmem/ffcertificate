<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Reregistration\ReregistrationAjaxHandler;

/**
 * Tests for the reregistration admin AJAX endpoints: record generation,
 * submission-details modal and the affected-member count. Covers the
 * nonce/capability/input guards directly; the count happy-path drives the
 * repository via an alias mock in an isolated process.
 *
 * @covers \FreeFormCertificate\Reregistration\ReregistrationAjaxHandler
 */
class ReregistrationAjaxHandlerTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var array<int, array{type: string, data: mixed}> */
	private array $json = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( '__' )->returnArg();
		Functions\when( 'absint' )->alias( static fn ( $v ) => abs( (int) $v ) );
		Functions\when( 'check_ajax_referer' )->justReturn( true );

		$this->json = array();
		$ref =& $this->json;
		Functions\when( 'wp_send_json_success' )->alias( static function ( $data = null ) use ( &$ref ) {
			$ref[] = array( 'type' => 'success', 'data' => $data );
			throw new \RuntimeException( 'wp_send_json_success' );
		} );
		Functions\when( 'wp_send_json_error' )->alias( static function ( $data = null ) use ( &$ref ) {
			$ref[] = array( 'type' => 'error', 'data' => $data );
			throw new \RuntimeException( 'wp_send_json_error' );
		} );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
		$_POST = array();
	}

	/** Invoke an ajax method, swallowing the halt exception thrown by the JSON stubs. */
	private function invoke( ReregistrationAjaxHandler $h, string $method ): void {
		try {
			$h->$method();
		} catch ( \RuntimeException $e ) {
			// Expected — wp_send_json_* halts execution.
		}
	}

	/** @return array{type: string, data: mixed} */
	private function last(): array {
		$last = end( $this->json );
		$this->assertIsArray( $last );
		return $last;
	}

	/** @return array<string, mixed> */
	private function lastData(): array {
		$data = $this->last()['data'];
		$this->assertIsArray( $data );
		return $data;
	}

	public function test_constructor_creates_instance(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		$this->assertInstanceOf( ReregistrationAjaxHandler::class, new ReregistrationAjaxHandler() );
	}

	// --- generate_record guards -------------------------------------------

	public function test_generate_record_denies_without_capability(): void {
		Functions\when( 'current_user_can' )->justReturn( false );
		$this->invoke( new ReregistrationAjaxHandler(), 'ajax_generate_record' );
		$this->assertSame( 'error', $this->last()['type'] );
		$this->assertSame( 'Permission denied.', $this->lastData()['message'] );
	}

	public function test_generate_record_rejects_missing_submission_id(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		$_POST = array(); // no submission_id
		$this->invoke( new ReregistrationAjaxHandler(), 'ajax_generate_record' );
		$this->assertSame( 'error', $this->last()['type'] );
		$this->assertSame( 'Invalid submission.', $this->lastData()['message'] );
	}

	// --- view_submission_details guards ----------------------------------

	public function test_view_details_denies_without_capability(): void {
		Functions\when( 'current_user_can' )->justReturn( false );
		$this->invoke( new ReregistrationAjaxHandler(), 'ajax_view_submission_details' );
		$this->assertSame( 'error', $this->last()['type'] );
	}

	public function test_view_details_rejects_missing_submission_id(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		$_POST = array();
		$this->invoke( new ReregistrationAjaxHandler(), 'ajax_view_submission_details' );
		$this->assertSame( 'error', $this->last()['type'] );
		$this->assertSame( 'Invalid submission.', $this->lastData()['message'] );
	}

	// --- count_members ----------------------------------------------------

	public function test_count_members_denies_without_capability(): void {
		Functions\when( 'current_user_can' )->justReturn( false );
		$this->invoke( new ReregistrationAjaxHandler(), 'ajax_count_members' );
		$this->assertSame( 'error', $this->last()['type'] );
	}

	public function test_count_members_returns_zero_for_empty_audience_set(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		$_POST = array( 'audience_ids' => array( 0, '', 'x' ) ); // all filtered to empty
		$this->invoke( new ReregistrationAjaxHandler(), 'ajax_count_members' );
		$this->assertSame( 'success', $this->last()['type'] );
		$this->assertSame( 0, $this->lastData()['count'] );
	}

	// --- happy paths and not-found guards (alias mocks, isolated) ---------

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_count_members_counts_the_distinct_users_of_the_audiences(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		$_POST = array( 'audience_ids' => array( '4', '0', '7' ) );
		Mockery::mock( 'alias:FreeFormCertificate\Reregistration\ReregistrationRepository' )
			->shouldReceive( 'get_user_ids_for_audiences' )->once()
			->with( array( 0 => 4, 2 => 7 ) )->andReturn( array( 10, 11, 12 ) );

		$this->invoke( new ReregistrationAjaxHandler(), 'ajax_count_members' );

		$this->assertSame( array( 'type' => 'success', 'data' => array( 'count' => 3 ) ), $this->last() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_generate_record_reports_a_record_that_cannot_be_built(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		$_POST = array( 'submission_id' => '15' );
		Mockery::mock( 'alias:FreeFormCertificate\Reregistration\RecordGenerator' )
			->shouldReceive( 'generate_record_data' )->once()->with( 15 )->andReturn( null );

		$this->invoke( new ReregistrationAjaxHandler(), 'ajax_generate_record' );

		$this->assertSame( 'Could not generate record.', $this->lastData()['message'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_generate_record_returns_the_pdf_data(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		$_POST = array( 'submission_id' => '15' );
		Mockery::mock( 'alias:FreeFormCertificate\Reregistration\RecordGenerator' )
			->shouldReceive( 'generate_record_data' )->andReturn( array( 'html' => '<p>record</p>' ) );

		$this->invoke( new ReregistrationAjaxHandler(), 'ajax_generate_record' );

		$this->assertSame( array( 'type' => 'success', 'data' => array( 'pdf_data' => array( 'html' => '<p>record</p>' ) ) ), $this->last() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_view_details_reports_an_unknown_submission(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		$_POST = array( 'submission_id' => '15' );
		Mockery::mock( 'alias:FreeFormCertificate\Reregistration\ReregistrationSubmissionReader' )
			->shouldReceive( 'get_by_id' )->with( 15 )->andReturn( null );

		$this->invoke( new ReregistrationAjaxHandler(), 'ajax_view_submission_details' );

		$this->assertSame( 'Submission not found.', $this->lastData()['message'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_view_details_reports_a_submission_whose_campaign_is_gone(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		$_POST = array( 'submission_id' => '15' );
		Mockery::mock( 'alias:FreeFormCertificate\Reregistration\ReregistrationSubmissionReader' )
			->shouldReceive( 'get_by_id' )->andReturn( (object) array( 'reregistration_id' => '3', 'data' => null ) );
		Mockery::mock( 'alias:FreeFormCertificate\Reregistration\ReregistrationRepository' )
			->shouldReceive( 'get_by_id' )->with( 3 )->andReturn( null );

		$this->invoke( new ReregistrationAjaxHandler(), 'ajax_view_submission_details' );

		$this->assertSame( 'Reregistration not found.', $this->lastData()['message'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_view_details_renders_the_decrypted_values(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		$_POST      = array( 'submission_id' => '15' );
		$submission = (object) array(
			'reregistration_id' => '3',
			'data'              => json_encode( array( 'fields' => array( 'cpf' => 'CIPHER' ) ) ),
		);
		$rereg      = (object) array( 'id' => 3 );
		$fields     = array( (object) array( 'field_key' => 'cpf' ) );
		Mockery::mock( 'alias:FreeFormCertificate\Reregistration\ReregistrationSubmissionReader' )
			->shouldReceive( 'get_by_id' )->andReturn( $submission );
		Mockery::mock( 'alias:FreeFormCertificate\Reregistration\ReregistrationRepository' )
			->shouldReceive( 'get_by_id' )->andReturn( $rereg );
		$generator = Mockery::mock( 'alias:FreeFormCertificate\Reregistration\RecordGenerator' );
		$generator->shouldReceive( 'get_custom_fields_for_reregistration' )->once()->with( $rereg )->andReturn( $fields );
		$generator->shouldReceive( 'decrypt_field_values' )->once()->with( $fields, array( 'cpf' => 'CIPHER' ) )->andReturn( array( 'cpf' => '529.982.247-25' ) );
		Mockery::mock( 'overload:FreeFormCertificate\Reregistration\ReregistrationSubmissionDetailsRenderer' )
			->shouldReceive( 'build_submission_details_html' )->once()
			->with( $submission, $fields, array( 'cpf' => '529.982.247-25' ) )->andReturn( '<dl>details</dl>' );

		$this->invoke( new ReregistrationAjaxHandler(), 'ajax_view_submission_details' );

		$this->assertSame( array( 'type' => 'success', 'data' => array( 'html' => '<dl>details</dl>' ) ), $this->last() );
	}

	// --- send_invitations -------------------------------------------------

	public function test_send_invitations_denies_without_capability(): void {
		Functions\when( 'current_user_can' )->justReturn( false );

		$this->invoke( new ReregistrationAjaxHandler(), 'ajax_send_invitations' );

		$this->assertSame( 'Permission denied.', $this->lastData()['message'] );
	}

	public function test_send_invitations_rejects_a_missing_campaign_id(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_unslash' )->returnArg();
		$_POST = array( 'reregistration_id' => '0' );

		$this->invoke( new ReregistrationAjaxHandler(), 'ajax_send_invitations' );

		$this->assertSame( 'Invalid campaign.', $this->lastData()['message'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_send_invitations_reports_an_unknown_campaign(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_unslash' )->returnArg();
		$_POST = array( 'reregistration_id' => '9' );
		Mockery::mock( 'alias:FreeFormCertificate\Reregistration\ReregistrationRepository' )
			->shouldReceive( 'get_by_id' )->with( 9 )->andReturn( null );

		$this->invoke( new ReregistrationAjaxHandler(), 'ajax_send_invitations' );

		$this->assertSame( 'Campaign not found.', $this->lastData()['message'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_send_invitations_refuses_when_invitations_are_disabled(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_unslash' )->returnArg();
		$_POST = array( 'reregistration_id' => '9' );
		Mockery::mock( 'alias:FreeFormCertificate\Reregistration\ReregistrationRepository' )
			->shouldReceive( 'get_by_id' )->andReturn( (object) array( 'email_invitation_enabled' => '0' ) );
		Mockery::mock( 'alias:FreeFormCertificate\Reregistration\ReregistrationEmailHandler' )
			->shouldNotReceive( 'send_invitations' );

		$this->invoke( new ReregistrationAjaxHandler(), 'ajax_send_invitations' );

		$this->assertSame( 'Invitation emails are disabled for this campaign.', $this->lastData()['message'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_send_invitations_reports_how_many_were_sent(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( '_n' )->alias( static fn ( $single, $plural, $n ) => 1 === $n ? $single : $plural );
		$_POST = array( 'reregistration_id' => '9' );
		Mockery::mock( 'alias:FreeFormCertificate\Reregistration\ReregistrationRepository' )
			->shouldReceive( 'get_by_id' )->andReturn( (object) array( 'email_invitation_enabled' => '1' ) );
		Mockery::mock( 'alias:FreeFormCertificate\Reregistration\ReregistrationEmailHandler' )
			->shouldReceive( 'send_invitations' )->twice()->with( 9 )->andReturn( 3, 0 );

		$this->invoke( new ReregistrationAjaxHandler(), 'ajax_send_invitations' );
		$this->invoke( new ReregistrationAjaxHandler(), 'ajax_send_invitations' );

		$this->assertSame( array( 'sent' => 3, 'message' => '3 invitations sent.' ), $this->json[0]['data'] );
		$this->assertSame( array( 'sent' => 0, 'message' => 'Nobody was awaiting an invitation.' ), $this->json[1]['data'] );
	}
}
