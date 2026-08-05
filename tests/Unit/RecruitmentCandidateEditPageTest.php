<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Recruitment\RecruitmentCandidateEditPage;

/**
 * Render-path tests for RecruitmentCandidateEditPage — the screen body
 * render() plus its five section renderers, none of which the handler /
 * smoke suites exercise. Each section's markup now lives in a
 * templates/admin/recruitment/candidate-edit/*.php partial; these tests pin
 * the composed output (section headings, form field names, nonce field,
 * classification + call + history table cells) so the extraction stays
 * behavior-preserving and the render path contributes real coverage.
 *
 * @covers \FreeFormCertificate\Recruitment\RecruitmentCandidateEditPage
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class RecruitmentCandidateEditPageTest extends TestCase {

	use MockeryPHPUnitIntegration;

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

		// pcov attributes coverage only to classes loaded before the test
		// method body runs — preload the SUT here so the render path counts.
		class_exists( '\FreeFormCertificate\Recruitment\RecruitmentCandidateEditPage' );

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_attr__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'esc_textarea' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'absint' )->alias( static fn ( $v ) => (int) $v );
		Functions\when( 'admin_url' )->returnArg();
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'add_query_arg' )->alias(
			static fn ( $args ) => 'admin.php?' . http_build_query( (array) $args )
		);
		Functions\when( 'wp_nonce_field' )->alias(
			static function ( $action = -1 ) {
				echo '<input type="hidden" name="_wpnonce" value="' . (string) $action . '" />';
			}
		);
		Functions\when( 'submit_button' )->alias(
			static function ( $text = '' ) {
				echo '<button type="submit">' . (string) $text . '</button>';
			}
		);
		Functions\when( 'wp_json_encode' )->alias(
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- unit-test stub for wp_json_encode; not runtime code.
			static fn ( $data ) => (string) \json_encode( $data )
		);
		Functions\when( 'wp_die' )->alias(
			static function ( $msg = '' ) {
				throw new \RuntimeException( 'WP_DIE:' . $msg );
			}
		);

		// RecruitmentPiiAccessPolicy — resolved once per section to pick the
		// PII tier. Default to unmasked so the General email field is editable
		// and the sensitive rows take the immediate-decrypt branch.
		Mockery::getConfiguration()->setConstantsMap(
			array(
				'FreeFormCertificate\Recruitment\RecruitmentPiiAccessPolicy' => array(
					'TIER_UNMASKED' => 'unmasked',
					'TIER_REVEAL'   => 'reveal',
					'TIER_MASKED'   => 'masked',
				),
			)
		);
		$pii = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentPiiAccessPolicy' );
		$pii->shouldReceive( 'resolve' )->andReturn( 'unmasked' )->byDefault();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		unset( $_GET['candidate_id'] );
		parent::tearDown();
	}

	/**
	 * Buffer the output of a render call.
	 *
	 * @param callable $fn Render invocation.
	 * @return string Captured HTML.
	 */
	private function capture_output( callable $fn ): string {
		ob_start();
		try {
			$fn();
		} finally {
			$out = (string) ob_get_clean();
		}
		return $out;
	}

	/**
	 * A minimal candidate row with no PII / no timestamps, so the sensitive
	 * rows collapse to the em-dash placeholder and build_delete_consequences
	 * touches neither DateFormatter nor get_userdata.
	 *
	 * @param array<string, mixed> $overrides Field overrides.
	 * @return object
	 */
	private function candidate( array $overrides = array() ): object {
		return (object) array_merge(
			array(
				'id'              => 5,
				'name'            => 'Jane Doe',
				'phone'           => null,
				'notes'           => null,
				'email_encrypted' => '',
				'cpf_encrypted'   => '',
				'rf_encrypted'    => '',
				'user_id'         => null,
				'created_at'      => '',
				'updated_at'      => '',
			),
			$overrides
		);
	}

	public function test_render_dies_when_cap_missing(): void {
		Functions\when( 'current_user_can' )->justReturn( false );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'WP_DIE:' );

		RecruitmentCandidateEditPage::render();
	}

	public function test_render_shows_not_found_notice_for_unknown_candidate(): void {
		$_GET['candidate_id'] = '7';

		$reader = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentCandidateReader' );
		$reader->shouldReceive( 'get_by_id' )->with( 7 )->andReturnNull();

		$html = $this->capture_output( array( RecruitmentCandidateEditPage::class, 'render' ) );

		$this->assertStringContainsString( 'Candidate not found.', $html );
		$this->assertStringContainsString( 'Back to Candidates', $html );
	}

	public function test_render_outputs_every_section_for_empty_candidate(): void {
		$_GET['candidate_id'] = '5';

		$reader = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentCandidateReader' );
		$reader->shouldReceive( 'get_by_id' )->andReturn( $this->candidate() );

		$class = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentClassificationRepository' );
		$class->shouldReceive( 'get_for_candidate' )->andReturn( array() );
		$class->shouldReceive( 'count_for_candidate' )->andReturn( 0 );

		$history = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentCandidateHistoryService' );
		$history->shouldReceive( 'get_for_candidate' )->andReturn( array() );

		$html = $this->capture_output( array( RecruitmentCandidateEditPage::class, 'render' ) );

		// Screen header + the five section postboxes.
		$this->assertStringContainsString( 'Edit candidate — Jane Doe', $html );
		$this->assertStringContainsString( 'General', $html );
		$this->assertStringContainsString( 'Sensitive data (admin only)', $html );
		$this->assertStringContainsString( 'Classifications + call history', $html );
		$this->assertStringContainsString( 'History', $html );
		$this->assertStringContainsString( 'Hard-delete candidate', $html );

		// General form fields + nonce.
		$this->assertStringContainsString( 'name="name"', $html );
		$this->assertStringContainsString( 'name="email"', $html );
		$this->assertStringContainsString( 'name="phone"', $html );
		$this->assertStringContainsString( 'name="notes"', $html );
		$this->assertStringContainsString( 'name="_wpnonce"', $html );

		// Empty-state copy for the two read-only feeds.
		$this->assertStringContainsString( '(no classifications)', $html );
		$this->assertStringContainsString( '(no activity recorded for this candidate)', $html );

		// Delete section is enabled (0 classifications ⇒ not blocked).
		$this->assertStringContainsString( 'Delete permanently', $html );
		$this->assertStringNotContainsString( 'Blocked:', $html );
	}

	public function test_render_populates_classification_and_history_tables(): void {
		$_GET['candidate_id'] = '5';

		$reader = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentCandidateReader' );
		$reader->shouldReceive( 'get_by_id' )->andReturn( $this->candidate() );

		$classification = (object) array(
			'id'           => 10,
			'notice_id'    => 3,
			'adjutancy_id' => 7,
			'list_type'    => 'geral',
			'rank'         => 1,
			'score'        => '9.5',
			'status'       => 'called',
		);

		$class = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentClassificationRepository' );
		$class->shouldReceive( 'get_for_candidate' )->andReturn( array( $classification ) );
		// > 0 ⇒ the delete section renders its blocked branch.
		$class->shouldReceive( 'count_for_candidate' )->andReturn( 1 );

		$call = (object) array(
			'classification_id' => 10,
			'called_at'         => 1700000000,
			'date_to_assume'    => '2026-05-20',
			'time_to_assume'    => '09:00:00',
			'out_of_order'      => '1',
			'cancelled_at'      => null,
			'notes'             => 'a note',
		);
		$calls = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentCallReader' );
		$calls->shouldReceive( 'get_history_for_classifications' )->with( array( 10 ) )->andReturn( array( $call ) );

		$junction = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentNoticeAdjutancyRepository' );
		$junction->shouldReceive( 'get_adjutancy_ids_for_notice' )->with( 3 )->andReturn( array( 7, 8 ) );

		$notice = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentNoticeReader' );
		$notice->shouldReceive( 'get_by_id' )->with( 3 )->andReturn( (object) array( 'code' => 'ED-01' ) );

		$adjutancy = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentAdjutancyReader' );
		$adjutancy->shouldReceive( 'get_by_id' )->with( 7 )->andReturn( (object) array( 'slug' => 'mat' ) );
		$adjutancy->shouldReceive( 'get_by_id' )->with( 8 )->andReturn( (object) array( 'slug' => 'hist' ) );

		// DateFormatter is used for the call's called_at + the history "when".
		$fmt = Mockery::mock( 'alias:FreeFormCertificate\Core\DateFormatter' );
		$fmt->shouldReceive( 'format_datetime' )->andReturnUsing( static fn ( $v ) => (string) $v );

		$history = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentCandidateHistoryService' );
		$history->shouldReceive( 'get_for_candidate' )->andReturn(
			array(
				array(
					'action'     => 'called',
					'context'    => array(),
					'created_at' => '2026-05-10 12:00:00',
					'user_id'    => 0,
				),
			)
		);
		$history->shouldReceive( 'summarize_event' )->with( 'called', array() )->andReturn( '<em>evt</em>' );

		$html = $this->capture_output( array( RecruitmentCandidateEditPage::class, 'render' ) );

		// Classification summary row.
		$this->assertStringContainsString( 'ED-01', $html );
		$this->assertStringContainsString( 'ffc-status-called', $html );
		// Adjutancy <select> branch (notice has ≥2 attached adjutancies).
		$this->assertStringContainsString( 'ffc-adjutancy-swap-select', $html );
		$this->assertStringContainsString( '>mat<', $html );
		$this->assertStringContainsString( '>hist<', $html );
		// Per-classification calls sub-table.
		$this->assertStringContainsString( 'Calls for classification #10', $html );
		$this->assertStringContainsString( '2026-05-20', $html );
		// History table row: actor resolves to System (user_id 0) + pre-escaped event.
		$this->assertStringContainsString( 'System', $html );
		$this->assertStringContainsString( '<em>evt</em>', $html );
		// Delete blocked branch (count_for_candidate > 0).
		$this->assertStringContainsString( 'Blocked:', $html );
	}
}
