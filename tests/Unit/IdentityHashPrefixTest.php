<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Maintenance\IdentityAuditExportSource;
use FreeFormCertificate\Maintenance\IdentityQueue;
use FreeFormCertificate\Maintenance\IdentityAdoption;
use FreeFormCertificate\Maintenance\IdentityRelink;
use FreeFormCertificate\Maintenance\IdentityRepair;
use FreeFormCertificate\Maintenance\IdentitySplit;
use FreeFormCertificate\Admin\IdentityPreflightAjaxEndpoint;
use FreeFormCertificate\Admin\IdentitySearchAjaxEndpoint;

/**
 * How much of an identifier hash any surface is allowed to print (#1477).
 *
 * THE BOUND IS A PII DECISION AND IT DRIFTED IN SILENCE.
 *
 * `IdentityQueue::DISPLAY_PREFIX` carries the reason for 12: the full hash of a
 * seven-digit number is not far from the number, the space being small enough
 * to enumerate against a known salt. Six constants followed it and the CSV
 * export declared 16 -- for two releases, undecided, in the one artifact that
 * LEAVES the server, while an operator holding the file beside the queue could
 * not match a row to a panel by eye.
 *
 * Nothing could have caught it: each constant is correct on its own, and no
 * gate reads two of them together. So this compares them, and the comparison
 * is the whole test.
 *
 * @coversNothing
 */
class IdentityHashPrefixTest extends TestCase {

	/**
	 * Every declared hash-prefix constant, frozen.
	 *
	 * A register rather than a floor, because a floor over a population that
	 * grows is what decays: a seventh surface declaring 16 has to fail here,
	 * and it only can if the set itself is pinned.
	 *
	 * @var array<string, string>
	 */
	private const DECLARED = array(
		'IdentityQueue::DISPLAY_PREFIX'                  => IdentityQueue::class,
		'IdentityAuditExportSource::HASH_PREFIX_CHARS'   => IdentityAuditExportSource::class,
		'IdentityAdoption::LOG_PREFIX'                   => IdentityAdoption::class,
		'IdentityRelink::LOG_PREFIX'                     => IdentityRelink::class,
		'IdentityRepair::LOG_PREFIX'                     => IdentityRepair::class,
		'IdentitySplit::LOG_PREFIX'                      => IdentitySplit::class,
		'IdentityPreflightAjaxEndpoint::DISPLAY_PREFIX'  => IdentityPreflightAjaxEndpoint::class,
		'IdentitySearchAjaxEndpoint::DISPLAY_PREFIX'     => IdentitySearchAjaxEndpoint::class,
	);

	/**
	 * No surface may print more of a hash than the screen's own figure.
	 *
	 * Stated as a bound and not as equality, because a surface is free to
	 * print LESS -- a log that keeps eight characters is a narrower decision,
	 * not a broken one. What no surface may do is exceed the one figure that
	 * carries a written reason.
	 */
	public function test_no_surface_prints_more_of_a_hash_than_the_screen_does(): void {
		$ceiling = IdentityQueue::DISPLAY_PREFIX;
		$over    = array();

		foreach ( self::DECLARED as $name => $class ) {
			$constant = substr( $name, (int) strpos( $name, '::' ) + 2 );
			$value    = constant( $class . '::' . $constant );

			$this->assertIsInt( $value, $name . ' must be an integer number of characters.' );

			if ( $value > $ceiling ) {
				$over[ $name ] = $value;
			}
		}

		$this->assertSame(
			array(),
			$over,
			sprintf(
				'These print more of a hash than %d characters, which is the figure whose reason is written down: %s',
				$ceiling,
				implode( ', ', array_map( static fn ( $k, $v ) => $k . '=' . $v, array_keys( $over ), $over ) )
			)
		);
	}

	/**
	 * THE REGISTER MUST NAME EVERY DECLARATION IN THE TREE.
	 *
	 * The self-check, and the reason it is a recount rather than a floor: a
	 * scan that finds fewer than it should must FAIL, not pass quietly on what
	 * it happened to read. Both sides move when a surface is added, so this
	 * needs no maintenance beyond deciding the new surface's figure -- which is
	 * exactly the decision that was never made for the export.
	 */
	public function test_the_register_names_every_prefix_constant_declared_under_includes(): void {
		$found = array();

		$files = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( dirname( __DIR__, 2 ) . '/includes' )
		);

		foreach ( $files as $file ) {
			if ( ! $file instanceof \SplFileInfo || 'php' !== $file->getExtension() ) {
				continue;
			}

			$source = (string) file_get_contents( $file->getPathname() );
			$class  = self::class_declared_in( $source );

			// The bound governs the IDENTITY-RESOLUTION surfaces, and saying so
			// is not a convenience. `CLAUDE.md` is explicit that a truncation
			// length is the call site's own and not a plugin-wide constant --
			// `PreflightTelemetry` keeps 12 and `ClientIpResolver` 16, both
			// deliberately, over a different value with a different salt. A
			// guard that swept those in would be asserting a rule nobody wrote.
			if ( '' === $class || 0 !== strpos( $class, 'Identity' ) ) {
				continue;
			}

			preg_match_all( '/\bconst\s+(HASH_PREFIX\w*|DISPLAY_PREFIX|LOG_PREFIX)\s*=/', $source, $names );

			foreach ( $names[1] as $constant ) {
				$found[] = $class . '::' . $constant;
			}
		}

		$this->assertNotEmpty( $found, 'Reading nothing is a broken scan, never a clean tree.' );

		sort( $found );
		$register = array_keys( self::DECLARED );
		sort( $register );

		$this->assertSame(
			$register,
			$found,
			'A hash-prefix constant exists that this register does not name, or names one that is gone. Decide its figure and list it.'
		);
	}

	/**
	 * The name of the class a file declares, read from TOKENS.
	 *
	 * A `\bclass\s+(\w+)` over the source matches the word inside prose --
	 * "the caps class", "this class validates" -- and the first version of this
	 * scan duly reported constants on classes named `caps`, `for`, `is` and
	 * `this`. The same lesson the suppression guards record: read tokens, never
	 * raw lines.
	 *
	 * @param string $source The file.
	 * @return string The class name, or an empty string when the file declares none.
	 */
	private static function class_declared_in( string $source ): string {
		$tokens = token_get_all( $source );
		$count  = count( $tokens );

		for ( $i = 0; $i < $count; $i++ ) {
			if ( ! is_array( $tokens[ $i ] ) || T_CLASS !== $tokens[ $i ][0] ) {
				continue;
			}

			for ( $j = $i + 1; $j < $count; $j++ ) {
				if ( is_array( $tokens[ $j ] ) && T_WHITESPACE === $tokens[ $j ][0] ) {
					continue;
				}

				// `Foo::class` puts `T_CLASS` after `::`, with no name to take.
				return is_array( $tokens[ $j ] ) && T_STRING === $tokens[ $j ][0]
					? (string) $tokens[ $j ][1]
					: '';
			}
		}

		return '';
	}
}
