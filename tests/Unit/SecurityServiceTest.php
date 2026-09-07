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

	/**
	 * In-memory stand-in for the transient store.
	 *
	 * @var array<string, mixed>
	 */
	private array $transients = [];

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// CaptchaProvider::resolve() reads ffc_settings.
		Functions\when( 'get_option' )->justReturn( array() );

		// Stub wp_rand to use PHP's random_int
		Functions\when('wp_rand')->alias(function (int $min, int $max): int {
			return random_int($min, $max);
		});

		// Deterministic signing key. Challenges are HMAC'd with a key derived
		// from wp_salt() since 6.23.0, so this is what makes them reproducible.
		Functions\when('wp_salt')->alias(function (string $scheme = 'auth'): string {
			return 'test-salt-' . $scheme;
		});

		// In-memory redemption ledger. Challenges are single-use, so the
		// transient store has to behave like one for a round trip to pass.
		$this->transients = [];
		Functions\when('get_transient')->alias(function (string $key) {
			return $this->transients[$key] ?? false;
		});
		Functions\when('set_transient')->alias(function (string $key, $value): bool {
			$this->transients[$key] = $value;
			return true;
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

	public function test_generate_simple_captcha_answer_is_non_negative_and_bounded(): void {
		for ($i = 0; $i < 100; $i++) {
			$result = SecurityService::generate_simple_captcha();

			$this->assertIsInt($result['answer']);
			$this->assertGreaterThanOrEqual(0, $result['answer'], 'Answer must never be negative');
			$this->assertLessThanOrEqual(45, $result['answer'], 'Max multiplication: 9×5=45');
		}
	}

	public function test_generate_simple_captcha_token_is_expiry_plus_signature(): void {
		$result = SecurityService::generate_simple_captcha();

		$this->assertMatchesRegularExpression('/^\d+\.[0-9a-f]{16}\.[0-9a-f]{64}$/', $result['hash']);

		[$expires] = explode('.', $result['hash']);
		$this->assertGreaterThan(time(), (int) $expires);
		$this->assertLessThanOrEqual(time() + SecurityService::CHALLENGE_TTL, (int) $expires);
	}

	public function test_generate_simple_captcha_token_is_not_derived_from_the_answer_alone(): void {
		// The pre-6.23.0 token was wp_hash($answer . $fixed_salt): same answer,
		// same token, forever. That is what made one captured pair authenticate
		// every later submission.
		$by_answer = [];
		for ($i = 0; $i < 60; $i++) {
			$captcha = SecurityService::generate_simple_captcha();

			$this->assertNotSame(
				hash('sha256', $captcha['answer'] . 'ffc_math_salt'),
				$captcha['hash'],
				'token must not be the legacy answer-only digest'
			);

			$by_answer[$captcha['answer']][] = $captcha['hash'];
		}

		$repeats = array_filter($by_answer, static fn (array $h): bool => count($h) > 1);
		$this->assertNotEmpty($repeats, 'expected some answer to recur across 60 draws');

		foreach ($repeats as $answer => $hashes) {
			$this->assertSame(
				count($hashes),
				count(array_unique($hashes)),
				"answer {$answer} produced a duplicate token; two visitors would share one challenge"
			);
		}
	}

	public function test_generate_simple_captcha_label_contains_valid_operands_and_operator(): void {
		$digit    = '[0-9]';
		$word     = '(?:one|two|three|four|five|six|seven|eight|nine)';
		$operand  = "(?:$digit|$word)";
		$operator = '(?:\+|-|×|plus|minus|times)';
		$pattern  = "/$operand\s+$operator\s+$operand/i";

		for ($i = 0; $i < 100; $i++) {
			$result = SecurityService::generate_simple_captcha();
			$this->assertMatchesRegularExpression(
				$pattern,
				$result['label'],
				"Label should match pattern: '$result[label]'"
			);
		}
	}

	public function test_generate_simple_captcha_uses_all_three_operators(): void {
		$has_add = false;
		$has_sub = false;
		$has_mul = false;

		for ($i = 0; $i < 300; $i++) {
			$label = SecurityService::generate_simple_captcha()['label'];
			if (strpos($label, '+') !== false || stripos($label, 'plus') !== false) {
				$has_add = true;
			}
			if (strpos($label, '-') !== false || stripos($label, 'minus') !== false) {
				$has_sub = true;
			}
			if (strpos($label, '×') !== false || stripos($label, 'times') !== false) {
				$has_mul = true;
			}
			if ($has_add && $has_sub && $has_mul) {
				break;
			}
		}

		$this->assertTrue($has_add, 'Addition should appear in 300 iterations');
		$this->assertTrue($has_sub, 'Subtraction should appear in 300 iterations');
		$this->assertTrue($has_mul, 'Multiplication should appear in 300 iterations');
	}

	public function test_generate_simple_captcha_mixes_digits_words_and_operator_words(): void {
		$has_digit    = false;
		$has_word     = false;
		$has_op_word  = false;
		$number_words = array('one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine');
		$op_words     = array('plus', 'minus', 'times');

		for ($i = 0; $i < 300; $i++) {
			$label = SecurityService::generate_simple_captcha()['label'];
			if (preg_match('/\d/', $label)) {
				$has_digit = true;
			}
			foreach ($number_words as $w) {
				if (stripos($label, $w) !== false) {
					$has_word = true;
					break;
				}
			}
			foreach ($op_words as $w) {
				if (stripos($label, $w) !== false) {
					$has_op_word = true;
					break;
				}
			}
			if ($has_digit && $has_word && $has_op_word) {
				break;
			}
		}

		$this->assertTrue($has_digit, 'At least one captcha should use a digit operand');
		$this->assertTrue($has_word, 'At least one captcha should use a word operand');
		$this->assertTrue($has_op_word, 'At least one captcha should use an operator word');
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
