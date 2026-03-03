<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Frontend\ReprintDetector;

/**
 * Tests for ReprintDetector: reprint detection by ticket (hash + JSON fallback),
 * by CPF/RF (hash-based with split columns + JSON fallback), and result building.
 *
 * Alias mocks for Utils and Encryption are created in setUp to prevent
 * autoloading of the real classes.
 *
 * @covers \FreeFormCertificate\Frontend\ReprintDetector
 */
class ReprintDetectorTest extends TestCase {

    use MockeryPHPUnitIntegration;

    /** @var Mockery\MockInterface */
    private $wpdb;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        global $wpdb;
        $wpdb = Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $this->wpdb = $wpdb;

        // Alias mocks: prevent autoloading
        $utilsMock = Mockery::mock('alias:\FreeFormCertificate\Core\Utils');
        $utilsMock->shouldReceive('get_submissions_table')
            ->andReturn('wp_ffc_submissions')->byDefault();
        $utilsMock->shouldReceive('debug_log')->byDefault();

        $encMock = Mockery::mock('alias:\FreeFormCertificate\Core\Encryption');
        $encMock->shouldReceive('is_configured')->andReturn(true)->byDefault();
        $encMock->shouldReceive('hash')->andReturn('default_hash')->byDefault();
        $encMock->shouldReceive('decrypt')->andReturn(null)->byDefault();

        Functions\when('__')->returnArg();
        Functions\when('wp_unslash')->alias(function ($val) {
            return is_string($val) ? stripslashes($val) : $val;
        });

        // Default wpdb stubs
        $this->wpdb->shouldReceive('prepare')->andReturn('SQL')->byDefault();
        $this->wpdb->shouldReceive('esc_like')->andReturnUsing(function ($val) {
            return $val;
        })->byDefault();
        $this->wpdb->shouldReceive('get_row')->andReturn(null)->byDefault();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function makeSubmissionRow(array $overrides = []): object {
        return (object) array_merge([
            'id' => 1,
            'data' => '{}',
            'auth_code' => '',
            'email_encrypted' => null,
            'submission_date' => '2025-01-01 00:00:00',
        ], $overrides);
    }

    // ==================================================================
    // detect — empty inputs
    // ==================================================================

    public function test_detect_returns_not_reprint_when_both_inputs_empty(): void {
        $result = ReprintDetector::detect(1, '', '');

        $this->assertFalse($result['is_reprint']);
        $this->assertSame([], $result['data']);
        $this->assertSame(0, $result['id']);
        $this->assertSame('', $result['email']);
        $this->assertSame('', $result['date']);
    }

    public function test_not_reprint_result_has_complete_structure(): void {
        $result = ReprintDetector::detect(1, '', '');

        $this->assertArrayHasKey('is_reprint', $result);
        $this->assertArrayHasKey('data', $result);
        $this->assertArrayHasKey('id', $result);
        $this->assertArrayHasKey('email', $result);
        $this->assertArrayHasKey('date', $result);
        $this->assertIsBool($result['is_reprint']);
        $this->assertIsArray($result['data']);
        $this->assertIsInt($result['id']);
        $this->assertIsString($result['email']);
        $this->assertIsString($result['date']);
    }

    // ==================================================================
    // detect by ticket — hash lookup finds match
    // ==================================================================

    public function test_detect_by_ticket_using_hash_when_encryption_configured(): void {
        $encMock = Mockery::mock('alias:\FreeFormCertificate\Core\Encryption');
        $encMock->shouldReceive('is_configured')->andReturn(true);
        $encMock->shouldReceive('hash')
            ->with('ABC123')
            ->andReturn('hashed_ticket');
        $encMock->shouldReceive('decrypt')->andReturn(null);

        $row = $this->makeSubmissionRow([
            'id' => 99,
            'data' => '{"name":"John","auth_code":"XXXX"}',
            'auth_code' => 'CODE123',
            'submission_date' => '2025-06-15 10:00:00',
        ]);

        $this->wpdb->shouldReceive('get_row')->once()->andReturn($row);

        $result = ReprintDetector::detect(1, '', 'abc123');

        $this->assertTrue($result['is_reprint']);
        $this->assertSame(99, $result['id']);
        $this->assertSame('2025-06-15 10:00:00', $result['date']);
        $this->assertSame('John', $result['data']['name']);
    }

