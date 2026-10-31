<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FreeFormCertificate\Tests\Support\CallLog;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Reregistration\ReregistrationFieldOptions;
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
	 * `__()` returns its argument in tests, so what is read here is the SOURCE
	 * string. The translation is charged by the next test.
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
	 * The fields that became required, and the one that stays optional.
	 *
	 * `endereco_complemento` stays optional ON PURPOSE -- the address complement
	 * is the field that legitimately does not apply to many addresses.
	 *
	 * @return void
	 */
	public function test_the_revised_fields_are_required(): void {
		$by_key = $this->definitionByKey();

		foreach ( array( 'rf', 'endereco', 'endereco_numero', 'contato_emergencia', 'tel_emergencia', 'email_institucional', 'sindicato' ) as $key ) {
			$this->assertSame( 1, (int) $by_key[ $key ]['required'], "`{$key}` should be required." );
		}

		$this->assertSame( 0, (int) $by_key['endereco_complemento']['required'], 'The address complement stays optional.' );
	}

	/**
	 * Every seeded label has a pt_BR translation. Blocks at ZERO.
	 *
	 * The seeder writes `__()`'s result INTO THE DATABASE, at the moment the
	 * audience is created. So an untranslated label does not degrade to English
	 * on that one screen: it is born in English in the row and stays that way
	 * until somebody renames it in the UI, audience by audience.
	 *
	 * This covers the class, not the five in this batch: changing a source
	 * without adding the translation is the natural slip, and it is what nearly
	 * happened while writing this very issue.
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

		$this->assertNotEmpty( $labels, 'The scan must not pass by being empty.' );

		$missing = array();
		foreach ( $labels as $label ) {
			if ( ! isset( $messages[ $label ] ) ) {
				$missing[] = $label;
			}
		}

		$this->assertSame(
			array(),
			$missing,
			"Seeded label with no pt_BR translation -- a new audience would be born with it in English:\n" . implode( "\n", $missing )
		);
	}

	/**
	 * The two translations that were WRONG, not merely absent.
	 *
	 * `Union` there means the labour union, and it read "Estado" -- colliding
	 * with the address\'s `State`, so the form showed two different things under
	 * the same word. `Acknowledgment` means the acceptance term, and it read
	 * "Agradecimentos"; the code itself already called the thing
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

	// ==================================================================
	// Canonical birth date (#1538)
	// ==================================================================

	/**
	 * `data_nascimento` feeds the canonical profile field, and the key it names
	 * is one the profile map actually declares -- a profile key the map does
	 * not know would be written to an ad-hoc meta nobody reads.
	 */
	public function test_the_birth_date_field_feeds_the_canonical_profile_field(): void {
		$by_key = $this->definitionByKey();

		$this->assertSame( 'birth_date', $by_key['data_nascimento']['profile_key'] );
		$this->assertTrue( \FreeFormCertificate\UserDashboard\UserProfileFieldMap::has( 'birth_date' ) );
	}

	/**
	 * The label-free map the schema heal reads agrees with the definitions,
	 * in both directions -- it exists only so the heal never calls `__()`.
	 */
	public function test_profile_keys_agree_with_the_definitions(): void {
		$declared = array();
		foreach ( ReregistrationStandardFieldsSeeder::get_standard_fields_definition() as $def ) {
			if ( ! empty( $def['profile_key'] ) ) {
				$declared[ (string) $def['field_key'] ] = (string) $def['profile_key'];
			}
		}

		$this->assertNotSame( array(), $declared, 'Read no profile key from the definitions -- the check did not run.' );
		$this->assertSame( $declared, ReregistrationStandardFieldsSeeder::PROFILE_KEYS );
	}

	// ==================================================================
	// relabel_union() -- the "Estado" left behind by #1209
	// ==================================================================

	public function test_register_hooks_one_upgrade_on_admin_init(): void {
		$hooked = array();
		Functions\when( 'add_action' )->alias( function ( $hook, $callback ) use ( &$hooked ) {
			$hooked[ $hook ][] = $callback;
			return true;
		});

		ReregistrationStandardFieldsSeeder::register();

		$this->assertSame(
			array(
				array( ReregistrationStandardFieldsSeeder::class, 'maybe_upgrade_seeded_fields' ),
			),
			$hooked['admin_init'] ?? null
		);
	}

	// ==================================================================
	// maybe_upgrade_seeded_fields() -- one versioned option (#2242)
	// ==================================================================

	/**
	 * Answer every step's read with nothing to change, and record which
	 * steps asked, by the column each step reads first.
	 *
	 * @return \ArrayObject<int, string>
	 */
	private function stepsFindNothing(): \ArrayObject {
		$asked = new \ArrayObject();
		Functions\when( 'sanitize_text_field' )->returnArg();
		// prepare() is called both spread and with an array of values; the
		// field key is the first value after the table either way.
		$this->wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			static fn( ...$args ) => 2 === count( $args ) && is_array( $args[1] ) ? array_merge( array( $args[0] ), $args[1] ) : $args
		);
		$this->wpdb->shouldReceive( 'get_col' )->andReturnUsing(
			static function ( $q ) use ( $asked ) {
				$asked[] = 'col:' . $q[2];
				return array();
			}
		);
		$this->wpdb->shouldReceive( 'get_results' )->andReturnUsing(
			static function ( $q ) use ( $asked ) {
				$asked[] = 'results:' . $q[2];
				return array();
			}
		);
		return $asked;
	}

	public function test_a_fresh_install_runs_every_step_once_and_records_the_version(): void {
		$asked   = $this->stepsFindNothing();
		$written = array();
		$deleted = array();
		Functions\when( 'get_option' )->alias( static fn( $key, $default = false ) => $default );
		Functions\when( 'update_option' )->alias(
			static function ( $key, $value, $autoload = null ) use ( &$written ) {
				$written[ $key ] = array( $value, $autoload );
				return true;
			}
		);
		Functions\when( 'delete_option' )->alias(
			static function ( $key ) use ( &$deleted ) {
				$deleted[] = $key;
				return true;
			}
		);

		ReregistrationStandardFieldsSeeder::maybe_upgrade_seeded_fields();

		$this->assertSame(
			array( 'col:sindicato', 'col:vinculo', 'col:jornada', 'col:horario_trabalho', 'results:jornada', 'results:jornada_acumulo', 'results:email_institucional', 'col:rf', 'col:cep', 'results:cep' ),
			$asked->getArrayCopy(),
			'Each of the six steps runs, in order.'
		);
		$this->assertSame( array( ReregistrationStandardFieldsSeeder::FIELDS_VERSION_OPTION => array( 6, true ) ), $written, 'One autoloaded option, at the last step.' );
		$this->assertSame(
			array( 'ffc_union_label_relabelled', 'ffc_schedule_fields_updated', 'ffc_edu_email_field_added', 'ffc_rf_field_validated', 'ffc_cep_field_validated', 'ffc_cep_before_address' ),
			$deleted,
			'The per-step flags it replaced are removed.'
		);
	}

	public function test_a_current_install_reads_one_option_and_stops(): void {
		$reads = array();
		Functions\when( 'get_option' )->alias(
			static function ( $key, $default = false ) use ( &$reads ) {
				$reads[] = $key;
				return 6;
			}
		);
		$writes = CallLog::record( 'update_option', true );
		$this->wpdb->shouldReceive( 'get_col' )->never();
		$this->wpdb->shouldReceive( 'get_results' )->never();

		ReregistrationStandardFieldsSeeder::maybe_upgrade_seeded_fields();

		$this->assertSame( array( ReregistrationStandardFieldsSeeder::FIELDS_VERSION_OPTION ), $reads );
		$this->assertSame( 0, $writes->count() );
	}

	public function test_an_upgraded_install_skips_the_steps_its_flags_recorded(): void {
		$asked = $this->stepsFindNothing();
		// The install ran every step but the CEP reorder before the version
		// option existed.
		$flags = array( 'ffc_union_label_relabelled', 'ffc_schedule_fields_updated', 'ffc_edu_email_field_added', 'ffc_rf_field_validated', 'ffc_cep_field_validated' );
		Functions\when( 'get_option' )->alias( static fn( $key, $default = false ) => in_array( $key, $flags, true ) ? '1' : $default );
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'delete_option' )->justReturn( true );

		ReregistrationStandardFieldsSeeder::maybe_upgrade_seeded_fields();

		$this->assertSame( array( 'results:cep' ), $asked->getArrayCopy(), 'Only the step never flagged runs.' );
	}

	public function test_a_later_step_runs_without_repeating_the_earlier_ones(): void {
		$asked = $this->stepsFindNothing();
		// Version 4: the two CEP steps are new to this install. A legacy flag
		// left behind is not read once a version exists.
		Functions\when( 'get_option' )->alias(
			static fn( $key, $default = false ) => ReregistrationStandardFieldsSeeder::FIELDS_VERSION_OPTION === $key ? '4' : '1'
		);
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'delete_option' )->justReturn( true );

		ReregistrationStandardFieldsSeeder::maybe_upgrade_seeded_fields();

		$this->assertSame( array( 'col:cep', 'results:cep' ), $asked->getArrayCopy() );
	}

	/**
	 * Only standard `sindicato` rows still labelled "Estado" are relabelled
	 * to the translated "Union".
	 */
	public function test_relabel_union_renames_the_rows_still_labelled_estado(): void {
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_cache_delete' )->justReturn( true );

		$queries = array();
		$this->wpdb->shouldReceive( 'prepare' )->andReturnUsing( function ( ...$args ) use ( &$queries ) {
			$queries[] = $args;
			return $args[0];
		});
		$this->wpdb->shouldReceive( 'get_col' )->andReturn( array( '7', '9', 'junk' ) );
		$this->wpdb->shouldReceive( 'update' )
			->twice()
			->with( 'wp_ffc_custom_fields', array( 'field_label' => 'Union' ), Mockery::on( fn( $where ) => in_array( $where['id'], array( 7, 9 ), true ) ), array( '%s' ), array( '%d' ) )
			->andReturn( 1 );

		ReregistrationStandardFieldsSeeder::relabel_union();

		$select = array_values( array_filter( $queries, fn( $q ) => str_contains( (string) $q[0], 'field_label IN' ) ) );
		$this->assertCount( 1, $select );
		$this->assertStringContainsString( "field_source = 'standard'", $select[0][0] );
		$this->assertSame( array( 'wp_ffc_custom_fields', 'sindicato', 'Estado' ), $select[0][1] );
	}

	// ==================================================================
	// update_schedule_fields() -- the labels and options of #2225
	// ==================================================================

	/**
	 * The three labels move off what was shipped, and the two jornada fields
	 * still on the legacy list take the current one; a list an operator
	 * changed stays.
	 */
	public function test_update_schedule_fields_relabels_and_replaces_only_the_shipped_values(): void {
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		Functions\when( 'wp_json_encode' )->alias( static fn( $v ) => json_encode( $v ) );

		$selects = array();
		$this->wpdb->shouldReceive( 'prepare' )->andReturnUsing( function ( ...$args ) use ( &$selects ) {
			$selects[] = $args;
			return $args;
		});
		$this->wpdb->shouldReceive( 'get_col' )->andReturnUsing( function ( $q ) {
			$values = $q[1];
			return array( 'vinculo' => array( '11' ), 'jornada' => array( '12' ), 'horario_trabalho' => array() )[ $values[1] ] ?? array();
		});
		$legacy = json_encode( array( 'choices' => array( 'JB.30', 'JBD.30', 'JEIF.40', 'JB.20' ) ) );
		$this->wpdb->shouldReceive( 'get_results' )->andReturnUsing( function ( $q ) use ( $legacy ) {
			return 'jornada' === $q[2]
				? array( array( 'id' => '21', 'field_options' => $legacy ), array( 'id' => '22', 'field_options' => json_encode( array( 'choices' => array( 'Edited' ) ) ) ) )
				: array( array( 'id' => '23', 'field_options' => $legacy ) );
		});

		$updates = array();
		$this->wpdb->shouldReceive( 'update' )->andReturnUsing( function ( $table, $data, $where ) use ( &$updates ) {
			$updates[ $where['id'] ] = $data;
			return 1;
		});

		ReregistrationStandardFieldsSeeder::update_schedule_fields();

		$this->assertSame( array( 'field_label' => 'Employment Bond' ), $updates[11] );
		$this->assertSame( array( 'field_label' => 'Workload or Category' ), $updates[12] );
		$current = json_encode( array( 'choices' => ReregistrationFieldOptions::get_work_schedule_options() ) );
		$this->assertSame( array( 'field_options' => $current ), $updates[21] );
		$this->assertSame( array( 'field_options' => $current ), $updates[23], 'The accumulation jornada follows the same list.' );
		$this->assertArrayNotHasKey( 22, $updates, 'A list an operator changed stays.' );
		$this->assertCount( 4, $updates );

		$labels = array_values( array_filter( $selects, fn( $q ) => str_contains( (string) $q[0], 'field_label IN' ) ) );
		$this->assertSame( array( 'wp_ffc_custom_fields', 'horario_trabalho', 'Working Hours', 'Horários de Funcionamento' ), $labels[2][1] );
	}

	// ==================================================================
	// add_edu_email_field() -- the @edu e-mail on seeded audiences
	// ==================================================================

	/**
	 * Every audience carrying the @sme field gains the @edu one at the same
	 * position, except one that already has it.
	 */
	public function test_add_edu_email_field_inserts_beside_the_sme_field(): void {

		$this->wpdb->shouldReceive( 'prepare' )->andReturnUsing( static fn( ...$args ) => $args );
		$this->wpdb->shouldReceive( 'get_results' )->andReturnUsing( function ( $q ) {
			$this->assertSame( 'email_institucional', $q[2] );
			return array(
				array( 'audience_id' => '5', 'sort_order' => '20' ),
				array( 'audience_id' => '6', 'sort_order' => '21' ),
			);
		});
		$this->wpdb->shouldReceive( 'get_col' )->andReturnUsing( static function ( $q ) {
			return 6 === $q[2] ? array( 'email_institucional', 'email_institucional_edu' ) : array( 'email_institucional' );
		});

		$inserted = array();
		$this->wpdb->shouldReceive( 'insert' )->andReturnUsing( function ( $table, $data ) use ( &$inserted ) {
			$inserted[] = $data;
			return 1;
		});

		ReregistrationStandardFieldsSeeder::add_edu_email_field();

		$this->assertCount( 1, $inserted, 'An audience that already has the key is left alone.' );
		$this->assertSame( 5, $inserted[0]['audience_id'] );
		$this->assertSame( 'email_institucional_edu', $inserted[0]['field_key'] );
		$this->assertSame( 'standard', $inserted[0]['field_source'] );
		$this->assertSame( 'email', $inserted[0]['field_mask'] );
		$this->assertSame( 20, $inserted[0]['sort_order'], 'It takes the @sme field\'s position.' );
		$this->assertSame( 0, $inserted[0]['is_required'] );
	}

	public function test_the_edu_email_field_is_seeded_after_the_sme_one(): void {
		$keys = array_column( ReregistrationStandardFieldsSeeder::get_standard_fields_definition(), 'field_key' );
		$sme  = array_search( 'email_institucional', $keys, true );

		$this->assertSame( 'email_institucional_edu', $keys[ $sme + 1 ] );
	}

	// ==================================================================
	// validate_rf_fields() -- the RF format check on seeded rows
	// ==================================================================

	public function test_validate_rf_fields_gives_rule_less_rows_the_rf_format(): void {
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_cache_delete' )->justReturn( true );

		$this->wpdb->shouldReceive( 'prepare' )->andReturnUsing( static fn( ...$args ) => $args );
		$this->wpdb->shouldReceive( 'get_col' )->andReturnUsing( function ( $q ) {
			$this->assertStringContainsString( 'validation_rules IS NULL', $q[0] );
			$this->assertSame( 'rf', $q[2] );
			return array( '31', '32' );
		});
		$updates = array();
		$this->wpdb->shouldReceive( 'update' )->andReturnUsing( function ( $table, $data, $where ) use ( &$updates ) {
			$updates[ $where['id'] ] = $data;
			return 1;
		});

		ReregistrationStandardFieldsSeeder::validate_rf_fields();

		$expected = array( 'validation_rules' => json_encode( array( 'format' => 'rf' ) ) );
		$this->assertSame( array( 31 => $expected, 32 => $expected ), $updates );
	}

	public function test_the_rf_field_is_seeded_with_the_rf_format(): void {
		$defs = array_column( ReregistrationStandardFieldsSeeder::get_standard_fields_definition(), null, 'field_key' );

		$this->assertSame( array( 'format' => 'rf' ), $defs['rf']['validation'] );
	}

	// ==================================================================
	// validate_cep_fields() -- the CEP format check on seeded rows (#2233)
	// ==================================================================

	public function test_validate_cep_fields_gives_rule_less_rows_the_cep_format(): void {
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_cache_delete' )->justReturn( true );

		$this->wpdb->shouldReceive( 'prepare' )->andReturnUsing( static fn( ...$args ) => $args );
		$this->wpdb->shouldReceive( 'get_col' )->andReturnUsing( function ( $q ) {
			$this->assertStringContainsString( 'validation_rules IS NULL', $q[0] );
			$this->assertSame( 'cep', $q[2] );
			return array( '41' );
		});
		$updates = array();
		$this->wpdb->shouldReceive( 'update' )->andReturnUsing( function ( $table, $data, $where ) use ( &$updates ) {
			$updates[ $where['id'] ] = $data;
			return 1;
		});

		ReregistrationStandardFieldsSeeder::validate_cep_fields();

		$this->assertSame( array( 41 => array( 'validation_rules' => json_encode( array( 'format' => 'cep' ) ) ) ), $updates );
	}

	public function test_the_cep_field_is_seeded_with_the_cep_format(): void {
		$defs = array_column( ReregistrationStandardFieldsSeeder::get_standard_fields_definition(), null, 'field_key' );

		$this->assertSame( array( 'format' => 'cep' ), $defs['cep']['validation'] );
	}

	// ==================================================================
	// move_cep_before_address_everywhere() -- the CEP typed before the address (#2234)
	// ==================================================================

	/**
	 * Answer the two reads the reorder makes: which audiences have a
	 * standard `cep`, and each audience's fields in their order.
	 *
	 * @param array<int, list<array{0: int, 1: string, 2: int}>> $audiences id => [field id, key, sort order], in query order.
	 * @param array<int, array<string, mixed>>                    $updates   Writes by field id, filled as they happen.
	 * @return void
	 */
	private function audiences_with_fields( array $audiences, array &$updates ): void {
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		$this->wpdb->shouldReceive( 'prepare' )->andReturnUsing( static fn( ...$args ) => $args );
		$this->wpdb->shouldReceive( 'get_results' )->andReturnUsing(
			static function ( $q ) use ( $audiences ) {
				if ( false !== strpos( $q[0], 'field_source' ) ) {
					return array_map(
						static fn( $id ) => array( 'audience_id' => (string) $id, 'sort_order' => '0' ),
						array_keys( $audiences )
					);
				}
				$fields = $audiences[ (int) $q[1][1] ] ?? array();
				return array_map(
					static fn( $f ) => (object) array( 'id' => (string) $f[0], 'field_key' => $f[1], 'sort_order' => (string) $f[2] ),
					$fields
				);
			}
		);
		$this->wpdb->shouldReceive( 'update' )->andReturnUsing(
			static function ( $table, $data, $where ) use ( &$updates ) {
				$updates[ (int) $where['id'] ] = $data;
				return 1;
			}
		);
	}

	public function test_move_cep_puts_it_right_before_the_address_and_renumbers(): void {
		$updates = array();
		$this->audiences_with_fields(
			array(
				// The seeded order: the CEP last in the contact group.
				7 => array( array( 1, 'nome_completo', 0 ), array( 2, 'endereco', 1 ), array( 3, 'endereco_numero', 2 ), array( 4, 'bairro', 3 ), array( 5, 'cep', 4 ), array( 6, 'telefone', 5 ) ),
				// Reordered by hand, with a tie, listed as the query sorts it:
				// still moved, and the tie renumbered.
				8 => array( array( 11, 'endereco', 3 ), array( 12, 'cidade', 3 ), array( 10, 'cep', 9 ) ),
			),
			$updates
		);

		ReregistrationStandardFieldsSeeder::move_cep_before_address_everywhere();

		$this->assertSame(
			array(
				5  => array( 'sort_order' => 1 ),
				2  => array( 'sort_order' => 2 ),
				3  => array( 'sort_order' => 3 ),
				4  => array( 'sort_order' => 4 ),
				10 => array( 'sort_order' => 0 ),
				11 => array( 'sort_order' => 1 ),
				12 => array( 'sort_order' => 2 ),
			),
			$updates,
			'Only the rows whose position changed are written; nome_completo and telefone keep theirs.'
		);
	}

	public function test_move_cep_leaves_an_audience_already_in_order_or_missing_a_field(): void {
		$updates = array();
		$this->audiences_with_fields(
			array(
				7 => array( array( 1, 'cep', 0 ), array( 2, 'endereco', 1 ) ),
				8 => array( array( 3, 'cep', 0 ), array( 4, 'bairro', 1 ) ),
			),
			$updates
		);

		ReregistrationStandardFieldsSeeder::move_cep_before_address_everywhere();

		$this->assertSame( array(), $updates );
	}

	public function test_the_cep_is_seeded_right_before_the_address(): void {
		$keys = array_column( ReregistrationStandardFieldsSeeder::get_standard_fields_definition(), 'field_key' );

		$this->assertSame( array_search( 'endereco', $keys, true ) - 1, array_search( 'cep', $keys, true ) );
	}
}
