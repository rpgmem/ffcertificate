<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Core\Encryption;
use FreeFormCertificate\Core\SensitiveFieldRegistry;
use FreeFormCertificate\Frontend\ReprintDetector;
use FreeFormCertificate\Tests\Support\Identifiers;

/**
 * Tests for ReprintDetector: reprint detection by ticket (hash + JSON fallback),
 * by CPF/RF (hash-based with split columns + JSON fallback), and result building.
 *
 * Encryption is the real class, configured by the test bootstrap's keys: a
 * lookup is checked by the hash it sent to the query, and an e-mail by the
 * ciphertext the real cipher wrote (#2117). The one test that needs the
 * cipher NOT configured runs in its own process with an alias mock.
 *
 * @covers \FreeFormCertificate\Frontend\ReprintDetector
 */
class ReprintDetectorTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/**
	 * A CPF in its `ddd.ddd.ddd-dd` display form, built from the standard fixture.
	 *
	 * @param string $cpf Eleven digits.
	 * @return string
	 */
	private static function mask_cpf( string $cpf ): string {
		return substr( $cpf, 0, 3 ) . '.' . substr( $cpf, 3, 3 ) . '.' . substr( $cpf, 6, 3 ) . '-' . substr( $cpf, 9, 2 );
	}

	/** @var Mockery\MockInterface */
	private $wpdb;

	/**
	 * The arguments of every prepared query, in order.
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $prepared = array();

	/**
	 * The text of every prepared query, in order.
	 *
	 * @var array<int, string>
	 */
	private array $queries = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		global $wpdb;
		$wpdb = Mockery::mock('wpdb');
		$wpdb->prefix = 'wp_';
		$this->wpdb = $wpdb;

		Functions\when('__')->returnArg();
		Functions\when('wp_unslash')->alias(function ($val) {
			return is_string($val) ? stripslashes($val) : $val;
		});

		// Default wpdb stubs
		$this->prepared = array();
		$this->queries  = array();
		$this->wpdb->shouldReceive('prepare')->andReturnUsing(function ($query, ...$args) {
			$this->prepared[] = $args;
			$this->queries[]  = $query;
			return 'SQL';
		})->byDefault();
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
		// 0, not '': the reprint branch returns unix seconds here, so the old
		// empty string made the key's type depend on the branch (#1060). Only
		// the persister reads it, and only when is_reprint is true.
		$this->assertSame(0, $result['date']);
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
		$this->assertIsInt($result['date']);
	}

	// ==================================================================
	// detect by ticket — hash lookup finds match
	// ==================================================================

	public function test_detect_by_ticket_using_hash_when_encryption_configured(): void {
		// `submission_date` is unix UTC int since 6.6.0 (#249 sub-escopo a).
		$row = $this->makeSubmissionRow([
			'id' => 99,
			'data' => '{"name":"John","auth_code":"XXXX"}',
			'auth_code' => 'CODE123',
			'submission_date' => 1750000000,
		]);

		$this->wpdb->shouldReceive('get_row')->once()->andReturn($row);

		$result = ReprintDetector::detect(1, '', 'abc123');

		$this->assertSame(
			array( 'wp_ffc_submissions', 1, SensitiveFieldRegistry::hash_identifier( 'ticket', 'abc123' ) ),
			$this->prepared[0],
			'the lookup goes by the ticket hash'
		);
		$this->assertTrue($result['is_reprint']);
		$this->assertSame(99, $result['id']);
		$this->assertSame(1750000000, $result['date']);
		$this->assertSame('John', $result['data']['name']);
	}

	// ==================================================================
	// detect by ticket — an encrypted install never scans `data`
	// ==================================================================

	public function test_detect_by_ticket_reads_only_the_hash_when_encryption_is_configured(): void {
		// With a key every ticket is stored as its hash, so a hash miss means
		// no certificate: the LIKE over `data` is for installs without one
		// (#2241), and with encryption `data` holds nothing to match.
		$this->wpdb->shouldReceive('get_row')->once()->andReturn(null);

		$result = ReprintDetector::detect(1, '', 'ABC-123');

		$this->assertFalse($result['is_reprint']);
		$this->assertCount(1, $this->queries);
		$this->assertStringNotContainsString('LIKE', $this->queries[0]);
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

	/**
	 * Whether the cipher is configured is fixed by the wp-config constants,
	 * which the bootstrap defines for the whole process; only an alias mock
	 * can answer "not configured", so this test has a process of its own.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_detect_by_ticket_uses_json_only_when_encryption_not_configured(): void {
		$encryption = Mockery::mock('alias:\FreeFormCertificate\Core\Encryption');
		$encryption->shouldReceive('is_configured')->andReturn(false);
		$encryption->shouldReceive('hash')->never();

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
		$row = $this->makeSubmissionRow([
			'id' => 77,
			'data' => '{"cpf_rf":"' . self::mask_cpf( Identifiers::CPF_A ) . '","name":"Pedro"}',
			'auth_code' => 'AUTH77',
		]);

		$this->wpdb->shouldReceive('get_row')->once()->andReturn($row);

		$result = ReprintDetector::detect(1, self::mask_cpf( Identifiers::CPF_A ), '');

		$this->assertSame(
			array( 'wp_ffc_submissions', 1, SensitiveFieldRegistry::hash_identifier( 'cpf', Identifiers::CPF_A ) ),
			$this->prepared[0]
		);
		$this->assertStringContainsString( 'AND cpf_hash = %s', $this->queries[0] );
		$this->assertTrue($result['is_reprint']);
		$this->assertSame(77, $result['id']);
	}

	// ==================================================================
	// detect by CPF — hash lookup (7 digits = rf_hash column)
	// ==================================================================

	public function test_detect_by_cpf_uses_rf_hash_column_for_7_digit_ids(): void {
		$row = $this->makeSubmissionRow([
			'id' => 88,
			'data' => '{"name":"Ana"}',
			'auth_code' => 'AUTH88',
		]);

		$this->wpdb->shouldReceive('get_row')->once()->andReturn($row);

		$result = ReprintDetector::detect(1, Identifiers::RF_A, '');

		$this->assertSame(
			array( 'wp_ffc_submissions', 1, SensitiveFieldRegistry::hash_identifier( 'cpf', Identifiers::RF_A ) ),
			$this->prepared[0]
		);
		$this->assertStringContainsString( 'AND rf_hash = %s', $this->queries[0] );
		$this->assertTrue($result['is_reprint']);
		$this->assertSame(88, $result['id']);
	}

	// ==================================================================
	// detect by CPF — hash miss, JSON fallback
	// ==================================================================

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
		$row = $this->makeSubmissionRow(['id' => 77]);
		$this->wpdb->shouldReceive('get_row')->andReturn($row);

		$result = ReprintDetector::detect(1, self::mask_cpf( Identifiers::CPF_A ), '');

		$this->assertCount( 1, $this->prepared );
		$this->assertSame( Encryption::hash( Identifiers::CPF_A ), $this->prepared[0][2], 'the digits are hashed, not the formatted value' );
		$this->assertTrue($result['is_reprint']);
	}

	// ==================================================================
	// detect — ticket takes priority over CPF
	// ==================================================================

	public function test_detect_ticket_takes_priority_over_cpf(): void {
		$row = $this->makeSubmissionRow([
			'id' => 66,
			'data' => '{"ticket":"T100"}',
		]);

		$this->wpdb->shouldReceive('get_row')
			->once() // only ticket query, CPF not attempted
			->andReturn($row);

		$result = ReprintDetector::detect(1, self::mask_cpf( Identifiers::CPF_A ), 'T100');

		$this->assertTrue($result['is_reprint']);
		$this->assertSame(66, $result['id']);
	}

	// ==================================================================
	// row_for / for_context — one lookup per submission (#2241)
	// ==================================================================

	public function test_a_submission_looks_its_row_up_once(): void {
		$row = $this->makeSubmissionRow(['id' => 31]);
		$this->wpdb->shouldReceive('get_row')->once()->andReturn($row);

		$ctx           = new \FreeFormCertificate\Frontend\Submission\SubmissionContext();
		$ctx->form_id  = 4;
		$ctx->val_cpf  = Identifiers::CPF_A;

		$first  = ReprintDetector::for_context($ctx);
		$second = ReprintDetector::for_context($ctx);

		$this->assertTrue($first['is_reprint']);
		$this->assertSame($first, $second);
		$this->assertSame($row, ReprintDetector::row_for($ctx), 'Every stage reads the row the first one fetched.');
	}

	public function test_a_lookup_that_found_nothing_is_not_repeated(): void {
		$this->wpdb->shouldReceive('get_row')->once()->andReturn(null);

		$ctx          = new \FreeFormCertificate\Frontend\Submission\SubmissionContext();
		$ctx->form_id = 4;
		$ctx->val_cpf = Identifiers::CPF_A;

		$this->assertNull(ReprintDetector::row_for($ctx));
		$this->assertFalse(ReprintDetector::for_context($ctx)['is_reprint']);
	}

	// ==================================================================
	// build_reprint_result — auth_code from column
	// ==================================================================

	public function test_reprint_result_includes_auth_code_from_column_when_missing_in_json(): void {
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
		$row = $this->makeSubmissionRow([
			'id' => 22,
			'data' => '{"name":"Test"}',
			'email_encrypted' => Encryption::encrypt( 'user@example.com' ),
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
