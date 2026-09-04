<?php
/**
 * PHPCS-suppression guard (#1035).
 *
 * Three things go wrong with a PHPCS annotation. None of them turns the gate
 * red — a suppression that suppresses nothing, or suppresses too much, or
 * cancels another one, all look identical to a green run — so each needed a
 * guard of its own.
 *
 * THE REASON. `phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching` with
 * nothing after it records that someone silenced the sniff, never why it was
 * safe. #1035 found 234 of them and, writing the reasons, discovered the answer
 * differs per site in ways the silence had been flattening: a schema probe must
 * NOT be cached, while a DESCRIBE two files away is memoised in a static and
 * therefore already does what the sniff asks.
 *
 * THE CANCELLATION. PHPCS annotations are a flat on/off switch per sniff, not a
 * stack. A `phpcs:enable X` inside a file turns X back on for the rest of that
 * file no matter who turned it off — so an inner disable/enable pair silently
 * ends an enclosing file-level disable at the `enable`, leaving the tail of the
 * file uncovered while the file-level comment still claims otherwise. This was
 * live in four classes.
 *
 * THE BARE FORM. `phpcs:ignore` naming no sniff at all silences every sniff on
 * the line, including ones nobody was thinking about when it was written.
 *
 * WHAT THIS CANNOT SEE. Whether a reason is *true*, and whether a suppression
 * is still needed. Deadness is measured, not asserted: neutralise every
 * annotation in place — rewrite the sniff it names to one that cannot fire,
 * never rename the `phpcs:` token itself, which turns the directive into an
 * ordinary comment and trips `Squiz.Commenting.InlineComment` on 439 lines that
 * were fine — then re-run PHPCS and map the violations that surface back to the
 * annotations covering them. That is how #1031, #1035 and this file's own
 * numbers were produced.
 *
 * Dependency-free on purpose — no WordPress, no Brain\Monkey — like the module
 * boundary, AJAX wiring and settings-default guards. It reads source text only.
 *
 * @package FreeFormCertificate\Tests\Unit
 */

declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
final class PhpcsSuppressionTest extends TestCase {

	/**
	 * Directories PHPCS itself does not scan (mirrors phpcs.xml.dist).
	 *
	 * @var array<int, string>
	 */
	private const SKIP_DIRS = array( 'vendor', 'node_modules', 'build', 'dist', 'languages', 'html', 'libs', 'tests', '.github', '.git' );

	/**
	 * Shortest reason accepted. A floor against emptiness ("ok", "safe", "see above."),
	 * not a quality bar — "read-only filter." is terse and correct.
	 */
	private const MIN_REASON_LENGTH = 15;

	/**
	 * WordPress core tables, by the `$wpdb` property that names each one.
	 *
	 * @var string
	 */
	private const CORE_TABLE_PROPERTIES = 'posts|users|usermeta|postmeta|options|comments|commentmeta|terms|termmeta|term_taxonomy|term_relationships|links|blogs|blogmeta|site|sitemeta|signups|registration_log|base_prefix';

	/**
	 * Files allowed a file-level DirectDatabaseQuery disable despite naming a core
	 * table. Each entry states why the reference is unavoidable — a file that
	 * merely grew one does not belong here; narrow the disable instead.
	 *
	 * @var array<string, string>
	 */
	private const CORE_TABLE_EXCEPTIONS = array(
		// The foreign keys this migration creates all point at wp_users, so it has
		// to read that table's storage engine and name it in the REFERENCES clause.
		'includes/migrations/class-ffc-migration-foreign-keys.php' => 'creates the user_id -> wp_users foreign keys',
	);

	/**
	 * Absolute path to the repository root.
	 */
	private static function root(): string {
		return dirname( __DIR__, 2 );
	}

	/**
	 * Repository-relative path, for readable failure messages.
	 */
	private static function relative( string $path ): string {
		return ltrim( str_replace( self::root(), '', $path ), '/' );
	}

