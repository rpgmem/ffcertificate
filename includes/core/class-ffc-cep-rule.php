<?php
/**
 * CEP Rule
 *
 * The offline check of a Brazilian postal code: what can be refused without
 * asking any external service (#2233).
 *
 * @package FreeFormCertificate\Core
 * @since 6.43.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Refuses a CEP that cannot exist: wrong length, all digits equal, below the
 * first range, or outside the range of the UF chosen in the same form.
 *
 * A well-formed CEP inside the right range that does not exist passes; only a
 * lookup can tell.
 */
class CepRule {

	/**
	 * The value is fine.
	 */
	public const OK = '';

	/**
	 * Not exactly eight digits.
	 */
	public const FORMAT = 'format';

	/**
	 * All eight digits are the same.
	 */
	public const REPEATED = 'repeated';

	/**
	 * Below 01000-000 or in no state's range.
	 */
	public const RANGE = 'range';

	/**
	 * Outside the range of the UF given.
	 */
	public const UF = 'uf';

	/**
	 * CEP ranges by UF, as the first five digits (inclusive).
	 *
	 * Source: the Correios ranges by state ("faixas de CEP por UF"). A state
	 * with two ranges lists both; the browser receives this table through
	 * `ffcCoreConfig.cepRanges`, so there is one copy.
	 *
	 * @var array<string, list<array{0: int, 1: int}>>
	 */
	public const RANGES = array(
		'SP' => array( array( 1000, 19999 ) ),
		'RJ' => array( array( 20000, 28999 ) ),
		'ES' => array( array( 29000, 29999 ) ),
		'MG' => array( array( 30000, 39999 ) ),
		'BA' => array( array( 40000, 48999 ) ),
		'SE' => array( array( 49000, 49999 ) ),
		'PE' => array( array( 50000, 56999 ) ),
		'AL' => array( array( 57000, 57999 ) ),
		'PB' => array( array( 58000, 58999 ) ),
		'RN' => array( array( 59000, 59999 ) ),
		'CE' => array( array( 60000, 63999 ) ),
		'PI' => array( array( 64000, 64999 ) ),
		'MA' => array( array( 65000, 65999 ) ),
		'PA' => array( array( 66000, 68899 ) ),
		'AP' => array( array( 68900, 68999 ) ),
		'AM' => array( array( 69000, 69299 ), array( 69400, 69899 ) ),
		'RR' => array( array( 69300, 69399 ) ),
		'AC' => array( array( 69900, 69999 ) ),
		'DF' => array( array( 70000, 72799 ), array( 73000, 73699 ) ),
		'GO' => array( array( 72800, 72999 ), array( 73700, 76799 ) ),
		'RO' => array( array( 76800, 76999 ) ),
		'TO' => array( array( 77000, 77999 ) ),
		'MT' => array( array( 78000, 78899 ) ),
		'MS' => array( array( 79000, 79999 ) ),
		'PR' => array( array( 80000, 87999 ) ),
		'SC' => array( array( 88000, 89999 ) ),
		'RS' => array( array( 90000, 99999 ) ),
	);

	/**
	 * Why a CEP is refused, or {@see self::OK}.
	 *
	 * @param string $cep CEP, masked or bare.
	 * @param string $uf  UF chosen in the same form; '' skips the UF check.
	 * @return string One of the class constants.
	 */
	public static function check( string $cep, string $uf = '' ): string {
		// The mask's shape, or the bare digits: `00000-000` or `00000000`.
		if ( 1 !== preg_match( '/^\d{5}-?\d{3}$/', trim( $cep ) ) ) {
			return self::FORMAT;
		}
		$digits = str_replace( '-', '', trim( $cep ) );
		if ( 1 === preg_match( '/^(\d)\1{7}$/', $digits ) ) {
			return self::REPEATED;
		}

		$state = self::state_of( $digits );
		if ( '' === $state ) {
			return self::RANGE;
		}

		$uf = strtoupper( trim( $uf ) );
		if ( '' !== $uf && isset( self::RANGES[ $uf ] ) && $uf !== $state ) {
			return self::UF;
		}

		return self::OK;
	}

	/**
	 * The UF whose range holds a CEP, or '' when none does.
	 *
	 * @param string $cep CEP, masked or bare.
	 * @return string
	 */
	public static function state_of( string $cep ): string {
		$cep = trim( $cep );
		if ( 1 !== preg_match( '/^\d{5}-?\d{3}$/', $cep ) ) {
			return '';
		}

		$prefix = (int) substr( $cep, 0, 5 );
		foreach ( self::RANGES as $state => $ranges ) {
			foreach ( $ranges as $range ) {
				if ( $prefix >= $range[0] && $prefix <= $range[1] ) {
					return $state;
				}
			}
		}

		return '';
	}
}
