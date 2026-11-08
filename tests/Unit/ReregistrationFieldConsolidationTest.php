<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Reregistration\CustomFieldAnswerMerge;
use FreeFormCertificate\Reregistration\ReregistrationStandardFieldsSeeder;

/**
 * Tests for folding every audience's own copy of a field into the field it
 * inherits (#2502), over an in-memory `ffc_custom_fields` and `ffc_audiences`.
 *
 * Audiences: 1 (top level) and 2 (its child). Each carries a copy of the
 * standard `sindicato` field, as the seeder gave them before #2502.
 *
 * @covers \FreeFormCertificate\Reregistration\ReregistrationStandardFieldsSeeder
 * @covers \FreeFormCertificate\Reregistration\CustomFieldWriter
 */
class ReregistrationFieldConsolidationTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var array<int, array<string, mixed>> Field rows by id. */
	private array $fields = array();

	/** @var array<int, array<string, mixed>> Audience rows by id. */
	private array $audiences = array();

	/** @var array<string, mixed> Options. */
	private array $options = array();

	/** @var list<int> Field ids somebody's profile holds an answer under. */
	private array $answered = array();

	/** @var int Next insert id. */
	private int $next_id = 1000;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		CustomFieldAnswerMerge::reset();

		$this->options   = array();
		$this->answered  = array();
		Functions\when( 'get_users' )->alias(
			function ( $args ) {
				foreach ( $this->answered as $id ) {
					if ( '"field_' . $id . '";' === $args['meta_value'] ) {
						return array( 7 );
					}
				}
				return array();
			}
		);
		$this->audiences = array(
			1 => array( 'id' => 1, 'name' => 'Teachers', 'parent_id' => null ),
			2 => array( 'id' => 2, 'name' => 'Math', 'parent_id' => 1 ),
		);
		$this->fields    = array();

		Functions\when( '__' )->returnArg();
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		Functions\when( 'get_option' )->alias( fn( $key, $default = false ) => $this->options[ $key ] ?? $default );
		Functions\when( 'update_option' )->alias(
			function ( $key, $value ) {
				$this->options[ $key ] = $value;
				return true;
			}
		);

		global $wpdb;
		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			static fn( ...$args ) => 2 === count( $args ) && is_array( $args[1] ) ? array_merge( array( $args[0] ), $args[1] ) : $args
		);
		$wpdb->shouldReceive( 'get_col' )->andReturnUsing(
			function ( $q ) {
				if ( 'SELECT id FROM %i' === $q[0] ) {
					return array_map( 'strval', array_keys( $this->audiences ) );
				}
				// Keys a level carries.
				return array_values( array_map( static fn( $f ) => $f['field_key'], $this->level( (int) $q[2] ) ) );
			}
		);
		$wpdb->shouldReceive( 'get_row' )->andReturnUsing(
			function ( $q ) {
				$rows = 'wp_ffc_audiences' === $q[1] ? $this->audiences : $this->fields;
				return isset( $rows[ (int) $q[2] ] ) ? (object) $rows[ (int) $q[2] ] : null;
			}
		);
		$wpdb->shouldReceive( 'get_results' )->andReturnUsing(
			fn( $q ) => array_map( static fn( $f ) => (object) $f, $this->level( (int) $q[2] ) )
		);
		$wpdb->shouldReceive( 'insert' )->andReturnUsing(
			function ( $table, $data ) use ( $wpdb ) {
				$wpdb->insert_id = $this->next_id++;
				$this->fields[ $wpdb->insert_id ] = array_merge(
					array( 'id' => $wpdb->insert_id, 'overrides_field_id' => null, 'is_hidden' => 0 ),
					$data
				);
				return 1;
			}
		);
		$wpdb->shouldReceive( 'update' )->andReturnUsing(
			function ( $table, $data, $where ) {
				$this->fields[ $where['id'] ] = array_merge( $this->fields[ $where['id'] ], $data );
				return 1;
			}
		);
		$wpdb->shouldReceive( 'delete' )->andReturnUsing(
			function ( $table, $where ) {
				unset( $this->fields[ $where['id'] ] );
				return 1;
			}
		);
	}

	protected function tearDown(): void {
		CustomFieldAnswerMerge::reset();
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * The rows of one level, ordered as the reader orders them.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function level( int $audience_id ): array {
		$rows = array_values( array_filter( $this->fields, static fn( $f ) => (int) $f['audience_id'] === $audience_id ) );
		usort( $rows, static fn( $a, $b ) => array( (int) $a['sort_order'], (int) $a['id'] ) <=> array( (int) $b['sort_order'], (int) $b['id'] ) );
		return $rows;
	}

	/**
	 * A copy of the standard `sindicato` field.
	 */
	private function copy( int $id, int $audience_id, array $overrides = array() ): void {
		$this->fields[ $id ] = array_merge(
			array(
				'id'                 => $id,
				'audience_id'        => $audience_id,
				'overrides_field_id' => null,
				'field_key'          => 'sindicato',
				'field_label'        => 'Union',
				'field_type'         => 'select',
				'field_group'        => 'union',
				'field_source'       => 'standard',
				'field_profile_key'  => null,
				'field_mask'         => null,
				'is_sensitive'       => 0,
				'field_options'      => '{"choices":["A","B"]}',
				'validation_rules'   => null,
				'sort_order'         => 27,
				'is_required'        => 0,
				'is_active'          => 1,
				'is_hidden'          => 0,
			),
			$overrides
		);
	}

	/**
	 * The global row of a key.
	 *
	 * @return array<string, mixed>
	 */
	private function global_row( string $key ): array {
		foreach ( $this->level( 0 ) as $row ) {
			if ( $key === $row['field_key'] ) {
				return $row;
			}
		}
		$this->fail( "No global {$key}" );
	}

	public function test_identical_copies_become_inheritance_and_the_global_field_takes_their_values(): void {
		$this->copy( 11, 1, array( 'field_options' => '{"choices":["Ours"]}' ) );
		$this->copy( 21, 2, array( 'field_options' => '{ "choices" : [ "Ours" ] }' ) );

		ReregistrationStandardFieldsSeeder::consolidate_into_global();

		$global = $this->global_row( 'sindicato' );
		$this->assertSame( '{"choices":["Ours"]}', $global['field_options'], 'The global field takes the values the top-level audiences gave their copy.' );
		$this->assertArrayNotHasKey( 11, $this->fields );
		$this->assertArrayNotHasKey( 21, $this->fields, 'The same options spelled differently are the same options.' );
		$map = CustomFieldAnswerMerge::map();
		$this->assertSame( array( (int) $global['id'], 1, 0, 'sindicato' ), $map[11] );
		$this->assertSame( array( (int) $global['id'], 2, 1, 'sindicato' ), $map[21] );
	}

	public function test_a_copy_that_differs_becomes_the_audiences_change(): void {
		$this->copy( 11, 1 );
		$this->copy( 21, 2, array( 'field_label' => 'Math union' ) );

		ReregistrationStandardFieldsSeeder::consolidate_into_global();

		$global = $this->global_row( 'sindicato' );
		$this->assertArrayNotHasKey( 11, $this->fields );
		$this->assertSame( (int) $global['id'], $this->fields[21]['overrides_field_id'] );
		$this->assertSame( 0, $this->fields[21]['is_hidden'] );
		$this->assertSame( 'Math union', $this->fields[21]['field_label'] );
	}

	public function test_an_inactive_copy_hides_the_field_in_that_audience(): void {
		$this->copy( 11, 1 );
		$this->copy( 21, 2, array( 'is_active' => 0 ) );

		ReregistrationStandardFieldsSeeder::consolidate_into_global();

		$this->assertSame( 1, $this->fields[21]['is_hidden'] );
		$this->assertSame( 1, $this->fields[21]['is_active'], 'A change is always active; hiding is what stops the asking.' );
	}

	/**
	 * A sub-audience compares with what its parent became: a copy equal to
	 * the parent's change is inheritance from the parent.
	 */
	public function test_a_child_equal_to_its_parents_change_inherits_it(): void {
		$this->copy( 11, 1, array( 'field_label' => 'Teachers union' ) );
		// Two top-level audiences kept the label, so it is the global one.
		$this->copy( 12, 3 );
		$this->copy( 13, 4 );
		$this->audiences[3] = array( 'id' => 3, 'name' => 'Staff', 'parent_id' => null );
		$this->audiences[4] = array( 'id' => 4, 'name' => 'Interns', 'parent_id' => null );
		$this->copy( 21, 2, array( 'field_label' => 'Teachers union' ) );

		ReregistrationStandardFieldsSeeder::consolidate_into_global();

		$this->assertSame( 'Union', $this->global_row( 'sindicato' )['field_label'] );
		$this->assertNotNull( $this->fields[11]['overrides_field_id'] ?? null, 'The parent keeps its label as a change.' );
		$this->assertArrayNotHasKey( 21, $this->fields, 'The child inherits the parent\'s change.' );
	}

	/**
	 * A copy whose type differs cannot be read as the inherited field: it
	 * stays, with its answers.
	 */
	public function test_an_answered_copy_of_another_type_stays_as_it_is(): void {
		$this->answered = array( 21 );
		$this->copy( 11, 1 );
		$this->copy( 21, 2, array( 'field_type' => 'text' ) );

		ReregistrationStandardFieldsSeeder::consolidate_into_global();

		$this->assertNull( $this->fields[21]['overrides_field_id'] );
		$this->assertArrayNotHasKey( 21, CustomFieldAnswerMerge::map() );
	}

	/**
	 * Nothing is stored under a copy nobody answered: it inherits the field
	 * even though its type differs.
	 */
	public function test_an_unanswered_copy_of_another_type_inherits(): void {
		$this->copy( 11, 1 );
		$this->copy( 21, 2, array( 'field_type' => 'text' ) );

		ReregistrationStandardFieldsSeeder::consolidate_into_global();

		$this->assertArrayNotHasKey( 21, $this->fields );
		$this->assertArrayNotHasKey( 21, CustomFieldAnswerMerge::map(), 'No answer to move.' );
	}

	public function test_a_field_only_one_audience_asks_stays_its_own(): void {
		$this->copy( 11, 1, array( 'field_key' => 'custom_question', 'field_source' => 'custom' ) );

		ReregistrationStandardFieldsSeeder::consolidate_into_global();

		$this->assertNull( $this->fields[11]['overrides_field_id'] );
		$this->assertSame( array(), CustomFieldAnswerMerge::map() );
	}
}
