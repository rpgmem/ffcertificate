<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Reregistration\FichaGenerator;

/**
 * Tests for FichaGenerator: working-hours formatting, custom-fields section
 * building, template loading, and ficha data generation.
 *
 * Uses ReflectionMethod to access private static helpers directly.
 *
 * @covers \FreeFormCertificate\Reregistration\FichaGenerator
 */
class FichaGeneratorTest extends TestCase {

    use MockeryPHPUnitIntegration;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        // pcov attribution preload (CLAUDE.md pcov gotcha).
        class_exists('\FreeFormCertificate\Reregistration\FichaGenerator');

        Functions\when('__')->returnArg();
        Functions\when('esc_html__')->returnArg();
        Functions\when('esc_html')->returnArg();
        Functions\when('esc_attr')->returnArg();
        Functions\when('wp_kses_post')->returnArg();
        Functions\when('sanitize_text_field')->alias('trim');
        Functions\when('sanitize_file_name')->alias(function ($name) {
            return preg_replace('/[^a-zA-Z0-9_\-.]/', '_', $name);
        });
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * Invoke a private/protected static method via reflection.
     *
     * @param string $method Method name.
     * @param array  $args   Arguments.
     * @return mixed
     */
    private function invokePrivateStatic(string $method, array $args = []) {
        $ref = new \ReflectionMethod(FichaGenerator::class, $method);
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

        // Should have the header row + 1 data row, not 2 data rows.
        // The second entry has empty entry1 AND empty exit2, so it gets skipped.
        $tr_count = substr_count($result, '<tr>');
        $this->assertSame(2, $tr_count); // 1 header + 1 data row
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
        // Split halves drive the two separate cells in the default ficha.
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

    public function test_build_standard_field_variables_hides_accumulation_fields_without_acumulo(): void {
        $field = (object) [
            'id'         => 2,
            'field_key'  => 'jornada_acumulo',
            'field_type' => 'text',
        ];

        $values = ['jornada_acumulo' => '40h'];

        $vars = $this->invokePrivateStatic('build_standard_field_variables', [[$field], $values, false]);

        $this->assertSame('', $vars['jornada_acumulo']);
    }

    public function test_build_standard_field_variables_keeps_accumulation_fields_with_acumulo(): void {
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
        $this->assertStringContainsString('<ol>', $result);
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
            if ($tag === 'ffcertificate_ficha_template_file') {
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
        $result = FichaGenerator::format_field_value($field, ['A', 'B', 'C']);
        $this->assertSame('A, B, C', $result);
    }

    public function test_format_field_value_default_non_scalar_returns_empty(): void {
        $field = (object) ['field_type' => 'text'];
        $result = FichaGenerator::format_field_value($field, (object) ['x' => 1]);
        $this->assertSame('', $result);
    }

    public function test_format_field_value_dependent_select_non_array_returns_empty(): void {
        $field = (object) ['field_type' => 'dependent_select'];
        // A plain string that is not JSON decodes to null → not an array.
        $result = FichaGenerator::format_field_value($field, 'plain-string');
        $this->assertSame('', $result);
    }

    // ==================================================================
    // decrypt_field_values()
    // ==================================================================

    public function test_decrypt_field_values_passes_through_non_sensitive(): void {
        $field  = (object) ['field_key' => 'hobby', 'is_sensitive' => 0];
        $values = ['hobby' => 'Reading'];
        $result = FichaGenerator::decrypt_field_values([$field], $values);
        $this->assertSame('Reading', $result['hobby']);
    }

    public function test_decrypt_field_values_skips_empty_or_non_string(): void {
        $fields = [
            (object) ['field_key' => 'a', 'is_sensitive' => 1],
            (object) ['field_key' => 'b', 'is_sensitive' => 1],
            (object) ['field_key' => 'c', 'is_sensitive' => 1],
        ];
        $values = ['a' => '', 'b' => ['x'], 'c' => null];
        $result = FichaGenerator::decrypt_field_values($fields, $values);
        // Untouched — decryption skipped for empty / non-string values.
        $this->assertSame('', $result['a']);
        $this->assertSame(['x'], $result['b']);
        $this->assertNull($result['c']);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_decrypt_field_values_decrypts_sensitive(): void {
        $enc = Mockery::mock('overload:FreeFormCertificate\Core\Encryption');
        $enc->shouldReceive('decrypt')->with('CIPHER')->andReturn('plain-cpf');

        $field  = (object) ['field_key' => 'cpf', 'is_sensitive' => 1];
        $values = ['cpf' => 'CIPHER'];
        $result = FichaGenerator::decrypt_field_values([$field], $values);

        $this->assertSame('plain-cpf', $result['cpf']);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_decrypt_field_values_keeps_original_when_decrypt_returns_null(): void {
        $enc = Mockery::mock('overload:FreeFormCertificate\Core\Encryption');
        $enc->shouldReceive('decrypt')->andReturn(null);

        $field  = (object) ['field_key' => 'cpf', 'is_sensitive' => 1];
        $values = ['cpf' => 'CIPHER'];
        $result = FichaGenerator::decrypt_field_values([$field], $values);

        $this->assertSame('CIPHER', $result['cpf']);
    }

    // ==================================================================
    // get_custom_fields_for_reregistration()
    // ==================================================================

    public function test_get_custom_fields_for_reregistration_dedupes_by_id(): void {
        Functions\when('wp_cache_get')->justReturn(false);
        Functions\when('wp_cache_set')->justReturn(true);

        // $wpdb drives ReregistrationRepository::get_audience_ids (get_col → [1,2])
        // and CustomFieldReader::get_by_audience_with_parents (get_row audience,
        // get_results fields). The same field id 10 appears for both audiences
        // and must be collapsed to a single entry.
        global $wpdb;
        $wpdb = Mockery::mock('wpdb')->makePartial();
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('prepare')->andReturnUsing(function () {
            return func_get_args()[0];
        })->byDefault();
        $wpdb->shouldReceive('get_col')->andReturn(['1', '2'])->byDefault();
        $wpdb->shouldReceive('get_row')->andReturn((object) ['id' => 1, 'name' => 'Aud', 'parent_id' => 0])->byDefault();
        $wpdb->shouldReceive('get_results')->andReturn([
            (object) ['id' => 10, 'field_key' => 'shared', 'field_type' => 'text'],
        ])->byDefault();

        $rereg  = (object) ['id' => 5];
        $fields = FichaGenerator::get_custom_fields_for_reregistration($rereg);

        $this->assertCount(1, $fields);
        $this->assertSame(10, (int) $fields[0]->id);
    }

    // ==================================================================
    // generate_ficha_data() — early returns
    // ==================================================================

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_generate_ficha_data_returns_null_when_submission_missing(): void {
        $reader = Mockery::mock('overload:FreeFormCertificate\Reregistration\ReregistrationSubmissionReader');
        $reader->shouldReceive('get_by_id')->andReturn(null);

        $this->assertNull(FichaGenerator::generate_ficha_data(1));
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_generate_ficha_data_returns_null_when_rereg_missing(): void {
        $sr = Mockery::mock('overload:FreeFormCertificate\Reregistration\ReregistrationSubmissionReader');
        $sr->shouldReceive('get_by_id')->andReturn((object) ['reregistration_id' => 9]);

        $rr = Mockery::mock('overload:FreeFormCertificate\Reregistration\ReregistrationRepository');
        $rr->shouldReceive('get_by_id')->andReturn(null);

        $this->assertNull(FichaGenerator::generate_ficha_data(1));
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_generate_ficha_data_returns_null_when_user_missing(): void {
        Functions\when('get_userdata')->justReturn(false);

        $sr = Mockery::mock('overload:FreeFormCertificate\Reregistration\ReregistrationSubmissionReader');
        $sr->shouldReceive('get_by_id')->andReturn((object) ['reregistration_id' => 9, 'user_id' => 7]);

        $rr = Mockery::mock('overload:FreeFormCertificate\Reregistration\ReregistrationRepository');
        $rr->shouldReceive('get_by_id')->andReturn((object) ['id' => 9, 'title' => 'C']);

        $this->assertNull(FichaGenerator::generate_ficha_data(1));
    }

    // ==================================================================
    // generate_ficha_data() — happy path
    // ==================================================================

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_generate_ficha_data_builds_full_payload(): void {
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
            if ($tag === 'ffcertificate_ficha_template_file') {
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

        $sr = Mockery::mock('overload:FreeFormCertificate\Reregistration\ReregistrationSubmissionReader');
        $sr->shouldReceive('get_by_id')->andReturn($submission);
        $sr->shouldReceive('get_status_labels')->andReturn(['approved' => 'Approved']);

        $rr = Mockery::mock('overload:FreeFormCertificate\Reregistration\ReregistrationRepository');
        $rr->shouldReceive('get_by_id')->andReturn($rereg);
        $rr->shouldReceive('get_audience_ids')->andReturn([1]);

        $cfr = Mockery::mock('overload:FreeFormCertificate\Reregistration\CustomFieldReader');
        $cfr->shouldReceive('get_by_audience_with_parents')->andReturn([
            (object) ['id' => 100, 'field_key' => 'display_name', 'field_label' => 'Name', 'field_type' => 'text', 'field_source' => 'standard', 'is_sensitive' => 0],
            (object) ['id' => 101, 'field_key' => 'hobby', 'field_label' => 'Hobby', 'field_type' => 'text', 'field_source' => 'custom', 'is_sensitive' => 0],
        ]);

        $df = Mockery::mock('overload:FreeFormCertificate\Core\DateFormatter');
        $df->shouldReceive('format_datetime')->andReturn('2023-11-14 22:13');

        $fh = Mockery::mock('overload:FreeFormCertificate\Core\FilenameHelper');
        $fh->shouldReceive('build_pdf_filename')->andReturn('ficha_9_R-ABCD1234.pdf');

        $user = (object) [
            'ID'           => 7,
            'display_name' => 'João Silva',
            'user_email'   => 'joao@example.test',
        ];
        Functions\when('get_userdata')->justReturn($user);

        $result = FichaGenerator::generate_ficha_data(42);

        $this->assertIsArray($result);
        $this->assertSame('ficha', $result['type']);
        $this->assertSame('portrait', $result['orientation']);
        $this->assertSame('ficha_9_R-ABCD1234.pdf', $result['filename']);
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
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_generate_ficha_data_uses_synthetic_code_without_auth_code(): void {
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
        Functions\when('apply_filters')->alias(function ($tag, $value) {
            if ($tag === 'ffcertificate_ficha_template_file') {
                return '/tmp/ffc-nonexistent-template.html';
            }
            return $value;
        });

        // No auth_code, no submitted_at, no start_date → exercises the
        // synthetic `S{id}` fallback + the empty submitted_at / reference_year
        // branches.
        $submission = (object) [
            'id'                => 55,
            'reregistration_id' => 9,
            'user_id'           => 7,
            'data'              => null,
            'status'            => 'submitted',
            'submitted_at'      => 0,
            'auth_code'         => '',
        ];
        $rereg = (object) ['id' => 9, 'title' => 'C', 'start_date' => ''];

        $sr = Mockery::mock('overload:FreeFormCertificate\Reregistration\ReregistrationSubmissionReader');
        $sr->shouldReceive('get_by_id')->andReturn($submission);
        $sr->shouldReceive('get_status_labels')->andReturn([]);

        $rr = Mockery::mock('overload:FreeFormCertificate\Reregistration\ReregistrationRepository');
        $rr->shouldReceive('get_by_id')->andReturn($rereg);
        $rr->shouldReceive('get_audience_ids')->andReturn([]);

        $cfr = Mockery::mock('overload:FreeFormCertificate\Reregistration\CustomFieldReader');
        $cfr->shouldReceive('get_by_audience_with_parents')->andReturn([]);

        $df = Mockery::mock('overload:FreeFormCertificate\Core\DateFormatter');
        $df->shouldReceive('format_datetime')->andReturn('now');

        $fh = Mockery::mock('overload:FreeFormCertificate\Core\FilenameHelper');
        $fh->shouldReceive('build_pdf_filename')->andReturnUsing(function ($type, $id, $code) use (&$captured_code) {
            $captured_code = $code;
            return 'ficha.pdf';
        });

        Functions\when('get_userdata')->justReturn((object) [
            'ID'           => 7,
            'display_name' => 'Ana',
            'user_email'   => 'ana@e.test',
        ]);

        $result = FichaGenerator::generate_ficha_data(55);

        $this->assertIsArray($result);
        // Status with no label falls back to the raw status key.
        $this->assertStringContainsString('submitted', $result['html']);
        // Synthetic code S{submission_id} is used when auth_code is empty.
        $this->assertSame('S55', $captured_code);
    }
}
