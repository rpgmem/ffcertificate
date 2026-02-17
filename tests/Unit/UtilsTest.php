<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Core\Utils;

/**
 * Tests for Utils: document validation/formatting, sanitization, captcha, and helpers.
 *
 * Group A: Pure functions (no mocking needed)
 * Group B: Functions requiring WordPress mocks (Brain\Monkey)
 */
class UtilsTest extends TestCase {

    use MockeryPHPUnitIntegration;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    // ==================================================================
    // validate_cpf() — Group A (Pure)
    // ==================================================================

    public function test_validate_cpf_valid(): void {
        // Known valid CPF: 529.982.247-25
        $this->assertTrue( Utils::validate_cpf( '52998224725' ) );
    }

    public function test_validate_cpf_valid_with_formatting(): void {
        $this->assertTrue( Utils::validate_cpf( '529.982.247-25' ) );
    }

    public function test_validate_cpf_invalid_check_digit(): void {
        $this->assertFalse( Utils::validate_cpf( '52998224700' ) );
    }

    public function test_validate_cpf_all_same_digits(): void {
        $this->assertFalse( Utils::validate_cpf( '11111111111' ) );
        $this->assertFalse( Utils::validate_cpf( '00000000000' ) );
        $this->assertFalse( Utils::validate_cpf( '99999999999' ) );
    }

    public function test_validate_cpf_wrong_length(): void {
        $this->assertFalse( Utils::validate_cpf( '1234567' ) );
        $this->assertFalse( Utils::validate_cpf( '123456789012' ) );
    }

    public function test_validate_cpf_empty(): void {
        $this->assertFalse( Utils::validate_cpf( '' ) );
    }

    public function test_validate_cpf_another_valid(): void {
        // Another known valid CPF: 111.444.777-35
        $this->assertTrue( Utils::validate_cpf( '11144477735' ) );
    }

    // ==================================================================
    // validate_phone() — Group A (Pure)
    // ==================================================================

    public function test_validate_phone_mobile_formatted(): void {
        $this->assertTrue( Utils::validate_phone( '(11) 99876-5432' ) );
    }

    public function test_validate_phone_landline_formatted(): void {
        $this->assertTrue( Utils::validate_phone( '(11) 3456-7890' ) );
    }

    public function test_validate_phone_no_formatting(): void {
        $this->assertTrue( Utils::validate_phone( '11998765432' ) );
    }

    public function test_validate_phone_without_parentheses(): void {
        $this->assertTrue( Utils::validate_phone( '11 99876-5432' ) );
    }

    public function test_validate_phone_invalid_short(): void {
        $this->assertFalse( Utils::validate_phone( '123' ) );
    }

    public function test_validate_phone_invalid_letters(): void {
        $this->assertFalse( Utils::validate_phone( 'abcdefghij' ) );
    }

    public function test_validate_phone_empty(): void {
        $this->assertFalse( Utils::validate_phone( '' ) );
    }

    // ==================================================================
    // format_cpf() — Group A (Pure)
    // ==================================================================

    public function test_format_cpf_eleven_digits(): void {
        $this->assertSame( '529.982.247-25', Utils::format_cpf( '52998224725' ) );
    }

    public function test_format_cpf_already_formatted(): void {
        $this->assertSame( '529.982.247-25', Utils::format_cpf( '529.982.247-25' ) );
    }

    public function test_format_cpf_wrong_length_returned_as_is(): void {
        $this->assertSame( '1234567', Utils::format_cpf( '1234567' ) );
    }

    // ==================================================================
    // validate_rf() — Group A (Pure)
    // ==================================================================

    public function test_validate_rf_valid_seven_digits(): void {
        $this->assertTrue( Utils::validate_rf( '1234567' ) );
    }

    public function test_validate_rf_valid_with_dots(): void {
        $this->assertTrue( Utils::validate_rf( '123.456-7' ) );
    }

    public function test_validate_rf_invalid_too_short(): void {
        $this->assertFalse( Utils::validate_rf( '12345' ) );
    }

    public function test_validate_rf_invalid_too_long(): void {
        $this->assertFalse( Utils::validate_rf( '12345678' ) );
    }

    public function test_validate_rf_empty(): void {
        $this->assertFalse( Utils::validate_rf( '' ) );
    }

    // ==================================================================
    // format_rf() — Group A (Pure)
    // ==================================================================

    public function test_format_rf_seven_digits(): void {
        $this->assertSame( '123.456-7', Utils::format_rf( '1234567' ) );
    }

    public function test_format_rf_already_formatted(): void {
        $this->assertSame( '123.456-7', Utils::format_rf( '123.456-7' ) );
    }

