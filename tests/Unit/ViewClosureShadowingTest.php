<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * A view's closure may not be overwritten by a later assignment (#1532).
 *
 * WHY THIS EXISTS, AND IT IS A NEAR MISS RATHER THAN A SHIPPED DEFECT.
 *
 * A view is `require`d into a caller's scope and PHP does not scope a
 * `foreach`, so `foreach ( $rows as $helper )` silently replaces a closure
 * named `$helper` with an array -- and the failure is a fatal at the NEXT call
 * site, which may be hundreds of lines away and may not exist yet. Nothing
 * else can see it: `phpstan.neon.dist` excludes `includes/admin/views` as
 * markup, so level 8 never reads these files.
 *
 * `identity-resolution-page.php` is 2,500 lines with twelve closures and warns
 * about this trap in two separate comments. Writing #1532's accepted panel hit
 * it anyway, twice in one change -- once on a loop variable the outer panel
 * loop owned, and once on `$ffc_identity_ack`, which is a closure three cards
 * call. The second would have been a fatal the moment anybody added a card
 * below that panel. Three comments had not been enough, which is the argument
 * for a gate rather than a fourth.
 *
 * IT BLOCKS AT ZERO. Measured when written: four view files declare closures,
 * sixteen in all, and not one is reassigned.
 *
 * @coversNothing
 */
class ViewClosureShadowingTest extends TestCase {

	/**
	 * Where a view may live.
	 *
	 * @var array<int, string>
	 */
	private const VIEW_DIRS = array(
		'includes/admin/views',
		'includes/settings/views',
		'includes/self-scheduling/views',
	);

	/**
	 * No closure in a view is replaced by a later assignment.
	 */
	public function test_no_view_overwrites_one_of_its_own_closures(): void {
		$root     = dirname( __DIR__, 2 );
		$scanned  = 0;
		$closures = 0;
		$shadowed = array();

		foreach ( self::VIEW_DIRS as $dir ) {
			foreach ( $this->views_in( $root . '/' . $dir ) as $file ) {
				++$scanned;

				$source = (string) file_get_contents( $file );

				preg_match_all( '/\$(\w+)\s*=\s*(?:static\s+)?function\s*\(/', $source, $found );

				foreach ( array_unique( $found[1] ) as $name ) {
					++$closures;

					// A SECOND `=` OR ANY `foreach` BINDING IS THE DEFECT. The
					// declaration itself is one assignment, so more than one
					// means something else writes the name; `as $name` is the
					// shape that bit #1532 and carries no `=` at all.
					$assigned = preg_match_all( '/\$' . preg_quote( $name, '/' ) . '\s*=[^=]/', $source );
					$bound    = preg_match_all( '/as\s+\$' . preg_quote( $name, '/' ) . '\b/', $source );

					if ( $assigned > 1 || $bound > 0 ) {
						$shadowed[] = str_replace( $root . '/', '', $file ) . ': $' . $name;
					}
				}
			}
		}

		// SELF-CHECKS, because a scan that read nothing must never read as
		// clean. The file count is taken by a SECOND traversal -- `scandir`
		// against the `glob` above -- which is the shape `CLAUDE.md` prescribes
		// where there is no frozen register: adding a view moves both sides.
		$this->assertSame(
			$this->recount( $root ),
			$scanned,
			'The two traversals disagree about how many views exist, so this scan read a different tree from the one it reports on.'
		);
		$this->assertGreaterThan( 0, $closures, 'No closure was found at all, so this proves nothing.' );

		sort( $shadowed );

		$this->assertSame(
			array(),
			$shadowed,
			"A view assigns over one of its own closures. The next call to it is a fatal:\n  " . implode( "\n  ", $shadowed )
		);
	}

	/**
	 * The view files of one directory.
	 *
	 * @param string $dir Absolute path.
	 * @return array<int, string>
	 */
	private function views_in( string $dir ): array {
		if ( ! is_dir( $dir ) ) {
			return array();
		}

		return array_values( (array) glob( $dir . '/*.php' ) );
	}

	/**
	 * The same population, counted by a different traversal.
	 *
	 * @param string $root Repository root.
	 * @return int
	 */
	private function recount( string $root ): int {
		$seen = 0;

		foreach ( self::VIEW_DIRS as $dir ) {
			$path = $root . '/' . $dir;

			if ( ! is_dir( $path ) ) {
				continue;
			}

			foreach ( (array) scandir( $path ) as $entry ) {
				if ( is_string( $entry ) && '.php' === substr( $entry, -4 ) && is_file( $path . '/' . $entry ) ) {
					++$seen;
				}
			}
		}

		return $seen;
	}
}
