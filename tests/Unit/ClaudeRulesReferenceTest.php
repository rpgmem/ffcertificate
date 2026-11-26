<?php
/**
 * The agent instruction files point at each other, and nothing else reads them (#2869).
 *
 * `CLAUDE.md` loads into every agent session; the files under `.claude/rules/`
 * load only when a session reads or edits a file one of their `paths:` globs
 * matches. Code, tests and the instruction files cite their sections as
 * `` `file` "Section" ``, and a move between files leaves such a reference
 * pointing at nothing, in silence: the final audit of #2867 found four. A rule
 * file breaks in silence too. With no `paths:` it loads in every session, which
 * is what moving it out of `CLAUDE.md` avoided. With a glob that matches no
 * file it never loads. And if no `CLAUDE.md` pointer names it, a session reaches
 * it only by chance.
 *
 * A section is a Markdown heading or a paragraph's bold lead (`**Rule.**`); the
 * comparison drops backticks, a heading's number and a trailing parenthesis, so
 * "Page scope on the admin `wrap` (#1184)" answers "Page scope on the admin
 * wrap". `docs/engineering/` is not scanned: its files are verbatim snapshots
 * whose references describe the moment they moved.
 *
 * It sees presence, never truth: a reference that names the right heading for
 * the wrong reason passes, and so does a rule file whose glob matches a file
 * nobody works on.
 *
 * @package FreeFormCertificate\Tests\Unit
 */

declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
final class ClaudeRulesReferenceTest extends TestCase {

	/**
	 * Where a qualified reference may live.
	 */
	private const SCANNED = array( 'CLAUDE.md', '.claude/rules', 'includes', 'tests', 'templates', 'assets/js', 'assets/css', '.github', 'uninstall.php', 'ffcertificate.php' );

	/**
	 * Directories never walked when matching a glob.
	 */
	private const SKIPPED_DIRS = array( '.git', 'vendor', 'node_modules', 'coverage', 'coverage-js', 'build', 'dist' );

	/**
	 * A qualified reference: the file, then one or more quoted sections joined by arrows.
	 */
	private const REFERENCE = '/`?(CLAUDE\.md|\.claude\/rules\/[a-z0-9-]+\.md)`?,?\s*((?:"[^"\n]{2,90}"(?:\s*→\s*)?)+)/u';

	/**
	 * Repository root.
	 *
	 * @return string
	 */
	private static function root(): string {
		return dirname( __DIR__, 2 );
	}

	/**
	 * The rule files, as paths relative to the root.
	 *
	 * @return list<string>
	 */
	private static function rule_files(): array {
		$files = glob( self::root() . '/.claude/rules/*.md' );
		$files = false === $files ? array() : $files;
		$out   = array();
		foreach ( $files as $file ) {
			$out[] = '.claude/rules/' . basename( $file );
		}
		sort( $out );
		return $out;
	}

	/**
	 * The instruction files: `CLAUDE.md` and every rule file.
	 *
	 * @return list<string>
	 */
	private static function instruction_files(): array {
		return array_merge( array( 'CLAUDE.md' ), self::rule_files() );
	}

	/**
	 * Normalises a section name for comparison.
	 *
	 * @param string $name A heading, a bold lead or a quoted reference.
	 * @return string
	 */
	private static function normalise( string $name ): string {
		$name = str_replace( '`', '', trim( $name ) );
		$name = (string) preg_replace( '/^\d+\.\s+/', '', $name );
		$name = (string) preg_replace( '/\s*\([^()]*\)\s*$/', '', $name );
		$name = (string) preg_replace( '/[.:]$/', '', $name );
		return strtolower( trim( $name ) );
	}

