<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use FreeFormCertificate\Tests\Support\PhpSource;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Tests\Support\Identifiers;

/**
 * A CPF or RF fixture must be one of the standard values (#1492).
 *
 * WHAT WENT WRONG WITHOUT THIS
 *
 * #1489 added a check-digit rule to the recruitment CSV import and **19 tests
 * went red across two files**, because their fixtures used `12345678901` -- a
 * CPF no person can hold. Nobody wrote it meaning an invalid value; nobody
 * could see that it was one. Validation has a single home, so a rule change
 * lands in one place; fixtures had none, so the same change landed in as many
 * places as there were literals.
 *
 * IT CHECKS THE VALUE, NOT THAT A CONSTANT WAS USED
 *
 * Requiring `Identifiers::CPF_A` everywhere would be stronger and is not
 * workable: the recruitment fixtures embed a CPF inside a whole CSV row
 * (`'Alice,51817842080,,a@b.test,mat,1,90,Sim'`), where a constant needs
 * concatenation that makes the row unreadable. So the standard is the SET of
 * values, and `Identifiers` is where they are named and explained.
 *
 * THE WIDTH IS THE DISCRIMINATOR FOR INTENT, WHICH IS WHY THERE IS NO ALLOWLIST
 *
 * A fixture of the wrong width -- `'12345'`, `'123456789012345'`, a 7-digit RF
 * sitting in a CPF column -- is the test's SUBJECT, not its fixture, and nobody
 * writes a five-digit CPF by accident. So a literal is only judged when it is
 * exactly as wide as the key's identifier, and everything else is left alone.
 * An allowlist would have had to carry those, and an allowlist is where a
 * standard goes to die.
 *
 * ANCHORED ON THE KEY, NEVER ON BARE DIGITS
 *
 * Scanning for 11-digit runs finds phone numbers: `11999999999` and four
 * siblings are São Paulo mobiles, the same width as a CPF. A sweep driven by
 * that scan would have rewritten telephones into CPFs, which is the
 * false-positive trap `CLAUDE.md` records for the suppression scanners, wearing
 * a new costume.
 *
 * @covers \FreeFormCertificate\Tests\Support\Identifiers
 */
class IdentifierFixtureStandardTest extends TestCase {

	/**
	 * Keys that hold a CPF, and the width a CPF has.
	 *
	 * @var array<int, string>
	 */
	private const CPF_KEYS = array( 'cpf', 'cpf_normalized', 'ffc_cpf' );

	/**
	 * Keys that hold an RF.
	 *
	 * @var array<int, string>
	 */
	private const RF_KEYS = array( 'rf', 'rf_normalized', 'ffc_rf' );

	/**
	 * The one file exempt, and the reason is circularity rather than convenience.
	 *
	 * `DocumentFormatterTest` does not hold fixtures; it holds TEST VECTORS. Its
	 * docblocks explain the arithmetic of the values -- *`'1234567'` has a
	 * weighted sum of 77, residue 0* -- and its assertions spell the digits out
	 * (`'529.982.247-25'`, `'123.456-7'`, `Check digit 7, expected 1.`). A value
	 * there is what the test asserts ABOUT, so it must be free to name any
	 * value, invalid ones included.
	 *
	 * And the standard's own values were verified by calling that very class.
	 * Requiring the test of `DocumentFormatter` to use values `DocumentFormatter`
	 * certified would make the certification circular.
	 *
	 * @var string
	 */
	private const TEST_VECTORS = 'DocumentFormatterTest.php';

	/**
	 * Every test file, by one traversal.
	 *
	 * @return array<int, string>
	 */
	private function files(): array {
		$root  = dirname( __DIR__, 2 ) . '/';
		$found = array();

		$it = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( dirname( __DIR__ ), \FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $it as $file ) {
			if ( $file->isFile() && 'php' === $file->getExtension()
				&& self::TEST_VECTORS !== $file->getFilename() ) {
				// Repository-relative, which is what `PhpSource` speaks.
				$found[] = str_replace( $root, '', $file->getPathname() );
			}
		}

		sort( $found );

		return $found;
	}

