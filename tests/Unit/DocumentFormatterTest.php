<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Core\DocumentFormatter;

/**
 * Tests for DocumentFormatter: document validation, formatting, masking,
 * auth code parsing, and identifier cleaning.
 *
 * @covers \FreeFormCertificate\Core\DocumentFormatter
 */
class DocumentFormatterTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// Mock WP's is_email used by mask_email
		Functions\when('is_email')->alias(function ($email) {
			return strpos($email, '@') !== false;
		});
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// ==================================================================
	// Constants
	// ==================================================================

	public function test_constants_are_defined(): void {
		$this->assertSame('C', DocumentFormatter::PREFIX_CERTIFICATE);
		$this->assertSame('R', DocumentFormatter::PREFIX_REREGISTRATION);
		$this->assertSame('A', DocumentFormatter::PREFIX_APPOINTMENT);
		$this->assertNotEmpty(DocumentFormatter::PHONE_REGEX);
	}

	// ==================================================================
	// validate_cpf
	// ==================================================================

	/**
	 * @dataProvider valid_cpf_provider
	 */
	public function test_validate_cpf_with_valid_values(string $cpf): void {
		$this->assertTrue(DocumentFormatter::validate_cpf($cpf));
	}

	public static function valid_cpf_provider(): array {
		return [
			'unformatted valid CPF'       => ['52998224725'],
			'formatted valid CPF'         => ['529.982.247-25'],
			'another valid CPF'           => ['11144477735'],
			'formatted another valid CPF' => ['111.444.777-35'],
		];
	}

	/**
	 * @dataProvider invalid_cpf_provider
	 */
	public function test_validate_cpf_with_invalid_values(string $cpf): void {
		$this->assertFalse(DocumentFormatter::validate_cpf($cpf));
	}

	public static function invalid_cpf_provider(): array {
		return [
			'all zeros'                  => ['00000000000'],
			'all ones'                   => ['11111111111'],
			'all twos'                   => ['22222222222'],
			'all nines'                  => ['99999999999'],
			'too short'                  => ['1234567890'],
			'too long'                   => ['123456789012'],
			'empty string'               => [''],
			'wrong check digit first'    => ['52998224715'],
			'wrong check digit second'   => ['52998224726'],
			'letters mixed in'           => ['529982247AB'],
		];
	}

	// ==================================================================
	// validate_rf
	// ==================================================================

	/**
	 * @dataProvider valid_rf_provider
	 */
	public function test_validate_rf_with_valid_values(string $rf): void {
		$this->assertTrue(DocumentFormatter::validate_rf($rf));
	}

	public static function valid_rf_provider(): array {
		return [
			'7-digit numeric'            => ['1234567'],
			'formatted with dots/dash'   => ['123.456-7'],
			'all zeros'                  => ['0000000'],
			'starts with zero'           => ['0123456'],
		];
	}

	/**
	 * @dataProvider invalid_rf_provider
	 */
	public function test_validate_rf_with_invalid_values(string $rf): void {
		$this->assertFalse(DocumentFormatter::validate_rf($rf));
	}

	public static function invalid_rf_provider(): array {
		return [
			'too short (6 digits)'   => ['123456'],
			'too long (8 digits)'    => ['12345678'],
			'empty string'           => [''],
			'letters only'           => ['ABCDEFG'],
			'mixed letters digits'   => ['12345AB'],
		];
	}

	// ==================================================================
	// rf_check_digit
	// ==================================================================

	/**
	 * Pins the whole residue -> digit table, one case per residue.
	 *
	 * The rule is `dv = (11 - r) mod 10`, and the second modulus is the part
	 * that cannot be inferred from a handful of examples: it sends `r = 1` to
	 * 0 rather than to the impossible 10, and it makes `r = 0` and `r = 10`
	 * BOTH produce 1. Eleven cases rather than a spot check, because a wrong
	 * formula agrees with the right one on most residues -- the production
	 * base and the independent list behind #1345 both exercise all eleven for
	 * the same reason.
	 *
	 * The bodies are synthetic. No RF from any install appears here.
	 *
	 * @dataProvider rf_residue_provider
	 */
	public function test_rf_check_digit_pins_the_eleven_residue_table(string $body, int $expected): void {
		$this->assertSame(
			$expected,
			DocumentFormatter::rf_check_digit($body . '0'),
			'The check digit for a body whose weighted sum has this residue.'
		);
	}

	public static function rf_residue_provider(): array {
		return [
			'r=0  -> 1 (collides with r=10)' => ['100002', 1],
			'r=1  -> 0 (never the 10 that 11-r would give)' => ['100008', 0],
			'r=2  -> 9'                      => ['100003', 9],
			'r=3  -> 8'                      => ['100009', 8],
			'r=4  -> 7'                      => ['100004', 7],
			'r=5  -> 6'                      => ['100013', 6],
			'r=6  -> 5'                      => ['100005', 5],
			'r=7  -> 4'                      => ['100000', 4],
			'r=8  -> 3'                      => ['100006', 3],
			'r=9  -> 2'                      => ['100001', 2],
			'r=10 -> 1 (collides with r=0)'  => ['100007', 1],
		];
	}

	/**
	 * The two residues that collapse onto the same digit are the rule's one
	 * blind spot, and it is asserted rather than described: a body error that
	 * moves the residue between 0 and 10 is undetectable by this check.
	 */
	public function test_the_check_digit_cannot_separate_residue_zero_from_residue_ten(): void {
		$this->assertSame(1, DocumentFormatter::rf_check_digit('1000020'));
		$this->assertSame(1, DocumentFormatter::rf_check_digit('1000070'));
	}

	public function test_rf_check_digit_ignores_formatting(): void {
		$this->assertSame(
			DocumentFormatter::rf_check_digit('1000021'),
			DocumentFormatter::rf_check_digit('100.002-1')
		);
	}

	/**
	 * @dataProvider not_an_rf_provider
	 */
	public function test_rf_check_digit_is_null_when_there_is_no_rf_to_read(string $value): void {
		$this->assertNull(DocumentFormatter::rf_check_digit($value));
	}

	public static function not_an_rf_provider(): array {
		return [
			'six digits'   => ['123456'],
			'eight digits' => ['12345678'],
			'empty string' => [''],
			'letters only' => ['ABCDEFG'],
		];
	}

	// ==================================================================
	// rf_check_digit_matches
	// ==================================================================

	public function test_rf_check_digit_matches_accepts_a_consistent_rf(): void {
		$this->assertTrue(DocumentFormatter::rf_check_digit_matches('1000021'));
	}

	public function test_rf_check_digit_matches_rejects_an_inconsistent_rf(): void {
		// Same body, every other seventh digit.
		foreach (['0', '2', '3', '4', '5', '6', '7', '8', '9'] as $wrong) {
			$this->assertFalse(
				DocumentFormatter::rf_check_digit_matches('100002' . $wrong),
				"100002{$wrong} should fail the check digit."
			);
		}
	}

	/**
	 * Structure first, so a caller asking whether an RF is consistent never
	 * has to ask separately whether it is an RF.
	 */
	public function test_rf_check_digit_matches_rejects_what_is_not_an_rf(): void {
		$this->assertFalse(DocumentFormatter::rf_check_digit_matches('123456'));
		$this->assertFalse(DocumentFormatter::rf_check_digit_matches(''));
	}

	// ==================================================================
	// validate_rf + the ffc_validate_rf_check_digit opt-in
	// ==================================================================

	/**
	 * The default is unchanged behaviour, and this is the assertion that says
	 * so: '1234567' has a weighted sum of 77, residue 0, so its check digit
	 * should be 1 and is 7. It has always been accepted and still is.
	 */
	public function test_validate_rf_ignores_the_check_digit_by_default(): void {
		$this->assertFalse(DocumentFormatter::rf_check_digit_matches('1234567'));
		$this->assertTrue(DocumentFormatter::validate_rf('1234567'));
	}

	public function test_validate_rf_enforces_the_check_digit_when_the_filter_opts_in(): void {
		Functions\when('apply_filters')->alias(
			static function ($hook, $value) {
				return 'ffc_validate_rf_check_digit' === $hook ? true : $value;
			}
		);

		$this->assertFalse(DocumentFormatter::validate_rf('1234567'), 'Check digit 7, expected 1.');
		$this->assertTrue(DocumentFormatter::validate_rf('1000021'), 'A consistent RF still passes.');
	}

	/**
	 * Opting in narrows what is accepted and never widens it -- a value that
	 * is not seven digits is refused before the filter is consulted.
	 */
	public function test_the_opt_in_cannot_rescue_a_malformed_rf(): void {
		Functions\when('apply_filters')->alias(static fn($hook, $value) => true);

		$this->assertFalse(DocumentFormatter::validate_rf('123456'));
		$this->assertFalse(DocumentFormatter::validate_rf('12345678'));
		$this->assertFalse(DocumentFormatter::validate_rf(''));
	}

	// ==================================================================
	// validate_phone
	// ==================================================================

	/**
	 * @dataProvider valid_phone_provider
	 */
	public function test_validate_phone_with_valid_values(string $phone): void {
		$this->assertTrue(DocumentFormatter::validate_phone($phone));
	}

	public static function valid_phone_provider(): array {
		return [
			'landline no formatting'        => ['1133334444'],
			'mobile no formatting'          => ['11933334444'],
			'landline with parens'          => ['(11)33334444'],
			'mobile with parens'            => ['(11)933334444'],
			'landline parens space dash'    => ['(11) 3333-4444'],
			'mobile parens space dash'      => ['(11) 93333-4444'],
			'landline with dash only'       => ['113333-4444'],
		];
	}

	/**
	 * @dataProvider invalid_phone_provider
	 */
	public function test_validate_phone_with_invalid_values(string $phone): void {
		$this->assertFalse(DocumentFormatter::validate_phone($phone));
	}

	public static function invalid_phone_provider(): array {
		return [
			'too short'              => ['12345'],
			'too long'               => ['111234567890123'],
			'empty string'           => [''],
			'letters'                => ['abcdefghij'],
			'missing area code'      => ['33334444'],
		];
	}

	// ==================================================================
	// format_cpf
	// ==================================================================

	public function test_format_cpf_with_11_digit_string(): void {
		$this->assertSame('529.982.247-25', DocumentFormatter::format_cpf('52998224725'));
	}

	public function test_format_cpf_with_already_formatted_input(): void {
		// The method strips non-digits first, so formatted input should still work
		$this->assertSame('529.982.247-25', DocumentFormatter::format_cpf('529.982.247-25'));
	}

	public function test_format_cpf_returns_raw_when_not_11_digits(): void {
		// Too short: returns digits only since it strips non-digits
		$this->assertSame('12345', DocumentFormatter::format_cpf('12345'));
	}

	public function test_format_cpf_returns_raw_when_empty(): void {
		$this->assertSame('', DocumentFormatter::format_cpf(''));
	}

	// ==================================================================
	// format_rf
	// ==================================================================

	public function test_format_rf_with_7_digit_string(): void {
		$this->assertSame('123.456-7', DocumentFormatter::format_rf('1234567'));
	}

	public function test_format_rf_with_already_formatted_input(): void {
		$this->assertSame('123.456-7', DocumentFormatter::format_rf('123.456-7'));
	}

	public function test_format_rf_returns_raw_when_not_7_digits(): void {
		$this->assertSame('12345', DocumentFormatter::format_rf('12345'));
	}

	public function test_format_rf_returns_raw_when_empty(): void {
		$this->assertSame('', DocumentFormatter::format_rf(''));
	}

	// ==================================================================
	// format_auth_code
	// ==================================================================

	public function test_format_auth_code_12_chars_no_prefix(): void {
		$this->assertSame('ABCD-1234-EFGH', DocumentFormatter::format_auth_code('ABCD1234EFGH'));
	}

	public function test_format_auth_code_12_chars_with_valid_prefix(): void {
		$this->assertSame('C-ABCD-1234-EFGH', DocumentFormatter::format_auth_code('ABCD1234EFGH', 'C'));
	}

	public function test_format_auth_code_with_prefix_r(): void {
		$this->assertSame('R-ABCD-1234-EFGH', DocumentFormatter::format_auth_code('ABCD1234EFGH', 'R'));
	}

	public function test_format_auth_code_with_prefix_a(): void {
		$this->assertSame('A-ABCD-1234-EFGH', DocumentFormatter::format_auth_code('ABCD1234EFGH', 'A'));
	}

	public function test_format_auth_code_with_invalid_prefix_is_ignored(): void {
		$this->assertSame('ABCD-1234-EFGH', DocumentFormatter::format_auth_code('ABCD1234EFGH', 'X'));
	}

	public function test_format_auth_code_lowercase_prefix_is_uppercased(): void {
		$this->assertSame('C-ABCD-1234-EFGH', DocumentFormatter::format_auth_code('ABCD1234EFGH', 'c'));
	}

	public function test_format_auth_code_non_12_chars_returned_as_is_uppercased(): void {
		$this->assertSame('SHORT', DocumentFormatter::format_auth_code('short'));
	}

	public function test_format_auth_code_already_formatted_input(): void {
		// Dashes are stripped, then re-formatted
		$this->assertSame('ABCD-1234-EFGH', DocumentFormatter::format_auth_code('ABCD-1234-EFGH'));
	}

	public function test_format_auth_code_lowercase_code_is_uppercased(): void {
		$this->assertSame('ABCD-1234-EFGH', DocumentFormatter::format_auth_code('abcd1234efgh'));
	}

	public function test_format_auth_code_empty_prefix_string(): void {
		$this->assertSame('ABCD-1234-EFGH', DocumentFormatter::format_auth_code('ABCD1234EFGH', ''));
	}

	// ==================================================================
	// format_document
	// ==================================================================

	public function test_format_document_auto_detects_cpf_by_11_digits(): void {
		$this->assertSame('529.982.247-25', DocumentFormatter::format_document('52998224725'));
	}

	public function test_format_document_auto_detects_rf_by_7_digits(): void {
		$this->assertSame('123.456-7', DocumentFormatter::format_document('1234567'));
	}

	public function test_format_document_auto_detects_auth_code_by_12_digits(): void {
		$this->assertSame('1234-5678-9012', DocumentFormatter::format_document('123456789012'));
	}

	public function test_format_document_explicit_cpf_type(): void {
		$this->assertSame('529.982.247-25', DocumentFormatter::format_document('52998224725', 'cpf'));
	}

	public function test_format_document_explicit_rf_type(): void {
		$this->assertSame('123.456-7', DocumentFormatter::format_document('1234567', 'rf'));
	}

	public function test_format_document_explicit_auth_code_type(): void {
		$this->assertSame('1234-5678-9012', DocumentFormatter::format_document('123456789012', 'auth_code'));
	}

	public function test_format_document_unknown_type_returns_original(): void {
		$this->assertSame('randomtext', DocumentFormatter::format_document('randomtext', 'unknown'));
	}

	public function test_format_document_auto_no_match_returns_original(): void {
		// 5 digits does not match cpf/rf/auth_code
		$this->assertSame('12345', DocumentFormatter::format_document('12345'));
	}

	// ==================================================================
	// mask_cpf
	// ==================================================================

	public function test_mask_cpf_with_11_digit_cpf(): void {
		$this->assertSame('529.***.***-25', DocumentFormatter::mask_cpf('52998224725'));
	}

	public function test_mask_cpf_with_formatted_cpf(): void {
		$this->assertSame('529.***.***-25', DocumentFormatter::mask_cpf('529.982.247-25'));
	}

	public function test_mask_cpf_with_7_digit_rf(): void {
		$this->assertSame('123.***-7', DocumentFormatter::mask_cpf('1234567'));
	}

	public function test_mask_cpf_with_formatted_rf(): void {
		$this->assertSame('123.***-7', DocumentFormatter::mask_cpf('123.456-7'));
	}

	public function test_mask_cpf_returns_empty_for_empty_input(): void {
		$this->assertSame('', DocumentFormatter::mask_cpf(''));
	}

	public function test_mask_cpf_returns_original_for_unknown_length(): void {
		$this->assertSame('12345', DocumentFormatter::mask_cpf('12345'));
	}

	// ==================================================================
	// mask_rf
	// ==================================================================

	public function test_mask_rf_with_4_digit_value(): void {
		$this->assertSame('123.***.4', DocumentFormatter::mask_rf('1234'));
	}

	public function test_mask_rf_with_7_digit_value(): void {
		$this->assertSame('123.***.7', DocumentFormatter::mask_rf('1234567'));
	}

	public function test_mask_rf_with_long_value(): void {
		// 12-digit RFs exist in some Brazilian states/agencies; mask still
		// shows first 3 + last 1 of the digit-only sequence.
		$this->assertSame('123.***.2', DocumentFormatter::mask_rf('123456789012'));
	}

	public function test_mask_rf_strips_punctuation_before_masking(): void {
		$this->assertSame('123.***.7', DocumentFormatter::mask_rf('123.456-7'));
	}

	public function test_mask_rf_returns_original_when_too_short(): void {
		// < 4 digits → cannot mask meaningfully; return unchanged for
		// observability (caller sees the raw input was malformed).
		$this->assertSame('123', DocumentFormatter::mask_rf('123'));
		$this->assertSame('1', DocumentFormatter::mask_rf('1'));
	}

	public function test_mask_rf_returns_empty_for_empty_input(): void {
		$this->assertSame('', DocumentFormatter::mask_rf(''));
	}

	// ==================================================================
	// mask_email
	// ==================================================================

	public function test_mask_email_valid_address(): void {
		$this->assertSame('j***@example.com', DocumentFormatter::mask_email('john@example.com'));
	}

	public function test_mask_email_single_char_local(): void {
		$this->assertSame('a***@test.org', DocumentFormatter::mask_email('a@test.org'));
	}

	public function test_mask_email_returns_original_for_empty(): void {
		$this->assertSame('', DocumentFormatter::mask_email(''));
	}

	public function test_mask_email_returns_original_for_invalid(): void {
		// is_email mock returns false for strings without @
		$this->assertSame('not-an-email', DocumentFormatter::mask_email('not-an-email'));
	}

	// ==================================================================
	// mask_field_value — shared per-key PII masker (#838)
	// ==================================================================

	public function test_mask_field_value_masks_cpf_family_keys(): void {
		$this->assertSame('529.***.***-25', DocumentFormatter::mask_field_value('cpf', '52998224725'));
		$this->assertSame('529.***.***-25', DocumentFormatter::mask_field_value('cpf_rf', '52998224725'));
		$this->assertSame('529.***.***-25', DocumentFormatter::mask_field_value('rg', '52998224725'));
	}

	public function test_mask_field_value_masks_rf_key(): void {
		$this->assertSame('123.***.7', DocumentFormatter::mask_field_value('rf', '1234567'));
	}

	public function test_mask_field_value_masks_email_key(): void {
		$this->assertSame('j***@example.com', DocumentFormatter::mask_field_value('email', 'john@example.com'));
	}

	public function test_mask_field_value_leaves_non_pii_keys_untouched(): void {
		// Legitimate certificate content must survive verbatim — masking a
		// course name / hour count would corrupt the certificate.
		$this->assertSame('Advanced Math', DocumentFormatter::mask_field_value('course', 'Advanced Math'));
		$this->assertSame('40', DocumentFormatter::mask_field_value('hours', '40'));
		$this->assertSame(40, DocumentFormatter::mask_field_value('hours', 40));
	}

	public function test_mask_field_value_passes_through_empty_and_non_scalar(): void {
		$this->assertSame('', DocumentFormatter::mask_field_value('cpf', ''));
		$this->assertNull(DocumentFormatter::mask_field_value('email', null));
		$this->assertSame(array('a', 'b'), DocumentFormatter::mask_field_value('cpf', array('a', 'b')));
	}

	// ==================================================================
	// parse_prefixed_code
	// ==================================================================

	/**
	 * @dataProvider prefixed_code_provider
	 */
	public function test_parse_prefixed_code(string $input, string $expected_prefix, string $expected_code): void {
		$result = DocumentFormatter::parse_prefixed_code($input);
		$this->assertSame($expected_prefix, $result['prefix']);
		$this->assertSame($expected_code, $result['code']);
	}

	public static function prefixed_code_provider(): array {
		return [
			'C prefix with dashes'       => ['C-ABCD-1234-EFGH', 'C', 'ABCD1234EFGH'],
			'R prefix with dashes'       => ['R-ABCD-1234-EFGH', 'R', 'ABCD1234EFGH'],
			'A prefix with dashes'       => ['A-ABCD-1234-EFGH', 'A', 'ABCD1234EFGH'],
			'C prefix no dashes'         => ['CABCD1234EFGH', 'C', 'ABCD1234EFGH'],
			'R prefix no dashes'         => ['RABCD1234EFGH', 'R', 'ABCD1234EFGH'],
			'A prefix no dashes'         => ['AABCD1234EFGH', 'A', 'ABCD1234EFGH'],
			'no prefix with dashes'      => ['ABCD-1234-EFGH', '', 'ABCD1234EFGH'],
			'no prefix no dashes'        => ['ABCD1234EFGH', '', 'ABCD1234EFGH'],
			'lowercase input with C'     => ['c-abcd-1234-efgh', 'C', 'ABCD1234EFGH'],
			'whitespace trimmed'         => ['  ABCD1234EFGH  ', '', 'ABCD1234EFGH'],
			'short fallback no prefix'   => ['SHORT', '', 'SHORT'],
			'empty string'               => ['', '', ''],
		];
	}

	public function test_parse_prefixed_code_returns_array_with_keys(): void {
		$result = DocumentFormatter::parse_prefixed_code('ABCD1234EFGH');
		$this->assertArrayHasKey('prefix', $result);
		$this->assertArrayHasKey('code', $result);
	}

	// ==================================================================
	// clean_auth_code
	// ==================================================================

	public function test_clean_auth_code_strips_prefix_and_dashes(): void {
		$this->assertSame('ABCD1234EFGH', DocumentFormatter::clean_auth_code('C-ABCD-1234-EFGH'));
	}

	public function test_clean_auth_code_strips_dashes_no_prefix(): void {
		$this->assertSame('ABCD1234EFGH', DocumentFormatter::clean_auth_code('ABCD-1234-EFGH'));
	}

	public function test_clean_auth_code_raw_12_char_unchanged(): void {
		$this->assertSame('ABCD1234EFGH', DocumentFormatter::clean_auth_code('ABCD1234EFGH'));
	}

	public function test_clean_auth_code_lowercase_is_uppercased(): void {
		$this->assertSame('ABCD1234EFGH', DocumentFormatter::clean_auth_code('abcd1234efgh'));
	}

	public function test_clean_auth_code_with_prefix_r(): void {
		$this->assertSame('ABCD1234EFGH', DocumentFormatter::clean_auth_code('R-ABCD-1234-EFGH'));
	}

	public function test_clean_auth_code_short_input(): void {
		$this->assertSame('SHORT', DocumentFormatter::clean_auth_code('short'));
	}

	// ==================================================================
	// clean_identifier
	// ==================================================================

	public function test_clean_identifier_removes_special_chars(): void {
		$this->assertSame('52998224725', DocumentFormatter::clean_identifier('529.982.247-25'));
	}

	public function test_clean_identifier_uppercases_letters(): void {
		$this->assertSame('ABCDEF123', DocumentFormatter::clean_identifier('abcdef123'));
	}

	public function test_clean_identifier_strips_spaces_and_symbols(): void {
		$this->assertSame('ABC123', DocumentFormatter::clean_identifier(' a!b@c#1$2%3 '));
	}

	public function test_clean_identifier_empty_string_returns_empty(): void {
		$this->assertSame('', DocumentFormatter::clean_identifier(''));
	}

	public function test_clean_identifier_already_clean(): void {
		$this->assertSame('ABC123', DocumentFormatter::clean_identifier('ABC123'));
	}
}
