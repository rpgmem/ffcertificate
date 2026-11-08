<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The identity tools ask whether a store exists with `SHOW TABLES LIKE`, and
 * `_` is a LIKE wildcard: `wp_ffc_user_profiles` also matches
 * `wpXffcXuserXprofiles`, and when both exist the first row may be the other
 * one (#2338). Every probe escapes the name, the way `esc_like()` does.
 *
 * Read off the source because the probes live in many classes and each test
 * double answers the probe on its own terms.
 *
 * @coversNothing
 */
class IdentityTableProbeEscapeTest extends TestCase {

	/**
	 * The identity classes under includes/maintenance.
	 *
	 * @return array<int, string>
	 */
	private static function files(): array {
		$files = glob( dirname( __DIR__, 2 ) . '/includes/maintenance/class-ffc-identity-*.php' );
		return is_array( $files ) ? $files : array();
	}

	public function test_every_table_probe_escapes_the_like_wildcards(): void {
		$probes = 0;
		foreach ( self::files() as $file ) {
			$source = (string) file_get_contents( $file );
			preg_match_all( "/'SHOW TABLES LIKE %s',\\s*([^)]*\\))/", $source, $found );
			foreach ( $found[1] as $argument ) {
				++$probes;
				$this->assertStringStartsWith( "addcslashes(", trim( $argument ), basename( $file ) . ' probes a table without escaping _ and %.' );
			}
		}

		// Independent recount: every occurrence of the statement is one the
		// pattern above read, so a probe written another way cannot slip by.
		$recount = 0;
		foreach ( self::files() as $file ) {
			$recount += substr_count( (string) file_get_contents( $file ), 'SHOW TABLES LIKE' );
		}
		$this->assertNotEmpty( self::files(), 'The identity classes were not found.' );
		$this->assertGreaterThan( 0, $recount, 'No probe was found to check.' );
		$this->assertSame( $recount, $probes, 'A probe is written in a shape the scan does not read.' );
	}

	public function test_the_escape_matches_wpdb_esc_like(): void {
		// `wpdb::esc_like()` is `addcslashes( $text, '_%\\' )`; the probes use
		// the same call so the test doubles need no `esc_like()`.
		$this->assertSame( 'wp\\_ffc\\_user\\_profiles', addcslashes( 'wp_ffc_user_profiles', '_%\\' ) );
	}
}
