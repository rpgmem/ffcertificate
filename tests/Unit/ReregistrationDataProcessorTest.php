<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Reregistration\ReregistrationDataProcessor;
use FreeFormCertificate\Reregistration\CustomFieldRepository;

/**
 * Tests for ReregistrationDataProcessor: sanitization and validation logic.
 *
 * Uses real Utils::validate_cpf() and validate_phone() (pure functions)
 * and mocks $wpdb for CustomFieldRepository database access.
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
        Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
        Functions\when( 'is_email' )->alias( function ( $email ) {
            return (bool) filter_var( $email, FILTER_VALIDATE_EMAIL );
        } );

        // Mock $wpdb for CustomFieldRepository
        $this->mock_wpdb_for_custom_fields( array() );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * Configure $wpdb mock so that CustomFieldRepository::get_by_audience_with_parents
     * returns the given custom fields. It must also mock AudienceRepository::get_by_id.
     *
     * @param array $fields Custom field objects to return.
     */
    private function mock_wpdb_for_custom_fields( array $fields ): void {
        global $wpdb;
        $wpdb = Mockery::mock( 'wpdb' );
        $wpdb->prefix = 'wp_';
        // AudienceRepository::get_by_id uses wp_cache_get + $wpdb->get_row
        // Return null so get_by_audience_with_parents returns early with []
        $wpdb->shouldReceive( 'get_row' )->andReturn( null )->byDefault();
        $wpdb->shouldReceive( 'prepare' )->andReturnUsing( function () {
            return func_get_args()[0];
        } )->byDefault();
        $wpdb->shouldReceive( 'get_results' )->andReturn( $fields )->byDefault();

        Functions\when( 'wp_cache_get' )->justReturn( false );
        Functions\when( 'wp_cache_set' )->justReturn( true );
        Functions\when( 'wp_cache_delete' )->justReturn( true );
    }

    /**
     * Configure $wpdb to return an audience and custom fields for validation tests.
     */
    private function setup_custom_fields( array $fields ): void {
        global $wpdb;
        $wpdb = Mockery::mock( 'wpdb' );
        $wpdb->prefix = 'wp_';
        // AudienceRepository::get_by_id — return an audience with no parent
        $audience = (object) array( 'id' => 1, 'name' => 'Test', 'parent_id' => 0 );
        $wpdb->shouldReceive( 'get_row' )->andReturn( $audience )->byDefault();
        $wpdb->shouldReceive( 'prepare' )->andReturnUsing( function () {
            return func_get_args()[0];
        } )->byDefault();
        // CustomFieldRepository::get_by_audience — return our custom fields
        $wpdb->shouldReceive( 'get_results' )->andReturn( $fields )->byDefault();
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
    // validate_submission() — standard fields
    // ==================================================================

    private function valid_standard_fields(): array {
        return array(
            'display_name'       => 'João Silva',
            'sexo'               => 'Male',
            'estado_civil'       => 'Single',
            'data_nascimento'    => '1990-01-15',
            'cpf'                => '529.982.247-25', // Valid CPF (passes Utils::validate_cpf)
            'rg'                 => '12345678',
            'divisao'            => 'DRE - Gabinete',
            'setor'              => 'Assessoria',
            'jornada'            => 'JB.30',
            'celular'            => '(11) 99999-9999',
            'contato_emergencia' => 'Maria Silva',
            'tel_emergencia'     => '(11) 98888-8888',
            'phone'              => '',
            'vinculo'            => '',
            'rf'                 => '',
            'unidade_lotacao'    => '',
            'unidade_exercicio'  => '',
            'endereco'           => '',
            'endereco_numero'    => '',
            'endereco_complemento' => '',
            'bairro'             => '',
            'cidade'             => '',
            'uf'                 => '',
            'cep'                => '',
            'email_institucional' => '',
            'email_particular'   => '',
            'horario_trabalho'   => '',
            'sindicato'          => '',
            'acumulo_cargos'     => '',
            'jornada_acumulo'    => '',
            'cargo_funcao_acumulo' => '',
            'horario_trabalho_acumulo' => '',
            'department'         => '',
            'organization'       => '',
        );
    }

    private function make_data( array $standard_overrides = array(), array $custom = array() ): array {
        return array(
            'standard_fields' => array_merge( $this->valid_standard_fields(), $standard_overrides ),
            'custom_fields'   => $custom,
        );
    }

    private function make_rereg( int $audience_id = 1 ): object {
        return (object) array( 'audience_id' => $audience_id );
    }

    public function test_validate_submission_all_valid(): void {
        $data   = $this->make_data();
        $errors = ReregistrationDataProcessor::validate_submission( $data, $this->make_rereg(), 1 );

        $this->assertEmpty( $errors, 'Valid data should produce no errors' );
    }

    public function test_validate_submission_empty_required_fields(): void {
        $data = $this->make_data( array(
            'display_name'       => '',
            'sexo'               => '',
            'estado_civil'       => '',
            'data_nascimento'    => '',
            'cpf'                => '',
            'divisao'            => '',
            'setor'              => '',
            'jornada'            => '',
            'celular'            => '',
            'contato_emergencia' => '',
            'tel_emergencia'     => '',
        ) );

        $errors = ReregistrationDataProcessor::validate_submission( $data, $this->make_rereg(), 1 );

        $this->assertArrayHasKey( 'standard_fields[display_name]', $errors );
        $this->assertArrayHasKey( 'standard_fields[sexo]', $errors );
        $this->assertArrayHasKey( 'standard_fields[estado_civil]', $errors );
        $this->assertArrayHasKey( 'standard_fields[data_nascimento]', $errors );
        $this->assertArrayHasKey( 'standard_fields[cpf]', $errors );
        $this->assertArrayHasKey( 'standard_fields[divisao]', $errors );
        $this->assertArrayHasKey( 'standard_fields[setor]', $errors );
        $this->assertArrayHasKey( 'standard_fields[jornada]', $errors );
        $this->assertArrayHasKey( 'standard_fields[celular]', $errors );
        $this->assertArrayHasKey( 'standard_fields[contato_emergencia]', $errors );
        $this->assertArrayHasKey( 'standard_fields[tel_emergencia]', $errors );

        $this->assertCount( 11, $errors );
    }

    public function test_validate_submission_invalid_cpf(): void {
        // '000.000.000-00' is a known invalid CPF (all same digits)
        $data   = $this->make_data( array( 'cpf' => '000.000.000-00' ) );
        $errors = ReregistrationDataProcessor::validate_submission( $data, $this->make_rereg(), 1 );

        $this->assertArrayHasKey( 'standard_fields[cpf]', $errors );
        $this->assertStringContainsString( 'Invalid CPF', $errors['standard_fields[cpf]'] );
    }

    public function test_validate_submission_invalid_phone(): void {
        $data   = $this->make_data( array( 'phone' => 'abc' ) );
        $errors = ReregistrationDataProcessor::validate_submission( $data, $this->make_rereg(), 1 );

        $this->assertArrayHasKey( 'standard_fields[phone]', $errors );
    }

    public function test_validate_submission_invalid_celular(): void {
        $data   = $this->make_data( array( 'celular' => 'xyz123' ) );
        $errors = ReregistrationDataProcessor::validate_submission( $data, $this->make_rereg(), 1 );

        $this->assertArrayHasKey( 'standard_fields[celular]', $errors );
    }

    public function test_validate_submission_invalid_division_department_combo(): void {
        // 'NTIC' belongs to DIAF, not Gabinete
        $data   = $this->make_data( array( 'divisao' => 'DRE - Gabinete', 'setor' => 'NTIC' ) );
        $errors = ReregistrationDataProcessor::validate_submission( $data, $this->make_rereg(), 1 );

        $this->assertArrayHasKey( 'standard_fields[setor]', $errors );
        $this->assertStringContainsString( 'Invalid department', $errors['standard_fields[setor]'] );
    }

    public function test_validate_submission_valid_division_department_combo(): void {
        // 'NTIC' belongs to DIAF — this is valid
        $data   = $this->make_data( array( 'divisao' => 'DRE - DIAF', 'setor' => 'NTIC' ) );
        $errors = ReregistrationDataProcessor::validate_submission( $data, $this->make_rereg(), 1 );

        $this->assertArrayNotHasKey( 'standard_fields[setor]', $errors );
    }

    // ==================================================================
    // validate_submission() — custom field validation
    // ==================================================================

    public function test_validate_submission_required_custom_field_empty(): void {
        $custom_field = (object) array(
            'id'               => 10,
            'field_label'      => 'Department Code',
            'field_type'       => 'text',
            'is_required'      => 1,
            'validation_rules' => null,
        );
        $this->setup_custom_fields( array( $custom_field ) );

        $data   = $this->make_data( array(), array( 'field_10' => '' ) );
        $errors = ReregistrationDataProcessor::validate_submission( $data, $this->make_rereg(), 1 );

        $this->assertArrayHasKey( 'custom_fields[field_10]', $errors );
        $this->assertStringContainsString( 'Department Code', $errors['custom_fields[field_10]'] );
    }

    public function test_validate_submission_custom_field_cpf_format(): void {
        $custom_field = (object) array(
            'id'               => 20,
            'field_label'      => 'Secondary CPF',
            'field_type'       => 'text',
            'is_required'      => 0,
            'validation_rules' => json_encode( array( 'format' => 'cpf' ) ),
        );
        $this->setup_custom_fields( array( $custom_field ) );

        // '111.111.111-11' is invalid (all same digits)
        $data   = $this->make_data( array(), array( 'field_20' => '111.111.111-11' ) );
        $errors = ReregistrationDataProcessor::validate_submission( $data, $this->make_rereg(), 1 );

        $this->assertArrayHasKey( 'custom_fields[field_20]', $errors );
        $this->assertStringContainsString( 'CPF', $errors['custom_fields[field_20]'] );
    }

    public function test_validate_submission_custom_field_email_format(): void {
        $custom_field = (object) array(
            'id'               => 30,
            'field_label'      => 'Alt Email',
            'field_type'       => 'text',
            'is_required'      => 0,
            'validation_rules' => json_encode( array( 'format' => 'email' ) ),
        );
        $this->setup_custom_fields( array( $custom_field ) );

        $data   = $this->make_data( array(), array( 'field_30' => 'not-an-email' ) );
        $errors = ReregistrationDataProcessor::validate_submission( $data, $this->make_rereg(), 1 );

        $this->assertArrayHasKey( 'custom_fields[field_30]', $errors );
    }

    public function test_validate_submission_custom_field_regex_valid_and_invalid(): void {
        $custom_field = (object) array(
            'id'               => 40,
            'field_label'      => 'Code',
            'field_type'       => 'text',
            'is_required'      => 0,
            'validation_rules' => json_encode( array(
                'format'               => 'custom_regex',
                'custom_regex'         => '^\d{3}-\d{4}$',
                'custom_regex_message' => 'Must be in format 000-0000',
            ) ),
        );
        $this->setup_custom_fields( array( $custom_field ) );

        // Valid format
        $data   = $this->make_data( array(), array( 'field_40' => '123-4567' ) );
        $errors = ReregistrationDataProcessor::validate_submission( $data, $this->make_rereg(), 1 );
        $this->assertArrayNotHasKey( 'custom_fields[field_40]', $errors );

        // Invalid format
        $data2   = $this->make_data( array(), array( 'field_40' => 'abc' ) );
        $errors2 = ReregistrationDataProcessor::validate_submission( $data2, $this->make_rereg(), 1 );
        $this->assertArrayHasKey( 'custom_fields[field_40]', $errors2 );
        $this->assertSame( 'Must be in format 000-0000', $errors2['custom_fields[field_40]'] );
    }

    public function test_validate_submission_custom_field_regex_with_slash_delimiter(): void {
        $custom_field = (object) array(
            'id'               => 50,
            'field_label'      => 'Lowercase',
            'field_type'       => 'text',
            'is_required'      => 0,
            'validation_rules' => json_encode( array(
                'format'       => 'custom_regex',
                'custom_regex' => '/^[a-z]+$/',
            ) ),
        );
        $this->setup_custom_fields( array( $custom_field ) );

        $data   = $this->make_data( array(), array( 'field_50' => 'abc' ) );
        $errors = ReregistrationDataProcessor::validate_submission( $data, $this->make_rereg(), 1 );
        $this->assertArrayNotHasKey( 'custom_fields[field_50]', $errors );

        $data2   = $this->make_data( array(), array( 'field_50' => 'ABC123' ) );
        $errors2 = ReregistrationDataProcessor::validate_submission( $data2, $this->make_rereg(), 1 );
        $this->assertArrayHasKey( 'custom_fields[field_50]', $errors2 );
    }

    public function test_validate_submission_custom_field_optional_empty_no_error(): void {
        $custom_field = (object) array(
            'id'               => 60,
            'field_label'      => 'Notes',
            'field_type'       => 'textarea',
            'is_required'      => 0,
            'validation_rules' => null,
        );
        $this->setup_custom_fields( array( $custom_field ) );

        $data   = $this->make_data( array(), array( 'field_60' => '' ) );
        $errors = ReregistrationDataProcessor::validate_submission( $data, $this->make_rereg(), 1 );

        $this->assertArrayNotHasKey( 'custom_fields[field_60]', $errors );
    }
}
