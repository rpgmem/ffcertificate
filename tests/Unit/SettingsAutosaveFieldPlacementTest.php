<?php
/**
 * An autosave key is rendered by exactly one settings tab.
 *
 * @package FreeFormCertificate\Tests
 */

declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Guard over where `data-ffc-autosave-key` fields live (#1148 item 2).
 *
 * Moving the Code Editor Theme field from Advanced to General raised the
 * question the issue framed as "mover ou espelhar": a mirrored field would
 * have put the same key on two tabs. Mirroring is what this file refuses,
 * and the reason is the no-clobber invariant in CLAUDE.md — a tab's Save
 * rebuilds that tab's fields from `$_POST`, so two copies of one key mean
 * two rebuild paths that drift the moment one of them is edited, plus two
 * elements sharing an `id` for anyone who opens both screens.
 *
 * It is not a style rule: every one of the literal keys in `includes/settings`
 * is rendered exactly once today, so the guard starts with no exception list.
 * If mirroring ever becomes the right answer for a key, add it here with the
 * reason inline — the point is that the decision is taken deliberately rather
 * than arrived at by a copy-paste.
 *
 * What it cannot see: keys built at runtime (`'activity_log_cat_' . $cat`, the
 * SMTP loop's `$ffcertificate_key`). Those are unique by construction — each
 * loop lives in one file — and `test_the_scan_recognises_every_occurrence`
 * fails loudly if a fourth shape appears, so the scan can never quietly stop
 * covering a form it used to cover.
 */
class SettingsAutosaveFieldPlacementTest extends TestCase {

	/**
	 * Keys deliberately rendered by more than one tab, each with the reason.
	 *
	 * Empty on purpose — see the class docblock.
	 *
	 * @var array<string, string>
	 */
	private const KNOWN_MIRRORED = array();

	/**
	 * Every literal autosave key, as `key => list of repo-relative paths`.
	 *
	 * Two literal shapes carry one: the markup attribute, and the `data`
	 * array `AdminUI::render_toggle()` turns into that attribute.
	 *
	 * @return array<string, list<string>>
	 */
	private function scan(): array {
		$found = array();
		foreach ( $this->files() as $path => $src ) {
			preg_match_all( '/data-ffc-autosave-key="([a-z0-9_]+)"/', $src, $markup );
			preg_match_all( "/'ffc-autosave-key'\s*=>\s*'([a-z0-9_]+)'/", $src, $array );

			foreach ( array_merge( $markup[1], $array[1] ) as $key ) {
				$found[ $key ][] = $path;
			}
		}

		return $found;
	}

	/**
	 * The settings PHP sources, keyed by repo-relative path.
	 *
	 * @return array<string, string>
	 */
	private function files(): array {
		$root  = dirname( __DIR__, 2 );
		$files = array();

		$it = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root . '/includes/settings' ) );
		foreach ( $it as $file ) {
			if ( $file->isFile() && 'php' === $file->getExtension() ) {
				$files[ substr( $file->getPathname(), strlen( $root ) + 1 ) ] = (string) file_get_contents( $file->getPathname() );
			}
		}
		ksort( $files );

		return $files;
	}

	public function test_no_autosave_key_is_rendered_by_two_tabs(): void {
		$mirrored = array();
		foreach ( $this->scan() as $key => $paths ) {
			$paths = array_values( array_unique( $paths ) );
			if ( count( $paths ) > 1 && ! isset( self::KNOWN_MIRRORED[ $key ] ) ) {
				$mirrored[] = $key . ' → ' . implode( ', ', $paths );
			}
		}

		$this->assertSame(
			array(),
			$mirrored,
			"An autosave key is rendered by more than one tab. Each tab's Save rebuilds "
				. "its own fields, so two copies drift. Move the field, or list it in "
				. 'KNOWN_MIRRORED with the reason:' . "\n" . implode( "\n", $mirrored )
		);
	}

	public function test_no_autosave_key_is_rendered_twice_in_one_file(): void {
		$repeated = array();
		foreach ( $this->scan() as $key => $paths ) {
			if ( count( $paths ) !== count( array_unique( $paths ) ) ) {
				$repeated[] = $key . ' in ' . $paths[0];
			}
		}

		$this->assertSame( array(), $repeated, 'Two controls share one autosave key — and therefore one `id`.' );
	}

	public function test_the_code_editor_theme_field_sits_next_to_dark_mode(): void {
		$found = $this->scan();

		$this->assertArrayHasKey( 'code_editor_theme', $found );
		$this->assertSame(
			array( 'includes/settings/views/ffc-tab-general.php' ),
			$found['code_editor_theme'],
			'The Code Editor Theme field belongs on General, beside Dark Mode: its '
				. '"auto" value resolves by reading dark_mode, so the two only make '
				. 'sense side by side (#1148 item 2 — on Advanced, nobody found it).'
		);
		$this->assertSame( $found['code_editor_theme'], $found['dark_mode'] );
	}

	public function test_the_scan_recognises_every_occurrence(): void {
		$unrecognised = array();
		$literal      = 0;

		foreach ( $this->files() as $path => $src ) {
			foreach ( explode( "\n", $src ) as $number => $line ) {
				if ( ! str_contains( $line, 'ffc-autosave-key' ) ) {
					continue;
				}

				// A literal key, in either of the two shapes the scan reads.
				if ( preg_match( '/data-ffc-autosave-key="[a-z0-9_]+"/', $line )
					|| preg_match( "/'ffc-autosave-key'\s*=>\s*'[a-z0-9_]+'/", $line ) ) {
					++$literal;
					continue;
				}

				// A key built at runtime: a variable, a concatenation, or an
				// open PHP tag interpolating part of the attribute value. Each
				// of these lives inside one loop in one file, so it is unique
				// by construction and the cross-tab check has nothing to say.
				// (The tag is named by its opening half on purpose — a closing
				// one ends this comment and the file stops parsing.)
				if ( preg_match( "/'ffc-autosave-key'\s*=>\s*(?:'[a-z0-9_]+'\s*\.\s*)?\\\$/", $line )
					|| str_contains( $line, 'data-ffc-autosave-key="' ) && str_contains( $line, '<?php' ) ) {
					continue;
				}

				// Not a field: the attribute named in a `wp_kses()` allowlist,
				// which is how a tab permits it on markup it builds as a string.
				if ( preg_match( "/'data-ffc-autosave-key'\s*=>\s*true/", $line ) ) {
					continue;
				}

				// Prose in a docblock naming the attribute.
				if ( preg_match( '/^\s*(?:\*|\/\/)/', $line ) ) {
					continue;
				}

				$unrecognised[] = $path . ':' . ( $number + 1 ) . ' — ' . trim( $line );
			}
		}

		$this->assertSame(
			array(),
			$unrecognised,
			"An autosave key is written in a shape this scan does not read, so the "
				. "uniqueness check above silently skips it:\n" . implode( "\n", $unrecognised )
		);

		// The collapse check: a parser that stops matching passes every
		// assertion above by finding nothing. 60 is well under the ~90 keys
		// present, so it fails on a collapse rather than on a deleted field.
		$this->assertGreaterThan( 60, $literal, 'The scan found almost no keys — it has stopped reading the markup.' );
	}
}
