<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use FreeFormCertificate\Tests\Support\I18nCalls;
use PHPUnit\Framework\TestCase;

/**
 * No translatable string names an issue or a pull request (#2340).
 *
 * An issue number means something to whoever works on the repository and
 * nothing to the operator reading a capability description or a help topic.
 * About twenty such strings had shipped, most of them in the capability
 * catalog, because a description was often written while the issue that
 * introduced it was open. The number belongs in the CHANGELOG, the plugin's
 * changelog and code comments, none of which is a translatable string.
 *
 * The scan reads every literal i18n call through I18nCalls, the extractor the
 * catalogue guards share, so the two cannot disagree about what a string is.
 * A `#` followed by three or more digits is the shape refused: issue numbers
 * here are never shorter. A colour written in digits only (`#123456`) would
 * match too; none is in a translatable string, and one would be worth a look.
 */
class IssueNumberInUiStringTest extends TestCase {

	private const PATTERN = '/#\d{3,}/';

	public function test_no_translatable_string_names_an_issue(): void {
		$found = array();
		foreach ( I18nCalls::all() as $call ) {
			foreach ( array( $call['msgid'], (string) $call['msgid_plural'] ) as $text ) {
				if ( 1 === preg_match( self::PATTERN, $text ) ) {
					$found[] = $call['refs'][0] . ': ' . $text;
				}
			}
		}

		$this->assertSame( array(), $found, "A translatable string names an issue; move the reference to a code comment or the CHANGELOG:\n" . implode( "\n", $found ) );
	}

	public function test_the_scan_reaches_the_files_where_the_defect_lived(): void {
		// An empty scan must never read as clean: the extractor has to see
		// the strings of the capability catalog and of a documentation topic.
		$files = array();
		foreach ( I18nCalls::all() as $call ) {
			foreach ( $call['refs'] as $ref ) {
				$files[ (string) preg_replace( '/:\d+$/', '', $ref ) ] = true;
			}
		}

		$this->assertArrayHasKey( 'includes/user-dashboard/class-ffc-capability-catalog.php', $files );
		$this->assertArrayHasKey( 'includes/settings/views/documentation/config-activity-log.php', $files );
	}

	public function test_the_pattern_refuses_an_issue_and_keeps_what_is_not_one(): void {
		$this->assertSame( 1, preg_match( self::PATTERN, 'Without it the values stay masked (#739).' ) );
		$this->assertSame( 1, preg_match( self::PATTERN, 'see rpgmem/ffcertificate#711' ) );
		$this->assertSame( 0, preg_match( self::PATTERN, 'Item #%d' ) );
		$this->assertSame( 0, preg_match( self::PATTERN, 'Use #ffcc00 or #12' ) );
	}
}
