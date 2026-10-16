<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Reregistration\ReregistrationAdminRenderer;

/**
 * Tests for ReregistrationAdminRenderer: the list, form and submissions
 * views, including the filters each reads from the request and the
 * not-found refusal on the views that take an id.
 *
 * Every collaborator is a static class, so the views run in separate
 * processes with alias mocks rather than against `$wpdb`.
 *
 * @covers \FreeFormCertificate\Reregistration\ReregistrationAdminRenderer
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class ReregistrationAdminRendererTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'esc_html_e' )->alias( function ( $text ) { echo $text; } );
		Functions\when( 'esc_attr_e' )->alias( function ( $text ) { echo $text; } );
		Functions\when( 'absint' )->alias( function ( $v ) { return abs( (int) $v ); } );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'admin_url' )->alias( function ( $path = '' ) {
			return 'https://example.com/wp-admin/' . $path;
		} );
		Functions\when( 'wp_nonce_url' )->alias( function ( $url, $action ) {
			return $url . '&_wpnonce=' . $action;
		} );
		Functions\when( 'selected' )->justReturn( '' );
		Functions\when( 'get_settings_errors' )->justReturn( array() );
		Functions\when( 'submit_button' )->alias( function ( $text = '' ) {
			echo '<button>' . $text . '</button>';
		} );
		Functions\when( 'wp_nonce_field' )->alias( function ( $action ) {
			echo '<input type="hidden" data-nonce="' . $action . '">';
		} );
		Functions\when( 'wp_die' )->alias( function ( $message ) {
			throw new \RuntimeException( 'wp_die: ' . $message );
		} );
		// PII policy (#1815): a logged-in viewer with no PII cap and no role,
		// i.e. the masked tier, unless a test says otherwise.
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'user_can' )->justReturn( false );
		Functions\when( 'get_user_by' )->justReturn( false );
		Functions\when( 'is_email' )->alias( static fn ( $e ) => false !== filter_var( $e, FILTER_VALIDATE_EMAIL ) );
		Functions\when( 'wp_create_nonce' )->alias( static fn ( $action ) => 'nonce:' . $action );
		// No capability by default: a view-only user, whom the Record PDF
		// endpoint refuses.
		Functions\when( 'current_user_can' )->justReturn( false );

		\Mockery::getConfiguration()->setConstantsMap(
			array(
				'FreeFormCertificate\Reregistration\ReregistrationRepository' => array(
					'STATUSES' => array( 'draft', 'active', 'closed' ),
				),
				'FreeFormCertificate\Reregistration\ReregistrationSubmissionReader' => array(
					'STATUSES' => array( 'pending', 'submitted', 'approved' ),
				),
			)
		);

		Mockery::mock( 'alias:FreeFormCertificate\Core\DateFormatter' )
			->shouldReceive( 'format_date' )->andReturnUsing( fn ( $ts ) => null === $ts ? 'no-date' : gmdate( 'Y-m-d', $ts ) )
			->shouldReceive( 'format_datetime' )->andReturnUsing( fn ( $ts ) => 1 === $ts ? '' : gmdate( 'Y-m-d H:i', $ts ) );
	}

	protected function tearDown(): void {
		$_GET = array();
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @return Mockery\MockInterface
	 */
	private function campaignRepository() {
		$repo = Mockery::mock( 'alias:FreeFormCertificate\Reregistration\ReregistrationRepository' );
		$repo->shouldReceive( 'get_status_label' )->andReturnUsing( fn ( $s ) => 'Label:' . $s )->byDefault();
		return $repo;
	}

	/**
	 * @return Mockery\MockInterface
	 */
	private function submissionReader() {
		$reader = Mockery::mock( 'alias:FreeFormCertificate\Reregistration\ReregistrationSubmissionReader' );
		$reader->shouldReceive( 'get_status_label' )->andReturnUsing( fn ( $s ) => 'Sub:' . $s )->byDefault();
		return $reader;
	}

	private function campaign( int $id = 7 ): object {
		return (object) array(
			'id'                         => $id,
			'title'                      => 'Census 2026',
			'status'                     => 'active',
			'start_date'                 => '2026-01-01 08:00:00',
			'end_date'                   => '2026-02-01 18:00:00',
			'auto_approve'               => 1,
			'email_invitation_enabled'   => 1,
			'email_reminder_enabled'     => 0,
			'email_confirmation_enabled' => 0,
			'reminder_days'              => 5,
		);
	}

	private function capture( callable $render ): string {
		ob_start();
		try {
			$render();
		} finally {
			$html = (string) ob_get_clean();
		}
		return $html;
	}

	// ==================================================================
	// render_list()
	// ==================================================================

	public function test_render_list_passes_status_and_audience_filters_to_the_repository(): void {
		$_GET['status']      = 'active';
		$_GET['audience_id'] = '4';

		$repo = $this->campaignRepository();
		$repo->shouldReceive( 'get_all' )->once()->with( array( 'status' => 'active', 'audience_id' => 4 ) )->andReturn( array( $this->campaign() ) );
		$repo->shouldReceive( 'get_audiences' )->andReturn( array() );
		$this->submissionReader()->shouldReceive( 'get_statistics' )->andReturn( array( 'approved' => 1, 'total' => 3 ) );
		Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceReader' )
			->shouldReceive( 'get_hierarchical' )->andReturn(
				array(
					(object) array(
						'id'       => 4,
						'name'     => 'Teachers',
						'children' => array( (object) array( 'id' => 5, 'name' => 'Substitutes' ) ),
					),
				)
			);

		$html = $this->capture( fn () => ReregistrationAdminRenderer::render_list( 'ffc-reregistration', true ) );

		$this->assertStringContainsString( 'admin.php?page=ffc-reregistration&view=new', $html );
		$this->assertStringContainsString( 'Census 2026', $html );
		$this->assertStringContainsString( '<option value="4" >Teachers</option>', $html );
		$this->assertStringContainsString( '<option value="5" >&mdash; Substitutes</option>', $html );
	}

	public function test_render_list_without_filters_asks_for_everything(): void {
		$repo = $this->campaignRepository();
		$repo->shouldReceive( 'get_all' )->once()->with( array() )->andReturn( array() );
		Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceReader' )
			->shouldReceive( 'get_hierarchical' )->andReturn( array() );

		$html = $this->capture( fn () => ReregistrationAdminRenderer::render_list( 'ffc-reregistration', false ) );

		$this->assertStringContainsString( 'Label:draft', $html, 'The status filter lists every status.' );
	}

	// ==================================================================
	// render_list_row()
	// ==================================================================

	public function test_render_list_row_for_a_viewer_links_the_title_to_submissions(): void {
		$repo = $this->campaignRepository();
		$repo->shouldReceive( 'get_audiences' )->with( 7 )->andReturn( array() );
		$this->submissionReader()->shouldReceive( 'get_statistics' )->with( 7 )->andReturn( array( 'approved' => 0, 'total' => 0 ) );

		$html = $this->capture( fn () => ReregistrationAdminRenderer::render_list_row( 'ffc-reregistration', $this->campaign(), false ) );

		$this->assertStringContainsString( 'view=submissions&id=7', $html );
		$this->assertStringNotContainsString( 'view=edit&id=7', $html, 'A read-only viewer is never sent to the editor.' );
		$this->assertStringContainsString( '2026-01-01', $html );
		$this->assertStringContainsString( '2026-02-01', $html );
	}

	public function test_render_list_row_survives_an_unparseable_date(): void {
		$repo = $this->campaignRepository();
		$repo->shouldReceive( 'get_audiences' )->andReturn( array() );
		$this->submissionReader()->shouldReceive( 'get_statistics' )->andReturn( array( 'approved' => 0, 'total' => 0 ) );
		$item             = $this->campaign();
		$item->start_date = 'garbage';

		$html = $this->capture( fn () => ReregistrationAdminRenderer::render_list_row( 'ffc-reregistration', $item, true ) );

		$this->assertStringContainsString( 'no-date', $html );
		$this->assertStringContainsString( 'delete_reregistration_7', $html, 'The delete link carries its nonce action.' );
	}

	// ==================================================================
	// render_form()
	// ==================================================================

	/**
	 * Aliases the collaborators the form template draws.
	 */
	private function stubFormWidgets( bool $can_import ): void {
		Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceTransferList' )
			->shouldReceive( 'render' )->andReturnUsing(
				function ( $selected ) {
					echo '<div class="transfer" data-selected="' . implode( ',', $selected ) . '"></div>';
				}
			);
		Mockery::mock( 'alias:FreeFormCertificate\Core\EmailDisabledNotice' )->shouldReceive( 'render' );
		Mockery::mock( 'alias:FreeFormCertificate\Admin\AdminUI' )
			->shouldReceive( 'render_toggle' )->andReturnUsing(
				function ( $args ) {
					echo '<toggle name="' . $args['name'] . '" checked="' . ( $args['checked'] ? '1' : '0' ) . '">';
				}
			);
		Mockery::mock( 'alias:FreeFormCertificate\Core\Capabilities' )
			->shouldReceive( 'current_user_can_admin_or' )->andReturn( $can_import );
	}

	public function test_render_form_for_a_new_campaign_queries_no_audiences(): void {
		$this->stubFormWidgets( true );
		$repo = $this->campaignRepository();
		$repo->shouldNotReceive( 'get_by_id' );
		$repo->shouldNotReceive( 'get_audience_ids' );
		Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceReader' )->shouldNotReceive( 'get_by_id' );

		$html = $this->capture( fn () => ReregistrationAdminRenderer::render_form( 'ffc-reregistration', 0 ) );

		$this->assertStringContainsString( '<h1>New Reregistration</h1>', $html );
		$this->assertStringContainsString( 'Create Reregistration', $html );
		$this->assertStringContainsString( 'data-selected=""', $html );
		$this->assertStringNotContainsString( 'ffc-rereg-send-invitations', $html, 'An unsaved campaign has nobody to invite.' );
	}

	public function test_render_form_for_an_existing_campaign_names_only_live_audiences(): void {
		$this->stubFormWidgets( true );
		$repo = $this->campaignRepository();
		$repo->shouldReceive( 'get_by_id' )->with( 7 )->andReturn( $this->campaign() );
		$repo->shouldReceive( 'get_audience_ids' )->with( 7 )->andReturn( array( 3, '9' ) );
		$repo->shouldReceive( 'get_affected_user_ids_for_reregistration' )->with( 7 )->andReturn( array( 1, 2, 3 ) );
		$audiences = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceReader' );
		$audiences->shouldReceive( 'get_by_id' )->with( 3 )->andReturn( (object) array( 'id' => 3, 'name' => 'Teachers' ) );
		$audiences->shouldReceive( 'get_by_id' )->with( 9 )->andReturn( null );

		$html = $this->capture( fn () => ReregistrationAdminRenderer::render_form( 'ffc-reregistration', 7 ) );

		$this->assertStringContainsString( '<h1>Edit Reregistration</h1>', $html );
		$this->assertStringContainsString( 'data-selected="3,9"', $html );
		$this->assertStringContainsString( 'value="Census 2026"', $html );
		$this->assertStringContainsString( '<toggle name="rereg_auto_approve" checked="1">', $html );
		$this->assertStringContainsString( '<toggle name="rereg_email_reminder" checked="0">', $html );
		$this->assertStringContainsString( '<strong>Affected users:</strong> 3', $html );
		$this->assertStringContainsString( 'ffc-rereg-send-invitations', $html );
		// The import picker offers the live audience and drops the deleted one.
		$this->assertStringContainsString( 'Teachers', $html );
		$this->assertStringNotContainsString( 'value="9"', $html );
	}

	public function test_render_form_hides_the_import_panel_without_the_import_capability(): void {
		$this->stubFormWidgets( false );
		$repo = $this->campaignRepository();
		$repo->shouldReceive( 'get_by_id' )->andReturn( $this->campaign() );
		$repo->shouldReceive( 'get_audience_ids' )->andReturn( array( 3 ) );
		$repo->shouldReceive( 'get_affected_user_ids_for_reregistration' )->andReturn( array() );
		Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceReader' )
			->shouldReceive( 'get_by_id' )->andReturn( (object) array( 'id' => 3, 'name' => 'Teachers' ) );

		$with_cap = $this->capture( fn () => ReregistrationAdminRenderer::render_form( 'ffc-reregistration', 7 ) );

		$this->assertStringNotContainsString( 'Teachers', $with_cap, 'Without the capability no import picker is drawn.' );
		$this->assertStringContainsString( 'ffc-rereg-send-invitations', $with_cap );
	}

	public function test_render_form_dies_for_an_unknown_campaign(): void {
		$this->campaignRepository()->shouldReceive( 'get_by_id' )->with( 404 )->andReturn( null );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'wp_die: Reregistration not found.' );

		$this->capture( fn () => ReregistrationAdminRenderer::render_form( 'ffc-reregistration', 404 ) );
	}

	// ==================================================================
	// render_submissions() / render_submission_row()
	// ==================================================================

	public function test_render_submissions_dies_for_an_unknown_campaign(): void {
		$this->campaignRepository()->shouldReceive( 'get_by_id' )->andReturn( null );
		$this->submissionReader()->shouldNotReceive( 'get_by_reregistration' );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'wp_die: Reregistration not found.' );

		$this->capture( fn () => ReregistrationAdminRenderer::render_submissions( 'ffc-reregistration', 404, true ) );
	}

	public function test_render_submissions_filters_and_draws_one_card_per_status(): void {
		$_GET['sub_status'] = 'submitted';
		$_GET['s']          = 'maria';

		$this->campaignRepository()->shouldReceive( 'get_by_id' )->with( 7 )->andReturn( $this->campaign() );
		$reader = $this->submissionReader();
		$reader->shouldReceive( 'get_by_reregistration' )->once()
			->with( 7, array( 'status' => 'submitted', 'search' => 'maria' ) )->andReturn( array() );
		$reader->shouldReceive( 'get_statistics' )->with( 7 )->andReturn(
			array( 'pending' => 2, 'odd' => 1, 'total' => 3 )
		);
		$cards = array();
		Mockery::mock( 'alias:FreeFormCertificate\Admin\AdminUI' )
			->shouldReceive( 'get_stat_card' )->andReturnUsing(
				function ( $args ) use ( &$cards ) {
					$cards[] = $args;
					return '<card>';
				}
			);

		$html = $this->capture( fn () => ReregistrationAdminRenderer::render_submissions( 'ffc-reregistration', 7, true ) );

		$this->assertSame( 'Submissions: Census 2026', trim( strip_tags( explode( '</h1>', $html )[0] ) ) );
		$this->assertStringContainsString( 'No submissions found.', $html );
		$this->assertStringContainsString( 'name="bulk_action"', $html );
		$this->assertStringContainsString( 'value="maria"', $html );
		$this->assertSame(
			array(
				array( 'label' => 'Sub:pending', 'value' => 2, 'icon' => 'clock', 'tone' => 'neutral' ),
				array( 'label' => 'Sub:odd', 'value' => 1, 'icon' => 'info', 'tone' => 'neutral' ),
				array( 'label' => 'Total', 'value' => 3, 'icon' => 'users' ),
			),
			$cards
		);
	}

	public function test_render_submissions_for_a_viewer_hides_bulk_actions_and_lists_rows(): void {
		$this->campaignRepository()->shouldReceive( 'get_by_id' )->andReturn( $this->campaign() );
		$reader = $this->submissionReader();
		$reader->shouldReceive( 'get_by_reregistration' )->once()->with( 7, array() )->andReturn(
			array(
				(object) array(
					'id'           => 31,
					'status'       => 'approved',
					'user_name'    => 'Maria',
					'user_email'   => 'maria@example.org',
					'submitted_at' => 1767225600,
					'reviewed_at'  => null,
					'notes'        => '',
				),
			)
		);
		$reader->shouldReceive( 'get_statistics' )->andReturn( array( 'total' => 1 ) );
		Mockery::mock( 'alias:FreeFormCertificate\Admin\AdminUI' )->shouldReceive( 'get_stat_card' )->andReturn( '' );

		$html = $this->capture( fn () => ReregistrationAdminRenderer::render_submissions( 'ffc-reregistration', 7, false ) );

		$this->assertStringNotContainsString( 'name="bulk_action"', $html );
		$this->assertStringNotContainsString( 'maria@example.org', $html, 'The list never prints the email in clear (#1815).' );
		$this->assertStringContainsString( 'm***@example.org', $html );
		$this->assertStringNotContainsString( 'return_to_draft', $html, 'A viewer cannot send it back.' );
		$this->assertStringNotContainsString( 'ffc-record-btn', $html, 'A viewer the Record endpoint refuses is not offered the button (#1815).' );
	}

	// ==================================================================
	// render_submission_row() — PII tiers (#1815)
	// ==================================================================

	private function piiRow(): object {
		return (object) array(
			'id'           => 31,
			'user_id'      => 42,
			'status'       => 'approved',
			'user_name'    => 'Maria',
			'user_email'   => 'maria@example.org',
			'submitted_at' => 1767225600,
			'reviewed_at'  => null,
			'notes'        => '',
		);
	}

	public function test_masked_tier_sees_the_masked_email_without_reveal_or_details(): void {
		$this->submissionReader();

		$html = $this->capture( fn () => ReregistrationAdminRenderer::render_submission_row( 'ffc-reregistration', $this->piiRow(), 7, false ) );

		$this->assertStringNotContainsString( 'maria@example.org', $html );
		$this->assertStringContainsString( '<span class="ffc-pii-value" data-field="email">m***@example.org</span>', $html );
		$this->assertStringNotContainsString( 'ffc-reveal-pii', $html );
		$this->assertStringNotContainsString( 'ffc-view-details-btn', $html, 'A viewer who cannot open the details is not offered them.' );
	}

	public function test_reveal_tier_gets_the_masked_email_a_reveal_button_and_the_details(): void {
		$this->submissionReader();
		Functions\when( 'user_can' )->alias( static fn ( $uid, $cap ) => 'ffc_view_reregistration_pii' === $cap );

		$html = $this->capture( fn () => ReregistrationAdminRenderer::render_submission_row( 'ffc-reregistration', $this->piiRow(), 7, false ) );

		$this->assertStringNotContainsString( 'maria@example.org', $html );
		$this->assertStringContainsString( 'm***@example.org', $html );
		$this->assertStringContainsString(
			'<button type="button" class="button button-small ffc-reveal-pii ffc-icon-eye" data-type="reregistration" data-field="email" data-submission-id="31" data-nonce="nonce:ffc_reveal_pii_nonce">Reveal</button>',
			$html
		);
		$this->assertStringContainsString( 'ffc-view-details-btn', $html );
	}

	public function test_unmasked_tier_still_gets_a_masked_email_with_reveal(): void {
		$this->submissionReader();
		Functions\when( 'get_user_by' )->justReturn( (object) array( 'roles' => array( 'ffc_reregistration_admin' ) ) );

		$html = $this->capture( fn () => ReregistrationAdminRenderer::render_submission_row( 'ffc-reregistration', $this->piiRow(), 7, true ) );

		$this->assertStringNotContainsString( 'maria@example.org', $html, 'Even the unmasked tier fetches the clear value through the audited endpoint.' );
		$this->assertStringContainsString( 'm***@example.org', $html );
		$this->assertStringContainsString( 'data-type="reregistration"', $html );
		$this->assertStringContainsString( 'ffc-view-details-btn', $html );
	}

	public function test_ffc_administrator_is_the_reveal_tier_as_in_the_other_domains(): void {
		$this->submissionReader();
		Functions\when( 'get_user_by' )->justReturn( (object) array( 'roles' => array( 'ffc_administrator' ) ) );
		Functions\when( 'user_can' )->alias( static fn ( $uid, $cap ) => 'manage_options' !== $cap );

		$html = $this->capture( fn () => ReregistrationAdminRenderer::render_submission_row( 'ffc-reregistration', $this->piiRow(), 7, true ) );

		$this->assertStringNotContainsString( 'maria@example.org', $html );
		$this->assertStringContainsString( 'ffc-reveal-pii', $html );
		$this->assertStringContainsString( 'ffc-view-details-btn', $html );
	}

	public function test_the_submitter_viewing_their_own_row_is_the_reveal_tier(): void {
		$this->submissionReader();
		Functions\when( 'get_current_user_id' )->justReturn( 42 );

		$html = $this->capture( fn () => ReregistrationAdminRenderer::render_submission_row( 'ffc-reregistration', $this->piiRow(), 7, false ) );

		$this->assertStringContainsString( 'ffc-reveal-pii', $html );
		$this->assertStringNotContainsString( 'maria@example.org', $html );
	}

	public function test_a_value_the_masker_does_not_recognise_is_hidden_whole(): void {
		$this->submissionReader();
		$row             = $this->piiRow();
		$row->user_email = 'not-an-address';

		$html = $this->capture( fn () => ReregistrationAdminRenderer::render_submission_row( 'ffc-reregistration', $row, 7, false ) );

		$this->assertStringNotContainsString( 'not-an-address', $html );
		$this->assertStringContainsString( '<span class="ffc-pii-value" data-field="email">***</span>', $html );
	}

	public function test_a_row_without_an_email_shows_a_dash_and_no_reveal(): void {
		$this->submissionReader();
		Functions\when( 'user_can' )->justReturn( true );
		$row             = $this->piiRow();
		$row->user_email = null;

		$html = $this->capture( fn () => ReregistrationAdminRenderer::render_submission_row( 'ffc-reregistration', $row, 7, false ) );

		$this->assertStringNotContainsString( 'ffc-pii-value', $html );
		$this->assertStringNotContainsString( 'ffc-reveal-pii', $html );
	}

	public function test_the_record_button_is_drawn_for_a_manager(): void {
		$this->submissionReader();
		Functions\when( 'current_user_can' )->alias( static fn ( $cap ) => 'ffc_manage_reregistration' === $cap );

		$html = $this->capture( fn () => ReregistrationAdminRenderer::render_submission_row( 'ffc-reregistration', $this->piiRow(), 7, true ) );

		$this->assertStringContainsString( 'ffc-record-btn', $html );
	}

	public function test_the_record_button_is_not_drawn_for_a_view_only_user(): void {
		$this->submissionReader();
		Functions\when( 'current_user_can' )->alias( static fn ( $cap ) => 'ffc_view_reregistration' === $cap );

		$html = $this->capture( fn () => ReregistrationAdminRenderer::render_submission_row( 'ffc-reregistration', $this->piiRow(), 7, false ) );

		$this->assertStringNotContainsString( 'ffc-record-btn', $html );
	}

	public function test_render_submission_row_for_a_submitted_entry_offers_review_actions(): void {
		$this->submissionReader();
		$sub = (object) array(
			'id'           => 12,
			'status'       => 'submitted',
			'submitted_at' => 1767225600,
			'reviewed_at'  => 1767312000,
		);

		$html = $this->capture( fn () => ReregistrationAdminRenderer::render_submission_row( 'ffc-reregistration', $sub, 7, true ) );

		$this->assertStringContainsString( 'action=approve&sub_id=12&id=7&_wpnonce=approve_submission_12', $html );
		$this->assertStringContainsString( 'action=reject&sub_id=12&id=7&_wpnonce=reject_submission_12', $html );
		$this->assertStringContainsString( 'action=return_to_draft&sub_id=12&id=7&_wpnonce=return_to_draft_submission_12', $html );
		$this->assertStringContainsString( '2026-01-01 00:00', $html );
		$this->assertStringContainsString( '2026-01-02 00:00', $html );
	}

	public function test_render_submission_row_uses_a_dash_for_missing_or_unformattable_dates(): void {
		$this->submissionReader();
		$sub = (object) array(
			'id'           => 13,
			'status'       => 'pending',
			'user_name'    => 'Ana',
			'user_email'   => 'ana@example.org',
			'submitted_at' => 1,
			'reviewed_at'  => 1,
		);

		$html = $this->capture( fn () => ReregistrationAdminRenderer::render_submission_row( 'ffc-reregistration', $sub, 7, true ) );

		$this->assertSame( 2, substr_count( $html, '<td>—</td>' ), 'A date the formatter cannot render reads as a dash.' );
		$this->assertStringContainsString( 'Awaiting user', $html );
		$this->assertStringNotContainsString( 'return_to_draft', $html, 'A pending entry has nothing to send back.' );
	}
}
