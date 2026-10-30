<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Reregistration\ReregistrationSubmissionReader;
use FreeFormCertificate\Reregistration\ReregistrationSubmissionWriter;

/**
 * Tests for the reregistration-submission repository read/write split: table
 * name, status labels, CRUD operations, approval/rejection workflows, bulk
 * operations, statistics, and audience member submission creation.
 *
 * @covers \FreeFormCertificate\Reregistration\ReregistrationSubmissionReader
 * @covers \FreeFormCertificate\Reregistration\ReregistrationSubmissionWriter
 */
class ReregistrationSubmissionRepositoryTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var Mockery\MockInterface */
	private $wpdb;

	/**
	 * The audience ids each member read asked for, in order.
	 *
	 * @var list<list<int>>
	 */
	private array $member_reads = array();

	/** @var mixed The `$wpdb` global before the test. */
	private $previous_wpdb;

	/** Whether `$wpdb` was set before the test. */
	private bool $had_wpdb = false;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->member_reads  = array();
		$this->had_wpdb      = array_key_exists('wpdb', $GLOBALS);
		$this->previous_wpdb = $GLOBALS['wpdb'] ?? null;
		// AudienceReader::get_all() orders through it; the default order passes.
		Functions\when('sanitize_sql_orderby')->returnArg();
		// Several methods this class reaches are deprecated (#2089) and emit
		// the notice; stub it so each test chooses the branch rather than
		// inheriting it from an earlier class.
		\Brain\Monkey\Functions\when( '_deprecated_function' )->justReturn( null );

		// pcov does not record lines for files first autoloaded mid-test-method,
		// so the reader/writer coverage would attribute to nothing. Preload the
		// extracted classes here so pcov attributes their lines to this test.
		class_exists( '\\FreeFormCertificate\\Reregistration\\ReregistrationSubmissionReader' );
		class_exists( '\\FreeFormCertificate\\Reregistration\\ReregistrationSubmissionWriter' );

		global $wpdb;
		$wpdb = Mockery::mock('wpdb');
		$wpdb->prefix = 'wp_';
		$wpdb->users = 'wp_users';
		$wpdb->last_error = '';
		$this->wpdb = $wpdb;

		// Stub WP cache functions.
		Functions\when('wp_cache_get')->justReturn(false);
		Functions\when('wp_cache_set')->justReturn(true);
		Functions\when('wp_cache_delete')->justReturn(true);
		Functions\when('__')->returnArg();
		Functions\when('wp_parse_args')->alias(function ($args, $defaults = array()) {
			return array_merge($defaults, $args);
		});
		Functions\when('sanitize_text_field')->alias('trim');
		Functions\when('sanitize_textarea_field')->alias('trim');
		Functions\when('wp_json_encode')->alias('json_encode');
		Functions\when('current_time')->justReturn('2026-03-01 12:00:00');
		Functions\when('get_current_user_id')->justReturn(1);
		Functions\when('absint')->alias(function ($val) {
			return abs(intval($val));
		});

		$this->wpdb->shouldReceive('prepare')->andReturnUsing(function () {
			return func_get_args()[0];
		})->byDefault();

		// Approval issues an authentication code (#2242).
		Functions\when('wp_rand')->alias(function ($min, $max) {
			return random_int($min, $max);
		});
	}

	protected function tearDown(): void {
		if ($this->had_wpdb) {
			$GLOBALS['wpdb'] = $this->previous_wpdb;
		} else {
			unset($GLOBALS['wpdb']);
		}
		Monkey\tearDown();
		parent::tearDown();
	}

	// ==================================================================
	// get_table_name()
	// ==================================================================

	public function test_get_table_name_returns_prefixed_table(): void {
		$this->assertSame(
			'wp_ffc_reregistration_submissions',
			ReregistrationSubmissionReader::get_table_name()
		);
	}

	// ==================================================================
	// STATUSES constant
	// ==================================================================

	public function test_statuses_constant_contains_all_expected_values(): void {
		$expected = array('pending', 'in_progress', 'submitted', 'approved', 'rejected', 'expired');
		$this->assertSame($expected, ReregistrationSubmissionReader::STATUSES);
	}

	// ==================================================================
	// get_status_labels() / get_status_label()
	// ==================================================================

	public function test_get_status_labels_returns_all_statuses(): void {
		$labels = ReregistrationSubmissionReader::get_status_labels();

		$this->assertArrayHasKey('pending', $labels);
		$this->assertArrayHasKey('in_progress', $labels);
		$this->assertArrayHasKey('submitted', $labels);
		$this->assertArrayHasKey('approved', $labels);
		$this->assertArrayHasKey('rejected', $labels);
		$this->assertArrayHasKey('expired', $labels);
		$this->assertCount(6, $labels);
	}

	public function test_get_status_labels_values_are_strings(): void {
		$labels = ReregistrationSubmissionReader::get_status_labels();

		foreach ($labels as $key => $label) {
			$this->assertIsString($label, "Label for '{$key}' should be a string.");
		}
	}

	public function test_get_status_label_returns_label_for_known_status(): void {
		$label = ReregistrationSubmissionReader::get_status_label('approved');
		$this->assertSame('Approved', $label);
	}

	public function test_get_status_label_returns_key_for_unknown_status(): void {
		$label = ReregistrationSubmissionReader::get_status_label('nonexistent');
		$this->assertSame('nonexistent', $label);
	}

	public function test_get_status_label_returns_submitted_with_review_note(): void {
		// The __ mock returns the first argument, so the raw English string.
		$label = ReregistrationSubmissionReader::get_status_label('submitted');
		$this->assertStringContainsString('Submitted', $label);
	}

	// ==================================================================
	// get_by_id()
	// ==================================================================

	public function test_get_by_id_returns_result_from_database(): void {
		$row = (object) array('id' => 1, 'status' => 'pending', 'user_id' => 10);

		$this->wpdb->shouldReceive('prepare')->once()->andReturn('SELECT * FROM wp_ffc_reregistration_submissions WHERE id = 1');
		$this->wpdb->shouldReceive('get_row')->once()->andReturn($row);

		$result = ReregistrationSubmissionReader::get_by_id(1);

		$this->assertSame(1, $result->id);
		$this->assertSame('pending', $result->status);
	}

	public function test_get_by_id_returns_null_when_not_found(): void {
		$this->wpdb->shouldReceive('prepare')->once()->andReturn('QUERY');
		$this->wpdb->shouldReceive('get_row')->once()->andReturn(null);

		$this->assertNull(ReregistrationSubmissionReader::get_by_id(999));
	}

	public function test_get_by_id_returns_cached_result_on_cache_hit(): void {
		$cached = (object) array('id' => 5, 'status' => 'approved');

		Functions\when('wp_cache_get')->alias(function ($key) use ($cached) {
			return $key === 'id_5' ? $cached : false;
		});

		// wpdb should NOT be called since we have a cache hit.
		$this->wpdb->shouldNotReceive('get_row');

		$result = ReregistrationSubmissionReader::get_by_id(5);

		$this->assertSame(5, $result->id);
		$this->assertSame('approved', $result->status);
	}

	public function test_get_by_id_sets_cache_on_miss(): void {
		$row = (object) array('id' => 3, 'status' => 'submitted');

		$this->wpdb->shouldReceive('prepare')->once()->andReturn('QUERY');
		$this->wpdb->shouldReceive('get_row')->once()->andReturn($row);

		// Verify that cache_set is called via wp_cache_set.
		// The default justReturn(true) already handles this, but the call should happen.
		$result = ReregistrationSubmissionReader::get_by_id(3);

		$this->assertSame(3, $result->id);
	}

	// ==================================================================
	// get_by_auth_code()
	// ==================================================================

	public function test_get_by_auth_code_returns_null_for_empty_string(): void {
		$this->wpdb->shouldNotReceive('prepare');
		$this->wpdb->shouldNotReceive('get_row');

		$this->assertNull(ReregistrationSubmissionReader::get_by_auth_code(''));
	}

	public function test_get_by_auth_code_queries_database_for_valid_code(): void {
		$row = (object) array('id' => 10, 'auth_code' => 'ABCD1234', 'status' => 'submitted');

		$this->wpdb->shouldReceive('prepare')->once()->andReturn('QUERY');
		$this->wpdb->shouldReceive('get_row')->once()->andReturn($row);

		$result = ReregistrationSubmissionReader::get_by_auth_code('ABCD1234');

		$this->assertSame(10, $result->id);
		$this->assertSame('ABCD1234', $result->auth_code);
	}

	public function test_get_by_auth_code_returns_null_when_not_found(): void {
		$this->wpdb->shouldReceive('prepare')->once()->andReturn('QUERY');
		$this->wpdb->shouldReceive('get_row')->once()->andReturn(null);

		$this->assertNull(ReregistrationSubmissionReader::get_by_auth_code('NONEXIST'));
	}

	public function test_get_by_auth_code_resolves_only_an_approved_record(): void {
		// #2242: a code verifies only an APPROVED record. A row returned to
		// draft keeps its code stored, and a submitted one has none yet, so
		// neither may answer to it.
		$captured_sql = '';
		$this->wpdb->shouldReceive('prepare')
			->once()
			->andReturnUsing(function ($sql) use (&$captured_sql) {
				$captured_sql = $sql;
				return 'QUERY';
			});
		$this->wpdb->shouldReceive('get_row')->once()->andReturn(null);

		ReregistrationSubmissionReader::get_by_auth_code('ANYCODE');

		$this->assertStringContainsString("status = 'approved'", $captured_sql);
		$this->assertStringNotContainsString("'submitted'", $captured_sql);
		$this->assertStringNotContainsString("'expired'", $captured_sql);
	}

	// ==================================================================
	// get_by_magic_token()
	// ==================================================================

	public function test_get_by_magic_token_returns_null_for_empty_string(): void {
		$this->wpdb->shouldNotReceive('prepare');
		$this->wpdb->shouldNotReceive('get_row');

		$this->assertNull(ReregistrationSubmissionReader::get_by_magic_token(''));
	}

	public function test_get_by_magic_token_queries_database_for_valid_token(): void {
		$token = str_repeat('ab', 32); // 64 hex chars
		$row = (object) array('id' => 15, 'magic_token' => $token, 'status' => 'approved');

		$this->wpdb->shouldReceive('prepare')->once()->andReturn('QUERY');
		$this->wpdb->shouldReceive('get_row')->once()->andReturn($row);

		$result = ReregistrationSubmissionReader::get_by_magic_token($token);

		$this->assertSame(15, $result->id);
		$this->assertSame($token, $result->magic_token);
	}

	public function test_get_by_magic_token_returns_null_when_not_found(): void {
		$this->wpdb->shouldReceive('prepare')->once()->andReturn('QUERY');
		$this->wpdb->shouldReceive('get_row')->once()->andReturn(null);

		$this->assertNull(ReregistrationSubmissionReader::get_by_magic_token('deadbeef'));
	}

	public function test_get_by_magic_token_resolves_only_an_approved_record(): void {
		$captured_sql = '';
		$this->wpdb->shouldReceive('prepare')
			->once()
			->andReturnUsing(function ($sql) use (&$captured_sql) {
				$captured_sql = $sql;
				return 'QUERY';
			});
		$this->wpdb->shouldReceive('get_row')->once()->andReturn(null);

		ReregistrationSubmissionReader::get_by_magic_token('deadbeef');

		$this->assertStringContainsString("status = 'approved'", $captured_sql);
		$this->assertStringNotContainsString("'submitted'", $captured_sql);
	}

	// ==================================================================
	// ensure_magic_token()
	// ==================================================================

	public function test_ensure_magic_token_returns_existing_token(): void {
		$token = str_repeat('cd', 32);
		$submission = (object) array('id' => 20, 'magic_token' => $token);

		// Should NOT call update since token already exists.
		$this->wpdb->shouldNotReceive('update');

		$result = ReregistrationSubmissionWriter::ensure_magic_token($submission);

		$this->assertSame($token, $result);
	}

	public function test_ensure_magic_token_generates_new_token_when_empty(): void {
		$submission = (object) array('id' => 25, 'magic_token' => '');

		// The update call should happen to persist the new token.
		$this->wpdb->shouldReceive('prepare')->andReturn('QUERY');
		$this->wpdb->shouldReceive('update')->once()->andReturn(1);

		$result = ReregistrationSubmissionWriter::ensure_magic_token($submission);

		// Generated token should be 64 hex characters (bin2hex of 32 random bytes).
		$this->assertSame(64, strlen($result));
		$this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $result);
	}

	public function test_ensure_magic_token_generates_new_token_when_null(): void {
		$submission = (object) array('id' => 26, 'magic_token' => null);

		$this->wpdb->shouldReceive('prepare')->andReturn('QUERY');
		$this->wpdb->shouldReceive('update')->once()->andReturn(1);

		$result = ReregistrationSubmissionWriter::ensure_magic_token($submission);

		$this->assertSame(64, strlen($result));
		$this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $result);
	}

	// ==================================================================
	// get_by_reregistration_and_user()
	// ==================================================================

	public function test_get_by_reregistration_and_user_returns_matching_row(): void {
		$row = (object) array('id' => 30, 'reregistration_id' => 5, 'user_id' => 42);

		$this->wpdb->shouldReceive('prepare')->once()->andReturn('QUERY');
		$this->wpdb->shouldReceive('get_row')->once()->andReturn($row);

		$result = ReregistrationSubmissionReader::get_by_reregistration_and_user(5, 42);

		$this->assertSame(30, $result->id);
		$this->assertSame(5, $result->reregistration_id);
		$this->assertSame(42, $result->user_id);
	}

	public function test_get_by_reregistration_and_user_returns_null_when_not_found(): void {
		$this->wpdb->shouldReceive('prepare')->once()->andReturn('QUERY');
		$this->wpdb->shouldReceive('get_row')->once()->andReturn(null);

		$this->assertNull(ReregistrationSubmissionReader::get_by_reregistration_and_user(5, 999));
	}

	// ==================================================================
	// create()
	// ==================================================================

	public function test_create_inserts_basic_submission(): void {
		$this->wpdb->shouldReceive('insert')
			->once()
			->with(
				'wp_ffc_reregistration_submissions',
				Mockery::on(function ($data) {
					return $data['reregistration_id'] === 1
						&& $data['user_id'] === 10
						&& $data['status'] === 'pending'
						&& !isset($data['data'])
						&& !isset($data['submitted_at'])
						&& !isset($data['notes']);
				}),
				Mockery::type('array')
			)
			->andReturn(1);

		$this->wpdb->insert_id = 42;

		$result = ReregistrationSubmissionWriter::create(array(
			'reregistration_id' => 1,
			'user_id'           => 10,
		));

		$this->assertSame(42, $result);
	}

	public function test_create_inserts_with_json_data(): void {
		$this->wpdb->shouldReceive('insert')
			->once()
			->with(
				'wp_ffc_reregistration_submissions',
				Mockery::on(function ($data) {
					return isset($data['data'])
						&& $data['data'] === '{"field":"value"}';
				}),
				Mockery::type('array')
			)
			->andReturn(1);

		$this->wpdb->insert_id = 50;

		$result = ReregistrationSubmissionWriter::create(array(
			'reregistration_id' => 1,
			'user_id'           => 10,
			'data'              => array('field' => 'value'),
		));

		$this->assertSame(50, $result);
	}

	public function test_create_inserts_with_string_data(): void {
		$this->wpdb->shouldReceive('insert')
			->once()
			->with(
				'wp_ffc_reregistration_submissions',
				Mockery::on(function ($data) {
					return isset($data['data'])
						&& $data['data'] === '{"already":"encoded"}';
				}),
				Mockery::type('array')
			)
			->andReturn(1);

		$this->wpdb->insert_id = 51;

		$result = ReregistrationSubmissionWriter::create(array(
			'reregistration_id' => 1,
			'user_id'           => 10,
			'data'              => '{"already":"encoded"}',
		));

		$this->assertSame(51, $result);
	}

	public function test_create_inserts_with_submitted_at(): void {
		$this->wpdb->shouldReceive('insert')
			->once()
			->with(
				'wp_ffc_reregistration_submissions',
				Mockery::on(function ($data) {
					return isset($data['submitted_at'])
						&& $data['submitted_at'] === '2026-03-01 10:00:00';
				}),
				Mockery::type('array')
			)
			->andReturn(1);

		$this->wpdb->insert_id = 52;

		$result = ReregistrationSubmissionWriter::create(array(
			'reregistration_id' => 1,
			'user_id'           => 10,
			'submitted_at'      => '2026-03-01 10:00:00',
		));

		$this->assertSame(52, $result);
	}

	public function test_create_inserts_with_notes(): void {
		$this->wpdb->shouldReceive('insert')
			->once()
			->with(
				'wp_ffc_reregistration_submissions',
				Mockery::on(function ($data) {
					return isset($data['notes'])
						&& $data['notes'] === 'Some notes';
				}),
				Mockery::type('array')
			)
			->andReturn(1);

		$this->wpdb->insert_id = 53;

		$result = ReregistrationSubmissionWriter::create(array(
			'reregistration_id' => 1,
			'user_id'           => 10,
			'notes'             => 'Some notes',
		));

		$this->assertSame(53, $result);
	}

	public function test_create_returns_false_on_insert_failure(): void {
		$this->wpdb->shouldReceive('insert')->once()->andReturn(false);

		$result = ReregistrationSubmissionWriter::create(array(
			'reregistration_id' => 1,
			'user_id'           => 10,
		));

		$this->assertFalse($result);
	}

	public function test_create_uses_default_status_pending(): void {
		$this->wpdb->shouldReceive('insert')
			->once()
			->with(
				'wp_ffc_reregistration_submissions',
				Mockery::on(function ($data) {
					return $data['status'] === 'pending';
				}),
				Mockery::type('array')
			)
			->andReturn(1);

		$this->wpdb->insert_id = 54;

		ReregistrationSubmissionWriter::create(array(
			'reregistration_id' => 1,
			'user_id'           => 10,
		));
	}

	public function test_create_allows_custom_status(): void {
		$this->wpdb->shouldReceive('insert')
			->once()
			->with(
				'wp_ffc_reregistration_submissions',
				Mockery::on(function ($data) {
					return $data['status'] === 'in_progress';
				}),
				Mockery::type('array')
			)
			->andReturn(1);

		$this->wpdb->insert_id = 55;

		ReregistrationSubmissionWriter::create(array(
			'reregistration_id' => 1,
			'user_id'           => 10,
			'status'            => 'in_progress',
		));
	}

	// ==================================================================
	// update()
	// ==================================================================

	// ==================================================================
	// update_if_status() — the status test and the write in one statement (#2242)
	// ==================================================================

	/**
	 * The template and values of the conditional UPDATE, captured from prepare()
	 * under the keys `sql` and `values`.
	 *
	 * @return \ArrayObject<string, mixed>
	 */
	private function capture_conditional_write(): \ArrayObject {
		$captured = new \ArrayObject();
		$this->wpdb->shouldReceive('prepare')->andReturnUsing(function ($sql, ...$args) use ($captured) {
			if (0 === strpos($sql, 'UPDATE %i SET')) {
				$captured['sql']    = $sql;
				$captured['values'] = (1 === count($args) && is_array($args[0])) ? $args[0] : $args;
			}
			return $sql;
		});
		return $captured;
	}

	public function test_update_if_status_writes_only_while_the_status_is_allowed(): void {
		$captured = $this->capture_conditional_write();
		$this->wpdb->shouldReceive('query')->once()->andReturn(1);

		$this->assertTrue(ReregistrationSubmissionWriter::update_if_status(10, array('status' => 'submitted', 'notes' => null, 'submitted_at' => 1700000000), array('pending', 'in_progress')));

		$this->assertSame('UPDATE %i SET %i = %s, %i = NULL, %i = %d WHERE id = %d AND status IN (%s,%s)', $captured['sql']);
		$this->assertSame(
			array('wp_ffc_reregistration_submissions', 'status', 'submitted', 'notes', 'submitted_at', 1700000000, 10, 'pending', 'in_progress'),
			$captured['values'],
			'Every value travels as a placeholder argument, the statuses last.'
		);
	}

	public function test_update_if_status_refuses_a_row_that_moved_on(): void {
		// The second of two submits: the row is already `submitted`, so the
		// UPDATE matches nothing and the re-read says why.
		$this->capture_conditional_write();
		$this->wpdb->shouldReceive('query')->once()->andReturn(0);
		$this->wpdb->shouldReceive('get_var')->once()->andReturn('submitted');

		$this->assertFalse(ReregistrationSubmissionWriter::update_if_status(10, array('status' => 'in_progress'), array('pending', 'in_progress', 'rejected')));
	}

	public function test_update_if_status_accepts_a_write_that_changed_nothing(): void {
		// A draft saved twice in the same second with the same values: MySQL
		// reports zero rows changed, but the row is still open and holds them.
		$this->capture_conditional_write();
		$this->wpdb->shouldReceive('query')->once()->andReturn(0);
		$this->wpdb->shouldReceive('get_var')->once()->andReturn('in_progress');

		$this->assertTrue(ReregistrationSubmissionWriter::update_if_status(10, array('status' => 'in_progress'), array('pending', 'in_progress', 'rejected')));
	}

	public function test_update_if_status_reports_a_failed_query(): void {
		$this->capture_conditional_write();
		$this->wpdb->shouldReceive('query')->once()->andReturn(false);
		$this->wpdb->shouldNotReceive('get_var');

		$this->assertFalse(ReregistrationSubmissionWriter::update_if_status(10, array('status' => 'submitted'), array('pending')));
	}

	public function test_update_if_status_writes_nothing_without_columns_or_statuses(): void {
		$this->wpdb->shouldNotReceive('query');

		$this->assertFalse(ReregistrationSubmissionWriter::update_if_status(10, array('user_id' => 3), array('pending')), 'Only immutable columns: nothing to write.');
		$this->assertFalse(ReregistrationSubmissionWriter::update_if_status(10, array('status' => 'submitted'), array()), 'No allowed status: nothing may be written.');
	}

	public function test_update_if_status_drops_the_cached_row(): void {
		$this->capture_conditional_write();
		$this->wpdb->shouldReceive('query')->once()->andReturn(1);
		$deleted = array();
		Functions\when('wp_cache_delete')->alias(function ($key, $group) use (&$deleted) {
			$deleted[] = $group . ':' . $key;
			return true;
		});

		ReregistrationSubmissionWriter::update_if_status(10, array('status' => 'submitted'), array('pending'));

		$this->assertSame(array('ffc_rereg_submissions:id_10'), $deleted);
	}

	public function test_update_returns_true_on_success(): void {
		$this->wpdb->shouldReceive('update')
			->once()
			->with(
				'wp_ffc_reregistration_submissions',
				Mockery::on(function ($data) {
					return $data['status'] === 'submitted';
				}),
				array('id' => 1),
				Mockery::type('array'),
				array('%d')
			)
			->andReturn(1);

		$result = ReregistrationSubmissionWriter::update(1, array(
			'status' => 'submitted',
		));

		$this->assertTrue($result);
	}

	public function test_update_returns_false_on_failure(): void {
		$this->wpdb->shouldReceive('update')->once()->andReturn(false);

		$result = ReregistrationSubmissionWriter::update(1, array(
			'status' => 'submitted',
		));

		$this->assertFalse($result);
	}

	public function test_update_returns_false_for_empty_data(): void {
		$this->wpdb->shouldNotReceive('update');

		$result = ReregistrationSubmissionWriter::update(1, array());

		$this->assertFalse($result);
	}

	public function test_update_strips_immutable_fields(): void {
		// When only immutable fields are provided, nothing is left to update.
		$this->wpdb->shouldNotReceive('update');

		$result = ReregistrationSubmissionWriter::update(1, array(
			'id'                => 999,
			'reregistration_id' => 999,
			'user_id'           => 999,
			'created_at'        => '2026-01-01 00:00:00',
		));

		$this->assertFalse($result);
	}

	public function test_update_ignores_unknown_fields(): void {
		// Unknown fields should be silently ignored.
		$this->wpdb->shouldNotReceive('update');

		$result = ReregistrationSubmissionWriter::update(1, array(
			'unknown_field' => 'value',
			'another_bad'   => 123,
		));

		$this->assertFalse($result);
	}

	public function test_update_encodes_array_data_field(): void {
		$this->wpdb->shouldReceive('update')
			->once()
			->with(
				'wp_ffc_reregistration_submissions',
				Mockery::on(function ($data) {
					return $data['data'] === '{"key":"val"}';
				}),
				array('id' => 1),
				Mockery::type('array'),
				array('%d')
			)
			->andReturn(1);

		$result = ReregistrationSubmissionWriter::update(1, array(
			'data' => array('key' => 'val'),
		));

		$this->assertTrue($result);
	}

	public function test_update_preserves_string_data_field(): void {
		$this->wpdb->shouldReceive('update')
			->once()
			->with(
				'wp_ffc_reregistration_submissions',
				Mockery::on(function ($data) {
					return $data['data'] === '{"raw":"json"}';
				}),
				array('id' => 1),
				Mockery::type('array'),
				array('%d')
			)
			->andReturn(1);

		$result = ReregistrationSubmissionWriter::update(1, array(
			'data' => '{"raw":"json"}',
		));

		$this->assertTrue($result);
	}

	public function test_update_sanitizes_notes_field(): void {
		$this->wpdb->shouldReceive('update')
			->once()
			->with(
				'wp_ffc_reregistration_submissions',
				Mockery::on(function ($data) {
					// sanitize_textarea_field is aliased to trim.
					return $data['notes'] === 'trimmed notes';
				}),
				array('id' => 1),
				Mockery::type('array'),
				array('%d')
			)
			->andReturn(1);

		$result = ReregistrationSubmissionWriter::update(1, array(
			'notes' => '  trimmed notes  ',
		));

		$this->assertTrue($result);
	}

	public function test_update_allows_null_notes(): void {
		$this->wpdb->shouldReceive('update')
			->once()
			->with(
				'wp_ffc_reregistration_submissions',
				Mockery::on(function ($data) {
					return array_key_exists('notes', $data) && $data['notes'] === null;
				}),
				array('id' => 1),
				Mockery::type('array'),
				array('%d')
			)
			->andReturn(1);

		$result = ReregistrationSubmissionWriter::update(1, array(
			'notes' => null,
		));

		$this->assertTrue($result);
	}

	public function test_update_deletes_cache_for_id(): void {
		$this->wpdb->shouldReceive('update')->once()->andReturn(1);

		// We rely on the wp_cache_delete stub returning true.
		// The method should call cache_delete("id_1").
		$result = ReregistrationSubmissionWriter::update(1, array(
			'status' => 'approved',
		));

		$this->assertTrue($result);
	}

	public function test_update_handles_auth_code_field(): void {
		$this->wpdb->shouldReceive('update')
			->once()
			->with(
				'wp_ffc_reregistration_submissions',
				Mockery::on(function ($data) {
					return $data['auth_code'] === 'ABCD1234';
				}),
				array('id' => 1),
				Mockery::type('array'),
				array('%d')
			)
			->andReturn(1);

		$result = ReregistrationSubmissionWriter::update(1, array(
			'auth_code' => 'ABCD1234',
		));

		$this->assertTrue($result);
	}

	public function test_update_handles_magic_token_field(): void {
		$token = str_repeat('ef', 32);

		$this->wpdb->shouldReceive('update')
			->once()
			->with(
				'wp_ffc_reregistration_submissions',
				Mockery::on(function ($data) use ($token) {
					return $data['magic_token'] === $token;
				}),
				array('id' => 1),
				Mockery::type('array'),
				array('%d')
			)
			->andReturn(1);

		$result = ReregistrationSubmissionWriter::update(1, array(
			'magic_token' => $token,
		));

		$this->assertTrue($result);
	}

	// ==================================================================
	// approve()
	// ==================================================================

	/**
	 * The template and values of every conditional UPDATE, in order, keyed
	 * `sql` and `values`, plus the reads under `reads`.
	 *
	 * @return \ArrayObject<string, mixed>
	 */
	private function capture_review_writes(): \ArrayObject {
		$captured           = new \ArrayObject();
		$captured['writes'] = array();
		$this->wpdb->shouldReceive('prepare')->andReturnUsing(function ($sql, ...$args) use ($captured) {
			if (0 === strpos($sql, 'UPDATE %i SET')) {
				$writes             = $captured['writes'];
				$writes[]           = array(
					'sql'    => $sql,
					'values' => (1 === count($args) && is_array($args[0])) ? $args[0] : $args,
				);
				$captured['writes'] = $writes;
			}
			return $sql;
		});
		return $captured;
	}

	/**
	 * The SET column => value map of one captured conditional UPDATE.
	 *
	 * @param array{sql: string, values: array<int, mixed>} $write Captured write.
	 * @return array<string, mixed>
	 */
	private function set_map(array $write): array {
		$set    = substr($write['sql'], strlen('UPDATE %i SET '), strpos($write['sql'], ' WHERE ') - strlen('UPDATE %i SET '));
		$values = array_slice($write['values'], 1);
		$map    = array();
		foreach (explode(', ', $set) as $part) {
			$column = array_shift($values);
			$map[ $column ] = ('%i = NULL' === $part) ? null : array_shift($values);
		}
		return $map;
	}

	/**
	 * The statuses a captured conditional UPDATE was limited to.
	 *
	 * @param array{sql: string, values: array<int, mixed>} $write Captured write.
	 * @return array<int, mixed>
	 */
	private function allowed_statuses(array $write): array {
		$count = substr_count(substr($write['sql'], strpos($write['sql'], 'IN (')), '%s');
		return array_slice($write['values'], -$count);
	}

	/**
	 * `get_var()` answering the status re-read with `$status`, and every
	 * other read (the stored code, the code-uniqueness checks) with null.
	 *
	 * @param string $status Status the row is in.
	 * @return void
	 */
	private function status_reads_answer(string $status): void {
		$this->wpdb->shouldReceive('get_var')->andReturnUsing(function ($sql) use ($status) {
			return 0 === strpos((string) $sql, 'SELECT status') ? $status : null;
		});
	}

	public function test_approve_issues_a_code_and_records_the_reviewer(): void {
		$captured = $this->capture_review_writes();
		$this->status_reads_answer('approved');
		$this->wpdb->shouldReceive('query')->once()->andReturn(1);

		$this->assertTrue(ReregistrationSubmissionWriter::approve(5, 99));

		$map = $this->set_map($captured['writes'][0]);
		$this->assertSame('approved', $map['status']);
		$this->assertSame(99, $map['reviewed_by']);
		$this->assertGreaterThan(time() - 10, $map['reviewed_at']);
		$this->assertIsString($map['auth_code']);
		$this->assertNotSame('', $map['auth_code'], 'The code is born on approval (#2242).');
		$this->assertSame(array('submitted'), $this->allowed_statuses($captured['writes'][0]), 'Only a submission awaiting review is approved.');
	}

	public function test_re_approval_brings_back_the_same_code(): void {
		// Approved, returned to draft, submitted again: the stored code comes
		// back, so a record printed under the first approval still matches.
		$captured = $this->capture_review_writes();
		$this->wpdb->shouldReceive('get_var')->once()->andReturn('ORIGINALCODE');
		$this->wpdb->shouldReceive('query')->once()->andReturn(1);

		$this->assertTrue(ReregistrationSubmissionWriter::approve(5, 99));

		$this->assertSame('ORIGINALCODE', $this->set_map($captured['writes'][0])['auth_code']);
	}

	public function test_approve_refuses_a_row_that_is_not_awaiting_review(): void {
		$this->capture_review_writes();
		$this->status_reads_answer('pending');
		$this->wpdb->shouldReceive('query')->once()->andReturn(0);

		$this->assertFalse(ReregistrationSubmissionWriter::approve(5, 99));
	}

	public function test_approve_returns_false_on_failure(): void {
		$this->capture_review_writes();
		$this->status_reads_answer('submitted');
		$this->wpdb->shouldReceive('query')->once()->andReturn(false);

		$this->assertFalse(ReregistrationSubmissionWriter::approve(5, 99));
	}

	// ==================================================================
	// reject()
	// ==================================================================

	public function test_reject_deletes_the_code_and_records_the_reviewer(): void {
		$captured = $this->capture_review_writes();
		$this->wpdb->shouldReceive('query')->once()->andReturn(1);

		$this->assertTrue(ReregistrationSubmissionWriter::reject(7, 99, 'Incomplete submission'));

		$map = $this->set_map($captured['writes'][0]);
		$this->assertSame('rejected', $map['status']);
		$this->assertSame(99, $map['reviewed_by']);
		$this->assertSame('Incomplete submission', $map['notes']);
		$this->assertArrayHasKey('auth_code', $map);
		$this->assertNull($map['auth_code'], 'A rejected record must not verify (#2242).');
		$this->assertArrayHasKey('reminder_sent_at', $map);
		$this->assertNull($map['reminder_sent_at'], 'A rejected member is reminded again (#2242).');
		$this->assertSame(array('submitted'), $this->allowed_statuses($captured['writes'][0]));
	}

	public function test_reject_with_empty_notes(): void {
		$captured = $this->capture_review_writes();
		$this->wpdb->shouldReceive('query')->once()->andReturn(1);

		$this->assertTrue(ReregistrationSubmissionWriter::reject(7, 99));

		$this->assertSame('', $this->set_map($captured['writes'][0])['notes']);
	}

	public function test_reject_returns_false_on_failure(): void {
		$this->capture_review_writes();
		$this->wpdb->shouldReceive('query')->once()->andReturn(false);

		$this->assertFalse(ReregistrationSubmissionWriter::reject(7, 99, 'Reason'));
	}

	// ==================================================================
	// return_to_draft()
	// ==================================================================

	public function test_return_to_draft_resets_the_review_and_keeps_the_code(): void {
		$captured = $this->capture_review_writes();
		$this->wpdb->shouldReceive('query')->once()->andReturn(1);

		$this->assertTrue(ReregistrationSubmissionWriter::return_to_draft(8));

		$map = $this->set_map($captured['writes'][0]);
		$this->assertSame(
			array('status' => 'in_progress', 'submitted_at' => null, 'reviewed_at' => null, 'reviewed_by' => null, 'notes' => null, 'reminder_sent_at' => null),
			$map,
			'The code is not touched: it stays stored and hidden until re-approval (#2242).'
		);
		$this->assertSame(array('submitted', 'approved', 'rejected'), $this->allowed_statuses($captured['writes'][0]));
	}

	public function test_return_to_draft_returns_false_on_failure(): void {
		$this->capture_review_writes();
		$this->wpdb->shouldReceive('query')->once()->andReturn(false);

		$this->assertFalse(ReregistrationSubmissionWriter::return_to_draft(8));
	}

	public function test_return_to_draft_refuses_a_row_that_was_never_submitted(): void {
		$this->capture_review_writes();
		$this->wpdb->shouldReceive('query')->once()->andReturn(0);
		$this->wpdb->shouldReceive('get_var')->once()->andReturn('pending');

		$this->assertFalse(ReregistrationSubmissionWriter::return_to_draft(8));
	}

	// ==================================================================
	// bulk_approve()
	// ==================================================================

	public function test_bulk_approve_approves_all_submissions(): void {
		$this->capture_review_writes();
		$this->status_reads_answer('approved');
		$this->wpdb->shouldReceive('query')->times(3)->andReturn(1);

		$this->assertSame(3, ReregistrationSubmissionWriter::bulk_approve(array(1, 2, 3), 99));
	}

	public function test_bulk_approve_skips_the_rows_not_awaiting_review(): void {
		// "Select all" selects members who never answered: the conditional
		// write matches nothing for them, and the re-read says they moved on.
		$this->capture_review_writes();
		$this->status_reads_answer('pending');
		$this->wpdb->shouldReceive('query')->andReturn(1, 0, 1);

		$this->assertSame(2, ReregistrationSubmissionWriter::bulk_approve(array(1, 2, 3), 99));
	}

	public function test_bulk_approve_returns_zero_for_empty_array(): void {
		$this->assertSame(0, ReregistrationSubmissionWriter::bulk_approve(array(), 99));
	}

	public function test_bulk_approve_returns_zero_when_all_fail(): void {
		$this->capture_review_writes();
		$this->status_reads_answer('submitted');
		$this->wpdb->shouldReceive('query')->times(2)->andReturn(false);

		$this->assertSame(0, ReregistrationSubmissionWriter::bulk_approve(array(1, 2), 99));
	}

	public function test_return_to_draft_stores_the_reason_for_the_member(): void {
		$captured = $this->capture_review_writes();
		$this->wpdb->shouldReceive('query')->once()->andReturn(1);

		$this->assertTrue(ReregistrationSubmissionWriter::return_to_draft(8, 'Attach the missing document.'));

		$this->assertSame('Attach the missing document.', $this->set_map($captured['writes'][0])['notes']);
	}

	// ==================================================================
	// retract() and the reason floor (#2242)
	// ==================================================================

	public function test_retract_takes_back_only_a_submission_awaiting_review(): void {
		$captured = $this->capture_review_writes();
		$this->wpdb->shouldReceive('query')->once()->andReturn(1);

		$this->assertTrue(ReregistrationSubmissionWriter::retract(8));

		$this->assertSame(
			array('status' => 'in_progress', 'submitted_at' => null, 'notes' => null),
			$this->set_map($captured['writes'][0])
		);
		$this->assertSame(array('submitted'), $this->allowed_statuses($captured['writes'][0]));
	}

	public function test_retract_refuses_a_reviewed_submission(): void {
		$this->capture_review_writes();
		$this->wpdb->shouldReceive('query')->once()->andReturn(0);
		$this->wpdb->shouldReceive('get_var')->once()->andReturn('approved');

		$this->assertFalse(ReregistrationSubmissionWriter::retract(8));
	}

	public function test_a_reason_needs_ten_characters_after_trimming(): void {
		$this->assertFalse(ReregistrationSubmissionWriter::is_valid_reason(''));
		$this->assertFalse(ReregistrationSubmissionWriter::is_valid_reason('   short   '));
		$this->assertFalse(ReregistrationSubmissionWriter::is_valid_reason('123456789'));
		$this->assertTrue(ReregistrationSubmissionWriter::is_valid_reason('1234567890'));
		$this->assertFalse(ReregistrationSubmissionWriter::is_valid_reason(str_repeat('ã', 9)), 'Characters, not bytes: nine accented letters are 18 bytes.');
	}

	// ==================================================================
	// get_statistics()
	// ==================================================================

	public function test_get_statistics_returns_all_status_counts(): void {
		$rows = array(
			(object) array('status' => 'pending', 'count' => '5'),
			(object) array('status' => 'in_progress', 'count' => '3'),
			(object) array('status' => 'submitted', 'count' => '7'),
			(object) array('status' => 'approved', 'count' => '10'),
			(object) array('status' => 'rejected', 'count' => '2'),
			(object) array('status' => 'expired', 'count' => '1'),
		);

		$this->wpdb->shouldReceive('prepare')->once()->andReturn('QUERY');
		$this->wpdb->shouldReceive('get_results')->once()->andReturn($rows);

		$stats = ReregistrationSubmissionReader::get_statistics(1);

		$this->assertSame(28, $stats['total']);
		$this->assertSame(5, $stats['pending']);
		$this->assertSame(3, $stats['in_progress']);
		$this->assertSame(7, $stats['submitted']);
		$this->assertSame(10, $stats['approved']);
		$this->assertSame(2, $stats['rejected']);
		$this->assertSame(1, $stats['expired']);
	}

	public function test_get_statistics_returns_zeros_when_no_results(): void {
		$this->wpdb->shouldReceive('prepare')->once()->andReturn('QUERY');
		$this->wpdb->shouldReceive('get_results')->once()->andReturn(array());

		$stats = ReregistrationSubmissionReader::get_statistics(1);

		$this->assertSame(0, $stats['total']);
		$this->assertSame(0, $stats['pending']);
		$this->assertSame(0, $stats['in_progress']);
		$this->assertSame(0, $stats['submitted']);
		$this->assertSame(0, $stats['approved']);
		$this->assertSame(0, $stats['rejected']);
		$this->assertSame(0, $stats['expired']);
	}

	public function test_get_statistics_handles_partial_statuses(): void {
		$rows = array(
			(object) array('status' => 'approved', 'count' => '15'),
			(object) array('status' => 'pending', 'count' => '5'),
		);

		$this->wpdb->shouldReceive('prepare')->once()->andReturn('QUERY');
		$this->wpdb->shouldReceive('get_results')->once()->andReturn($rows);

		$stats = ReregistrationSubmissionReader::get_statistics(1);

		$this->assertSame(20, $stats['total']);
		$this->assertSame(15, $stats['approved']);
		$this->assertSame(5, $stats['pending']);
		$this->assertSame(0, $stats['in_progress']);
		$this->assertSame(0, $stats['submitted']);
		$this->assertSame(0, $stats['rejected']);
		$this->assertSame(0, $stats['expired']);
	}

	// ==================================================================
	// count_filtered() / filters (#2242)
	// ==================================================================

	/**
	 * A prepare() that records its arguments, so a test reads the SQL and
	 * the values the filters produced.
	 *
	 * @return \ArrayObject<int, array<int, mixed>>
	 */
	private function recordPrepare(): \ArrayObject {
		$calls = new \ArrayObject();
		$this->wpdb->shouldReceive('esc_like')->andReturnUsing(static fn ($v) => $v);
		$this->wpdb->shouldReceive('prepare')->andReturnUsing(
			static function (...$args) use ($calls) {
				$flat    = 2 === count($args) && is_array($args[1]) ? array_merge(array($args[0]), $args[1]) : $args;
				$calls[] = $flat;
				return 'Q' . (count($calls) - 1);
			}
		);
		return $calls;
	}

	public function test_count_filtered_counts_the_filtered_rows(): void {
		$calls = $this->recordPrepare();
		$this->wpdb->shouldReceive('get_var')->once()->andReturn('12');

		$count = ReregistrationSubmissionReader::count_filtered(1, array('status' => 'approved'));

		$this->assertSame(12, $count);
		$this->assertStringContainsString('SELECT COUNT(*) FROM %i s LEFT JOIN %i u', $calls[0][0]);
		$this->assertSame(array('wp_ffc_reregistration_submissions', 'wp_users', 1, 'approved'), array_slice($calls[0], 1));
	}

	public function test_count_filtered_returns_zero_for_a_failed_query(): void {
		$this->recordPrepare();
		$this->wpdb->shouldReceive('get_var')->once()->andReturn(null);

		$this->assertSame(0, ReregistrationSubmissionReader::count_filtered(1));
	}

	public function test_normalize_filters_keeps_known_values_only(): void {
		$this->assertSame(
			array('status' => 'not_finished', 'search' => 'ana', 'audience_id' => 3),
			ReregistrationSubmissionReader::normalize_filters('not_finished', ' ana ', 3)
		);
		$this->assertSame(
			array('status' => null, 'search' => null, 'audience_id' => 0),
			ReregistrationSubmissionReader::normalize_filters('nope', '  ', -4),
			'An unknown status lists everything rather than nothing.'
		);
	}

	public function test_a_cpf_search_also_matches_the_account_that_carries_it(): void {
		$calls = $this->recordPrepare();
		$this->wpdb->shouldReceive('get_var')->andReturnUsing(
			static fn ($sql) => '42'
		);
		$this->wpdb->shouldReceive('get_results')->andReturn(array());

		ReregistrationSubmissionReader::get_by_reregistration(1, array('search' => '123.456.789-09'));

		$lookup = array_values(array_filter((array) $calls, static fn ($c) => false !== strpos($c[0], 'cpf_hash = %s')));
		$this->assertNotEmpty($lookup, 'The identity index is asked by hash.');
		$this->assertSame(\FreeFormCertificate\Core\Encryption::hash('12345678909'), $lookup[0][2], 'Asked by the digits\' hash, whatever the mask.');
		$this->assertNotSame('12345678909', $lookup[0][2], 'The CPF is never sent in clear.');

		$list = array_values(array_filter((array) $calls, static fn ($c) => false !== strpos($c[0], 'SELECT s.*')));
		$this->assertStringContainsString('(u.display_name LIKE %s OR u.user_email LIKE %s OR s.user_id = %d)', $list[0][0]);
		$this->assertSame(42, end($list[0]));
	}

	public function test_an_rf_search_asks_the_rf_column(): void {
		$calls = $this->recordPrepare();
		$this->wpdb->shouldReceive('get_var')->andReturn(null);
		$this->wpdb->shouldReceive('get_results')->andReturn(array());

		ReregistrationSubmissionReader::get_by_reregistration(1, array('search' => '1234567'));

		$lookups = array_values(array_filter((array) $calls, static fn ($c) => false !== strpos($c[0], 'rf_hash = %s')));
		$this->assertNotEmpty($lookups);
		$list = array_values(array_filter((array) $calls, static fn ($c) => false !== strpos($c[0], 'SELECT s.*')));
		$this->assertStringNotContainsString('s.user_id = %d', $list[0][0], 'No account carries it, so only the name and e-mail match.');
	}

	public function test_a_name_search_never_asks_the_identity_index(): void {
		$calls = $this->recordPrepare();
		$this->wpdb->shouldReceive('get_results')->andReturn(array());

		ReregistrationSubmissionReader::get_by_reregistration(1, array('search' => 'Ana 1234567'));

		$this->assertCount(1, $calls, 'Only the list query.');
	}

	// ==================================================================
	// find_by_cursor_for_export() — keyset page for the batched CSV export
	// ==================================================================

	public function test_find_by_cursor_for_export_builds_keyset_desc_query(): void {
		$captured_sql = '';
		$this->wpdb->shouldReceive('prepare')->once()->andReturnUsing(function () use (&$captured_sql) {
			$captured_sql = func_get_args()[0];
			return $captured_sql;
		});
		$rows = [(object) ['id' => 5], (object) ['id' => 4]];
		$this->wpdb->shouldReceive('get_results')->once()->andReturn($rows);

		$result = ReregistrationSubmissionReader::find_by_cursor_for_export(3, 10, 50);

		$this->assertSame($rows, $result);
		$this->assertStringContainsString('s.reregistration_id = %d AND s.id < %d', $captured_sql);
		$this->assertStringContainsString('ORDER BY s.id DESC', $captured_sql);
		$this->assertStringContainsString('LIMIT %d', $captured_sql);
		$this->assertStringContainsString('LEFT JOIN', $captured_sql);
	}

	public function test_find_by_cursor_for_export_returns_empty_when_no_results(): void {
		$this->wpdb->shouldReceive('prepare')->once()->andReturn('QUERY');
		$this->wpdb->shouldReceive('get_results')->once()->andReturn(null);

		$this->assertSame([], ReregistrationSubmissionReader::find_by_cursor_for_export(3, 10, 50));
	}

	// ==================================================================
	// get_by_reregistration()
	// ==================================================================

	public function test_get_by_reregistration_returns_results(): void {
		$rows = array(
			(object) array('id' => 1, 'status' => 'pending', 'user_name' => 'Alice'),
			(object) array('id' => 2, 'status' => 'approved', 'user_name' => 'Bob'),
		);

		$this->wpdb->shouldReceive('prepare')->once()->andReturn('QUERY');
		$this->wpdb->shouldReceive('get_results')->once()->andReturn($rows);

		$results = ReregistrationSubmissionReader::get_by_reregistration(1);

		$this->assertCount(2, $results);
		$this->assertSame('Alice', $results[0]->user_name);
	}

	public function test_get_by_reregistration_with_status_filter(): void {
		$this->wpdb->shouldReceive('prepare')
			->once()
			->andReturnUsing(function () {
				$sql = func_get_args()[0];
				$this->assertStringContainsString('s.status IN (%s)', $sql);
				return 'QUERY';
			});
		$this->wpdb->shouldReceive('get_results')->once()->andReturn(array());

		ReregistrationSubmissionReader::get_by_reregistration(1, array('status' => 'approved'));
	}

	public function test_get_by_reregistration_with_search_filter(): void {
		$this->wpdb->shouldReceive('esc_like')->once()->andReturnUsing(function ($v) {
			return $v;
		});
		$this->wpdb->shouldReceive('prepare')
			->once()
			->andReturnUsing(function () {
				$sql = func_get_args()[0];
				$this->assertStringContainsString('LIKE', $sql);
				return 'QUERY';
			});
		$this->wpdb->shouldReceive('get_results')->once()->andReturn(array());

		ReregistrationSubmissionReader::get_by_reregistration(1, array('search' => 'alice'));
	}

	public function test_get_by_reregistration_with_limit_and_offset(): void {
		$this->wpdb->shouldReceive('prepare')
			->once()
			->andReturnUsing(function () {
				$sql = func_get_args()[0];
				$this->assertStringContainsString('LIMIT', $sql);
				return 'QUERY';
			});
		$this->wpdb->shouldReceive('get_results')->once()->andReturn(array());

		ReregistrationSubmissionReader::get_by_reregistration(1, array(
			'limit'  => 10,
			'offset' => 20,
		));
	}

	public function test_get_by_reregistration_invalid_orderby_falls_back(): void {
		$this->wpdb->shouldReceive('prepare')
			->once()
			->andReturnUsing(function () {
				$sql = func_get_args()[0];
				$this->assertStringContainsString('s.created_at', $sql);
				return 'QUERY';
			});
		$this->wpdb->shouldReceive('get_results')->once()->andReturn(array());

		ReregistrationSubmissionReader::get_by_reregistration(1, array(
			'orderby' => 'malicious_column',
		));
	}

	public function test_get_by_reregistration_desc_order(): void {
		$this->wpdb->shouldReceive('prepare')
			->once()
			->andReturnUsing(function () {
				$sql = func_get_args()[0];
				$this->assertStringContainsString('DESC', $sql);
				return 'QUERY';
			});
		$this->wpdb->shouldReceive('get_results')->once()->andReturn(array());

		ReregistrationSubmissionReader::get_by_reregistration(1, array(
			'order' => 'DESC',
		));
	}

	// ==================================================================
	// get_for_export()
	// ==================================================================

	public function test_get_for_export_delegates_to_get_by_reregistration(): void {
		$rows = array(
			(object) array('id' => 1, 'status' => 'approved'),
		);

		$this->wpdb->shouldReceive('prepare')->once()->andReturn('QUERY');
		$this->wpdb->shouldReceive('get_results')->once()->andReturn($rows);

		$results = ReregistrationSubmissionReader::get_for_export(1);

		$this->assertCount(1, $results);
	}

	// ==================================================================
	// get_all_by_user()
	// ==================================================================

	public function test_get_all_by_user_returns_array_of_results(): void {
		$rows = array(
			(object) array('id' => 1, 'user_id' => 42, 'reregistration_title' => 'Campaign A'),
			(object) array('id' => 2, 'user_id' => 42, 'reregistration_title' => 'Campaign B'),
		);

		$this->wpdb->shouldReceive('prepare')->once()->andReturn('QUERY');
		$this->wpdb->shouldReceive('get_results')->once()->andReturn($rows);

		$results = ReregistrationSubmissionReader::get_all_by_user(42);

		$this->assertCount(2, $results);
		$this->assertSame('Campaign A', $results[0]->reregistration_title);
	}

	public function test_get_all_by_user_returns_empty_array_when_no_results(): void {
		$this->wpdb->shouldReceive('prepare')->once()->andReturn('QUERY');
		$this->wpdb->shouldReceive('get_results')->once()->andReturn(null);

		$results = ReregistrationSubmissionReader::get_all_by_user(42);

		$this->assertSame(array(), $results);
	}

	// ==================================================================
	// create_for_audience_members()
	// The real ReregistrationRepository and AudienceReader resolve the
	// members: the double answers that an audience has no children, answers
	// its members, and records the audiences asked for.
	// ==================================================================

	/**
	 * Teach the `$wpdb` double the audience tables the real AudienceReader
	 * reads: `get_results()` answers that a parent has no children, `get_col()`
	 * the members of the audiences in an `IN ()` list. Each audience asked
	 * for is recorded in `$this->member_reads`.
	 *
	 * @param array<int, list<mixed>> $members Stored member ids by audience, as the driver hands them over.
	 */
	private function install_audiences(array $members): void {
		$last = array('', array());
		$this->wpdb->shouldReceive('prepare')->andReturnUsing(
			function ($sql, ...$args) use (&$last) {
				if (1 === count($args) && is_array($args[0])) {
					$args = $args[0];
				}
				$last = array((string) $sql, array_values($args));
				return (string) $sql;
			}
		);
		$this->wpdb->shouldReceive('get_results')->andReturnUsing(
			function ($query) use (&$last) {
				[$sql, $args] = $last;
				$this->assertSame('SELECT * FROM %i WHERE parent_id = %d ORDER BY name ASC ', $sql, 'Unexpected get_results() query.');
				$this->assertSame('wp_ffc_audiences', $args[0]);
				return array();
			}
		);
		$this->wpdb->shouldReceive('get_col')->andReturnUsing(
			function ($query) use (&$last, $members) {
				[$sql, $args] = $last;
				$this->assertStringStartsWith('SELECT DISTINCT user_id FROM %i WHERE audience_id IN (', $sql, 'Unexpected get_col() query.');
				$this->assertSame('wp_ffc_audience_members', array_shift($args));
				$this->member_reads[] = $args;
				$out = array();
				foreach ($args as $audience_id) {
					$out = array_merge($out, $members[$audience_id] ?? array());
				}
				return $out;
			}
		);
	}

	/**
	 * The daily run creates the missing rows of every active campaign (#2242):
	 * whoever joined an audience after activation, or one of its children,
	 * gets a row and so an invitation and reminders. Each campaign is seeded
	 * from its own audiences, through the same `INSERT IGNORE`.
	 */
	public function test_seed_active_campaigns_seeds_each_active_campaign_from_its_own_audiences(): void {
		$last = array('', array());
		$this->wpdb->shouldReceive('prepare')->andReturnUsing(
			function ($sql, ...$args) use (&$last) {
				if (1 === count($args) && is_array($args[0])) {
					$args = $args[0];
				}
				$last = array((string) $sql, array_values($args));
				return (string) $sql;
			}
		);
		$this->wpdb->shouldReceive('get_results')->andReturn(array()); // No child audiences.
		$this->wpdb->shouldReceive('get_col')->andReturnUsing(
			function () use (&$last) {
				[$sql, $args] = $last;
				if (0 === strpos($sql, "SELECT id FROM %i WHERE status = 'active'")) {
					return array('3', '5');
				}
				if (0 === strpos($sql, 'SELECT audience_id FROM %i WHERE reregistration_id')) {
					return 3 === (int) $args[1] ? array('100') : array('200');
				}
				// Members: 100 has a newcomer; 200 has none new.
				return 100 === (int) $args[1] ? array('10', '11') : array('20');
			}
		);
		$seeded = array();
		$this->wpdb->shouldReceive('query')->andReturnUsing(
			function () use (&$last, &$seeded) {
				[$sql, $args] = $last;
				$this->assertStringStartsWith('INSERT IGNORE INTO %i', $sql);
				$seeded[] = array_slice($args, 1);
				return 1;
			}
		);

		ReregistrationSubmissionWriter::seed_active_campaigns();

		$this->assertSame(
			array(
				array(3, 10, 'pending', 3, 11, 'pending'),
				array(5, 20, 'pending'),
			),
			$seeded
		);
	}

	public function test_seed_active_campaigns_does_nothing_without_an_active_campaign(): void {
		$this->wpdb->shouldReceive('get_col')->once()->andReturn(array());
		$this->wpdb->shouldNotReceive('query');

		ReregistrationSubmissionWriter::seed_active_campaigns();
	}

	/**
	 * A single INSERT covers the whole page, and the count comes from the database.
	 *
	 * This is #1234's central assertion: it used to be two statements PER USER
	 * (an existence SELECT and an INSERT); now it is `ceil( N / 500 )` INSERTs.
	 * With three users, exactly one.
	 */
	public function test_create_for_audience_members_seeds_every_user_in_one_statement(): void {
		$this->install_audiences(array(100 => array('10', '20'), 200 => array('20', '30')));

		$captured = '';
		$this->wpdb->shouldReceive('query')->once()->andReturnUsing(
			function ($sql) use (&$captured) {
				$captured = (string) $sql;
				return 3;
			}
		);

		// The old shape cannot survive this change without being seen.
		$this->wpdb->shouldNotReceive('insert');
		$this->wpdb->shouldNotReceive('get_row');

		$count = ReregistrationSubmissionWriter::create_for_audience_members(5, array(100, 200));
		$this->assertSame(array(array(100), array(200)), $this->member_reads, 'Each audience\'s members are read once; 20 belongs to both.');

		$this->assertSame(3, $count, 'The count is the INSERT\'s affected_rows, not a PHP accumulator.');
		$this->assertStringContainsString('INSERT IGNORE INTO', $captured);
		$this->assertSame(
			3,
			substr_count($captured, '(%d, %d, %s)'),
			'One placeholder tuple per user -- a misaligned list here becomes wrong data, not an error.'
		);
	}

	/**
	 * Whoever already has a submission is dropped by the UNIQUE, not by a prior read.
	 *
	 * `INSERT IGNORE` sends two tuples and the database accepts one; the
	 * `affected_rows` returned is already the count of rows CREATED. No
	 * existence SELECT is emitted -- it was that one which cost half of the
	 * twenty thousand queries.
	 */
	public function test_create_for_audience_members_lets_the_unique_key_skip_existing_rows(): void {
		$this->install_audiences(array(100 => array('10', '20')));

		$captured = '';
		$this->wpdb->shouldReceive('query')->once()->andReturnUsing(
			function ($sql) use (&$captured) {
				$captured = (string) $sql;
				return 1;
			}
		);
		$this->wpdb->shouldNotReceive('get_row');

		$count = ReregistrationSubmissionWriter::create_for_audience_members(5, array(100));
		$this->assertSame(array(array(100)), $this->member_reads, 'The members of the audience asked for are read.');

		$this->assertSame(1, $count);
		$this->assertStringContainsString(
			'IGNORE',
			$captured,
			'Without the IGNORE the duplicate row brings down the whole batch, not just itself.'
		);
	}

	/**
	 * Above the batch size, one query per page -- not a single, giant one.
	 *
	 * This is the half of the fix a three-user test cannot see: without the
	 * `array_chunk`, ten thousand members become one INSERT of thirty thousand
	 * placeholders, which hits `max_allowed_packet` and fails on the real
	 * client without failing here.
	 */
	public function test_create_for_audience_members_splits_large_audiences_into_pages(): void {
		$user_ids = range(1, 1201);

		$this->install_audiences(array(100 => array_map('strval', $user_ids)));

		$sizes = array();
		$this->wpdb->shouldReceive('query')->times(3)->andReturnUsing(
			function ($sql) use (&$sizes) {
				$rows    = substr_count((string) $sql, '(%d, %d, %s)');
				$sizes[] = $rows;
				return $rows;
			}
		);

		$count = ReregistrationSubmissionWriter::create_for_audience_members(5, array(100));
		$this->assertSame(array(array(100)), $this->member_reads, 'The members of the audience asked for are read.');

		$this->assertSame(array(500, 500, 201), $sizes);
		$this->assertSame(1201, $count, 'The count sums the pages.');
		$this->assertSame(
			500,
			ReregistrationSubmissionWriter::SEED_CHUNK_SIZE,
			'The size is public because this test reads it -- changing the number means revising the pages above.'
		);
	}

	/**
	 * With no users, no query at all.
	 */
	public function test_create_for_audience_members_returns_zero_for_no_users(): void {
		$this->install_audiences(array(100 => array()));

		$this->wpdb->shouldNotReceive('query');
		$this->wpdb->shouldNotReceive('insert');

		$count = ReregistrationSubmissionWriter::create_for_audience_members(5, array(100));
		$this->assertSame(array(array(100)), $this->member_reads, 'The members of the audience asked for are read.');

		$this->assertSame(0, $count);
	}

	/**
	 * An invalid id does not become a tuple.
	 *
	 * `get_members()` returns whatever the driver handed over -- a string, and
	 * occasionally `0` or empty. Without the normalisation, a `0` would enter as
	 * `user_id = 0`: a pending submission belonging to nobody, which the UNIQUE
	 * accepts once per campaign and which nobody ever fills in. With the real
	 * readers the stored strings are cast by `get_members()` and the repeat is
	 * dropped by `get_user_ids_for_audiences()`; the writer drops the rest.
	 */
	public function test_create_for_audience_members_drops_non_positive_and_duplicate_ids(): void {
		$this->install_audiences(array(100 => array('10', '0', '10', '', '20', '-3')));

		$captured = '';
		$this->wpdb->shouldReceive('query')->once()->andReturnUsing(
			function ($sql) use (&$captured) {
				$captured = (string) $sql;
				return 2;
			}
		);

		$count = ReregistrationSubmissionWriter::create_for_audience_members(5, array(100));
		$this->assertSame(array(array(100)), $this->member_reads, 'The members of the audience asked for are read.');

		$this->assertSame(2, $count);
		$this->assertSame(
			2,
			substr_count($captured, '(%d, %d, %s)'),
			'Only 10 and 20 remain: the zero, the empty, the negative and the repeat leave before the SQL.'
		);
	}

	/**
	 * A page that fails brings down neither the others nor inflates the count.
	 *
	 * `wpdb::query()` returns `false` on error. MEASURED: swapping
	 * `is_int( $affected ) && $affected > 0` for just `$affected > 0` keeps this
	 * test green, because `false > 0` is already false -- the `is_int()` is
	 * there for PHPStan (level 8 does not narrow `int|bool` from a comparison),
	 * not for the runtime. What this test proves is the behaviour: the next page
	 * runs and only it counts.
	 */
	public function test_create_for_audience_members_survives_a_failed_page(): void {
		$this->install_audiences(array(100 => range(1, 600)));

		$this->wpdb->shouldReceive('query')->twice()->andReturn(false, 100);

		$count = ReregistrationSubmissionWriter::create_for_audience_members(5, array(100));
		$this->assertSame(array(array(100)), $this->member_reads, 'The members of the audience asked for are read.');

		$this->assertSame(100, $count);
	}

	// ==================================================================
	// get_awaiting_invitation() / mark_invited() — #1190
	// ==================================================================

	/**
	 * Captures the SQL and the bound values of the eligibility query.
	 *
	 * @param int|null $extended_at Deadline extension, or null.
	 * @return array{sql: string, values: array<int, mixed>}
	 */
	private function capture_awaiting_query(?int $extended_at, int $after_id = 0, int $limit = 0): array {
		$captured = array( 'sql' => '', 'values' => array() );

		$this->wpdb->shouldReceive('prepare')->andReturnUsing(function () use (&$captured) {
			$args = func_get_args();
			if ( strpos( (string) $args[0], 'invited_at' ) !== false ) {
				$captured['sql']    = (string) $args[0];
				$captured['values'] = isset( $args[1] ) && is_array( $args[1] ) ? $args[1] : array();
			}
			return $args[0];
		});
		$this->wpdb->shouldReceive('get_results')->andReturn(array());

		ReregistrationSubmissionReader::get_awaiting_invitation(7, $extended_at, $after_id, $limit);

		return $captured;
	}

	/**
	 * The daily batch (#1762) pages through the queue by `id`: the cursor and
	 * the limit land in the SQL and in the values, after the clause, in order.
	 */
	public function test_get_awaiting_invitation_pages_by_id_when_asked(): void {
		$captured = $this->capture_awaiting_query(1780000000, 40, 50);

		$this->assertStringContainsString('AND id > %d ORDER BY id ASC LIMIT %d', $captured['sql']);
		$this->assertSame(array(40, 50), array_slice($captured['values'], -2));
	}

	public function test_get_awaiting_invitation_without_a_page_reads_every_row(): void {
		$captured = $this->capture_awaiting_query(null);

		$this->assertStringNotContainsString('id >', $captured['sql']);
		$this->assertStringNotContainsString('LIMIT', $captured['sql']);
	}

	/**
	 * With no extension recorded, only the never-invited are owed an email —
	 * which is what makes the button idempotent.
	 */
	public function test_get_awaiting_invitation_without_extension_asks_only_for_the_never_invited(): void {
		$captured = $this->capture_awaiting_query(null);

		$this->assertStringContainsString('invited_at IS NULL', $captured['sql']);
		$this->assertStringNotContainsString('status IN', $captured['sql']);
		$this->assertContains(7, $captured['values']);
	}

	/**
	 * With an extension, whoever has not finished is owed one too — and the
	 * comparison is against the extension timestamp, so somebody invited AFTER
	 * it is left alone.
	 */
	public function test_get_awaiting_invitation_with_extension_adds_the_unfinished(): void {
		$captured = $this->capture_awaiting_query(1780000000);

		$this->assertStringContainsString('invited_at IS NULL', $captured['sql']);
		$this->assertStringContainsString('invited_at < %d', $captured['sql']);
		$this->assertStringContainsString('status IN', $captured['sql']);
		$this->assertContains(1780000000, $captured['values']);

		foreach ( ReregistrationSubmissionReader::UNFINISHED_STATUSES as $status ) {
			$this->assertContains($status, $captured['values']);
		}
	}

	/**
	 * `submitted` and `approved` are out on purpose: the person did their part,
	 * and a longer deadline is not news to them.
	 */
	public function test_finished_statuses_are_not_re_invited(): void {
		$this->assertNotContains('submitted', ReregistrationSubmissionReader::UNFINISHED_STATUSES);
		$this->assertNotContains('approved', ReregistrationSubmissionReader::UNFINISHED_STATUSES);
		$this->assertContains('rejected', ReregistrationSubmissionReader::UNFINISHED_STATUSES);
		$this->assertContains('expired', ReregistrationSubmissionReader::UNFINISHED_STATUSES);
	}

	public function test_mark_invited_stamps_only_the_supplied_rows(): void {
		$captured = array();
		$this->wpdb->shouldReceive('prepare')->andReturnUsing(function () use (&$captured) {
			$args = func_get_args();
			if ( strpos( (string) $args[0], 'SET invited_at' ) !== false ) {
				$captured = isset( $args[1] ) && is_array( $args[1] ) ? $args[1] : array();
			}
			return $args[0];
		});
		$this->wpdb->shouldReceive('query')->andReturn(2);

		$this->assertSame(2, ReregistrationSubmissionWriter::mark_invited(array(101, 102)));
		$this->assertContains(101, $captured);
		$this->assertContains(102, $captured);
	}

	/**
	 * An empty batch must not issue an `UPDATE … WHERE id IN ()` — which is a
	 * syntax error, and on some builds a table-wide write.
	 */
	public function test_mark_invited_does_nothing_for_an_empty_batch(): void {
		$this->wpdb->shouldNotReceive('query');

		$this->assertSame(0, ReregistrationSubmissionWriter::mark_invited(array()));
	}

	/**
	 * `prepare()` returns `string|null`, and `query()` only takes a string. A
	 * null must stop before the query rather than be cast to an empty one.
	 */
	public function test_mark_invited_stops_when_prepare_fails(): void {
		$this->wpdb->shouldReceive('prepare')->andReturn(null);
		$this->wpdb->shouldNotReceive('query');

		$this->assertSame(0, ReregistrationSubmissionWriter::mark_invited(array(101)));
	}

}
