<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Reregistration\ReregistrationDataProcessor;

/**
 * Tests for ReregistrationDataProcessor: sanitization and validation logic
 * under the unified dynamic field architecture.
 *
 * The processor no longer distinguishes between "standard" and "custom"
 * fields — every field shown in the form is a row in wp_ffc_custom_fields
 * and the submission payload is a flat `fields: { field_key => value }`
 * map. Validation mirrors CustomFieldRepository::validate_field_value.
 *
 * Uses real Utils::validate_cpf() / validate_phone() (pure helpers) and
 * mocks $wpdb so CustomFieldRepository::get_by_audience_with_parents
 * returns whatever field definitions each test requires.
 */
class ReregistrationDataProcessorTest extends TestCase {

    use MockeryPHPUnitIntegration;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        Functions\when( '__' )->returnArg();
        Functions\when( 'esc_html__' )->returnArg();
        Functions\when( 'absint' )->alias( function ( $val ) {
            return abs( intval( $val ) );
        } );
        Functions\when( 'sanitize_text_field' )->alias( 'trim' );
        Functions\when( 'sanitize_textarea_field' )->alias( 'trim' );
        Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
        Functions\when( 'wp_unslash' )->alias( function ( $val ) {
            return is_string( $val ) ? stripslashes( $val ) : $val;
        } );
        Functions\when( 'is_email' )->alias( function ( $email ) {
            return (bool) filter_var( $email, FILTER_VALIDATE_EMAIL );
        } );
        Functions\when( 'wp_cache_get' )->justReturn( false );
        Functions\when( 'wp_cache_set' )->justReturn( true );
        Functions\when( 'wp_cache_delete' )->justReturn( true );
        Functions\when( 'is_wp_error' )->alias( function ( $thing ) {
            return $thing instanceof \WP_Error;
        });
        Functions\when( 'FreeFormCertificate\Reregistration\is_wp_error' )->alias( function ( $thing ) {
            return $thing instanceof \WP_Error;
        });
        Functions\when( 'FreeFormCertificate\Reregistration\is_email' )->alias( function ( $email ) {
            return (bool) filter_var( $email, FILTER_VALIDATE_EMAIL );
        });

        // Default $wpdb mock — no fields returned.
        $this->setup_wpdb_with_fields( array() );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * Configure the global $wpdb so that:
     *   - AudienceRepository::get_by_id returns a one-level audience (no parent)
     *   - ReregistrationRepository::get_audience_ids returns [1]
     *   - CustomFieldRepository::get_by_audience returns $fields
     *
     * @param array<object> $fields Field stdClass definitions to return.
     */
    private function setup_wpdb_with_fields( array $fields ): void {
        global $wpdb;
        $wpdb = Mockery::mock( 'wpdb' );
        $wpdb->prefix = 'wp_';

        $audience = (object) array(
            'id'        => 1,
            'name'      => 'Test Audience',
            'parent_id' => 0,
        );
        $wpdb->shouldReceive( 'get_row' )->andReturn( $audience )->byDefault();
        $wpdb->shouldReceive( 'prepare' )->andReturnUsing( function () {
            return func_get_args()[0];
        } )->byDefault();
        $wpdb->shouldReceive( 'get_results' )->andReturn( $fields )->byDefault();
        $wpdb->shouldReceive( 'get_col' )->andReturn( array( '1' ) )->byDefault();
    }

    /**
     * Build a stdClass field definition with sensible defaults.
     *
     * @param array<string, mixed> $overrides Property overrides.
     * @return object
     */
    private function make_field( array $overrides ): object {
        $defaults = array(
            'id'                 => 0,
            'audience_id'        => 1,
            'field_key'          => '',
            'field_label'        => '',
            'field_type'         => 'text',
            'field_group'        => 'personal',
            'field_source'       => 'standard',
            'field_profile_key'  => null,
            'field_mask'         => null,
            'is_sensitive'       => 0,
            'field_options'      => null,
            'validation_rules'   => null,
            'sort_order'         => 0,
            'is_required'        => 0,
            'is_active'          => 1,
        );
        return (object) array_merge( $defaults, $overrides );
    }

