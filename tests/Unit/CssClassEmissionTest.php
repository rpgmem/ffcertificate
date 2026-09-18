<?php
/**
 * CSS class-emission guard (#1170).
 *
 * Renaming a class is safe exactly as far as you can find who emits it — and a
 * grep cannot, because the name is usually assembled at runtime.
 * `tests/Support/CssClassEmitters.php` is the scan; this file is what keeps it
 * honest.
 *
 * **The shape tests are the content, not ceremony.** Every one of them was born
 * from a class the scan did NOT find, and each of those would have been a rename
 * breaking in silence. They are here so that the next change to the scan does
 * not undo one of them without anybody noticing.
 *
 * @package FreeFormCertificate\Tests\Unit
 */

declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use FreeFormCertificate\Tests\Support\CssClassEmitters;
use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
final class CssClassEmissionTest extends TestCase {

	/**
	 * Classes the ADMINISTRATOR's own HTML emits, not our code.
	 *
	 * `ffc-pdf-core.css` has a section headed **"UTILITY CLASSES FOR CERTIFICATE
	 * TEMPLATES"** and two comments reading, literally, *"Add class
	 * `ffc-responsive-logo` to img tag to enable"*. They are an API for whoever
	 * writes the certificate body — which lives in the database, not in the
	 * repository — and so no code scan can ever find an emitter for them.
	 *
	 * They are not open questions and they are not debt: they are the third
	 * category `WITHOUT_EMITTER`'s docblock always allowed for ("applied by
	 * something outside our code") and that, until this measurement, had never
	 * had an occupant.
	 *
	 * **#1170 classified them as dead** — "they appear NOWHERE in the
	 * repository" — and that was right about the repository and wrong about the
	 * world. Deleting them would silently break every certificate already using
	 * `class="ffc-txt-center"`, which is the use they were published for.
	 *
	 * @var array<string, string>
	 */
	private const TEMPLATE_API = array(
		'ffc-txt-center'      => 'section 9 of the sheet: alignment for the certificate body',
		'ffc-txt-left'        => 'section 9 of the sheet: alignment for the certificate body',
		'ffc-txt-right'       => 'section 9 of the sheet: alignment for the certificate body',
		'ffc-txt-justify'     => 'section 9 of the sheet: alignment for the certificate body',
		'ffc-full-width'      => 'section 9 of the sheet: full width for the certificate body',
		'ffc-full-width-img'  => 'comment in the sheet: "Add class ffc-full-width-img to img tag to enable"',
		'ffc-responsive-logo' => 'comment in the sheet: "Add class ffc-responsive-logo to img tag to enable"',
	);

	/**
	 * A compatibility shim the sheet declares as such.
	 *
	 * `ffc-pdf-core.css` has a section **13. LEGACY CLASSES (Backward
	 * compatibility)**. None of them has been emitted by our code at ANY point in
	 * the repository's history (`git log -S` over `assets/js`, `includes` and
	 * `templates` returns zero), so what they stay compatible with is outside
	 * here — a certificate body saved in the database, the same way the API above
	 * is.
	 *
	 * Each has a live sibling the JS emits today, and the pair names the rename:
	 * `stage`→`wrapper`, `bg-img`→`bg`, `user-content`→`content`,
	 * `temp-wrapper`→`temp-container`.
	 *
	 * **They are listed here rather than in `WITHOUT_EMITTER` because they are
	 * not open questions: they are a shim, and `CLAUDE.md` "Legacy and tech debt"
	 * requires install evidence — never a code scan — to retire one.** Its
	 * inventory now records
	 * them with their exit condition.
	 *
	 * @var array<string, string>
	 */
	private const LEGACY_SHIM = array(
		'ffc-pdf-stage'        => 'section 13 of the sheet; live sibling `ffc-pdf-wrapper`',
		'ffc-pdf-bg-img'       => 'section 13 of the sheet; live sibling `ffc-pdf-bg`',
		'ffc-pdf-user-content' => 'section 13 of the sheet; live sibling `ffc-pdf-content`',
		'ffc-pdf-temp-wrapper' => 'paired with the live `ffc-pdf-temp-container` in the wp-admin rule',
	);

