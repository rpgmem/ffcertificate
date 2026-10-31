<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Recruitment\RecruitmentCallIcs;
use FreeFormCertificate\Recruitment\RecruitmentDashboardSection;

/**
 * Tests for the "My Calls" section (#1889): the view-as user, the grouping
 * into Called / Waiting / Closed, the derived "Deadline passed" status and
 * its +1-day boundary in the site timezone, the relative date and the
 * "leaves on" line of a card, and what the info popovers hold.
 *
 * The clock is always passed in, never read: the deadline rule snaps to a
 * site-timezone midnight, so a test reading `time()` once would fail on the
 * run that crosses it (CLAUDE.md "Test infrastructure").
 *
 * The readers, DateFormatter and DashboardViewMode are the real classes.
 * The readers run over a `$wpdb` double that answers each query from the
 * rows a test stores, by table and bound id, records every query, and fails
 * the test on one it does not expect. Dates render in the default formats
 * (`d/m/Y`, `H:i`) of an install nobody configured.
 *
 * @covers \FreeFormCertificate\Recruitment\RecruitmentDashboardSection
 */
class RecruitmentDashboardSectionCallsTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/**
	 * Candidate rows by WP user id.
	 *
	 * @var array<int, list<object>>
	 */
	private array $candidates = array();

	/**
	 * Classification rows by candidate id.
	 *
	 * @var array<int, list<object>>
	 */
	private array $classifications = array();

	/**
	 * Notice rows by id.
	 *
	 * @var array<int, object>
	 */
	private array $notices = array();

	/**
	 * Every call row in the database.
	 *
	 * @var list<object>
	 */
	private array $calls = array();

	/**
	 * Every query the database answered, in order.
	 *
	 * @var list<string>
	 */
	private array $queries = array();

	/** @var array<int, string> Script handles enqueued during the test. */
	private array $scripts = array();

	/** @var mixed The `$wpdb` global an earlier test left, restored afterwards. */
	private $wpdb_backup;

	/** @var bool Whether `$wpdb` was set before this test. */
	private bool $had_wpdb = false;

	/**
	 * 2026-10-18 12:00 in São Paulo (UTC-3, no DST since 2019).
	 */
	private const NOW = 1792335600;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->had_wpdb    = array_key_exists( 'wpdb', $GLOBALS );
		$this->wpdb_backup = $GLOBALS['wpdb'] ?? null;
		$_GET              = array();

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_attr__' )->returnArg();
		Functions\when( '_x' )->returnArg();
		Functions\when( 'esc_html_x' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( '_n' )->alias( static fn ( $single, $plural, $n ) => 1 === (int) $n ? $single : $plural );
		Functions\when( 'number_format_i18n' )->alias( static fn ( $n, $d = 0 ) => number_format( (float) $n, (int) $d ) );
		Functions\when( 'wp_timezone' )->alias( static fn () => new \DateTimeZone( 'America/Sao_Paulo' ) );
		Functions\when( 'wp_date' )->alias(
			static function ( $format, $ts = null, $tz = null ) {
				$zone = $tz instanceof \DateTimeZone ? $tz : new \DateTimeZone( 'America/Sao_Paulo' );
				return ( new \DateTimeImmutable( '@' . (int) $ts ) )->setTimezone( $zone )->format( (string) $format );
			}
		);
		Functions\when( 'admin_url' )->alias( static fn ( $path = '' ) => 'https://example.test/wp-admin/' . $path );
		Functions\when( 'wp_create_nonce' )->alias( static fn ( $action ) => 'nonce-' . $action );
		Functions\when( 'add_query_arg' )->alias( static fn ( array $args, string $url ) => $url . '?' . http_build_query( $args ) );
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		// No plugin settings stored: DateFormatter falls back to its defaults.
		Functions\when( 'get_option' )->alias( static fn ( $key, $default = false ) => $default );

		$this->scripts = array();
		Functions\when( 'wp_enqueue_script' )->alias(
			function ( $handle ) {
				$this->scripts[] = $handle;
			}
		);

		$this->candidates      = array();
		$this->classifications = array();
		$this->notices         = array();
		$this->calls           = array();
		$this->queries         = array();
		$GLOBALS['wpdb']       = $this->database();
	}

	protected function tearDown(): void {
		$_GET = array();
		if ( $this->had_wpdb ) {
			$GLOBALS['wpdb'] = $this->wpdb_backup;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * A database answering from the rows the test stored. Adjutancy 1 is
	 * Mathematics.
	 *
	 * @return Mockery\MockInterface
	 */
	private function database() {
		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';

		// Interpolates, so the reads below can dispatch on table and id.
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			static function ( $sql, ...$args ) {
				$values = ( 1 === count( $args ) && is_array( $args[0] ) ) ? $args[0] : $args;
				foreach ( $values as $v ) {
					$sql = (string) preg_replace( '/%[ids]/', (string) $v, (string) $sql, 1 );
				}
				return $sql;
			}
		);

		$adjutancies = array( 1 => (object) array( 'id' => 1, 'name' => 'Mathematics' ) );

		$wpdb->shouldReceive( 'get_row' )->andReturnUsing(
			function ( $sql ) use ( $adjutancies ) {
				$this->queries[] = (string) $sql;
				if ( 1 === preg_match( '/^SELECT \* FROM wp_ffc_recruitment_notice WHERE id = (\d+)$/', (string) $sql, $m ) ) {
					return $this->notices[ (int) $m[1] ] ?? null;
				}
				if ( 1 === preg_match( '/^SELECT \* FROM wp_ffc_recruitment_adjutancy WHERE id = (\d+)$/', (string) $sql, $m ) ) {
					return $adjutancies[ (int) $m[1] ] ?? null;
				}
				$this->fail( 'Unexpected get_row(): ' . $sql );
			}
		);

		$wpdb->shouldReceive( 'get_results' )->andReturnUsing(
			function ( $sql ) {
				$this->queries[] = (string) $sql;
				if ( 1 === preg_match( '/^SELECT \* FROM wp_ffc_recruitment_candidate WHERE user_id = (\d+)$/', (string) $sql, $m ) ) {
					return $this->candidates[ (int) $m[1] ] ?? array();
				}
				if ( 1 === preg_match( '/^SELECT \* FROM wp_ffc_recruitment_classification WHERE candidate_id = (\d+) /', (string) $sql, $m ) ) {
					return $this->classifications[ (int) $m[1] ] ?? array();
				}
				if ( 1 === preg_match( '/^SELECT \* FROM wp_ffc_recruitment_call WHERE classification_id IN \(([\d,]+)\)/', (string) $sql, $m ) ) {
					$ids = array_map( 'intval', explode( ',', $m[1] ) );
					return array_values(
						array_filter( $this->calls, static fn ( $call ) => in_array( (int) $call->classification_id, $ids, true ) )
					);
				}
				$this->fail( 'Unexpected get_results(): ' . $sql );
			}
		);

		return $wpdb;
	}

	/**
	 * The queries that read the given table.
	 *
	 * @param string $table Table name without the prefix.
	 * @return list<string>
	 */
	private function queries_on( string $table ): array {
		return array_values(
			array_filter( $this->queries, static fn ( $sql ) => str_contains( $sql, 'FROM wp_' . $table . ' ' ) )
		);
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

	private function call( int $id, int $classification, array $extra = array() ): object {
		return (object) array_merge(
			array(
				'id'                  => $id,
				'classification_id'   => $classification,
				'cancelled_at'        => null,
				'cancellation_reason' => null,
				'called_at'           => 1700000000,
				'date_to_assume'      => '2026-10-22',
				'time_to_assume'      => '08:00:00',
				'notes'               => null,
			),
			$extra
		);
	}

	private function notice( int $id, string $status ): object {
		return (object) array( 'id' => $id, 'code' => 'N-' . $id, 'name' => 'Notice ' . $id, 'status' => $status );
	}

	/**
	 * Store candidate 3, linked to user 9, with the given classifications,
	 * notices and calls.
	 *
	 * @param array<int, object> $classifications Classification rows.
	 * @param array<int, object> $notices         Notice rows by id.
	 * @param array<int, object> $calls           Call rows.
	 */
	private function wire( array $classifications, array $notices, array $calls ): void {
		$this->candidates      = array( 9 => array( (object) array( 'id' => 3 ) ) );
		$this->classifications = array( 3 => array_values( $classifications ) );
		$this->notices         = $notices;
		$this->calls           = array_values( $calls );
	}

	/**
	 * The HTML of one status section, or '' when it is not rendered.
	 */
	private function section( string $html, string $key ): string {
		if ( 1 !== preg_match( '#<section class="ffc-mycalls-section ffc-mycalls-section-' . $key . '".*?</section>#s', $html, $m ) ) {
			return '';
		}
		return $m[0];
	}

	public function test_register_adds_the_shortcode_and_the_calendar_download(): void {
		Functions\expect( 'add_shortcode' )->once()->with( 'ffc_recruitment_my_calls', array( RecruitmentDashboardSection::class, 'render' ) );
		Actions\expectAdded( 'admin_post_ffc_recruitment_call_ics' )->once()->with( array( RecruitmentCallIcs::class, 'handle' ) );

		RecruitmentDashboardSection::register();
	}

	public function test_render_uses_the_view_as_user_when_set(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		// An administrator with a valid view-as nonce for user 42.
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'absint' )->alias( static fn ( $v ) => abs( (int) $v ) );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->alias( 'trim' );
		Functions\when( 'wp_verify_nonce' )->alias( static fn ( $nonce, $action ) => 'good' === $nonce && 'ffc_view_as_user_42' === $action );
		Functions\when( 'get_user_by' )->alias( static fn ( $field, $id ) => 42 === (int) $id ? (object) array( 'ID' => 42 ) : false );
		$_GET = array(
			'ffc_view_as_user' => '42',
			'ffc_view_nonce'   => 'good',
		);

		$this->assertSame( '', RecruitmentDashboardSection::render() );
		$this->assertSame(
			array( 'SELECT * FROM wp_ffc_recruitment_candidate WHERE user_id = 42' ),
			$this->queries_on( 'ffc_recruitment_candidate' ),
			'The candidate is looked up once, for the viewed user and not the admin.'
		);
	}

	public function test_render_for_user_refuses_a_non_positive_id(): void {
		$this->assertSame( '', RecruitmentDashboardSection::render_for_user( 0, self::NOW ) );
		$this->assertSame( array(), $this->queries, 'No candidate is looked up.' );
	}

	public function test_a_candidate_whose_notices_are_gone_or_drafts_sees_nothing(): void {
		$this->wire(
			array( $this->cls( 1, 10, 'definitive', 'empty' ), $this->cls( 2, 11, 'definitive', 'empty' ) ),
			array( 11 => $this->notice( 11, 'draft' ) ),
			array( $this->call( 101, 1 ), $this->call( 102, 2 ) )
		);

		$this->assertSame( '', RecruitmentDashboardSection::render_for_user( 9, self::NOW ) );
		$this->assertSame( array(), $this->queries_on( 'ffc_recruitment_call' ), 'No call history is read.' );
		$this->assertSame( array(), $this->scripts, 'Nothing rendered, nothing to page.' );
	}

	public function test_classifications_are_grouped_into_the_three_sections_in_order(): void {
		$this->wire(
			array(
				$this->cls( 1, 10, 'definitive', 'called' ),     // Active, presentation in 4 days.
				$this->cls( 2, 10, 'definitive', 'accepted' ),   // Active, presentation passed: Deadline passed.
				$this->cls( 3, 10, 'definitive', 'hired' ),
				$this->cls( 4, 10, 'definitive', 'withdrew' ),
				$this->cls( 5, 10, 'definitive', 'not_shown' ),
				$this->cls( 6, 10, 'definitive', 'empty' ),
				$this->cls( 7, 12, 'preview', 'empty' ),          // Preliminary notice.
				$this->cls( 8, 10, 'preview', 'empty' ),          // Wrong list for a definitive notice.
				$this->cls( 9, 10, 'definitive', 'called' ),     // Called, but its only call was reverted.
			),
			array(
				10 => $this->notice( 10, 'definitive' ),
				12 => $this->notice( 12, 'preliminary' ),
			),
			array(
				$this->call( 101, 1, array( 'notes' => 'Bring documents' ) ),
				$this->call( 102, 2, array( 'date_to_assume' => '2026-10-15' ) ),
				$this->call( 103, 3, array( 'date_to_assume' => '2026-09-01', 'notes' => 'Morning shift' ) ),
				$this->call( 109, 9, array( 'cancelled_at' => 1700001000 ) ),
			)
		);

		$html = RecruitmentDashboardSection::render_for_user( 9, self::NOW );

		$this->assertStringContainsString( '<h2>My Calls</h2>', $html );
		$called_at  = strpos( $html, 'id="ffc-mycalls-called"' );
		$waiting_at = strpos( $html, 'id="ffc-mycalls-waiting"' );
		$closed_at  = strpos( $html, 'id="ffc-mycalls-closed"' );
		$this->assertNotFalse( $called_at );
		$this->assertTrue( $called_at < $waiting_at && $waiting_at < $closed_at, 'Called, then Waiting, then Closed.' );

		// The counters, each linking to its section.
		$this->assertStringContainsString( 'href="#ffc-mycalls-called"><b>1</b> active call</a>', $html );
		$this->assertStringContainsString( 'href="#ffc-mycalls-waiting"><b>3</b> waiting</a>', $html );
		$this->assertStringContainsString( 'href="#ffc-mycalls-closed"><b>4</b> closed</a>', $html );

		$called = $this->section( $html, 'called' );
		$this->assertSame( 1, substr_count( $called, '<article class="ffc-mycalls-card">' ) );
		$this->assertStringContainsString( '<span class="ffc-mycalls-notice-code">N-10</span> <span class="ffc-mycalls-notice-name">Notice 10</span>', $called );
		$this->assertStringContainsString( '<strong>22/10/2026 at 08:00</strong>', $called );
		$this->assertStringContainsString( 'in 4 days', $called );
		$this->assertStringContainsString( 'This card leaves on 23/10/2026.', $called );
		$this->assertStringContainsString( '<dd>Bring documents</dd>', $called );
		$this->assertStringContainsString( '<dd>14/11/2023 19:13</dd>', $called, 'Called at is the call\'s called_at.' );
		$this->assertStringContainsString( 'ffc-icon-calendar-check', $called );
		$this->assertStringContainsString( 'action=ffc_recruitment_call_ics&call=101&_wpnonce=nonce-ffc_recruitment_call_ics_101', $called );

		$waiting = $this->section( $html, 'waiting' );
		$final   = strpos( $waiting, 'data-group="final"><td colspan="5">Final classification' );
		$prelim  = strpos( $waiting, 'data-group="preliminary"><td colspan="5">Preliminary classification — subject to review' );
		$this->assertNotFalse( $final );
		$this->assertNotFalse( $prelim );
		$this->assertLessThan( $prelim, $final, 'Final group first.' );
		$this->assertSame( 2, substr_count( $waiting, '<tr class="ffc-mycalls-group-row"' ), 'One label per group, not per row.' );
		$this->assertSame( 3, substr_count( $waiting, '<tr class="ffc-mycalls-row"' ) );
		$this->assertStringContainsString( 'N-12', $waiting );
		$this->assertStringContainsString( 'data-per-page="5"', $waiting );
		$this->assertStringContainsString( 'Previous call reverted', $waiting, 'The reverted call explains itself behind the i.' );
		$this->assertStringNotContainsString( 'data-label="Position">8</td>', $waiting, 'A preview row on a definitive notice is not shown.' );

		$closed = $this->section( $html, 'closed' );
		$this->assertSame( 4, substr_count( $closed, '<tr class="ffc-mycalls-row"' ) );
		$this->assertStringContainsString( 'ffc-mycalls-badge-expired">Deadline passed</span>', $closed );
		$this->assertStringContainsString( 'ffc-mycalls-badge-hired">Hired</span>', $closed );
		$this->assertStringContainsString( 'ffc-mycalls-badge-withdrew">Withdrew</span>', $closed );
		$this->assertStringContainsString( 'ffc-mycalls-badge-not-shown">Did not show up</span>', $closed );
		$this->assertStringContainsString( 'data-per-page="2"', $closed );
		$this->assertStringContainsString( '<td data-label="Notes">Morning shift</td>', $closed );
		$this->assertStringContainsString( 'data-label="Presentation date">—</td>', $closed, 'A row without a call has no date.' );
		// Most recent presentation first.
		$this->assertLessThan( strpos( $closed, '>Hired<' ), strpos( $closed, '>Deadline passed<' ) );

		$this->assertSame( array( 'ffc-recruitment-my-calls' ), $this->scripts );
	}

	public function test_an_empty_section_and_its_counter_are_not_rendered(): void {
		$this->wire(
			array( $this->cls( 1, 10, 'definitive', 'empty' ) ),
			array( 10 => $this->notice( 10, 'definitive' ) ),
			array()
		);

		$html = RecruitmentDashboardSection::render_for_user( 9, self::NOW );

		$this->assertStringContainsString( 'id="ffc-mycalls-waiting"', $html );
		$this->assertStringNotContainsString( 'id="ffc-mycalls-called"', $html );
		$this->assertStringNotContainsString( 'id="ffc-mycalls-closed"', $html );
		$this->assertStringNotContainsString( 'href="#ffc-mycalls-called"', $html );
		$this->assertStringNotContainsString( 'href="#ffc-mycalls-closed"', $html );
		$this->assertStringContainsString( '<b>1</b> waiting', $html );
	}

	public function test_the_card_stays_through_its_presentation_day_and_leaves_at_the_next_midnight_in_the_site_timezone(): void {
		$tz       = new \DateTimeZone( 'America/Sao_Paulo' );
		$midnight = ( new \DateTimeImmutable( '2026-10-20 00:00:00', $tz ) )->getTimestamp();

		// One second before São Paulo's midnight it is 02:59:59 UTC on the
		// 20th: a UTC reading would already have moved the card.
		$this->assertFalse( RecruitmentDashboardSection::deadline_passed( '2026-10-19', $midnight - 1 ) );
		$this->assertTrue( RecruitmentDashboardSection::deadline_passed( '2026-10-19', $midnight ) );
		$this->assertSame( '2026-10-20T03:00:00+00:00', gmdate( 'c', $midnight ), 'The boundary is the site\'s midnight, not UTC\'s.' );

		$this->assertSame( '2026-10-20', RecruitmentDashboardSection::leaves_on( '2026-10-19' ) );
		$this->assertSame( '', RecruitmentDashboardSection::leaves_on( 'not-a-date' ) );
		$this->assertFalse( RecruitmentDashboardSection::deadline_passed( '2026-02-30', PHP_INT_MAX ), 'An unreadable date never moves the card.' );
	}

	public function test_a_call_on_both_sides_of_the_boundary_renders_in_called_then_in_closed(): void {
		$tz       = new \DateTimeZone( 'America/Sao_Paulo' );
		$midnight = ( new \DateTimeImmutable( '2026-10-20 00:00:00', $tz ) )->getTimestamp();
		$entries  = array(
			array(
				'notice'         => $this->notice( 10, 'definitive' ),
				'classification' => $this->cls( 1, 10, 'definitive', 'called' ),
				'calls'          => array( $this->call( 101, 1, array( 'date_to_assume' => '2026-10-19' ) ) ),
			),
		);

		$before = RecruitmentDashboardSection::build_sections( $entries, $midnight - 1 );
		$this->assertCount( 1, $before['called'] );
		$this->assertCount( 0, $before['closed'] );

		$after = RecruitmentDashboardSection::build_sections( $entries, $midnight );
		$this->assertCount( 0, $after['called'] );
		$this->assertCount( 1, $after['closed'] );
		$this->assertSame( 'expired', $after['closed'][0]['status'] );
	}

	public function test_the_relative_label_reads_today_tomorrow_and_in_n_days(): void {
		$this->assertSame( 0, RecruitmentDashboardSection::days_until( '2026-10-18', self::NOW ) );
		$this->assertSame( 1, RecruitmentDashboardSection::days_until( '2026-10-19', self::NOW ) );
		$this->assertSame( 4, RecruitmentDashboardSection::days_until( '2026-10-22', self::NOW ) );
		$this->assertSame( -1, RecruitmentDashboardSection::days_until( '2026-10-17', self::NOW ) );
		$this->assertNull( RecruitmentDashboardSection::days_until( 'nope', self::NOW ) );

		$this->wire(
			array( $this->cls( 1, 10, 'definitive', 'called' ), $this->cls( 2, 10, 'definitive', 'called' ) ),
			array( 10 => $this->notice( 10, 'definitive' ) ),
			array(
				$this->call( 102, 2, array( 'date_to_assume' => '2026-10-19' ) ),
				$this->call( 101, 1, array( 'date_to_assume' => '2026-10-18' ) ),
			)
		);

		$html = RecruitmentDashboardSection::render_for_user( 9, self::NOW );

		$this->assertStringContainsString( 'ffc-mycalls-countdown ffc-mycalls-countdown-today">today</span>', $html );
		$this->assertStringContainsString( '<span class="ffc-mycalls-countdown">tomorrow</span>', $html );
		$this->assertLessThan( strpos( $html, '>tomorrow<' ), strpos( $html, '>today<' ), 'The nearest presentation comes first.' );
	}

	public function test_a_passed_deadline_explains_itself_only_behind_the_info_button(): void {
		$this->wire(
			array( $this->cls( 1, 10, 'definitive', 'called' ) ),
			array( 10 => $this->notice( 10, 'definitive' ) ),
			array( $this->call( 101, 1, array( 'date_to_assume' => '2026-10-15', 'time_to_assume' => '13:30:00' ) ) )
		);

		$html   = RecruitmentDashboardSection::render_for_user( 9, self::NOW );
		$closed = $this->section( $html, 'closed' );

		$this->assertMatchesRegularExpression(
			'#<button type="button" class="ffc-btn ffc-btn-neutral ffc-btn-icon ffc-icon-info ffc-mycalls-info-btn" aria-label="About the passed deadline" title="About the passed deadline" aria-expanded="false" aria-controls="(ffc-mycalls-pop-\d+)"></button><div class="ffc-mycalls-pop" id="\1" hidden>#',
			$closed
		);
		$this->assertStringContainsString( '<strong class="ffc-mycalls-pop-title">Status not updated yet</strong>', $closed );
		$this->assertStringContainsString( '<dt>Presentation</dt><dd>15/10/2026 13:30</dd>', $closed );
		$this->assertStringContainsString( 'has not recorded the result yet', $closed );
		// Only the badge and the i: no text sits under the badge.
		$this->assertStringContainsString( 'Deadline passed</span><div class="ffc-mycalls-info">', $closed );
		$this->assertStringNotContainsString( 'id="ffc-mycalls-called"', $html );
	}

	public function test_a_reverted_call_lists_its_dates_and_reason_and_omits_an_empty_reason(): void {
		$with = RecruitmentDashboardSection::reverted_info(
			$this->call(
				101,
				1,
				array(
					'called_at'           => 1700000000,
					'cancelled_at'        => 1700086400,
					'cancellation_reason' => 'Called by mistake.',
				)
			)
		);
		$this->assertStringContainsString( 'aria-label="About the reverted call"', $with );
		$this->assertStringContainsString( '<strong class="ffc-mycalls-pop-title">Previous call reverted</strong>', $with );
		$this->assertStringContainsString( '<dt>Called on</dt><dd>14/11/2023 19:13</dd>', $with );
		$this->assertStringContainsString( '<dt>Reverted on</dt><dd>15/11/2023 19:13</dd>', $with );
		$this->assertStringContainsString( '<dt>Reason</dt><dd>Called by mistake.</dd>', $with );

		$without = '';
		foreach ( array( null, '', '   ' ) as $empty ) {
			$without = RecruitmentDashboardSection::reverted_info(
				$this->call( 102, 1, array( 'cancelled_at' => 1700086400, 'cancellation_reason' => $empty ) )
			);
			$this->assertStringContainsString( '<dt>Reverted on</dt>', $without );
			$this->assertStringNotContainsString( 'Reason', $without );
		}

		// Two popovers never share an id.
		preg_match_all( '#id="(ffc-mycalls-pop-\d+)"#', $with . $without, $ids );
		$this->assertCount( 2, array_unique( $ids[1] ) );
	}

	public function test_a_reverted_call_on_a_closed_row_sits_beside_the_status_badge(): void {
		$this->wire(
			array( $this->cls( 1, 10, 'definitive', 'withdrew' ) ),
			array( 10 => $this->notice( 10, 'definitive' ) ),
			array(
				$this->call( 101, 1, array( 'called_at' => 1700000000, 'cancelled_at' => 1700086400, 'cancellation_reason' => 'Date changed.' ) ),
				$this->call( 102, 1, array( 'called_at' => 1700100000, 'date_to_assume' => '2026-09-10' ) ),
			)
		);

		$closed = $this->section( RecruitmentDashboardSection::render_for_user( 9, self::NOW ), 'closed' );

		$this->assertStringContainsString( 'Withdrew</span><div class="ffc-mycalls-info">', $closed );
		$this->assertStringContainsString( '<dt>Reason</dt><dd>Date changed.</dd>', $closed );
		$this->assertStringContainsString( '10/09/2026 08:00', $closed, 'The presentation date is the live call\'s.' );
	}

	public function test_a_closed_notice_moves_its_open_rows_to_closed_without_touching_the_stored_status(): void {
		$tz       = new \DateTimeZone( 'America/Sao_Paulo' );
		$midnight = ( new \DateTimeImmutable( '2026-10-20 00:00:00', $tz ) )->getTimestamp();
		$notice   = $this->notice( 10, 'closed' );
		$rows     = array(
			'empty'    => $this->cls( 1, 10, 'definitive', 'empty' ),
			'called'   => $this->cls( 2, 10, 'definitive', 'called' ),
			'accepted' => $this->cls( 3, 10, 'definitive', 'accepted' ),
			'expired'  => $this->cls( 4, 10, 'definitive', 'called' ),
			'hired'    => $this->cls( 5, 10, 'definitive', 'hired' ),
		);
		$entries  = array(
			array( 'notice' => $notice, 'classification' => $rows['empty'], 'calls' => array() ),
			array( 'notice' => $notice, 'classification' => $rows['called'], 'calls' => array( $this->call( 102, 2, array( 'date_to_assume' => '2026-10-25' ) ) ) ),
			array( 'notice' => $notice, 'classification' => $rows['accepted'], 'calls' => array( $this->call( 103, 3, array( 'date_to_assume' => '2026-10-25' ) ) ) ),
			array( 'notice' => $notice, 'classification' => $rows['expired'], 'calls' => array( $this->call( 104, 4, array( 'date_to_assume' => '2026-10-19' ) ) ) ),
			array( 'notice' => $notice, 'classification' => $rows['hired'], 'calls' => array( $this->call( 105, 5 ) ) ),
		);

		$sections = RecruitmentDashboardSection::build_sections( $entries, $midnight );

		$this->assertSame( array(), $sections['called'], 'Nothing is still called on a closed notice.' );
		$this->assertSame( array(), $sections['waiting'], 'Nothing waits on a closed notice.' );
		$by_id = array();
		foreach ( $sections['closed'] as $item ) {
			$by_id[ (int) $item['classification']->id ] = $item['status'];
		}
		ksort( $by_id );
		$this->assertSame(
			array(
				1 => 'notice_closed',
				2 => 'notice_closed',
				3 => 'notice_closed',
				4 => 'expired',
				5 => 'hired',
			),
			$by_id,
			'Only a row with no outcome reads "Notice closed"; an outcome keeps its badge.'
		);
		$this->assertSame( 'empty', $rows['empty']->status, 'The label is visual: the classification row is never rewritten.' );
		$this->assertSame( 'called', $rows['called']->status );
		$this->assertSame( 'accepted', $rows['accepted']->status );
	}

	public function test_a_closed_notice_row_renders_its_badge_in_the_closed_section(): void {
		$this->wire(
			array( $this->cls( 1, 10, 'definitive', 'empty' ) ),
			array( 10 => $this->notice( 10, 'closed' ) ),
			array()
		);

		$html = RecruitmentDashboardSection::render_for_user( 9, self::NOW );

		$this->assertStringNotContainsString( 'id="ffc-mycalls-waiting"', $html );
		$this->assertStringContainsString( '<span class="ffc-pill ffc-mycalls-badge-notice-closed">Notice closed</span>', $this->section( $html, 'closed' ) );
	}
}
