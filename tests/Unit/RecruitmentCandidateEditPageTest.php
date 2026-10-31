<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Core\Encryption;
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
 * Every collaborator is the real class: the recruitment readers and
 * repositories, RecruitmentCandidateHistoryService over ActivityLogQuery,
 * RecruitmentPiiAccessPolicy over the shared PiiAccessPolicy, Encryption
 * with the bootstrap's key constants, DocumentFormatter and DateFormatter.
 * They run over a `$wpdb` double that answers each read from the rows a
 * test stores and fails on any query it does not recognise.
 *
 * The PII tier is chosen through the data, as the real policy decides it:
 * the candidate row is linked to the current user (the owner clause, which
 * is the unmasked tier) unless a test unlinks it to reach the reveal tier.
 *
 * @covers \FreeFormCertificate\Recruitment\RecruitmentCandidateEditPage
 */
class RecruitmentCandidateEditPageTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** The current user, who is also the candidate's linked account by default. */
	private const CURRENT_USER = 1;

	/** @var bool Whether `$wpdb` was set before this test. */
	private bool $had_wpdb = false;

	/** @var mixed The `$wpdb` global an earlier test left, restored afterwards. */
	private $wpdb_backup;

	/**
	 * Prepared statements by the token prepare() returned: [sql, args].
	 *
	 * @var array<string, array{0: string, 1: array<int, mixed>}>
	 */
	private array $prepared = array();

	/** @var array<int, object> Candidate rows by id. */
	private array $candidates = array();

	/** @var list<object> Classification rows. */
	private array $classifications = array();

	/** @var list<object> Call rows. */
	private array $calls = array();

	/** @var array<int, list<int>> Attached adjutancy ids per notice id. */
	private array $junction = array();

	/** @var array<int, object> Notice rows by id. */
	private array $notices = array();

	/** @var array<int, object> Adjutancy rows by id. */
	private array $adjutancies = array();

	/** @var list<array<string, mixed>> Activity-log rows, as `ARRAY_A`. */
	private array $activity = array();

	/**
	 * Every row lookup by id, as [table, id].
	 *
	 * @var list<array{0: string, 1: int}>
	 */
	private array $row_lookups = array();

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
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'sanitize_key' )->returnArg();
		Functions\when( 'absint' )->alias( static fn ( $v ) => (int) $v );
		Functions\when( 'admin_url' )->returnArg();
		Functions\when( 'current_user_can' )->justReturn( true );
		// PiiAccessPolicy asks user_can() only past the owner clause, i.e. for
		// a candidate not linked to the current user: that user may reveal.
		Functions\when( 'user_can' )->justReturn( true );
		// The status cell now comes from `RecruitmentAdminPage::classification_status_badge()`,
		// which reads the configurable colours from Settings (#1193). Empty = defaults.
		// No settings also keeps DateFormatter on its default formats.
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'get_current_user_id' )->justReturn( self::CURRENT_USER );
		Functions\when( 'get_userdata' )->alias(
			static fn ( $id ) => self::CURRENT_USER === (int) $id
				? (object) array(
					'ID'           => self::CURRENT_USER,
					'user_login'   => 'jdoe',
					'display_name' => 'Jane Doe',
				)
				: false
		);
		Functions\when( 'get_edit_user_link' )->alias( static fn ( $id ) => 'user-edit.php?user_id=' . (int) $id );
		Functions\when( 'is_email' )->alias( static fn ( $v ) => false !== filter_var( $v, FILTER_VALIDATE_EMAIL ) );
		Functions\when( 'wp_parse_args' )->alias( static fn ( $args, $defaults ) => array_merge( $defaults, (array) $args ) );
		Functions\when( 'wp_timezone' )->alias( static fn () => new \DateTimeZone( 'UTC' ) );
		Functions\when( 'wp_date' )->alias(
			static fn ( $format, $ts, $tz = null ) => ( new \DateTimeImmutable( '@' . (int) $ts ) )
				->setTimezone( $tz instanceof \DateTimeZone ? $tz : new \DateTimeZone( 'UTC' ) )
				->format( (string) $format )
		);
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'wp_cache_delete' )->justReturn( true );
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

		$this->prepared        = array();
		$this->candidates      = array();
		$this->classifications = array();
		$this->calls           = array();
		$this->junction        = array();
		$this->notices         = array();
		$this->adjutancies     = array();
		$this->activity        = array();
		$this->row_lookups     = array();

		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			function ( $sql, ...$args ) {
				// Some callers bind one array instead of a variadic list.
				if ( 1 === count( $args ) && is_array( $args[0] ) ) {
					$args = array_values( $args[0] );
				}
				$token                    = 'PREPARED#' . count( $this->prepared );
				$this->prepared[ $token ] = array( (string) $sql, $args );
				return $token;
			}
		);
		$wpdb->shouldReceive( 'get_row' )->andReturnUsing( fn ( $q ) => $this->answer_row( (string) $q ) );
		$wpdb->shouldReceive( 'get_results' )->andReturnUsing( fn ( $q ) => $this->answer_results( (string) $q ) );
		$wpdb->shouldReceive( 'get_var' )->andReturnUsing( fn ( $q ) => $this->answer_var( (string) $q ) );
		$wpdb->shouldReceive( 'get_col' )->andReturnUsing( fn ( $q ) => $this->answer_col( (string) $q ) );

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
		unset( $_GET['candidate_id'] );
		parent::tearDown();
	}

	/**
	 * The statement and bound arguments behind a prepare() token.
	 *
	 * @param string $token Token prepare() returned.
	 * @return array{0: string, 1: array<int, mixed>}
	 */
	private function statement( string $token ): array {
		if ( ! isset( $this->prepared[ $token ] ) ) {
			$this->fail( 'Query run without prepare(): ' . $token );
		}
		return $this->prepared[ $token ];
	}

	private function answer_row( string $token ) {
		[ $sql, $args ] = $this->statement( $token );
		if ( 'SELECT * FROM %i WHERE id = %d' !== $sql ) {
			$this->fail( 'Unexpected get_row(): ' . $sql );
		}
		$table               = (string) $args[0];
		$id                  = (int) $args[1];
		$this->row_lookups[] = array( $table, $id );
		switch ( $table ) {
			case 'wp_ffc_recruitment_candidate':
				return $this->candidates[ $id ] ?? null;
			case 'wp_ffc_recruitment_notice':
				return $this->notices[ $id ] ?? null;
			case 'wp_ffc_recruitment_adjutancy':
				return $this->adjutancies[ $id ] ?? null;
		}
		$this->fail( 'Unexpected get_row() table: ' . $table );
	}

	private function answer_results( string $token ) {
		[ $sql, $args ] = $this->statement( $token );
		$table          = (string) $args[0];
		if ( 'wp_ffc_recruitment_classification' === $table
			&& 'SELECT * FROM %i WHERE candidate_id = %d ORDER BY notice_id ASC, adjutancy_id ASC' === $sql ) {
			$candidate_id = (int) $args[1];
			return array_values( array_filter( $this->classifications, static fn ( $c ) => (int) $c->candidate_id === $candidate_id ) );
		}
		if ( 'wp_ffc_recruitment_call' === $table && 0 === strpos( $sql, 'SELECT * FROM %i WHERE classification_id IN (' ) ) {
			$ids = array_map( 'intval', array_slice( $args, 1 ) );
			return array_values( array_filter( $this->calls, static fn ( $c ) => in_array( (int) $c->classification_id, $ids, true ) ) );
		}
		if ( 'wp_ffc_activity_log' === $table && 0 === strpos( $sql, 'SELECT * FROM %i WHERE 1=1 AND ' ) ) {
			return $this->activity;
		}
		$this->fail( 'Unexpected get_results(): ' . $sql . ' on ' . $table );
	}

	private function answer_var( string $token ) {
		[ $sql, $args ] = $this->statement( $token );
		if ( 'SELECT COUNT(*) FROM %i WHERE candidate_id = %d' === $sql && 'wp_ffc_recruitment_classification' === $args[0] ) {
			$candidate_id = (int) $args[1];
			return (string) count( array_filter( $this->classifications, static fn ( $c ) => (int) $c->candidate_id === $candidate_id ) );
		}
		$this->fail( 'Unexpected get_var(): ' . $sql );
	}

	private function answer_col( string $token ) {
		[ $sql, $args ] = $this->statement( $token );
		if ( 'SELECT adjutancy_id FROM %i WHERE notice_id = %d' === $sql && 'wp_ffc_recruitment_notice_adjutancy' === $args[0] ) {
			return array_map( 'strval', $this->junction[ (int) $args[1] ] ?? array() );
		}
		$this->fail( 'Unexpected get_col(): ' . $sql );
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
	 * touches no DateFormatter. It is linked to the current user, which is
	 * what makes the real policy answer the unmasked tier (the General email
	 * field editable).
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
				'user_id'         => self::CURRENT_USER,
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

		$html = $this->capture_output( array( RecruitmentCandidateEditPage::class, 'render' ) );

		$this->assertSame(
			array( array( 'wp_ffc_recruitment_candidate', 7 ) ),
			$this->row_lookups,
			'The candidate is looked up by the requested id.'
		);
		$this->assertStringContainsString( 'Candidate not found.', $html );
		$this->assertStringContainsString( 'Back to Candidates', $html );
	}

	public function test_render_outputs_every_section_for_empty_candidate(): void {
		$_GET['candidate_id'] = '5';
		$this->candidates[5]  = $this->candidate();

		$html = $this->capture_output( array( RecruitmentCandidateEditPage::class, 'render' ) );

		// Screen header + the five section cards (#1631).
		$this->assertStringContainsString( '<h1>Edit candidate — Jane Doe</h1>', $html );
		$this->assertSame( 5, substr_count( $html, '<div class="card"><h2 class="ffc-icon-' ) );
		$this->assertStringNotContainsString( 'postbox', $html );
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

	/**
	 * @return array<string, array{0: string, 1: bool}>
	 */
	public static function swap_by_status(): array {
		return array(
			'draft'       => array( 'draft', true ),
			'preliminary' => array( 'preliminary', true ),
			'definitive'  => array( 'definitive', false ),
			'closed'      => array( 'closed', false ),
		);
	}

	/**
	 * @dataProvider swap_by_status
	 */
	public function test_the_adjutancy_swap_follows_the_notice_status( string $status, bool $swap ): void {
		$_GET['candidate_id']    = '5';
		$this->candidates[5]     = $this->candidate();
		$this->classifications[] = (object) array(
			'id'           => 10,
			'candidate_id' => 5,
			'notice_id'    => 3,
			'adjutancy_id' => 7,
			'list_type'    => 'definitive',
			'rank'         => 1,
			'score'        => '9.5',
			'status'       => 'empty',
		);
		$this->junction[3]    = array( 7, 8 );
		$this->notices[3]     = (object) array(
			'id'     => 3,
			'code'   => 'ED-01',
			'status' => $status,
		);
		$this->adjutancies[7] = (object) array(
			'id'   => 7,
			'slug' => 'mat',
		);
		$this->adjutancies[8] = (object) array(
			'id'   => 8,
			'slug' => 'hist',
		);

		$html = $this->capture_output( array( RecruitmentCandidateEditPage::class, 'render' ) );

		$this->assertSame( $swap, str_contains( $html, 'ffc-adjutancy-swap-select' ), 'A row moves to another adjutancy only in draft or preliminary (#2245).' );
		$this->assertStringContainsString( 'mat', $html );
	}

	public function test_render_populates_classification_and_history_tables(): void {
		$_GET['candidate_id'] = '5';
		$this->candidates[5]  = $this->candidate();

		$this->classifications[] = (object) array(
			'id'           => 10,
			'candidate_id' => 5,
			'notice_id'    => 3,
			'adjutancy_id' => 7,
			'list_type'    => 'geral',
			'rank'         => 1,
			'score'        => '9.5',
			'status'       => 'called',
		);
		$this->calls[] = (object) array(
			'classification_id' => 10,
			'called_at'         => 1700000000,
			'date_to_assume'    => '2026-05-20',
			'time_to_assume'    => '09:00:00',
			'out_of_order'      => '1',
			'cancelled_at'      => null,
			'notes'             => 'a note',
		);
		// A call on another classification must not reach this candidate.
		$this->calls[] = (object) array(
			'classification_id' => 99,
			'called_at'         => 1700000000,
			'date_to_assume'    => '2031-01-01',
			'time_to_assume'    => '10:00:00',
			'out_of_order'      => '0',
			'cancelled_at'      => null,
			'notes'             => 'not this candidate',
		);
		// The notice has two adjutancies attached ⇒ the swap <select>.
		$this->junction[3]    = array( 7, 8 );
		$this->notices[3]     = (object) array(
			'id'     => 3,
			'code'   => 'ED-01',
			'status' => 'preliminary',
		);
		$this->adjutancies[7] = (object) array(
			'id'   => 7,
			'slug' => 'mat',
		);
		$this->adjutancies[8] = (object) array(
			'id'   => 8,
			'slug' => 'hist',
		);
		// One activity-log row about the classification, written by the system,
		// and one about a stranger's classification that the feed must drop.
		$this->activity[] = array(
			'id'         => 2,
			'action'     => 'recruitment_call_created',
			'context'    => '{"call_id":4,"classification_id":10}',
			'user_id'    => 0,
			'created_at' => '2026-05-10 12:00:00',
		);
		$this->activity[] = array(
			'id'         => 1,
			'action'     => 'recruitment_call_created',
			'context'    => '{"call_id":5,"classification_id":99}',
			'user_id'    => 0,
			'created_at' => '2026-05-09 12:00:00',
		);

		$html = $this->capture_output( array( RecruitmentCandidateEditPage::class, 'render' ) );

		// Classification summary row.
		$this->assertStringContainsString( 'ED-01', $html );
		$this->assertStringContainsString( 'ffc-recruitment-status-called', $html );
		// The cell goes through the helper since #1193: shape from the base class
		// and a translated label — before, the raw enum came out, colourless and
		// with a shape no other badge on that screen had. The colour comes from a
		// generated rule, so the `<span>` carries no style at all.
		$this->assertStringContainsString(
			'<span class="ffc-pill ffc-recruitment-status-badge ffc-recruitment-status-called">Called</span>',
			$html
		);
		// Adjutancy <select> branch (notice has ≥2 attached adjutancies).
		$this->assertStringContainsString( 'ffc-adjutancy-swap-select', $html );
		$this->assertStringContainsString( '>mat<', $html );
		$this->assertStringContainsString( '>hist<', $html );
		// Per-classification calls sub-table: only this candidate's call.
		$this->assertStringContainsString( 'Calls for classification #10', $html );
		$this->assertStringContainsString( '2026-05-20', $html );
		$this->assertStringNotContainsString( 'not this candidate', $html );
		// History table row: actor resolves to System (user_id 0) and the
		// event cell carries the service's own summary of the matching row.
		$this->assertStringContainsString( 'System', $html );
		$this->assertStringContainsString( 'Call #4 issued on classification #10.', $html );
		$this->assertStringNotContainsString( 'Call #5', $html );
		// Delete blocked branch (count_for_candidate > 0).
		$this->assertStringContainsString( 'Blocked:', $html );
	}

	public function test_general_section_offers_the_reveal_tier_a_reveal_that_enables_the_email(): void {
		// #1863: masked and disabled for staff, with a Reveal that names and
		// enables the field; never a clear address in the HTML. The row has
		// no linked user, so the policy answers the reveal tier for a user
		// who holds the PII cap.
		$cipher = Encryption::encrypt( 'jane@example.test' );
		$this->assertIsString( $cipher );

		$method = new \ReflectionMethod( RecruitmentCandidateEditPage::class, 'render_general_section' );
		$method->setAccessible( true );
		$html = $this->capture_output( static fn () => $method->invoke( null, (object) array( 'id' => 5, 'name' => 'Jane', 'phone' => null, 'notes' => null, 'email_encrypted' => $cipher ) ) );

		$this->assertStringNotContainsString( 'jane@example.test', $html );
		$this->assertStringNotContainsString( ' name="email"', $html, 'The field posts only once a reveal named it.' );
		$this->assertStringContainsString( 'value="j***@example.test" data-ffc-pii-field="email" data-ffc-pii-name="email" disabled', $html );
		$this->assertStringContainsString( 'class="ffc-btn ffc-btn-neutral ffc-icon-eye ffc-pii-reveal-btn" data-ffc-pii-candidate="5" data-ffc-pii-field="email"', $html );
	}
}
