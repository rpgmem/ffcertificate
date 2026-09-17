<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Reregistration\ReregistrationImportMessages;

/**
 * Tests for the import's error-code presenter (#1214 sprint 5).
 *
 * The property worth pinning is not the wording — that is translators' — but
 * that **every code the staging service can emit is known here**, and that the
 * two parameterised shapes keep their parameter. A code falling through to the
 * fallback is a real gap: the operator then reads a machine token instead of an
 * instruction, which is the difference between fixing the spreadsheet and
 * filing a support request.
 *
 * @covers \FreeFormCertificate\Reregistration\ReregistrationImportMessages
 */
class ReregistrationImportMessagesTest extends TestCase {

	/**
	 * Every `rereg_import_*` literal the staging service emits.
	 *
	 * Read OUT OF the service rather than listed here, so a code added there
	 * without a sentence fails this test instead of reaching an operator. That
	 * is the same reason `.github/scripts/ffc-create-statements.php` is shared:
	 * two files that must agree about a set should not each carry their own
	 * copy of it.
	 *
	 * @return list<string>
	 */
	private function service_codes(): array {
		$source = (string) file_get_contents(
			dirname( __DIR__, 2 ) . '/includes/reregistration/class-ffc-reregistration-import-staging-service.php'
		);

		$found = array();
		if ( preg_match_all( "/'(rereg_import_[a-z_]+)(?::%[sd])?'/", $source, $m ) ) {
			$found = array_values( array_unique( $m[1] ) );
		}

		return $found;
	}

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// pcov attribution preload (CLAUDE.md pcov gotcha).
		class_exists( '\FreeFormCertificate\Reregistration\ReregistrationImportMessages' );

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_the_scan_of_the_service_finds_codes_at_all(): void {
		// Self-check: an empty scan would make the test below vacuously green,
		// which is the #1071/#1094 rule every guard in this repository carries.
		$this->assertGreaterThan( 10, count( $this->service_codes() ) );
	}

	/**
	 * The one that matters: no code reaches the fallback.
	 */
	public function test_every_code_the_service_emits_has_a_sentence(): void {
		$unknown = array();

		foreach ( $this->service_codes() as $code ) {
			$job = ReregistrationImportMessages::job_error( $code );
			$row = ReregistrationImportMessages::row_error( 2, $code );

			// The fallback is the only output that contains the raw code.
			if ( false !== strpos( $job, $code ) && false !== strpos( $row, $code ) ) {
				$unknown[] = $code;
			}
		}

		$this->assertSame(
			array(),
			$unknown,
			'These codes render as a raw token on screen; give each one a sentence in ReregistrationImportMessages.'
		);
	}

	public function test_a_job_error_with_no_line_reads_as_a_sentence(): void {
		$this->assertSame(
			'The file has no header row.',
			ReregistrationImportMessages::job_error( 'rereg_import_csv_empty' )
		);
	}

	public function test_the_missing_column_list_survives_into_the_message(): void {
		$message = ReregistrationImportMessages::job_error( 'rereg_import_required_column_absent:cpf, nome_completo' );

		$this->assertStringContainsString( 'cpf, nome_completo', $message );
	}

	public function test_a_row_error_names_its_line(): void {
		$message = ReregistrationImportMessages::row_error( 7, 'rereg_import_no_identifier' );

		$this->assertStringContainsString( '7', $message );
	}

	/**
	 * A field-validation code is not one of ours — it comes from
	 * `CustomFieldReader::validate_field_value()` as `<code>:<field_key>` — and
	 * the field key is the actionable half a generic fallback would lose.
	 */
	public function test_a_field_error_names_the_column(): void {
		$message = ReregistrationImportMessages::row_error( 4, 'field_invalid_number:idade' );

		$this->assertStringContainsString( 'idade', $message );
		$this->assertStringNotContainsString( 'field_invalid_number', $message );
	}

	public function test_the_duplicate_identity_code_keeps_the_line_it_points_at(): void {
		$message = ReregistrationImportMessages::row_error( 9, 'rereg_import_duplicate_identity:4' );

		$this->assertStringContainsString( '9', $message, 'The failing line.' );
		$this->assertStringContainsString( '4', $message, 'The line it collides with — without it the operator has half the pair.' );
	}

	/**
	 * `explode( ':', $code, 2 )` from the LEFT, so a detail that itself
	 * contains a colon arrives whole.
	 */
	public function test_a_detail_containing_a_colon_is_not_truncated(): void {
		$message = ReregistrationImportMessages::row_error( 1, 'field_bad:a:b' );

		$this->assertStringContainsString( 'a:b', $message );
	}

	public function test_an_unknown_code_is_shown_rather_than_swallowed(): void {
		$message = ReregistrationImportMessages::job_error( 'something_nobody_wrote_a_sentence_for' );

		$this->assertStringContainsString(
			'something_nobody_wrote_a_sentence_for',
			$message,
			'An operator who can quote the code gets help faster than one who read "an error occurred".'
		);
	}
}
