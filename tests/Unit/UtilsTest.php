<?php
/**
 * Tests for Utils class
 *
 * @package FreeFormCertificate
 * @subpackage Tests
 */

namespace FreeFormCertificate\Tests\Unit;

use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Core\Utils;

/**
 * @covers \FreeFormCertificate\Core\Utils
 */
class UtilsTest extends TestCase {

    /**
     * Test CPF validation with valid CPF
     */
    public function test_validate_cpf_with_valid_cpf() {
        // Valid CPF: 123.456.789-09
        $this->assertTrue(Utils::validate_cpf('12345678909'));
        $this->assertTrue(Utils::validate_cpf('123.456.789-09'));
    }

    /**
     * Test CPF validation with invalid CPF
     */
    public function test_validate_cpf_with_invalid_cpf() {
        $this->assertFalse(Utils::validate_cpf('12345678900')); // Invalid checksum
        $this->assertFalse(Utils::validate_cpf('00000000000')); // All zeros
        $this->assertFalse(Utils::validate_cpf('11111111111')); // All same digit
        $this->assertFalse(Utils::validate_cpf('123')); // Too short
        $this->assertFalse(Utils::validate_cpf('')); // Empty
    }

    /**
     * Test CPF formatting
     */
    public function test_format_cpf() {
        $this->assertEquals('123.456.789-09', Utils::format_cpf('12345678909'));
        $this->assertEquals('123.456.789-09', Utils::format_cpf('123.456.789-09'));
        $this->assertEquals('123', Utils::format_cpf('123')); // Too short, returns as-is
    }

    /**
     * Test RF validation with valid RF
     */
    public function test_validate_rf_with_valid_rf() {
        $this->assertTrue(Utils::validate_rf('1234567'));
        $this->assertTrue(Utils::validate_rf('123.456-7'));
    }

    /**
     * Test RF validation with invalid RF
     */
    public function test_validate_rf_with_invalid_rf() {
        $this->assertFalse(Utils::validate_rf('123')); // Too short
        $this->assertFalse(Utils::validate_rf('12345678')); // Too long
        $this->assertFalse(Utils::validate_rf('')); // Empty
        $this->assertFalse(Utils::validate_rf('abcdefg')); // Non-numeric
    }

    /**
     * Test RF formatting
     */
    public function test_format_rf() {
        $this->assertEquals('123.456-7', Utils::format_rf('1234567'));
        $this->assertEquals('123.456-7', Utils::format_rf('123.456-7'));
        $this->assertEquals('123', Utils::format_rf('123')); // Too short, returns as-is
    }

    /**
     * Test CPF masking for privacy
     */
    public function test_mask_cpf() {
        $this->assertEquals('123.***.***-09', Utils::mask_cpf('12345678909'));
        $this->assertEquals('123.***.***-09', Utils::mask_cpf('123.456.789-09'));
    }

    /**
     * Test RF masking for privacy
     */
    public function test_mask_rf() {
        $this->assertEquals('123.***-7', Utils::mask_cpf('1234567'));
        $this->assertEquals('123.***-7', Utils::mask_cpf('123.456-7'));
    }

    /**
     * Test masking with invalid input
     */
    public function test_mask_cpf_with_invalid_input() {
        $this->assertEquals('', Utils::mask_cpf(''));
        $this->assertEquals('123', Utils::mask_cpf('123')); // Too short
    }

    /**
     * Test get_submissions_table
     *
     * Note: This test requires WordPress $wpdb mock
     */
    public function test_get_submissions_table() {
        global $wpdb;

        // Mock $wpdb
        $wpdb = new \stdClass();
        $wpdb->prefix = 'wp_';

        $this->assertEquals('wp_ffc_submissions', Utils::get_submissions_table());
    }

    /**
     * Test get_allowed_html_tags returns array
     */
    public function test_get_allowed_html_tags_returns_array() {
        $allowed_tags = Utils::get_allowed_html_tags();

        $this->assertIsArray($allowed_tags);
        $this->assertNotEmpty($allowed_tags);
        $this->assertArrayHasKey('p', $allowed_tags);
        $this->assertArrayHasKey('strong', $allowed_tags);
        $this->assertArrayHasKey('img', $allowed_tags);
    }

    /**
     * Test get_allowed_html_tags structure
     */
    public function test_get_allowed_html_tags_structure() {
        $allowed_tags = Utils::get_allowed_html_tags();

        // Check that 'p' tag has 'style' attribute allowed
        $this->assertArrayHasKey('style', $allowed_tags['p']);

        // Check that 'img' tag has required attributes
        $this->assertArrayHasKey('src', $allowed_tags['img']);
        $this->assertArrayHasKey('alt', $allowed_tags['img']);
    }

    /**
     * Test recursive sanitization with array
     */
    public function test_recursive_sanitize_with_array() {
        $input = [
            'name' => '<script>alert("xss")</script>John',
            'email' => 'test@example.com',
            'nested' => [
                'field' => '<b>bold</b>text'
            ]
        ];

        $sanitized = Utils::recursive_sanitize($input);

        $this->assertIsArray($sanitized);
        $this->assertStringNotContainsString('<script>', $sanitized['name']);
        $this->assertEquals('test@example.com', $sanitized['email']);
        $this->assertIsArray($sanitized['nested']);
    }

    /**
     * Test recursive sanitization with string
     */
    public function test_recursive_sanitize_with_string() {
        $input = '<script>alert("xss")</script>Hello World';
        $sanitized = Utils::recursive_sanitize($input);

        $this->assertIsString($sanitized);
        $this->assertStringNotContainsString('<script>', $sanitized);
    }
}
