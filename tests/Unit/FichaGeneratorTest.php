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
    }
}
