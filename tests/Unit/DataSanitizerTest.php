<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Migrations\DataSanitizer;

/**
 * Tests for DataSanitizer: field sanitization, JSON cleaning, identifier validation.
 */
class DataSanitizerTest extends TestCase {

    use MockeryPHPUnitIntegration;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        Functions\when( 'sanitize_text_field' )->returnArg();
        Functions\when( 'is_email' )->alias( function ( $email ) {
            return (bool) filter_var( $email, FILTER_VALIDATE_EMAIL );
        } );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    // ==================================================================
    // sanitize_field_value()
    // ==================================================================

    public function test_sanitize_empty_value_returns_empty_string(): void {
        $this->assertSame( '', DataSanitizer::sanitize_field_value( '', array() ) );
    }

    public function test_sanitize_null_value_returns_empty_string(): void {
        $this->assertSame( '', DataSanitizer::sanitize_field_value( null, array() ) );
    }

    public function test_sanitize_with_custom_callback(): void {
        $config = array( 'sanitize_callback' => 'strtoupper' );
        $this->assertSame( 'HELLO', DataSanitizer::sanitize_field_value( 'hello', $config ) );
    }

    public function test_sanitize_with_closure_callback(): void {
        $config = array( 'sanitize_callback' => function ( $v ) { return trim( $v ); } );
        $this->assertSame( 'hello', DataSanitizer::sanitize_field_value( '  hello  ', $config ) );
    }

    public function test_sanitize_no_callback_uses_sanitize_text_field(): void {
        $result = DataSanitizer::sanitize_field_value( 'test value', array() );
        $this->assertSame( 'test value', $result );
    }

    public function test_sanitize_non_callable_fallback(): void {
        $config = array( 'sanitize_callback' => 'nonexistent_function_xyz' );
        $result = DataSanitizer::sanitize_field_value( 'test', $config );
        $this->assertSame( 'test', $result );
    }

    // ==================================================================
    // clean_json_data()
    // ==================================================================

    public function test_clean_json_from_string(): void {
        $json = '{"name":"John","email":"john@test.com"}';
        $result = DataSanitizer::clean_json_data( $json );
        $this->assertSame( array( 'name' => 'John', 'email' => 'john@test.com' ), $result );
    }

    public function test_clean_json_from_array(): void {
        $data = array( 'name' => 'John', 'city' => 'SP' );
        $result = DataSanitizer::clean_json_data( $data );
        $this->assertSame( $data, $result );
    }

    public function test_clean_json_removes_empty_values(): void {
        $data = array( 'name' => 'John', 'empty1' => '', 'empty2' => null, 'empty3' => array() );
        $result = DataSanitizer::clean_json_data( $data );
        $this->assertSame( array( 'name' => 'John' ), $result );
    }

    public function test_clean_json_preserves_zero_string(): void {
        $data = array( 'name' => 'John', 'count' => '0' );
        $result = DataSanitizer::clean_json_data( $data );
        $this->assertArrayHasKey( 'count', $result );
        $this->assertSame( '0', $result['count'] );
    }

    public function test_clean_json_preserves_zero_int(): void {
        $data = array( 'name' => 'John', 'count' => 0 );
        $result = DataSanitizer::clean_json_data( $data );
        $this->assertArrayHasKey( 'count', $result );
        $this->assertSame( 0, $result['count'] );
    }

    public function test_clean_json_invalid_json_returns_empty(): void {
        $this->assertSame( array(), DataSanitizer::clean_json_data( 'not json' ) );
    }

    public function test_clean_json_non_array_returns_empty(): void {
        $this->assertSame( array(), DataSanitizer::clean_json_data( 42 ) );
    }

    // ==================================================================
    // extract_field_from_json()
    // ==================================================================

    public function test_extract_finds_first_matching_key(): void {
        $data = array( 'email_address' => 'a@b.com', 'name' => 'John' );
        $result = DataSanitizer::extract_field_from_json( $data, array( 'email', 'email_address' ) );
        $this->assertSame( 'a@b.com', $result );
    }

    public function test_extract_returns_first_non_empty_match(): void {
        $data = array( 'email' => '', 'email_address' => 'a@b.com' );
        $result = DataSanitizer::extract_field_from_json( $data, array( 'email', 'email_address' ) );
        $this->assertSame( 'a@b.com', $result );
    }

    public function test_extract_no_match_returns_empty(): void {
        $data = array( 'name' => 'John' );
        $result = DataSanitizer::extract_field_from_json( $data, array( 'email', 'email_address' ) );
        $this->assertSame( '', $result );
    }

    public function test_extract_empty_keys_returns_empty(): void {
        $data = array( 'name' => 'John' );
        $this->assertSame( '', DataSanitizer::extract_field_from_json( $data, array() ) );
    }

    // ==================================================================
    // is_valid_identifier()
    // ==================================================================

    public function test_valid_cpf_11_digits(): void {
        $this->assertTrue( DataSanitizer::is_valid_identifier( '12345678901' ) );
    }

    public function test_valid_cpf_formatted(): void {
        $this->assertTrue( DataSanitizer::is_valid_identifier( '123.456.789-01' ) );
    }

    public function test_valid_rf_6_digits(): void {
        $this->assertTrue( DataSanitizer::is_valid_identifier( '123456' ) );
    }

    public function test_invalid_identifier_too_short(): void {
        $this->assertFalse( DataSanitizer::is_valid_identifier( '12345' ) );
    }

    public function test_invalid_identifier_too_long(): void {
        $this->assertFalse( DataSanitizer::is_valid_identifier( '123456789012' ) );
    }

    public function test_invalid_identifier_empty(): void {
        $this->assertFalse( DataSanitizer::is_valid_identifier( '' ) );
    }

    public function test_valid_identifier_with_formatting(): void {
        $this->assertTrue( DataSanitizer::is_valid_identifier( '123-456-789' ) );
    }

    // ==================================================================
    // is_valid_email()
    // ==================================================================

    public function test_valid_email(): void {
        $this->assertTrue( DataSanitizer::is_valid_email( 'test@example.com' ) );
    }

    public function test_invalid_email(): void {
        $this->assertFalse( DataSanitizer::is_valid_email( 'not-an-email' ) );
    }

    public function test_empty_email(): void {
        $this->assertFalse( DataSanitizer::is_valid_email( '' ) );
    }

    // ==================================================================
    // normalize_auth_code()
    // ==================================================================

    public function test_normalize_removes_spaces(): void {
        $this->assertSame( 'ABC123', DataSanitizer::normalize_auth_code( 'abc 123' ) );
    }

    public function test_normalize_removes_dashes(): void {
        $this->assertSame( 'ABC123', DataSanitizer::normalize_auth_code( 'abc-123' ) );
    }

    public function test_normalize_removes_underscores(): void {
        $this->assertSame( 'ABC123', DataSanitizer::normalize_auth_code( 'abc_123' ) );
    }

    public function test_normalize_uppercase(): void {
        $this->assertSame( 'ABCDEF', DataSanitizer::normalize_auth_code( 'abcdef' ) );
    }

    public function test_normalize_empty_returns_empty(): void {
        $this->assertSame( '', DataSanitizer::normalize_auth_code( '' ) );
    }

    public function test_normalize_already_clean(): void {
        $this->assertSame( 'ABC123', DataSanitizer::normalize_auth_code( 'ABC123' ) );
    }
}
