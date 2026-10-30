<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Reregistration\ReregistrationDataProcessor;
use FreeFormCertificate\Tests\Support\Identifiers;

/**
 * Tests for ReregistrationDataProcessor: sanitization and validation logic
 * under the unified dynamic field architecture.
 *
 * The processor no longer distinguishes between "standard" and "custom"
 * fields — every field shown in the form is a row in wp_ffc_custom_fields
 * and the submission payload is a flat `fields: { field_key => value }`
 * map. Validation mirrors CustomFieldReader::validate_field_value.
 *
 * Uses real Utils::validate_cpf() / validate_phone() (pure helpers) and
 * mocks $wpdb so CustomFieldReader::get_by_audience_with_parents
 * returns whatever field definitions each test requires.
 *
 * @covers \FreeFormCertificate\Reregistration\ReregistrationDataProcessor
 */
class ReregistrationDataProcessorTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/**
	 * Every `$wpdb->update()` of the persistence path, as `[ table, data, where ]`.
	 *
	 * @var list<array{0: string, 1: array<string, mixed>, 2: array<string, mixed>}>
	 */
	private array $db_updates = array();

	/**
	 * Every `$wpdb->insert()` of the persistence path, as `[ table, data ]`.
	 *
	 * @var list<array{0: string, 1: array<string, mixed>}>
	 */
	private array $db_inserts = array();

	/** @var int How many times a submission row was read by id. */
	private int $submission_reads = 0;

	/**
	 * The last `prepare()` call, as `[ template, values ]`.
	 *
	 * @var array{0: string, 1: list<mixed>}
	 */
	private array $last_prepare = array( '', array() );

	/**
	 * The statuses each conditional write allowed, in order.
	 *
	 * @var list<list<mixed>>
	 */
	private array $written_statuses = array();

	/**
	 * The status the row had already moved to, or null: then the conditional
	 * write matches no row and the re-read answers with it (#2242).
	 *
	 * @var string|null
	 */
	private ?string $moved_to = null;

	/**
	 * Every `update_user_meta()`, as `[ user_id, key, value ]`.
	 *
	 * @var list<array{0: int, 1: string, 2: mixed}>
	 */
	private array $meta_writes = array();

	/** @var list<string> Recipients `wp_mail()` was called with. */
	private array $mailed = array();

	/** @var mixed The `$wpdb` global an earlier test left, restored afterwards. */
	private $wpdb_backup;

	/** @var bool Whether `$wpdb` was set before this test. */
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

		$this->had_wpdb    = array_key_exists( 'wpdb', $GLOBALS );
		$this->wpdb_backup = $GLOBALS['wpdb'] ?? null;

		// pcov attribution: preload the class-under-test so coverage is
		// attributed even though the autoloader would otherwise pull it in
		// mid-test (CLAUDE.md pcov gotcha).
		class_exists( '\FreeFormCertificate\Reregistration\ReregistrationDataProcessor' );

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
		Functions\when( 'is_wp_error' )->alias( function ( $thing ) {
			return $thing instanceof \WP_Error;
		});
		Functions\when( 'is_email' )->alias( function ( $email ) {
			return (bool) filter_var( $email, FILTER_VALIDATE_EMAIL );
		});
		// Default $wpdb mock — no fields returned.
		$this->setup_wpdb_with_fields( array() );
	}

	protected function tearDown(): void {
		if ( $this->had_wpdb ) {
			$GLOBALS['wpdb'] = $this->wpdb_backup;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Configure the global $wpdb so that:
	 *   - AudienceReader::get_by_id returns a one-level audience (no parent)
	 *   - ReregistrationRepository::get_audience_ids returns [1]
	 *   - CustomFieldReader::get_by_audience returns $fields
	 *
	 * @param array<object> $fields Field stdClass definitions to return.
	 */
	private function setup_wpdb_with_fields( array $fields ): void {
		global $wpdb;
		$wpdb = Mockery::mock( 'wpdb' )->makePartial();
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
		$divisao_map = \FreeFormCertificate\Reregistration\ReregistrationFieldOptions::get_default_division_sector_map();
		$sexo        = \FreeFormCertificate\Reregistration\ReregistrationFieldOptions::get_gender_options();
		$estado      = \FreeFormCertificate\Reregistration\ReregistrationFieldOptions::get_marital_status_options();
		$jornada     = \FreeFormCertificate\Reregistration\ReregistrationFieldOptions::get_work_schedule_options();

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
			'cpf'                => self::mask_cpf( Identifiers::CPF_A ), // Valid CPF
			'divisao_setor'      => json_encode( array(
				'parent' => 'DRE - Gabinete',
				'child'  => 'Assessoria',
			) ),
			'jornada'            => 'JB - 20h/a',
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
			array( 'day' => 1, 'entry1' => '08:00', 'exit1' => '12:00', 'entry2' => '13:00', 'exit2' => '17:00' ),
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
			array( 'day' => '3', 'entry1' => '08:00', 'exit1' => '', 'entry2' => '', 'exit2' => '17:00' ),
		) );

		$result = ReregistrationDataProcessor::sanitize_working_hours( $input );
		$decoded = json_decode( $result, true );

		$this->assertSame( 3, $decoded[0]['day'] );
	}

	/**
	 * #1128 changed this: a row with no time at all used to be stored as four
	 * empty strings. It is now dropped — "I do not work this day" is what a
	 * missing row already means, so storing an empty one said nothing and made
	 * the field's own `is_required` unenforceable.
	 */
	public function test_sanitize_working_hours_drops_a_row_with_no_times(): void {
		$input = json_encode( array(
			array( 'day' => 1 ),
		) );

		$this->assertSame( '[]', ReregistrationDataProcessor::sanitize_working_hours( $input ) );
	}

	/**
	 * The optional middle shift still defaults to empty on a row that is
	 * otherwise complete — only the two required times gate the row.
	 */
	public function test_sanitize_working_hours_keeps_the_middle_shift_empty(): void {
		$input = json_encode( array(
			array( 'day' => 1, 'entry1' => '08:00', 'exit2' => '17:00' ),
		) );

		$decoded = json_decode( ReregistrationDataProcessor::sanitize_working_hours( $input ), true );

		$this->assertSame( '08:00', $decoded[0]['entry1'] );
		$this->assertSame( '', $decoded[0]['exit1'] );
		$this->assertSame( '', $decoded[0]['entry2'] );
		$this->assertSame( '17:00', $decoded[0]['exit2'] );
	}

	/**
	 * A half-filled row is dropped rather than stored (#1128) — the defect the
	 * smoke of 6.24.0 found, where `isset( '' )` being true let it through.
	 */
	public function test_sanitize_working_hours_drops_a_half_filled_row(): void {
		$input = json_encode( array(
			array( 'day' => 1, 'entry1' => '', 'exit1' => '', 'entry2' => '', 'exit2' => '17:00' ),
		) );

		$this->assertSame( '[]', ReregistrationDataProcessor::sanitize_working_hours( $input ) );
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

	// ==================================================================
	// Display-only fields (acknowledgment)
	// ==================================================================

	public function test_validate_submission_skips_display_only_acknowledgment(): void {
		// Even flagged required, an acknowledgment block has no value to
		// submit, so it must never produce a "required" error.
		$this->setup_wpdb_with_fields( array(
			$this->make_field( array(
				'id'            => 1,
				'field_key'     => 'acknowledgment',
				'field_label'   => 'Acknowledgment',
				'field_type'    => 'acknowledgment',
				'is_required'   => 1,
				'field_options' => json_encode( array( 'html' => '<p>Notice</p>' ) ),
			) ),
		) );

		$errors = ReregistrationDataProcessor::validate_submission( array( 'fields' => array() ), $this->make_rereg(), 1 );

		$this->assertSame( array(), $errors );
	}

	public function test_collect_form_data_skips_display_only_acknowledgment(): void {
		$this->setup_wpdb_with_fields( array(
			$this->make_field( array( 'id' => 1, 'field_key' => 'display_name', 'field_type' => 'text' ) ),
			$this->make_field( array( 'id' => 2, 'field_key' => 'acknowledgment', 'field_type' => 'acknowledgment' ) ),
		) );

		$_POST['fields'] = array(
			'display_name'   => 'João',
			'acknowledgment' => 'tampered value',
		);
		$data = ReregistrationDataProcessor::collect_form_data( $this->make_rereg(), 1 );
		unset( $_POST['fields'] );

		$this->assertArrayHasKey( 'display_name', $data['fields'] );
		$this->assertArrayNotHasKey( 'acknowledgment', $data['fields'] );
	}

	// ==================================================================
	// The second post (#2242): hidden fields are neither required nor kept
	// ==================================================================

	/**
	 * The accumulation question and the three fields it reveals, all required.
	 *
	 * @return array<object>
	 */
	private function second_post_fields(): array {
		return array(
			$this->make_field( array( 'id' => 1, 'field_key' => 'acumulo_cargos', 'field_type' => 'select', 'is_required' => 1, 'field_options' => json_encode( array( 'choices' => array( 'I hold', 'I do not hold' ) ) ) ) ),
			$this->make_field( array( 'id' => 2, 'field_key' => 'jornada_acumulo', 'is_required' => 1 ) ),
			$this->make_field( array( 'id' => 3, 'field_key' => 'cargo_funcao_acumulo', 'is_required' => 1 ) ),
			$this->make_field( array( 'id' => 4, 'field_key' => 'horario_trabalho_acumulo', 'is_required' => 1 ) ),
		);
	}

	public function test_a_hidden_second_post_field_is_not_required(): void {
		$this->setup_wpdb_with_fields( $this->second_post_fields() );

		$errors = ReregistrationDataProcessor::validate_submission(
			array( 'fields' => array( 'acumulo_cargos' => 'I do not hold' ) ),
			$this->make_rereg(),
			1
		);

		$this->assertSame( array(), $errors );
	}

	public function test_a_declared_second_post_requires_its_fields(): void {
		$this->setup_wpdb_with_fields( $this->second_post_fields() );

		$errors = ReregistrationDataProcessor::validate_submission(
			array( 'fields' => array( 'acumulo_cargos' => 'I hold' ) ),
			$this->make_rereg(),
			1
		);

		$this->assertSame( array( 'fields[jornada_acumulo]', 'fields[cargo_funcao_acumulo]', 'fields[horario_trabalho_acumulo]' ), array_keys( $errors ) );
	}

	public function test_a_stale_second_post_answer_is_not_stored(): void {
		$this->setup_wpdb_with_fields( $this->second_post_fields() );

		$data = ReregistrationDataProcessor::sanitize_values(
			array(
				'acumulo_cargos'       => 'I do not hold',
				'jornada_acumulo'      => 'J30',
				'cargo_funcao_acumulo' => 'Teacher',
			),
			$this->make_rereg()
		);

		$this->assertSame(
			array( 'acumulo_cargos' => 'I do not hold', 'jornada_acumulo' => '', 'cargo_funcao_acumulo' => '', 'horario_trabalho_acumulo' => '' ),
			$data['fields'],
			'Typed and then hidden by the answer, so dropped.'
		);
	}

	public function test_a_declared_second_post_keeps_its_answers(): void {
		$this->setup_wpdb_with_fields( $this->second_post_fields() );

		$data = ReregistrationDataProcessor::sanitize_values(
			array( 'acumulo_cargos' => 'I hold', 'jornada_acumulo' => 'J30' ),
			$this->make_rereg()
		);

		$this->assertSame( 'J30', $data['fields']['jornada_acumulo'] );
	}

	public function test_the_field_definitions_are_read_once_per_request(): void {
		$this->setup_wpdb_with_fields( $this->second_post_fields() );
		$cache = array();
		Functions\when( 'wp_cache_get' )->alias(
			static function ( $key, $group ) use ( &$cache ) {
				return $cache[ $group ][ $key ] ?? false;
			}
		);
		Functions\when( 'wp_cache_set' )->alias(
			static function ( $key, $value, $group ) use ( &$cache ) {
				$cache[ $group ][ $key ] = $value;
				return true;
			}
		);
		global $wpdb;
		$reads = 0;
		$wpdb->shouldReceive( 'get_col' )->andReturnUsing(
			static function () use ( &$reads ) {
				++$reads;
				return array( '1' );
			}
		);

		$data = ReregistrationDataProcessor::sanitize_values( array( 'acumulo_cargos' => 'I do not hold' ), $this->make_rereg() );
		ReregistrationDataProcessor::validate_submission( $data, $this->make_rereg(), 1 );

		$this->assertSame( 1, $reads, 'The campaign\'s audiences are walked once.' );
		$this->assertArrayHasKey( 'fields_' . $this->make_rereg()->id, $cache[ ReregistrationDataProcessor::REQUEST_CACHE_GROUP ] );
	}

	// ==================================================================
	// collect_form_data() — per-type sanitization branches
	// ==================================================================

	public function test_collect_form_data_sanitizes_every_field_type(): void {
		$this->setup_wpdb_with_fields( array(
			$this->make_field( array( 'id' => 1, 'field_key' => 'wh', 'field_type' => 'working_hours' ) ),
			$this->make_field( array( 'id' => 2, 'field_key' => 'dep', 'field_type' => 'dependent_select' ) ),
			$this->make_field( array( 'id' => 3, 'field_key' => 'bio', 'field_type' => 'textarea' ) ),
			$this->make_field( array( 'id' => 4, 'field_key' => 'age', 'field_type' => 'number' ) ),
			$this->make_field( array( 'id' => 5, 'field_key' => 'agree', 'field_type' => 'checkbox' ) ),
			$this->make_field( array( 'id' => 6, 'field_key' => 'dob', 'field_type' => 'date' ) ),
			$this->make_field( array( 'id' => 7, 'field_key' => 'name', 'field_type' => 'text' ) ),
		) );

		$_POST['fields'] = array(
			// Complete row: half-filled rows are dropped since #1128, and this
			// test is about the per-type dispatch, not the row rule.
			'wh'    => json_encode( array( array( 'day' => 1, 'entry1' => '08:00', 'exit2' => '17:00' ) ) ),
			'dep'   => json_encode( array( 'parent' => 'A', 'child' => 'B' ) ),
			'bio'   => 'Some notes',
			'age'   => '42',
			'agree' => 'on',
			'dob'   => '1990-01-01',
			'name'  => 'João',
		);
		$data = ReregistrationDataProcessor::collect_form_data( $this->make_rereg(), 1 );
		unset( $_POST['fields'] );

		$f = $data['fields'];
		$this->assertStringContainsString( '08:00', $f['wh'] );
		$this->assertSame( array( 'parent' => 'A', 'child' => 'B' ), json_decode( $f['dep'], true ) );
		$this->assertSame( 'Some notes', $f['bio'] );
		$this->assertSame( '42', $f['age'] );
		$this->assertSame( '1', $f['agree'] );
		$this->assertSame( '1990-01-01', $f['dob'] );
		$this->assertSame( 'João', $f['name'] );
	}

	public function test_collect_form_data_number_non_numeric_becomes_empty(): void {
		$this->setup_wpdb_with_fields( array(
			$this->make_field( array( 'id' => 1, 'field_key' => 'age', 'field_type' => 'number' ) ),
		) );

		$_POST['fields'] = array( 'age' => 'not-a-number' );
		$data = ReregistrationDataProcessor::collect_form_data( $this->make_rereg(), 1 );
		unset( $_POST['fields'] );

		$this->assertSame( '', $data['fields']['age'] );
	}

	public function test_collect_form_data_checkbox_unchecked_is_zero(): void {
		$this->setup_wpdb_with_fields( array(
			$this->make_field( array( 'id' => 1, 'field_key' => 'agree', 'field_type' => 'checkbox' ) ),
		) );

		// Field present in the form but absent from POST → default '' → '0'.
		$_POST['fields'] = array();
		$data = ReregistrationDataProcessor::collect_form_data( $this->make_rereg(), 1 );
		unset( $_POST['fields'] );

		$this->assertSame( '0', $data['fields']['agree'] );
	}

	public function test_collect_form_data_dependent_select_missing_keys_becomes_empty_pair(): void {
		$this->setup_wpdb_with_fields( array(
			$this->make_field( array( 'id' => 1, 'field_key' => 'dep', 'field_type' => 'dependent_select' ) ),
		) );

		$_POST['fields'] = array( 'dep' => json_encode( array( 'foo' => 'bar' ) ) );
		$data = ReregistrationDataProcessor::collect_form_data( $this->make_rereg(), 1 );
		unset( $_POST['fields'] );

		$this->assertSame( array( 'parent' => '', 'child' => '' ), json_decode( $data['fields']['dep'], true ) );
	}

	public function test_collect_form_data_empty_when_no_post_root(): void {
		$this->setup_wpdb_with_fields( array(
			$this->make_field( array( 'id' => 1, 'field_key' => 'name', 'field_type' => 'text' ) ),
		) );

		unset( $_POST['fields'] );
		$data = ReregistrationDataProcessor::collect_form_data( $this->make_rereg(), 1 );

		// Field present in definition, absent from POST → '' via sanitize.
		$this->assertSame( '', $data['fields']['name'] );
	}

	// ==================================================================
	// validate_submission() — dependent_select with empty child skips combo check
	// ==================================================================

	public function test_validate_submission_dependent_select_empty_child_passes(): void {
		// A dependent_select whose decoded value has an empty child never hits
		// the processor's group-membership check (guarded by ! empty( child )).
		// With no `groups` defined the reader's own validator returns true, so
		// the only path that could add an error is the processor's combo check.
		$field = $this->make_field( array(
			'id'            => 1,
			'field_key'     => 'divisao_setor',
			'field_label'   => 'Division',
			'field_type'    => 'dependent_select',
			'is_required'   => 0,
			'field_options' => json_encode( array( 'groups' => array() ) ),
		) );
		$this->setup_wpdb_with_fields( array( $field ) );

		$data = array( 'fields' => array(
			'divisao_setor' => json_encode( array( 'parent' => 'DRE - DIAF', 'child' => '' ) ),
		) );

		$errors = ReregistrationDataProcessor::validate_submission( $data, $this->make_rereg(), 1 );

		$this->assertArrayNotHasKey( 'fields[divisao_setor]', $errors );
	}

	// ==================================================================
	// process_submission() — full persistence path
	// ==================================================================

	/**
	 * Stub the WordPress functions the persistence path reaches, recording
	 * the user-meta writes and the e-mails sent.
	 *
	 * The writer, the profile service and its repository, the custom-field
	 * reader and writer, the auth-code service, Encryption (the bootstrap's
	 * keys) and the e-mail handler down to `wp_mail()` are the real classes.
	 * Every `get_option()` answers its default: e-mails enabled, activity log
	 * and debug off — an install nobody configured.
	 */
	private function stub_persistence_functions(): void {
		Functions\when( 'get_option' )->alias( static fn ( $key, $default = false ) => $default );
		Functions\when( 'get_user_meta' )->justReturn( array() );
		Functions\when( 'sanitize_key' )->alias( static fn ( $key ) => strtolower( (string) $key ) );
		Functions\when( 'wp_cache_flush' )->justReturn( true );
		// clear_cache() with no key asks the backend first (#2241); without
		// group flushing it falls back to wp_cache_flush(), stubbed above.
		Functions\when( 'wp_cache_supports' )->justReturn( false );
		Functions\when( 'wp_rand' )->alias( static fn ( $min = 0, $max = 0 ) => random_int( (int) $min, (int) $max ) );

		$this->meta_writes = array();
		$meta_writes       = &$this->meta_writes;
		Functions\when( 'update_user_meta' )->alias(
			static function ( $user_id, $key, $value ) use ( &$meta_writes ) {
				$meta_writes[] = array( (int) $user_id, (string) $key, $value );
				return true;
			}
		);
		Functions\when( 'delete_user_meta' )->alias(
			function ( $user_id, $key ) {
				$this->fail( "Unexpected delete_user_meta( {$user_id}, {$key} )." );
			}
		);

		// The confirmation e-mail, rendered through the plugin's own chrome.
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'wp_kses_post' )->returnArg();
		Functions\when( 'wp_strip_all_tags' )->alias( 'strip_tags' );
		Functions\when( 'home_url' )->alias( static fn ( $path = '' ) => 'https://example.com' . $path );
		Functions\when( 'get_bloginfo' )->justReturn( 'Test Site' );
		Functions\when( 'wp_timezone' )->alias( static fn () => new \DateTimeZone( 'UTC' ) );
		Functions\when( 'wp_date' )->alias( static fn ( $format, $ts = null ) => gmdate( (string) $format, (int) $ts ) );
		Functions\when( 'get_userdata' )->alias(
			static fn ( $id ) => 7 === (int) $id
				? (object) array(
					'ID'           => 7,
					'display_name' => 'Owner',
					'user_email'   => 'owner@example.com',
				)
				: false
		);

		$this->mailed = array();
		$mailed       = &$this->mailed;
		Functions\when( 'wp_mail' )->alias(
			static function ( $to ) use ( &$mailed ) {
				$mailed[] = $to;
				return true;
			}
		);
	}

	/**
	 * A strict `$wpdb` double for process_submission(): campaign 1 is linked
	 * to the given audiences, audience 1 has no parent and carries `$fields`,
	 * no auth code is taken yet, and user 7 has no profile row. Submission 99
	 * of user 7 and campaign 1 (confirmation e-mail on) answer the reads the
	 * confirmation makes; any other submission id reads as missing.
	 *
	 * Writes are recorded in $db_updates / $db_inserts; any query the path is
	 * not expected to make fails the test.
	 *
	 * @param array<object> $fields       Field rows of audience 1.
	 * @param list<string>  $audience_ids What the campaign's audience link returns.
	 */
	private function persistence_database( array $fields, array $audience_ids ): void {
		$this->db_updates          = array();
		$this->db_inserts          = array();
		$this->written_statuses    = array();
		$this->moved_to            = null;
		$this->submission_reads    = 0;

		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';

		// Interpolates, so every read can dispatch on the query's full text.
		// The last template and values are kept: the submission write is one
		// conditional UPDATE (#2242), read back from them below.
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			function ( $sql, ...$args ) {
				$values             = ( 1 === count( $args ) && is_array( $args[0] ) ) ? $args[0] : $args;
				$this->last_prepare = array( (string) $sql, array_values( $values ) );
				foreach ( $values as $v ) {
					$sql = (string) preg_replace( '/%[ids]/', (string) $v, (string) $sql, 1 );
				}
				return $sql;
			}
		);

		$submission = (object) array(
			'id'                => 99,
			'reregistration_id' => 1,
			'user_id'           => '7',
			'status'            => 'submitted',
			'auth_code'         => 'ABCD2345EFGH',
			'magic_token'       => 'token',
		);
		$campaign   = (object) array(
			'id'                         => 1,
			'title'                      => 'Campaign',
			'email_confirmation_enabled' => 1,
			'start_date'                 => '2026-01-01',
			'end_date'                   => '2099-12-31',
		);
		$audience   = (object) array(
			'id'        => 1,
			'name'      => 'Test Audience',
			'parent_id' => 0,
		);

		$wpdb->shouldReceive( 'get_col' )->andReturnUsing(
			function ( $sql ) use ( $audience_ids ) {
				if ( 'SELECT audience_id FROM wp_ffc_reregistration_audiences WHERE reregistration_id = 1' === $sql ) {
					return $audience_ids;
				}
				$this->fail( 'Unexpected get_col: ' . $sql );
			}
		);

		$wpdb->shouldReceive( 'get_results' )->andReturnUsing(
			function ( $sql ) use ( $fields ) {
				if ( str_starts_with( (string) $sql, 'SELECT * FROM wp_ffc_custom_fields WHERE audience_id = 1 AND is_active = 1' ) ) {
					return $fields;
				}
				$this->fail( 'Unexpected get_results: ' . $sql );
			}
		);

		$wpdb->shouldReceive( 'get_row' )->andReturnUsing(
			function ( $sql ) use ( $submission, $campaign, $audience ) {
				if ( 'SELECT * FROM wp_ffc_audiences WHERE id = 1' === $sql ) {
					return $audience;
				}
				if ( str_starts_with( (string) $sql, 'SELECT * FROM wp_ffc_reregistration_submissions WHERE id = ' ) ) {
					++$this->submission_reads;
					return 'SELECT * FROM wp_ffc_reregistration_submissions WHERE id = 99' === $sql ? $submission : null;
				}
				if ( 'SELECT * FROM wp_ffc_reregistrations WHERE id = 1' === $sql ) {
					return $campaign;
				}
				$this->fail( 'Unexpected get_row: ' . $sql );
			}
		);

		$wpdb->shouldReceive( 'get_var' )->andReturnUsing(
			function ( $sql ) {
				// The auth-code uniqueness checks, and the profile-row lookup.
				if ( preg_match( '/^SELECT id FROM wp_ffc_(submissions|reregistration_submissions) WHERE auth_code = \S+ LIMIT 1$/', (string) $sql )
					|| preg_match( '/^SELECT id FROM wp_ffc_self_scheduling_appointments WHERE validation_code = \S+ LIMIT 1$/', (string) $sql )
					|| 'SELECT id FROM wp_ffc_user_profiles WHERE user_id = 7' === $sql
				) {
					return null;
				}
				if ( null !== $this->moved_to && 'SELECT status FROM wp_ffc_reregistration_submissions WHERE id = 99' === $sql ) {
					return $this->moved_to;
				}
				$this->fail( 'Unexpected get_var: ' . $sql );
			}
		);

		$wpdb->shouldReceive( 'update' )->andReturnUsing(
			function ( $table, $data, $where ) {
				$this->db_updates[] = array( $table, $data, $where );
				return 1;
			}
		);

		// The conditional write, decoded from its prepare() call into the same
		// [ table, data, where ] shape `update()` records, plus the statuses.
		$wpdb->shouldReceive( 'query' )->andReturnUsing(
			function () {
				list( $template, $values ) = $this->last_prepare;
				if ( 1 !== preg_match( '/^UPDATE %i SET (.+) WHERE id = %d AND status IN \(/', $template, $m ) ) {
					$this->fail( 'Unexpected query: ' . $template );
				}
				$table = array_shift( $values );
				$data  = array();
				foreach ( explode( ', ', $m[1] ) as $assignment ) {
					$column          = array_shift( $values );
					$data[ $column ] = str_ends_with( $assignment, 'NULL' ) ? null : array_shift( $values );
				}
				$id                       = array_shift( $values );
				$this->written_statuses[] = $values;
				if ( null !== $this->moved_to ) {
					return 0;
				}
				$this->db_updates[] = array( $table, $data, array( 'id' => $id ) );
				return 1;
			}
		);

		$wpdb->shouldReceive( 'insert' )->andReturnUsing(
			function ( $table, $data ) {
				$this->db_inserts[] = array( $table, $data );
				return 1;
			}
		);

		$GLOBALS['wpdb'] = $wpdb;
	}

	/**
	 * The one update written to the submissions table, as [ id, data ].
	 *
	 * @return array{0: int, 1: array<string, mixed>}
	 */
	private function the_submission_write(): array {
		$writes = array();
		foreach ( $this->db_updates as $update ) {
			if ( 'wp_ffc_reregistration_submissions' === $update[0] ) {
				$writes[] = array( (int) $update[2]['id'], $update[1] );
			}
		}
		$this->assertCount( 1, $writes, 'process_submission() writes the submission exactly once.' );
		return $writes[0];
	}

	/**
	 * The values written to one user-meta key.
	 *
	 * @param string $key Meta key.
	 * @return list<mixed>
	 */
	private function meta_written( string $key ): array {
		$out = array();
		foreach ( $this->meta_writes as $write ) {
			if ( $key === $write[1] ) {
				$this->assertSame( 7, $write[0], "{$key} is written for the submitting user." );
				$out[] = $write[2];
			}
		}
		return $out;
	}

	public function test_process_submission_persists_encrypts_and_notifies(): void {
		$this->stub_persistence_functions();

		// Two active fields: a sensitive CPF (encrypted + profile-mapped) and a
		// plain custom field (goes into the user snapshot).
		$this->persistence_database(
			array(
				$this->make_field( array(
					'id'                => 1,
					'field_key'         => 'cpf',
					'field_type'        => 'text',
					'is_sensitive'      => 1,
					'field_profile_key' => 'cpf',
				) ),
				$this->make_field( array(
					'id'           => 2,
					'field_key'    => 'nickname',
					'field_type'   => 'text',
					'is_sensitive' => 0,
				) ),
			),
			array( '1' )
		);

		$submission = (object) array( 'id' => 99 );
		$rereg      = (object) array( 'id' => 1, 'auto_approve' => 0 );
		$data       = array( 'fields' => array( 'cpf' => Identifiers::CPF_A, 'nickname' => 'Jo' ) );

		ReregistrationDataProcessor::process_submission( $submission, $rereg, $data, 7 );

		// The writer received the encrypted CPF and a 'submitted' status.
		list( $id, $written ) = $this->the_submission_write();
		$this->assertSame( 99, $id );
		$this->assertSame( 'submitted', $written['status'] );
		$stored = json_decode( (string) $written['data'], true );
		$this->assertNotSame( Identifiers::CPF_A, $stored['fields']['cpf'], 'The sensitive field is not stored in clear.' );
		$this->assertSame( Identifiers::CPF_A, \FreeFormCertificate\Core\Encryption::decrypt( $stored['fields']['cpf'] ) );
		$this->assertSame( 'Jo', $stored['fields']['nickname'] );
		$this->assertArrayNotHasKey( 'auth_code', $written, 'A plain submit issues no code: approval does (#2242).' );
		$this->assertArrayNotHasKey( 'reviewed_at', $written );

		// The profile sync carried the plain CPF and treated it as sensitive:
		// it lands encrypted in the profile meta, with its lookup hash indexed.
		$profile_cpf = $this->meta_written( 'ffc_user_cpf' );
		$this->assertCount( 1, $profile_cpf );
		$this->assertNotSame( Identifiers::CPF_A, $profile_cpf[0] );
		$this->assertSame( Identifiers::CPF_A, \FreeFormCertificate\Core\Encryption::decrypt( $profile_cpf[0] ) );
		$this->assertSame(
			array( array( 'wp_ffc_user_profiles', array( 'cpf_hash' => \FreeFormCertificate\Core\SensitiveFieldRegistry::hash_identifier( 'cpf', Identifiers::CPF_A ), 'user_id' => 7 ) ) ),
			$this->db_inserts
		);

		// The snapshot only carries the non-profile 'nickname' (keyed field_<id>).
		$snapshots = $this->meta_written( 'ffc_custom_fields_data' );
		$this->assertCount( 1, $snapshots );
		$this->assertSame( array( 'field_2' => 'Jo' ), $snapshots[0] );

		// And the confirmation went to the submission's owner, once.
		$this->assertSame( array( 'owner@example.com' ), $this->mailed );
	}

	public function test_process_submission_auto_approve_sets_review_fields(): void {
		$this->stub_persistence_functions();

		// No audiences → no fields, so the field loop is empty.
		$this->persistence_database( array(), array() );

		$submission = (object) array( 'id' => 5 );
		$rereg      = (object) array( 'id' => 1, 'auto_approve' => 1 );

		ReregistrationDataProcessor::process_submission( $submission, $rereg, array( 'fields' => array() ), 3 );

		list( $id, $written ) = $this->the_submission_write();
		$this->assertSame( 5, $id );
		$this->assertSame( 'approved', $written['status'] );
		$this->assertArrayHasKey( 'reviewed_at', $written );
		$this->assertSame( 0, $written['reviewed_by'] );
		$this->assertArrayHasKey( 'notes', $written );
		$this->assertMatchesRegularExpression( '/^[A-Z0-9]{12}$/', $written['auth_code'], 'Auto-approval is an approval: it issues the code (#2242).' );
	}

	public function test_auto_approve_keeps_the_code_a_returned_record_already_holds(): void {
		// Approved, returned to draft, submitted again: the printed record's
		// code comes back instead of a new one (#2242).
		$this->stub_persistence_functions();
		$this->persistence_database( array(), array() );

		$submission = (object) array( 'id' => 5, 'auth_code' => 'KEEPTHISCODE' );
		$rereg      = (object) array( 'id' => 1, 'auto_approve' => 1 );

		ReregistrationDataProcessor::process_submission( $submission, $rereg, array( 'fields' => array() ), 3 );

		$this->assertSame( 'KEEPTHISCODE', $this->the_submission_write()[1]['auth_code'] );
	}

	/**
	 * The bulk-import path (#1214) passes `$notify = false`: the confirmation
	 * reads "we received your reregistration" to somebody who did nothing — an
	 * operator filed the record for them — and at import scale it is one
	 * synchronous `wp_mail` per row inside a batch loop.
	 *
	 * The submission itself is still written; only the e-mail goes. The
	 * database holds everything a confirmation for submission 99 needs, so a
	 * send that slipped past the guard would read the submission and mail its
	 * owner — the companion test above proves it does exactly that.
	 */
	public function test_process_submission_can_suppress_the_confirmation(): void {
		$this->stub_persistence_functions();
		$this->persistence_database( array(), array() );

		$submission = (object) array( 'id' => 99 );
		$rereg      = (object) array( 'id' => 1, 'auto_approve' => 0 );

		ReregistrationDataProcessor::process_submission( $submission, $rereg, array( 'fields' => array() ), 7, false );

		list( $id ) = $this->the_submission_write();
		$this->assertSame( 99, $id );
		$this->assertSame( 0, $this->submission_reads, 'The confirmation is never attempted.' );
		$this->assertSame( array(), $this->mailed );
	}

	/**
	 * A double submit, or a stale tab (#2242): the row already left the open
	 * statuses, so the write changes nothing — and nothing else runs either:
	 * no profile sync, no snapshot, no second confirmation.
	 */
	public function test_process_submission_does_nothing_on_a_row_that_moved_on(): void {
		$this->stub_persistence_functions();
		$this->persistence_database(
			array( $this->make_field( array( 'id' => 1, 'field_key' => 'cpf', 'field_type' => 'text', 'is_sensitive' => 1, 'field_profile_key' => 'cpf' ) ) ),
			array( '1' )
		);
		$this->moved_to = 'submitted';

		$written = ReregistrationDataProcessor::process_submission(
			(object) array( 'id' => 99 ),
			(object) array( 'id' => 1, 'auto_approve' => 0 ),
			array( 'fields' => array( 'cpf' => Identifiers::CPF_A ) ),
			7
		);

		$this->assertFalse( $written );
		$this->assertSame( array(), $this->db_updates, 'Nothing is written.' );
		$this->assertSame( array(), $this->meta_writes, 'No profile sync and no snapshot.' );
		$this->assertSame( array(), $this->mailed, 'No second confirmation.' );
	}

	public function test_process_submission_writes_only_from_an_open_status(): void {
		$this->stub_persistence_functions();
		$this->persistence_database( array(), array() );

		$this->assertTrue( ReregistrationDataProcessor::process_submission( (object) array( 'id' => 99 ), (object) array( 'id' => 1, 'auto_approve' => 0 ), array( 'fields' => array() ), 7, false ) );

		$this->assertSame( array( array( 'pending', 'in_progress', 'rejected' ) ), $this->written_statuses, 'A submitted or approved row is never written over by a submit.' );
	}

	/**
	 * The draft used to be written straight from the form, so sensitive
	 * values sat in clear until the final submit (#2242). Now the draft and
	 * the submission persist through the same encryption.
	 */
	public function test_save_draft_encrypts_the_sensitive_values(): void {
		$this->stub_persistence_functions();
		$this->persistence_database(
			array(
				$this->make_field( array( 'id' => 1, 'field_key' => 'cpf', 'field_type' => 'text', 'is_sensitive' => 1 ) ),
				$this->make_field( array( 'id' => 2, 'field_key' => 'nickname', 'field_type' => 'text', 'is_sensitive' => 0 ) ),
				$this->make_field( array( 'id' => 3, 'field_key' => 'note', 'field_type' => 'acknowledgment' ) ),
			),
			array( '1' )
		);

		$saved = ReregistrationDataProcessor::save_draft(
			(object) array( 'id' => 99 ),
			(object) array( 'id' => 1 ),
			array( 'fields' => array( 'cpf' => Identifiers::CPF_A, 'nickname' => 'Jo' ) )
		);

		$this->assertTrue( $saved );
		list( $id, $written ) = $this->the_submission_write();
		$this->assertSame( 99, $id );
		$this->assertSame( 'in_progress', $written['status'] );
		$stored = json_decode( (string) $written['data'], true );
		$this->assertNotSame( Identifiers::CPF_A, $stored['fields']['cpf'], 'The sensitive value is never stored in clear, not even in a draft.' );
		$this->assertSame( Identifiers::CPF_A, \FreeFormCertificate\Core\Encryption::decrypt( $stored['fields']['cpf'] ) );
		$this->assertSame( 'Jo', $stored['fields']['nickname'] );
		$this->assertArrayNotHasKey( 'note', $stored['fields'], 'Display-only blocks are not stored.' );
		$this->assertSame( array( array( 'pending', 'in_progress', 'rejected' ) ), $this->written_statuses, 'A submitted row cannot be pulled back into a draft.' );
		$this->assertSame( array(), $this->meta_writes, 'A draft does not touch the profile.' );
	}

	public function test_save_draft_refuses_a_row_that_was_already_submitted(): void {
		$this->stub_persistence_functions();
		$this->persistence_database( array(), array() );
		$this->moved_to = 'submitted';

		$this->assertFalse( ReregistrationDataProcessor::save_draft( (object) array( 'id' => 99 ), (object) array( 'id' => 1 ), array( 'fields' => array() ) ) );
		$this->assertSame( array(), $this->db_updates );
	}

	public function test_encrypt_value_wraps_once_and_leaves_empty_alone(): void {
		$this->stub_persistence_functions();

		$envelope = ReregistrationDataProcessor::encrypt_value( Identifiers::CPF_A );
		$this->assertNotSame( Identifiers::CPF_A, $envelope );
		$this->assertSame( $envelope, ReregistrationDataProcessor::encrypt_value( $envelope ), 'An envelope is not wrapped a second time.' );
		$this->assertSame( '', ReregistrationDataProcessor::encrypt_value( '' ) );
		$this->assertSame( null, ReregistrationDataProcessor::encrypt_value( null ) );

		$array = ReregistrationDataProcessor::encrypt_value( array( 'parent' => 'A', 'child' => 'B' ) );
		$this->assertIsString( $array, 'An array is encoded before it is encrypted, so it is never stored in clear.' );
		$this->assertSame( '{"parent":"A","child":"B"}', \FreeFormCertificate\Core\Encryption::decrypt( $array ) );
	}

	// ==================================================================
	// sanitize_values() — the sanitizer, reachable without $_POST
	// ==================================================================

	/**
	 * `collect_form_data()` is `sanitize_values()` over `$_POST`, and the
	 * extraction (#1214) exists so the CSV import sanitizes exactly what a form
	 * submit sanitizes. This asserts the two agree on the same input.
	 */
	public function test_sanitize_values_matches_what_collect_form_data_produces(): void {
		$this->setup_wpdb_with_fields( array(
			$this->make_field( array( 'id' => 1, 'field_key' => 'nickname', 'field_type' => 'text' ) ),
			$this->make_field( array( 'id' => 2, 'field_key' => 'age', 'field_type' => 'number' ) ),
		) );

		$raw = array( 'nickname' => '  <b>Jo</b> ', 'age' => '41' );

		$_POST = array( 'fields' => $raw );
		$from_post = ReregistrationDataProcessor::collect_form_data( $this->make_rereg() );
		$_POST     = array();

		$direct = ReregistrationDataProcessor::sanitize_values( $raw, $this->make_rereg() );

		$this->assertSame( $from_post, $direct );
	}

	/**
	 * The field set comes from the campaign, not from the supplied keys — so a
	 * column the import invented is dropped and a field nobody filled lands
	 * empty, which is what the form submit does with `$_POST`.
	 */
	public function test_sanitize_values_is_bounded_by_the_campaign_fields(): void {
		$this->setup_wpdb_with_fields( array(
			$this->make_field( array( 'id' => 1, 'field_key' => 'nickname', 'field_type' => 'text' ) ),
			$this->make_field( array( 'id' => 2, 'field_key' => 'untouched', 'field_type' => 'text' ) ),
		) );

		$out = ReregistrationDataProcessor::sanitize_values(
			array( 'nickname' => 'Jo', 'not_a_field' => 'x' ),
			$this->make_rereg()
		);

		$this->assertSame( array( 'nickname' => 'Jo', 'untouched' => '' ), $out['fields'] );
	}
}
