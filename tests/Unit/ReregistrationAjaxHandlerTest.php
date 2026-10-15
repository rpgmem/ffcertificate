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

	/** @var array<int, int|null> HTTP status passed to each wp_send_json_error(). */
	private array $statuses = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( '__' )->returnArg();
		Functions\when( 'absint' )->alias( static fn ( $v ) => abs( (int) $v ) );
		Functions\when( 'check_ajax_referer' )->justReturn( true );
		// The details endpoint resolves the PII tier (#1815). By default the
		// caller holds no PII cap and no role, which is the masked tier.
		Functions\when( 'get_current_user_id' )->justReturn( 9 );
		Functions\when( 'user_can' )->justReturn( false );
		Functions\when( 'get_user_by' )->justReturn( false );

		$this->json     = array();
		$this->statuses = array();
		$ref =& $this->json;
		$codes =& $this->statuses;
		Functions\when( 'wp_send_json_success' )->alias( static function ( $data = null ) use ( &$ref ) {
			$ref[] = array( 'type' => 'success', 'data' => $data );
			throw new \RuntimeException( 'wp_send_json_success' );
		} );
		Functions\when( 'wp_send_json_error' )->alias( static function ( $data = null, $status = null ) use ( &$ref, &$codes ) {
			$ref[]   = array( 'type' => 'error', 'data' => $data );
			$codes[] = $status;
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

	public function test_view_details_denies_without_the_view_capability(): void {
		Functions\when( 'current_user_can' )->justReturn( false );
		$this->invoke( new ReregistrationAjaxHandler(), 'ajax_view_submission_details' );
		$this->assertSame( 'error', $this->last()['type'] );
		$this->assertSame( 'Permission denied.', $this->lastData()['message'] );
		$this->assertSame( array( 403 ), $this->statuses );
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
		Functions\when( 'user_can' )->justReturn( true ); // A site admin: the unmasked tier.
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
		Functions\when( 'user_can' )->justReturn( true ); // A site admin: the unmasked tier.
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
		// A site admin's open is audited like any other (#1815).
		$this->activityLog()->shouldReceive( 'log' )->once()->andReturn( true );

		$this->invoke( new ReregistrationAjaxHandler(), 'ajax_view_submission_details' );

		$this->assertSame( array( 'type' => 'success', 'data' => array( 'html' => '<dl>details</dl>' ) ), $this->last() );
	}

	// --- view_submission_details: PII tiers (#1815) ------------------------

	/**
	 * Alias-mock the collaborators of a details request that reaches the
	 * renderer, and return the submission it serves.
	 *
	 * @param bool $expect_render Whether the renderer must be reached.
	 */
	private function stubDetailsRequest( bool $expect_render ): object {
		$_POST      = array( 'submission_id' => '15' );
		$submission = (object) array(
			'user_id'           => '42',
			'reregistration_id' => '3',
			'data'              => null,
		);
		Mockery::mock( 'alias:FreeFormCertificate\Reregistration\ReregistrationSubmissionReader' )
			->shouldReceive( 'get_by_id' )->with( 15 )->andReturn( $submission );
		$repo = Mockery::mock( 'alias:FreeFormCertificate\Reregistration\ReregistrationRepository' );
		$gen  = Mockery::mock( 'alias:FreeFormCertificate\Reregistration\RecordGenerator' );
		$view = Mockery::mock( 'overload:FreeFormCertificate\Reregistration\ReregistrationSubmissionDetailsRenderer' );
		if ( $expect_render ) {
			$repo->shouldReceive( 'get_by_id' )->with( 3 )->andReturn( (object) array( 'id' => 3 ) );
			$gen->shouldReceive( 'get_custom_fields_for_reregistration' )->andReturn( array() );
			$gen->shouldReceive( 'decrypt_field_values' )->andReturn( array() );
			$view->shouldReceive( 'build_submission_details_html' )->once()->andReturn( '<dl>details</dl>' );
		} else {
			$repo->shouldReceive( 'get_by_id' )->never();
			$gen->shouldReceive( 'decrypt_field_values' )->never();
			$view->shouldReceive( 'build_submission_details_html' )->never();
		}
		return $submission;
	}

	/**
	 * The ActivityLog alias, with the level constant the handler reads.
	 *
	 * @return Mockery\MockInterface
	 */
	private function activityLog() {
		Mockery::getConfiguration()->setConstantsMap(
			array(
				'FreeFormCertificate\Core\ActivityLog' => array( 'LEVEL_INFO' => 'info' ),
			)
		);
		return Mockery::mock( 'alias:FreeFormCertificate\Core\ActivityLog' );
	}

	/**
	 * A viewer of the list without the PII cap is the masked tier: 403, and
	 * nothing is decrypted.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_view_details_refuses_the_masked_tier_with_a_403(): void {
		Functions\when( 'current_user_can' )->alias( static fn ( $cap ) => 'ffc_view_reregistration' === $cap );
		$this->stubDetailsRequest( false );
		$this->activityLog()->shouldReceive( 'log' )->never();

		$this->invoke( new ReregistrationAjaxHandler(), 'ajax_view_submission_details' );

		$this->assertSame( 'error', $this->last()['type'] );
		$this->assertSame( "You do not have permission to view this submission's personal data.", $this->lastData()['message'] );
		$this->assertSame( array( 403 ), $this->statuses );
	}

	/**
	 * The manage cap alone no longer opens the details: the PII cap does.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_view_details_refuses_a_manager_without_the_pii_cap(): void {
		Functions\when( 'current_user_can' )->alias(
			static fn ( $cap ) => in_array( $cap, array( 'ffc_view_reregistration', 'ffc_manage_reregistration' ), true )
		);
		Functions\when( 'user_can' )->alias(
			static fn ( $uid, $cap ) => in_array( $cap, array( 'ffc_view_reregistration', 'ffc_manage_reregistration' ), true )
		);
		$this->stubDetailsRequest( false );
		$this->activityLog()->shouldReceive( 'log' )->never();

		$this->invoke( new ReregistrationAjaxHandler(), 'ajax_view_submission_details' );

		$this->assertSame( array( 403 ), $this->statuses );
	}

	/**
	 * The reveal tier opens the details and writes an audit row naming the
	 * submission.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_view_details_opens_for_the_reveal_tier_and_audits(): void {
		Functions\when( 'current_user_can' )->alias( static fn ( $cap ) => 'ffc_view_reregistration' === $cap );
		Functions\when( 'user_can' )->alias( static fn ( $uid, $cap ) => 'ffc_view_reregistration_pii' === $cap );
		$this->stubDetailsRequest( true );
		$this->activityLog()->shouldReceive( 'log' )->once()
			->with( 'reregistration_details_viewed', 'info', array( 'reregistration_id' => 3 ), 9, 15 )
			->andReturn( true );

		$this->invoke( new ReregistrationAjaxHandler(), 'ajax_view_submission_details' );

		$this->assertSame( array( 'type' => 'success', 'data' => array( 'html' => '<dl>details</dl>' ) ), $this->last() );
	}

	/**
	 * The unmasked tier (the reregistration admin role) is audited too: the
	 * modal decrypts the whole record, and every reveal served is logged,
	 * whatever the tier, as `ffc_reveal_pii` does (#1655).
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_view_details_opens_for_the_unmasked_tier_and_audits(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_user_by' )->justReturn( (object) array( 'roles' => array( 'ffc_reregistration_admin' ) ) );
		$this->stubDetailsRequest( true );
		$this->activityLog()->shouldReceive( 'log' )->once()
			->with( 'reregistration_details_viewed', 'info', array( 'reregistration_id' => 3 ), 9, 15 )
			->andReturn( true );

		$this->invoke( new ReregistrationAjaxHandler(), 'ajax_view_submission_details' );

		$this->assertSame( 'success', $this->last()['type'] );
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
