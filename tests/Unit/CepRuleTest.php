<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Core\CepRule;

/**
 * Tests for CepRule: the offline CEP check (#2233).
 *
 * @covers \FreeFormCertificate\Core\CepRule
 */
class CepRuleTest extends TestCase {

	public function test_a_cep_in_its_state_passes_masked_or_bare(): void {
		$this->assertSame( CepRule::OK, CepRule::check( '08615-070', 'SP' ) );
		$this->assertSame( CepRule::OK, CepRule::check( '08615070', 'SP' ) );
		$this->assertSame( CepRule::OK, CepRule::check( ' 08615-070 ', 'sp' ), 'Whitespace and a lower-case UF are tolerated.' );
	}

	/**
	 * @dataProvider bad_format_provider
	 */
	public function test_a_value_that_is_not_eight_digits_is_a_format_error( string $cep ): void {
		$this->assertSame( CepRule::FORMAT, CepRule::check( $cep ) );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function bad_format_provider(): array {
		return array(
			'seven digits'      => array( '0861507' ),
			'nine digits'       => array( '086150700' ),
			'letters'           => array( '0861A-070' ),
			'hyphen misplaced'  => array( '0861-5070' ),
			'dot separator'     => array( '08.615-070' ),
			'empty'             => array( '' ),
		);
	}

	/**
	 * @dataProvider repeated_provider
	 */
	public function test_eight_equal_digits_are_refused( string $cep ): void {
		$this->assertSame( CepRule::REPEATED, CepRule::check( $cep ) );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function repeated_provider(): array {
		$cases = array();
		foreach ( range( 0, 9 ) as $digit ) {
			$cases[ (string) $digit ] = array( str_repeat( (string) $digit, 5 ) . '-' . str_repeat( (string) $digit, 3 ) );
		}
		return $cases;
	}

	public function test_a_cep_below_the_first_range_is_refused(): void {
		$this->assertSame( CepRule::RANGE, CepRule::check( '00999-999' ) );
		$this->assertSame( CepRule::RANGE, CepRule::check( '00100-000' ) );
	}

	public function test_a_cep_from_another_state_than_the_uf_is_refused(): void {
		$this->assertSame( CepRule::UF, CepRule::check( '28615-070', 'SP' ), 'A RJ CEP with SP chosen.' );
	}

	public function test_without_a_uf_or_with_an_unknown_one_the_uf_check_is_skipped(): void {
		$this->assertSame( CepRule::OK, CepRule::check( '28615-070' ) );
		$this->assertSame( CepRule::OK, CepRule::check( '28615-070', 'XX' ), 'A UF the table does not know is not a mismatch.' );
	}

	/**
	 * Every range's first and last CEP belongs to its state, and the CEP one
	 * below the first and one above the last does not.
	 */
	public function test_every_range_boundary_belongs_to_its_state(): void {
		$checked = 0;
		foreach ( CepRule::RANGES as $uf => $ranges ) {
			foreach ( $ranges as $range ) {
				$first = sprintf( '%05d000', $range[0] );
				$last  = sprintf( '%05d999', $range[1] );
				$this->assertSame( $uf, CepRule::state_of( $first ), "{$first} opens {$uf}." );
				$this->assertSame( $uf, CepRule::state_of( $last ), "{$last} closes {$uf}." );
				$this->assertSame( CepRule::OK, CepRule::check( $first, $uf ) );

				$below = sprintf( '%05d999', $range[0] - 1 );
				$this->assertNotSame( $uf, CepRule::state_of( $below ), "{$below} is outside {$uf}." );
				if ( $range[1] < 99999 ) {
					$above = sprintf( '%05d000', $range[1] + 1 );
					$this->assertNotSame( $uf, CepRule::state_of( $above ), "{$above} is outside {$uf}." );
				}
				++$checked;
			}
		}

		// 27 states, three of them with two ranges.
		$this->assertSame( 30, $checked );
	}

	public function test_the_ranges_cover_every_uf_the_form_offers_and_do_not_overlap(): void {
		$this->assertCount( 27, CepRule::RANGES );

		$spans = array();
		foreach ( CepRule::RANGES as $ranges ) {
			foreach ( $ranges as $range ) {
				$spans[] = $range;
			}
		}
		usort( $spans, static fn( $a, $b ) => $a[0] <=> $b[0] );
		for ( $i = 1, $n = count( $spans ); $i < $n; $i++ ) {
			$this->assertGreaterThan( $spans[ $i - 1 ][1], $spans[ $i ][0], 'Ranges must not overlap.' );
		}
		$this->assertSame( 1000, $spans[0][0] );
		$this->assertSame( 99999, $spans[ count( $spans ) - 1 ][1] );
	}

	public function test_state_of_needs_eight_digits(): void {
		$this->assertSame( '', CepRule::state_of( '0861507' ) );
		$this->assertSame( 'SP', CepRule::state_of( '08615-070' ) );
	}
}