	/**
	 * Classes the sheets declare for which the scan finds no emitter.
	 *
	 * A ratchet that only shrinks. It is not a list of dead code — it is a list
	 * of **open questions**, and every entry has one of three answers:
	 *
	 *  - genuinely dead;
	 *  - emitted by a shape the scan does not know yet — and there the fix is to
	 *    teach the scan, never to lower the guard;
	 *  - applied by something outside our code.
	 *
	 * **Of the original 24 entries, nine were the second answer** — they had an
	 * emitter in the repository and it was the scan that could not read the
	 * shape. Teaching it answered all nine at once, and the three new shapes are
	 * in `provider_shapes()`. Another eleven were the third: they became
	 * `TEMPLATE_API` and `LEGACY_SHIM` above, each with the evidence that took it
	 * out of here.
	 *
	 * Four remain, and all four are candidates for the FIRST answer — never
	 * emitted in the repository's history, with no section of the sheet claiming
	 * them. They stay as questions because deleting CSS a template author may be
	 * using is a product decision, not a scan's.
	 *
	 * @var array<int, string>
	 */
	private const WITHOUT_EMITTER = array(
		'ffc-cap-chip--color',
		'ffc-flex',
		'ffc-pdf-progress-overlay',
		'ffc-progress-spinner',
	);

	/**
	 * The shapes the scan has to know, with a real case of each.
	 *
	 * The middle column is the class; the last one is the shape that emits it.
	 * Every entry here has failed once.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function provider_shapes(): array {
		return array(
			'literal attribute'           => array( 'ffc-status-badge', 'literal' ),
			'jQuery class API'            => array( 'ffc-collapsed', 'literal' ),
			'selector inside a string'    => array( 'ffc-timeslot-full', 'literal' ),
			'echo embedded in attribute'  => array( 'ffc-audience-status-active', 'prefix' ),
			'printf placeholder'          => array( 'ffc-cap-origin--user', 'prefix' ),
			'concatenation at str end'    => array( 'ffc-dashboard-status-confirmed', 'prefix' ),
			'PHP interpolation'           => array( 'ffc-verification-status-cancelled', 'prefix' ),
			'attribute opened not closed' => array( 'ffc-appointments-table', 'literal' ),
			'several classes in a string' => array( 'ffc-has-geofence', 'literal' ),
			'library option'              => array( 'ffc-sortable-placeholder', 'literal' ),
		);
	}

	/**
	 * Every known shape is still found.
	 *
	 * @dataProvider provider_shapes
	 * @param string $class A real class declared in the sheets.
	 * @param string $how   `literal` or `prefix`.
	 */
	public function test_every_known_shape_is_still_found( string $class, string $how ): void {
		$found = CssClassEmitters::of( $class );

		$this->assertSame(
			$how,
			$found['how'],
			"The scan stopped finding `{$class}` by `{$how}`.\n"
			. "That shape has failed once already, and each of its failures is a rename that\n"
			. 'breaks in silence — teach the scan back, do not relax the test.'
		);
		$this->assertNotEmpty( $found['files'], "Found `{$class}` but cannot say where." );
	}

	/**
	 * No new class enters without a known emitter.
	 */
	public function test_no_new_class_lacks_an_emitter(): void {
		$new = array();

		foreach ( CssClassEmitters::declared_ffc_classes() as $class ) {
			if ( 'none' !== CssClassEmitters::of( $class )['how'] ) {
				continue;
			}
			if (
				! in_array( $class, self::WITHOUT_EMITTER, true )
				&& ! isset( self::TEMPLATE_API[ $class ] )
				&& ! isset( self::LEGACY_SHIM[ $class ] )
			) {
				$new[] = $class;
			}
		}

		$this->assertSame(
			array(),
			$new,
			"Declared class nobody emits:\n  ." . implode( "\n  .", $new )
			. "\n\nEither it is dead, or it is emitted by a shape the scan does not know."
			. "\nIf it is the second case, teach `CssClassEmitters` — adding it to the list"
			. "\nhides exactly what the list exists to show."
		);
	}