	/**
	 * Fixture literals sitting under one of these keys, at the given width.
	 *
	 * @param array<int, string> $keys  The keys to look under.
	 * @param int                $width The identifier's canonical width.
	 * @return array<string, array<int, string>> File => the offending values.
	 */
	private function literals( array $keys, int $width ): array {
		$pattern = sprintf(
			'/\x27(?:%s)\x27\s*(?:=>|,)\s*\x27([0-9]{%d})\x27/',
			implode( '|', array_map( 'preg_quote', $keys ) ),
			$width
		);

		$out = array();

		foreach ( $this->files() as $path ) {
			preg_match_all( $pattern, PhpSource::code( $path ), $m );

			if ( array() !== $m[1] ) {
				$out[ $path ] = array_values( array_unique( $m[1] ) );
			}
		}

		return $out;
	}

	/**
	 * The scan reads something, so an empty result cannot read as compliance.
	 *
	 * The #1071 / #1094 rule. The floor is a recount by a different traversal
	 * rather than a number that decays as the suite grows: `glob` over the two
	 * test directories against the recursive iterator above.
	 */
	public function test_the_scan_reaches_the_suite(): void {
		$walked = $this->files();

		$this->assertNotEmpty( $walked, 'The traversal found no test file, so this proves nothing.' );

		$recount = array_merge(
			(array) glob( dirname( __DIR__ ) . '/Unit/*.php' ),
			(array) glob( dirname( __DIR__ ) . '/Integration/*.php' ),
			(array) glob( dirname( __DIR__ ) . '/Support/*.php' )
		);

		$this->assertGreaterThanOrEqual(
			count( array_filter( $recount ) ),
			count( $walked ),
			'The recursive walk must reach at least what a flat glob of the same directories finds.'
		);

		$this->assertNotEmpty(
			$this->literals( self::CPF_KEYS, 11 ),
			'No CPF fixture found at all: the pattern stopped matching and this guard is inert.'
		);
	}

	/**
	 * Every 11-digit CPF fixture is one of the standard values.
	 */
	public function test_every_cpf_fixture_is_standard(): void {
		$this->assertStandard( self::CPF_KEYS, 11, Identifiers::cpfs(), 'CPF' );
	}

	/**
	 * Every 7-digit RF fixture is one of the standard values.
	 */
	public function test_every_rf_fixture_is_standard(): void {
		$this->assertStandard( self::RF_KEYS, 7, Identifiers::rfs(), 'RF' );
	}

	/**
	 * The legacy combined column holds either, and each at its own width.
	 */
	public function test_every_combined_cpf_rf_fixture_is_standard(): void {
		$this->assertStandard( array( 'cpf_rf' ), 11, Identifiers::cpfs(), 'CPF (in cpf_rf)' );
		$this->assertStandard( array( 'cpf_rf' ), 7, Identifiers::rfs(), 'RF (in cpf_rf)' );
	}

	/**
	 * Fail naming each file and value that is not in the standard set.
	 *
	 * @param array<int, string> $keys    Keys to look under.
	 * @param int                $width   The identifier's canonical width.
	 * @param array<int, string> $allowed The standard values.
	 * @param string             $label   What to call it in the message.
	 * @return void
	 */
	private function assertStandard( array $keys, int $width, array $allowed, string $label ): void {
		$offending = array();

		foreach ( $this->literals( $keys, $width ) as $path => $values ) {
			$stray = array_values( array_diff( $values, $allowed ) );

			if ( array() !== $stray ) {
				$offending[] = sprintf( '%s: %s', basename( $path ), implode( ', ', $stray ) );
			}
		}

		sort( $offending );

		$this->assertSame(
			array(),
			$offending,
			sprintf(
				"These %s fixtures are not in the standard set (see tests/Support/Identifiers.php):\n- %s",
				$label,
				implode( "\n- ", $offending )
			)
		);
	}
}