    public function test_format_rf_wrong_length_returned_as_is(): void {
        $this->assertSame( '12345', Utils::format_rf( '12345' ) );
    }

    // ==================================================================
    // mask_cpf() — Group A (Pure)
    // ==================================================================

    public function test_mask_cpf_eleven_digits(): void {
        $this->assertSame( '123.***.***-09', Utils::mask_cpf( '12345678909' ) );
    }

    public function test_mask_cpf_formatted_input(): void {
        $this->assertSame( '123.***.***-09', Utils::mask_cpf( '123.456.789-09' ) );
    }

    public function test_mask_cpf_rf_seven_digits(): void {
        $this->assertSame( '123.***-7', Utils::mask_cpf( '1234567' ) );
    }

    public function test_mask_cpf_other_length_returned_as_is(): void {
        $this->assertSame( '12345', Utils::mask_cpf( '12345' ) );
    }

    public function test_mask_cpf_empty(): void {
        $this->assertSame( '', Utils::mask_cpf( '' ) );
    }

    // ==================================================================
    // format_auth_code() — Group A (Pure)
    // ==================================================================

    public function test_format_auth_code_twelve_chars(): void {
        $this->assertSame( 'ABCD-EFGH-1234', Utils::format_auth_code( 'abcdefgh1234' ) );
    }

    public function test_format_auth_code_with_hyphens_cleaned(): void {
        $this->assertSame( 'ABCD-EFGH-1234', Utils::format_auth_code( 'ABCD-EFGH-1234' ) );
    }

    public function test_format_auth_code_wrong_length_returned_uppercase(): void {
        $this->assertSame( 'ABC', Utils::format_auth_code( 'abc' ) );
    }

    // ==================================================================
    // format_document() — Group A (Pure)
    // ==================================================================

    public function test_format_document_auto_cpf(): void {
        $this->assertSame( '529.982.247-25', Utils::format_document( '52998224725' ) );
    }

    public function test_format_document_auto_rf(): void {
        $this->assertSame( '123.456-7', Utils::format_document( '1234567' ) );
    }

    public function test_format_document_auto_auth_code(): void {
        $this->assertSame( 'ABCD-EFGH-1234', Utils::format_document( 'abcdefgh1234', 'auth_code' ) );
    }

    public function test_format_document_explicit_cpf(): void {
        $this->assertSame( '529.982.247-25', Utils::format_document( '52998224725', 'cpf' ) );
    }

    public function test_format_document_unknown_type_returned_as_is(): void {
        $this->assertSame( 'XYZ', Utils::format_document( 'XYZ', 'unknown' ) );
    }

    public function test_format_document_auto_unknown_length(): void {
        $this->assertSame( '12345', Utils::format_document( '12345' ) );
    }

    // ==================================================================
    // sanitize_filename() — Group A (Pure)
    // ==================================================================

    public function test_sanitize_filename_simple(): void {
        $this->assertSame( 'certificate.pdf', Utils::sanitize_filename( 'Certificate.pdf' ) );
    }

    public function test_sanitize_filename_special_chars(): void {
        $this->assertSame( 'meu-certificado.pdf', Utils::sanitize_filename( 'Meu Certificado!.pdf' ) );
    }

    public function test_sanitize_filename_multiple_dashes_collapsed(): void {
        $this->assertSame( 'a-b.txt', Utils::sanitize_filename( 'a---b.txt' ) );
    }

    public function test_sanitize_filename_edge_dashes_trimmed(): void {
        $this->assertSame( 'test.pdf', Utils::sanitize_filename( '--test--.pdf' ) );
    }

    public function test_sanitize_filename_no_extension(): void {
        $this->assertSame( 'readme', Utils::sanitize_filename( 'README' ) );
    }

    public function test_sanitize_filename_accented_chars(): void {
        // ç and ã are 2 bytes each, both replaced by '-', then collapsed
        $this->assertSame( 'certifica-o.pdf', Utils::sanitize_filename( 'Certificação.pdf' ) );
    }

    // ==================================================================
    // format_bytes() — Group A (Pure)
    // ==================================================================

    public function test_format_bytes_zero(): void {
        $this->assertSame( '0 B', Utils::format_bytes( 0 ) );
    }

    public function test_format_bytes_bytes(): void {
        $this->assertSame( '512 B', Utils::format_bytes( 512 ) );
    }

    public function test_format_bytes_kilobytes(): void {
        $this->assertSame( '1 KB', Utils::format_bytes( 1024 ) );
    }