	/**
	 * A listed entry that gained an emitter leaves the list.
	 *
	 * The direction that locks the win in, as in the other ratchets.
	 */
	public function test_the_list_only_shrinks(): void {
		$resolved = array();

		foreach ( self::listed_classes() as $class ) {
			$found = CssClassEmitters::of( $class );
			if ( 'none' !== $found['how'] ) {
				$resolved[] = sprintf( '%s (now by %s)', $class, $found['how'] );
			}
		}

		$this->assertSame(
			array(),
			$resolved,
			"These gained an emitter — drop them from whichever list they are in:\n  " . implode( "\n  ", $resolved )
		);
	}

	/**
	 * Every listed entry is still a declared class.
	 */
	public function test_every_listed_class_is_still_declared(): void {
		$declared = CssClassEmitters::declared_ffc_classes();

		foreach ( self::listed_classes() as $class ) {
			$this->assertContains(
				$class,
				$declared,
				"`{$class}` is no longer declared in any sheet. Drop it from the list."
			);
		}
	}

	/**
	 * The three lists together — no class may be in two of them.
	 *
	 * @return array<int, string>
	 */
	private static function listed_classes(): array {
		return array_merge(
			self::WITHOUT_EMITTER,
			array_keys( self::TEMPLATE_API ),
			array_keys( self::LEGACY_SHIM )
		);
	}

	/**
	 * A class belongs to exactly one of the three lists.
	 *
	 * The three say different things — open question, published API, shim with
	 * an exit condition — so a class in two of them is a contradictory statement
	 * about what to do with it.
	 */
	public function test_no_class_is_listed_twice(): void {
		$all  = self::listed_classes();
		$dupe = array_keys( array_filter( array_count_values( $all ), static fn ( int $n ): bool => $n > 1 ) );

		$this->assertSame( array(), $dupe, 'Class in more than one list: ' . implode( ', ', $dupe ) );
	}

	/**
	 * Every reason says something.
	 *
	 * The 20-character floor is against "legacy" and "unused" — it is not a
	 * quality measure, it is the same floor the other suppression guards use.
	 */
	public function test_every_reason_says_something(): void {
		foreach ( array( 'TEMPLATE_API' => self::TEMPLATE_API, 'LEGACY_SHIM' => self::LEGACY_SHIM ) as $list => $entries ) {
			foreach ( $entries as $class => $reason ) {
				$this->assertGreaterThan(
					20,
					strlen( $reason ),
					"The reason for `{$class}` in {$list} does not say enough: the reader needs to know WHY there is no emitter."
				);
			}
		}
	}

	/**
	 * The scan must not collapse in silence.
	 *
	 * An empty map satisfies `assertSame( array(), $new )` just as well as a
	 * correct one — the #1071 / #1094 shape. The floors are deliberately loose:
	 * they say "the scan worked", not how big the codebase is.
	 */
	public function test_the_scan_cannot_collapse_in_silence(): void {
		$this->assertGreaterThan( 800, count( CssClassEmitters::declared_ffc_classes() ), 'Reading the sheets collapsed.' );
		$this->assertGreaterThan( 500, count( CssClassEmitters::literals() ), 'The literal map collapsed.' );
		$this->assertGreaterThan( 10, count( CssClassEmitters::prefixes() ), 'The prefix map collapsed.' );

		// And the scan has to be able to say NO: an invented name has no emitter.
		$this->assertSame(
			'none',
			CssClassEmitters::of( 'ffc-class-that-exists-nowhere' )['how'],
			'The scan finds an emitter for anything — the net is catching the ocean.'
		);
	}
}
