<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FreeFormCertificate\Submissions\BulkIssuePlanner;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Tests\Support\Identifiers;

/**
 * Tests for BulkIssuePlanner: the pure plan behind "Issue in bulk" (#1945).
 *
 * The planner writes nothing, so these tests only need the settings reads the
 * RF check-digit rule makes. Every identifier comes from `Identifiers`.
 *
 * @covers \FreeFormCertificate\Submissions\BulkIssuePlanner
 */
class BulkIssuePlannerTest extends TestCase {

	/**
	 * A CPF in the `xxx.xxx.xxx-xx` mask a spreadsheet carries.
	 *
	 * @param string $cpf Eleven digits.
	 */
	private static function masked( string $cpf ): string {
		return substr( $cpf, 0, 3 ) . '.' . substr( $cpf, 3, 3 ) . '.' . substr( $cpf, 6, 3 ) . '-' . substr( $cpf, 9 );
	}

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		// The RF check-digit rule reads its setting (default off) and filters it.
		Functions\when( 'get_option' )->alias(
			static fn ( string $key, $default = false ) => $default
		);
		Functions\when( 'apply_filters' )->alias(
			static fn ( string $hook, $value ) => $value
		);
		Functions\when( 'wp_timezone' )->justReturn( new \DateTimeZone( 'UTC' ) );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/** Column mapping that binds each identifier to a header name. */
	private function mapping(): array {
		return array(
			'name'   => 'Nome',
			'cpf_rf' => 'CPF',
			'email'  => 'E-mail',
			'course' => 'Curso',
		);
	}

	private const HEADER = array( 'Nome', 'CPF', 'E-mail', 'Curso' );

	public function test_template_columns_skip_decorative_rows_and_duplicates(): void {
		$fields = array(
			array( 'name' => 'name', 'label' => 'Name' ),
			array( 'label' => 'A heading, no name' ),
			array( 'name' => 'cpf_rf' ),
			array( 'name' => 'name' ),
			array( 'name' => '   ' ),
			'not an array',
		);

		$this->assertSame( array( 'name', 'cpf_rf' ), BulkIssuePlanner::template_columns( $fields ) );
	}

	public function test_parse_detects_a_semicolon_delimiter_and_skips_the_bom(): void {
		$content = "\xEF\xBB\xBFNome;CPF;E-mail\nAna;" . Identifiers::CPF_A . ";ana@example.test\n";

		$parsed = BulkIssuePlanner::parse( $content );

		$this->assertSame( ';', $parsed['delimiter'] );
		$this->assertSame( array( 'Nome', 'CPF', 'E-mail' ), $parsed['header'] );
		$this->assertSame( array( array( 'Ana', Identifiers::CPF_A, 'ana@example.test' ) ), $parsed['rows'] );
	}

	public function test_mapping_problems_report_unmapped_and_unknown_columns(): void {
		$this->assertSame( array(), BulkIssuePlanner::mapping_problems( $this->mapping(), self::HEADER ) );

		$unmapped = $this->mapping();
		unset( $unmapped['email'] );
		$this->assertSame( array( 'unmapped:email' ), BulkIssuePlanner::mapping_problems( $unmapped, self::HEADER ) );

		$unknown          = $this->mapping();
		$unknown['cpf_rf'] = 'Documento';
		$this->assertSame( array( 'unknown:cpf_rf' ), BulkIssuePlanner::mapping_problems( $unknown, self::HEADER ) );
	}

	public function test_a_file_in_an_unsupported_delimiter_maps_every_required_column_to_nothing(): void {
		$parsed = BulkIssuePlanner::parse( "Nome|CPF|E-mail\nAna|" . Identifiers::CPF_A . "|ana@example.test\n" );

		$this->assertSame( array( 'Nome|CPF|E-mail' ), $parsed['header'], 'A pipe is not a delimiter the parser reads.' );
		$this->assertSame(
			array( 'unknown:name', 'unknown:cpf_rf', 'unknown:email' ),
			BulkIssuePlanner::mapping_problems( $this->mapping(), $parsed['header'] )
		);
	}