    public function test_format_bytes_megabytes(): void {
        $this->assertSame( '1.5 MB', Utils::format_bytes( 1572864 ) );
    }

    public function test_format_bytes_gigabytes(): void {
        $this->assertSame( '1 GB', Utils::format_bytes( 1073741824 ) );
    }

    public function test_format_bytes_custom_precision(): void {
        $this->assertSame( '1.5 MB', Utils::format_bytes( 1572864, 1 ) );
    }

    // ==================================================================
    // truncate() — Group A (Pure)
    // ==================================================================

    public function test_truncate_short_text_unchanged(): void {
        $this->assertSame( 'Hello', Utils::truncate( 'Hello', 10 ) );
    }

    public function test_truncate_exact_length_unchanged(): void {
        $this->assertSame( 'Hello', Utils::truncate( 'Hello', 5 ) );
    }

    public function test_truncate_long_text(): void {
        $this->assertSame( 'Hell...', Utils::truncate( 'Hello World', 7 ) );
    }

    public function test_truncate_custom_suffix(): void {
        $this->assertSame( 'Hel--', Utils::truncate( 'Hello World', 5, '--' ) );
    }

    public function test_truncate_default_length(): void {
        $long = str_repeat( 'a', 200 );
        $result = Utils::truncate( $long );
        $this->assertSame( 100, strlen( $result ) );
        $this->assertStringEndsWith( '...', $result );
    }

    // ==================================================================
    // clean_auth_code() — Group A (Pure)
    // ==================================================================

    public function test_clean_auth_code_strips_hyphens_uppercases(): void {
        $this->assertSame( 'ABCD1234EFGH', Utils::clean_auth_code( 'abcd-1234-efgh' ) );
    }

    public function test_clean_auth_code_strips_spaces_and_specials(): void {
        $this->assertSame( 'ABC123', Utils::clean_auth_code( ' a.b.c 1-2-3! ' ) );
    }

    public function test_clean_auth_code_already_clean(): void {
        $this->assertSame( 'ABCD', Utils::clean_auth_code( 'ABCD' ) );
    }

    // ==================================================================
    // clean_identifier() — Group A (Pure)
    // ==================================================================

    public function test_clean_identifier_strips_dots_hyphens(): void {
        $this->assertSame( '52998224725', Utils::clean_identifier( '529.982.247-25' ) );
    }

    public function test_clean_identifier_uppercases(): void {
        $this->assertSame( 'ABCDEF', Utils::clean_identifier( 'abc-def' ) );
    }

    // ==================================================================
    // normalize_brazilian_name() — Group A (Pure)
    // ==================================================================

    public function test_normalize_name_all_uppercase(): void {
        $this->assertSame( 'Alex Pereira da Silva', Utils::normalize_brazilian_name( 'ALEX PEREIRA DA SILVA' ) );
    }

    public function test_normalize_name_all_lowercase(): void {
        $this->assertSame( 'Maria dos Santos e Oliveira', Utils::normalize_brazilian_name( 'maria dos santos e oliveira' ) );
    }

    public function test_normalize_name_connectives_lowercase(): void {
        $result = Utils::normalize_brazilian_name( 'JOÃO DE SOUZA FILHO' );
        $this->assertSame( 'João de Souza Filho', $result );
    }

    public function test_normalize_name_first_word_connective_capitalized(): void {
        // Even if the first word is a connective, it gets capitalized
        $this->assertSame( 'Da Silva', Utils::normalize_brazilian_name( 'da silva' ) );
    }

    public function test_normalize_name_italian_connectives(): void {
        $this->assertSame( 'Marco di Pietro du Valle', Utils::normalize_brazilian_name( 'MARCO DI PIETRO DU VALLE' ) );
    }

    public function test_normalize_name_empty(): void {
        $this->assertSame( '', Utils::normalize_brazilian_name( '' ) );
    }

    public function test_normalize_name_accented_characters(): void {
        $this->assertSame( 'José das Neves', Utils::normalize_brazilian_name( 'JOSÉ DAS NEVES' ) );
    }

    public function test_normalize_name_extra_spaces(): void {
        $this->assertSame( 'Ana Clara', Utils::normalize_brazilian_name( '  ANA   CLARA  ' ) );
    }

    // ==================================================================
    // PHONE_REGEX constant
    // ==================================================================

    public function test_phone_regex_constant_matches_validate_phone(): void {
        // The constant and the method should agree
        $phone = '(11) 99876-5432';
        $phone_clean = preg_replace( '/\s+/', '', $phone );
        $regex_result = (bool) preg_match( '/' . Utils::PHONE_REGEX . '/', $phone_clean );
        $this->assertSame( Utils::validate_phone( $phone ), $regex_result );
    }