	/**
	 * Every PHP file inside the PHPCS scope.
	 *
	 * @return array<int, string>
	 */
	private static function sources(): array {
		$found = array();

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( self::root(), \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::SELF_FIRST
		);

		/** @var \SplFileInfo $file */
		foreach ( $iterator as $file ) {
			if ( $file->isDir() ) {
				continue;
			}
			if ( 'php' !== $file->getExtension() ) {
				continue;
			}

			$relative = self::relative( $file->getPathname() );
			$segments = explode( '/', $relative );
			array_pop( $segments );

			if ( array_intersect( $segments, self::SKIP_DIRS ) ) {
				continue;
			}
			if ( str_starts_with( $relative, 'includes/libraries/' ) ) {
				continue;
			}

			$found[] = $file->getPathname();
		}

		sort( $found );

		return $found;
	}

	/**
	 * Parse every annotation out of one file.
	 *
	 * Only a token that opens its comment counts. `phpcs:ignore` appearing in
	 * the middle of a docblock sentence — "…option writes and phpcs:ignore
	 * annotations." — is prose, not a directive, and PHPCS ignores it too.
	 *
	 * A `//` comment ends at `?>`, so the sniff list must stop there (and at
	 * `*` + `/` for the block form) or the close tag is read as part of it.
	 *
	 * @param string $path Absolute file path.
	 * @return array<int, array{line: int, kind: string, sniffs: array<int, string>, reason: string, text: string}>
	 */
	private static function annotations( string $path ): array {
		$found = array();

		foreach ( explode( "\n", (string) file_get_contents( $path ) ) as $i => $line ) {
			if ( 1 !== preg_match( '/phpcs:(ignore|disable|enable)(?![A-Za-z])([^\n]*)$/', $line, $matches, PREG_OFFSET_CAPTURE ) ) {
				continue;
			}

			$offset = (int) $matches[0][1];
			$before = substr( $line, 0, $offset );

			// Everything between the comment opener and the token must be blank.
			if ( 1 !== preg_match( '#(//|/\*+|\*)\s*$#', $before ) ) {
				continue;
			}

			$rest  = (string) $matches[2][0];
			$parts = preg_split( '#\?>|\*/#', $rest );
			$rest  = false === $parts ? $rest : (string) $parts[0];

			$reason = '';
			if ( str_contains( $rest, '--' ) ) {
				list( $rest, $reason ) = explode( '--', $rest, 2 );
			}

			$sniffs = array_values( array_filter( array_map( 'trim', explode( ',', trim( $rest ) ) ) ) );

			$found[] = array(
				'line'   => $i + 1,
				'kind'   => (string) $matches[1][0],
				'sniffs' => $sniffs,
				'reason' => trim( $reason ),
				'text'   => trim( $line ),
			);
		}

		return $found;
	}

	public function test_every_suppression_names_a_sniff(): void {
		$offenders = array();

		foreach ( self::sources() as $file ) {
			foreach ( self::annotations( $file ) as $annotation ) {
				if ( array() === $annotation['sniffs'] ) {
					$offenders[] = self::relative( $file ) . ':' . $annotation['line'] . ' — ' . $annotation['text'];
				}
			}
		}

		$this->assertSame(
			array(),
			$offenders,
			"A bare annotation silences EVERY sniff on the line or in the range, including ones\n"
			. "nobody had in mind when it was written. Name the sniff it is actually for:\n\n  "
			. implode( "\n  ", $offenders )
		);
	}

	public function test_every_suppression_carries_a_reason(): void {
		$offenders = array();

		foreach ( self::sources() as $file ) {
			foreach ( self::annotations( $file ) as $annotation ) {
				if ( 'enable' === $annotation['kind'] ) {
					continue; // An enable restores the default; there is nothing to justify.
				}

				if ( strlen( $annotation['reason'] ) < self::MIN_REASON_LENGTH ) {
					$offenders[] = self::relative( $file ) . ':' . $annotation['line'] . ' — ' . $annotation['text'];
				}
			}
		}

		$this->assertSame(
			array(),
			$offenders,
			"A suppression with no reason records that a sniff was silenced, never why that is\n"
			. "safe — and the answer differs per site. Add `-- <why>` after the sniff name:\n\n  "
			. implode( "\n  ", $offenders )
		);
	}

