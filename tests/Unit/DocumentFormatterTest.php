<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Core\DocumentFormatter;
use FreeFormCertificate\Tests\Support\CallLog;

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
		// Several methods this class reaches are deprecated (#2089) and emit
		// the notice; stub it so each test chooses the branch rather than
		// inheriting it from an earlier class.
		\Brain\Monkey\Functions\when( '_deprecated_function' )->justReturn( null );

		// Mock WP's is_email used by mask_email
		Functions\when('is_email')->alias(function ($email) {
			return strpos($email, '@') !== false;
		});

		// `validate_rf()` reads `ffc_settings` for the check-digit switch, and
		// `SettingsReader::all()` calls `get_option()` with no defensive
		// guard. Stubbed HERE, explicitly, rather than left to whichever
		// earlier test happened to teach Patchwork the function: that is what
		// makes the branch CHOSEN instead of inherited, and an empty array is
		// the honest default -- a fresh install with the toggle off.
		Functions\when('get_option')->justReturn(array());
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
	 * The setting is the FILTER'S DEFAULT, not a second switch.
	 *
	 * The same shape as `ffc_ip_resolver_mode`, which its own documentation
	 * describes as "normally set from the IP Diagnostics tab". Two
	 * independent switches would let an administrator turn this on from the
	 * screen while a filter silently kept it off, with nothing on the page
	 * saying so -- so what is asserted here is that the screen alone is
	 * enough, with no filter in play.
	 */
	public function test_the_setting_alone_enforces_the_check_digit(): void {
		Functions\when('get_option')->justReturn(
			array(DocumentFormatter::SETTING_CHECK_DIGIT => true)
		);

		$this->assertFalse(DocumentFormatter::validate_rf('1234567'), 'Check digit 7, expected 1.');
		$this->assertTrue(DocumentFormatter::validate_rf('1000021'), 'A consistent RF still passes.');
	}

	/**
	 * …and a filter still overrides it, in the direction that matters most:
	 * an administrator who turned it on can be overruled by code, which is
	 * the rollback that needs no database write.
	 */
	public function test_a_filter_can_overrule_the_setting(): void {
		Functions\when('get_option')->justReturn(
			array(DocumentFormatter::SETTING_CHECK_DIGIT => true)
		);
		Functions\when('apply_filters')->alias(
			static function ($hook, $value) {
				return 'ffc_validate_rf_check_digit' === $hook ? false : $value;
			}
		);

		$this->assertTrue(DocumentFormatter::validate_rf('1234567'), 'The filter is consulted last.');
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

	public function test_mask_cpf_masks_an_unknown_length_as_generic(): void {
		$this->assertSame('1***', DocumentFormatter::mask_cpf('12345'));
	}

	// ==================================================================
	// mask_rf
	// ==================================================================

	public function test_mask_rf_masks_a_value_its_shape_would_show_as_generic(): void {
		// `123.***-4` would print every digit of a 4-digit value (#1920).
		$this->assertSame('1***', DocumentFormatter::mask_rf('1234'));
	}

	public function test_mask_rf_with_7_digit_value(): void {
		$this->assertSame('123.***-7', DocumentFormatter::mask_rf('1234567'));
	}

	public function test_mask_rf_with_long_value(): void {
		// 12-digit RFs exist in some Brazilian states/agencies; mask still
		// shows first 3 + last 1 of the digit-only sequence.
		$this->assertSame('123.***-2', DocumentFormatter::mask_rf('123456789012'));
	}

	public function test_mask_rf_strips_punctuation_before_masking(): void {
		$this->assertSame('123.***-7', DocumentFormatter::mask_rf('123.456-7'));
	}

	public function test_mask_rf_never_returns_a_short_value(): void {
		$this->assertSame('1***', DocumentFormatter::mask_rf('123'));
		$this->assertSame('1***', DocumentFormatter::mask_rf('1'));
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

	public function test_mask_email_masks_a_value_that_is_not_an_email_as_generic(): void {
		// is_email mock returns false for strings without @.
		$this->assertSame('n***', DocumentFormatter::mask_email('not-an-email'));
	}

	// ==================================================================
	// mask_phone / mask_name / mask_any (#1863)
	// ==================================================================

	public function test_mask_phone_keeps_the_last_two_digits_and_the_punctuation(): void {
		$this->assertSame('(**) *****-**21', DocumentFormatter::mask_phone('(11) 98765-4321'));
		$this->assertSame('*********88', DocumentFormatter::mask_phone('11999998888'));
	}

	public function test_mask_phone_masks_a_short_value_whole_and_an_empty_one_to_nothing(): void {
		$this->assertSame('***', DocumentFormatter::mask_phone('123'));
		$this->assertSame('', DocumentFormatter::mask_phone('  '));
	}

	public function test_mask_name_keeps_each_words_first_letter(): void {
		$this->assertSame('M*** d* S***', DocumentFormatter::mask_name('Maria da Silva'));
		$this->assertSame('Á***', DocumentFormatter::mask_name('Álvaro'));
		$this->assertSame('', DocumentFormatter::mask_name('   '));
	}

	public function test_mask_any_uses_the_key_s_own_mask(): void {
		$this->assertSame('518.***.***-80', DocumentFormatter::mask_any('cpf', '51817842080'));
		$this->assertSame('a***@x.com', DocumentFormatter::mask_any('email', 'ana@x.com'));
		$this->assertSame('*********88', DocumentFormatter::mask_any('telefone', '11999998888'));
	}

	public function test_mask_any_never_returns_a_value_its_mask_could_not_handle(): void {
		// A value its kind's shape does not fit, and a key no kind knows,
		// both mask as generic.
		$this->assertSame('1***', DocumentFormatter::mask_any('cpf', '12345'));
		$this->assertSame('M***', DocumentFormatter::mask_any('rg_estadual', 'MG-1234'));
		$this->assertSame('', DocumentFormatter::mask_any('rg', ''));
	}

	// ==================================================================
	// mask_ip (#1760)
	// ==================================================================

	public function test_mask_ip_keeps_the_first_two_ipv4_octets(): void {
		$this->assertSame('203.0.***.***', DocumentFormatter::mask_ip('203.0.113.7'));
	}

	public function test_mask_ip_keeps_the_ipv6_network_half(): void {
		$this->assertSame('2001:db8:85a3:0:****:****:****:****', DocumentFormatter::mask_ip('2001:0db8:85a3:0000:0000:8a2e:0370:7334'));
	}

	public function test_mask_ip_expands_a_compressed_ipv6_address_first(): void {
		$this->assertSame('2001:db8:0:0:****:****:****:****', DocumentFormatter::mask_ip('2001:db8::1'));
		$this->assertSame('0:0:0:0:****:****:****:****', DocumentFormatter::mask_ip('::1'));
	}

	public function test_mask_ip_masks_an_ipv4_address_written_inside_ipv6_as_ipv4(): void {
		$this->assertSame('203.0.***.***', DocumentFormatter::mask_ip('::ffff:203.0.113.7'));
		$this->assertSame('203.0.***.***', DocumentFormatter::mask_ip('::FFFF:cb00:7107'));
	}

	public function test_mask_ip_masks_a_value_that_is_not_an_ip_whole(): void {
		$this->assertSame('***', DocumentFormatter::mask_ip('not-an-ip'));
	}

	public function test_mask_ip_returns_empty_for_empty_input(): void {
		$this->assertSame('', DocumentFormatter::mask_ip(''));
		$this->assertSame('', DocumentFormatter::mask_ip('  '));
	}

	// ==================================================================
	// mask_field_value — shared per-key PII masker (#838)
	// ==================================================================

	public function test_mask_field_value_masks_cpf_family_keys(): void {
		$this->assertSame('529.***.***-25', DocumentFormatter::mask_field_value('cpf', '52998224725'));
		$this->assertSame('529.***.***-25', DocumentFormatter::mask_field_value('cpf_rf', '52998224725'));
		$this->assertSame('52.***.***-*', DocumentFormatter::mask_field_value('rg', '529982247'));
	}

	public function test_mask_field_value_masks_rf_key(): void {
		$this->assertSame('123.***-7', DocumentFormatter::mask_field_value('rf', '1234567'));
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
	// One rule per kind (#1920)
	// ==================================================================

	/**
	 * @return array<string, array{0: string, 1: string, 2: string}>
	 */
	public function kind_provider(): array {
		return array(
			'cpf'         => array( DocumentFormatter::MASK_CPF, '529.982.247-25', '529.***.***-25' ),
			'cpf as rf'   => array( DocumentFormatter::MASK_CPF, '123.456-7', '123.***-7' ),
			'rf'          => array( DocumentFormatter::MASK_RF, '1234567', '123.***-7' ),
			'cin'         => array( DocumentFormatter::MASK_CIN, '12.345.678-9', '12.***.***-*' ),
			'cin with x'  => array( DocumentFormatter::MASK_CIN, '12.345.678-X', '12.***.***-*' ),
			'email'       => array( DocumentFormatter::MASK_EMAIL, 'maria@example.com', 'm***@example.com' ),
			'phone'       => array( DocumentFormatter::MASK_PHONE, '(11) 98765-4321', '(**) *****-**21' ),
			'name'        => array( DocumentFormatter::MASK_NAME, 'Maria da Silva', 'M*** d* S***' ),
			'birth date'  => array( DocumentFormatter::MASK_BIRTH_DATE, '1990-03-15', '15/03/****' ),
			'birth typed' => array( DocumentFormatter::MASK_BIRTH_DATE, '15/03/1990', '15/03/****' ),
			'ip'          => array( DocumentFormatter::MASK_IP, '203.0.113.7', '203.0.***.***' ),
			'generic'     => array( DocumentFormatter::MASK_GENERIC, 'Rua das Flores', 'R***' ),
			'unknown'     => array( 'no-such-kind', 'Rua das Flores', 'R***' ),
		);
	}

	/**
	 * @dataProvider kind_provider
	 */
	public function test_each_kind_has_one_shape( string $kind, string $value, string $expected ): void {
		$this->stub_wp_date();
		$this->assertSame( $expected, DocumentFormatter::mask( $kind, $value ) );
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public function malformed_provider(): array {
		$out   = array();
		$kinds = array(
			DocumentFormatter::MASK_CPF,
			DocumentFormatter::MASK_RF,
			DocumentFormatter::MASK_CIN,
			DocumentFormatter::MASK_EMAIL,
			DocumentFormatter::MASK_PHONE,
			DocumentFormatter::MASK_NAME,
			DocumentFormatter::MASK_BIRTH_DATE,
			DocumentFormatter::MASK_IP,
			DocumentFormatter::MASK_GENERIC,
		);
		$values = array( '1', '12345', 'abc', 'not-an-email', '2023-02-30', '99/99/9999', '529.982.247-25', 'x@y' );
		foreach ( $kinds as $kind ) {
			foreach ( $values as $value ) {
				$out[ $kind . ' ' . $value ] = array( $kind, $value );
			}
		}
		return $out;
	}

	/**
	 * @dataProvider malformed_provider
	 */
	public function test_no_kind_ever_returns_its_input( string $kind, string $value ): void {
		$this->stub_wp_date();
		$masked = DocumentFormatter::mask( $kind, $value );
		$this->assertNotSame( '', $masked, 'a non-empty value never masks to nothing' );
		$this->assertNotSame( $value, $masked, 'a mask never hands its input back' );
	}

	public function test_an_empty_value_masks_to_nothing_in_every_kind(): void {
		foreach ( array( 'cpf', 'rf', 'cin', 'email', 'phone', 'name', 'birth_date', 'ip', 'generic' ) as $kind ) {
			$this->assertSame( '', DocumentFormatter::mask( $kind, '' ), $kind );
			$this->assertSame( '', DocumentFormatter::mask( $kind, '   ' ), $kind );
		}
	}

	public function test_the_key_map_names_each_kind(): void {
		$this->assertSame( 'cpf', DocumentFormatter::kind_for_key( 'cpf_rf' ) );
		$this->assertSame( 'rf', DocumentFormatter::kind_for_key( 'rf' ) );
		$this->assertSame( 'cin', DocumentFormatter::kind_for_key( 'rg' ) );
		$this->assertSame( 'email', DocumentFormatter::kind_for_key( 'user_email' ) );
		$this->assertSame( 'email', DocumentFormatter::kind_for_key( 'email_institucional' ) );
		$this->assertSame( 'phone', DocumentFormatter::kind_for_key( 'celular' ) );
		$this->assertSame( 'birth_date', DocumentFormatter::kind_for_key( 'data_nascimento' ) );
		$this->assertSame( 'ip', DocumentFormatter::kind_for_key( 'user_ip' ) );
		$this->assertNull( DocumentFormatter::kind_for_key( 'course' ) );
		$this->assertNull( DocumentFormatter::kind_for_key( 'name' ), 'a name is masked by the screen, never by its key' );
	}

	public function test_a_custom_field_s_input_mask_picks_its_kind(): void {
		$this->assertSame( 'phone', DocumentFormatter::kind_for_key( 'contato_emergencia', 'phone' ) );
		$this->assertSame( '(**) *****-**21', DocumentFormatter::mask_any( 'contato_emergencia', '(11) 98765-4321', 'phone' ) );
		$this->assertSame( 'cpf', DocumentFormatter::kind_for_key( 'email', 'cpf' ), 'the input mask wins over the key' );
		$this->assertSame( 'email', DocumentFormatter::kind_for_key( 'email', 'number' ), 'an input mask with no kind leaves the key to decide' );
	}

	public function test_mask_field_value_now_masks_phone_birth_date_and_ip_keys(): void {
		$this->stub_wp_date();
		$this->assertSame( '(**) *****-**21', DocumentFormatter::mask_field_value( 'telefone', '(11) 98765-4321' ) );
		$this->assertSame( '15/03/****', DocumentFormatter::mask_field_value( 'data_nascimento', '1990-03-15' ) );
		$this->assertSame( '203.0.***.***', DocumentFormatter::mask_field_value( 'user_ip', '203.0.113.7' ) );
	}

	public function test_the_birth_date_follows_the_site_date_format_without_its_year(): void {
		$this->stub_wp_date();
		Functions\when( 'get_option' )->justReturn( array( 'date_format' => 'custom', 'date_format_custom' => 'Y-m-d' ) );
		$this->assertSame( '****-03-15', DocumentFormatter::mask_birth_date( '1990-03-15' ) );

		Functions\when( 'get_option' )->justReturn( array( 'date_format' => 'custom', 'date_format_custom' => 'd.m.y' ) );
		$this->assertSame( '15.03.**', DocumentFormatter::mask_birth_date( '1990-03-15' ) );
	}

	public function test_an_unparseable_birth_date_masks_to_its_placeholder(): void {
		$this->assertSame( '**/**/****', DocumentFormatter::mask_birth_date( 'yesterday' ) );
		$this->assertSame( '**/**/****', DocumentFormatter::mask_birth_date( '1990-02-30' ), 'a date that does not exist is not shifted into March' );
	}

	public function test_each_kind_has_a_placeholder_with_no_data(): void {
		$this->assertSame( '***.***.***-**', DocumentFormatter::mask_placeholder( 'cpf' ) );
		$this->assertSame( '***.***-*', DocumentFormatter::mask_placeholder( 'rf' ) );
		$this->assertSame( '**.***.***-*', DocumentFormatter::mask_placeholder( 'cin' ) );
		$this->assertSame( '***@***', DocumentFormatter::mask_placeholder( 'email' ) );
		$this->assertSame( '(**) *****-****', DocumentFormatter::mask_placeholder( 'phone' ) );
		$this->assertSame( '**/**/****', DocumentFormatter::mask_placeholder( 'birth_date' ) );
		$this->assertSame( '***', DocumentFormatter::mask_placeholder( 'generic' ) );
	}

	/**
	 * `wp_date()` formatting in UTC, as WordPress does for a given zone.
	 */
	private function stub_wp_date(): void {
		Functions\when( 'wp_date' )->alias(
			static function ( string $format, int $ts, ?\DateTimeZone $tz = null ): string {
				return ( new \DateTimeImmutable( '@' . $ts ) )->setTimezone( $tz ?? new \DateTimeZone( 'UTC' ) )->format( $format );
			}
		);
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

	// ==================================================================
	// Moved from UtilsTest (#2092)
	// ==================================================================

	public function test_validate_cpf_valid(): void {
		// Known valid CPF: 529.982.247-25
		$this->assertTrue( DocumentFormatter::validate_cpf( '52998224725' ) );
	}

	public function test_validate_cpf_valid_with_formatting(): void {
		$this->assertTrue( DocumentFormatter::validate_cpf( '529.982.247-25' ) );
	}

	public function test_validate_cpf_invalid_check_digit(): void {
		$this->assertFalse( DocumentFormatter::validate_cpf( '52998224700' ) );
	}

	public function test_validate_cpf_all_same_digits(): void {
		$this->assertFalse( DocumentFormatter::validate_cpf( '11111111111' ) );
		$this->assertFalse( DocumentFormatter::validate_cpf( '00000000000' ) );
		$this->assertFalse( DocumentFormatter::validate_cpf( '99999999999' ) );
	}

	public function test_validate_cpf_wrong_length(): void {
		$this->assertFalse( DocumentFormatter::validate_cpf( '1234567' ) );
		$this->assertFalse( DocumentFormatter::validate_cpf( '123456789012' ) );
	}

	public function test_validate_cpf_empty(): void {
		$this->assertFalse( DocumentFormatter::validate_cpf( '' ) );
	}

	public function test_validate_cpf_another_valid(): void {
		// Another known valid CPF: 111.444.777-35
		$this->assertTrue( DocumentFormatter::validate_cpf( '11144477735' ) );
	}

	public function test_validate_phone_mobile_formatted(): void {
		$this->assertTrue( DocumentFormatter::validate_phone( '(11) 99876-5432' ) );
	}

	public function test_validate_phone_landline_formatted(): void {
		$this->assertTrue( DocumentFormatter::validate_phone( '(11) 3456-7890' ) );
	}

	public function test_validate_phone_no_formatting(): void {
		$this->assertTrue( DocumentFormatter::validate_phone( '11998765432' ) );
	}

	public function test_validate_phone_without_parentheses(): void {
		$this->assertTrue( DocumentFormatter::validate_phone( '11 99876-5432' ) );
	}

	public function test_validate_phone_invalid_short(): void {
		$this->assertFalse( DocumentFormatter::validate_phone( '123' ) );
	}

	public function test_validate_phone_invalid_letters(): void {
		$this->assertFalse( DocumentFormatter::validate_phone( 'abcdefghij' ) );
	}

	public function test_validate_phone_empty(): void {
		$this->assertFalse( DocumentFormatter::validate_phone( '' ) );
	}

	public function test_format_cpf_wrong_length_returned_as_is(): void {
		$this->assertSame( '1234567', DocumentFormatter::format_cpf( '1234567' ) );
	}

	public function test_validate_rf_valid_with_dots(): void {
		$this->assertTrue( DocumentFormatter::validate_rf( '123.456-7' ) );
	}

	public function test_validate_rf_invalid_too_short(): void {
		$this->assertFalse( DocumentFormatter::validate_rf( '12345' ) );
	}

	public function test_mask_cpf_eleven_digits(): void {
		$this->assertSame( '123.***.***-09', DocumentFormatter::mask_cpf( '12345678909' ) );
	}

	public function test_mask_cpf_formatted_input(): void {
		$this->assertSame( '123.***.***-09', DocumentFormatter::mask_cpf( '123.456.789-09' ) );
	}

	public function test_format_auth_code_twelve_chars(): void {
		$this->assertSame( 'ABCD-EFGH-1234', DocumentFormatter::format_auth_code( 'abcdefgh1234' ) );
	}

	public function test_format_auth_code_with_hyphens_cleaned(): void {
		$this->assertSame( 'ABCD-EFGH-1234', DocumentFormatter::format_auth_code( 'ABCD-EFGH-1234' ) );
	}

	public function test_format_auth_code_wrong_length_returned_uppercase(): void {
		$this->assertSame( 'ABC', DocumentFormatter::format_auth_code( 'abc' ) );
	}

	public function test_format_document_auto_auth_code(): void {
		$this->assertSame( 'ABCD-EFGH-1234', DocumentFormatter::format_document( 'abcdefgh1234', 'auth_code' ) );
	}

	public function test_format_document_unknown_type_returned_as_is(): void {
		$this->assertSame( 'XYZ', DocumentFormatter::format_document( 'XYZ', 'unknown' ) );
	}

	public function test_clean_auth_code_strips_hyphens_uppercases(): void {
		$this->assertSame( 'ABCD1234EFGH', DocumentFormatter::clean_auth_code( 'abcd-1234-efgh' ) );
	}

	public function test_clean_auth_code_strips_spaces_and_specials(): void {
		$this->assertSame( 'ABC123', DocumentFormatter::clean_auth_code( ' a.b.c 1-2-3! ' ) );
	}

	public function test_clean_auth_code_already_clean(): void {
		$this->assertSame( 'ABCD', DocumentFormatter::clean_auth_code( 'ABCD' ) );
	}

	public function test_clean_identifier_uppercases(): void {
		$this->assertSame( 'ABCDEF', DocumentFormatter::clean_identifier( 'abc-def' ) );
	}

	public function test_phone_regex_constant_matches_validate_phone(): void {
		// The constant and the method should agree
		$phone = '(11) 99876-5432';
		$phone_clean = preg_replace( '/\s+/', '', $phone );
		$regex_result = (bool) preg_match( '/' . DocumentFormatter::PHONE_REGEX . '/', $phone_clean );
		$this->assertSame( DocumentFormatter::validate_phone( $phone ), $regex_result );
	}

	public function test_mask_email_valid(): void {
		Functions\when( 'is_email' )->justReturn( true );
		$this->assertSame( 'j***@gmail.com', DocumentFormatter::mask_email( 'joao@gmail.com' ) );
	}

	// ==================================================================
	// Gaps found by mutation testing (#2100)
	// ==================================================================

	public function test_validate_cpf_checks_the_first_check_digit_on_its_own(): void {
		// The first check digit of 529.982.247 is 2; with a 3 there, the second
		// digit that follows from it is 3, so only the first check catches it.
		$this->assertFalse( DocumentFormatter::validate_cpf( '52998224733' ) );
	}

	public function test_validate_cpf_maps_a_remainder_of_ten_to_zero(): void {
		// Both check digits of 100.000.001 come from a remainder of 10.
		$this->assertTrue( DocumentFormatter::validate_cpf( '10000000108' ) );
	}

	public function test_mask_generic_keeps_an_accented_first_letter_whole(): void {
		$this->assertSame( 'É***', DocumentFormatter::mask_generic( 'Érica' ) );
	}

	public function test_mask_name_counts_letters_not_bytes(): void {
		$this->assertSame( 'Z*', DocumentFormatter::mask_name( 'Zé' ), 'Two letters keep one asterisk, although é is two bytes.' );
	}

	public function test_parse_prefixed_code_reads_the_prefix_from_the_first_character(): void {
		$this->assertSame(
			array( 'prefix' => 'C', 'code' => '1BCD2345EFGH' ),
			DocumentFormatter::parse_prefixed_code( 'C-1BCD-2345-EFGH' ),
			'The second character is a digit, so it cannot pass for the prefix.'
		);
	}

	public function test_masks_take_their_shape_from_the_shortest_length_that_has_one(): void {
		$this->assertSame( '123.***-6', DocumentFormatter::mask_rf( '123456' ), 'Six digits is an RF.' );
		$this->assertSame( '12.***.***-*', DocumentFormatter::mask_cin( '123456' ), 'Six characters is a CIN.' );
		$this->assertSame( '**34', DocumentFormatter::mask_phone( '1234' ), 'Four digits is a phone.' );
	}

	public function test_mask_email_masks_an_invalid_address_with_one_at_sign_as_generic(): void {
		Functions\when( 'is_email' )->justReturn( false );

		$this->assertSame( 'j***', DocumentFormatter::mask_email( 'joao@gmail' ), 'An address WordPress rejects must not show its domain.' );
	}

	public function test_mask_email_trims_surrounding_spaces_before_validating(): void {
		Functions\when( 'is_email' )->alias( static fn( $email ) => false !== strpos( $email, '@' ) && trim( $email ) === $email );

		$this->assertSame( 'j***@gmail.com', DocumentFormatter::mask_email( '  joao@gmail.com ' ) );
	}

	public function test_clean_identifier_still_announces_its_deprecation(): void {
		$deprecated = CallLog::record( '_deprecated_function' );

		$this->assertSame( 'ABC123', DocumentFormatter::clean_identifier( 'abc-123' ) );
		$this->assertSame( 1, $deprecated->count() );
	}
}
