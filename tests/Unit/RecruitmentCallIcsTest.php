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
 * The five readers, Capabilities and IcsGenerator are the real ones, over a
 * `$wpdb` double that answers `get_row()` from a per-table store keyed by id
 * and records every lookup. The handler's refusals end in a throwing
 * wp_die() stub, so no test reaches its headers or `exit`.
 *
 * @covers \FreeFormCertificate\Recruitment\RecruitmentCallIcs
 */
class RecruitmentCallIcsTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var bool Whether `$wpdb` was set before this test. */
	private bool $had_wpdb = false;

	/** @var mixed The `$wpdb` global an earlier test left, restored afterwards. */
	private $wpdb_backup;

	/**
	 * Stored rows, by short table name (without the `wp_ffc_recruitment_`
	 * prefix) and id.
	 *
	 * @var array<string, array<int, object>>
	 */
	private array $rows = array();

	/**
	 * Every `get_row()` lookup, as "<short table>#<id>".
	 *
	 * @var list<string>
	 */
	private array $reads = array();

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
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'wp_cache_delete' )->justReturn( true );

		$this->reads = array();
		$this->rows  = array(
			'call'           => array(),
			'classification' => array(
				7 => (object) array( 'id' => 7, 'candidate_id' => 3, 'notice_id' => 10, 'adjutancy_id' => 1 ),
			),
			'candidate'      => array(
				3 => (object) array( 'id' => 3, 'user_id' => '100' ),
			),
			'notice'         => array(
				10 => (object) array( 'id' => 10, 'code' => 'EDT-15', 'name' => 'Temporary teachers', 'status' => 'definitive' ),
			),
			'adjutancy'      => array(
				1 => (object) array( 'id' => 1, 'name' => 'Mathematics' ),
			),
		);

		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		// Every reader's get_by_id() prepares 'SELECT * FROM %i WHERE id = %d'
		// with the table, then the id; the prepared string carries both.
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			function ( $sql, ...$args ) {
				$this->assertSame( 'SELECT * FROM %i WHERE id = %d', $sql, 'Only lookups by id are expected.' );
				return $args[0] . '#' . (int) $args[1];
			}
		);
		$wpdb->shouldReceive( 'get_row' )->andReturnUsing(
			function ( $prepared ) {
				[ $table, $id ] = explode( '#', (string) $prepared );
				$this->assertStringStartsWith( 'wp_ffc_recruitment_', $table );
				$short = substr( $table, strlen( 'wp_ffc_recruitment_' ) );
				$this->assertArrayHasKey( $short, $this->rows, 'Unexpected table: ' . $table );
				$this->reads[] = $short . '#' . $id;
				return $this->rows[ $short ][ (int) $id ] ?? null;
			}
		);

		$this->had_wpdb    = array_key_exists( 'wpdb', $GLOBALS );
		$this->wpdb_backup = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb']   = $wpdb;
	}

	protected function tearDown(): void {
		if ( $this->had_wpdb ) {
			$GLOBALS['wpdb'] = $this->wpdb_backup;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		Monkey\tearDown();
		unset( $_GET['call'], $_GET['_wpnonce'] );
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
		$this->rows['call'][55] = $this->call();

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
		$this->rows['call'][55] = $this->call();

		$this->assertNull( RecruitmentCallIcs::build( 55, 200, false ), 'Somebody else\'s call.' );
		$this->assertIsString( RecruitmentCallIcs::build( 55, 200, true ), 'A view-as holder sees the card, so it gets the file.' );
		$this->assertNull( RecruitmentCallIcs::build( 55, 0, true ), 'No user, no file.' );
	}

	public function test_a_cancelled_missing_or_draft_call_gets_nothing(): void {
		$this->rows['call'][55] = $this->call( array( 'cancelled_at' => '1700000000' ) );
		// No row 56.
		$this->rows['call'][57] = $this->call( array( 'id' => 57, 'classification_id' => 999 ) );

		$this->assertNull( RecruitmentCallIcs::build( 55, 100, false ) );
		$this->assertNull( RecruitmentCallIcs::build( 56, 100, false ) );
		$this->assertNull( RecruitmentCallIcs::build( 57, 100, false ), 'A call whose classification is gone.' );
		$this->assertNull( RecruitmentCallIcs::build( 0, 100, false ) );
	}

	public function test_a_draft_notice_gets_nothing(): void {
		$this->rows['call'][55]   = $this->call();
		$this->rows['notice'][10] = (object) array( 'id' => 10, 'code' => 'X', 'name' => 'X', 'status' => 'draft' );

		$this->assertNull( RecruitmentCallIcs::build( 55, 100, false ) );
	}

	public function test_a_late_presentation_ends_on_its_own_day_and_a_short_time_reads(): void {
		$this->rows['call'][55] = $this->call( array( 'time_to_assume' => '23:30' ) );

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

		try {
			RecruitmentCallIcs::handle();
			$this->fail( 'The handler must refuse.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( '403', $e->getMessage() );
		}
		$this->assertSame( array(), $this->reads, 'A bad nonce must not read the call.' );
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
		$this->rows['call'][55] = $this->call();

		$this->expectExceptionMessage( '404' );
		RecruitmentCallIcs::handle();
	}
}
