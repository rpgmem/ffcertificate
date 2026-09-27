<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FreeFormCertificate\Tests\Support\PhpSource;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Recruitment\RecruitmentErrorMessages;

/**
 * The CSV error codes an operator can be shown must all have a sentence.
 *
 * NEITHER DIRECTION IS HYPOTHETICAL -- both were live, and wider than expected
 * (#1489).
 *
 * Four codes were emitted with no entry in `RecruitmentErrorMessages::map()`.
 * An unmapped code passes through VERBATIM by design, so the operator read
 * `Line 12: recruitment_csv_cpf_too_long` instead of a sentence. Meanwhile three
 * labels sat in the map with nothing emitting them. The two lists had drifted in
 * opposite directions at once and nothing reported it.
 *
 * Modelled on `CapabilityCatalogTest`'s invariant between
 * `CapabilityCatalog::all_slugs()` and `CapabilityManager::get_all_capabilities()`:
 * two lists that must agree as a set, with a test that fails when one moves.
 *
 * @covers \FreeFormCertificate\Recruitment\RecruitmentErrorMessages
 */
class RecruitmentErrorCodeCoverageTest extends TestCase {

	/**
	 * The module scanned for emitted codes, relative to the plugin root.
	 *
	 * A DIRECTORY RATHER THAN A LIST, BECAUSE THE LIST WAS ALREADY WRONG.
	 *
	 * This began as two hand-named files and the guard's own second direction
	 * caught it: codes are emitted from five files, so the list reported live
	 * labels as dead. That is the decaying-register shape `CLAUDE.md` describes --
	 * a hand-maintained population nobody updates when a file is added. A
	 * directory needs no maintenance.
	 *
	 * @var string
	 */
	private const MODULE = 'includes/recruitment';

	/**
	 * The file holding the labels, which is not an emitter.
	 *
	 * @var string
	 */
	private const MAP_FILE = 'includes/recruitment/class-ffc-recruitment-error-messages.php';

	/**
	 * The two shapes that put a code where `translate()` will render it.
	 *
	 * SCOPED BY HOW A CODE IS USED, NOT BY WHICH FILE USES IT.
	 *
	 * The `recruitment_csv_` prefix carries THREE vocabularies, and this guard
	 * met all three while being written. Only the first needs a map entry:
	 *
	 * - **Line and file errors** — `line_error( $n, 'code' )`, or a code inside
	 *   an `'errors' => array( … )` literal. These reach an operator through
	 *   `translate_all()`, so an unmapped one is shown as its own identifier.
	 * - **`WP_Error` codes** in the classifications REST controller, which carry
	 *   their translated message as the second argument. The message travels with
	 *   the code, so a map entry would be a second copy that drifts.
	 * - **Activity-log action names** (`recruitment_csv_imported`), labelled by
	 *   the activity-log page's own map.
	 *
	 * Matching the USE rather than excluding files is what keeps this honest as
	 * files are added. The limit is that a fourth shape would go unseen -- and it
	 * would fail LOUDLY rather than quietly, because its label would then read as
	 * dead: a failing test asking to be taught, never a silent pass.
	 *
	 * Both patterns accept the composite form, where the separator `translate()`
	 * splits on is INSIDE the literal (`'recruitment_csv_adjutancy_not_in_notice: '
	 * . $slug`). A pattern anchored on the closing quote never sees that code, and
	 * it reported two live labels as dead -- which is how the shape was found.
	 *
	 * @var array<int, string>
	 */
	private const RENDERED_SHAPES = array(
		'/line_error\([^;]*?\x27(recruitment_csv_[a-z0-9_]+)(?::\s*)?\x27/s',
		'/\x27errors\x27\s*=>\s*array\(\s*\x27(recruitment_csv_[a-z0-9_]+)(?::\s*)?\x27/s',
	);

	/**
	 * Set up Brain\Monkey so the map's `__()` calls resolve.
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'size_format' )->justReturn( '10 MB' );
	}

	/**
	 * Tear down Brain\Monkey.
	 */
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Every module file that can emit a rendered error code.
	 *
	 * REPOSITORY-RELATIVE, which is what `PhpSource` speaks and what the filter
	 * below already had to compute anyway.
	 *
	 * @return array<int, string>
	 */
	private function sources(): array {
		$root = dirname( __DIR__, 2 ) . '/';
		$all  = glob( $root . self::MODULE . '/*.php' );

		$this->assertNotEmpty( $all, 'The module scan found no file at all, so it proves nothing.' );

		$out = array();

		foreach ( (array) $all as $path ) {
			$relative = substr( (string) $path, strlen( $root ) );

			if ( self::MAP_FILE !== $relative ) {
				$out[] = $relative;
			}
		}

		return $out;
	}