    /**
     * Build the 10 standard fields that are required for the core
     * "happy path" / "required fields" validation tests. Each returns a
     * stdClass with the exact property surface the processor reads.
     *
     * @return array<object>
     */
    private function standard_field_mocks(): array {
        $divisao_map = \FreeFormCertificate\Reregistration\ReregistrationFieldOptions::get_divisao_setor_map();
        $sexo        = \FreeFormCertificate\Reregistration\ReregistrationFieldOptions::get_sexo_options();
        $estado      = \FreeFormCertificate\Reregistration\ReregistrationFieldOptions::get_estado_civil_options();
        $jornada     = \FreeFormCertificate\Reregistration\ReregistrationFieldOptions::get_jornada_options();

        $id = 1;
        return array(
            $this->make_field( array(
                'id'          => $id++,
                'field_key'   => 'display_name',
                'field_label' => 'Name',
                'field_type'  => 'text',
                'is_required' => 1,
            ) ),
            $this->make_field( array(
                'id'            => $id++,
                'field_key'     => 'sexo',
                'field_label'   => 'Sex',
                'field_type'    => 'select',
                'is_required'   => 1,
                'field_options' => json_encode( array( 'choices' => $sexo ) ),
            ) ),
            $this->make_field( array(
                'id'            => $id++,
                'field_key'     => 'estado_civil',
                'field_label'   => 'Marital Status',
                'field_type'    => 'select',
                'is_required'   => 1,
                'field_options' => json_encode( array( 'choices' => $estado ) ),
            ) ),
            $this->make_field( array(
                'id'          => $id++,
                'field_key'   => 'data_nascimento',
                'field_label' => 'Date of Birth',
                'field_type'  => 'date',
                'is_required' => 1,
            ) ),
            $this->make_field( array(
                'id'               => $id++,
                'field_key'        => 'cpf',
                'field_label'      => 'CPF/CIN',
                'field_type'       => 'text',
                'is_required'      => 1,
                'is_sensitive'     => 1,
                'validation_rules' => json_encode( array( 'format' => 'cpf' ) ),
            ) ),
            $this->make_field( array(
                'id'            => $id++,
                'field_key'     => 'divisao_setor',
                'field_label'   => 'Division / Department',
                'field_type'    => 'dependent_select',
                'is_required'   => 1,
                'field_options' => json_encode( array( 'groups' => $divisao_map ) ),
            ) ),
            $this->make_field( array(
                'id'            => $id++,
                'field_key'     => 'jornada',
                'field_label'   => 'Work Schedule',
                'field_type'    => 'select',
                'is_required'   => 1,
                'field_options' => json_encode( array( 'choices' => $jornada ) ),
            ) ),
            $this->make_field( array(
                'id'               => $id++,
                'field_key'        => 'phone',
                'field_label'      => 'Home Phone',
                'field_type'       => 'text',
                'is_required'      => 0,
                'validation_rules' => json_encode( array( 'format' => 'phone' ) ),
            ) ),
            $this->make_field( array(
                'id'               => $id++,
                'field_key'        => 'celular',
                'field_label'      => 'Cell Phone',
                'field_type'       => 'text',
                'is_required'      => 1,
                'validation_rules' => json_encode( array( 'format' => 'phone' ) ),
            ) ),
            $this->make_field( array(
                'id'          => $id++,
                'field_key'   => 'contato_emergencia',
                'field_label' => 'Emergency Contact',
                'field_type'  => 'text',
                'is_required' => 1,
            ) ),
            $this->make_field( array(
                'id'               => $id++,
                'field_key'        => 'tel_emergencia',
                'field_label'      => 'Emergency Phone',
                'field_type'       => 'text',
                'is_required'      => 1,
                'validation_rules' => json_encode( array( 'format' => 'phone' ) ),
            ) ),
        );
    }

    /**
     * Valid values matching the standard_field_mocks above.
     *
     * @return array<string, mixed>
     */
    private function valid_field_values(): array {
        return array(
            'display_name'       => 'João Silva',
            'sexo'               => 'Male',
            'estado_civil'       => 'Single',
            'data_nascimento'    => '1990-01-15',
            'cpf'                => '529.982.247-25', // Valid CPF
            'divisao_setor'      => json_encode( array(
                'parent' => 'DRE - Gabinete',
                'child'  => 'Assessoria',
            ) ),
            'jornada'            => 'JB.30',
            'phone'              => '',
            'celular'            => '(11) 99999-9999',
            'contato_emergencia' => 'Maria Silva',
            'tel_emergencia'     => '(11) 98888-8888',
        );
    }

    /**
     * Build a unified { fields: {...} } payload with optional overrides.
     *
     * @param array<string, mixed> $overrides Field overrides merged on top.
     * @return array<string, mixed>
     */
    private function make_data( array $overrides = array() ): array {
        return array(
            'fields' => array_merge( $this->valid_field_values(), $overrides ),
        );
    }