	/**
	 * The sections a text declares: its headings and its paragraphs' bold leads.
	 *
	 * @param string $text Markdown.
	 * @return list<string>
	 */
	private static function anchors_of( string $text ): array {
		$out = array();
		preg_match_all( '/^#{1,6} (.+)$/m', $text, $headings );
		preg_match_all( '/^(?:[-*]\s+|\d+\.\s+)?\*\*([^*\n]{3,160}?)\*\*/m', $text, $leads );
		foreach ( array_merge( $headings[1], $leads[1] ) as $name ) {
			$out[] = self::normalise( $name );
		}
		return $out;
	}

	/**
	 * Whether a section name resolves against a file's sections.
	 *
	 * A long enough name may be a prefix in either direction, so a reference can
	 * quote a heading without its suffix and a heading can carry a qualifier.
	 *
	 * @param string       $name    Normalised name.
	 * @param list<string> $anchors Normalised sections.
	 * @return bool
	 */
	private static function resolves( string $name, array $anchors ): bool {
		foreach ( $anchors as $anchor ) {
			if ( $anchor === $name
				|| ( strlen( $name ) > 8 && str_starts_with( $anchor, $name ) )
				|| ( strlen( $anchor ) > 8 && str_starts_with( $name, $anchor ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Sections per instruction file.
	 *
	 * @return array<string, list<string>>
	 */
	private static function anchors(): array {
		$out = array();
		foreach ( self::instruction_files() as $file ) {
			$out[ $file ] = self::anchors_of( (string) file_get_contents( self::root() . '/' . $file ) );
		}
		return $out;
	}

	/**
	 * Files that may carry a reference, as relative path => contents.
	 *
	 * @return array<string, string>
	 */
	private static function scanned_texts(): array {
		$root = self::root();
		$out  = array();
		foreach ( self::SCANNED as $entry ) {
			$path = $root . '/' . $entry;
			if ( is_file( $path ) ) {
				$out[ $entry ] = (string) file_get_contents( $path );
				continue;
			}
			if ( ! is_dir( $path ) ) {
				continue;
			}
			$it = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $path, \FilesystemIterator::SKIP_DOTS ) );
			foreach ( $it as $file ) {
				$name = (string) $file;
				if ( ! $file->isFile() || preg_match( '/\.min\.(js|css)$|\.(png|jpe?g|gif|webp|mo|zip|woff2?|ttf)$/', $name ) ) {
					continue;
				}
				$out[ substr( $name, strlen( $root ) + 1 ) ] = (string) file_get_contents( $name );
			}
		}
		return $out;
	}

	/**
	 * Every qualified reference that names no section of its file.
	 *
	 * @param array<string, string>       $texts   Relative path => contents.
	 * @param array<string, list<string>> $anchors Sections per instruction file.
	 * @return list<string>
	 */
	private static function broken_references( array $texts, array $anchors ): array {
		$broken = array();
		foreach ( $texts as $path => $text ) {
			preg_match_all( self::REFERENCE, $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE );
			foreach ( $matches as $match ) {
				$target = $match[1][0];
				$line   = substr_count( substr( $text, 0, $match[0][1] ), "\n" ) + 1;
				if ( ! isset( $anchors[ $target ] ) ) {
					$broken[] = sprintf( '%s:%d names %s, which does not exist', $path, $line, $target );
					continue;
				}
				preg_match_all( '/"([^"\n]+)"/', $match[2][0], $names );
				foreach ( $names[1] as $name ) {
					if ( ! self::resolves( self::normalise( $name ), $anchors[ $target ] ) ) {
						$broken[] = sprintf( '%s:%d: %s "%s"', $path, $line, $target, $name );
					}
				}
			}
		}
		return $broken;
	}

	/**
	 * The `paths:` globs a rule file declares.
	 *
	 * @param string $text The rule file.
	 * @return list<string>
	 */
	private static function paths_of( string $text ): array {
		if ( ! preg_match( '/\A---\n(.*?)\n---\n/s', $text, $front ) ) {
			return array();
		}
		if ( ! preg_match( '/^paths:\s*\n((?:\s+-\s+.+\n?)+)/m', $front[1] . "\n", $block ) ) {
			return array();
		}
		preg_match_all( '/^\s+-\s+"?([^"\n]+?)"?\s*$/m', $block[1], $globs );
		return array_values( array_filter( array_map( 'trim', $globs[1] ) ) );
	}

	/**
	 * Every file in the repository, relative to the root.
	 *
	 * @return list<string>
	 */
	private static function repository_files(): array {
		$root = self::root();
		$dir  = new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS );
		$skip = new \RecursiveCallbackFilterIterator(
			$dir,
			static function ( \SplFileInfo $file ): bool {
				return ! ( $file->isDir() && in_array( $file->getFilename(), self::SKIPPED_DIRS, true ) );
			}
		);
		$out  = array();
		foreach ( new \RecursiveIteratorIterator( $skip ) as $file ) {
			$out[] = substr( (string) $file, strlen( $root ) + 1 );
		}
		return $out;
	}

	/**
	 * A `paths:` glob as a regular expression (`**` crosses directories, `*` does not).
	 *
	 * @param string $glob The glob.
	 * @return string
	 */
	private static function glob_regex( string $glob ): string {
		$out = '';
		$len = strlen( $glob );
		for ( $i = 0; $i < $len; $i++ ) {
			$c = $glob[ $i ];
			if ( '*' === $c && $i + 1 < $len && '*' === $glob[ $i + 1 ] ) {
				$out .= '.*';
				++$i;
			} elseif ( '*' === $c ) {
				$out .= '[^/]*';
			} elseif ( '?' === $c ) {
				$out .= '[^/]';
			} else {
				$out .= preg_quote( $c, '#' );
			}
		}
		return '#\A' . $out . '\z#';
	}

	/**
	 * Every rule file declares where it applies, and every glob matches a file.
	 */
	public function test_every_rule_file_is_path_scoped_to_files_that_exist(): void {
		$files    = self::repository_files();
		$problems = array();
		foreach ( self::rule_files() as $rule ) {
			$globs = self::paths_of( (string) file_get_contents( self::root() . '/' . $rule ) );
			if ( array() === $globs ) {
				$problems[] = $rule . ' declares no paths: it would load in every session; its text belongs in CLAUDE.md';
				continue;
			}
			foreach ( $globs as $glob ) {
				if ( array() === preg_grep( self::glob_regex( $glob ), $files ) ) {
					$problems[] = sprintf( '%s: "%s" matches no file, so the rule never loads', $rule, $glob );
				}
			}
		}

		$this->assertSame( array(), $problems, "A rule file would not load as intended:\n  " . implode( "\n  ", $problems ) );
	}

	/**
	 * `CLAUDE.md` points at every rule file, so a session that needs one before
	 * touching its paths is told where it is.
	 */
	public function test_claude_md_names_every_rule_file(): void {
		$claude  = (string) file_get_contents( self::root() . '/CLAUDE.md' );
		$missing = array();
		foreach ( self::rule_files() as $rule ) {
			if ( ! str_contains( $claude, '`' . $rule . '`' ) ) {
				$missing[] = $rule;
			}
		}

		$this->assertSame( array(), $missing, "CLAUDE.md has no pointer to:\n  " . implode( "\n  ", $missing ) );
	}

	/**
	 * Every `` `file` "Section" `` reference names a section that exists in that file.
	 */
	public function test_every_qualified_reference_names_a_section_that_exists(): void {
		$broken = self::broken_references( self::scanned_texts(), self::anchors() );

		$this->assertSame(
			array(),
			$broken,
			"A reference names a section its file does not have:\n  " . implode( "\n  ", $broken )
			. "\n\nPoint it at the file that now holds the section, or at the section's current name."
		);
	}

	/**
	 * An unqualified quoted name in one instruction file that is a section only
	 * of another instruction file was written before the section moved.
	 */
	public function test_an_unqualified_name_does_not_point_at_another_files_section(): void {
		$anchors = self::anchors();
		$stale   = array();
		foreach ( $anchors as $file => $own ) {
			$text = (string) file_get_contents( self::root() . '/' . $file );
			preg_match_all( '/(?<![`\w])"([A-Z][^"\n]{3,80})"/u', $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE );
			foreach ( $matches as $match ) {
				$before = substr( $text, max( 0, $match[0][1] - 120 ), min( 120, $match[0][1] ) );
				if ( preg_match( '/(?:CLAUDE\.md|\.claude\/rules\/[a-z0-9-]+\.md)`?,?\s*(?:"[^"\n]*"\s*→\s*)*\z/u', $before ) ) {
					continue;
				}
				$name = self::normalise( $match[1][0] );
				if ( in_array( $name, $own, true ) ) {
					continue;
				}
				foreach ( $anchors as $other => $theirs ) {
					if ( $other !== $file && in_array( $name, $theirs, true ) ) {
						$stale[] = sprintf( '%s:%d: "%s" is a section of %s; qualify it', $file, substr_count( substr( $text, 0, $match[0][1] ), "\n" ) + 1, $match[1][0], $other );
					}
				}
			}
		}

		$this->assertSame( array(), $stale, "An instruction file names another file's section without saying which file:\n  " . implode( "\n  ", $stale ) );
	}

	/**
	 * Self-check: a second traversal finds the same rule files, and they exist.
	 */
	public function test_the_rule_file_list_matches_a_second_traversal(): void {
		$listed = scandir( self::root() . '/.claude/rules' );
		$listed = false === $listed ? array() : $listed;
		$recount = array();
		foreach ( $listed as $name ) {
			if ( str_ends_with( $name, '.md' ) ) {
				$recount[] = '.claude/rules/' . $name;
			}
		}
		sort( $recount );

		$this->assertNotSame( array(), $recount, 'No rule file found: the scan would pass on nothing.' );
		$this->assertSame( $recount, self::rule_files() );
	}

	/**
	 * Self-check: a broken reference injected into real text is reported, and the
	 * real text alone carries references the scan reads, so a zero is a reading.
	 */
	public function test_the_scan_reports_a_broken_reference_in_real_text(): void {
		$anchors = self::anchors();
		$texts   = self::scanned_texts();
		$rule    = self::rule_files()[0];

		$read = 0;
		foreach ( $texts as $text ) {
			$read += preg_match_all( self::REFERENCE, $text );
		}
		$this->assertGreaterThan( 0, $read, 'The scan read no reference at all.' );

		$texts['CLAUDE.md'] .= "\nSee `" . $rule . '` "A section that was never written".' . "\n";
		$texts['CLAUDE.md'] .= "\nSee `CLAUDE.md` \"Quality gates and testing\" → \"Nor this one\".\n";
		$broken = self::broken_references( $texts, $anchors );

		$this->assertCount( 2, $broken, "The canaries were not both reported:\n  " . implode( "\n  ", $broken ) );
		$this->assertStringContainsString( 'A section that was never written', $broken[0] );
		$this->assertStringContainsString( 'Nor this one', $broken[1] );
	}

	/**
	 * Self-check: the glob reader matches what it should and nothing else.
	 */
	public function test_the_glob_reader_matches_as_claude_code_does(): void {
		$this->assertSame( 1, preg_match( self::glob_regex( 'tests/**' ), 'tests/Unit/X.php' ) );
		$this->assertSame( 1, preg_match( self::glob_regex( 'assets/css/**' ), 'assets/css/ffc-common.css' ) );
		$this->assertSame( 0, preg_match( self::glob_regex( 'assets/css/*.css' ), 'assets/css/sub/x.css' ) );
		$this->assertSame( 0, preg_match( self::glob_regex( 'phpcs.xml.dist' ), 'phpcsAxml.dist' ) );
		$this->assertSame( array( 'tests/**', 'a.dist' ), self::paths_of( "---\npaths:\n  - \"tests/**\"\n  - a.dist\n---\n\n# X\n" ) );
		$this->assertSame( array(), self::paths_of( "# No front matter\n" ) );
	}
}
