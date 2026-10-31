<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Core\DocumentFormatter;
use FreeFormCertificate\Core\Encryption;
use FreeFormCertificate\Recruitment\RecruitmentPcdHasher;
use FreeFormCertificate\Recruitment\RecruitmentPublicShortcodeRenderer;
use FreeFormCertificate\Tests\Support\Identifiers;

/**
 * Pipeline-level tests for {@see RecruitmentPublicShortcodeRenderer} — the
 * heavy rendering methods the helper-focused sibling test deliberately left
 * out: `render_section()` (pagination math + candidate cache warm + row
 * loop), the full column matrix of `render_row()` (every optional column
 * branch, preview vs. definitive status, PCD true/false, date/time cells),
 * `render_filters_bar()` (preserved-param re-emission + the adjutancy/name/
 * subscription sub-inputs) and `render_pagination()` (the sliding window).
 *
 * The collaborators are the real classes: the recruitment readers over a
 * `$wpdb` double that answers each query by its table and SQL text from the
 * arrays a test sets (an unexpected query fails the test), BadgeHtml,
 * DocumentFormatter, RecruitmentPcdHasher over a stubbed `wp_salt()`, and
 * Encryption with the bootstrap's key constants, so the candidate's CPF, RF
 * and e-mail are real ciphertexts decrypted and masked on the way out.
 *
 * @covers \FreeFormCertificate\Recruitment\RecruitmentPublicShortcodeRenderer
 */