    private function make_rereg( int $audience_id = 1 ): object {
        return (object) array( 'id' => 1, 'audience_id' => $audience_id );
    }

    // ==================================================================
    // sanitize_working_hours()
    // ==================================================================

    public function test_sanitize_working_hours_valid_json(): void {
        $input = json_encode( array(
            array( 'day' => 1, 'entry1' => '08:00', 'exit1' => '12:00', 'entry2' => '13:00', 'exit2' => '17:00' ),
            array( 'day' => 2, 'entry1' => '09:00', 'exit1' => '12:00', 'entry2' => '14:00', 'exit2' => '18:00' ),
        ) );

        $result = ReregistrationDataProcessor::sanitize_working_hours( $input );
        $decoded = json_decode( $result, true );

        $this->assertIsArray( $decoded );
        $this->assertCount( 2, $decoded );
        $this->assertSame( 1, $decoded[0]['day'] );
        $this->assertSame( '08:00', $decoded[0]['entry1'] );
        $this->assertSame( '17:00', $decoded[0]['exit2'] );
    }

    public function test_sanitize_working_hours_empty_string(): void {
        $result = ReregistrationDataProcessor::sanitize_working_hours( '' );
        $this->assertSame( '[]', $result );
    }

    public function test_sanitize_working_hours_invalid_json(): void {
        $result = ReregistrationDataProcessor::sanitize_working_hours( 'not json' );
        $this->assertSame( '[]', $result );
    }

    public function test_sanitize_working_hours_non_array_json(): void {
        $result = ReregistrationDataProcessor::sanitize_working_hours( '"just a string"' );
        $this->assertSame( '[]', $result );
    }

    public function test_sanitize_working_hours_skips_entries_without_day(): void {
        $input = json_encode( array(
            array( 'day' => 1, 'entry1' => '08:00', 'exit1' => '12:00', 'entry2' => '', 'exit2' => '' ),
            array( 'entry1' => '09:00' ), // Missing 'day'
            'not an array',
        ) );

        $result = ReregistrationDataProcessor::sanitize_working_hours( $input );
        $decoded = json_decode( $result, true );

        $this->assertCount( 1, $decoded, 'Only entries with day key should be kept' );
        $this->assertSame( 1, $decoded[0]['day'] );
    }

    public function test_sanitize_working_hours_casts_day_to_int(): void {
        $input = json_encode( array(
            array( 'day' => '3', 'entry1' => '', 'exit1' => '', 'entry2' => '', 'exit2' => '' ),
        ) );

        $result = ReregistrationDataProcessor::sanitize_working_hours( $input );
        $decoded = json_decode( $result, true );

        $this->assertSame( 3, $decoded[0]['day'] );
    }

    public function test_sanitize_working_hours_missing_optional_fields_default_empty(): void {
        $input = json_encode( array(
            array( 'day' => 1 ),
        ) );

        $result = ReregistrationDataProcessor::sanitize_working_hours( $input );
        $decoded = json_decode( $result, true );

        $this->assertSame( '', $decoded[0]['entry1'] );
        $this->assertSame( '', $decoded[0]['exit1'] );
        $this->assertSame( '', $decoded[0]['entry2'] );
        $this->assertSame( '', $decoded[0]['exit2'] );
    }

    // ==================================================================
    // validate_submission() — standard field definitions
    // ==================================================================

    public function test_validate_submission_all_valid(): void {
        $this->setup_wpdb_with_fields( $this->standard_field_mocks() );

        $data   = $this->make_data();
        $errors = ReregistrationDataProcessor::validate_submission( $data, $this->make_rereg(), 1 );

        $this->assertEmpty( $errors, 'Valid data should produce no errors. Got: ' . json_encode( $errors ) );
    }