	public function test_required_fields_leave_out_the_identifiers_and_optional_fields(): void {
		$fields = array(
			array( 'name' => 'name', 'required' => true ),
			array( 'name' => 'course', 'required' => true ),
			array( 'name' => 'notes' ),
			array( 'type' => 'heading' ),
		);

		$this->assertSame( array( 'course' ), BulkIssuePlanner::required_fields( $fields, false ) );
	}

	public function test_a_score_from_the_file_leaves_out_the_scored_questions(): void {
		$question = array(
			'type'        => 'text',
			'name'        => 'q1',
			'required'    => true,
			'quiz_scored' => '1',
			'quiz_text'   => 'answered',
			'quiz_points' => '2',
		);

		$this->assertSame( array( 'q1' ), BulkIssuePlanner::required_fields( array( $question ), false ) );
		$this->assertSame( array(), BulkIssuePlanner::required_fields( array( $question ), true ) );
	}

	public function test_a_row_that_leaves_a_required_field_empty_is_skipped_in_the_preview(): void {
		$rows = array(
			array( 'Ana', self::masked( Identifiers::CPF_A ), 'ana@example.test', '' ),
			array( 'Bia', self::masked( Identifiers::CPF_B ), 'bia@example.test', 'Python' ),
		);

		$plan = BulkIssuePlanner::plan( $rows, $this->mapping(), self::HEADER, array( 'mode' => BulkIssuePlanner::DATE_NOW, 'date' => '', 'column' => '' ), false, array( 'mode' => BulkIssuePlanner::SCORE_NONE, 'column' => '', 'max' => 0, 'pass' => 0 ), array( 'course' ) );

		$this->assertSame( array( array( 'line' => 2, 'reason' => BulkIssuePlanner::SKIP_MISSING_REQUIRED ) ), $plan['skipped'] );
		$this->assertCount( 1, $plan['issue'] );
		$this->assertSame( BulkIssuePlanner::SKIP_MISSING_REQUIRED, \FreeFormCertificate\Submissions\BulkIssueRunner::SKIP_MISSING_REQUIRED, 'the preview and the issue report the same reason' );
	}

	public function test_a_valid_row_is_issued_with_its_digits_and_carried_fields(): void {
		$rows = array(
			array( 'Ana', self::masked( Identifiers::CPF_A ), 'ana@example.test', 'Python' ),
		);

		$plan = BulkIssuePlanner::plan( $rows, $this->mapping(), self::HEADER );

		$this->assertSame( array(), $plan['skipped'] );
		$this->assertCount( 1, $plan['issue'] );
		$this->assertSame( 2, $plan['issue'][0]['line'] );
		$this->assertSame( Identifiers::CPF_A, $plan['issue'][0]['cpf_rf'] );
		$this->assertSame( array( 'course' => 'Python' ), $plan['issue'][0]['fields'] );
	}

	public function test_the_default_date_leaves_every_certificate_to_its_moment_of_issue(): void {
		$rows = array(
			array( 'Ana', Identifiers::CPF_A, 'ana@example.test', '' ),
		);

		$plan = BulkIssuePlanner::plan( $rows, $this->mapping(), self::HEADER );

		$this->assertNull( $plan['issue'][0]['issued_at'] );
	}

	public function test_an_event_date_is_the_local_midnight_of_the_chosen_day(): void {
		$rows = array(
			array( 'Ana', Identifiers::CPF_A, 'ana@example.test', '' ),
		);
		$dates = array(
			'mode'   => BulkIssuePlanner::DATE_EVENT,
			'date'   => '2026-05-20',
			'column' => '',
		);

		$plan = BulkIssuePlanner::plan( $rows, $this->mapping(), self::HEADER, $dates );

		$this->assertSame( gmmktime( 0, 0, 0, 5, 20, 2026 ), $plan['issue'][0]['issued_at'] );
	}