	/**
	 * Every code the module puts somewhere `translate()` will render it.
	 *
	 * Read as text rather than by running the validator: reaching every rule
	 * would mean constructing a row per rule, and a rule added without such a row
	 * would then be invisible to exactly the check this makes.
	 *
	 * @return array<int, string>
	 */
	private function emitted(): array {
		$found = array();

		foreach ( $this->sources() as $path ) {
			// STRIPPED BY PHP ITSELF, NOT BY A REGEX FOR COMMENTS.
			//
			// Several files NAME codes in a docblock, and a scan that reads prose
			// as a directive is the trap `CLAUDE.md` records for the suppression
			// scanners. `PhpSource::code()` removes comments with the same lexer
			// that runs the file -- the `TranslationCatalogueAgreement` rule
			// applied here: parse the file with its own language where you can.
			//
			// THROUGH THE SHARED READER, NOT `php_strip_whitespace()` (#1493).
			// That call opens the file, and Brain\Monkey's Patchwork registers a
			// stream wrapper over every open -- so twenty files here are twenty
			// passes through it, charged against a budget a different test set.
			// `PhpSource::code()` reads the bytes once and tokenises a string.
			$code = PhpSource::code( $path );

			foreach ( self::RENDERED_SHAPES as $pattern ) {
				preg_match_all( $pattern, $code, $matches );

				$found = array_merge( $found, $matches[1] );
			}
		}

		$found = array_values( array_unique( $found ) );
		sort( $found );

		return $found;
	}

	/**
	 * Every `recruitment_csv_*` key the message map carries.
	 *
	 * READ AS SOURCE BECAUSE `map()` IS PRIVATE, AND THAT IS THE RIGHT SHAPE.
	 *
	 * Opening it up for a test would widen a seam for no other caller. The
	 * operator-facing direction is checked through `translate()` below, which is
	 * the public behaviour and a stronger claim than the array's contents; this
	 * list exists only for the opposite direction, where a key with no emitter has
	 * no observable behaviour to assert on at all.
	 *
	 * The `=>` is required, not decoration: this file's own comments name codes in
	 * prose, and so does the map's docblock.
	 *
	 * @return array<int, string>
	 */
	private function labelled(): array {
		$this->assertFileExists( dirname( __DIR__, 2 ) . '/' . self::MAP_FILE );

		preg_match_all(
			'/\x27(recruitment_csv_[a-z0-9_]+)\x27\s*=>/',
			PhpSource::code( self::MAP_FILE ),
			$matches
		);

		$keys = array_values( array_unique( $matches[1] ) );
		sort( $keys );

		return $keys;
	}

	/**
	 * The scan reached both lists, so an empty result cannot read as agreement.
	 *
	 * The #1071 / #1094 rule: two empty sets are equal, so a collapsed scan would
	 * report this file's whole point as satisfied. The recount is taken by a
	 * different traversal -- one concatenation of every source rather than a
	 * per-file loop -- which is the shape `CLAUDE.md` prefers over a floor,
	 * because adding a file moves both sides.
	 */
	public function test_the_scan_read_both_lists(): void {
		$emitted  = $this->emitted();
		$labelled = $this->labelled();

		$this->assertNotEmpty( $emitted, 'No emitted code found: the scan collapsed and proves nothing.' );
		$this->assertNotEmpty( $labelled, 'No labelled code found: the map moved and this scan cannot see it.' );

		$blob = '';
		foreach ( $this->sources() as $path ) {
			$blob .= PhpSource::code( $path );
		}

		$recount = array();
		foreach ( self::RENDERED_SHAPES as $pattern ) {
			preg_match_all( $pattern, $blob, $all );
			$recount = array_merge( $recount, $all[1] );
		}

		$recount = array_values( array_unique( $recount ) );
		sort( $recount );

		$this->assertSame( $recount, $emitted, 'The two readings of the sources disagree, so neither can be trusted.' );
	}

	/**
	 * Every code an operator can be shown has a sentence to show.
	 *
	 * This is the direction that was live and visible on screen.
	 */
	public function test_every_emitted_code_has_a_label(): void {
		$unreadable = array();

		foreach ( $this->emitted() as $code ) {
			// THE OBSERVABLE PROPERTY, THROUGH THE PUBLIC SEAM.
			//
			// An unmapped code is returned unchanged, so `translate()` giving back
			// its own input is exactly what the operator would have been shown.
			// Comparing the two lists instead would say something about today's
			// map and nothing about what reaches a screen.
			if ( RecruitmentErrorMessages::translate( $code ) === $code ) {
				$unreadable[] = $code;
			}
		}

		$this->assertSame(
			array(),
			$unreadable,
			"These codes reach an operator as their own identifier:\n- " . implode( "\n- ", $unreadable )
		);
	}

	/**
	 * And no label describes a code nothing emits.
	 *
	 * The quieter direction, and translation is why it matters: a dead entry is a
	 * string a translator carries forever for a case that cannot occur.
	 */
	public function test_every_label_describes_a_code_that_is_emitted(): void {
		$dead = array_values( array_diff( $this->labelled(), $this->emitted() ) );

		$this->assertSame(
			array(),
			$dead,
			"These labels describe codes nothing emits:\n- " . implode( "\n- ", $dead )
		);
	}

	/**
	 * The line prefix survives translation, so the operator is told WHICH line.
	 *
	 * `translate()` strips `line=N: ` and re-prefixes it. A label that arrived
	 * without that happening would name the defect and not the row, which on a
	 * five-thousand-row import is most of the answer missing.
	 */
	public function test_a_line_scoped_code_keeps_its_line(): void {
		$rendered = RecruitmentErrorMessages::translate( 'line=12: recruitment_csv_cpf_invalid' );

		$this->assertStringContainsString( '12', $rendered );
		$this->assertStringNotContainsString( 'recruitment_csv_', $rendered, 'The identifier must not reach the operator.' );
	}
}
