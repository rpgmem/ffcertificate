<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Recruitment\RecruitmentPublicShortcode;

/**
 * Tests for the listing half of RecruitmentPublicShortcode::render_uncached():
 * the adjutancy filter, the public name and subscription filters, and the
 * split into the "waiting" and "called" sections.
 *
 * The repositories and the renderer are aliased so the decisions the
 * shortcode makes are observable without the markup, which is why every test
 * runs in its own process.
 *
 * @covers \FreeFormCertificate\Recruitment\RecruitmentPublicShortcode
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class RecruitmentPublicShortcodeListingTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var Mockery\MockInterface */
	private $renderer;

	/** @var Mockery\MockInterface */
	private $classification;

	/** @var list<array<int, mixed>> render_section() calls. */
	private array $sections = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( '__' )->returnArg();

		$this->sections = array();
		$sections       = &$this->sections;

		$this->renderer = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentPublicShortcodeRenderer' );
		$this->renderer->shouldReceive( 'msg' )->andReturnUsing( static fn ( $text, $type ) => "[{$type}]{$text}" );
		$this->renderer->shouldReceive( 'wrap_with_banner' )->andReturnUsing( static fn ( $notice, $body ) => "BANNER({$body})" );
		$this->renderer->shouldReceive( 'render_filters_bar' )->andReturnUsing(
			static fn ( $notice, $locked, $q, $sub ) => '<filters locked=' . ( $locked ? '1' : '0' ) . " q={$q} sub={$sub}>"
		);
		$this->renderer->shouldReceive( 'parse_columns_config' )->andReturn( array( 'rank' => true ) );
		$this->renderer->shouldReceive( 'render_section' )->andReturnUsing(
			static function ( ...$args ) use ( &$sections ) {
				$sections[] = $args;
				return '<section>';
			}
		);

		$this->classification = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentClassificationRepository' );

		Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentSettings' )
			->shouldReceive( 'all' )->andReturn( array( 'public_default_page_size' => '25' ) );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function notice( string $status ): object {
		return (object) array(
			'id'                    => '5',
			'code'                  => 'EDITAL-1',
			'status'                => $status,
			'public_columns_config' => '{}',
		);
	}

	private function withNotice( string $status ): void {
		Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentNoticeReader' )
			->shouldReceive( 'get_by_code' )->with( 'EDITAL-1' )->andReturn( $this->notice( $status ) );
	}

	private function row( int $candidate, string $status ): object {
		return (object) array( 'candidate_id' => $candidate, 'status' => $status );
	}

	/**
	 * @param array<int, object> $candidates candidate_id => candidate.
	 */
	private function withCandidates( array $candidates ): void {
		Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentCandidateReader' )
			->shouldReceive( 'get_by_id' )->andReturnUsing( static fn ( $id ) => $candidates[ $id ] ?? null );
	}

	// ==================================================================
	// list= mismatch and adjutancy filter
	// ==================================================================

	public function test_asking_for_the_preliminary_list_of_a_definitive_notice_says_it_is_gone(): void {
		$this->withNotice( 'definitive' );
		$this->classification->shouldNotReceive( 'get_for_notice' );

		$html = RecruitmentPublicShortcode::render_uncached( 'EDITAL-1', '', 1, 1, false, '', '', 'preliminary' );

		$this->assertSame( '[info]The preliminary list for this notice is no longer available.', $html );
	}

	public function test_an_adjutancy_not_attached_to_the_notice_is_refused(): void {
		$this->withNotice( 'definitive' );
		Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentAdjutancyReader' )
			->shouldReceive( 'get_by_slug' )->with( 'math' )->andReturn( (object) array( 'id' => '3' ) );
		Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentNoticeAdjutancyRepository' )
			->shouldReceive( 'get_adjutancy_ids_for_notice' )->with( 5 )->andReturn( array( 1, 2 ) );
		$this->classification->shouldNotReceive( 'get_for_notice' );

		$html = RecruitmentPublicShortcode::render_uncached( 'EDITAL-1', 'math', 1, 1 );

		$this->assertSame( '[error]Adjutancy not found for this notice.', $html );
	}

	public function test_an_attached_adjutancy_narrows_the_preview_list(): void {
		$this->withNotice( 'preliminary' );
		Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentAdjutancyReader' )
			->shouldReceive( 'get_by_slug' )->andReturn( (object) array( 'id' => '3' ) );
		Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentNoticeAdjutancyRepository' )
			->shouldReceive( 'get_adjutancy_ids_for_notice' )->andReturn( array( 3 ) );
		$this->classification->shouldReceive( 'get_for_notice' )->once()->with( 5, 'preview', 3 )->andReturn( array() );

		$html = RecruitmentPublicShortcode::render_uncached( 'EDITAL-1', 'math', 1, 1, true );

		$this->assertSame( 'BANNER([info]No candidates classified yet.)', $html );
	}

	// ==================================================================
	// Sections
	// ==================================================================

	public function test_rows_split_into_waiting_and_called_with_the_newest_call_first(): void {
		$this->withNotice( 'definitive' );
		$rows = array( $this->row( 1, 'called' ), $this->row( 2, 'empty' ), $this->row( 3, 'accepted' ), $this->row( 4, 'empty' ) );
		$this->classification->shouldReceive( 'get_for_notice' )->with( 5, 'definitive', null )->andReturn( $rows );

		$html = RecruitmentPublicShortcode::render_uncached( 'EDITAL-1', '', 2, 3 );

		$this->assertSame( 'BANNER(<div class="ffc-recruitment-queue"><filters locked=0 q= sub=><section><section></div>)', $html );
		$this->assertCount( 2, $this->sections );
		list( $waiting, $called ) = $this->sections;
		$this->assertSame( 'Waiting called', $waiting[0] );
		$this->assertSame( array( 2, 4 ), array_column( $waiting[1], 'candidate_id' ) );
		$this->assertFalse( $waiting[3] );
		$this->assertSame( array( 2, 25, 'page_top' ), array_slice( $waiting, 4 ) );
		$this->assertSame( 'Called', $called[0] );
		$this->assertSame( array( 3, 1 ), array_column( $called[1], 'candidate_id' ), 'Called rows are reversed: the newest first.' );
		$this->assertTrue( $called[3] );
		$this->assertSame( array( 3, 25, 'page_bottom' ), array_slice( $called, 4 ) );
	}

	// ==================================================================
	// Name (?q=) and subscription (?subscription=) filters
	// ==================================================================

	public function test_the_name_filter_is_case_insensitive_and_drops_missing_candidates(): void {
		$this->withNotice( 'definitive' );
		$this->withCandidates(
			array(
				1 => (object) array( 'id' => 1, 'name' => 'Ana ÁVILA' ),
				2 => (object) array( 'id' => 2, 'name' => 'Bruno' ),
			)
		);
		$this->classification->shouldReceive( 'get_for_notice' )->andReturn(
			array( $this->row( 1, 'empty' ), $this->row( 2, 'empty' ), $this->row( 9, 'empty' ) )
		);

		RecruitmentPublicShortcode::render_uncached( 'EDITAL-1', '', 1, 1, false, 'ávila' );

		$this->assertSame( array( 1 ), array_column( $this->sections[0][1], 'candidate_id' ) );
	}

	public function test_the_subscription_filter_keeps_only_the_matching_quota(): void {
		$this->withNotice( 'definitive' );
		$this->withCandidates(
			array(
				1 => (object) array( 'id' => 1, 'name' => 'A', 'pcd_hash' => 'h1' ),
				2 => (object) array( 'id' => 2, 'name' => 'B', 'pcd_hash' => 'h2' ),
			)
		);
		Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentPcdHasher' )
			->shouldReceive( 'verify' )->andReturnUsing( static fn ( $hash, $id ) => 2 === $id );
		$this->classification->shouldReceive( 'get_for_notice' )->andReturn(
			array( $this->row( 1, 'empty' ), $this->row( 2, 'called' ) )
		);

		RecruitmentPublicShortcode::render_uncached( 'EDITAL-1', '', 1, 1, false, '', 'pcd' );

		$this->assertSame( array(), $this->sections[0][1] );
		$this->assertSame( array( 2 ), array_column( $this->sections[1][1], 'candidate_id' ) );
	}

	public function test_a_name_that_matches_nobody_keeps_the_filters_and_says_so(): void {
		$this->withNotice( 'definitive' );
		$this->withCandidates( array( 1 => (object) array( 'id' => 1, 'name' => 'Ana', 'pcd_hash' => '' ) ) );
		Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentPcdHasher' )
			->shouldReceive( 'verify' )->andReturn( false );
		$this->classification->shouldReceive( 'get_for_notice' )->andReturn( array( $this->row( 1, 'empty' ) ) );

		$html = RecruitmentPublicShortcode::render_uncached( 'EDITAL-1', '', 1, 1, true, 'zé', 'geral' );

		$this->assertSame( 'BANNER(<filters locked=1 q=zé sub=geral>[info]No matches for the current search.)', $html );
		$this->assertSame( array(), $this->sections );
	}

	public function test_an_unknown_subscription_value_filters_nothing(): void {
		$this->withNotice( 'definitive' );
		Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentCandidateReader' )->shouldNotReceive( 'get_by_id' );
		$this->classification->shouldReceive( 'get_for_notice' )->andReturn( array( $this->row( 1, 'empty' ) ) );

		RecruitmentPublicShortcode::render_uncached( 'EDITAL-1', '', 1, 1, false, '', 'other' );

		$this->assertSame( array( 1 ), array_column( $this->sections[0][1], 'candidate_id' ) );
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