	public function test_a_date_column_is_read_in_iso_or_day_first_format(): void {
		$header = array( 'Nome', 'CPF', 'E-mail', 'Curso', 'Data' );
		$mapping = $this->mapping();
		$rows    = array(
			array( 'Ana', Identifiers::CPF_A, 'ana@example.test', '', '20/05/2026' ),
			array( 'Bruno', Identifiers::CPF_B, 'bruno@example.test', '', '2026-05-21' ),
		);
		$dates   = array(
			'mode'   => BulkIssuePlanner::DATE_COLUMN,
			'date'   => '',
			'column' => 'Data',
		);

		$plan = BulkIssuePlanner::plan( $rows, $mapping, $header, $dates );

		$this->assertSame( gmmktime( 0, 0, 0, 5, 20, 2026 ), $plan['issue'][0]['issued_at'] );
		$this->assertSame( gmmktime( 0, 0, 0, 5, 21, 2026 ), $plan['issue'][1]['issued_at'] );
		$this->assertSame( array(), $plan['skipped'] );
	}

	/**
	 * @return iterable<string, array{0: string}>
	 */
	public static function unreadable_dates(): iterable {
		yield 'day that does not exist' => array( '31/02/2026' );
		yield 'month first'             => array( '05/20/2026' );
		yield 'free text'               => array( 'maio' );
		yield 'blank cell'              => array( '' );
	}

	/**
	 * @dataProvider unreadable_dates
	 */
	public function test_a_row_whose_date_cannot_be_read_is_skipped_with_its_reason( string $cell ): void {
		$header = array( 'Nome', 'CPF', 'E-mail', 'Curso', 'Data' );
		$rows   = array(
			array( 'Ana', Identifiers::CPF_A, 'ana@example.test', '', $cell ),
		);
		$dates  = array(
			'mode'   => BulkIssuePlanner::DATE_COLUMN,
			'date'   => '',
			'column' => 'Data',
		);

		$plan = BulkIssuePlanner::plan( $rows, $this->mapping(), $header, $dates );

		$this->assertSame( array(), $plan['issue'] );
		$this->assertSame( BulkIssuePlanner::SKIP_INVALID_DATE, $plan['skipped'][0]['reason'] );
	}

	public function test_the_date_choice_is_checked_before_the_plan_runs(): void {
		$header = array( 'Nome', 'CPF', 'E-mail', 'Curso' );

		$this->assertSame(
			array( 'event_date' ),
			BulkIssuePlanner::date_problems( array( 'mode' => BulkIssuePlanner::DATE_EVENT, 'date' => '2026-02-30', 'column' => '' ), $header )
		);
		$this->assertSame(
			array( 'unknown_date_column' ),
			BulkIssuePlanner::date_problems( array( 'mode' => BulkIssuePlanner::DATE_COLUMN, 'date' => '', 'column' => 'Data' ), $header )
		);
		$this->assertSame(
			array(),
			BulkIssuePlanner::date_problems( array( 'mode' => BulkIssuePlanner::DATE_NOW, 'date' => '', 'column' => '' ), $header )
		);
	}

	public function test_every_issued_line_carries_the_send_link_choice(): void {
		$rows = array(
			array( 'Ana', Identifiers::CPF_A, 'ana@example.test', '' ),
		);

		$this->assertFalse( BulkIssuePlanner::plan( $rows, $this->mapping(), self::HEADER )['issue'][0]['send_link'] );
		$this->assertTrue( BulkIssuePlanner::plan( $rows, $this->mapping(), self::HEADER, array( 'mode' => BulkIssuePlanner::DATE_NOW, 'date' => '', 'column' => '' ), true )['issue'][0]['send_link'] );
	}

	public function test_a_blank_email_is_skipped_and_reported_not_issued(): void {
		$rows = array(
			array( 'Bruno', Identifiers::CPF_B, '', 'Java' ),
		);

		$plan = BulkIssuePlanner::plan( $rows, $this->mapping(), self::HEADER );

		$this->assertSame( array(), $plan['issue'] );
		$this->assertSame(
			array( array( 'line' => 2, 'reason' => BulkIssuePlanner::SKIP_MISSING_EMAIL ) ),
			$plan['skipped']
		);
	}