    // ==================================================================
    // detect by ticket — hash miss, JSON fallback
    // ==================================================================

    public function test_detect_by_ticket_falls_back_to_json_when_hash_misses(): void {
        $encMock = Mockery::mock('alias:\FreeFormCertificate\Core\Encryption');
        $encMock->shouldReceive('is_configured')->andReturn(true);
        $encMock->shouldReceive('hash')->andReturn('hashed_ticket');
        $encMock->shouldReceive('decrypt')->andReturn(null);

        $row = $this->makeSubmissionRow([
            'id' => 55,
            'data' => '{"ticket":"ABC-123","name":"Maria"}',
            'submission_date' => '2025-03-10 08:30:00',
        ]);

        // Hash lookup returns null, JSON LIKE returns row
        $this->wpdb->shouldReceive('get_row')
            ->twice()
            ->andReturn(null, $row);

        $result = ReprintDetector::detect(1, '', 'ABC-123');

        $this->assertTrue($result['is_reprint']);
        $this->assertSame(55, $result['id']);
        $this->assertSame('Maria', $result['data']['name']);
    }

    // ==================================================================
    // detect by ticket — no match (hash + JSON both miss)
    // ==================================================================

    public function test_detect_by_ticket_returns_not_reprint_when_no_match(): void {
        $result = ReprintDetector::detect(1, '', 'NONEXISTENT');

        $this->assertFalse($result['is_reprint']);
    }

    // ==================================================================
    // detect by ticket — encryption not configured, JSON only
    // ==================================================================

    public function test_detect_by_ticket_uses_json_only_when_encryption_not_configured(): void {
        $encMock = Mockery::mock('alias:\FreeFormCertificate\Core\Encryption');
        $encMock->shouldReceive('is_configured')->andReturn(false);
        $encMock->shouldReceive('decrypt')->andReturn(null);

        $row = $this->makeSubmissionRow([
            'id' => 10,
            'data' => '{"ticket":"T001","name":"Carlos"}',
        ]);

        $this->wpdb->shouldReceive('get_row')->once()->andReturn($row);

        $result = ReprintDetector::detect(1, '', 'T001');

        $this->assertTrue($result['is_reprint']);
        $this->assertSame(10, $result['id']);
        $this->assertSame('Carlos', $result['data']['name']);
    }

    // ==================================================================
    // detect by CPF — hash lookup (11 digits = cpf_hash column)
    // ==================================================================

    public function test_detect_by_cpf_uses_cpf_hash_column_for_11_digit_ids(): void {
        $encMock = Mockery::mock('alias:\FreeFormCertificate\Core\Encryption');
        $encMock->shouldReceive('is_configured')->andReturn(true);
        $encMock->shouldReceive('hash')
            ->with('12345678901')
            ->andReturn('cpf_hashed');
        $encMock->shouldReceive('decrypt')->andReturn(null);

        $row = $this->makeSubmissionRow([
            'id' => 77,
            'data' => '{"cpf_rf":"123.456.789-01","name":"Pedro"}',
            'auth_code' => 'AUTH77',
        ]);

        $this->wpdb->shouldReceive('get_row')->once()->andReturn($row);

        $result = ReprintDetector::detect(1, '123.456.789-01', '');

        $this->assertTrue($result['is_reprint']);
        $this->assertSame(77, $result['id']);
    }

    // ==================================================================
    // detect by CPF — hash lookup (7 digits = rf_hash column)
    // ==================================================================