class RecruitmentPublicShortcodeRenderPipelineTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** The e-mail every candidate holds. */
	private const EMAIL = 'ana@example.test';

	/** @var bool Whether `$wpdb` was set before this test. */
	private bool $had_wpdb = false;

	/** @var mixed The `$wpdb` global an earlier test left, restored afterwards. */
	private $wpdb_backup;

	/** @var array<string, mixed> The `$_GET` an earlier test left, restored afterwards. */
	private array $get_backup = array();

	/** @var array<string, array{0: string, 1: array<int, mixed>}> Prepared query => [SQL, bound values]. */
	private array $prepared = array();

	/** @var array<int, object> Candidate rows by id. */
	private array $candidates = array();

	/** @var array<int, object> Active calls by classification id. */
	private array $calls = array();

	/** @var array<int, object> Reason rows by id. */
	private array $reasons = array();

	/** @var array<int, list<int>> Adjutancy ids attached to each notice id. */
	private array $attached = array();

	/** @var array{cpf: string, rf: string, email: string} Ciphertexts every candidate carries. */
	private array $ciphertexts = array(
		'cpf'   => '',
		'rf'    => '',
		'email' => '',
	);

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// pcov coverage-attribution: preload so lines attribute to this test.
		class_exists( '\FreeFormCertificate\Recruitment\RecruitmentPublicShortcodeRenderer' );

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_attr__' )->returnArg();
		Functions\when( 'esc_html' )->alias( fn( $s ) => htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ) );
		Functions\when( 'esc_attr' )->alias( fn( $s ) => htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ) );
		Functions\when( 'esc_url' )->alias( fn( $s ) => (string) $s );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'selected' )->justReturn( '' );
		Functions\when( 'number_format_i18n' )->alias( fn( $n ) => (string) $n );
		Functions\when( '_n' )->alias( fn( $single, $plural, $number ) => 1 === (int) $number ? $single : $plural );
		Functions\when( 'add_query_arg' )->alias( fn( $k, $v, $base = '' ) => "?{$k}={$v}" );
		Functions\when( 'remove_query_arg' )->alias( fn( $k, $base = '' ) => '?' );
		Functions\when( 'is_email' )->alias( static fn( $email ) => false !== filter_var( $email, FILTER_VALIDATE_EMAIL ) ? $email : false );
		Functions\when( 'wp_salt' )->justReturn( 'unit-test-salt' );
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		// Encryption and Debug read their options here; every one answers its default.
		Functions\when( 'get_option' )->alias( static fn( $key, $default = false ) => $default );

		$this->prepared    = array();
		$this->ciphertexts = array(
			'cpf'   => (string) Encryption::encrypt( Identifiers::CPF_A ),
			'rf'    => (string) Encryption::encrypt( Identifiers::RF_A ),
			'email' => (string) Encryption::encrypt( self::EMAIL ),
		);
		$this->candidates  = array();
		foreach ( array( 1, 2, 3, 4, 5 ) as $id ) {
			$this->candidates[ $id ] = $this->candidate( $id, true );
		}
		$this->calls    = array(
			10 => (object) array(
				'date_to_assume' => '2026-05-20',
				'time_to_assume' => '09:00:00',
			),
		);
		$this->reasons  = array( 5 => (object) array( 'id' => '5', 'label' => 'Fora do prazo' ) );
		$this->attached = array( 7 => array( 1, 2 ) );

		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			function ( $sql, ...$args ) {
				if ( 1 === count( $args ) && is_array( $args[0] ) ) {
					$args = $args[0];
				}
				$key                    = 'Q' . count( $this->prepared ) . ':' . $sql;
				$this->prepared[ $key ] = array( (string) $sql, array_values( $args ) );
				return $key;
			}
		);
		foreach ( array( 'get_row', 'get_results', 'get_col' ) as $method ) {
			$wpdb->shouldReceive( $method )->andReturnUsing(
				fn( $query ) => $this->answer( $method, (string) $query )
			);
		}

		$this->had_wpdb    = array_key_exists( 'wpdb', $GLOBALS );
		$this->wpdb_backup = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb']   = $wpdb;

		$this->get_backup = $_GET ?? array();
		$_GET             = array();
	}

	protected function tearDown(): void {
		$_GET = $this->get_backup;
		if ( $this->had_wpdb ) {
			$GLOBALS['wpdb'] = $this->wpdb_backup;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Answer one `$wpdb` read from the arrays the test set.
	 *
	 * @return mixed
	 */
	private function answer( string $method, string $query ) {
		if ( ! isset( $this->prepared[ $query ] ) ) {
			$this->fail( "Unprepared {$method}(): {$query}" );
		}
		list( $sql, $bound ) = $this->prepared[ $query ];
		$table               = substr( (string) $bound[0], strlen( 'wp_' ) );

		if ( 'get_row' === $method && 'ffc_recruitment_candidate' === $table && false !== strpos( $sql, 'id = %d' ) ) {
			return $this->candidates[ (int) $bound[1] ] ?? null;
		}
		if ( 'get_results' === $method && 'ffc_recruitment_candidate' === $table && false !== strpos( $sql, 'id IN (' ) ) {
			return array_values( array_intersect_key( $this->candidates, array_flip( array_map( 'intval', array_slice( $bound, 1 ) ) ) ) );
		}
		if ( 'get_row' === $method && 'ffc_recruitment_adjutancy' === $table && false !== strpos( $sql, 'id = %d' ) ) {
			$id = (int) $bound[1];
			return (object) array(
				'id'    => (string) $id,
				'name'  => 'Adjutancy ' . $id,
				'slug'  => 'adj-' . $id,
				'color' => '#123456',
			);
		}
		if ( 'get_col' === $method && 'ffc_recruitment_notice_adjutancy' === $table ) {
			return array_map( 'strval', $this->attached[ (int) $bound[1] ] ?? array() );
		}
		if ( 'get_row' === $method && 'ffc_recruitment_call' === $table && false !== strpos( $sql, 'classification_id = %d' ) ) {
			return $this->calls[ (int) $bound[1] ] ?? null;
		}
		if ( 'get_row' === $method && 'ffc_recruitment_reason' === $table && false !== strpos( $sql, 'id = %d' ) ) {
			return $this->reasons[ (int) $bound[1] ] ?? null;
		}
		$this->fail( "Unexpected {$method}() on {$table}: {$sql}" );
	}

	/** Build a candidate row with every field the renderer reads. */
	private function candidate( int $id, bool $is_pcd ): \stdClass {
		return (object) array(
			'id'              => (string) $id,
			'name'            => "Candidate {$id}",
			'cpf_encrypted'   => $this->ciphertexts['cpf'],
			'rf_encrypted'    => $this->ciphertexts['rf'],
			'email_encrypted' => $this->ciphertexts['email'],
			'pcd_hash'        => RecruitmentPcdHasher::compute( $id, $is_pcd ),
		);
	}

	/**
	 * Build a classification row; overrides merge over the defaults.
	 *
	 * The default row is PCD in its notice (#2245).
	 */
	private static function row( array $over = array() ): \stdClass {
		return (object) array_merge(
			array(
				'id'                => 10,
				'notice_id'         => 1,
				'candidate_id'      => 1,
				'pcd_hash'          => RecruitmentPcdHasher::compute_for_row( 1, 1, 3, true ),
				'rank'              => 1,
				'adjutancy_id'      => 3,
				'status'            => 'called',
				'list_type'         => 'definitive',
				'preview_status'    => 'granted',
				'preview_reason_id' => null,
				'score'             => 53.0,
				'time_points'       => 4.5,
				'hab_emebs'         => 1,
			),
			$over
		);
	}

	/** Every optional column switched on. */
	private static function all_columns(): array {
		return array(
			'rank'           => true,
			'name'           => true,
			'adjutancy'      => true,
			'cpf_masked'     => true,
			'rf_masked'      => true,
			'email_masked'   => true,
			'score'          => true,
			'time_points'    => true,
			'hab_emebs'      => true,
			'status'         => true,
			'pcd_badge'      => true,
			'date_to_assume' => true,
			'time_to_assume' => true,
			'preview_reason' => true,
		);
	}

	// ------------------------------------------------------------------
	// render_section()
	// ------------------------------------------------------------------

	public function test_render_section_returns_empty_string_for_no_rows(): void {
		$out = RecruitmentPublicShortcodeRenderer::render_section(
			'Waiting',
			array(),
			self::all_columns(),
			true,
			1,
			25,
			'p'
		);
		$this->assertSame( '', $out );
	}

	public function test_render_section_renders_full_table_with_all_columns_and_definitive_status(): void {
		$out = RecruitmentPublicShortcodeRenderer::render_section(
			'Called',
			array( self::row() ),
			self::all_columns(),
			true,
			1,
			25,
			'p'
		);

		$this->assertStringContainsString( '<section class="ffc-recruitment-section">', $out );
		$this->assertStringContainsString( 'Candidate 1', $out );
		$this->assertStringContainsString( '<td>' . DocumentFormatter::mask_cpf( Identifiers::CPF_A ) . '</td>', $out ); // decrypted, then masked
		$this->assertStringContainsString( '<td>' . DocumentFormatter::mask_rf( Identifiers::RF_A ) . '</td>', $out );
		$this->assertStringContainsString( '<td>' . DocumentFormatter::mask_email( self::EMAIL ) . '</td>', $out );
		$this->assertStringNotContainsString( Identifiers::CPF_A, $out, 'The clear CPF never reaches the page.' );
		$this->assertStringNotContainsString( self::EMAIL, $out, 'The clear e-mail never reaches the page.' );
		$this->assertStringContainsString( '53.00', $out );           // score, 2 decimals
		$this->assertStringContainsString( '4.50', $out );            // time_points
		$this->assertStringContainsString( 'ffc-recruitment-status-called', $out ); // definitive status badge
		$this->assertStringContainsString( 'ffc-recruitment-subscription-pcd', $out );
		$this->assertStringContainsString( 'Adjutancy 3', $out );
		$this->assertStringContainsString( '20-05-2026', $out );      // date reformatted d-m-Y
		$this->assertStringContainsString( '09:00', $out );           // time HH:MM
		$this->assertStringContainsString( '</tbody></table>', $out );
		$this->assertStringContainsString( '</section>', $out );
	}

	public function test_render_section_renders_preview_status_with_reason_tooltip(): void {
		$out = RecruitmentPublicShortcodeRenderer::render_section(
			'Preview',
			array( self::row( array( 'list_type' => 'preview', 'preview_status' => 'granted', 'preview_reason_id' => 5 ) ) ),
			self::all_columns(),
			true,
			1,
			25,
			'p'
		);

		// The preview badge carries the resolved reason label as its tooltip.
		$this->assertStringContainsString( 'title="Fora do prazo"', $out );
		$this->assertStringContainsString( 'ffc-recruitment-preview-status-granted', $out );
	}

	public function test_render_section_skips_row_whose_candidate_is_missing(): void {
		$this->candidates = array(); // every candidate missing

		$out = RecruitmentPublicShortcodeRenderer::render_section(
			'Called',
			array( self::row() ),
			self::all_columns(),
			true,
			1,
			25,
			'p'
		);

		// Table chrome still renders; the row itself is skipped (no candidate name).
		$this->assertStringContainsString( '<tbody>', $out );
		$this->assertStringNotContainsString( 'Candidate 1', $out );
	}

	public function test_render_section_hab_emebs_off_renders_dash(): void {
		$out = RecruitmentPublicShortcodeRenderer::render_section(
			'Called',
			array( self::row( array( 'hab_emebs' => 0 ) ) ),
			self::all_columns(),
			true,
			1,
			25,
			'p'
		);
		$this->assertStringContainsString( '—', $out );
	}

	public function test_render_section_paginates_across_multiple_pages(): void {
		$rows = array();
		for ( $i = 1; $i <= 5; $i++ ) {
			$rows[] = self::row( array( 'id' => $i, 'candidate_id' => $i, 'rank' => $i ) );
		}

		// page_size 2 over 5 rows → 3 pages → the pagination template renders.
		$out = RecruitmentPublicShortcodeRenderer::render_section(
			'Called',
			$rows,
			self::all_columns(),
			true,
			1,
			2,
			'p'
		);

		$this->assertStringContainsString( 'ffc-recruitment-pagination', $out );
	}

	public function test_render_section_clamps_current_page_past_last(): void {
		$rows = array( self::row( array( 'id' => 1, 'candidate_id' => 1 ) ), self::row( array( 'id' => 2, 'candidate_id' => 2 ) ) );

		// Ask for page 99 with 1 page-worth of data → clamps to the last page.
		$out = RecruitmentPublicShortcodeRenderer::render_section(
			'Called',
			$rows,
			self::all_columns(),
			true,
			99,
			25,
			'p'
		);
		$this->assertStringContainsString( 'Candidate 1', $out );
	}

	// ------------------------------------------------------------------
	// render_row() — date/time cells when no active call
	// ------------------------------------------------------------------

	public function test_render_row_empty_date_time_when_no_active_call(): void {
		$this->calls = array();

		$out = RecruitmentPublicShortcodeRenderer::render_section(
			'Called',
			array( self::row() ),
			self::all_columns(),
			true,
			1,
			25,
			'p'
		);

		// Row still renders; the date/time cells are simply blank.
		$this->assertStringContainsString( 'Candidate 1', $out );
		$this->assertStringNotContainsString( '20-05-2026', $out );
		$this->assertStringEndsWith( '<td></td><td></td></tr></tbody></table></section>', $out );
	}

	public function test_render_row_pcd_false_renders_geral_badge(): void {
		// The candidate row says PCD; the classification says general, and
		// the classification is what decides (#2245).
		$this->candidates[1] = $this->candidate( 1, true );

		$out = RecruitmentPublicShortcodeRenderer::render_section(
			'Called',
			array( self::row( array( 'pcd_hash' => RecruitmentPcdHasher::compute_for_row( 1, 1, 3, false ) ) ) ),
			self::all_columns(),
			true,
			1,
			25,
			'p'
		);

		$this->assertStringContainsString( 'ffc-recruitment-subscription-geral', $out );
		$this->assertStringNotContainsString( 'ffc-recruitment-subscription-pcd', $out );
	}

	// ------------------------------------------------------------------
	// render_filters_bar()
	// ------------------------------------------------------------------

	public function test_render_filters_bar_renders_all_controls_and_preserves_params(): void {
		$_GET = array(
			'page'      => 'notice-x',
			'q'         => 'ana',
			'adjutancy' => '3',
		);

		$notice = (object) array( 'id' => 7, 'public_columns_config' => '' );
		$out    = RecruitmentPublicShortcodeRenderer::render_filters_bar( $notice, false, 'ana', '' );

		$this->assertStringContainsString( '<form class="ffc-recruitment-filters"', $out );
		// Preserved param (page) is re-emitted as a hidden input; the filter
		// controls' own params (q/adjutancy) are NOT re-emitted as hidden
		// inputs — those live in the visible controls, which own them.
		$this->assertStringContainsString( '<input type="hidden" name="page"', $out );
		$this->assertStringNotContainsString( '<input type="hidden" name="q"', $out );
		$this->assertStringNotContainsString( '<input type="hidden" name="adjutancy"', $out );
		$this->assertStringContainsString( '<select name="adjutancy"', $out, 'Two attached adjutancies draw the dropdown.' );
	}

	public function test_render_filters_bar_locked_omits_adjutancy_select(): void {
		$notice = (object) array( 'id' => 7, 'public_columns_config' => '' );

		$out = RecruitmentPublicShortcodeRenderer::render_filters_bar( $notice, true, '', '' );

		// Locked → the adjutancy sub-input is skipped, but the form (name +
		// subscription controls) still renders.
		$this->assertStringContainsString( '<form class="ffc-recruitment-filters"', $out );
		$this->assertStringNotContainsString( 'name="adjutancy"', $out );
		$this->assertStringContainsString( 'name="subscription"', $out );
		$this->assertStringContainsString( 'name="q"', $out );
	}

	public function test_render_filters_bar_single_adjutancy_hides_dropdown(): void {
		$this->attached[7] = array( 1 ); // < 2 → hidden

		$notice = (object) array( 'id' => 7, 'public_columns_config' => '' );

		$out = RecruitmentPublicShortcodeRenderer::render_filters_bar( $notice, false, '', '' );

		$this->assertStringContainsString( '<form class="ffc-recruitment-filters"', $out );
		$this->assertStringNotContainsString( 'name="adjutancy"', $out );
	}
}