	/**
	 * @return iterable<string, array{0: list<string>, 1: string}>
	 */
	public static function skipped_rows(): iterable {
		yield 'empty row'          => array( array( '', '', '', '' ), BulkIssuePlanner::SKIP_EMPTY_ROW );
		yield 'missing name'       => array( array( '  ', Identifiers::CPF_A, 'a@example.test', '' ), BulkIssuePlanner::SKIP_MISSING_NAME );
		yield 'missing cpf'        => array( array( 'Ana', '', 'a@example.test', '' ), BulkIssuePlanner::SKIP_MISSING_CPF_RF );
		yield 'cpf check digits'   => array( array( 'Ana', '11111111111', 'a@example.test', '' ), BulkIssuePlanner::SKIP_INVALID_CPF_RF );
		yield 'cpf too short'      => array( array( 'Ana', '1234', 'a@example.test', '' ), BulkIssuePlanner::SKIP_INVALID_CPF_RF );
		yield 'malformed e-mail'   => array( array( 'Ana', Identifiers::CPF_A, 'ana@', '' ), BulkIssuePlanner::SKIP_INVALID_EMAIL );
		yield 'blank e-mail'       => array( array( 'Ana', Identifiers::CPF_A, '', '' ), BulkIssuePlanner::SKIP_MISSING_EMAIL );
	}

	/**
	 * @param list<string> $cells  One data row.
	 * @param string       $reason Expected skip reason.
	 * @dataProvider skipped_rows
	 */
	public function test_a_bad_row_is_skipped_with_its_reason( array $cells, string $reason ): void {
		$plan = BulkIssuePlanner::plan( array( $cells ), $this->mapping(), self::HEADER );

		$this->assertSame( array(), $plan['issue'] );
		$this->assertSame( array( array( 'line' => 2, 'reason' => $reason ) ), $plan['skipped'] );
	}

	public function test_the_second_occurrence_of_an_identifier_is_a_duplicate_in_the_file(): void {
		$rows = array(
			array( 'Ana', Identifiers::CPF_A, 'a@example.test', '' ),
			array( 'Ana outra vez', self::masked( Identifiers::CPF_A ), 'b@example.test', '' ),
		);

		$plan = BulkIssuePlanner::plan( $rows, $this->mapping(), self::HEADER );

		$this->assertCount( 1, $plan['issue'] );
		$this->assertSame(
			array( array( 'line' => 3, 'reason' => BulkIssuePlanner::SKIP_DUPLICATE_FILE ) ),
			$plan['skipped']
		);
	}

	public function test_a_seven_digit_rf_is_accepted_when_the_check_digit_is_not_enforced(): void {
		$rows = array(
			array( 'Carla', Identifiers::RF_A, 'c@example.test', '' ),
		);

		$plan = BulkIssuePlanner::plan( $rows, $this->mapping(), self::HEADER );

		$this->assertCount( 1, $plan['issue'] );
		$this->assertSame( Identifiers::RF_A, $plan['issue'][0]['cpf_rf'] );
	}

	public function test_every_row_is_decided_and_the_lines_follow_the_file(): void {
		$rows = array(
			array( 'Ana', Identifiers::CPF_A, 'a@example.test', '' ),
			array( '', '', '', '' ),
			array( 'Bruno', Identifiers::CPF_B, '', '' ),
		);

		$plan = BulkIssuePlanner::plan( $rows, $this->mapping(), self::HEADER );

		$this->assertSame( array( 2 ), array_column( $plan['issue'], 'line' ) );
		$this->assertSame(
			array(
				array( 'line' => 3, 'reason' => BulkIssuePlanner::SKIP_EMPTY_ROW ),
				array( 'line' => 4, 'reason' => BulkIssuePlanner::SKIP_MISSING_EMAIL ),
			),
			$plan['skipped']
		);
	}