	public function test_no_enable_cancels_an_enclosing_disable(): void {
		$offenders = array();

		foreach ( self::sources() as $file ) {
			/** @var array<string, int> $open Sniff => how many disables are open for it. */
			$open = array();

			foreach ( self::annotations( $file ) as $annotation ) {
				foreach ( $annotation['sniffs'] as $sniff ) {
					if ( 'disable' === $annotation['kind'] ) {
						$open[ $sniff ] = ( $open[ $sniff ] ?? 0 ) + 1;
						continue;
					}

					// An enable cancels every open disable naming this sniff, or a
					// parent of it — PHPCS matches by dotted prefix.
					$cancels = 0;
					foreach ( $open as $candidate => $depth ) {
						if ( $depth > 0 && self::related( $candidate, $sniff ) ) {
							$cancels           += $depth;
							$open[ $candidate ] = 0;
						}
					}

					if ( $cancels > 1 ) {
						$offenders[] = self::relative( $file ) . ':' . $annotation['line'] . ' — ' . $sniff;
					}
				}
			}
		}

		$this->assertSame(
			array(),
			$offenders,
			"PHPCS annotations are a flat on/off switch, not a stack: this `phpcs:enable` turns the\n"
			. "sniff back on for the REST of the file, ending an enclosing disable early and leaving\n"
			. "its tail uncovered while its comment still claims otherwise. Drop the sniff from the\n"
			. "inner pair — the outer disable already covers it:\n\n  "
			. implode( "\n  ", $offenders )
		);
	}

	public function test_file_level_direct_query_disables_only_cover_plugin_tables(): void {
		$offenders = array();
		$core      = '/\\$wpdb->(' . self::CORE_TABLE_PROPERTIES . ')\\b'
			. '|\\$wpdb->prefix\\s*\\.\\s*\'(?!ffc_)/';

		foreach ( self::sources() as $file ) {
			$source = (string) file_get_contents( $file );

			$covers_family = false;
			foreach ( self::annotations( $file ) as $annotation ) {
				if ( 'disable' !== $annotation['kind'] ) {
					continue;
				}
				foreach ( $annotation['sniffs'] as $sniff ) {
					if ( self::related( $sniff, 'WordPress.DB.DirectDatabaseQuery' ) ) {
						$covers_family = true;
					}
				}
			}

			if ( ! $covers_family ) {
				continue;
			}

			$relative = self::relative( $file );
			if ( isset( self::CORE_TABLE_EXCEPTIONS[ $relative ] ) ) {
				continue;
			}

			if ( 1 === preg_match( $core, $source, $matches ) ) {
				$offenders[] = $relative . ' — ' . trim( $matches[0] );
			}
		}

		$this->assertSame(
			array(),
			$offenders,
			"A file-level DirectDatabaseQuery disable is only justified while every query in the\n"
			. "file runs against one of the plugin's own ffc_* tables — that is the exact test that\n"
			. "chose the files carrying one (#1035). These name a WordPress core table, where the\n"
			. "sniff has something real to say: use the WP API, or drop the file-level disable and\n"
			. "annotate the plugin-table queries per line:\n\n  "
			. implode( "\n  ", $offenders )
		);
	}

	/**
	 * Whether two sniff names match, in either direction, by PHPCS's dotted prefix rule.
	 *
	 * @param string $a First sniff name.
	 * @param string $b Second sniff name.
	 */
	private static function related( string $a, string $b ): bool {
		return $a === $b || str_starts_with( $a, $b . '.' ) || str_starts_with( $b, $a . '.' );
	}
}
