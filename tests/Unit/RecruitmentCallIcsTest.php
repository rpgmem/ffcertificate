<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Recruitment\RecruitmentCallIcs;

/**
 * The "Add to calendar" download of a "My Calls" card (#1889): the file it
 * builds, and the two gates in front of it — the per-call nonce, and
 * ownership read from the call up to the candidate's account, so a user
 * asking for somebody else's call gets nothing.
 *
 * Every reader is aliased, so each test runs in its own process.
 *
 * @covers \FreeFormCertificate\Recruitment\RecruitmentCallIcs
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class RecruitmentCallIcsTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var Mockery\MockInterface */
	private $calls;

	/** @var Mockery\MockInterface */
	private $notices;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'get_bloginfo' )->justReturn( 'Site' );
		Functions\when( 'home_url' )->justReturn( 'https://example.test' );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'absint' )->alias( static fn ( $v ) => abs( (int) $v ) );
		Functions\when( 'wp_parse_url' )->alias( static fn ( $url, $part = -1 ) => parse_url( $url, $part ) );

		$this->calls   = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentCallReader' );
		$this->notices = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentNoticeReader' );

		Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentClassificationRepository' )
			->shouldReceive( 'get_by_id' )->andReturnUsing(
				static fn ( $id ) => 7 === $id ? (object) array( 'id' => 7, 'candidate_id' => 3, 'notice_id' => 10, 'adjutancy_id' => 1 ) : null
			);
		Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentCandidateReader' )
			->shouldReceive( 'get_by_id' )->andReturnUsing(
				static fn ( $id ) => 3 === $id ? (object) array( 'id' => 3, 'user_id' => '100' ) : null
			);
		Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentAdjutancyReader' )
			->shouldReceive( 'get_by_id' )->andReturn( (object) array( 'id' => 1, 'name' => 'Mathematics' ) );

		$this->notices->shouldReceive( 'get_by_id' )->andReturn(
			(object) array( 'id' => 10, 'code' => 'EDT-15', 'name' => 'Temporary teachers', 'status' => 'definitive' )
		)->byDefault();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function call( array $extra = array() ): object {
		return (object) array_merge(
			array(
				'id'                => 55,
				'classification_id' => 7,
				'cancelled_at'      => null,
				'date_to_assume'    => '2026-10-19',
				'time_to_assume'    => '13:30:00',
				'notes'             => 'Bring your ID; and diploma',
			),
			$extra
		);
	}

	public function test_the_owner_gets_a_one_hour_event_at_the_presentation(): void {
		$this->calls->shouldReceive( 'get_by_id' )->with( 55 )->andReturn( $this->call() );

		$ics = RecruitmentCallIcs::build( 55, 100, false );

		$this->assertIsString( $ics );
		$this->assertStringStartsWith( "BEGIN:VCALENDAR\r\n", $ics );
		$this->assertStringContainsString( "METHOD:PUBLISH\r\n", $ics );
		$this->assertStringContainsString( "UID:ffc-recruitment-call-55@example.test\r\n", $ics );
		$this->assertStringContainsString( "DTSTART:20261019T133000\r\n", $ics, 'Wall-clock: written as floating local time.' );
		$this->assertStringContainsString( "DTEND:20261019T143000\r\n", $ics );
		$this->assertStringContainsString( "SUMMARY:Presentation: EDT-15 — Mathematics\r\n", $ics );
		$this->assertStringContainsString( "DESCRIPTION:Temporary teachers\\nBring your ID\\; and diploma\r\n", $ics );
		$this->assertStringEndsWith( "END:VCALENDAR\r\n", $ics );
	}

	public function test_another_user_gets_nothing_unless_they_may_view_as_the_owner(): void {
		$this->calls->shouldReceive( 'get_by_id' )->with( 55 )->andReturn( $this->call() );

		$this->assertNull( RecruitmentCallIcs::build( 55, 200, false ), 'Somebody else\'s call.' );
		$this->assertIsString( RecruitmentCallIcs::build( 55, 200, true ), 'A view-as holder sees the card, so it gets the file.' );
		$this->assertNull( RecruitmentCallIcs::build( 55, 0, true ), 'No user, no file.' );
	}

	public function test_a_cancelled_missing_or_draft_call_gets_nothing(): void {
		$this->calls->shouldReceive( 'get_by_id' )->with( 55 )->andReturn( $this->call( array( 'cancelled_at' => '1700000000' ) ) );
		$this->calls->shouldReceive( 'get_by_id' )->with( 56 )->andReturn( null );
		$this->calls->shouldReceive( 'get_by_id' )->with( 57 )->andReturn( $this->call( array( 'id' => 57, 'classification_id' => 999 ) ) );

		$this->assertNull( RecruitmentCallIcs::build( 55, 100, false ) );
		$this->assertNull( RecruitmentCallIcs::build( 56, 100, false ) );
		$this->assertNull( RecruitmentCallIcs::build( 57, 100, false ), 'A call whose classification is gone.' );
		$this->assertNull( RecruitmentCallIcs::build( 0, 100, false ) );
	}

	public function test_a_draft_notice_gets_nothing(): void {
		$this->calls->shouldReceive( 'get_by_id' )->andReturn( $this->call() );
		$this->notices->shouldReceive( 'get_by_id' )->andReturn( (object) array( 'id' => 10, 'code' => 'X', 'name' => 'X', 'status' => 'draft' ) );

		$this->assertNull( RecruitmentCallIcs::build( 55, 100, false ) );
	}

	public function test_a_late_presentation_ends_on_its_own_day_and_a_short_time_reads(): void {
		$this->calls->shouldReceive( 'get_by_id' )->andReturn( $this->call( array( 'time_to_assume' => '23:30' ) ) );

		$ics = (string) RecruitmentCallIcs::build( 55, 100, false );

		$this->assertStringContainsString( "DTSTART:20261019T233000\r\n", $ics );
		$this->assertStringContainsString( "DTEND:20261019T235959\r\n", $ics );
	}

	public function test_the_link_carries_a_nonce_bound_to_the_call(): void {
		Functions\when( 'admin_url' )->alias( static fn ( $path ) => 'https://example.test/wp-admin/' . $path );
		Functions\when( 'add_query_arg' )->alias( static fn ( array $args, string $url ) => $url . '?' . http_build_query( $args ) );
		Functions\expect( 'wp_create_nonce' )->once()->with( 'ffc_recruitment_call_ics_55' )->andReturn( 'abc' );

		$this->assertSame(
			'https://example.test/wp-admin/admin-post.php?action=ffc_recruitment_call_ics&call=55&_wpnonce=abc',
			RecruitmentCallIcs::url( 55 )
		);
	}

	public function test_the_handler_refuses_a_bad_nonce_with_403(): void {
		$_GET = array(
			'call'     => '55',
			'_wpnonce' => 'forged',
		);
		Functions\expect( 'wp_verify_nonce' )->once()->with( 'forged', 'ffc_recruitment_call_ics_55' )->andReturn( false );
		Functions\when( 'wp_die' )->alias(
			static function ( $message, $title, $args ) {
				throw new \RuntimeException( (string) $args['response'] );
			}
		);
		$this->calls->shouldNotReceive( 'get_by_id' );

		$this->expectExceptionMessage( '403' );
		RecruitmentCallIcs::handle();
	}

	public function test_the_handler_refuses_another_users_call_with_404(): void {
		$_GET = array(
			'call'     => '55',
			'_wpnonce' => 'good',
		);
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		Functions\when( 'get_current_user_id' )->justReturn( 200 );
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( 'wp_die' )->alias(
			static function ( $message, $title, $args ) {
				throw new \RuntimeException( (string) $args['response'] );
			}
		);
		$this->calls->shouldReceive( 'get_by_id' )->with( 55 )->andReturn( $this->call() );

		$this->expectExceptionMessage( '404' );
		RecruitmentCallIcs::handle();
	}
}
