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
        $wpdb = Mockery::mock( 'wpdb' );
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
        // get_standard_fields_definition() resolves the divisao_setor map via
        // SettingsReader → get_option. Empty option → hardcoded default.
        Functions\when( 'get_option' )->alias( function ( $key, $default = null ) {
            return 'ffc_settings' === $key ? array() : $default;
        } );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
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
        $valid_types = array( 'text', 'select', 'date', 'working_hours', 'dependent_select' );
        $defs = ReregistrationStandardFieldsSeeder::get_standard_fields_definition();

        foreach ( $defs as $def ) {
            $this->assertContains( $def['field_type'], $valid_types, "Invalid type '{$def['field_type']}' for key '{$def['field_key']}'" );
        }
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

    public function test_divisao_setor_is_dependent_select(): void {
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
    // resync_divisao_setor_groups()
    // ==================================================================

    public function test_resync_returns_zero_when_no_audiences(): void {
        // get_col default returns array() → AudienceRepository::get_all_ids() empty.
        $this->assertSame( 0, ReregistrationStandardFieldsSeeder::resync_divisao_setor_groups() );
    }

    public function test_resync_updates_each_audience_divisao_setor_field(): void {
        Functions\when( 'wp_cache_delete' )->justReturn( true );

        // One audience id from get_all_ids().
        $this->wpdb->shouldReceive( 'get_col' )->andReturn( array( '7' ) );

        // get_by_key(7, 'divisao_setor') → an existing field row.
        $field = (object) array(
            'id'            => 42,
            'field_options' => json_encode(
                array(
                    'groups'       => array( 'Old Division' => array( 'Old Sector' ) ),
                    'parent_label' => 'Division',
                    'child_label'  => 'Department',
                )
            ),
        );
        $this->wpdb->shouldReceive( 'get_row' )->andReturn( $field );

        // The update() call must target field id 42 with the new groups while
        // preserving parent_label / child_label.
        $captured = null;
        $this->wpdb->shouldReceive( 'update' )->andReturnUsing(
            function ( $table, $data, $where ) use ( &$captured ) {
                $captured = array( 'data' => $data, 'where' => $where );
                return 1;
            }
        );

        $updated = ReregistrationStandardFieldsSeeder::resync_divisao_setor_groups();

        $this->assertSame( 1, $updated );
        $this->assertNotNull( $captured );
        $this->assertSame( 42, $captured['where']['id'] );

        $decoded = json_decode( $captured['data']['field_options'], true );
        $this->assertArrayHasKey( 'groups', $decoded );
        $this->assertArrayHasKey( 'DRE - DIAF', $decoded['groups'], 'groups should be rewritten to the current map' );
        $this->assertSame( 'Division', $decoded['parent_label'], 'parent_label must be preserved' );
        $this->assertSame( 'Department', $decoded['child_label'], 'child_label must be preserved' );
    }
}