	/** A quiz file: the score column sits after the mapped columns. */
	private const SCORE_HEADER = array( 'Nome', 'CPF', 'E-mail', 'Curso', 'Pontos' );

	/**
	 * @return array{mode: string, column: string, max: int, pass: int}
	 */
	private function score_column(): array {
		return array(
			'mode'   => BulkIssuePlanner::SCORE_COLUMN,
			'column' => 'Pontos',
			'max'    => 10,
			'pass'   => 60,
		);
	}

	public function test_a_form_that_is_not_a_quiz_issues_lines_with_no_score(): void {
		$rows = array( array( 'Ana', Identifiers::CPF_A, 'a@example.test', '', '' ) );

		$plan = BulkIssuePlanner::plan( $rows, $this->mapping(), self::SCORE_HEADER );

		$this->assertNull( $plan['issue'][0]['score'] );
		$this->assertSame( 0, $plan['zero_score'] );
	}

	public function test_the_score_column_gives_each_line_its_points_and_percentage(): void {
		$rows = array( array( 'Ana', Identifiers::CPF_A, 'a@example.test', '', '8' ) );

		$plan = BulkIssuePlanner::plan( $rows, $this->mapping(), self::SCORE_HEADER, array(
			'mode'   => BulkIssuePlanner::DATE_NOW,
			'date'   => '',
			'column' => '',
		), false, $this->score_column() );

		$this->assertSame(
			array( 'score' => 8, 'max' => 10, 'percent' => 80 ),
			$plan['issue'][0]['score']
		);
	}

	/**
	 * @return iterable<string, array{0: string, 1: string}>
	 */
	public static function unscored_quiz_rows(): iterable {
		yield 'empty score'        => array( '', BulkIssuePlanner::SKIP_MISSING_SCORE );
		yield 'not whole points'   => array( '7,5', BulkIssuePlanner::SKIP_INVALID_SCORE );
		yield 'negative points'    => array( '-1', BulkIssuePlanner::SKIP_INVALID_SCORE );
		yield 'above the maximum'  => array( '11', BulkIssuePlanner::SKIP_SCORE_ABOVE_MAX );
		yield 'below the pass mark' => array( '5', BulkIssuePlanner::SKIP_BELOW_PASS_MARK );
	}

	/**
	 * @param string $points Score cell.
	 * @param string $reason Expected skip reason.
	 * @dataProvider unscored_quiz_rows
	 */
	public function test_a_line_whose_score_is_unusable_is_skipped_with_its_reason( string $points, string $reason ): void {
		$rows = array( array( 'Ana', Identifiers::CPF_A, 'a@example.test', '', $points ) );

		$plan = BulkIssuePlanner::plan( $rows, $this->mapping(), self::SCORE_HEADER, array(
			'mode'   => BulkIssuePlanner::DATE_NOW,
			'date'   => '',
			'column' => '',
		), false, $this->score_column() );

		$this->assertSame( array(), $plan['issue'] );
		$this->assertSame( array( array( 'line' => 2, 'reason' => $reason ) ), $plan['skipped'] );
	}

	public function test_the_zero_score_issues_every_line_with_zero_and_counts_them(): void {
		$rows = array(
			array( 'Ana', Identifiers::CPF_A, 'a@example.test', '', '' ),
			array( 'Bruno', Identifiers::CPF_B, 'b@example.test', '', '' ),
		);

		$plan = BulkIssuePlanner::plan( $rows, $this->mapping(), self::SCORE_HEADER, array(
			'mode'   => BulkIssuePlanner::DATE_NOW,
			'date'   => '',
			'column' => '',
		), false, array(
			'mode'   => BulkIssuePlanner::SCORE_ZERO,
			'column' => '',
			'max'    => 10,
			'pass'   => 60,
		) );

		$this->assertCount( 2, $plan['issue'] );
		$this->assertSame( array( 'score' => 0, 'max' => 10, 'percent' => 0 ), $plan['issue'][0]['score'] );
		$this->assertSame( 2, $plan['zero_score'] );
	}
}
