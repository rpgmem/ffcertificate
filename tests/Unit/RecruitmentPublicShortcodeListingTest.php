<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Recruitment\RecruitmentPcdHasher;
use FreeFormCertificate\Recruitment\RecruitmentPublicShortcode;

/**
 * Tests for the listing half of RecruitmentPublicShortcode::render_uncached():
 * the adjutancy filter, the public name and subscription filters, and the
 * split into the "waiting" and "called" sections.
 *
 * The renderer, the readers, RecruitmentSettings and RecruitmentPcdHasher are
 * the real classes. The `$wpdb` double below answers each query by its table
 * and SQL text, with the values bound to it, from the arrays a test sets, and
 * records every query so a test can assert which lookups ran; an unexpected
 * query fails the test. The decisions the shortcode makes are read off the
 * rendered sections.
 *
 * @covers \FreeFormCertificate\Recruitment\RecruitmentPublicShortcode
 */
class RecruitmentPublicShortcodeListingTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var bool Whether `$wpdb` was set before this test. */
	private bool $had_wpdb = false;

	/** @var mixed The `$wpdb` global an earlier test left, restored afterwards. */
	private $wpdb_backup;

	/** @var array<string, mixed> The `$_GET` an earlier test left, restored afterwards. */
	private array $get_backup = array();

	/** @var array<string, array{0: string, 1: array<int, mixed>}> Prepared query => [SQL, bound values]. */
	private array $prepared = array();

	/** @var list<array{0: string, 1: string, 2: string, 3: array<int, mixed>}> Every query run, as [method, table, SQL, bound values]. */
	private array $queries = array();

	/** @var array<string, mixed> The stored `ffc_recruitment_settings` option. */
	private array $settings = array();

	/** @var array<string, object> Notice rows by code. */
	private array $notices = array();

	/** @var list<object> Classification rows the notice query answers. */
	private array $classifications = array();

	/** @var array<int, object> Candidate rows by id. */
	private array $candidates = array();

	/** @var array<int, object> Adjutancy rows by id. */
	private array $adjutancies = array();

	/** @var array<int, list<int>> Adjutancy ids attached to each notice id. */
	private array $attached = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_attr__' )->returnArg();
		Functions\when( 'esc_html' )->alias( static fn ( $s ) => htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ) );
		Functions\when( 'esc_attr' )->alias( static fn ( $s ) => htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ) );
		Functions\when( 'esc_url' )->alias( static fn ( $s ) => (string) $s );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'selected' )->alias(
			static fn ( $a, $b, $display = true ) => (string) $a === (string) $b ? " selected='selected'" : ''
		);
		Functions\when( 'number_format_i18n' )->alias( static fn ( $n ) => (string) $n );
		Functions\when( '_n' )->alias( static fn ( $single, $plural, $number ) => 1 === (int) $number ? $single : $plural );
		Functions\when( 'add_query_arg' )->alias( static fn ( $k, $v, $base = '' ) => "?{$k}={$v}" );
		Functions\when( 'remove_query_arg' )->alias( static fn ( $k, $base = '' ) => '?' );
		Functions\when( 'wp_salt' )->justReturn( 'unit-test-salt' );
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		Functions\when( 'get_option' )->alias(
			fn ( $key, $default = false ) => 'ffc_recruitment_settings' === $key ? $this->settings : $default
		);

		$this->prepared        = array();
		$this->queries         = array();
		$this->settings        = array( 'public_default_page_size' => 25 );
		$this->notices         = array();
		$this->classifications = array();
		$this->candidates      = array();
		$this->adjutancies     = array(
			3 => (object) array(
				'id'    => '3',
				'slug'  => 'math',
				'name'  => 'Math',
				'color' => '#123456',
			),
		);
		$this->attached        = array();

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
				fn ( $query ) => $this->answer( $method, (string) $query )
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
		$this->queries[]     = array( $method, $table, $sql, $bound );

		if ( 'get_row' === $method && 'ffc_recruitment_notice' === $table && false !== strpos( $sql, 'code = %s' ) ) {
			return $this->notices[ $bound[1] ] ?? null;
		}
		if ( 'get_results' === $method && 'ffc_recruitment_classification' === $table && false !== strpos( $sql, 'notice_id = %d' ) ) {
			return $this->classifications;
		}
		if ( 'get_row' === $method && 'ffc_recruitment_candidate' === $table && false !== strpos( $sql, 'id = %d' ) ) {
			return $this->candidates[ (int) $bound[1] ] ?? null;
		}
		if ( 'get_results' === $method && 'ffc_recruitment_candidate' === $table && false !== strpos( $sql, 'id IN (' ) ) {
			return array_values( array_intersect_key( $this->candidates, array_flip( array_map( 'intval', array_slice( $bound, 1 ) ) ) ) );
		}
		if ( 'get_row' === $method && 'ffc_recruitment_adjutancy' === $table && false !== strpos( $sql, 'slug = %s' ) ) {
			foreach ( $this->adjutancies as $adjutancy ) {
				if ( $adjutancy->slug === $bound[1] ) {
					return $adjutancy;
				}
			}
			return null;
		}
		if ( 'get_row' === $method && 'ffc_recruitment_adjutancy' === $table && false !== strpos( $sql, 'id = %d' ) ) {
			return $this->adjutancies[ (int) $bound[1] ] ?? null;
		}
		if ( 'get_col' === $method && 'ffc_recruitment_notice_adjutancy' === $table ) {
			return array_map( 'strval', $this->attached[ (int) $bound[1] ] ?? array() );
		}
		if ( 'get_row' === $method && 'ffc_recruitment_call' === $table ) {
			// No classification here has an active call.
			return null;
		}
		$this->fail( "Unexpected {$method}() on {$table}: {$sql}" );
	}

	/**
	 * The queries run against one table.
	 *
	 * @return list<array{0: string, 1: string, 2: string, 3: array<int, mixed>}>
	 */
	private function queriesOn( string $table ): array {
		return array_values( array_filter( $this->queries, static fn ( array $q ): bool => $table === $q[1] ) );
	}

	private function withNotice( string $status ): void {
		$this->notices['EDITAL-1'] = (object) array(
			'id'                    => '5',
			'code'                  => 'EDITAL-1',
			'name'                  => 'Teachers',
			'status'                => $status,
			'public_columns_config' => '{}',
		);
	}

	private function row( int $candidate, string $status ): object {
		return (object) array(
			'id'           => (string) ( 100 + $candidate ),
			'candidate_id' => (string) $candidate,
			'rank'         => (string) $candidate,
			'adjutancy_id' => '3',
			'list_type'    => 'definitive',
			'status'       => $status,
		);
	}

	private function candidate( int $id, string $name, string $pcd_hash = '' ): object {
		return (object) array(
			'id'       => (string) $id,
			'name'     => $name,
			'pcd_hash' => $pcd_hash,
		);
	}

	/**
	 * Strip the notice banner, asserting it is the one of the given status.
	 */
	private function bodyUnderBanner( string $html, string $status ): string {
		$this->assertStringStartsWith(
			'<header class="ffc-recruitment-header"><div class="ffc-recruitment-banner ffc-recruitment-banner-' . $status . '"',
			$html,
			'The listing is wrapped with the banner of the notice status.'
		);
		$this->assertStringContainsString( '<p class="ffc-recruitment-notice-title">EDITAL-1 — Teachers</p></header>', $html );
		return substr( $html, strpos( $html, '</header>' ) + strlen( '</header>' ) );
	}

	/**
	 * The rendered sections, in order: heading, candidate ids in row order, markup.
	 *
	 * @return list<array{heading: string, candidates: list<int>, html: string}>
	 */
	private function sections( string $html ): array {
		preg_match_all( '#<section class="ffc-recruitment-section">(.*?)</section>#s', $html, $matches );
		$out = array();
		foreach ( $matches[1] as $section ) {
			preg_match( '#<span class="ffc-recruitment-section-title">(.*?)</span>#', $section, $title );
			preg_match_all( '#<td>Candidate (\d+)</td>#', $section, $names );
			$out[] = array(
				'heading'    => $title[1] ?? '',
				'candidates' => array_map( 'intval', $names[1] ),
				'html'       => $section,
			);
		}
		return $out;
	}

	private function message( string $kind, string $text ): string {
		return '<div class="ffc-recruitment-message ffc-recruitment-message-' . $kind . '">' . $text . '</div>';
	}

	// ==================================================================
	// list= mismatch and adjutancy filter
	// ==================================================================

	public function test_asking_for_the_preliminary_list_of_a_definitive_notice_says_it_is_gone(): void {
		$this->withNotice( 'definitive' );

		$html = RecruitmentPublicShortcode::render_uncached( 'EDITAL-1', '', 1, 1, false, '', '', 'preliminary' );

		$this->assertSame( $this->message( 'info', 'The preliminary list for this notice is no longer available.' ), $html );
		$this->assertSame( array(), $this->queriesOn( 'ffc_recruitment_classification' ), 'No classification is read.' );
	}

	public function test_an_adjutancy_not_attached_to_the_notice_is_refused(): void {
		$this->withNotice( 'definitive' );
		$this->attached[5] = array( 1, 2 );

		$html = RecruitmentPublicShortcode::render_uncached( 'EDITAL-1', 'math', 1, 1 );

		$this->assertSame( $this->message( 'error', 'Adjutancy not found for this notice.' ), $html );
		$attachments = $this->queriesOn( 'ffc_recruitment_notice_adjutancy' );
		$this->assertCount( 1, $attachments );
		$this->assertSame( 5, $attachments[0][3][1], 'The attachments of notice 5 are read.' );
		$this->assertSame( array(), $this->queriesOn( 'ffc_recruitment_classification' ), 'No classification is read.' );
	}

	public function test_an_attached_adjutancy_narrows_the_preview_list(): void {
		$this->withNotice( 'preliminary' );
		$this->attached[5] = array( 3 );

		$html = RecruitmentPublicShortcode::render_uncached( 'EDITAL-1', 'math', 1, 1, true );

		$this->assertSame(
			$this->message( 'info', 'No candidates classified yet.' ),
			$this->bodyUnderBanner( $html, 'preliminary' )
		);
		$reads = $this->queriesOn( 'ffc_recruitment_classification' );
		$this->assertCount( 1, $reads );
		$this->assertSame( array( 'wp_ffc_recruitment_classification', 5, 'preview', 3 ), $reads[0][3] );
	}

	// ==================================================================
	// Sections
	// ==================================================================

	public function test_rows_split_into_waiting_and_called_with_the_newest_call_first(): void {
		$this->withNotice( 'definitive' );
		foreach ( array( 1, 2, 3, 4 ) as $id ) {
			$this->candidates[ $id ] = $this->candidate( $id, "Candidate {$id}" );
		}
		$this->classifications = array( $this->row( 1, 'called' ), $this->row( 2, 'empty' ), $this->row( 3, 'accepted' ), $this->row( 4, 'empty' ) );

		$html = RecruitmentPublicShortcode::render_uncached( 'EDITAL-1', '', 1, 1 );

		$reads = $this->queriesOn( 'ffc_recruitment_classification' );
		$this->assertSame( array( 'wp_ffc_recruitment_classification', 5, 'definitive' ), $reads[0][3], 'No adjutancy narrows the read.' );

		$body = $this->bodyUnderBanner( $html, 'definitive' );
		$this->assertStringStartsWith( '<div class="ffc-recruitment-queue"><form class="ffc-recruitment-filters" method="get">', $body );
		$this->assertStringEndsWith( '</section></div>', $body );
		$this->assertCount( 1, $this->queriesOn( 'ffc_recruitment_notice_adjutancy' ), 'An unlocked filter bar looks up the adjutancy dropdown.' );

		$sections = $this->sections( $body );
		$this->assertCount( 2, $sections );
		list( $waiting, $called ) = $sections;
		$this->assertSame( 'Waiting called', $waiting['heading'] );
		$this->assertSame( array( 2, 4 ), $waiting['candidates'] );
		$this->assertStringNotContainsString( 'Date to assume', $waiting['html'], 'The waiting section carries no date column.' );
		$this->assertSame( 'Called', $called['heading'] );
		$this->assertSame( array( 3, 1 ), $called['candidates'], 'Called rows are reversed: the newest first.' );
		$this->assertStringContainsString( 'Date to assume', $called['html'], 'The called section carries the date column.' );
	}

	public function test_each_section_pages_by_its_own_parameter_at_the_configured_page_size(): void {
		$this->settings = array( 'public_default_page_size' => 1 );
		$this->withNotice( 'definitive' );
		foreach ( array( 1, 2, 3, 4 ) as $id ) {
			$this->candidates[ $id ] = $this->candidate( $id, "Candidate {$id}" );
		}
		$this->classifications = array( $this->row( 1, 'called' ), $this->row( 2, 'empty' ), $this->row( 3, 'accepted' ), $this->row( 4, 'empty' ) );

		$html = RecruitmentPublicShortcode::render_uncached( 'EDITAL-1', '', 2, 3 );

		list( $waiting, $called ) = $this->sections( $this->bodyUnderBanner( $html, 'definitive' ) );
		$this->assertSame( array( 4 ), $waiting['candidates'], 'Page 2 of the waiting rows, one per page.' );
		$this->assertStringContainsString( 'href="?page_top=1"', $waiting['html'] );
		$this->assertStringNotContainsString( 'page_bottom', $waiting['html'] );
		$this->assertSame( array( 1 ), $called['candidates'], 'Page 3 of two called rows lands on the last page.' );
		$this->assertStringContainsString( 'href="?page_bottom=1"', $called['html'] );
		$this->assertStringNotContainsString( 'page_top', $called['html'] );
	}

	// ==================================================================
	// Name (?q=) and subscription (?subscription=) filters
	// ==================================================================

	public function test_the_name_filter_is_case_insensitive_and_drops_missing_candidates(): void {
		$this->withNotice( 'definitive' );
		$this->candidates[1]   = $this->candidate( 1, 'Ana ÁVILA' );
		$this->candidates[2]   = $this->candidate( 2, 'Bruno' );
		$this->classifications = array( $this->row( 1, 'empty' ), $this->row( 2, 'empty' ), $this->row( 9, 'empty' ) );

		$html = RecruitmentPublicShortcode::render_uncached( 'EDITAL-1', '', 1, 1, false, 'ávila' );

		$sections = $this->sections( $html );
		$this->assertCount( 1, $sections );
		$this->assertSame( 'Waiting called', $sections[0]['heading'] );
		$this->assertStringContainsString( '<td>Ana ÁVILA</td>', $sections[0]['html'] );
		$this->assertStringNotContainsString( 'Bruno', $html );
		$this->assertStringContainsString( '1 candidate<', $sections[0]['html'], 'Only the one match is counted.' );
	}

	public function test_the_subscription_filter_keeps_only_the_matching_quota(): void {
		$this->withNotice( 'definitive' );
		$this->candidates[1]   = $this->candidate( 1, 'Candidate 1', RecruitmentPcdHasher::compute( 1, false ) );
		$this->candidates[2]   = $this->candidate( 2, 'Candidate 2', RecruitmentPcdHasher::compute( 2, true ) );
		$this->classifications = array( $this->row( 1, 'empty' ), $this->row( 2, 'called' ) );

		$html = RecruitmentPublicShortcode::render_uncached( 'EDITAL-1', '', 1, 1, false, '', 'pcd' );

		$sections = $this->sections( $html );
		$this->assertCount( 1, $sections, 'The waiting section is left empty, so it is not drawn.' );
		$this->assertSame( 'Called', $sections[0]['heading'] );
		$this->assertSame( array( 2 ), $sections[0]['candidates'] );
		$this->assertStringNotContainsString( 'Candidate 1', $html );
	}

	public function test_a_notice_hiding_the_pcd_column_ignores_the_subscription_filter(): void {
		$this->withNotice( 'definitive' );
		$this->notices['EDITAL-1']->public_columns_config = '{"pcd_badge":false}';
		$this->candidates[1]   = $this->candidate( 1, 'Candidate 1', RecruitmentPcdHasher::compute( 1, false ) );
		$this->candidates[2]   = $this->candidate( 2, 'Candidate 2', RecruitmentPcdHasher::compute( 2, true ) );
		$this->classifications = array( $this->row( 1, 'empty' ), $this->row( 2, 'empty' ) );

		$html = RecruitmentPublicShortcode::render_uncached( 'EDITAL-1', '', 1, 1, false, '', 'pcd' );

		$this->assertSame( array( 1, 2 ), $this->sections( $html )[0]['candidates'], 'Filtering by PCD would disclose what the hidden column hides.' );
		$this->assertStringNotContainsString( 'name="subscription"', $html, 'Nor is the PCD select offered.' );
	}

	public function test_a_name_that_matches_nobody_keeps_the_filters_and_says_so(): void {
		$this->withNotice( 'definitive' );
		$this->candidates[1]   = $this->candidate( 1, 'Ana' );
		$this->classifications = array( $this->row( 1, 'empty' ) );

		$html = RecruitmentPublicShortcode::render_uncached( 'EDITAL-1', '', 1, 1, true, 'zé', 'geral' );

		$body = $this->bodyUnderBanner( $html, 'definitive' );
		$this->assertStringStartsWith( '<form class="ffc-recruitment-filters" method="get">', $body );
		$this->assertStringEndsWith( '</form>' . $this->message( 'info', 'No matches for the current search.' ), $body );
		$this->assertStringContainsString( 'name="q" value="zé"', $body );
		$this->assertStringContainsString( "<option value=\"geral\" selected='selected'>", $body );
		$this->assertStringNotContainsString( 'name="adjutancy"', $body, 'A locked filter bar has no adjutancy dropdown.' );
		$this->assertSame( array(), $this->queriesOn( 'ffc_recruitment_notice_adjutancy' ), 'A locked filter bar never looks the adjutancies up.' );
		$this->assertSame( array(), $this->sections( $html ) );
	}

	public function test_an_unknown_subscription_value_filters_nothing(): void {
		$this->withNotice( 'definitive' );
		$this->candidates[1]   = $this->candidate( 1, 'Candidate 1' );
		$this->classifications = array( $this->row( 1, 'empty' ) );

		$html = RecruitmentPublicShortcode::render_uncached( 'EDITAL-1', '', 1, 1, false, '', 'other' );

		$candidate_reads = $this->queriesOn( 'ffc_recruitment_candidate' );
		$this->assertNotEmpty( $candidate_reads );
		$this->assertStringContainsString(
			'id IN (',
			$candidate_reads[0][2],
			'The first candidate read is the section\'s batch warm-up: the filter looked nobody up.'
		);
		$this->assertSame( array( 1 ), $this->sections( $html )[0]['candidates'] );
	}

	// ==================================================================
	// maybe_enqueue_early()
	// ==================================================================

	public function test_early_enqueue_does_nothing_in_wp_admin(): void {
		Functions\when( 'is_admin' )->justReturn( true );
		$looked_up = false;
		Functions\when( 'get_post' )->alias(
			static function () use ( &$looked_up ) {
				$looked_up = true;
				return null;
			}
		);

		RecruitmentPublicShortcode::maybe_enqueue_early();

		$this->assertFalse( $looked_up, 'wp-admin never reads the queried post.' );
	}
}
