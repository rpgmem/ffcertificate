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
 *    through ob_start()/ob_get_clean() with a stdClass notice fixture. The
 *    collaborators are the real classes (the recruitment readers and
 *    repositories, the filter manager, the PCD hasher, the admin badges,
 *    AdminUI), over a `$wpdb` double that answers from the rows each test
 *    stores and fails on any query it does not know, so the data-prep
 *    private helpers and the included templates execute. We assert the
 *    captured markup contains a handful of load-bearing substrings.
 *
 * @covers \FreeFormCertificate\Recruitment\RecruitmentNoticeEditPageRenderer
 */
class RecruitmentNoticeEditPageRendererTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var bool Whether `$wpdb` was set before this test. */
	private bool $had_wpdb = false;

	/** @var mixed The `$wpdb` global an earlier test left, restored afterwards. */
	private $wpdb_backup;

	/** @var array<string, mixed> `$_GET` before the test, restored afterwards. */
	private array $get_backup = array();

	/**
	 * Adjutancy rows the database holds, keyed by id.
	 *
	 * @var array<int, object>
	 */
	private array $adjutancies = array();

	/**
	 * Classification rows of notice 7, keyed by list_type.
	 *
	 * @var array<string, array<int, object>>
	 */
	private array $classifications = array();

	/**
	 * Adjutancy ids attached to notice 7.
	 *
	 * @var array<int, int>
	 */
	private array $attached = array();

	/**
	 * Candidate rows the database holds, keyed by id.
	 *
	 * @var array<int, object>
	 */
	private array $candidates = array();

	/**
	 * Reason rows the database holds.
	 *
	 * @var array<int, object>
	 */
	private array $reasons = array();

	/** @var list<object> Call rows the active-call lookup answers. */
	private array $calls = array();

	/** @var array<string, bool> Capabilities the user holds; every one unless a test says otherwise. */
	private array $caps = array();

	/**
	 * Every query the double answered, as [method, sql, bound args].
	 *
	 * @var list<array{0: string, 1: string, 2: array<int, mixed>}>
	 */
	private array $queries = array();

	/** @var array<int, mixed> Arguments of the last prepare(). */
	private array $bound = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->get_backup = $_GET ?? array();
		$_GET             = array();
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
		class_exists( '\\FreeFormCertificate\\Recruitment\\RecruitmentNoticeEditPageRenderer' );

		$this->stub_wp_functions();
		$this->install_wpdb();
	}

	protected function tearDown(): void {
		if ( $this->had_wpdb ) {
			$GLOBALS['wpdb'] = $this->wpdb_backup;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		$_GET = $this->get_backup;
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

		Functions\when( 'current_user_can' )->alias( fn ( $cap ) => $this->caps[ $cap ] ?? true );
		Functions\when( 'get_current_user_id' )->justReturn( 7 );

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
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();

		// The readers cache through the object cache: always a miss.
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		// No setting stored: every reader takes its default.
		Functions\when( 'get_option' )->alias( static fn( $key, $default = false ) => $default );
	}

	/**
	 * Install a `$wpdb` double answering the recruitment readers from the
	 * rows the test stored. A query it does not recognise fails the test.
	 */
	private function install_wpdb(): void {
		$this->adjutancies     = array();
		$this->classifications = array();
		$this->attached        = array();
		$this->candidates      = array();
		$this->reasons         = array();
		$this->queries         = array();

		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			function ( $sql, ...$args ) {
				// Some readers bind one array of arguments.
				$this->bound = ( 1 === count( $args ) && is_array( $args[0] ) ) ? array_values( $args[0] ) : $args;
				return (string) $sql;
			}
		);
		$wpdb->shouldReceive( 'get_row' )->andReturnUsing(
			function ( $sql ) {
				$this->queries[] = array( 'get_row', $sql, $this->bound );
				if ( 'SELECT * FROM %i WHERE id = %d' === $sql && 'wp_ffc_recruitment_adjutancy' === $this->bound[0] ) {
					return $this->adjutancies[ (int) $this->bound[1] ] ?? null;
				}
				throw new \LogicException( 'Unexpected get_row: ' . $sql );
			}
		);
		$wpdb->shouldReceive( 'get_results' )->andReturnUsing(
			function ( $sql ) {
				$this->queries[] = array( 'get_results', $sql, $this->bound );
				$table           = $this->bound[0];
				if ( 'wp_ffc_recruitment_adjutancy' === $table && str_contains( $sql, 'ORDER BY name ASC' ) ) {
					return array_values( $this->adjutancies );
				}
				if ( 'wp_ffc_recruitment_classification' === $table && str_contains( $sql, 'notice_id = %d AND list_type = %s' ) ) {
					return 7 === (int) $this->bound[1] ? ( $this->classifications[ (string) $this->bound[2] ] ?? array() ) : array();
				}
				if ( 'wp_ffc_recruitment_candidate' === $table && str_contains( $sql, 'WHERE id IN' ) ) {
					$ids = array_map( 'intval', array_slice( $this->bound, 1 ) );
					return array_values( array_intersect_key( $this->candidates, array_flip( $ids ) ) );
				}
				if ( 'wp_ffc_recruitment_reason' === $table && str_contains( $sql, 'ORDER BY label ASC' ) ) {
					return $this->reasons;
				}
				if ( 'wp_ffc_recruitment_call' === $table && str_contains( $sql, 'WHERE classification_id IN' ) ) {
					return $this->calls;
				}
				throw new \LogicException( 'Unexpected get_results: ' . $sql );
			}
		);
		$wpdb->shouldReceive( 'get_col' )->andReturnUsing(
			function ( $sql ) {
				$this->queries[] = array( 'get_col', $sql, $this->bound );
				if ( 'SELECT adjutancy_id FROM %i WHERE notice_id = %d' === $sql && 'wp_ffc_recruitment_notice_adjutancy' === $this->bound[0] ) {
					return 7 === (int) $this->bound[1] ? array_map( 'strval', $this->attached ) : array();
				}
				throw new \LogicException( 'Unexpected get_col: ' . $sql );
			}
		);

		$this->had_wpdb    = array_key_exists( 'wpdb', $GLOBALS );
		$this->wpdb_backup = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb']   = $wpdb;
	}

	/**
	 * Store an adjutancy row.
	 */
	private function store_adjutancy( int $id, string $slug, string $name ): void {
		$this->adjutancies[ $id ] = (object) array(
			'id'    => $id,
			'slug'  => $slug,
			'name'  => $name,
			'color' => '#123456',
		);
	}

	/**
	 * Which list_types of notice 7 were read, in order.
	 *
	 * @return list<string>
	 */
	private function classification_reads(): array {
		$out = array();
		foreach ( $this->queries as $q ) {
			if ( 'wp_ffc_recruitment_classification' === ( $q[2][0] ?? '' ) ) {
				$out[] = (string) $q[2][2];
			}
		}
		return $out;
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
		$this->store_adjutancy( 1, 'adj-1', 'Adjutancy 1' );
		$this->store_adjutancy( 2, 'adj-2', 'Adjutancy 2' );

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
		$this->assertSame( array(), $this->queries, 'no adjutancy is looked up without an empty row' );
	}

	public function test_falls_back_to_hashed_id_key_when_adjutancy_slug_missing(): void {
		// No adjutancy row 7 is stored: the reader finds nothing.
		$map = $this->invoke( array( $this->row( 99, 1, 'empty', 7 ) ) );

		$this->assertSame(
			array( '#7' => array( array( 'id' => 99, 'rank' => 1 ) ) ),
			$map
		);
		$this->assertCount( 1, $this->queries, 'the adjutancy was looked up' );
		$this->assertSame( array( 'wp_ffc_recruitment_adjutancy', 7 ), $this->queries[0][2] );
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
		// The real AdminUI drew the toggles and the one save button.
		$this->assertStringContainsString( 'ffc-control-toggle', $html );
		$this->assertSame( 1, substr_count( $html, '<p class="submit">' ), 'one save button' );
		$this->assertMatchesRegularExpression( '/<button[^>]*type="submit"[^>]*>/', $html );
		$this->assertStringContainsString( 'value="Save general"', $html );
	}

	public function test_render_general_section_tolerates_invalid_columns_json(): void {
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
		// No definitive rows stored for notice 7.
		ob_start();
		RecruitmentNoticeEditPageRenderer::render_status_section( $this->notice( array( 'status' => 'preliminary' ) ) );
		$html = (string) ob_get_clean();

		$this->assertSame( array( 'definitive' ), $this->classification_reads() );
		$this->assertStringContainsString( 'Status', $html );
		$this->assertStringContainsString( 'ffc-recruitment-status-preliminary', $html, 'the real status badge' );
		// dual-path snapshot button when no definitive list exists.
		$this->assertStringContainsString( 'ffcRecruitmentSnapshotPromote', $html );
		// No way back to draft (#2245): the only transition is the promotion,
		// which has its own dual-path block, so no plain transition button.
		$this->assertStringNotContainsString( 'target_status', $html );
		$this->assertStringNotContainsString( 'Back to draft', $html );
	}

	public function test_render_status_section_preliminary_with_existing_definitive_single_button(): void {
		$this->classifications['definitive'] = array( $this->cls_row( 1, 1, 'empty', 1, 100 ) );

		ob_start();
		RecruitmentNoticeEditPageRenderer::render_status_section( $this->notice( array( 'status' => 'preliminary' ) ) );
		$html = (string) ob_get_clean();

		$this->assertSame( array( 'definitive' ), $this->classification_reads() );
		$this->assertStringContainsString( 'A definitive list already exists', $html );
		$this->assertStringNotContainsString( 'ffcRecruitmentSnapshotPromote', $html );
	}

	public function test_render_status_section_definitive_renders_close_transition(): void {
		ob_start();
		RecruitmentNoticeEditPageRenderer::render_status_section( $this->notice( array( 'status' => 'definitive' ) ) );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'ffc-recruitment-status-definitive', $html, 'the real status badge' );
		// definitive offers close + back-to-preliminary transition buttons.
		$this->assertStringContainsString( 'target_status', $html );
		$this->assertStringContainsString( 'ffc_recruitment_transition_notice', $html );
		$this->assertSame( array(), $this->queries, 'only the preliminary state reads the definitive list' );
	}

	public function test_render_status_section_closed_renders_reopen_and_reopened_note(): void {
		ob_start();
		RecruitmentNoticeEditPageRenderer::render_status_section(
			$this->notice( array( 'status' => 'closed', 'was_reopened' => '1' ) )
		);
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'ffc-recruitment-status-closed', $html, 'the real status badge' );
		// closed → definitive reopen transition, with reason_label data attr.
		$this->assertStringContainsString( 'data-ffc-confirm-reason-label', $html );
		// was_reopened note rendered.
		$this->assertStringContainsString( 'previously reopened', $html );
	}

	public function test_render_status_section_draft_renders_single_transition(): void {
		ob_start();
		RecruitmentNoticeEditPageRenderer::render_status_section( $this->notice( array( 'status' => 'draft' ) ) );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'ffc-recruitment-status-draft', $html, 'the real status badge' );
		$this->assertStringContainsString( 'target_status', $html );
	}

	// ------------------------------------------------------------------
	// render_adjutancies_section()
	// ------------------------------------------------------------------

	public function test_render_adjutancies_section_partitions_attached_and_detached(): void {
		$this->store_adjutancy( 1, 'A1', 'Alpha' );
		$this->store_adjutancy( 2, 'B2', 'Bravo' );
		$this->attached = array( 1 );

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
		$this->store_adjutancy( 1, 'A1', 'Alpha' );

		ob_start();
		RecruitmentNoticeEditPageRenderer::render_adjutancies_section( $this->notice() );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'No adjutancies attached yet', $html );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function adjutancy_locked_statuses(): array {
		return array(
			'definitive' => array( 'definitive' ),
			'closed'     => array( 'closed' ),
		);
	}

	/**
	 * @dataProvider adjutancy_locked_statuses
	 */
	public function test_adjutancies_are_read_only_once_the_notice_is_past_preliminary( string $status ): void {
		$this->store_adjutancy( 1, 'A1', 'Alpha' );
		$this->store_adjutancy( 2, 'B2', 'Bravo' );
		$this->attached = array( 1 );

		ob_start();
		RecruitmentNoticeEditPageRenderer::render_adjutancies_section( $this->notice( array( 'status' => $status ) ) );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'A1', $html, 'The attached adjutancies still show.' );
		$this->assertStringNotContainsString( 'ffcDetachAdjutancy', $html );
		$this->assertStringNotContainsString( 'ffcAttachAdjutancy', $html );
		$this->assertStringContainsString( 'only while the notice is in draft or preliminary', $html );
	}

	public function test_a_draft_notice_takes_adjutancy_changes(): void {
		$this->store_adjutancy( 1, 'A1', 'Alpha' );
		$this->store_adjutancy( 2, 'B2', 'Bravo' );
		$this->attached = array( 1 );

		ob_start();
		RecruitmentNoticeEditPageRenderer::render_adjutancies_section( $this->notice( array( 'status' => 'draft' ) ) );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'ffcDetachAdjutancy', $html );
		$this->assertStringContainsString( 'ffcAttachAdjutancy', $html );
	}

	// ------------------------------------------------------------------
	// render_classifications_section() — full data-prep + table render
	// ------------------------------------------------------------------

	/**
	 * Store the rows the classifications section + table read: the preview
	 * and definitive lists of notice 7, a candidate per row, an adjutancy
	 * per row and one reason. No filter is in the request, so the real
	 * filter manager passes every row through.
	 *
	 * @param array<int, object> $preview_rows    Preview list.
	 * @param array<int, object> $definitive_rows Definitive list.
	 */
	private function store_classifications( array $preview_rows, array $definitive_rows ): void {
		$this->classifications = array(
			'preview'    => $preview_rows,
			'definitive' => $definitive_rows,
		);
		foreach ( array_merge( $preview_rows, $definitive_rows ) as $row ) {
			$cid                      = (int) $row->candidate_id;
			$this->candidates[ $cid ] = (object) array( 'id' => $cid, 'name' => 'Cand ' . $cid, 'pcd_hash' => '' );
			$aid                      = (int) $row->adjutancy_id;
			$this->store_adjutancy( $aid, 'adj-' . $aid, 'Adj ' . $aid );
		}
		$this->reasons = array( (object) array( 'id' => 1, 'label' => 'Reason 1', 'applies_to' => '' ) );
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
		$this->store_classifications(
			array( $this->cls_row( 50, 1, 'empty', 1, 500 ) ),
			array(
				$this->cls_row( 60, 1, 'called', 1, 600 ),
				$this->cls_row( 61, 2, 'empty', 1, 601 ),
			)
		);

		ob_start();
		RecruitmentNoticeEditPageRenderer::render_classifications_section( $this->notice( array( 'status' => 'definitive' ) ) );
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
		// The candidates came from the store, through the real reader.
		$this->assertStringContainsString( 'Cand 600', $html );
		$this->assertStringContainsString( 'Cand 500', $html );
		$this->assertStringContainsString( 'Reason 1', $html );
	}

	public function test_flags_a_candidate_with_no_email_on_the_definitive_list_only(): void {
		$this->store_classifications(
			array( $this->cls_row( 50, 1, 'empty', 1, 500 ) ),
			array(
				$this->cls_row( 60, 1, 'empty', 1, 600 ),
				$this->cls_row( 61, 2, 'empty', 1, 601 ),
			)
		);
		$this->candidates[601]->email_hash = 'hash-601';

		ob_start();
		RecruitmentNoticeEditPageRenderer::render_classifications_section( $this->notice( array( 'status' => 'definitive' ) ) );
		$html = (string) ob_get_clean();

		$this->assertSame( 1, substr_count( $html, 'No email — contact manually' ), 'Only the definitive row whose candidate has no address is flagged; the preview list takes no calls (#2245).' );
		$this->assertMatchesRegularExpression( '/Cand 600 <span class="ffc-icon-warning ffc-icon-tone-warning ffc-rec-ml-half">No email/', $html );
	}

	/**
	 * @return array<string, array{0: string, 1: bool, 2: bool}>
	 */
	public static function notice_states(): array {
		// status => [ preliminary statuses editable, definitive actions shown ].
		return array(
			'draft'       => array( 'draft', false, false ),
			'preliminary' => array( 'preliminary', true, false ),
			'definitive'  => array( 'definitive', false, true ),
			'closed'      => array( 'closed', false, false ),
		);
	}

	/**
	 * @dataProvider notice_states
	 */
	public function test_the_lists_offer_only_what_the_notice_status_allows( string $status, bool $marks, bool $calls ): void {
		$this->store_classifications(
			array( $this->cls_row( 50, 1, 'empty', 1, 500 ) ),
			array( $this->cls_row( 60, 1, 'hired', 1, 600 ) )
		);

		ob_start();
		RecruitmentNoticeEditPageRenderer::render_classifications_section( $this->notice( array( 'status' => $status ) ) );
		$html = (string) ob_get_clean();

		$this->assertSame( $marks, 1 === preg_match( '/<select class="ffc-cls-preview-status" data-cls-id="50">/', $html ), 'Preliminary statuses change only while the notice is preliminary (#2245).' );
		$this->assertSame( $calls, str_contains( $html, 'ffcRecruitmentClsAct' ), 'Call, outcomes, reopen and undo only on a definitive notice.' );
	}

	public function test_cancel_names_the_rows_active_call(): void {
		$this->store_classifications( array(), array( $this->cls_row( 60, 1, 'called', 1, 600 ) ) );
		$this->calls = array(
			(object) array(
				'id'                => '91',
				'classification_id' => '60',
				'cancelled_at'      => null,
				'created_by'        => '3',
			),
			(object) array(
				'id'                => '90',
				'classification_id' => '60',
				'cancelled_at'      => '1777968000',
				'created_by'        => '3',
			),
		);

		ob_start();
		RecruitmentNoticeEditPageRenderer::render_classifications_section( $this->notice( array( 'status' => 'definitive' ) ) );
		$html = (string) ob_get_clean();

		$this->assertMatchesRegularExpression( '/data-cls-action="cancel" data-call-id="91"/', $html );
		$this->assertStringNotContainsString( 'data-call-id="90"', $html, 'A cancelled call is never the one to end.' );
	}

	/**
	 * An Operator: view and call, no manage.
	 */
	private function as_operator(): void {
		$this->caps = array(
			'manage_options'         => false,
			'ffc_manage_recruitment' => false,
			'ffc_view_recruitment'   => true,
			'ffc_call_recruitment'   => true,
		);
	}

	public function test_an_operator_records_outcomes_and_ends_only_their_own_call(): void {
		$this->as_operator();
		$this->store_classifications(
			array( $this->cls_row( 50, 1, 'empty', 1, 500 ) ),
			array(
				$this->cls_row( 60, 1, 'called', 1, 600 ),
				$this->cls_row( 61, 2, 'called', 1, 601 ),
				$this->cls_row( 62, 3, 'hired', 1, 602 ),
			)
		);
		$this->calls = array(
			(object) array(
				'id'                => '91',
				'classification_id' => '60',
				'cancelled_at'      => null,
				'created_by'        => '7',
			),
			(object) array(
				'id'                => '92',
				'classification_id' => '61',
				'cancelled_at'      => null,
				'created_by'        => '3',
			),
		);

		ob_start();
		RecruitmentNoticeEditPageRenderer::render_classifications_section( $this->notice( array( 'status' => 'definitive' ) ) );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'ffc-cls-bulk-toolbar', $html, 'The Operator calls.' );
		$this->assertStringContainsString( 'data-cls-id="61" data-cls-action="hired"', $html, 'The Operator records an outcome on anyone\'s call.' );
		$this->assertMatchesRegularExpression( '/data-cls-action="cancel" data-call-id="91"/', $html, 'Their own call can be cancelled.' );
		$this->assertStringNotContainsString( 'data-call-id="92"', $html, 'Another operator\'s call cannot.' );
		$this->assertStringNotContainsString( 'data-cls-action="override"', $html, 'Undoing a decision is the Manager\'s.' );
		$this->assertMatchesRegularExpression( '/<select class="ffc-cls-preview-status" data-cls-id="50" disabled>/', $html, 'The preliminary statuses are the Manager\'s.' );
	}

	public function test_a_viewer_reads_the_lists_without_actions(): void {
		$this->caps = array(
			'manage_options'         => false,
			'ffc_manage_recruitment' => false,
			'ffc_call_recruitment'   => false,
			'ffc_view_recruitment'   => true,
		);
		$this->store_classifications( array(), array( $this->cls_row( 61, 1, 'empty', 1, 601 ) ) );

		ob_start();
		RecruitmentNoticeEditPageRenderer::render_classifications_section( $this->notice() );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Cand 601', $html );
		$this->assertStringNotContainsString( 'ffcRecruitmentClsAct', $html );
		$this->assertStringNotContainsString( 'ffc-cls-bulk-toolbar', $html );
	}

	public function test_the_status_section_offers_no_transition_to_a_non_manager(): void {
		$this->store_classifications( array(), array() );

		ob_start();
		RecruitmentNoticeEditPageRenderer::render_status_section( $this->notice( array( 'status' => 'definitive' ) ), false );
		$html = (string) ob_get_clean();

		$this->assertStringNotContainsString( 'target_status', $html );
		$this->assertStringContainsString( 'Current state:', $html );
	}

	public function test_render_classifications_section_empty_lists_render_no_rows_notice(): void {
		$this->store_classifications( array(), array() );

		ob_start();
		RecruitmentNoticeEditPageRenderer::render_classifications_section( $this->notice() );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( '(no rows)', $html );
		// no definitive rows → default tab is preliminary.
		$this->assertStringContainsString( 'data-ffc-clspanel="preliminary"', $html );
		$this->assertSame( array( 'preview', 'definitive' ), $this->classification_reads() );
	}

	// ------------------------------------------------------------------
	// render_shortcode_section() (#1646)
	// ------------------------------------------------------------------

	public function test_shortcode_section_offers_the_notice_shortcode_to_copy(): void {
		ob_start();
		RecruitmentNoticeEditPageRenderer::render_shortcode_section( $this->notice( array( 'code' => 'EDITAL-01' ) ) );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'id="ffc-notice-shortcode"', $html );
		$this->assertStringContainsString( 'value="[ffc_recruitment_queue notice="EDITAL-01"]"', $html );
		// The shared copy handler reads the input the button names.
		$this->assertStringContainsString( 'ffc-copy-link', $html );
		$this->assertStringContainsString( 'data-ffc-copy-target="#ffc-notice-shortcode"', $html );
		// The bare shortcode is offered as the selector alternative.
		$this->assertStringContainsString( '<code>[ffc_recruitment_queue]</code>', $html );
	}
}