    public function test_detect_by_cpf_uses_rf_hash_column_for_7_digit_ids(): void {
        $encMock = Mockery::mock('alias:\FreeFormCertificate\Core\Encryption');
        $encMock->shouldReceive('is_configured')->andReturn(true);
        $encMock->shouldReceive('hash')
            ->with('1234567')
            ->andReturn('rf_hashed');
        $encMock->shouldReceive('decrypt')->andReturn(null);

        $row = $this->makeSubmissionRow([
            'id' => 88,
            'data' => '{"name":"Ana"}',
            'auth_code' => 'AUTH88',
        ]);

        $this->wpdb->shouldReceive('get_row')->once()->andReturn($row);

        $result = ReprintDetector::detect(1, '1234567', '');

        $this->assertTrue($result['is_reprint']);
        $this->assertSame(88, $result['id']);
    }

    // ==================================================================
    // detect by CPF — hash miss, JSON fallback
    // ==================================================================

    public function test_detect_by_cpf_falls_back_to_json_when_hash_misses(): void {
        $encMock = Mockery::mock('alias:\FreeFormCertificate\Core\Encryption');
        $encMock->shouldReceive('is_configured')->andReturn(true);
        $encMock->shouldReceive('hash')->andReturn('hash');
        $encMock->shouldReceive('decrypt')->andReturn(null);

        $row = $this->makeSubmissionRow([
            'id' => 33,
            'data' => '{"cpf_rf":"123.456.789-01","name":"Jose"}',
        ]);

        // Hash query misses, JSON fallback finds it
        $this->wpdb->shouldReceive('get_row')
            ->twice()
            ->andReturn(null, $row);

        $result = ReprintDetector::detect(1, '123.456.789-01', '');

        $this->assertTrue($result['is_reprint']);
        $this->assertSame(33, $result['id']);
    }

    // ==================================================================
    // detect by CPF — no match
    // ==================================================================

    public function test_detect_by_cpf_returns_not_reprint_when_no_match(): void {
        $result = ReprintDetector::detect(1, '000.000.000-00', '');

        $this->assertFalse($result['is_reprint']);
        $this->assertSame(0, $result['id']);
    }

    // ==================================================================
    // detect — CPF formatting stripped for hash
    // ==================================================================

    public function test_detect_strips_cpf_formatting_before_hash(): void {
        $encMock = Mockery::mock('alias:\FreeFormCertificate\Core\Encryption');
        $encMock->shouldReceive('is_configured')->andReturn(true);
        $encMock->shouldReceive('hash')
            ->with('12345678901')
            ->once()
            ->andReturn('clean_hash');
        $encMock->shouldReceive('decrypt')->andReturn(null);

        $row = $this->makeSubmissionRow(['id' => 77]);
        $this->wpdb->shouldReceive('get_row')->andReturn($row);

        $result = ReprintDetector::detect(1, '123.456.789-01', '');

        $this->assertTrue($result['is_reprint']);
    }

    // ==================================================================
    // detect — ticket takes priority over CPF
    // ==================================================================

    public function test_detect_ticket_takes_priority_over_cpf(): void {
        $encMock = Mockery::mock('alias:\FreeFormCertificate\Core\Encryption');
        $encMock->shouldReceive('is_configured')->andReturn(true);
        $encMock->shouldReceive('hash')->andReturn('ticket_hash');
        $encMock->shouldReceive('decrypt')->andReturn(null);

        $row = $this->makeSubmissionRow([
            'id' => 66,
            'data' => '{"ticket":"T100"}',
        ]);

        $this->wpdb->shouldReceive('get_row')
            ->once() // only ticket query, CPF not attempted
            ->andReturn($row);

        $result = ReprintDetector::detect(1, '123.456.789-01', 'T100');

        $this->assertTrue($result['is_reprint']);
        $this->assertSame(66, $result['id']);
    }

    // ==================================================================
    // build_reprint_result — auth_code from column
    // ==================================================================

