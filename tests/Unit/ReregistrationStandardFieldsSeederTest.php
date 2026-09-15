<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Reregistration\ReregistrationStandardFieldsSeeder;

/**
 * Tests for ReregistrationStandardFieldsSeeder: field definitions integrity,
 * group labels, seed_for_audience idempotency, and hook registration.
 *
 * @covers \FreeFormCertificate\Reregistration\ReregistrationStandardFieldsSeeder
 */
class ReregistrationStandardFieldsSeederTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private $wpdb;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		global $wpdb;
		$wpdb = Mockery::mock( 'wpdb' )->makePartial();
		$wpdb->prefix     = 'wp_';
		$wpdb->last_error  = '';
		$wpdb->insert_id   = 0;
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing( function () { return func_get_args()[0]; } )->byDefault();
		$wpdb->shouldReceive( 'get_col' )->andReturn( array() )->byDefault();
		$wpdb->shouldReceive( 'insert' )->andReturn( 1 )->byDefault();

		$this->wpdb = $wpdb;

		Functions\when( '__' )->returnArg();
		Functions\when( 'wp_json_encode' )->alias( function ( $v ) { return json_encode( $v ); } );
		Functions\when( 'add_action' )->justReturn( true );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// ==================================================================
	// Rótulos e obrigatoriedade revisados (#1209)
	// ==================================================================

	/**
	 * Os cinco rótulos revisados carregam a string-fonte nova.
	 *
	 * `__()` devolve o argumento nos testes, então o que se lê aqui é a
	 * string-FONTE. A tradução é cobrada pelo teste seguinte.
	 *
	 * @return void
	 */
	public function test_the_revised_fields_carry_the_new_source_label(): void {
		$by_key = $this->definitionByKey();

		$this->assertSame( 'Functional Registry (RF)', $by_key['rf']['field_label'] );
		$this->assertSame( 'Address Complement', $by_key['endereco_complemento']['field_label'] );
		$this->assertSame( 'Emergency Contact Name', $by_key['contato_emergencia']['field_label'] );
		$this->assertSame( 'Emergency Contact Phone', $by_key['tel_emergencia']['field_label'] );
		$this->assertSame( 'Institutional Email (@sme)', $by_key['email_institucional']['field_label'] );
	}

	/**
	 * Os campos que passaram a obrigatórios, e o que segue opcional.
	 *
	 * `endereco_complemento` continua opcional DE PROPÓSITO -- complemento é
	 * o campo que legitimamente não se aplica a muitos endereços.
	 *
	 * @return void
	 */
	public function test_the_revised_fields_are_required(): void {
		$by_key = $this->definitionByKey();

		foreach ( array( 'rf', 'endereco', 'endereco_numero', 'contato_emergencia', 'tel_emergencia', 'email_institucional', 'sindicato' ) as $key ) {
			$this->assertSame( 1, (int) $by_key[ $key ]['required'], "`{$key}` deveria ser obrigatório." );
		}

		$this->assertSame( 0, (int) $by_key['endereco_complemento']['required'], 'Complemento segue opcional.' );
	}

	/**
	 * Todo rótulo semeado tem tradução pt_BR. Bloqueia em ZERO.
	 *
	 * O seeder grava o resultado de `__()` NO BANCO, no momento em que o
	 * público é criado. Então um rótulo sem tradução não degrada para
	 * inglês só naquela tela: ele nasce em inglês na linha e fica assim até
	 * alguém renomear na UI, público por público.
	 *
	 * Isto cobre a classe, não os cinco desta leva: mudar uma string-fonte
	 * sem acrescentar a tradução é o engano natural, e foi o que quase
	 * aconteceu ao escrever esta própria issue.
	 *
	 * @return void
	 */
	public function test_every_seeded_label_has_a_pt_br_translation(): void {
		$messages = $this->ptBrMessages();

		$labels = array();
		foreach ( $this->definitionByKey() as $def ) {
			$labels[] = (string) $def['field_label'];
		}
		foreach ( ReregistrationStandardFieldsSeeder::get_group_labels() as $label ) {
			$labels[] = (string) $label;
		}
		$labels = array_values( array_unique( $labels ) );

		$this->assertNotEmpty( $labels, 'A varredura não pode passar por vazia.' );

		$missing = array();
		foreach ( $labels as $label ) {
			if ( ! isset( $messages[ $label ] ) ) {
				$missing[] = $label;
			}
		}

		$this->assertSame(
			array(),
			$missing,
			"Rótulo semeado sem tradução pt_BR -- um público novo nasceria com ele em inglês:\n" . implode( "\n", $missing )
		);
	}

	/**
	 * As duas traduções que estavam ERRADAS, não apenas ausentes.
	 *
	 * `Union` ali é sindicato, e estava como "Estado" -- colidindo com o
	 * `State` do endereço, de modo que o formulário mostrava duas coisas
	 * diferentes sob a mesma palavra. `Acknowledgment` é ciência/aceite, e
	 * estava como "Agradecimentos"; o próprio código já chamava a coisa de
	 * `get_default_acknowledgment_html()`.
	 *
	 * @return void
	 */
	public function test_the_two_corrected_translations(): void {
		$messages = $this->ptBrMessages();

		$this->assertSame( 'Sindicato', $messages['Union'] ?? null );
		$this->assertSame( 'Termo de Ciência', $messages['Acknowledgment'] ?? null );
	}

	/**
	 * Definições do seeder indexadas por `field_key`.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function definitionByKey(): array {
		$ref = new \ReflectionMethod( ReregistrationStandardFieldsSeeder::class, 'get_standard_fields_definition' );
		$ref->setAccessible( true );

		$by_key = array();
		foreach ( (array) $ref->invoke( null ) as $def ) {
			$by_key[ (string) $def['field_key'] ] = $def;
		}

		return $by_key;
	}

	/**
	 * Mensagens do catálogo pt_BR.
	 *
	 * @return array<string, string>
	 */
	private function ptBrMessages(): array {
		$catalog = include dirname( __DIR__, 2 ) . '/languages/ffcertificate-pt_BR.l10n.php';

		return is_array( $catalog['messages'] ?? null ) ? $catalog['messages'] : array();
	}

	// ==================================================================
	// get_group_labels()
	// ==================================================================

	public function test_get_group_labels_returns_all_groups(): void {
		$labels = ReregistrationStandardFieldsSeeder::get_group_labels();

		$this->assertArrayHasKey( ReregistrationStandardFieldsSeeder::GROUP_PERSONAL, $labels );
		$this->assertArrayHasKey( ReregistrationStandardFieldsSeeder::GROUP_CONTACT, $labels );
		$this->assertArrayHasKey( ReregistrationStandardFieldsSeeder::GROUP_SCHEDULE, $labels );
		$this->assertArrayHasKey( ReregistrationStandardFieldsSeeder::GROUP_ACCUMULATION, $labels );
		$this->assertArrayHasKey( ReregistrationStandardFieldsSeeder::GROUP_UNION, $labels );
	}

	public function test_group_constants_are_strings(): void {
		$this->assertSame( 'personal', ReregistrationStandardFieldsSeeder::GROUP_PERSONAL );
		$this->assertSame( 'contact', ReregistrationStandardFieldsSeeder::GROUP_CONTACT );
		$this->assertSame( 'schedule', ReregistrationStandardFieldsSeeder::GROUP_SCHEDULE );
		$this->assertSame( 'accumulation', ReregistrationStandardFieldsSeeder::GROUP_ACCUMULATION );
		$this->assertSame( 'union', ReregistrationStandardFieldsSeeder::GROUP_UNION );
	}

	// ==================================================================
	// get_standard_fields_definition()
	// ==================================================================

	public function test_standard_fields_definition_not_empty(): void {
		$defs = ReregistrationStandardFieldsSeeder::get_standard_fields_definition();
		$this->assertNotEmpty( $defs );
		$this->assertGreaterThanOrEqual( 20, count( $defs ) );
	}

	public function test_standard_fields_have_required_keys(): void {
		$defs = ReregistrationStandardFieldsSeeder::get_standard_fields_definition();
		$required_keys = array( 'field_key', 'field_label', 'field_type', 'field_group', 'required' );

		foreach ( $defs as $i => $def ) {
			foreach ( $required_keys as $key ) {
				$this->assertArrayHasKey( $key, $def, "Definition at index {$i} missing key '{$key}'" );
			}
		}
	}

	public function test_standard_fields_have_unique_keys(): void {
		$defs = ReregistrationStandardFieldsSeeder::get_standard_fields_definition();
		$keys = array_column( $defs, 'field_key' );

		$this->assertSame( count( $keys ), count( array_unique( $keys ) ), 'Duplicate field_keys found' );
	}

	public function test_standard_fields_use_valid_groups(): void {
		$defs   = ReregistrationStandardFieldsSeeder::get_standard_fields_definition();
		$labels = ReregistrationStandardFieldsSeeder::get_group_labels();
		$valid  = array_keys( $labels );

		foreach ( $defs as $def ) {
			$this->assertContains( $def['field_group'], $valid, "Invalid group '{$def['field_group']}' for key '{$def['field_key']}'" );
		}
	}

	public function test_standard_fields_use_valid_types(): void {
		$valid_types = array( 'text', 'select', 'date', 'working_hours', 'dependent_select', 'acknowledgment' );
		$defs = ReregistrationStandardFieldsSeeder::get_standard_fields_definition();

		foreach ( $defs as $def ) {
			$this->assertContains( $def['field_type'], $valid_types, "Invalid type '{$def['field_type']}' for key '{$def['field_key']}'" );
		}
	}

	public function test_acknowledgment_field_is_display_only_with_default_html(): void {
		$defs  = ReregistrationStandardFieldsSeeder::get_standard_fields_definition();
		$found = false;
		foreach ( $defs as $def ) {
			if ( 'acknowledgment' === $def['field_key'] ) {
				$this->assertSame( 'acknowledgment', $def['field_type'] );
				$this->assertSame( ReregistrationStandardFieldsSeeder::GROUP_ACKNOWLEDGMENT, $def['field_group'] );
				$this->assertSame( 0, $def['required'] );
				$this->assertSame( 0, $def['is_sensitive'] );
				$this->assertNull( $def['profile_key'] );
				$this->assertArrayHasKey( 'html', $def['options'] );
				$this->assertNotEmpty( $def['options']['html'] );
				$found = true;
				break;
			}
		}
		$this->assertTrue( $found, 'acknowledgment field not found' );
	}

	public function test_acknowledgment_field_is_seeded_last(): void {
		// Sort order = array index, so the acknowledgment block must render
		// after every other group's fields.
		$defs = ReregistrationStandardFieldsSeeder::get_standard_fields_definition();
		$last = end( $defs );
		$this->assertSame( 'acknowledgment', $last['field_key'] );
	}

	public function test_cpf_field_has_validation_format(): void {
		$defs = ReregistrationStandardFieldsSeeder::get_standard_fields_definition();
		$cpf  = null;
		foreach ( $defs as $def ) {
			if ( 'cpf' === $def['field_key'] ) {
				$cpf = $def;
				break;
			}
		}

		$this->assertNotNull( $cpf, 'CPF field not found' );
		$this->assertSame( 'cpf', $cpf['validation']['format'] );
		$this->assertSame( 1, $cpf['is_sensitive'] );
		$this->assertSame( 1, $cpf['required'] );
	}

	public function test_division_sector_is_dependent_select(): void {
		$defs = ReregistrationStandardFieldsSeeder::get_standard_fields_definition();
		$found = false;
		foreach ( $defs as $def ) {
			if ( 'divisao_setor' === $def['field_key'] ) {
				$this->assertSame( 'dependent_select', $def['field_type'] );
				$this->assertArrayHasKey( 'groups', $def['options'] );
				$found = true;
				break;
			}
		}
		$this->assertTrue( $found, 'divisao_setor field not found' );
	}

	// ==================================================================
	// seed_for_audience()
	// ==================================================================

	public function test_seed_for_audience_returns_zero_for_invalid_id(): void {
		$this->assertSame( 0, ReregistrationStandardFieldsSeeder::seed_for_audience( 0 ) );
		$this->assertSame( 0, ReregistrationStandardFieldsSeeder::seed_for_audience( -1 ) );
	}

	public function test_seed_for_audience_inserts_all_fields(): void {
		$defs = ReregistrationStandardFieldsSeeder::get_standard_fields_definition();

		$this->wpdb->shouldReceive( 'get_col' )->once()->andReturn( array() );
		$this->wpdb->shouldReceive( 'insert' )->times( count( $defs ) )->andReturn( 1 );

		$inserted = ReregistrationStandardFieldsSeeder::seed_for_audience( 1 );

		$this->assertSame( count( $defs ), $inserted );
	}

	public function test_seed_for_audience_skips_existing_keys(): void {
		$this->wpdb->shouldReceive( 'get_col' )->once()->andReturn( array( 'display_name', 'cpf' ) );

		$defs = ReregistrationStandardFieldsSeeder::get_standard_fields_definition();
		$expected = count( $defs ) - 2;

		$this->wpdb->shouldReceive( 'insert' )->times( $expected )->andReturn( 1 );

		$inserted = ReregistrationStandardFieldsSeeder::seed_for_audience( 1 );

		$this->assertSame( $expected, $inserted );
	}

	public function test_seed_for_audience_handles_insert_failure(): void {
		$this->wpdb->shouldReceive( 'get_col' )->andReturn( array() );
		$this->wpdb->shouldReceive( 'insert' )->andReturn( false );

		$inserted = ReregistrationStandardFieldsSeeder::seed_for_audience( 1 );

		$this->assertSame( 0, $inserted );
	}

	// ==================================================================
	// on_audience_created()
	// ==================================================================

	public function test_on_audience_created_skips_invalid_id(): void {
		$this->wpdb->shouldNotReceive( 'get_col' );
		ReregistrationStandardFieldsSeeder::on_audience_created( 0 );
		$this->assertTrue( true );
	}

	// ==================================================================
	// register()
	// ==================================================================

	public function test_register_hooks_audience_created(): void {
		$hooked = array();
		Functions\when( 'add_action' )->alias( function ( $hook, $callback, $priority = 10, $args = 1 ) use ( &$hooked ) {
			$hooked[] = array( 'hook' => $hook, 'callback' => $callback, 'priority' => $priority, 'args' => $args );
			return true;
		});

		ReregistrationStandardFieldsSeeder::register();

		$match = array_filter( $hooked, function ( $h ) {
			return 'ffc_audience_created' === $h['hook'];
		});
		$this->assertNotEmpty( $match, 'Expected add_action for ffc_audience_created' );
	}

	// ==================================================================
	// replicate_field_options_to_descendants()
	// ==================================================================

	public function test_replicate_returns_zero_for_invalid_audience(): void {
		$this->assertSame( 0, ReregistrationStandardFieldsSeeder::replicate_field_options_to_descendants( 0 ) );
	}

	public function test_replicate_returns_zero_when_parent_has_no_standard_options(): void {
		// get_by_audience(parent) → get_results returns no fields → no
		// parent options to replicate → early return 0.
		$this->wpdb->shouldReceive( 'get_results' )->andReturn( array() );

		$this->assertSame( 0, ReregistrationStandardFieldsSeeder::replicate_field_options_to_descendants( 5 ) );
	}

	public function test_replicate_copies_parent_options_to_descendant_fields(): void {
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'wp_parse_args' )->alias( function ( $args, $defaults = array() ) {
			return is_array( $args ) ? array_merge( $defaults, $args ) : $defaults;
		} );
		Functions\when( 'sanitize_sql_orderby' )->returnArg();
		Functions\when( 'absint' )->alias( function ( $v ) { return abs( (int) $v ); } );

		$parent_field = (object) array(
			'id'            => 10,
			'field_key'     => 'divisao_setor',
			'field_source'  => 'standard',
			'field_options' => json_encode( array( 'groups' => array( 'D' => array( 'S' ) ), 'parent_label' => 'Division' ) ),
		);
		$child_audience = (object) array( 'id' => 99, 'parent_id' => '5' );
		$child_field    = (object) array( 'id' => 77, 'field_key' => 'divisao_setor', 'field_source' => 'standard', 'field_options' => null );

		// get_results: 1) parent fields, 2) children of parent, 3) children of child (none).
		$this->wpdb->shouldReceive( 'get_results' )->andReturn(
			array( $parent_field ),
			array( $child_audience ),
			array()
		);
		// get_by_key(99, 'divisao_setor') → child's field.
		$this->wpdb->shouldReceive( 'get_row' )->andReturn( $child_field );

		$captured = null;
		$this->wpdb->shouldReceive( 'update' )->andReturnUsing(
			function ( $table, $data, $where ) use ( &$captured ) {
				$captured = array( 'data' => $data, 'where' => $where );
				return 1;
			}
		);

		$updated = ReregistrationStandardFieldsSeeder::replicate_field_options_to_descendants( 5 );

		$this->assertSame( 1, $updated );
		$this->assertNotNull( $captured );
		$this->assertSame( 77, $captured['where']['id'] );
		$decoded = json_decode( $captured['data']['field_options'], true );
		$this->assertArrayHasKey( 'groups', $decoded );
		$this->assertSame( array( 'D' => array( 'S' ) ), $decoded['groups'] );
	}
}
