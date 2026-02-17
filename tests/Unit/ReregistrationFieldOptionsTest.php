<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Reregistration\ReregistrationFieldOptions;

/**
 * Tests for ReregistrationFieldOptions: data providers and field mapping.
 */
class ReregistrationFieldOptionsTest extends TestCase {

    use MockeryPHPUnitIntegration;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        Functions\when( '__' )->returnArg();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    // ==================================================================
    // get_divisao_setor_map()
    // ==================================================================

    public function test_divisao_setor_map_returns_non_empty_array(): void {
        $map = ReregistrationFieldOptions::get_divisao_setor_map();

        $this->assertIsArray( $map );
        $this->assertNotEmpty( $map );
    }

    public function test_divisao_setor_map_contains_expected_divisions(): void {
        $map = ReregistrationFieldOptions::get_divisao_setor_map();

        $this->assertArrayHasKey( 'DRE - Gabinete', $map );
        $this->assertArrayHasKey( 'DRE - DIAF', $map );
        $this->assertArrayHasKey( 'DRE - DIAFRH', $map );
        $this->assertArrayHasKey( 'DRE - DICEU', $map );
        $this->assertArrayHasKey( 'DRE - DIPED', $map );
        $this->assertArrayHasKey( 'DRE - Supervisão', $map );
        $this->assertArrayHasKey( 'ESCOLA - Gestão', $map );
        $this->assertArrayHasKey( 'ESCOLA - Pedagógico', $map );
        $this->assertArrayHasKey( 'ESCOLA - Quadro de Apoio', $map );
    }

    public function test_divisao_setor_map_values_are_string_arrays(): void {
        $map = ReregistrationFieldOptions::get_divisao_setor_map();

        foreach ( $map as $division => $sectors ) {
            $this->assertIsArray( $sectors, "Sectors for '$division' should be an array" );
            $this->assertNotEmpty( $sectors, "Sectors for '$division' should not be empty" );
            foreach ( $sectors as $sector ) {
                $this->assertIsString( $sector, "Each sector in '$division' should be a string" );
            }
        }
    }

    public function test_divisao_setor_map_diaf_has_many_sectors(): void {
        $map = ReregistrationFieldOptions::get_divisao_setor_map();
        $diaf = $map['DRE - DIAF'];

        $this->assertGreaterThan( 10, count( $diaf ), 'DIAF should have many sectors' );
        $this->assertContains( 'NTIC', $diaf );
        $this->assertContains( 'Protocolo', $diaf );
        $this->assertContains( 'PTRF', $diaf );
    }

    // ==================================================================
    // Field option methods — structure and content
    // ==================================================================

    public function test_sexo_options_non_empty(): void {
        $options = ReregistrationFieldOptions::get_sexo_options();

        $this->assertIsArray( $options );
        $this->assertGreaterThanOrEqual( 3, count( $options ) );
    }

    public function test_estado_civil_options_non_empty(): void {
        $options = ReregistrationFieldOptions::get_estado_civil_options();

        $this->assertIsArray( $options );
        $this->assertGreaterThanOrEqual( 5, count( $options ) );
    }

    public function test_sindicato_options_contains_known_unions(): void {
        $options = ReregistrationFieldOptions::get_sindicato_options();

        $this->assertContains( 'APROFEM', $options );
        $this->assertContains( 'SINPEEM', $options );
        $this->assertContains( 'SINESP', $options );
    }

    public function test_jornada_options_non_empty(): void {
        $options = ReregistrationFieldOptions::get_jornada_options();

        $this->assertIsArray( $options );
        $this->assertNotEmpty( $options );
        $this->assertContains( 'JB.30', $options );
        $this->assertContains( 'JEIF.40', $options );
    }

    public function test_acumulo_options_non_empty(): void {
        $options = ReregistrationFieldOptions::get_acumulo_options();

        $this->assertIsArray( $options );
        $this->assertGreaterThanOrEqual( 3, count( $options ) );
    }

    public function test_uf_options_has_27_states(): void {
        $options = ReregistrationFieldOptions::get_uf_options();

        $this->assertCount( 27, $options, 'Brazil has 26 states + 1 DF = 27 UFs' );
        $this->assertContains( 'SP', $options );
        $this->assertContains( 'RJ', $options );
        $this->assertContains( 'DF', $options );
        $this->assertContains( 'AM', $options );
    }

    public function test_uf_options_are_two_letter_codes(): void {
        $options = ReregistrationFieldOptions::get_uf_options();

        foreach ( $options as $uf ) {
            $this->assertSame( 2, strlen( $uf ), "UF '$uf' should be exactly 2 characters" );
            $this->assertMatchesRegularExpression( '/^[A-Z]{2}$/', $uf, "UF '$uf' should be uppercase" );
        }
    }

    // ==================================================================
    // get_default_working_hours()
    // ==================================================================

    public function test_default_working_hours_has_5_days(): void {
        $hours = ReregistrationFieldOptions::get_default_working_hours();

        $this->assertCount( 5, $hours, 'Default working hours should cover Mon-Fri (5 days)' );
    }

    public function test_default_working_hours_days_are_1_through_5(): void {
        $hours = ReregistrationFieldOptions::get_default_working_hours();
        $days = array_column( $hours, 'day' );

        $this->assertSame( array( 1, 2, 3, 4, 5 ), $days );
    }

    public function test_default_working_hours_entries_have_required_keys(): void {
        $hours = ReregistrationFieldOptions::get_default_working_hours();

        foreach ( $hours as $entry ) {
            $this->assertArrayHasKey( 'day', $entry );
            $this->assertArrayHasKey( 'entry1', $entry );
            $this->assertArrayHasKey( 'exit1', $entry );
            $this->assertArrayHasKey( 'entry2', $entry );
            $this->assertArrayHasKey( 'exit2', $entry );
        }
    }

    public function test_default_working_hours_times_are_empty_strings(): void {
        $hours = ReregistrationFieldOptions::get_default_working_hours();

        foreach ( $hours as $entry ) {
            $this->assertSame( '', $entry['entry1'] );
            $this->assertSame( '', $entry['exit1'] );
            $this->assertSame( '', $entry['entry2'] );
            $this->assertSame( '', $entry['exit2'] );
        }
    }
}
