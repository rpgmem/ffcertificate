<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Core\SecurityService;

/**
 * Tests for SecurityService: captcha generation/verification and
 * honeypot-based security field validation.
 *
 * @covers \FreeFormCertificate\Core\SecurityService
 * @runClassInSeparateProcess
 * @preserveGlobalState disabled
 */
class SecurityServiceTest extends TestCase {

    use MockeryPHPUnitIntegration;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        // Stub wp_rand to use PHP's random_int
        Functions\when('wp_rand')->alias(function (int $min, int $max): int {
            return random_int($min, $max);
        });

        // Stub wp_hash to use a deterministic sha256 hash
        Functions\when('wp_hash')->alias(function ($data) {
            return hash('sha256', $data);
        });

        // Stub translation functions to return the first argument
        Functions\when('esc_html__')->returnArg();
        Functions\when('__')->returnArg();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    // ==================================================================
    // generate_simple_captcha
    // ==================================================================

    public function test_generate_simple_captcha_returns_array_with_expected_keys(): void {
        $result = SecurityService::generate_simple_captcha();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('label', $result);
        $this->assertArrayHasKey('hash', $result);
        $this->assertArrayHasKey('answer', $result);
    }

    public function test_generate_simple_captcha_answer_is_sum_between_2_and_18(): void {
        // Run multiple times to increase confidence in the range
        for ($i = 0; $i < 50; $i++) {
            $result = SecurityService::generate_simple_captcha();

            $this->assertIsInt($result['answer']);
            $this->assertGreaterThanOrEqual(2, $result['answer']);
            $this->assertLessThanOrEqual(18, $result['answer']);
        }
    }

    public function test_generate_simple_captcha_hash_matches_answer_with_salt(): void {
        $result = SecurityService::generate_simple_captcha();

        $expected_hash = hash('sha256', $result['answer'] . 'ffc_math_salt');
        $this->assertSame($expected_hash, $result['hash']);
    }

    public function test_generate_simple_captcha_label_contains_valid_operands(): void {
        $digit   = '[1-9]';
        $word    = '(?:one|two|three|four|five|six|seven|eight|nine)';
        $operand = "(?:$digit|$word)";
        $pattern = "/$operand\s*\+\s*$operand/i";

        for ($i = 0; $i < 50; $i++) {
            $result = SecurityService::generate_simple_captcha();
            $this->assertMatchesRegularExpression(
                $pattern,
                $result['label'],
                'Label should contain two operands (digit or word) separated by +'
            );
        }
    }

    public function test_generate_simple_captcha_mixes_digits_and_words(): void {
        $has_digit = false;
        $has_word  = false;
        $words     = array('one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine');

        for ($i = 0; $i < 200; $i++) {
            $result = SecurityService::generate_simple_captcha();
            foreach ($words as $w) {
                if (stripos($result['label'], $w) !== false) {
                    $has_word = true;
                    break;
                }
            }
            if (preg_match('/\d\s*\+|\+\s*\d/', $result['label'])) {
                $has_digit = true;
            }
            if ($has_digit && $has_word) {
                break;
            }
        }

        $this->assertTrue($has_digit, 'At least one captcha should use a digit operand in 200 iterations');
        $this->assertTrue($has_word, 'At least one captcha should use a word operand in 200 iterations');
    }

    // ==================================================================
    // verify_simple_captcha
    // ==================================================================

    public function test_verify_simple_captcha_correct_answer_returns_true(): void {
        $captcha = SecurityService::generate_simple_captcha();

        $result = SecurityService::verify_simple_captcha(
            (string) $captcha['answer'],
            $captcha['hash']
        );

        $this->assertTrue($result);
    }

    public function test_verify_simple_captcha_wrong_answer_returns_false(): void {
        $captcha = SecurityService::generate_simple_captcha();

        // Use an answer that is guaranteed to be wrong
        $wrong_answer = (string) ($captcha['answer'] + 1);

        $result = SecurityService::verify_simple_captcha($wrong_answer, $captcha['hash']);

        $this->assertFalse($result);
    }

    public function test_verify_simple_captcha_empty_answer_returns_false(): void {
        $captcha = SecurityService::generate_simple_captcha();

        $result = SecurityService::verify_simple_captcha('', $captcha['hash']);

        $this->assertFalse($result);
    }

    public function test_verify_simple_captcha_empty_hash_returns_false(): void {
        $captcha = SecurityService::generate_simple_captcha();

        $result = SecurityService::verify_simple_captcha((string) $captcha['answer'], '');

        $this->assertFalse($result);
    }

    public function test_verify_simple_captcha_whitespace_trimmed_answer_works(): void {
        $captcha = SecurityService::generate_simple_captcha();

        $result = SecurityService::verify_simple_captcha(
            '  ' . $captcha['answer'] . '  ',
            $captcha['hash']
        );

        $this->assertTrue($result);
    }

    // ==================================================================
    // validate_security_fields
    // ==================================================================

    public function test_validate_security_fields_valid_data_returns_true(): void {
        $captcha = SecurityService::generate_simple_captcha();

        $data = [
            'ffc_honeypot_trap' => '',
            'ffc_captcha_ans'   => (string) $captcha['answer'],
            'ffc_captcha_hash'  => $captcha['hash'],
        ];

        $result = SecurityService::validate_security_fields($data);

        $this->assertTrue($result);
    }

    public function test_validate_security_fields_honeypot_filled_returns_error_string(): void {
        $captcha = SecurityService::generate_simple_captcha();

        $data = [
            'ffc_honeypot_trap' => 'bot-filled-this',
            'ffc_captcha_ans'   => (string) $captcha['answer'],
            'ffc_captcha_hash'  => $captcha['hash'],
        ];

        $result = SecurityService::validate_security_fields($data);

        $this->assertIsString($result);
        $this->assertStringContainsString('Honeypot', $result);
    }

    public function test_validate_security_fields_missing_captcha_ans_returns_error_string(): void {
        $captcha = SecurityService::generate_simple_captcha();

        $data = [
            'ffc_honeypot_trap' => '',
            'ffc_captcha_hash'  => $captcha['hash'],
        ];

        $result = SecurityService::validate_security_fields($data);

        $this->assertIsString($result);
        $this->assertStringContainsString('security question', $result);
    }

    public function test_validate_security_fields_missing_captcha_hash_returns_error_string(): void {
        $captcha = SecurityService::generate_simple_captcha();

        $data = [
            'ffc_honeypot_trap' => '',
            'ffc_captcha_ans'   => (string) $captcha['answer'],
        ];

        $result = SecurityService::validate_security_fields($data);

        $this->assertIsString($result);
        $this->assertStringContainsString('security question', $result);
    }

    public function test_validate_security_fields_wrong_captcha_answer_returns_error_string(): void {
        $captcha = SecurityService::generate_simple_captcha();

        $data = [
            'ffc_honeypot_trap' => '',
            'ffc_captcha_ans'   => (string) ($captcha['answer'] + 1),
            'ffc_captcha_hash'  => $captcha['hash'],
        ];

        $result = SecurityService::validate_security_fields($data);

        $this->assertIsString($result);
        $this->assertStringContainsString('math answer is incorrect', $result);
    }
}