    public function test_reprint_result_includes_auth_code_from_column_when_missing_in_json(): void {
        $encMock = Mockery::mock('alias:\FreeFormCertificate\Core\Encryption');
        $encMock->shouldReceive('is_configured')->andReturn(false);
        $encMock->shouldReceive('decrypt')->andReturn(null);

        $row = $this->makeSubmissionRow([
            'id' => 11,
            'data' => '{"name":"Test"}',
            'auth_code' => 'MYAUTHCODE12',
        ]);
        $this->wpdb->shouldReceive('get_row')->andReturn($row);

        $result = ReprintDetector::detect(1, '', 'TICKET1');

        $this->assertSame('MYAUTHCODE12', $result['data']['auth_code']);
    }

    // ==================================================================
    // build_reprint_result — decrypted email
    // ==================================================================

    public function test_reprint_result_includes_decrypted_email(): void {
        $encMock = Mockery::mock('alias:\FreeFormCertificate\Core\Encryption');
        $encMock->shouldReceive('is_configured')->andReturn(false);
        $encMock->shouldReceive('decrypt')
            ->with('encrypted_email_data')
            ->andReturn('user@example.com');

        $row = $this->makeSubmissionRow([
            'id' => 22,
            'data' => '{"name":"Test"}',
            'email_encrypted' => 'encrypted_email_data',
        ]);
        $this->wpdb->shouldReceive('get_row')->andReturn($row);

        $result = ReprintDetector::detect(1, '', 'TICKET2');

        $this->assertSame('user@example.com', $result['email']);
        $this->assertSame('user@example.com', $result['data']['email']);
    }

    // ==================================================================
    // build_reprint_result — null data handled
    // ==================================================================

    public function test_reprint_result_handles_null_data_field(): void {
        $encMock = Mockery::mock('alias:\FreeFormCertificate\Core\Encryption');
        $encMock->shouldReceive('is_configured')->andReturn(false);
        $encMock->shouldReceive('decrypt')->andReturn(null);

        $row = $this->makeSubmissionRow([
            'id' => 33,
            'data' => null,
            'auth_code' => 'CODE33',
        ]);
        $this->wpdb->shouldReceive('get_row')->andReturn($row);

        $result = ReprintDetector::detect(1, '', 'TICKET3');

        $this->assertTrue($result['is_reprint']);
        $this->assertIsArray($result['data']);
        $this->assertSame('CODE33', $result['data']['auth_code']);
    }

    // ==================================================================
    // build_reprint_result — empty string data
    // ==================================================================

    public function test_reprint_result_handles_empty_string_data(): void {
        $encMock = Mockery::mock('alias:\FreeFormCertificate\Core\Encryption');
        $encMock->shouldReceive('is_configured')->andReturn(false);
        $encMock->shouldReceive('decrypt')->andReturn(null);

        $row = $this->makeSubmissionRow([
            'id' => 44,
            'data' => '',
            'auth_code' => '',
        ]);
        $this->wpdb->shouldReceive('get_row')->andReturn($row);

        $result = ReprintDetector::detect(1, '', 'TICKET4');

        $this->assertTrue($result['is_reprint']);
        $this->assertSame([], $result['data']);
    }

    // ==================================================================
    // build_reprint_result — slashed JSON via wp_unslash
    // ==================================================================

    public function test_reprint_result_handles_slashed_json_via_wp_unslash(): void {
        $encMock = Mockery::mock('alias:\FreeFormCertificate\Core\Encryption');
        $encMock->shouldReceive('is_configured')->andReturn(false);
        $encMock->shouldReceive('decrypt')->andReturn(null);

        $slashed_json = '{"name":"John\\"s"}';
        $row = $this->makeSubmissionRow([
            'id' => 55,
            'data' => $slashed_json,
        ]);
        $this->wpdb->shouldReceive('get_row')->andReturn($row);

        $result = ReprintDetector::detect(1, '', 'TICKET5');

        $this->assertTrue($result['is_reprint']);
        $this->assertSame(55, $result['id']);
    }
}
