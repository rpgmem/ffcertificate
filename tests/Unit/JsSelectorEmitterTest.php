<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use FreeFormCertificate\Tests\Support\JsIdSelectors;
use PHPUnit\Framework\TestCase;

/**
 * An id the JS looks for must be emitted by somebody (#1220).
 *
 * Three times in one session a test passed green over markup the product never
 * rendered: `.ffc-tab.active` after #1170's rename, which left the user
 * dashboard loading no panel at all (#1204); one screen's markup built by hand
 * inside another screen's (#1184); and `#ffc_rereg_acumulo`, which no PHP emits
 * and which existed only in the JS looking for it and in a fixture that invented
 * it (#1219). The pattern is always the same: **a hand-written fixture ages in
 * silence**.
 *
 * This guard sees the PRODUCT half -- the selector nobody emits. The other half
 * (the fixture matching the real markup) would mean comparing the test's HTML
 * with what PHP emits through templates full of conditionals, and is not here.
 * It is worth writing down, because in all three cases the defect was in both
 * halves.
 *
 * **The naive shape does not work, and the measurement says why.** Scanning PHP
 * alone would report dozens of false positives: most of the DOM the dashboard
 * looks for is created by the JS itself. The scan covers PHP and JS, and the
 * shapes it had to learn are in `JsIdSelectors`'s docblock -- each arrived
 * because of a false positive it produced.
 *
 * @covers \FreeFormCertificate\Tests\Support\JsIdSelectors
 */
class JsSelectorEmitterTest extends TestCase {

	/**
	 * Ids WordPress emits, not us.
	 *
	 * An exception of the same kind as `ClassNamingIdiomTest`'s
	 * `VENDOR_CLASSES`: the markup exists, just not in our repository.
	 *
	 * @var array<string, string>
	 */
	private const CORE_IDS = array(
		'wpbody-content' => 'The wp-admin content container, emitted by `wp-admin/admin-header.php`.',
		'title'          => 'The post editor\'s title field, emitted by `wp-admin/edit-form-advanced.php`.',
	);

	/**
	 * Ids the JS looks for and nobody emits.
	 *
	 * **A ratchet both ways**: a new id with no emitter fails (write the markup,
	 * or delete the lookup); an id here that gained an emitter also fails (drop
	 * it from the list to lock the win in).
	 *
	 * **It is empty, and it stayed empty by decision, not by accident.** The
	 * seven entries it opened with were analysed one by one in #1227: six were
	 * dead code and left with the code that looked for them (the id-based
	 * dependent select, superseded by the class-based implementation; the
	 * migrations dropdown, whose markup never existed in any revision; and
	 * `#ffc_bg_image_url`, whose `name`-attribute fallback already reached both
	 * real screens). The seventh -- `#ffc_bg_image_preview` -- was the opposite
	 * case: the preview code was written and correct, only the container was
	 * missing, so both screens started emitting it.
	 *
	 * Empty does not switch the guard off: what charges it is the test below,
	 * which scans the whole of `assets/js` on every run. A new entry here is a
	 * decision to defend, not a line to add.
	 *
	 * @var array<string, string>
	 */
	private const WITHOUT_EMITTER = array();

	public function test_every_id_the_javascript_looks_for_is_emitted_by_someone(): void {
		$consumers = JsIdSelectors::consumers();

		// Self-check: an empty scan must never read as "clean" (the #1071 /
		// #1094 lesson). Both sides have to have found a population.
		$this->assertGreaterThan(
			100,
			count( $consumers ),
			'The consumption scan came back almost empty — the parser broke, and a green here would mean nothing.'
		);
		$this->assertGreaterThan(
			300,
			JsIdSelectors::emitted_count(),
			'The emission scan came back almost empty — every id would look orphaned, or none would.'
		);

		$orphans = array();
		foreach ( $consumers as $id => $paths ) {
			if ( isset( self::CORE_IDS[ $id ] ) ) {
				continue;
			}
			if ( ! JsIdSelectors::is_emitted( $id ) ) {
				$orphans[ $id ] = $paths;
			}
		}

		$new = array_diff_key( $orphans, self::WITHOUT_EMITTER );
		$this->assertSame(
			array(),
			array_map( static fn( array $p ): string => implode( ', ', $p ), $new ),
			"A new id the JavaScript looks for and NOBODY emits.\n"
			. "Either the markup does not exist (#1219's defect), or it was renamed and the lookup was left behind (#1204's).\n"
			. 'If the emission shape is new, teach the scan in `JsIdSelectors` — never add to the list to silence the guard.'
		);

		$fixed = array_diff_key( self::WITHOUT_EMITTER, $orphans );
		$this->assertSame(
			array(),
			$fixed,
			'These ids gained an emitter (or the lookup was deleted). Drop them from `WITHOUT_EMITTER` to lock the win in.'
		);
	}

	public function test_the_core_exceptions_still_match_a_real_lookup(): void {
		// An exception that stopped matching any lookup is litter that hides the
		// next case — the same rule as `DarkModeCssTest`'s and
		// `AdminPageScopeTest`'s exceptions.
		$consumers = JsIdSelectors::consumers();

		foreach ( self::CORE_IDS as $id => $why ) {
			$this->assertArrayHasKey(
				$id,
				$consumers,
				"`#{$id}` is in the WordPress id list, but no JS looks for it any more. Drop the exception. {$why}"
			);
		}
	}

	public function test_a_selector_assembled_at_runtime_is_not_charged_an_emitter(): void {
		// `'#ffc-tabpanel-' + tab` never exists as a whole name, so demanding an
		// emitter for it would report an orphan that never existed. The same
		// limitation `CssClassEmitters` records for classes.
		$this->assertArrayNotHasKey( 'ffc-tabpanel-', JsIdSelectors::consumers() );
		$this->assertArrayNotHasKey( 'ffc-tabpanel', JsIdSelectors::consumers() );
	}
}