    // ==================================================================
    // asset_suffix() — Group B (WordPress mock)
    // ==================================================================

    public function test_asset_suffix_production(): void {
        // SCRIPT_DEBUG not defined → returns '.min'
        $this->assertSame( '.min', Utils::asset_suffix() );
    }

    // ==================================================================
    // mask_email() — Group B (WordPress mock)
    // ==================================================================

    public function test_mask_email_valid(): void {
        Functions\when( 'is_email' )->justReturn( true );
        $this->assertSame( 'j***@gmail.com', Utils::mask_email( 'joao@gmail.com' ) );
    }

    public function test_mask_email_invalid(): void {
        Functions\when( 'is_email' )->justReturn( false );
        $this->assertSame( 'not-an-email', Utils::mask_email( 'not-an-email' ) );
    }

    public function test_mask_email_empty(): void {
        $this->assertSame( '', Utils::mask_email( '' ) );
    }

    // ==================================================================
    // generate_random_string() — Group B (WordPress mock)
    // ==================================================================

    public function test_generate_random_string_length(): void {
        Functions\when( 'wp_rand' )->alias( function( $min, $max ) {
            return mt_rand( $min, $max );
        } );
        $result = Utils::generate_random_string( 8 );
        $this->assertSame( 8, strlen( $result ) );
    }

    public function test_generate_random_string_custom_chars(): void {
        Functions\when( 'wp_rand' )->justReturn( 0 );
        $result = Utils::generate_random_string( 4, 'ABC' );
        $this->assertSame( 'AAAA', $result );
    }

    public function test_generate_random_string_default_length(): void {
        Functions\when( 'wp_rand' )->alias( function( $min, $max ) {
            return mt_rand( $min, $max );
        } );
        $result = Utils::generate_random_string();
        $this->assertSame( 12, strlen( $result ) );
    }

    // ==================================================================
    // generate_auth_code() — Group B (WordPress mock)
    // ==================================================================

    public function test_generate_auth_code_format(): void {
        Functions\when( 'wp_rand' )->alias( function( $min, $max ) {
            return mt_rand( $min, $max );
        } );
        $code = Utils::generate_auth_code();
        $this->assertMatchesRegularExpression( '/^[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}$/', $code );
    }

    // ==================================================================
    // current_user_can_manage() — Group B (WordPress mock)
    // ==================================================================

    public function test_current_user_can_manage_true(): void {
        Functions\when( 'current_user_can' )->justReturn( true );
        $this->assertTrue( Utils::current_user_can_manage() );
    }

    public function test_current_user_can_manage_false(): void {
        Functions\when( 'current_user_can' )->justReturn( false );
        $this->assertFalse( Utils::current_user_can_manage() );
    }

    // ==================================================================
    // verify_simple_captcha() — Group B (WordPress mock)
    // ==================================================================

    public function test_verify_captcha_correct_answer(): void {
        Functions\when( 'wp_hash' )->alias( function( $data ) {
            return md5( $data );
        } );
        $hash = md5( '5ffc_math_salt' );
        $this->assertTrue( Utils::verify_simple_captcha( '5', $hash ) );
    }

    public function test_verify_captcha_wrong_answer(): void {
        Functions\when( 'wp_hash' )->alias( function( $data ) {
            return md5( $data );
        } );
        $hash = md5( '5ffc_math_salt' );
        $this->assertFalse( Utils::verify_simple_captcha( '99', $hash ) );
    }

    public function test_verify_captcha_empty_answer(): void {
        $this->assertFalse( Utils::verify_simple_captcha( '', 'somehash' ) );
    }

    public function test_verify_captcha_empty_hash(): void {
        $this->assertFalse( Utils::verify_simple_captcha( '5', '' ) );
    }

    public function test_verify_captcha_trims_answer(): void {
        Functions\when( 'wp_hash' )->alias( function( $data ) {
            return md5( $data );
        } );
        $hash = md5( '5ffc_math_salt' );
        $this->assertTrue( Utils::verify_simple_captcha( ' 5 ', $hash ) );
    }

    // ==================================================================
    // validate_security_fields() — Group B (WordPress mock)
    // ==================================================================

    public function test_security_fields_honeypot_triggered(): void {
        Functions\when( '__' )->returnArg();
        $data = array( 'ffc_honeypot_trap' => 'bot-filled' );
        $result = Utils::validate_security_fields( $data );
        $this->assertIsString( $result );
        $this->assertStringContainsString( 'Honeypot', $result );
    }