    public function test_validate_submission_empty_required_fields(): void {
        $this->setup_wpdb_with_fields( $this->standard_field_mocks() );

        $data = $this->make_data( array(
            'display_name'       => '',
            'sexo'               => '',
            'estado_civil'       => '',
            'data_nascimento'    => '',
            'cpf'                => '',
            'divisao_setor'      => '',
            'jornada'            => '',
            'celular'            => '',
            'contato_emergencia' => '',
            'tel_emergencia'     => '',
        ) );

        $errors = ReregistrationDataProcessor::validate_submission( $data, $this->make_rereg(), 1 );

        $this->assertArrayHasKey( 'fields[display_name]', $errors );
        $this->assertArrayHasKey( 'fields[sexo]', $errors );
        $this->assertArrayHasKey( 'fields[estado_civil]', $errors );
        $this->assertArrayHasKey( 'fields[data_nascimento]', $errors );
        $this->assertArrayHasKey( 'fields[cpf]', $errors );
        $this->assertArrayHasKey( 'fields[divisao_setor]', $errors );
        $this->assertArrayHasKey( 'fields[jornada]', $errors );
        $this->assertArrayHasKey( 'fields[celular]', $errors );
        $this->assertArrayHasKey( 'fields[contato_emergencia]', $errors );
        $this->assertArrayHasKey( 'fields[tel_emergencia]', $errors );

        $this->assertCount( 10, $errors );
    }

    public function test_validate_submission_invalid_cpf(): void {
        $this->setup_wpdb_with_fields( $this->standard_field_mocks() );

        // '000.000.000-00' is a known invalid CPF (all same digits)
        $data   = $this->make_data( array( 'cpf' => '000.000.000-00' ) );
        $errors = ReregistrationDataProcessor::validate_submission( $data, $this->make_rereg(), 1 );

        $this->assertArrayHasKey( 'fields[cpf]', $errors );
        $this->assertStringContainsString( 'CPF', $errors['fields[cpf]'] );
    }

    public function test_validate_submission_invalid_phone(): void {
        $this->setup_wpdb_with_fields( $this->standard_field_mocks() );

        $data   = $this->make_data( array( 'phone' => 'abc' ) );
        $errors = ReregistrationDataProcessor::validate_submission( $data, $this->make_rereg(), 1 );

        $this->assertArrayHasKey( 'fields[phone]', $errors );
    }

    public function test_validate_submission_invalid_celular(): void {
        $this->setup_wpdb_with_fields( $this->standard_field_mocks() );

        $data   = $this->make_data( array( 'celular' => 'xyz123' ) );
        $errors = ReregistrationDataProcessor::validate_submission( $data, $this->make_rereg(), 1 );

        $this->assertArrayHasKey( 'fields[celular]', $errors );
    }

    public function test_validate_submission_invalid_division_department_combo(): void {
        $this->setup_wpdb_with_fields( $this->standard_field_mocks() );

        // 'NTIC' belongs to DIAF, not Gabinete → dependent_select rejects it.
        $data = $this->make_data( array(
            'divisao_setor' => json_encode( array(
                'parent' => 'DRE - Gabinete',
                'child'  => 'NTIC',
            ) ),
        ) );

        $errors = ReregistrationDataProcessor::validate_submission( $data, $this->make_rereg(), 1 );

        $this->assertArrayHasKey( 'fields[divisao_setor]', $errors );
    }

    public function test_validate_submission_valid_division_department_combo(): void {
        $this->setup_wpdb_with_fields( $this->standard_field_mocks() );

        // 'NTIC' belongs to DIAF — valid.
        $data = $this->make_data( array(
            'divisao_setor' => json_encode( array(
                'parent' => 'DRE - DIAF',
                'child'  => 'NTIC',
            ) ),
        ) );

        $errors = ReregistrationDataProcessor::validate_submission( $data, $this->make_rereg(), 1 );

        $this->assertArrayNotHasKey( 'fields[divisao_setor]', $errors );
    }

    // ==================================================================
    // validate_submission() — admin-created custom fields
    // ==================================================================

    public function test_validate_submission_required_custom_field_empty(): void {
        $custom_field = $this->make_field( array(
            'id'           => 10,
            'field_key'    => 'department_code',
            'field_label'  => 'Department Code',
            'field_type'   => 'text',
            'field_source' => 'custom',
            'is_required'  => 1,
        ) );
        $this->setup_wpdb_with_fields( array( $custom_field ) );

        $data   = array( 'fields' => array( 'department_code' => '' ) );
        $errors = ReregistrationDataProcessor::validate_submission( $data, $this->make_rereg(), 1 );

        $this->assertArrayHasKey( 'fields[department_code]', $errors );
        $this->assertStringContainsString( 'Department Code', $errors['fields[department_code]'] );
    }

