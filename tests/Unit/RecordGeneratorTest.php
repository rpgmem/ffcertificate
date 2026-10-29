<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Reregistration\RecordGenerator;
use FreeFormCertificate\Tests\Support\Identifiers;

/**
 * Tests for RecordGenerator: working-hours formatting, custom-fields section
 * building, template loading, and record data generation.
 *
 * Uses ReflectionMethod to access private static helpers directly.
 *
 * The data paths run the real ReregistrationSubmissionReader,
 * ReregistrationRepository, CustomFieldReader, AudienceReader, Encryption
 * (with the bootstrap's key constants), DateFormatter, DocumentFormatter and
 * FilenameHelper over a `$wpdb` double: each table's rows live in this class,
 * every `prepare()` is recorded, and an unexpected query fails the test.
 *
 * @covers \FreeFormCertificate\Reregistration\RecordGenerator
 */
class RecordGeneratorTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var Mockery\MockInterface */
	private $wpdb;

	/**
	 * Every prepared statement, in order: [ sql, args ].
	 *
	 * @var list<array{0: string, 1: array<int, mixed>}>
	 */
	private array $prepared = [];

	/**
	 * Stored `ffc_reregistration_submissions` rows, by id.
	 *
	 * @var array<int, object>
	 */
	private array $submissions = [];

	/**
	 * Stored `ffc_reregistrations` rows, by id.
	 *
	 * @var array<int, object>
	 */
	private array $reregistrations = [];

	/**
	 * Stored `ffc_reregistration_audiences` links: campaign id => audience ids.
	 *
	 * @var array<int, list<int>>
	 */
	private array $campaign_audiences = [];

	/**
	 * Stored `ffc_audiences` rows, by id.
	 *
	 * @var array<int, object>
	 */
	private array $audiences = [];

	/**
	 * Stored active `ffc_custom_fields` rows, by audience id.
	 *
	 * @var array<int, list<object>>
	 */
	private array $fields_by_audience = [];

	/**
	 * The `$wpdb` global before the test, restored in tearDown().
	 *
	 * @var mixed
	 */
	private $previous_wpdb;

	/** Whether `$wpdb` was set before the test. */
	private bool $had_wpdb = false;

	/**
	 * A CPF in its `ddd.ddd.ddd-dd` display form, built from the standard fixture.
	 *
	 * @param string $cpf Eleven digits.
	 * @return string
	 */
	private static function mask_cpf( string $cpf ): string {
		return substr( $cpf, 0, 3 ) . '.' . substr( $cpf, 3, 3 ) . '.' . substr( $cpf, 6, 3 ) . '-' . substr( $cpf, 9, 2 );
	}

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// pcov attribution preload (CLAUDE.md pcov gotcha).
		class_exists('\FreeFormCertificate\Reregistration\RecordGenerator');

		Functions\when('__')->returnArg();
		Functions\when('esc_html__')->returnArg();
		Functions\when('esc_html')->returnArg();
		Functions\when('esc_attr')->returnArg();
		Functions\when('wp_kses_post')->returnArg();
		Functions\when('sanitize_text_field')->alias('trim');
		// #865 Phase 2: RecordGenerator now resolves {{logo_gov}}/{{logo_org}} via
		// BrandingTokens → SettingsReader::get() → get_option( 'ffc_settings' ).
		Functions\when('get_option')->justReturn(array(
			'logo_gov' => 'https://cdn.example/gov.png',
			'logo_org' => 'https://cdn.example/org.png',
		));
		Functions\when('sanitize_file_name')->alias(function ($name) {
			return preg_replace('/[^a-zA-Z0-9_\-.]/', '_', $name);
		});

		// The five `ffcertificate_ficha_*` hooks became `ffcertificate_record_*`
		// in 6.26.0 (#1264) and their aliases were removed in 6.28.0, so
		// nothing here calls `apply_filters_deprecated()` any more. The stub
		// stays as a pass-through: without it, an alias reintroduced by a
		// revert would fatal on an undefined function instead of being
		// reported by the two tests that assert it is gone.
		Functions\when('apply_filters_deprecated')->alias(function ($tag, $args) {
			return $args[0];
		});

		// Storage and clock for the real readers and the DateFormatter.
		Functions\when('wp_cache_get')->justReturn(false);
		Functions\when('wp_cache_set')->justReturn(true);
		Functions\when('wp_timezone')->alias(static function () {
			return new \DateTimeZone('UTC');
		});
		Functions\when('wp_date')->alias(static function ($format, $timestamp = null) {
			return gmdate((string) $format, null === $timestamp ? time() : (int) $timestamp);
		});

		// Encryption counts failed decrypts in a private static; start each test at zero.
		$this->setDecryptFailureCount(0);

		$this->prepared           = [];
		$this->submissions        = [];
		$this->reregistrations    = [];
		$this->campaign_audiences = [];
		$this->audiences          = [];
		$this->fields_by_audience = [];

		$this->wpdb         = Mockery::mock('wpdb');
		$this->wpdb->prefix = 'wp_';
		$this->wpdb->shouldReceive('prepare')->andReturnUsing(function (...$args) {
			$flat             = 2 === count($args) && is_array($args[1]) ? array_merge([$args[0]], $args[1]) : $args;
			$this->prepared[] = [(string) $flat[0], array_slice($flat, 1)];
			return 'Q' . (count($this->prepared) - 1);
		});
		$this->wpdb->shouldReceive('get_row')->andReturnUsing(function ($handle) {
			[$sql, $args] = $this->statement((string) $handle);
			if ('SELECT * FROM %i WHERE id = %d' === $sql) {
				$id = (int) $args[1];
				switch ($args[0]) {
					case 'wp_ffc_reregistration_submissions':
						return $this->submissions[$id] ?? null;
					case 'wp_ffc_reregistrations':
						return $this->reregistrations[$id] ?? null;
					case 'wp_ffc_audiences':
						return $this->audiences[$id] ?? null;
				}
			}
			$this->fail('Unexpected get_row(): ' . $sql . ' with ' . var_export($args, true));
		});
		$this->wpdb->shouldReceive('get_col')->andReturnUsing(function ($handle) {
			[$sql, $args] = $this->statement((string) $handle);
			if ('SELECT audience_id FROM %i WHERE reregistration_id = %d' === $sql && 'wp_ffc_reregistration_audiences' === $args[0]) {
				return array_map('strval', $this->campaign_audiences[(int) $args[1]] ?? []);
			}
			$this->fail('Unexpected get_col(): ' . $sql . ' with ' . var_export($args, true));
		});
		$this->wpdb->shouldReceive('get_results')->andReturnUsing(function ($handle) {
			[$sql, $args] = $this->statement((string) $handle);
			if ('SELECT * FROM %i WHERE audience_id = %d AND is_active = 1 ORDER BY sort_order ASC, id ASC' === $sql && 'wp_ffc_custom_fields' === $args[0]) {
				// Fresh objects per read: the reader annotates each row it returns.
				return array_map(static function ($row) {
					return clone $row;
				}, $this->fields_by_audience[(int) $args[1]] ?? []);
			}
			$this->fail('Unexpected get_results(): ' . $sql . ' with ' . var_export($args, true));
		});

		$this->had_wpdb      = array_key_exists('wpdb', $GLOBALS);
		$this->previous_wpdb = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb']     = $this->wpdb;
	}

	protected function tearDown(): void {
		if ($this->had_wpdb) {
			$GLOBALS['wpdb'] = $this->previous_wpdb;
		} else {
			unset($GLOBALS['wpdb']);
		}
		$this->setDecryptFailureCount(0);
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * The statement a `prepare()` handle stands for.
	 *
	 * @param string $handle Value prepare() returned.
	 * @return array{0: string, 1: array<int, mixed>}
	 */
	private function statement(string $handle): array {
		$index = (int) substr($handle, 1);
		if ('' === $handle || 'Q' !== $handle[0] || !isset($this->prepared[$index])) {
			$this->fail('A query ran without prepare(): ' . $handle);
		}
		return $this->prepared[$index];
	}

	/**
	 * Encryption's count of failed decrypts (each attempt that answered null).
	 *
	 * @return int
	 */
	private function decryptFailureCount(): int {
		$ref = new \ReflectionProperty(\FreeFormCertificate\Core\Encryption::class, 'decrypt_failure_count');
		$ref->setAccessible(true);
		return (int) $ref->getValue();
	}

	/**
	 * Set Encryption's count of failed decrypts.
	 *
	 * @param int $count New value.
	 * @return void
	 */
	private function setDecryptFailureCount(int $count): void {
		$ref = new \ReflectionProperty(\FreeFormCertificate\Core\Encryption::class, 'decrypt_failure_count');
		$ref->setAccessible(true);
		$ref->setValue(null, $count);
	}

	/**
	 * Store the campaign, audience, fields and submission a record is built from.
	 *
	 * Campaign 9 is linked to audience 1 (no parent); the submission belongs
	 * to user 7.
	 *
	 * @param object       $submission Submission row (its `id` is the key).
	 * @param object       $rereg      Campaign row (its `id` is the key).
	 * @param list<object> $fields     Active fields of audience 1.
	 * @return void
	 */
	private function storeRecord(object $submission, object $rereg, array $fields): void {
		$this->submissions[(int) $submission->id] = $submission;
		$this->reregistrations[(int) $rereg->id]  = $rereg;
		$this->campaign_audiences[(int) $rereg->id] = $fields ? [1] : [];
		$this->audiences[1]          = (object) ['id' => 1, 'name' => 'Servidores', 'parent_id' => 0];
		$this->fields_by_audience[1] = $fields;
	}

	/**
	 * Invoke a private/protected static method via reflection.
	 *
	 * @param string $method Method name.
	 * @param array  $args   Arguments.
	 * @return mixed
	 */
	private function invokePrivateStatic(string $method, array $args = []) {
		$ref = new \ReflectionMethod(RecordGenerator::class, $method);
		$ref->setAccessible(true);
		return $ref->invokeArgs(null, $args);
	}

	// ==================================================================
	// format_working_hours()
	// ==================================================================

	public function test_format_working_hours_returns_empty_for_empty_string(): void {
		$result = $this->invokePrivateStatic('format_working_hours', ['']);
		$this->assertSame('', $result);
	}

	public function test_format_working_hours_returns_empty_for_empty_json_array(): void {
		$result = $this->invokePrivateStatic('format_working_hours', ['[]']);
		$this->assertSame('', $result);
	}

	public function test_format_working_hours_returns_empty_for_invalid_json(): void {
		$result = $this->invokePrivateStatic('format_working_hours', ['not-json']);
		$this->assertSame('', $result);
	}

	public function test_format_working_hours_renders_table_for_valid_entries(): void {
		$json = json_encode([
			[
				'day'    => 1,
				'entry1' => '08:00',
				'exit1'  => '12:00',
				'entry2' => '13:00',
				'exit2'  => '17:00',
			],
		]);

		$result = $this->invokePrivateStatic('format_working_hours', [$json]);

		$this->assertStringContainsString('<table', $result);
		$this->assertStringContainsString('</table>', $result);
		$this->assertStringContainsString('08:00', $result);
		$this->assertStringContainsString('12:00', $result);
		$this->assertStringContainsString('13:00', $result);
		$this->assertStringContainsString('17:00', $result);
	}

	public function test_format_working_hours_skips_rows_with_empty_entry_and_exit(): void {
		$json = json_encode([
			[
				'day'    => 1,
				'entry1' => '08:00',
				'exit1'  => '12:00',
				'entry2' => '13:00',
				'exit2'  => '17:00',
			],
			[
				'day'    => 2,
				'entry1' => '',
				'exit1'  => '',
				'entry2' => '',
				'exit2'  => '',
			],
		]);

		$result = $this->invokePrivateStatic('format_working_hours', [$json]);

		// Days run across: the day with empty entry1 AND empty exit2 is
		// skipped, so the header carries one day column, not two.
		$this->assertSame(5, substr_count($result, '<tr>'), 'A day row and four time rows.');
		$this->assertSame(1, substr_count($result, '>Mon</th>'));
		$this->assertStringNotContainsString('>Tue</th>', $result);
	}

	public function test_format_working_hours_includes_day_labels(): void {
		$json = json_encode([
			[
				'day'    => 0,
				'entry1' => '09:00',
				'exit1'  => '',
				'entry2' => '',
				'exit2'  => '18:00',
			],
		]);

		$result = $this->invokePrivateStatic('format_working_hours', [$json]);

		// Day 0 = 'Sun' (through returnArg on __)
		$this->assertStringContainsString('Sun', $result);
	}

	public function test_format_working_hours_contains_header_columns(): void {
		$json = json_encode([
			['day' => 1, 'entry1' => '08:00', 'exit1' => '12:00', 'entry2' => '13:00', 'exit2' => '17:00'],
		]);

		$result = $this->invokePrivateStatic('format_working_hours', [$json]);

		// esc_html__ returns first arg, so these header strings should appear.
		$this->assertStringContainsString('Day', $result);
		$this->assertStringContainsString('Entry', $result);
		$this->assertStringContainsString('Lunch Out', $result);
		$this->assertStringContainsString('Lunch In', $result);
		$this->assertStringContainsString('Exit', $result);
	}

	// ==================================================================
	// build_custom_fields_section()
	// ==================================================================

	public function test_build_custom_fields_section_returns_empty_for_no_fields(): void {
		$result = $this->invokePrivateStatic('build_custom_fields_section', [[], []]);
		$this->assertSame('', $result);
	}

	public function test_build_custom_fields_section_renders_text_field(): void {
		$field = (object) [
			'id'          => 1,
			'field_key'   => 'hobby',
			'field_label' => 'Hobby',
			'field_type'  => 'text',
		];

		$values = ['hobby' => 'Reading'];

		$result = $this->invokePrivateStatic('build_custom_fields_section', [[$field], $values]);

		$this->assertStringContainsString('Additional Information', $result);
		$this->assertStringContainsString('Hobby', $result);
		$this->assertStringContainsString('Reading', $result);
		$this->assertStringContainsString('<table', $result);
	}

	public function test_build_custom_fields_section_formats_checkbox_yes(): void {
		$field = (object) [
			'id'          => 2,
			'field_key'   => 'agreed',
			'field_label' => 'Agreed',
			'field_type'  => 'checkbox',
		];

		$values = ['agreed' => '1'];

		$result = $this->invokePrivateStatic('build_custom_fields_section', [[$field], $values]);

		// __ returns first arg, so 'Yes' should appear.
		$this->assertStringContainsString('Yes', $result);
	}

	public function test_build_custom_fields_section_formats_checkbox_no(): void {
		$field = (object) [
			'id'          => 2,
			'field_key'   => 'agreed',
			'field_label' => 'Agreed',
			'field_type'  => 'checkbox',
		];

		$values = ['agreed' => '0'];

		$result = $this->invokePrivateStatic('build_custom_fields_section', [[$field], $values]);

		$this->assertStringContainsString('No', $result);
	}

	public function test_build_custom_fields_section_formats_dependent_select(): void {
		$field = (object) [
			'id'          => 3,
			'field_key'   => 'location',
			'field_label' => 'Location',
			'field_type'  => 'dependent_select',
		];

		$dep_json = json_encode(['parent' => 'State', 'child' => 'City']);
		$values = ['location' => $dep_json];

		$result = $this->invokePrivateStatic('build_custom_fields_section', [[$field], $values]);

		$this->assertStringContainsString('State - City', $result);
	}

	public function test_build_custom_fields_section_formats_working_hours(): void {
		$field = (object) [
			'id'          => 4,
			'field_key'   => 'schedule',
			'field_label' => 'Schedule',
			'field_type'  => 'working_hours',
		];

		$wh_json = json_encode([
			['day' => 1, 'entry1' => '08:00', 'exit1' => '12:00', 'entry2' => '13:00', 'exit2' => '17:00'],
		]);
		$values = ['schedule' => $wh_json];

		$result = $this->invokePrivateStatic('build_custom_fields_section', [[$field], $values]);

		$this->assertStringContainsString('Mon', $result);
		$this->assertStringContainsString('08:00', $result);
	}

	public function test_build_custom_fields_section_handles_missing_value(): void {
		$field = (object) [
			'id'          => 5,
			'field_key'   => 'notes',
			'field_label' => 'Notes',
			'field_type'  => 'textarea',
		];

		// No value for "notes"
		$values = [];

		$result = $this->invokePrivateStatic('build_custom_fields_section', [[$field], $values]);

		$this->assertStringContainsString('Notes', $result);
		// The empty value should still produce a row.
		$this->assertStringContainsString('<tr>', $result);
	}

	// ==================================================================
	// build_standard_field_variables()
	// ==================================================================

	public function test_build_standard_field_variables_splits_dependent_select_into_parent_child(): void {
		$field = (object) [
			'id'         => 1,
			'field_key'  => 'divisao_setor',
			'field_type' => 'dependent_select',
		];

		$values = ['divisao_setor' => json_encode(['parent' => 'DRE - DIAF', 'child' => 'Contabilidade'])];

		$vars = $this->invokePrivateStatic('build_standard_field_variables', [[$field], $values, false]);

		// Combined value stays available for back-compat templates.
		$this->assertSame('DRE - DIAF - Contabilidade', $vars['divisao_setor']);
		// Split halves drive the two separate cells in the default record.
		$this->assertSame('DRE - DIAF', $vars['divisao_setor_parent']);
		$this->assertSame('Contabilidade', $vars['divisao_setor_child']);
	}

	public function test_build_standard_field_variables_dependent_select_empty_when_value_missing(): void {
		$field = (object) [
			'id'         => 1,
			'field_key'  => 'divisao_setor',
			'field_type' => 'dependent_select',
		];

		$vars = $this->invokePrivateStatic('build_standard_field_variables', [[$field], [], false]);

		$this->assertSame('', $vars['divisao_setor']);
		$this->assertSame('', $vars['divisao_setor_parent']);
		$this->assertSame('', $vars['divisao_setor_child']);
	}

	public function test_build_standard_field_variables_hides_accumulation_fields_without_second_post(): void {
		$field = (object) [
			'id'         => 2,
			'field_key'  => 'jornada_acumulo',
			'field_type' => 'text',
		];

		$values = ['jornada_acumulo' => '40h'];

		$vars = $this->invokePrivateStatic('build_standard_field_variables', [[$field], $values, false]);

		$this->assertSame('', $vars['jornada_acumulo']);
	}

	public function test_build_standard_field_variables_keeps_accumulation_fields_with_second_post(): void {
		$field = (object) [
			'id'         => 2,
			'field_key'  => 'jornada_acumulo',
			'field_type' => 'text',
		];

		$values = ['jornada_acumulo' => '40h'];

		$vars = $this->invokePrivateStatic('build_standard_field_variables', [[$field], $values, true]);

		$this->assertSame('40h', $vars['jornada_acumulo']);
	}

	public function test_build_standard_field_variables_passes_through_plain_field(): void {
		$field = (object) [
			'id'         => 3,
			'field_key'  => 'sindicato',
			'field_type' => 'text',
		];

		$values = ['sindicato' => 'SINPEEM'];

		$vars = $this->invokePrivateStatic('build_standard_field_variables', [[$field], $values, false]);

		$this->assertSame('SINPEEM', $vars['sindicato']);
		$this->assertArrayNotHasKey('sindicato_parent', $vars);
	}

	// ==================================================================
	// resolve_acknowledgment_html()
	// ==================================================================

	public function test_resolve_acknowledgment_html_uses_field_options(): void {
		Functions\when('wp_kses_post')->returnArg();

		$field = (object) [
			'field_type'    => 'acknowledgment',
			'field_options' => json_encode(['html' => '<p>Custom notice</p>']),
		];

		$result = $this->invokePrivateStatic('resolve_acknowledgment_html', [[$field]]);

		$this->assertStringContainsString('Custom notice', $result);
	}

	public function test_resolve_acknowledgment_html_falls_back_to_default_when_no_field(): void {
		Functions\when('wp_kses_post')->returnArg();

		$result = $this->invokePrivateStatic('resolve_acknowledgment_html', [[]]);

		// The shipped default notice mentions these programmes.
		$this->assertStringContainsString('SISPATRI', $result);
		$this->assertStringNotContainsString('<ol>', $result, 'The list is drawn as numbered rows.');
		$this->assertStringContainsString('>6-</td>', $result);
	}

	public function test_resolve_acknowledgment_html_falls_back_when_field_html_empty(): void {
		Functions\when('wp_kses_post')->returnArg();

		$field = (object) [
			'field_type'    => 'acknowledgment',
			'field_options' => json_encode(['html' => '']),
		];

		$result = $this->invokePrivateStatic('resolve_acknowledgment_html', [[$field]]);

		$this->assertStringContainsString('SISPATRI', $result);
	}

	// ==================================================================
	// load_template()
	// ==================================================================

	public function test_load_template_returns_fallback_when_file_missing(): void {
		// Make the filter return a path that does not exist.
		Functions\when('apply_filters')->alias(function ($tag, $value) {
			if ($tag === 'ffcertificate_record_template_file') {
				return '/tmp/nonexistent_template_file.html';
			}
			return $value;
		});

		$result = $this->invokePrivateStatic('load_template', []);

		// Fallback template contains these markers.
		$this->assertStringContainsString('Reregistration Record', $result);
		$this->assertStringContainsString('{{display_name}}', $result);
		$this->assertStringContainsString('{{email}}', $result);
		$this->assertStringContainsString('{{reregistration_title}}', $result);
		$this->assertStringContainsString('{{submission_status}}', $result);
		$this->assertStringContainsString('{{custom_fields_section}}', $result);
	}

	public function test_load_template_returns_real_template_when_file_exists(): void {
		// Let the filter pass through the real path.
		Functions\when('apply_filters')->returnArg(2);

		$result = $this->invokePrivateStatic('load_template', []);

		// The real template should have standard placeholders.
		$this->assertStringContainsString('{{display_name}}', $result);
		$this->assertStringContainsString('{{custom_fields_section}}', $result);
		$this->assertNotEmpty($result);

		// Divisão/Setor must use the split dependent_select placeholders,
		// never the stale {{divisao}} / {{setor}} names the generator never emits.
		$this->assertStringContainsString('{{divisao_setor_parent}}', $result);
		$this->assertStringContainsString('{{divisao_setor_child}}', $result);
		$this->assertStringNotContainsString('{{divisao}}', $result);
		$this->assertStringNotContainsString('{{setor}}', $result);

		// The acknowledgment block is injected via the placeholder now, not
		// hardcoded in the template.
		$this->assertStringContainsString('{{termo_ciencia}}', $result);
		$this->assertStringNotContainsString('Declaração de Família WEB', $result);
	}

	// ==================================================================
	// format_field_value() — remaining branches
	// ==================================================================

	public function test_format_field_value_default_array_joins_with_comma(): void {
		$field = (object) ['field_type' => 'multiselect'];
		$result = RecordGenerator::format_field_value($field, ['A', 'B', 'C']);
		$this->assertSame('A, B, C', $result);
	}

	public function test_format_field_value_default_non_scalar_returns_empty(): void {
		$field = (object) ['field_type' => 'text'];
		$result = RecordGenerator::format_field_value($field, (object) ['x' => 1]);
		$this->assertSame('', $result);
	}

	public function test_format_field_value_dependent_select_non_array_returns_empty(): void {
		$field = (object) ['field_type' => 'dependent_select'];
		// A plain string that is not JSON decodes to null → not an array.
		$result = RecordGenerator::format_field_value($field, 'plain-string');
		$this->assertSame('', $result);
	}

	// ==================================================================
	// decrypt_field_values()
	// ==================================================================

	public function test_decrypt_field_values_passes_through_non_sensitive(): void {
		$field  = (object) ['field_key' => 'hobby', 'is_sensitive' => 0];
		$values = ['hobby' => 'Reading'];
		$result = RecordGenerator::decrypt_field_values([$field], $values);
		$this->assertSame('Reading', $result['hobby']);
	}

	public function test_decrypt_field_values_skips_empty_or_non_string(): void {
		$fields = [
			(object) ['field_key' => 'a', 'is_sensitive' => 1],
			(object) ['field_key' => 'b', 'is_sensitive' => 1],
			(object) ['field_key' => 'c', 'is_sensitive' => 1],
		];
		$values = ['a' => '', 'b' => ['x'], 'c' => null];
		$result = RecordGenerator::decrypt_field_values($fields, $values);
		// Untouched — decryption skipped for empty / non-string values.
		$this->assertSame('', $result['a']);
		$this->assertSame(['x'], $result['b']);
		$this->assertNull($result['c']);
	}

	public function test_decrypt_field_values_decrypts_sensitive(): void {
		$cipher = (string) \FreeFormCertificate\Core\Encryption::encrypt('plain-cpf');

		$field  = (object) ['field_key' => 'cpf', 'is_sensitive' => 1];
		$values = ['cpf' => $cipher];
		$result = RecordGenerator::decrypt_field_values([$field], $values);

		$this->assertSame('plain-cpf', $result['cpf']);
	}

	public function test_decrypt_field_values_keeps_original_when_decrypt_returns_null(): void {
		// Envelope-shaped (v2 prefix, HMAC + IV + one block) but signed by no
		// key: the reader attempts it and decrypt() answers null.
		$cipher = 'v2:' . base64_encode(str_repeat('A', 64));

		$field  = (object) ['field_key' => 'cpf', 'is_sensitive' => 1];
		$values = ['cpf' => $cipher];
		$result = RecordGenerator::decrypt_field_values([$field], $values);

		$this->assertSame($cipher, $result['cpf']);
		$this->assertSame(1, $this->decryptFailureCount(), 'The envelope must have been attempted and refused.');
	}

	// ------------------------------------------------------------------
	// #1509: the flag changed AFTER the write, in both directions.
	//
	// `is_sensitive` is admin-editable and read live, while the value in the row
	// was written under whatever it said then — and nothing records which. The
	// reader must therefore decide from the VALUE. These two tests are the whole
	// point of that change, so they drive the flag and the value INDEPENDENTLY:
	// each passes a flag that contradicts the data.
	// ------------------------------------------------------------------

	/**
	 * ON then OFF — the damaging direction.
	 *
	 * The row holds ciphertext because the field was sensitive when it was
	 * written; the flag has since been turned off. The old reader skipped it on
	 * the flag and returned the CIPHERTEXT, which the CSV export, the generated
	 * record and the public verification page then presented as the person's
	 * CPF. Nothing failed and nothing was logged.
	 */
	public function test_decrypt_field_values_decrypts_a_row_whose_field_is_no_longer_sensitive(): void {
		$envelope = (string) \FreeFormCertificate\Core\Encryption::encrypt(Identifiers::CPF_A);

		// The flag says NOT sensitive. The value says otherwise, and the value wins.
		$field  = (object) ['field_key' => 'cpf', 'is_sensitive' => 0];
		$values = ['cpf' => $envelope];

		$result = RecordGenerator::decrypt_field_values([$field], $values);

		$this->assertSame(
			Identifiers::CPF_A,
			$result['cpf'],
			'A stored envelope must be decrypted whatever the flag currently says — otherwise the ciphertext is exported as the CPF.'
		);
	}

	/**
	 * OFF then ON — the noisy direction, and the assertion is about what is NOT called.
	 *
	 * The row holds plaintext because the field was plain when it was written;
	 * the flag has since been turned on. The output was always right here (the
	 * `null !==` guard kept the plaintext), but every read handed plaintext to
	 * `decrypt()` and logged a `decrypt_failure` per field per request — #1441's
	 * noise. The failure count is the real assertion: the attempt must not
	 * happen at all, and a formatted CPF handed to `decrypt()` could only
	 * fail and be counted (the test above shows the count moving).
	 */
	public function test_decrypt_field_values_never_attempts_a_plaintext_row_whose_field_became_sensitive(): void {
		// The flag says sensitive. The value is a formatted CPF, which no
		// `encrypt()` ever produced.
		$field  = (object) ['field_key' => 'cpf', 'is_sensitive' => 1];
		$values = ['cpf' => self::mask_cpf( Identifiers::CPF_A )];

		$result = RecordGenerator::decrypt_field_values([$field], $values);

		$this->assertSame(self::mask_cpf( Identifiers::CPF_A ), $result['cpf'], 'A plaintext value must survive untouched.');
		$this->assertSame(0, $this->decryptFailureCount(), 'A plaintext value was handed to decrypt().');
	}

	// ==================================================================
	// get_custom_fields_for_reregistration()
	// ==================================================================

	public function test_get_custom_fields_for_reregistration_dedupes_by_id(): void {
		// Campaign 5 is linked to audiences 1 and 2 (ReregistrationRepository::
		// get_audience_ids), and CustomFieldReader::get_by_audience_with_parents
		// returns field id 10 for both. It must be collapsed to a single entry.
		$this->campaign_audiences[5] = [1, 2];
		$this->audiences[1]          = (object) ['id' => 1, 'name' => 'Aud', 'parent_id' => 0];
		$this->audiences[2]          = (object) ['id' => 2, 'name' => 'Aud 2', 'parent_id' => 0];
		$shared                      = (object) ['id' => 10, 'field_key' => 'shared', 'field_type' => 'text'];
		$this->fields_by_audience[1] = [$shared];
		$this->fields_by_audience[2] = [$shared];

		$rereg  = (object) ['id' => 5];
		$fields = RecordGenerator::get_custom_fields_for_reregistration($rereg);

		$this->assertCount(1, $fields);
		$this->assertSame(10, (int) $fields[0]->id);
	}

	// ==================================================================
	// generate_record_data() — early returns
	// ==================================================================

	public function test_generate_record_data_returns_null_when_submission_missing(): void {
		// No submission row is stored.
		$this->assertNull(RecordGenerator::generate_record_data(1));
		$this->assertSame(['wp_ffc_reregistration_submissions'], $this->tablesRead(), 'It must stop after the submission lookup.');
	}

	public function test_generate_record_data_returns_null_when_rereg_missing(): void {
		// The submission points at campaign 9, which is not stored.
		$this->submissions[1] = (object) ['id' => 1, 'reregistration_id' => 9, 'user_id' => 7];

		$this->assertNull(RecordGenerator::generate_record_data(1));
		$this->assertSame(
			['wp_ffc_reregistration_submissions', 'wp_ffc_reregistrations'],
			$this->tablesRead(),
			'It must stop after the campaign lookup.'
		);
	}

	public function test_generate_record_data_returns_null_when_user_missing(): void {
		$users = [];
		Functions\when('get_userdata')->alias(static function ($id) use (&$users) {
			$users[] = $id;
			return false;
		});

		$this->submissions[1]     = (object) ['id' => 1, 'reregistration_id' => 9, 'user_id' => 7];
		$this->reregistrations[9] = (object) ['id' => 9, 'title' => 'C'];

		$this->assertNull(RecordGenerator::generate_record_data(1));
		$this->assertSame([7], $users, 'The submission\'s user is the one looked up.');
		$this->assertSame(
			['wp_ffc_reregistration_submissions', 'wp_ffc_reregistrations'],
			$this->tablesRead(),
			'Nothing past the user lookup may run.'
		);
	}

	/**
	 * The table each prepared statement named, in order.
	 *
	 * @return list<string>
	 */
	private function tablesRead(): array {
		return array_map(static function (array $statement): string {
			return (string) ($statement[1][0] ?? '');
		}, $this->prepared);
	}

	// ==================================================================
	// generate_record_data() — happy path
	// ==================================================================

	public function test_generate_record_data_builds_full_payload(): void {
		Functions\when('__')->returnArg();
		Functions\when('esc_html__')->returnArg();
		Functions\when('esc_html')->returnArg();
		Functions\when('esc_attr')->returnArg();
		Functions\when('_x')->returnArg();
		Functions\when('wp_kses')->alias(function ($v) { return $v; });
		Functions\when('wp_kses_post')->returnArg();
		Functions\when('get_bloginfo')->justReturn('My Site');
		Functions\when('get_home_url')->justReturn('https://example.test');
		Functions\when('untrailingslashit')->alias(function ($u) { return rtrim($u, '/'); });
		Functions\when('sanitize_file_name')->alias(function ($n) {
			return preg_replace('/[^a-zA-Z0-9_\-.]/', '_', $n);
		});
		Functions\when('apply_filters')->alias(function ($tag, $value) {
			// Force the fallback template so no filesystem read is needed and
			// the placeholder replacement loop runs against known markers.
			if ($tag === 'ffcertificate_record_template_file') {
				return '/tmp/ffc-nonexistent-template.html';
			}
			return $value;
		});

		$submission = (object) [
			'id'                => 42,
			'reregistration_id' => 9,
			'user_id'           => 7,
			'data'              => json_encode(['fields' => ['display_name' => 'João', 'hobby' => 'Chess']]),
			'status'            => 'approved',
			'submitted_at'      => 1700000000,
			'auth_code'         => 'ABCD1234',
		];
		$rereg = (object) [
			'id'            => 9,
			'title'         => 'Recadastramento 2026',
			'audience_name' => 'Servidores',
			'start_date'    => '2026-02-15 00:00:00',
		];

		$this->storeRecord($submission, $rereg, [
			(object) ['id' => 100, 'field_key' => 'display_name', 'field_label' => 'Name', 'field_type' => 'text', 'field_source' => 'standard', 'is_sensitive' => 0],
			(object) ['id' => 101, 'field_key' => 'hobby', 'field_label' => 'Hobby', 'field_type' => 'text', 'field_source' => 'custom', 'is_sensitive' => 0],
		]);

		$user = (object) [
			'ID'           => 7,
			'display_name' => 'João Silva',
			'user_email'   => 'joao@example.test',
		];
		Functions\when('get_userdata')->justReturn($user);

		$result = RecordGenerator::generate_record_data(42);

		$this->assertIsArray($result);
		$this->assertSame('ficha', $result['type']);
		$this->assertSame('portrait', $result['orientation']);
		// FilenameHelper prefixes a real auth code with the record letter.
		$this->assertSame('record_9_R-ABCD1234.pdf', $result['filename']);
		$this->assertSame('joao@example.test', $result['user']['email']);
		$this->assertSame(7, $result['user']['id']);
		// Fallback template markers were replaced with the participant data.
		$this->assertStringContainsString('João', $result['html']);
		$this->assertStringContainsString('Approved', $result['html']);
		// The custom (non-standard) 'hobby' field renders in the section.
		$this->assertStringContainsString('Chess', $result['html']);
		// Relative URLs are rewritten to absolute (site url injected).
		$this->assertStringNotContainsString('{{display_name}}', $result['html']);
	}

	/**
	 * Approved: the footer carries the code in the plugin's format.
	 *
	 * It charges the composed VALUE, not the key's presence: `format_auth_code`
	 * hyphenates a 12-character code into groups of 4 and prefixes it with
	 * `R-`, which is the format of the invitation email and of the certificate.
	 * The variables are read through the `ffcertificate_record_data` filter,
	 * which is how the template will receive them.
	 */
	public function test_generate_record_data_composes_the_auth_code_line_when_approved(): void {
		$captured = $this->runRecordWithStatus('approved', 'MA6DE5LHPFTC');

		$this->assertSame('R-MA6D-E5LH-PFTC', $captured['auth_code']);
		$this->assertSame('Authentication: R-MA6D-E5LH-PFTC / ', $captured['auth_code_line']);
	}

	/**
	 * Not approved: no authentication line.
	 *
	 * The `auth_code` is born on the status transition, so `submitted` and
	 * `draft` have no code. The whole line has to come out EMPTY -- if only the
	 * code were empty, the default template would render "Authentication:  /".
	 */
	public function test_generate_record_data_omits_the_auth_code_line_when_not_approved(): void {
		$captured = $this->runRecordWithStatus('submitted', 'MA6DE5LHPFTC');

		$this->assertSame('', $captured['auth_code'], 'An unapproved submission publishes no code.');
		$this->assertSame('', $captured['auth_code_line'], 'No code, no fragment -- no "Authentication:  /".');
	}

	/**
	 * The default template really does consume the placeholder, before
	 * "Preenchido em".
	 *
	 * The half above proves the composition; this one proves the WIRING.
	 * Without it, the first two would pass with the template ignoring the
	 * variable.
	 *
	 * @return void
	 */
	public function test_the_default_ficha_template_prints_the_auth_code_line(): void {
		$template = (string) file_get_contents(
			dirname(__DIR__, 2) . '/templates/documents/default_ficha_template.html'
		);

		$this->assertStringContainsString('{{auth_code_line}}', $template);
		$this->assertMatchesRegularExpression(
			'/\{\{auth_code_line\}\}\s*Preenchido em: \{\{submitted_at\}\}/',
			$template,
			'The fragment must come immediately before "Preenchido em".'
		);
	}

	/**
	 * Runs `generate_record_data` capturing the template's variables.
	 *
	 * @param string $status    Submission status.
	 * @param string $auth_code Raw code stored on the row.
	 * @return array<string, mixed> Variables handed to the template.
	 */
	private function runRecordWithStatus(string $status, string $auth_code, ?array &$seen = null, ?array $fields = null, ?array $values = null): array {
		Functions\when('__')->returnArg();
		Functions\when('esc_html__')->returnArg();
		Functions\when('esc_html')->returnArg();
		Functions\when('esc_attr')->returnArg();
		Functions\when('_x')->returnArg();
		Functions\when('wp_kses')->alias(function ($v) { return $v; });
		Functions\when('wp_kses_post')->returnArg();
		Functions\when('get_bloginfo')->justReturn('My Site');
		Functions\when('get_home_url')->justReturn('https://example.test');
		Functions\when('untrailingslashit')->alias(function ($u) { return rtrim($u, '/'); });
		Functions\when('sanitize_file_name')->alias(function ($n) {
			return preg_replace('/[^a-zA-Z0-9_\-.]/', '_', $n);
		});

		$captured = array();

		// `$seen` is how a caller observes the hooks, because THIS stub would
		// silently replace one the caller declared first: `Functions\when()`
		// defines the function, and the last definition wins.
		$seen = array();
		Functions\when('apply_filters')->alias(function ($tag, $value) use (&$captured, &$seen) {
			$seen[ $tag ] = $value;
			if ('ffcertificate_record_template_file' === $tag) {
				return '/tmp/ffc-nonexistent-template.html';
			}
			if ('ffcertificate_record_data' === $tag) {
				$captured = $value;
			}
			return $value;
		});

		$submission = (object) array(
			'id'                => 42,
			'reregistration_id' => 9,
			'user_id'           => 7,
			'data'              => json_encode(array('fields' => $values ?? array('display_name' => 'João'))),
			'status'            => $status,
			'submitted_at'      => 1700000000,
			'auth_code'         => $auth_code,
		);
		$rereg = (object) array(
			'id'            => 9,
			'title'         => 'Recadastramento 2026',
			'audience_name' => 'Servidores',
			'start_date'    => '2026-02-15 00:00:00',
		);

		$this->storeRecord($submission, $rereg, $fields ?? array(
			(object) array('id' => 100, 'field_key' => 'display_name', 'field_label' => 'Name', 'field_type' => 'text', 'field_source' => 'standard', 'is_sensitive' => 0),
		));

		Functions\when('get_userdata')->justReturn((object) array(
			'ID'           => 7,
			'display_name' => 'João Silva',
			'user_email'   => 'joao@example.test',
		));

		RecordGenerator::generate_record_data(42);

		return $captured;
	}

	/**
	 * A standard field for the record tests below.
	 */
	private function standardField(int $id, string $key, string $type = 'text', string $mask = ''): object {
		return (object) array('id' => $id, 'field_key' => $key, 'field_label' => $key, 'field_type' => $type, 'field_source' => 'standard', 'field_mask' => $mask, 'is_sensitive' => 0);
	}

	public function test_the_second_post_block_is_composed_only_when_one_is_held(): void {
		$fields = array(
			$this->standardField(1, 'acumulo_cargos', 'select'),
			$this->standardField(2, 'jornada_acumulo', 'select'),
			$this->standardField(3, 'cargo_funcao_acumulo'),
		);
		$values = array('acumulo_cargos' => 'I hold', 'jornada_acumulo' => 'JB - 20h/a', 'cargo_funcao_acumulo' => 'Teacher');

		$seen = null;
		$held = $this->runRecordWithStatus('approved', 'MA6DE5LHPFTC', $seen, $fields, $values);
		$this->assertStringContainsString('Workload:', $held['acumulo_details']);
		$this->assertStringContainsString('JB - 20h/a', $held['acumulo_details']);
		$this->assertStringContainsString('Teacher', $held['acumulo_details']);

		$values['acumulo_cargos'] = 'I do not hold';
		$none = $this->runRecordWithStatus('approved', 'MA6DE5LHPFTC', $seen, $fields, $values);
		$this->assertSame('', $none['acumulo_details'], 'No second post, no Workload and Post/Role rows.');
	}

	public function test_the_edu_email_is_its_own_line_and_the_sme_one_no_longer_borrows_the_account(): void {
		$seen     = null;
		$captured = $this->runRecordWithStatus('approved', 'MA6DE5LHPFTC', $seen, array($this->standardField(1, 'email_institucional', 'text', 'email')), array('email_institucional' => ''));

		$this->assertSame('', $captured['email_institucional'], 'The account e-mail is not printed as the @sme one.');
		$this->assertSame('', $captured['email_institucional_edu'], 'A campaign without the field still fills the placeholder.');
	}

	public function test_the_default_template_reads_the_new_placeholders(): void {
		$template = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/documents/default_ficha_template.html');

		foreach (array('{{acumulo_details}}', '{{email_institucional_edu}}', '{{endereco_complemento}}', 'CIN/CPF:', 'RG:') as $needle) {
			$this->assertStringContainsString($needle, $template);
		}
		$this->assertStringNotContainsString('{{jornada_acumulo}}', $template, 'The second-post rows come composed, so they can vanish.');
		$this->assertStringNotContainsString('{{endereco_numero}} {{endereco_complemento}}', $template, 'Number and complement have a cell each.');
	}

	/**
	 * @dataProvider provideDisplayMasks
	 */
	public function test_build_standard_field_variables_prints_documents_in_their_usual_shape(string $mask, string $stored, string $printed): void {
		$vars = $this->invokePrivateStatic('build_standard_field_variables', [[$this->standardField(1, 'doc', 'text', $mask)], ['doc' => $stored], false]);

		$this->assertSame($printed, $vars['doc']);
	}

	public function provideDisplayMasks(): array {
		return array(
			'cpf bare'          => array('cpf', '52998224725', '529.982.247-25'),
			'cpf already masked' => array('cpf', '529.982.247-25', '529.982.247-25'),
			'rf'                => array('rf', '8026335', '802.633-5'),
			'rg'                => array('cin', '279047058', '27.904.705-8'),
			'rg with an X'      => array('cin', '27904705x', '27.904.705-X'),
			'cep'               => array('cep', '08615070', '08615-070'),
			'unknown length'    => array('cpf', '123', '123'),
			'no mask'           => array('', '52998224725', '52998224725'),
		);
	}

	public function test_format_field_value_prints_a_date_in_the_pdf_format(): void {
		Functions\when('get_option')->justReturn(array('date_format' => 'd/m/Y', 'date_format_pdf' => 'd-m-Y'));
		Functions\when('wp_date')->alias(static fn($format, $ts = null) => gmdate($format, $ts ?? time()));
		Functions\when('wp_timezone')->alias(static fn() => new \DateTimeZone('UTC'));
		$field = $this->standardField(1, 'data_nascimento', 'date');

		$this->assertSame('12-10-1985', RecordGenerator::format_field_value($field, '1985-10-12'));
		$this->assertSame('not a date', RecordGenerator::format_field_value($field, 'not a date'), 'A value that is not a date is printed as stored.');
	}

	public function test_number_list_items_numbers_every_item_with_a_dash(): void {
		$html = RecordGenerator::number_list_items('<p>Intro</p><ol><li><strong>A:</strong> one</li><li>two</li></ol>');

		$this->assertStringStartsWith('<p>Intro</p><table', $html);
		$this->assertStringNotContainsString('<ol', $html);
		$this->assertStringContainsString('>1-</td><td style="padding:0 0 2px;vertical-align:top"><strong>A:</strong> one</td>', $html);
		$this->assertStringContainsString('>2-</td>', $html);
		$this->assertSame('<p>No list</p>', RecordGenerator::number_list_items('<p>No list</p>'));
	}

	public function test_format_working_hours_puts_days_in_columns_and_names_the_lunch_break(): void {
		$json = json_encode(array(array('day' => 1, 'entry1' => '08:00', 'exit1' => '12:00', 'entry2' => '13:00', 'exit2' => '17:00')));

		$html = $this->invokePrivateStatic('format_working_hours', [$json]);

		$this->assertMatchesRegularExpression('#>Lunch Out</th><td[^>]*>12:00</td>#', $html, 'Lunch Out is the time the break starts.');
		$this->assertMatchesRegularExpression('#>Lunch In</th><td[^>]*>13:00</td>#', $html);
	}

	public function test_generate_record_data_uses_synthetic_code_without_auth_code(): void {
		Functions\when('__')->returnArg();
		Functions\when('esc_html__')->returnArg();
		Functions\when('esc_html')->returnArg();
		Functions\when('_x')->returnArg();
		Functions\when('wp_kses')->alias(function ($v) { return $v; });
		Functions\when('wp_kses_post')->returnArg();
		Functions\when('get_bloginfo')->justReturn('Site');
		Functions\when('get_home_url')->justReturn('https://e.test');
		Functions\when('untrailingslashit')->alias(function ($u) { return rtrim($u, '/'); });
		Functions\when('sanitize_file_name')->alias(function ($n) { return $n; });
		$captured_code = null;
		Functions\when('apply_filters')->alias(function ($tag, $value, ...$rest) use (&$captured_code) {
			if ($tag === 'ffcertificate_record_template_file') {
				return '/tmp/ffc-nonexistent-template.html';
			}
			if ('ffcertificate_pdf_filename' === $tag) {
				// FilenameHelper's own filter carries the code it used.
				$captured_code = $rest[2] ?? null;
			}
			return $value;
		});

		// No auth_code, no submitted_at, no start_date → exercises the
		// synthetic `S{id}` fallback + the empty submitted_at / reference_year
		// branches. `draft` is a status ReregistrationSubmissionReader has no
		// label for (the reader's list is real now, and labels `submitted`).
		$submission = (object) [
			'id'                => 55,
			'reregistration_id' => 9,
			'user_id'           => 7,
			'data'              => null,
			'status'            => 'draft',
			'submitted_at'      => 0,
			'auth_code'         => '',
		];
		$rereg = (object) ['id' => 9, 'title' => 'C', 'start_date' => ''];

		$this->storeRecord($submission, $rereg, []);

		Functions\when('get_userdata')->justReturn((object) [
			'ID'           => 7,
			'display_name' => 'Ana',
			'user_email'   => 'ana@e.test',
		]);

		$result = RecordGenerator::generate_record_data(55);

		$this->assertIsArray($result);
		// Status with no label falls back to the raw status key.
		$this->assertStringContainsString('<strong>Status:</strong> draft', $result['html']);
		// Synthetic code S{submission_id} is used when auth_code is empty, and
		// it is not dressed as an `R-` auth code in the filename.
		$this->assertSame('S55', $captured_code);
		$this->assertSame('record_9_S55.pdf', $result['filename']);
	}

	// ==================================================================
	// The 6.26.0 hook rename, whose cycle closed in 6.28.0 (#1264)
	// ==================================================================

	/**
	 * The two template hooks fire under their new names and nothing else.
	 *
	 * These used to assert the OPPOSITE -- that the old `ffcertificate_ficha_*`
	 * names still reached a listener through `apply_filters_deprecated()`. The
	 * cycle closed in 6.28.0, so the assertion inverts: an alias reappearing
	 * would mean the removal was reverted, and a new name that stopped firing
	 * would mean the removal took the live hook with it.
	 *
	 * IT CHARGES THE VALUE, NOT ONLY THE NAME
	 *
	 * Removing an alias is not a deletion here. The deprecated call SEEDED the
	 * variable the live filter then received -- `apply_filters_deprecated( …,
	 * array( '' ) )` feeding `$pool_html` -- so deleting the statement without
	 * moving its seed into the survivor leaves an undefined variable. Two of
	 * the five were that shape, and only an assertion on the value can tell a
	 * hook that fires with the right input from one that fires with nothing.
	 *
	 * @return void
	 */
	public function test_the_template_hooks_fire_under_their_new_names_only(): void {
		$deprecated = array();
		$seen       = array();

		Functions\when('apply_filters_deprecated')->alias(
			function ($tag, $args) use (&$deprecated) {
				$deprecated[] = $tag;
				return $args[0];
			}
		);
		Functions\when('apply_filters')->alias(function ($tag, $value) use (&$seen) {
			$seen[ $tag ] = $value;
			if ('ffcertificate_record_template_file' === $tag) {
				return '/tmp/ffc-nonexistent-template.html';
			}
			return $value;
		});

		$this->invokePrivateStatic('load_template', []);

		$this->assertSame(array(), $deprecated, 'A deprecated alias is back -- the 6.28.0 removal was reverted.');
		$this->assertArrayHasKey('ffcertificate_record_template_html', $seen, 'The pool-resolver hook stopped firing.');
		$this->assertSame('', $seen['ffcertificate_record_template_html'], 'It must receive the empty default the removed alias used to seed.');
		$this->assertArrayHasKey('ffcertificate_record_template_file', $seen, 'The template-file hook stopped firing.');
		$this->assertStringContainsString('default_ficha_template.html', (string) $seen['ffcertificate_record_template_file']);
	}

	/**
	 * The three generation hooks likewise, with the same value check.
	 *
	 * Separate from the pair above because these live inside
	 * `generate_record_data()`, which needs the whole fixture: covering all
	 * five in one test would mean the cheap two only run when the expensive
	 * three do.
	 *
	 * @return void
	 */
	public function test_the_generation_hooks_fire_under_their_new_names_only(): void {
		$deprecated = array();
		$seen       = array();

		Functions\when('apply_filters_deprecated')->alias(
			function ($tag, $args) use (&$deprecated) {
				$deprecated[] = $tag;
				return $args[0];
			}
		);

		// The hooks are observed through the fixture, not through a stub
		// declared here: `runRecordWithStatus()` defines its own
		// `apply_filters` and would replace one this test set up first.
		$this->runRecordWithStatus('approved', 'MA6DE5LHPFTC', $seen);

		$this->assertSame(array(), $deprecated, 'A deprecated alias is back -- the 6.28.0 removal was reverted.');

		foreach (array('data', 'html', 'filename') as $hook) {
			$this->assertArrayHasKey("ffcertificate_record_{$hook}", $seen, "The `{$hook}` hook stopped firing.");
		}

		// `record_html` is the second seeding case: its removed alias supplied
		// `$template`, and the live filter now takes it directly.
		$this->assertNotSame('', (string) $seen['ffcertificate_record_html'], 'The HTML hook fired with nothing -- the removed alias was seeding it.');
		$this->assertIsArray($seen['ffcertificate_record_data']);
	}

	public function test_default_document_templates_relocated_out_of_html(): void {
		// #865: the record + appointment-receipt defaults moved out of the
		// update-fragile html/ folder to the versioned templates/documents/.
		$root = dirname(__DIR__, 2);
		$this->assertFileExists($root . '/templates/documents/default_ficha_template.html');
		$this->assertFileExists($root . '/templates/documents/default_appointment_receipt_1.html');
		$this->assertFileDoesNotExist($root . '/html/default_ficha_template.html');
		$this->assertFileDoesNotExist($root . '/html/default_appointment_receipt_1.html');
	}
}