    public function test_security_fields_missing_captcha(): void {
        Functions\when( '__' )->returnArg();
        $data = array();
        $result = Utils::validate_security_fields( $data );
        $this->assertIsString( $result );
        $this->assertStringContainsString( 'security question', $result );
    }

    public function test_security_fields_wrong_captcha(): void {
        Functions\when( '__' )->returnArg();
        Functions\when( 'wp_hash' )->alias( function( $data ) {
            return md5( $data );
        } );
        $data = array(
            'ffc_captcha_ans'  => '99',
            'ffc_captcha_hash' => md5( '5ffc_math_salt' ),
        );
        $result = Utils::validate_security_fields( $data );
        $this->assertIsString( $result );
        $this->assertStringContainsString( 'incorrect', $result );
    }

    public function test_security_fields_valid(): void {
        Functions\when( '__' )->returnArg();
        Functions\when( 'wp_hash' )->alias( function( $data ) {
            return md5( $data );
        } );
        $hash = md5( '5ffc_math_salt' );
        $data = array(
            'ffc_captcha_ans'  => '5',
            'ffc_captcha_hash' => $hash,
        );
        $this->assertTrue( Utils::validate_security_fields( $data ) );
    }

    // ==================================================================
    // get_allowed_html_tags() — Group B (WordPress mock)
    // ==================================================================

    public function test_allowed_html_tags_returns_array(): void {
        Functions\when( 'apply_filters' )->alias( function() {
            $args = func_get_args();
            return $args[1]; // Return the second argument (the value being filtered)
        } );
        $tags = Utils::get_allowed_html_tags();
        $this->assertIsArray( $tags );
        $this->assertArrayHasKey( 'b', $tags );
        $this->assertArrayHasKey( 'table', $tags );
        $this->assertArrayHasKey( 'img', $tags );
        $this->assertArrayHasKey( 'h1', $tags );
        $this->assertArrayHasKey( 'ul', $tags );
    }

    // ==================================================================
    // get_submissions_table() — Group C (DB mock)
    // ==================================================================

    public function test_get_submissions_table(): void {
        global $wpdb;
        $wpdb = (object) array( 'prefix' => 'wp_' );
        $this->assertSame( 'wp_ffc_submissions', Utils::get_submissions_table() );
    }

    public function test_get_submissions_table_multisite_prefix(): void {
        global $wpdb;
        $wpdb = (object) array( 'prefix' => 'wp_3_' );
        $this->assertSame( 'wp_3_ffc_submissions', Utils::get_submissions_table() );
    }

    // ==================================================================
    // generate_simple_captcha() — Group B (WordPress mock)
    // ==================================================================

    public function test_generate_captcha_structure(): void {
        $call_count = 0;
        Functions\when( 'wp_rand' )->alias( function() use ( &$call_count ) {
            $call_count++;
            return $call_count <= 1 ? 3 : 7;
        } );
        Functions\when( 'esc_html__' )->alias( function( $text ) {
            return $text;
        } );
        Functions\when( 'wp_hash' )->alias( function( $data ) {
            return md5( $data );
        } );

        $captcha = Utils::generate_simple_captcha();
        $this->assertArrayHasKey( 'label', $captcha );
        $this->assertArrayHasKey( 'hash', $captcha );
        $this->assertArrayHasKey( 'answer', $captcha );
        $this->assertSame( 10, $captcha['answer'] );
    }

    // ==================================================================
    // recursive_sanitize() — Group B (WordPress mock)
    // ==================================================================

    public function test_recursive_sanitize_string(): void {
        Functions\when( 'wp_kses' )->alias( function( $data ) {
            return strip_tags( $data );
        } );
        Functions\when( 'apply_filters' )->alias( function() {
            $args = func_get_args();
            return $args[1];
        } );

        $this->assertSame( 'hello', Utils::recursive_sanitize( '<script>hello</script>' ) );
    }

    public function test_recursive_sanitize_nested_array(): void {
        Functions\when( 'sanitize_key' )->alias( function( $key ) {
            return strtolower( preg_replace( '/[^a-zA-Z0-9_\-]/', '', $key ) );
        } );
        Functions\when( 'wp_kses' )->alias( function( $data ) {
            return strip_tags( $data );
        } );
        Functions\when( 'apply_filters' )->alias( function() {
            $args = func_get_args();
            return $args[1];
        } );

        $input = array(
            'Name' => '<b>John</b>',
            'Items' => array(
                'Sub' => '<i>Value</i>',
            ),
        );

        $result = Utils::recursive_sanitize( $input );
        $this->assertSame( 'John', $result['name'] );
        $this->assertSame( 'Value', $result['items']['sub'] );
    }
}
