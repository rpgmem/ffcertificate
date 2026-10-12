<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Recruitment\RecruitmentDashboardSection;

/**
 * Tests for the "My Calls" section: the view-as user, the classification
 * table per notice status, and the call-history table with its per-call
 * situation label.
 *
 * Every reader is aliased, so each test runs in its own process.
 *
 * @covers \FreeFormCertificate\Recruitment\RecruitmentDashboardSection
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class RecruitmentDashboardSectionCallsTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var Mockery\MockInterface */
	private $candidates;

	/** @var Mockery\MockInterface */
	private $classifications;

	/** @var Mockery\MockInterface */
	private $notices;

	/** @var Mockery\MockInterface */
	private $calls;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'number_format_i18n' )->alias( static fn ( $n, $d = 0 ) => number_format( (float) $n, (int) $d ) );

		$this->candidates      = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentCandidateReader' );
		$this->classifications = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentClassificationRepository' );
		$this->notices         = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentNoticeReader' );
		$this->calls           = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentCallReader' );

		$adjutancies = array( 1 => (object) array( 'id' => 1, 'name' => 'Mathematics' ) );
		Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentAdjutancyReader' )
			->shouldReceive( 'get_by_id' )->andReturnUsing( static fn ( $id ) => $adjutancies[ $id ] ?? null );

		Mockery::mock( 'alias:FreeFormCertificate\Core\DateFormatter' )
			->shouldReceive( 'format_datetime' )->andReturnUsing( static fn ( $ts ) => 'DT' . $ts )
			->shouldReceive( 'format_date' )->andReturnUsing( static fn ( $d ) => 'D' . $d )
			->shouldReceive( 'format_time' )->andReturnUsing( static fn ( $t ) => 'T' . $t );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function cls( int $id, int $notice, string $list, string $status, int $adjutancy = 1 ): object {
		return (object) array(
			'id'           => $id,
			'notice_id'    => $notice,
			'adjutancy_id' => $adjutancy,
			'list_type'    => $list,
			'status'       => $status,
			'rank'         => $id,
			'score'        => '87.5',
		);
	}

	private function call( int $classification, ?string $cancelled = null, array $extra = array() ): object {
		return (object) array_merge(
			array(
				'classification_id' => $classification,
				'cancelled_at'      => $cancelled,
				'called_at'         => 1700000000,
				'date_to_assume'    => '2026-03-01',
				'time_to_assume'    => '08:00:00',
				'notes'             => null,
			),
			$extra
		);
	}

	private function notice( int $id, string $status ): object {
		return (object) array( 'id' => $id, 'code' => 'N-' . $id, 'name' => 'Notice ' . $id, 'status' => $status );
	}

	public function test_register_adds_the_shortcode(): void {
		Functions\expect( 'add_shortcode' )->once()->with( 'ffc_recruitment_my_calls', array( RecruitmentDashboardSection::class, 'render' ) );

		RecruitmentDashboardSection::register();
	}

	public function test_render_uses_the_view_as_user_when_set(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Mockery::mock( 'alias:FreeFormCertificate\Shortcodes\DashboardViewMode' )
			->shouldReceive( 'get_view_as_user_id' )->andReturn( 42 );
		$this->candidates->shouldReceive( 'get_by_user_id' )->once()->with( 42 )->andReturn( array() );

		$this->assertSame( '', RecruitmentDashboardSection::render() );
	}

	public function test_render_for_user_refuses_a_non_positive_id(): void {
		$this->candidates->shouldNotReceive( 'get_by_user_id' );

		$this->assertSame( '', RecruitmentDashboardSection::render_for_user( 0 ) );
	}

	public function test_a_candidate_whose_notices_are_gone_or_drafts_sees_nothing(): void {
		$this->candidates->shouldReceive( 'get_by_user_id' )->andReturn( array( (object) array( 'id' => 3 ) ) );
		$this->classifications->shouldReceive( 'get_for_candidate' )->with( 3 )->andReturn(
			array( $this->cls( 1, 10, 'definitive', 'empty' ), $this->cls( 2, 11, 'definitive', 'empty' ) )
		);
		$this->notices->shouldReceive( 'get_by_id' )->with( 10 )->andReturn( null );
		$this->notices->shouldReceive( 'get_by_id' )->with( 11 )->andReturn( $this->notice( 11, 'draft' ) );

		$this->assertSame( '', RecruitmentDashboardSection::render_for_user( 9 ) );
	}

	public function test_a_definitive_notice_lists_its_calls_with_each_situation(): void {
		$this->candidates->shouldReceive( 'get_by_user_id' )->andReturn( array( (object) array( 'id' => 3 ) ) );
		$rows = array(
			$this->cls( 1, 10, 'definitive', 'called' ),
			$this->cls( 2, 10, 'definitive', 'not_shown', 99 ),
			$this->cls( 3, 10, 'definitive', 'hired' ),
			$this->cls( 4, 10, 'definitive', 'empty' ),
			$this->cls( 5, 10, 'preview', 'empty' ),
			$this->cls( 6, 10, 'definitive', 'mystery' ),
		);
		$this->classifications->shouldReceive( 'get_for_candidate' )->andReturn( $rows );
		$this->notices->shouldReceive( 'get_by_id' )->andReturn( $this->notice( 10, 'definitive' ) );
		$this->calls->shouldReceive( 'get_history_for_classifications' )->once()->with( array( 1, 2, 3, 4, 5, 6 ) )->andReturn(
			array(
				$this->call( 1 ),
				$this->call( 2 ),
				$this->call( 3, null, array( 'notes' => 'Bring documents' ) ),
				$this->call( 4, null, array( 'called_at' => 0, 'date_to_assume' => null, 'time_to_assume' => '' ) ),
				$this->call( 1, '2026-02-02 10:00:00' ),
				$this->call( 77 ),
			)
		);

		$html = RecruitmentDashboardSection::render_for_user( 9 );

		$this->assertStringContainsString( '<h2>My Calls</h2>', $html );
		$this->assertStringContainsString( '<h3>N-10 — Notice 10</h3>', $html );
		$this->assertStringContainsString( 'ffc-banner-definitive', $html );
		$this->assertStringContainsString( 'Final classification', $html );
		// Classification table: the preview row is hidden, an unknown status passes through.
		$this->assertStringContainsString( '<td>—</td><td>2</td><td>87.50</td><td>Did not show up</td>', $html, 'A deleted adjutancy reads as a dash.' );
		$this->assertStringContainsString( '<td>Waiting</td>', $html );
		$this->assertStringContainsString( '<td>mystery</td>', $html );
		$this->assertStringNotContainsString( '<td>5</td>', $html );
		// Call history: one row per call that belongs to this notice.
		$this->assertStringContainsString( 'Call history', $html );
		$this->assertSame( 5, substr_count( $html, '<td>DT1700000000</td>' ) + substr_count( $html, '<td></td><td></td><td></td><td>Call reverted</td>' ) );
		$this->assertStringContainsString( '<td>Mathematics</td><td>DT1700000000</td><td>D2026-03-01</td><td>T08:00:00</td><td>Called</td><td></td>', $html );
		$this->assertStringContainsString( '<td>Hired</td><td>Bring documents</td>', $html );
		$this->assertStringContainsString( '<td>Cancelled</td>', $html );
		$this->assertStringContainsString( '<td>Did not show up</td><td></td></tr>', $html );
	}

	public function test_a_preliminary_notice_without_preview_rows_or_calls_says_so(): void {
		$this->candidates->shouldReceive( 'get_by_user_id' )->andReturn( array( (object) array( 'id' => 3 ) ) );
		$this->classifications->shouldReceive( 'get_for_candidate' )->andReturn( array( $this->cls( 1, 10, 'definitive', 'empty' ) ) );
		$this->notices->shouldReceive( 'get_by_id' )->andReturn( $this->notice( 10, 'preliminary' ) );
		$this->calls->shouldReceive( 'get_history_for_classifications' )->andReturn( array() );

		$html = RecruitmentDashboardSection::render_for_user( 9 );

		$this->assertStringContainsString( 'ffc-banner-preliminary', $html );
		$this->assertStringContainsString( 'No classification visible at the moment.', $html );
		$this->assertStringContainsString( 'You have not been called for this notice yet.', $html );
		$this->assertStringNotContainsString( 'Call history', $html );
	}
}
