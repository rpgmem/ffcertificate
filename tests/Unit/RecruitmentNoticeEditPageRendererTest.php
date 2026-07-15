<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Recruitment\RecruitmentNoticeEditPageRenderer;

/**
 * Tests for RecruitmentNoticeEditPageRenderer.
 *
 * Two layers:
 *  - compute_empties_by_adjutancy() — the authoritative out-of-order map that
 *    seeds the client justification gate from the full (unfiltered/unpaginated)
 *    definitive queue (#Item7).
 *  - render_* smoke tests — each public static section renderer is driven
 *    through ob_start()/ob_get_clean() with a stdClass notice fixture and the
 *    data collaborators (readers / repositories / badge helpers) alias-mocked,
 *    so the data-prep private helpers and the included templates execute. We
 *    assert the captured markup contains a handful of load-bearing substrings.
 *
 * @covers \FreeFormCertificate\Recruitment\RecruitmentNoticeEditPageRenderer
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class RecruitmentNoticeEditPageRendererTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		class_exists( '\\FreeFormCertificate\\Recruitment\\RecruitmentNoticeEditPageRenderer' );

		$this->stub_wp_functions();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Stub the WordPress i18n / escape / form helpers the renderer and its
	 * templates lean on. Escapers + i18n return their argument; the markup
	 * helpers echo or return a tiny recognizable token.
	 */
	private function stub_wp_functions(): void {
		foreach ( array( '__', 'esc_html', 'esc_attr', 'esc_textarea', 'wp_kses_post', 'sanitize_key', 'esc_html__', 'esc_attr__', 'number_format_i18n' ) as $fn ) {
			Functions\when( $fn )->returnArg();
		}

		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'esc_html_e' )->alias( static fn( $s ) => print $s );
		Functions\when( 'esc_attr_e' )->alias( static fn( $s ) => print $s );

		Functions\when( 'selected' )->justReturn( '' );
		Functions\when( 'checked' )->justReturn( '' );
		Functions\when( 'disabled' )->justReturn( '' );

		Functions\when( 'admin_url' )->alias( static fn( $p = '' ) => 'https://example.com/wp-admin/' . $p );
		Functions\when( 'add_query_arg' )->justReturn( 'https://example.com/wp-admin/admin.php?args' );
		Functions\when( 'remove_query_arg' )->justReturn( 'https://example.com/wp-admin/admin.php' );
		Functions\when( 'wp_nonce_url' )->justReturn( 'https://example.com/wp-admin/admin-post.php?nonced' );
		Functions\when( 'wp_nonce_field' )->alias( static fn() => print '<input type="hidden" name="_wpnonce" value="x">' );
		Functions\when( 'submit_button' )->alias( static fn( $text = '' ) => print '<button type="submit">' . $text . '</button>' );
		Functions\when( 'wp_json_encode' )->alias( static fn( $v ) => json_encode( $v ) );
		Functions\when( '_n' )->alias( static fn( $single, $plural, $n ) => $n === 1 ? $single : $plural );
		Functions\when( 'paginate_links' )->justReturn( '<a class="page-numbers">2</a>' );
	}

	/**
	 * Declare stub classes for the two retired-façade names the notice-edit
	 * templates still reference (RecruitmentNoticeRepository::DEFAULT_…,
	 * RecruitmentAdjutancyRepository::get_by_id). These names no longer exist
	 * in includes/ — see the production-issue note in the test for
	 * render_general_section. Declared here (guarded) so the included
	 * templates can execute under test.
	 */
	private function declare_template_facade_stubs(): void {
		if ( ! class_exists( '\\FreeFormCertificate\\Recruitment\\RecruitmentNoticeRepository', false ) ) {
			eval(
				'namespace FreeFormCertificate\Recruitment;'
				. ' class RecruitmentNoticeRepository {'
				. ' const DEFAULT_PUBLIC_COLUMNS_CONFIG = \'{"rank":true,"name":true,"adjutancy":true,"status":true,"pcd_badge":true,"score":false,"preview_reason":false}\';'
				. ' }'
			);
		}
		if ( ! class_exists( '\\FreeFormCertificate\\Recruitment\\RecruitmentAdjutancyRepository', false ) ) {
			eval(
				'namespace FreeFormCertificate\Recruitment;'
				. ' class RecruitmentAdjutancyRepository {'
				. ' public static function get_by_id( $id ) {'
				. ' return (object) array( "id" => (int) $id, "slug" => "adj-" . (int) $id, "name" => "Adjutancy " . (int) $id );'
				. ' } }'
			);
		}
	}

	/**
	 * Declare a stub `RecruitmentAdminPage` carrying the PAGE_SLUG constant
	 * (used by the filter-form template) plus pure badge helpers. Declared
	 * (not alias-mocked) because Mockery alias mocks can't supply the
	 * constant. Declared before the renderer autoloads the real one, so this
	 * wins in the isolated process.
	 */
	private function declare_admin_page_stub(): void {
		if ( ! class_exists( '\\FreeFormCertificate\\Recruitment\\RecruitmentAdminPage', false ) ) {
			eval(
				'namespace FreeFormCertificate\Recruitment;'
				. ' class RecruitmentAdminPage {'
				. ' const PAGE_SLUG = "ffc-recruitment";'
				. ' public static function notice_status_badge( $s ) { return "<span class=\"notice-badge\">" . $s . "</span>"; }'
				. ' public static function classification_status_badge( $s ) { return "<span class=\"status-badge\">" . $s . "</span>"; }'
				. ' public static function adjutancy_badge( $a ) { return "<span class=\"adj-badge\"></span>"; }'
				. ' }'
			);
		}
	}

	private function notice( array $overrides = array() ): object {
		return (object) array_merge(
			array(
				'id'                    => 7,
				'code'                  => 'EDITAL-001',
				'name'                  => 'Test Notice',
				'status'                => 'preliminary',
				'was_reopened'          => '0',
				'public_columns_config' => '{"rank":true,"name":true,"score":true}',
			),
			$overrides
		);
	}

	// ------------------------------------------------------------------
	// compute_empties_by_adjutancy() — original coverage
	// ------------------------------------------------------------------

	/**
	 * @param array<int, object> $rows
	 * @return array<string, array<int, array{id:int, rank:int}>>
	 */
	private function invoke( array $rows ): array {
		$ref = new \ReflectionMethod( RecruitmentNoticeEditPageRenderer::class, 'compute_empties_by_adjutancy' );
		$ref->setAccessible( true );
		/** @var array<string, array<int, array{id:int, rank:int}>> $out */
		$out = $ref->invoke( null, $rows );
		return $out;
	}

	private function row( int $id, int $rank, string $status, int $adjutancy_id ): object {
		return (object) array(
			'id'           => $id,
			'rank'         => $rank,
			'status'       => $status,
			'adjutancy_id' => $adjutancy_id,
		);
	}

	public function test_groups_empties_by_adjutancy_slug_sorted_by_rank_excluding_non_empty(): void {
		$adj = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentAdjutancyReader' );
		$adj->shouldReceive( 'get_by_id' )->andReturnUsing(
			static fn( $id ) => (object) array( 'slug' => 'adj-' . (int) $id )
		);

		$rows = array(
			$this->row( 10, 3, 'empty', 1 ),
			$this->row( 11, 1, 'empty', 1 ),
			$this->row( 12, 2, 'called', 1 ), // not empty → excluded.
			$this->row( 13, 5, 'hired', 1 ),  // not empty → excluded.
			$this->row( 14, 1, 'empty', 2 ),
			$this->row( 15, 4, 'empty', 2 ),
		);

		$map = $this->invoke( $rows );

		$this->assertSame(
			array(
				'adj-1' => array(
					array( 'id' => 11, 'rank' => 1 ),
					array( 'id' => 10, 'rank' => 3 ),
				),
				'adj-2' => array(
					array( 'id' => 14, 'rank' => 1 ),
					array( 'id' => 15, 'rank' => 4 ),
				),
			),
			$map
		);
	}

	public function test_returns_empty_map_when_no_empty_rows(): void {
		$rows = array(
			$this->row( 1, 1, 'called', 1 ),
			$this->row( 2, 2, 'hired', 1 ),
		);

		$this->assertSame( array(), $this->invoke( $rows ) );
	}

	public function test_falls_back_to_hashed_id_key_when_adjutancy_slug_missing(): void {
		$adj = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentAdjutancyReader' );
		$adj->shouldReceive( 'get_by_id' )->andReturn( null );

		$map = $this->invoke( array( $this->row( 99, 1, 'empty', 7 ) ) );

		$this->assertSame(
			array( '#7' => array( array( 'id' => 99, 'rank' => 1 ) ) ),
			$map
		);
	}

	// ------------------------------------------------------------------
	// columns_label_map()
	// ------------------------------------------------------------------

	public function test_columns_label_map_has_every_supported_column(): void {
		$map = RecruitmentNoticeEditPageRenderer::columns_label_map();

		foreach ( array( 'rank', 'name', 'adjutancy', 'status', 'pcd_badge', 'score', 'preview_reason' ) as $key ) {
			$this->assertArrayHasKey( $key, $map );
		}
	}

	// ------------------------------------------------------------------
	// render_csv_import_section()
	// ------------------------------------------------------------------

	public function test_render_csv_import_section_preliminary_shows_import_form(): void {
		ob_start();
		RecruitmentNoticeEditPageRenderer::render_csv_import_section( $this->notice( array( 'status' => 'preliminary' ) ) );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'ffc-recruitment-edit-import', $html );
		$this->assertStringContainsString( 'name="csv_file"', $html );
		// preliminary status exposes the "definitive" radio path.
		$this->assertStringContainsString( 'value="definitive"', $html );
	}

	public function test_render_csv_import_section_definitive_disables_import(): void {
		ob_start();
		RecruitmentNoticeEditPageRenderer::render_csv_import_section( $this->notice( array( 'status' => 'definitive' ) ) );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Import is disabled', $html );
		$this->assertStringNotContainsString( 'name="csv_file"', $html );
	}

	// ------------------------------------------------------------------
	// render_general_section() — exercises render_columns_toggles + template
	// ------------------------------------------------------------------

	public function test_render_general_section_renders_code_name_and_toggles_grid(): void {
		$this->declare_template_facade_stubs();

		$ui = Mockery::mock( 'alias:FreeFormCertificate\Admin\AdminUI' );
		$ui->shouldReceive( 'get_toggle' )->andReturnUsing(
			static fn( $args ) => '<span class="ffc-toggle" data-name="' . $args['name'] . '"></span>'
		);
		$ui->shouldReceive( 'render_toggle' )->andReturnUsing(
			static fn( $args ) => print '<span class="ffc-toggle" data-name="' . $args['name'] . '"></span>'
		);

		ob_start();
		RecruitmentNoticeEditPageRenderer::render_general_section( $this->notice() );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'EDITAL-001', $html );
		$this->assertStringContainsString( 'name="name"', $html );
		$this->assertStringContainsString( 'ffc-recruitment-columns-toggles', $html );
		// mandatory columns render a hidden pin input.
		$this->assertStringContainsString( 'public_columns[rank]', $html );
		// the dedicated preview_reason row uses render_toggle.
		$this->assertStringContainsString( 'public_columns[preview_reason]', $html );
	}

	public function test_render_general_section_tolerates_invalid_columns_json(): void {
		$this->declare_template_facade_stubs();

		$ui = Mockery::mock( 'alias:FreeFormCertificate\Admin\AdminUI' );
		$ui->shouldReceive( 'get_toggle' )->andReturn( '<span class="ffc-toggle"></span>' );
		$ui->shouldReceive( 'render_toggle' )->andReturnUsing( static fn() => print '' );

		ob_start();
		RecruitmentNoticeEditPageRenderer::render_general_section( $this->notice( array( 'public_columns_config' => 'not-json' ) ) );
		$html = (string) ob_get_clean();

		// Defaults still produce the grid.
		$this->assertStringContainsString( 'ffc-recruitment-columns-toggles', $html );
	}

	// ------------------------------------------------------------------
	// render_status_section() — preliminary / definitive / closed branches
	// ------------------------------------------------------------------

	public function test_render_status_section_preliminary_renders_promote_options_no_definitive(): void {
		$cls = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentClassificationRepository' );
		$cls->shouldReceive( 'get_for_notice' )->with( 7, 'definitive' )->andReturn( array() );

		$page = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentAdminPage' );
		$page->shouldReceive( 'notice_status_badge' )->andReturn( '<span class="badge">preliminary</span>' );

		ob_start();
		RecruitmentNoticeEditPageRenderer::render_status_section( $this->notice( array( 'status' => 'preliminary' ) ) );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Status', $html );
		// dual-path snapshot button when no definitive list exists.
		$this->assertStringContainsString( 'ffcRecruitmentSnapshotPromote', $html );
		// resolve_status_transitions drops definitive → still offers back-to-draft.
		$this->assertStringContainsString( 'target_status', $html );
	}

	public function test_render_status_section_preliminary_with_existing_definitive_single_button(): void {
		$cls = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentClassificationRepository' );
		$cls->shouldReceive( 'get_for_notice' )->with( 7, 'definitive' )->andReturn(
			array( $this->row( 1, 1, 'empty', 1 ) )
		);

		$page = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentAdminPage' );
		$page->shouldReceive( 'notice_status_badge' )->andReturn( '<span class="badge">preliminary</span>' );

		ob_start();
		RecruitmentNoticeEditPageRenderer::render_status_section( $this->notice( array( 'status' => 'preliminary' ) ) );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'A definitive list already exists', $html );
		$this->assertStringNotContainsString( 'ffcRecruitmentSnapshotPromote', $html );
	}

	public function test_render_status_section_definitive_renders_close_transition(): void {
		$page = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentAdminPage' );
		$page->shouldReceive( 'notice_status_badge' )->andReturn( '<span class="badge">definitive</span>' );

		ob_start();
		RecruitmentNoticeEditPageRenderer::render_status_section( $this->notice( array( 'status' => 'definitive' ) ) );
		$html = (string) ob_get_clean();

		// definitive offers close + back-to-preliminary transition buttons.
		$this->assertStringContainsString( 'target_status', $html );
		$this->assertStringContainsString( 'ffc_recruitment_transition_notice', $html );
	}

	public function test_render_status_section_closed_renders_reopen_and_reopened_note(): void {
		$page = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentAdminPage' );
		$page->shouldReceive( 'notice_status_badge' )->andReturn( '<span class="badge">closed</span>' );

		ob_start();
		RecruitmentNoticeEditPageRenderer::render_status_section(
			$this->notice( array( 'status' => 'closed', 'was_reopened' => '1' ) )
		);
		$html = (string) ob_get_clean();

		// closed → definitive reopen transition, with reason_label data attr.
		$this->assertStringContainsString( 'data-ffc-confirm-reason-label', $html );
		// was_reopened note rendered.
		$this->assertStringContainsString( 'previously reopened', $html );
	}

	public function test_render_status_section_draft_renders_single_transition(): void {
		$page = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentAdminPage' );
		$page->shouldReceive( 'notice_status_badge' )->andReturn( '<span class="badge">draft</span>' );

		ob_start();
		RecruitmentNoticeEditPageRenderer::render_status_section( $this->notice( array( 'status' => 'draft' ) ) );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'target_status', $html );
	}

	// ------------------------------------------------------------------
	// render_adjutancies_section()
	// ------------------------------------------------------------------

	public function test_render_adjutancies_section_partitions_attached_and_detached(): void {
		$adj = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentAdjutancyReader' );
		$adj->shouldReceive( 'get_all' )->andReturn(
			array(
				(object) array( 'id' => 1, 'slug' => 'A1', 'name' => 'Alpha' ),
				(object) array( 'id' => 2, 'slug' => 'B2', 'name' => 'Bravo' ),
			)
		);

		$na = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentNoticeAdjutancyRepository' );
		$na->shouldReceive( 'get_adjutancy_ids_for_notice' )->with( 7 )->andReturn( array( 1 ) );

		ob_start();
		RecruitmentNoticeEditPageRenderer::render_adjutancies_section( $this->notice() );
		$html = (string) ob_get_clean();

		// attached pill for A1, detach control.
		$this->assertStringContainsString( 'ffc-attached', $html );
		$this->assertStringContainsString( 'A1', $html );
		// detached B2 surfaces in the attach <select>.
		$this->assertStringContainsString( 'B2', $html );
		$this->assertStringContainsString( 'ffcAttachAdjutancy', $html );
	}

	public function test_render_adjutancies_section_no_attached_shows_empty_note(): void {
		$adj = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentAdjutancyReader' );
		$adj->shouldReceive( 'get_all' )->andReturn(
			array( (object) array( 'id' => 1, 'slug' => 'A1', 'name' => 'Alpha' ) )
		);

		$na = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentNoticeAdjutancyRepository' );
		$na->shouldReceive( 'get_adjutancy_ids_for_notice' )->with( 7 )->andReturn( array() );

		ob_start();
		RecruitmentNoticeEditPageRenderer::render_adjutancies_section( $this->notice() );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'No adjutancies attached yet', $html );
	}

	// ------------------------------------------------------------------
	// render_classifications_section() — full data-prep + table render
	// ------------------------------------------------------------------

	/**
	 * Wire up all the collaborators the classifications section + table touch:
	 * classification repo (preview/definitive), filter manager, candidate
	 * reader, adjutancy reader, reason reader, pcd hasher, admin badges,
	 * subscription badge, and the attached-adjutancies repo. Returns nothing;
	 * the alias mocks live for the rest of the (isolated) process.
	 */
	private function wire_classifications_collaborators(
		array $preview_rows,
		array $definitive_rows
	): void {
		// Declare the stubs BEFORE any alias mock so the real classes don't
		// get autoloaded transitively (the renderer references them directly).
		$this->declare_admin_page_stub();
		$this->declare_template_facade_stubs();

		$cls = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentClassificationRepository' );
		$cls->shouldReceive( 'get_for_notice' )->with( 7, 'preview' )->andReturn( $preview_rows );
		$cls->shouldReceive( 'get_for_notice' )->with( 7, 'definitive' )->andReturn( $definitive_rows );

		$fm = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentClassificationFilterManager' );
		$fm->shouldReceive( 'read_filters' )->andReturn(
			array( 'adjutancy_id' => 0, 'query' => '', 'cpf' => '', 'rf' => '', 'subscription' => '' )
		);
		$fm->shouldReceive( 'apply_filters' )->andReturnUsing( static fn( $rows ) => $rows );

		$cand = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentCandidateReader' );
		$cand->shouldReceive( 'get_by_ids' )->andReturnUsing(
			static function ( $ids ) {
				$out = array();
				foreach ( (array) $ids as $id ) {
					$out[] = (object) array( 'id' => (int) $id, 'name' => 'Cand ' . (int) $id, 'pcd_hash' => '' );
				}
				return $out;
			}
		);

		$adj = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentAdjutancyReader' );
		$adj->shouldReceive( 'get_by_id' )->andReturnUsing(
			static fn( $id ) => (object) array( 'id' => (int) $id, 'slug' => 'adj-' . (int) $id, 'name' => 'Adj ' . (int) $id )
		);

		$reason = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentReasonReader' );
		$reason->shouldReceive( 'get_all' )->andReturn(
			array( (object) array( 'id' => 1, 'label' => 'Reason 1', 'applies_to' => '' ) )
		);
		$reason->shouldReceive( 'decode_applies_to' )->andReturn( array() );

		$pcd = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentPcdHasher' );
		$pcd->shouldReceive( 'verify' )->andReturn( false );

		$sc = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentPublicShortcodeRenderer' );
		$sc->shouldReceive( 'render_subscription_badge' )->andReturn( '<span class="sub-badge"></span>' );

		$na = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentNoticeAdjutancyRepository' );
		$na->shouldReceive( 'get_adjutancy_ids_for_notice' )->with( 7 )->andReturn( array() );
	}

	private function cls_row( int $id, int $rank, string $status, int $adjutancy_id, int $candidate_id ): object {
		return (object) array(
			'id'                => $id,
			'rank'              => $rank,
			'status'            => $status,
			'adjutancy_id'      => $adjutancy_id,
			'candidate_id'      => $candidate_id,
			'score'             => '100',
			'preview_status'    => 'empty',
			'preview_reason_id' => null,
		);
	}

	public function test_render_classifications_section_with_definitive_rows_renders_actions_table(): void {
		$this->wire_classifications_collaborators(
			array( $this->cls_row( 50, 1, 'empty', 1, 500 ) ),
			array(
				$this->cls_row( 60, 1, 'called', 1, 600 ),
				$this->cls_row( 61, 2, 'empty', 1, 601 ),
			)
		);

		ob_start();
		RecruitmentNoticeEditPageRenderer::render_classifications_section( $this->notice() );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Classifications', $html );
		// filter form.
		$this->assertStringContainsString( 'ffc-cls-filters', $html );
		// both tab panels present.
		$this->assertStringContainsString( 'data-ffc-clspanel="preliminary"', $html );
		$this->assertStringContainsString( 'data-ffc-clspanel="definitive"', $html );
		// definitive (with_actions) renders bulk toolbar + action buttons.
		$this->assertStringContainsString( 'ffc-cls-bulk-toolbar', $html );
		$this->assertStringContainsString( 'ffcRecruitmentClsAct', $html );
		// preview tab renders the editable preview-status select.
		$this->assertStringContainsString( 'ffc-cls-preview-status', $html );
		// empties map handed to JS on the definitive panel.
		$this->assertStringContainsString( 'data-ffc-empties', $html );
	}

	public function test_render_classifications_section_empty_lists_render_no_rows_notice(): void {
		$this->wire_classifications_collaborators( array(), array() );

		ob_start();
		RecruitmentNoticeEditPageRenderer::render_classifications_section( $this->notice() );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( '(no rows)', $html );
		// no definitive rows → default tab is preliminary.
		$this->assertStringContainsString( 'data-ffc-clspanel="preliminary"', $html );
	}
}
