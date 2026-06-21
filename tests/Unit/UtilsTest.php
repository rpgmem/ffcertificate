<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Core\AuthCodeService;
use FreeFormCertificate\Core\DataSanitizer;
use FreeFormCertificate\Core\DocumentFormatter;
use FreeFormCertificate\Core\SecurityService;
use FreeFormCertificate\Core\Utils;
use FreeFormCertificate\Core\Capabilities;
use FreeFormCertificate\Core\RequestInput;
use FreeFormCertificate\Core\FilenameHelper;

/**
 * Tests for Utils: document validation/formatting, sanitization, captcha, and helpers.
 *
 * Group A: Pure functions (no mocking needed)
 * Group B: Functions requiring WordPress mocks (Brain\Monkey)
 * @runClassInSeparateProcess
 * @preserveGlobalState disabled
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

    // ==================================================================
    // validate_phone() — Group A (Pure)
    // ==================================================================

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

    // ==================================================================
    // format_cpf() — Group A (Pure)
    // ==================================================================

    public function test_format_cpf_eleven_digits(): void {
        $this->assertSame( '529.982.247-25', DocumentFormatter::format_cpf( '52998224725' ) );
    }

    public function test_format_cpf_already_formatted(): void {
        $this->assertSame( '529.982.247-25', DocumentFormatter::format_cpf( '529.982.247-25' ) );
    }

    public function test_format_cpf_wrong_length_returned_as_is(): void {
        $this->assertSame( '1234567', DocumentFormatter::format_cpf( '1234567' ) );
    }

    // ==================================================================
    // validate_rf() — Group A (Pure)
    // ==================================================================

    public function test_validate_rf_valid_seven_digits(): void {
        $this->assertTrue( DocumentFormatter::validate_rf( '1234567' ) );
    }

    public function test_validate_rf_valid_with_dots(): void {
        $this->assertTrue( DocumentFormatter::validate_rf( '123.456-7' ) );
    }

    public function test_validate_rf_invalid_too_short(): void {
        $this->assertFalse( DocumentFormatter::validate_rf( '12345' ) );
    }

    public function test_validate_rf_invalid_too_long(): void {
        $this->assertFalse( DocumentFormatter::validate_rf( '12345678' ) );
    }

    public function test_validate_rf_empty(): void {
        $this->assertFalse( DocumentFormatter::validate_rf( '' ) );
    }

    // ==================================================================
    // format_rf() — Group A (Pure)
    // ==================================================================

    public function test_format_rf_seven_digits(): void {
        $this->assertSame( '123.456-7', DocumentFormatter::format_rf( '1234567' ) );
    }

    public function test_format_rf_already_formatted(): void {
        $this->assertSame( '123.456-7', DocumentFormatter::format_rf( '123.456-7' ) );
    }

    public function test_format_rf_wrong_length_returned_as_is(): void {
        $this->assertSame( '12345', DocumentFormatter::format_rf( '12345' ) );
    }

    // ==================================================================
    // mask_cpf() — Group A (Pure)
    // ==================================================================

    public function test_mask_cpf_eleven_digits(): void {
        $this->assertSame( '123.***.***-09', DocumentFormatter::mask_cpf( '12345678909' ) );
    }

    public function test_mask_cpf_formatted_input(): void {
        $this->assertSame( '123.***.***-09', DocumentFormatter::mask_cpf( '123.456.789-09' ) );
    }

    public function test_mask_cpf_rf_seven_digits(): void {
        $this->assertSame( '123.***-7', DocumentFormatter::mask_cpf( '1234567' ) );
    }

    public function test_mask_cpf_other_length_returned_as_is(): void {
        $this->assertSame( '12345', DocumentFormatter::mask_cpf( '12345' ) );
    }

    public function test_mask_cpf_empty(): void {
        $this->assertSame( '', DocumentFormatter::mask_cpf( '' ) );
    }

    // ==================================================================
    // format_auth_code() — Group A (Pure)
    // ==================================================================

    public function test_format_auth_code_twelve_chars(): void {
        $this->assertSame( 'ABCD-EFGH-1234', DocumentFormatter::format_auth_code( 'abcdefgh1234' ) );
    }

    public function test_format_auth_code_with_hyphens_cleaned(): void {
        $this->assertSame( 'ABCD-EFGH-1234', DocumentFormatter::format_auth_code( 'ABCD-EFGH-1234' ) );
    }

    public function test_format_auth_code_wrong_length_returned_uppercase(): void {
        $this->assertSame( 'ABC', DocumentFormatter::format_auth_code( 'abc' ) );
    }

    // ==================================================================
    // format_document() — Group A (Pure)
    // ==================================================================

    public function test_format_document_auto_cpf(): void {
        $this->assertSame( '529.982.247-25', DocumentFormatter::format_document( '52998224725' ) );
    }

    public function test_format_document_auto_rf(): void {
        $this->assertSame( '123.456-7', DocumentFormatter::format_document( '1234567' ) );
    }

    public function test_format_document_auto_auth_code(): void {
        $this->assertSame( 'ABCD-EFGH-1234', DocumentFormatter::format_document( 'abcdefgh1234', 'auth_code' ) );
    }

    public function test_format_document_explicit_cpf(): void {
        $this->assertSame( '529.982.247-25', DocumentFormatter::format_document( '52998224725', 'cpf' ) );
    }

    public function test_format_document_unknown_type_returned_as_is(): void {
        $this->assertSame( 'XYZ', DocumentFormatter::format_document( 'XYZ', 'unknown' ) );
    }

    public function test_format_document_auto_unknown_length(): void {
        $this->assertSame( '12345', DocumentFormatter::format_document( '12345' ) );
    }

    // ==================================================================
    // sanitize_filename() — Group A (Pure)
    // ==================================================================

    // ==================================================================
    // build_pdf_filename() — 6.6.11 standardized PDF filename helper
    // ==================================================================

    /**
     * Helper: stub `_x` + `apply_filters` so the helper runs deterministically.
     * The default mapping mirrors the production PT-BR labels.
     */
    private function stub_pdf_filename_helpers(): void {
        Functions\when( '_x' )->alias( function ( $text, $context, $domain ) {
            return $text;
        } );
        Functions\when( 'apply_filters' )->alias( function ( $hook, $value ) {
            return $value;
        } );
    }

    public function test_build_pdf_filename_certificate_attaches_C_prefix(): void {
        $this->stub_pdf_filename_helpers();
        // Raw 12-char auth code from DB → helper prepends "C-" to match
        // DocumentFormatter::PREFIX_CERTIFICATE, and strips inner dashes
        // from the code body for filesystem compactness.
        $this->assertSame(
            'certificado_666_C-MLQQZ9UX9MWF.pdf',
            FilenameHelper::build_pdf_filename( 'certificate', 666, 'MLQQZ9UX9MWF' )
        );
    }

    public function test_build_pdf_filename_appointment_receipt_attaches_A_prefix(): void {
        $this->stub_pdf_filename_helpers();
        $this->assertSame(
            'recibo_42_A-7K3M9P2XQRST.pdf',
            FilenameHelper::build_pdf_filename( 'appointment_receipt', 42, '7K3M9P2XQRST' )
        );
    }

    public function test_build_pdf_filename_ficha_attaches_R_prefix_for_real_authcode(): void {
        $this->stub_pdf_filename_helpers();
        // Approved ficha → real auth code from AuthCodeService.
        $this->assertSame(
            'ficha_99_R-ABCDEF123456.pdf',
            FilenameHelper::build_pdf_filename( 'ficha', 99, 'ABCDEF123456' )
        );
    }

    public function test_build_pdf_filename_ficha_synthetic_code_skips_prefix(): void {
        $this->stub_pdf_filename_helpers();
        // Draft / submitted ficha (auth_code not yet generated) — synthetic
        // S{id} stays as-is, no `R-` prefix, since it's not a verifiable code.
        $this->assertSame(
            'ficha_99_S12345.pdf',
            FilenameHelper::build_pdf_filename( 'ficha', 99, 'S12345' )
        );
    }

    public function test_build_pdf_filename_does_not_double_prefix_pre_formatted_code(): void {
        $this->stub_pdf_filename_helpers();
        // Caller passed already-formatted `C-MLQQ-Z9UX-9MWF` (the display
        // shape). Helper detects the existing `C-` prefix and only strips
        // inner dashes — does not re-prepend, does not duplicate.
        $this->assertSame(
            'certificado_666_C-MLQQZ9UX9MWF.pdf',
            FilenameHelper::build_pdf_filename( 'certificate', 666, 'C-MLQQ-Z9UX-9MWF' )
        );
    }

    public function test_build_pdf_filename_code_is_uppercased(): void {
        $this->stub_pdf_filename_helpers();
        $this->assertSame(
            'certificado_1_C-ABC123.pdf',
            FilenameHelper::build_pdf_filename( 'certificate', 1, 'abc123' )
        );
    }

    public function test_build_pdf_filename_strips_unsafe_chars_from_code(): void {
        $this->stub_pdf_filename_helpers();
        // Spaces, slashes stripped — alphanumerics preserved, dashes from
        // the original input collapsed by the compact-body step.
        $this->assertSame(
            'certificado_1_C-ABCDEF123.pdf',
            FilenameHelper::build_pdf_filename( 'certificate', 1, 'abc def/123' )
        );
    }

    public function test_build_pdf_filename_empty_code_drops_segment(): void {
        $this->stub_pdf_filename_helpers();
        $this->assertSame(
            'ficha_99.pdf',
            FilenameHelper::build_pdf_filename( 'ficha', 99, '' )
        );
    }

    public function test_build_pdf_filename_negative_id_clamped_to_zero(): void {
        $this->stub_pdf_filename_helpers();
        $this->assertSame(
            'certificado_0_C-X.pdf',
            FilenameHelper::build_pdf_filename( 'certificate', -5, 'X' )
        );
    }

    public function test_build_pdf_filename_unknown_type_falls_back_to_slug(): void {
        $this->stub_pdf_filename_helpers();
        // Unknown types echo the slug as prefix AND skip the auth-code
        // virtual prefix — those map only to known types. Useful escape
        // hatch for sites adding custom PDF types via the central filter.
        $this->assertSame(
            'invoice_7_NF42.pdf',
            FilenameHelper::build_pdf_filename( 'invoice', 7, 'NF42' )
        );
    }

    public function test_build_pdf_filename_locale_translatable_prefix(): void {
        // Site running in EN locale — _x returns the translated string.
        Functions\when( '_x' )->alias( function ( $text, $context, $domain ) {
            $map = array(
                'certificado' => 'certificate',
                'recibo'      => 'receipt',
                'ficha'       => 'record',
            );
            return $map[ $text ] ?? $text;
        } );
        Functions\when( 'apply_filters' )->alias( function ( $hook, $value ) {
            return $value;
        } );

        $this->assertSame(
            'certificate_666_C-MLQQZ9UX9MWF.pdf',
            FilenameHelper::build_pdf_filename( 'certificate', 666, 'MLQQZ9UX9MWF' )
        );
        $this->assertSame(
            'receipt_42_A-7K3M9P2X.pdf',
            FilenameHelper::build_pdf_filename( 'appointment_receipt', 42, '7K3M9P2X' )
        );
        $this->assertSame(
            'record_99_S12345.pdf',
            FilenameHelper::build_pdf_filename( 'ficha', 99, 'S12345' )
        );
    }

    public function test_build_pdf_filename_central_filter_fires(): void {
        Functions\when( '_x' )->returnArg();
        // Filter rewrites filename to a stable EN slug — the documented
        // escape hatch for DMS automation sites. The $code arg has
        // already been normalised (uppercase + virtual prefix attached)
        // by the time the filter fires.
        Functions\when( 'apply_filters' )->alias( function ( $hook, $value, $type = null, $entity_id = null, $code = null ) {
            if ( 'ffcertificate_pdf_filename' === $hook ) {
                $stable = array(
                    'certificate'         => 'certificate',
                    'appointment_receipt' => 'receipt',
                    'ficha'               => 'record',
                );
                $prefix = $stable[ $type ] ?? $type;
                return '' !== $code
                    ? sprintf( '%s_%d_%s.pdf', $prefix, $entity_id, $code )
                    : sprintf( '%s_%d.pdf', $prefix, $entity_id );
            }
            return $value;
        } );

        $this->assertSame(
            'certificate_666_C-MLQQZ9UX9MWF.pdf',
            FilenameHelper::build_pdf_filename( 'certificate', 666, 'MLQQZ9UX9MWF' )
        );
    }

    // ==================================================================
    // sanitize_filename()
    // ==================================================================

    public function test_sanitize_filename_simple(): void {
        $this->assertSame( 'certificate.pdf', FilenameHelper::sanitize_filename( 'Certificate.pdf' ) );
    }

    public function test_sanitize_filename_special_chars(): void {
        $this->assertSame( 'meu-certificado.pdf', FilenameHelper::sanitize_filename( 'Meu Certificado!.pdf' ) );
    }

    public function test_sanitize_filename_multiple_dashes_collapsed(): void {
        $this->assertSame( 'a-b.txt', FilenameHelper::sanitize_filename( 'a---b.txt' ) );
    }

    public function test_sanitize_filename_edge_dashes_trimmed(): void {
        $this->assertSame( 'test.pdf', FilenameHelper::sanitize_filename( '--test--.pdf' ) );
    }

    public function test_sanitize_filename_no_extension(): void {
        $this->assertSame( 'readme', FilenameHelper::sanitize_filename( 'README' ) );
    }

    public function test_sanitize_filename_accented_chars(): void {
        // ç and ã are 2 bytes each, both replaced by '-', then collapsed
        $this->assertSame( 'certifica-o.pdf', FilenameHelper::sanitize_filename( 'Certificação.pdf' ) );
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
        $this->assertSame( 'ABCD1234EFGH', DocumentFormatter::clean_auth_code( 'abcd-1234-efgh' ) );
    }

    public function test_clean_auth_code_strips_spaces_and_specials(): void {
        $this->assertSame( 'ABC123', DocumentFormatter::clean_auth_code( ' a.b.c 1-2-3! ' ) );
    }

    public function test_clean_auth_code_already_clean(): void {
        $this->assertSame( 'ABCD', DocumentFormatter::clean_auth_code( 'ABCD' ) );
    }

    // ==================================================================
    // clean_identifier() — Group A (Pure)
    // ==================================================================

    public function test_clean_identifier_strips_dots_hyphens(): void {
        $this->assertSame( '52998224725', DocumentFormatter::clean_identifier( '529.982.247-25' ) );
    }

    public function test_clean_identifier_uppercases(): void {
        $this->assertSame( 'ABCDEF', DocumentFormatter::clean_identifier( 'abc-def' ) );
    }

    // ==================================================================
    // normalize_brazilian_name() — Group A (Pure)
    // ==================================================================

    public function test_normalize_name_all_uppercase(): void {
        $this->assertSame( 'Alex Pereira da Silva', DataSanitizer::normalize_brazilian_name( 'ALEX PEREIRA DA SILVA' ) );
    }

    public function test_normalize_name_all_lowercase(): void {
        $this->assertSame( 'Maria dos Santos e Oliveira', DataSanitizer::normalize_brazilian_name( 'maria dos santos e oliveira' ) );
    }

    public function test_normalize_name_connectives_lowercase(): void {
        $result = DataSanitizer::normalize_brazilian_name( 'JOÃO DE SOUZA FILHO' );
        $this->assertSame( 'João de Souza Filho', $result );
    }

    public function test_normalize_name_first_word_connective_capitalized(): void {
        // Even if the first word is a connective, it gets capitalized
        $this->assertSame( 'Da Silva', DataSanitizer::normalize_brazilian_name( 'da silva' ) );
    }

    public function test_normalize_name_italian_connectives(): void {
        $this->assertSame( 'Marco di Pietro du Valle', DataSanitizer::normalize_brazilian_name( 'MARCO DI PIETRO DU VALLE' ) );
    }

    public function test_normalize_name_empty(): void {
        $this->assertSame( '', DataSanitizer::normalize_brazilian_name( '' ) );
    }

    public function test_normalize_name_accented_characters(): void {
        $this->assertSame( 'José das Neves', DataSanitizer::normalize_brazilian_name( 'JOSÉ DAS NEVES' ) );
    }

    public function test_normalize_name_extra_spaces(): void {
        $this->assertSame( 'Ana Clara', DataSanitizer::normalize_brazilian_name( '  ANA   CLARA  ' ) );
    }

    // ==================================================================
    // PHONE_REGEX constant
    // ==================================================================

    public function test_phone_regex_constant_matches_validate_phone(): void {
        // The constant and the method should agree
        $phone = '(11) 99876-5432';
        $phone_clean = preg_replace( '/\s+/', '', $phone );
        $regex_result = (bool) preg_match( '/' . DocumentFormatter::PHONE_REGEX . '/', $phone_clean );
        $this->assertSame( DocumentFormatter::validate_phone( $phone ), $regex_result );
    }

    // ==================================================================
    // mask_email() — Group B (WordPress mock)
    // ==================================================================

    public function test_mask_email_valid(): void {
        Functions\when( 'is_email' )->justReturn( true );
        $this->assertSame( 'j***@gmail.com', DocumentFormatter::mask_email( 'joao@gmail.com' ) );
    }

    public function test_mask_email_invalid(): void {
        Functions\when( 'is_email' )->justReturn( false );
        $this->assertSame( 'not-an-email', DocumentFormatter::mask_email( 'not-an-email' ) );
    }

    public function test_mask_email_empty(): void {
        $this->assertSame( '', DocumentFormatter::mask_email( '' ) );
    }

    // ==================================================================
    // generate_random_string() — Group B (WordPress mock)
    // ==================================================================

    public function test_generate_random_string_length(): void {
        Functions\when( 'wp_rand' )->alias( function( $min, $max ) {
            return mt_rand( $min, $max );
        } );
        $result = AuthCodeService::generate_random_string( 8 );
        $this->assertSame( 8, strlen( $result ) );
    }

    public function test_generate_random_string_custom_chars(): void {
        Functions\when( 'wp_rand' )->justReturn( 0 );
        $result = AuthCodeService::generate_random_string( 4, 'ABC' );
        $this->assertSame( 'AAAA', $result );
    }

    public function test_generate_random_string_default_length(): void {
        Functions\when( 'wp_rand' )->alias( function( $min, $max ) {
            return mt_rand( $min, $max );
        } );
        $result = AuthCodeService::generate_random_string();
        $this->assertSame( 12, strlen( $result ) );
    }

    // ==================================================================
    // generate_auth_code() — Group B (WordPress mock)
    // ==================================================================

    public function test_generate_auth_code_format(): void {
        Functions\when( 'wp_rand' )->alias( function( $min, $max ) {
            return mt_rand( $min, $max );
        } );
        $code = AuthCodeService::generate_auth_code();
        $this->assertMatchesRegularExpression( '/^[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}$/', $code );
    }

    // ==================================================================
    // current_user_can_manage() — Group B (WordPress mock)
    // ==================================================================

    public function test_current_user_can_manage_true(): void {
        Functions\when( 'current_user_can' )->justReturn( true );
        $this->assertTrue( Capabilities::current_user_can_manage() );
    }

    public function test_current_user_can_manage_false(): void {
        Functions\when( 'current_user_can' )->justReturn( false );
        $this->assertFalse( Capabilities::current_user_can_manage() );
    }

    // ==================================================================
    // verify_simple_captcha() — Group B (WordPress mock)
    // ==================================================================

    public function test_verify_captcha_correct_answer(): void {
        Functions\when( 'wp_hash' )->alias( function( $data ) {
            return md5( $data );
        } );
        $hash = md5( '5ffc_math_salt' );
        $this->assertTrue( SecurityService::verify_simple_captcha( '5', $hash ) );
    }

    public function test_verify_captcha_wrong_answer(): void {
        Functions\when( 'wp_hash' )->alias( function( $data ) {
            return md5( $data );
        } );
        $hash = md5( '5ffc_math_salt' );
        $this->assertFalse( SecurityService::verify_simple_captcha( '99', $hash ) );
    }

    public function test_verify_captcha_empty_answer(): void {
        $this->assertFalse( SecurityService::verify_simple_captcha( '', 'somehash' ) );
    }

    public function test_verify_captcha_empty_hash(): void {
        $this->assertFalse( SecurityService::verify_simple_captcha( '5', '' ) );
    }

    public function test_verify_captcha_trims_answer(): void {
        Functions\when( 'wp_hash' )->alias( function( $data ) {
            return md5( $data );
        } );
        $hash = md5( '5ffc_math_salt' );
        $this->assertTrue( SecurityService::verify_simple_captcha( ' 5 ', $hash ) );
    }

    // ==================================================================
    // validate_security_fields() — Group B (WordPress mock)
    // ==================================================================

    public function test_security_fields_honeypot_triggered(): void {
        Functions\when( '__' )->returnArg();
        $data = array( 'ffc_honeypot_trap' => 'bot-filled' );
        $result = SecurityService::validate_security_fields( $data );
        $this->assertIsString( $result );
        $this->assertStringContainsString( 'Honeypot', $result );
    }

    public function test_security_fields_missing_captcha(): void {
        Functions\when( '__' )->returnArg();
        $data = array();
        $result = SecurityService::validate_security_fields( $data );
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
        $result = SecurityService::validate_security_fields( $data );
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
        $this->assertTrue( SecurityService::validate_security_fields( $data ) );
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
        Functions\when( '__' )->returnArg();
        Functions\when( 'wp_rand' )->alias( function ( int $min = 0, int $max = 0 ) { return random_int( $min, $max ); } );
        Functions\when( 'esc_html__' )->returnArg();
        Functions\when( 'wp_hash' )->alias( function( $data ) {
            return md5( $data );
        } );

        $captcha = SecurityService::generate_simple_captcha();
        $this->assertArrayHasKey( 'label', $captcha );
        $this->assertArrayHasKey( 'hash', $captcha );
        $this->assertArrayHasKey( 'answer', $captcha );
        $this->assertGreaterThanOrEqual( 0, $captcha['answer'] );
        $this->assertLessThanOrEqual( 45, $captcha['answer'] );
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

        $this->assertSame( 'hello', DataSanitizer::recursive_sanitize( '<script>hello</script>' ) );
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

        $result = DataSanitizer::recursive_sanitize( $input );
        $this->assertSame( 'John', $result['name'] );
        $this->assertSame( 'Value', $result['items']['sub'] );
    }

    // ==================================================================
    // get_export_filename
    // ==================================================================

    public function test_get_export_filename_without_title(): void {
        Functions\when( 'sanitize_file_name' )->returnArg();

        $result = FilenameHelper::get_export_filename( 'submissions' );

        $this->assertMatchesRegularExpression( '/^submissions-\d{4}-\d{2}-\d{2}\.csv$/', $result );
    }

    public function test_get_export_filename_with_title(): void {
        Functions\when( 'sanitize_file_name' )->returnArg();

        $result = FilenameHelper::get_export_filename( 'submissions', 'Course X' );

        $this->assertMatchesRegularExpression( '/^submissions-Course X-\d{4}-\d{2}-\d{2}\.csv$/', $result );
    }

    public function test_get_export_filename_with_empty_string_title_skips_segment(): void {
        Functions\when( 'sanitize_file_name' )->returnArg();

        $result = FilenameHelper::get_export_filename( 'audit', '' );

        $this->assertMatchesRegularExpression( '/^audit-\d{4}-\d{2}-\d{2}\.csv$/', $result );
    }

    public function test_get_export_filename_passes_title_through_sanitize_file_name(): void {
        Functions\when( 'sanitize_file_name' )->alias( static function ( string $name ): string {
            return strtolower( preg_replace( '/[^a-z0-9_-]/i', '_', $name ) );
        } );

        $result = FilenameHelper::get_export_filename( 'forms', 'My / Bad Name!' );

        $this->assertMatchesRegularExpression( '/^forms-my___bad_name_-\d{4}-\d{2}-\d{2}\.csv$/', $result );
    }

    // ==================================================================
    // get_day_of_week_number
    // ==================================================================

    public function test_get_day_of_week_number_for_known_dates(): void {
        // 2026-05-17 was a Sunday (`gmdate('w', ...)` returns 0).
        $sunday_ts = strtotime( '2026-05-17 12:00:00 UTC' );
        $this->assertSame( 0, Utils::get_day_of_week_number( $sunday_ts ) );

        // 2026-05-20 was a Wednesday → 3.
        $wednesday_ts = strtotime( '2026-05-20 12:00:00 UTC' );
        $this->assertSame( 3, Utils::get_day_of_week_number( $wednesday_ts ) );

        // 2026-05-23 was a Saturday → 6.
        $saturday_ts = strtotime( '2026-05-23 12:00:00 UTC' );
        $this->assertSame( 6, Utils::get_day_of_week_number( $saturday_ts ) );
    }

    public function test_get_day_of_week_number_defaults_to_current_time(): void {
        $value = Utils::get_day_of_week_number();

        $this->assertGreaterThanOrEqual( 0, $value );
        $this->assertLessThanOrEqual( 6, $value );
    }

    // ==================================================================
    // sanitize_username_slug
    // ==================================================================

    public function test_sanitize_username_slug_strips_accents_and_invalid_chars(): void {
        // Mock `sanitize_user` to replace spaces with dashes (matches the
        // class of separators the slug helper collapses to '.').
        Functions\when( 'sanitize_user' )->alias( static function ( string $user ): string {
            return strtolower( str_replace( ' ', '-', $user ) );
        } );
        Functions\when( 'remove_accents' )->alias( static function ( string $value ): string {
            return strtr( $value, array( 'á' => 'a', 'é' => 'e', 'ç' => 'c' ) );
        } );

        $this->assertSame( 'jose.silva', Utils::sanitize_username_slug( 'José Silva' ) );
    }

    public function test_sanitize_username_slug_collapses_separators(): void {
        Functions\when( 'sanitize_user' )->returnArg();
        Functions\when( 'remove_accents' )->returnArg();

        $this->assertSame( 'a.b.c', Utils::sanitize_username_slug( 'a---b___c' ) );
    }

    public function test_sanitize_username_slug_trims_leading_trailing_dots(): void {
        Functions\when( 'sanitize_user' )->returnArg();
        Functions\when( 'remove_accents' )->returnArg();

        $this->assertSame( 'abc', Utils::sanitize_username_slug( '...abc...' ) );
    }

    public function test_sanitize_username_slug_returns_empty_when_no_valid_chars(): void {
        Functions\when( 'sanitize_user' )->returnArg();
        Functions\when( 'remove_accents' )->returnArg();

        $this->assertSame( '', Utils::sanitize_username_slug( '...' ) );
    }

    // ==================================================================
    // get_post_array
    // ==================================================================

    public function test_get_post_array_returns_default_when_key_absent(): void {
        $_POST = array();

        $this->assertSame( array(), RequestInput::get_post_array( 'missing' ) );
        $this->assertSame( array( 'fallback' ), RequestInput::get_post_array( 'missing', array( 'fallback' ) ) );
    }

    public function test_get_post_array_returns_default_when_value_not_array(): void {
        Functions\when( 'wp_unslash' )->returnArg();
        $_POST = array( 'key' => 'not-an-array' );

        $this->assertSame( array(), RequestInput::get_post_array( 'key' ) );
    }

    public function test_get_post_array_sanitizes_string_values(): void {
        Functions\when( 'sanitize_text_field' )->alias( static function ( string $value ): string {
            return trim( strip_tags( $value ) );
        } );
        Functions\when( 'wp_unslash' )->returnArg();

        $_POST = array(
            'roles' => array( '  admin  ', '<b>editor</b>' ),
        );

        $this->assertSame( array( 'admin', 'editor' ), RequestInput::get_post_array( 'roles' ) );
    }

    public function test_get_post_array_strips_slashes_via_wp_unslash(): void {
        Functions\when( 'sanitize_text_field' )->returnArg();
        Functions\when( 'wp_unslash' )->alias( static function ( $value ) {
            return is_array( $value ) ? array_map( 'stripslashes', $value ) : stripslashes( $value );
        } );

        $_POST = array( 'list' => array( 'foo\\bar' ) );

        $this->assertSame( array( 'foobar' ), RequestInput::get_post_array( 'list' ) );
    }

    // ==================================================================
    // get_post_string / get_get_string
    // ==================================================================

    public function test_get_post_string_returns_default_when_key_absent(): void {
        $_POST = array();

        $this->assertSame( '', RequestInput::get_post_string( 'missing' ) );
        $this->assertSame( 'fallback', RequestInput::get_post_string( 'missing', 'fallback' ) );
    }

    public function test_get_post_string_returns_default_when_value_not_string(): void {
        Functions\when( 'wp_unslash' )->returnArg();
        $_POST = array( 'key' => array( 'array', 'not', 'string' ) );

        $this->assertSame( 'def', RequestInput::get_post_string( 'key', 'def' ) );
    }

    public function test_get_post_string_sanitizes_and_unslashes(): void {
        Functions\when( 'sanitize_text_field' )->alias( static function ( string $v ): string {
            return trim( strip_tags( $v ) );
        } );
        Functions\when( 'wp_unslash' )->alias( static function ( $v ) {
            return is_string( $v ) ? stripslashes( $v ) : $v;
        } );

        $_POST = array( 'name' => '  <b>John\\\'s</b>  ' );

        $this->assertSame( "John's", RequestInput::get_post_string( 'name' ) );
    }

    public function test_get_get_string_returns_default_when_key_absent(): void {
        $_GET = array();

        $this->assertSame( '', RequestInput::get_get_string( 'missing' ) );
        $this->assertSame( 'def', RequestInput::get_get_string( 'missing', 'def' ) );
    }

    public function test_get_get_string_reads_get_not_post(): void {
        Functions\when( 'sanitize_text_field' )->returnArg();
        Functions\when( 'wp_unslash' )->returnArg();

        $_GET  = array( 'k' => 'from-get' );
        $_POST = array( 'k' => 'from-post' );

        $this->assertSame( 'from-get', RequestInput::get_get_string( 'k' ) );
    }

    public function test_get_get_string_returns_default_when_value_not_string(): void {
        Functions\when( 'wp_unslash' )->returnArg();
        $_GET = array( 'k' => array( 'arr' ) );

        $this->assertSame( '', RequestInput::get_get_string( 'k' ) );
    }

    // ==================================================================
    // get_post_int
    // ==================================================================

    public function test_get_post_int_returns_default_when_absent(): void {
        $_POST = array();

        $this->assertSame( 0, RequestInput::get_post_int( 'missing' ) );
        $this->assertSame( 99, RequestInput::get_post_int( 'missing', 99 ) );
    }

    public function test_get_post_int_casts_string_to_int(): void {
        Functions\when( 'absint' )->alias( static function ( $v ): int {
            return abs( (int) $v );
        } );
        Functions\when( 'wp_unslash' )->returnArg();

        $_POST = array( 'limit' => '42' );

        $this->assertSame( 42, RequestInput::get_post_int( 'limit' ) );
    }

    public function test_get_post_int_absint_strips_negative_sign(): void {
        Functions\when( 'absint' )->alias( static function ( $v ): int {
            return abs( (int) $v );
        } );
        Functions\when( 'wp_unslash' )->returnArg();

        $_POST = array( 'n' => '-17' );

        $this->assertSame( 17, RequestInput::get_post_int( 'n' ) );
    }

    // ==================================================================
    // get_post_bool
    // ==================================================================

    public function test_get_post_bool_returns_default_when_absent(): void {
        $_POST = array();

        $this->assertFalse( RequestInput::get_post_bool( 'missing' ) );
        $this->assertTrue( RequestInput::get_post_bool( 'missing', true ) );
    }

    public function test_get_post_bool_truthy_values(): void {
        $_POST = array(
            'a' => '1',
            'b' => 'on',
            'c' => 'yes',
            'd' => 1,
        );

        $this->assertTrue( RequestInput::get_post_bool( 'a' ) );
        $this->assertTrue( RequestInput::get_post_bool( 'b' ) );
        $this->assertTrue( RequestInput::get_post_bool( 'c' ) );
        $this->assertTrue( RequestInput::get_post_bool( 'd' ) );
    }

    public function test_get_post_bool_falsy_values(): void {
        $_POST = array(
            'a' => '',
            'b' => '0',
            'c' => 0,
        );

        $this->assertFalse( RequestInput::get_post_bool( 'a' ) );
        $this->assertFalse( RequestInput::get_post_bool( 'b' ) );
        $this->assertFalse( RequestInput::get_post_bool( 'c' ) );
    }
}
