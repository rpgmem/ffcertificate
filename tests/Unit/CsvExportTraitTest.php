<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * Concrete stub that exposes CsvExportTrait's protected methods for testing.
 */
class CsvExportTraitStub {
    use \FreeFormCertificate\Core\CsvExportTrait;

    public function pub_extract_dynamic_keys( array $rows, string $plain = 'data', string $encrypted = 'data_encrypted' ): array {
        return $this->extract_dynamic_keys( $rows, $plain, $encrypted );
    }

    public function pub_decode_json_field( array $row, string $plain = 'data', string $encrypted = 'data_encrypted' ): array {
        return $this->decode_json_field( $row, $plain, $encrypted );
    }

    public function pub_build_dynamic_headers( array $keys ): array {
        return $this->build_dynamic_headers( $keys );
    }

    public function pub_extract_dynamic_values( array $row, array $keys, string $plain = 'data', string $encrypted = 'data_encrypted' ): array {
        return $this->extract_dynamic_values( $row, $keys, $plain, $encrypted );
    }
}

/**
 * Tests for CsvExportTrait: dynamic key extraction, JSON decoding,
 * header generation, value extraction.
 */
class CsvExportTraitTest extends TestCase {

    use MockeryPHPUnitIntegration;

    /** @var CsvExportTraitStub */
    private $stub;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        $this->stub = new CsvExportTraitStub();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    // ==================================================================
    // build_dynamic_headers() — pure string transformation
    // ==================================================================

    public function test_headers_snake_case_to_title_case(): void {
        $this->assertSame(
            array( 'First Name', 'Last Name' ),
            $this->stub->pub_build_dynamic_headers( array( 'first_name', 'last_name' ) )
        );
    }

    public function test_headers_kebab_case_to_title_case(): void {
        $this->assertSame(
            array( 'Phone Number' ),
            $this->stub->pub_build_dynamic_headers( array( 'phone-number' ) )
        );
    }

    public function test_headers_mixed_separators(): void {
        $this->assertSame(
            array( 'Some Field Name' ),
            $this->stub->pub_build_dynamic_headers( array( 'some_field-name' ) )
        );
    }

    public function test_headers_empty_input(): void {
        $this->assertSame( array(), $this->stub->pub_build_dynamic_headers( array() ) );
    }

    public function test_headers_single_word(): void {
        $this->assertSame( array( 'Email' ), $this->stub->pub_build_dynamic_headers( array( 'email' ) ) );
    }

    // ==================================================================
    // decode_json_field() — JSON + encryption fallback
    // ==================================================================

    public function test_decode_plain_json(): void {
        $row = array( 'data' => '{"name":"João","age":30}' );
        $result = $this->stub->pub_decode_json_field( $row );
        $this->assertSame( array( 'name' => 'João', 'age' => 30 ), $result );
    }

    public function test_decode_empty_row_returns_empty(): void {
        $this->assertSame( array(), $this->stub->pub_decode_json_field( array() ) );
    }

    public function test_decode_invalid_json_returns_empty(): void {
        $row = array( 'data' => 'not json' );
        $this->assertSame( array(), $this->stub->pub_decode_json_field( $row ) );
    }

    public function test_decode_null_json_returns_empty(): void {
        $row = array( 'data' => 'null' );
        $this->assertSame( array(), $this->stub->pub_decode_json_field( $row ) );
    }

    public function test_decode_custom_keys(): void {
        $row = array( 'custom_data' => '{"cpf":"123"}' );
        $result = $this->stub->pub_decode_json_field( $row, 'custom_data', 'custom_data_encrypted' );
        $this->assertSame( array( 'cpf' => '123' ), $result );
    }

    public function test_decode_plain_fallback_when_encrypted_empty(): void {
        $row = array(
            'data_encrypted' => '',
            'data'           => '{"field":"value"}',
        );
        $result = $this->stub->pub_decode_json_field( $row );
        $this->assertSame( array( 'field' => 'value' ), $result );
    }

    // ==================================================================
    // extract_dynamic_keys() — unique key discovery across rows
    // ==================================================================

    public function test_keys_from_multiple_rows(): void {
        $rows = array(
            array( 'data' => '{"name":"A","email":"a@test.com"}' ),
            array( 'data' => '{"name":"B","phone":"123"}' ),
        );
        $keys = $this->stub->pub_extract_dynamic_keys( $rows );
        sort( $keys );
        $this->assertSame( array( 'email', 'name', 'phone' ), $keys );
    }

    public function test_keys_deduplicates(): void {
        $rows = array(
            array( 'data' => '{"x":"1","y":"2"}' ),
            array( 'data' => '{"x":"3","y":"4"}' ),
        );
        $keys = $this->stub->pub_extract_dynamic_keys( $rows );
        $this->assertCount( 2, $keys );
    }

    public function test_keys_empty_rows(): void {
        $this->assertSame( array(), $this->stub->pub_extract_dynamic_keys( array() ) );
    }

    public function test_keys_rows_with_no_json(): void {
        $rows = array( array( 'data' => '' ), array( 'other' => 'val' ) );
        $this->assertSame( array(), $this->stub->pub_extract_dynamic_keys( $rows ) );
    }

    // ==================================================================
    // extract_dynamic_values() — ordered value extraction
    // ==================================================================

    public function test_values_in_key_order(): void {
        $row = array( 'data' => '{"b":"second","a":"first","c":"third"}' );
        $keys = array( 'a', 'b', 'c' );
        $this->assertSame( array( 'first', 'second', 'third' ), $this->stub->pub_extract_dynamic_values( $row, $keys ) );
    }

    public function test_values_missing_key_returns_empty_string(): void {
        $row = array( 'data' => '{"a":"1"}' );
        $keys = array( 'a', 'missing' );
        $this->assertSame( array( '1', '' ), $this->stub->pub_extract_dynamic_values( $row, $keys ) );
    }

    public function test_values_array_flattened(): void {
        $row = array( 'data' => '{"tags":["red","blue"]}' );
        $keys = array( 'tags' );
        $this->assertSame( array( 'red, blue' ), $this->stub->pub_extract_dynamic_values( $row, $keys ) );
    }

    public function test_values_empty_keys(): void {
        $row = array( 'data' => '{"a":"1"}' );
        $this->assertSame( array(), $this->stub->pub_extract_dynamic_values( $row, array() ) );
    }
}
