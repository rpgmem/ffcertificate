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