    public function test_validate_submission_custom_field_cpf_format(): void {
        $custom_field = $this->make_field( array(
            'id'               => 20,
            'field_key'        => 'secondary_cpf',
            'field_label'      => 'Secondary CPF',
            'field_type'       => 'text',
            'field_source'     => 'custom',
            'is_required'      => 0,
            'validation_rules' => json_encode( array( 'format' => 'cpf' ) ),
        ) );
        $this->setup_wpdb_with_fields( array( $custom_field ) );

        // '111.111.111-11' is invalid (all same digits)
        $data   = array( 'fields' => array( 'secondary_cpf' => '111.111.111-11' ) );
        $errors = ReregistrationDataProcessor::validate_submission( $data, $this->make_rereg(), 1 );

        $this->assertArrayHasKey( 'fields[secondary_cpf]', $errors );
        $this->assertStringContainsString( 'CPF', $errors['fields[secondary_cpf]'] );
    }

    public function test_validate_submission_custom_field_email_format(): void {
        $custom_field = $this->make_field( array(
            'id'               => 30,
            'field_key'        => 'alt_email',
            'field_label'      => 'Alt Email',
            'field_type'       => 'text',
            'field_source'     => 'custom',
            'is_required'      => 0,
            'validation_rules' => json_encode( array( 'format' => 'email' ) ),
        ) );
        $this->setup_wpdb_with_fields( array( $custom_field ) );

        $data   = array( 'fields' => array( 'alt_email' => 'not-an-email' ) );
        $errors = ReregistrationDataProcessor::validate_submission( $data, $this->make_rereg(), 1 );

        $this->assertArrayHasKey( 'fields[alt_email]', $errors );
    }

    public function test_validate_submission_custom_field_regex_valid_and_invalid(): void {
        $custom_field = $this->make_field( array(
            'id'               => 40,
            'field_key'        => 'code',
            'field_label'      => 'Code',
            'field_type'       => 'text',
            'field_source'     => 'custom',
            'is_required'      => 0,
            'validation_rules' => json_encode( array(
                'format'               => 'custom_regex',
                'custom_regex'         => '^\d{3}-\d{4}$',
                'custom_regex_message' => 'Must be in format 000-0000',
            ) ),
        ) );
        $this->setup_wpdb_with_fields( array( $custom_field ) );

        // Valid format
        $data   = array( 'fields' => array( 'code' => '123-4567' ) );
        $errors = ReregistrationDataProcessor::validate_submission( $data, $this->make_rereg(), 1 );
        $this->assertArrayNotHasKey( 'fields[code]', $errors );

        // Invalid format
        $data2   = array( 'fields' => array( 'code' => 'abc' ) );
        $errors2 = ReregistrationDataProcessor::validate_submission( $data2, $this->make_rereg(), 1 );
        $this->assertArrayHasKey( 'fields[code]', $errors2 );
        $this->assertSame( 'Must be in format 000-0000', $errors2['fields[code]'] );
    }

    public function test_validate_submission_custom_field_regex_with_slash_delimiter(): void {
        $custom_field = $this->make_field( array(
            'id'               => 50,
            'field_key'        => 'lowercase_only',
            'field_label'      => 'Lowercase',
            'field_type'       => 'text',
            'field_source'     => 'custom',
            'is_required'      => 0,
            'validation_rules' => json_encode( array(
                'format'       => 'custom_regex',
                'custom_regex' => '/^[a-z]+$/',
            ) ),
        ) );
        $this->setup_wpdb_with_fields( array( $custom_field ) );

        $data   = array( 'fields' => array( 'lowercase_only' => 'abc' ) );
        $errors = ReregistrationDataProcessor::validate_submission( $data, $this->make_rereg(), 1 );
        $this->assertArrayNotHasKey( 'fields[lowercase_only]', $errors );

        $data2   = array( 'fields' => array( 'lowercase_only' => 'ABC123' ) );
        $errors2 = ReregistrationDataProcessor::validate_submission( $data2, $this->make_rereg(), 1 );
        $this->assertArrayHasKey( 'fields[lowercase_only]', $errors2 );
    }

    public function test_validate_submission_custom_field_optional_empty_no_error(): void {
        $custom_field = $this->make_field( array(
            'id'           => 60,
            'field_key'    => 'notes',
            'field_label'  => 'Notes',
            'field_type'   => 'textarea',
            'field_source' => 'custom',
            'is_required'  => 0,
        ) );
        $this->setup_wpdb_with_fields( array( $custom_field ) );

        $data   = array( 'fields' => array( 'notes' => '' ) );
        $errors = ReregistrationDataProcessor::validate_submission( $data, $this->make_rereg(), 1 );

        $this->assertArrayNotHasKey( 'fields[notes]', $errors );
    }
}
