<?php
/**
 * The catalogue must cover the source, and the source must justify the catalogue.
 *
 * @package FreeFormCertificate\Tests
 */

declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use FreeFormCertificate\Tests\Support\I18nCalls;
use FreeFormCertificate\Tests\Support\PoCatalogue;
use PHPUnit\Framework\TestCase;

/**
 * Source-coverage guard (#1284).
 *
 * `TranslationCatalogueAgreementTest` (#1266) proves `.po` ↔ `.mo` ↔
 * `.l10n.php` agree with EACH OTHER. That is internal consistency, and
 * `languages/` was internally consistent throughout the two releases in which
 * **79 on-screen strings had no entry at all** and rendered in English on a
 * pt_BR install (#1282). No guard could have seen it, because none compared a
 * catalogue with the source. This is that comparison.
 *
 *     source ──this guard──> .po ──#1266──> .mo
 *                             └───#1266───> .l10n.php
 *
 * TWO DIRECTIONS, AND THEY CATCH DIFFERENT THINGS
 *
 * **A -- every source string has a translated entry.** Blocks at zero. A new
 * `__()` without one fails in the PR that adds it, which is the only moment
 * anybody has the context to translate it. The entry must also be
 * TRANSLATED: an empty `msgstr` passes every agreement check and still renders
 * the English source, which is the #1282 defect wearing a different hat.
 *
 * **B -- every entry has a source site.** A shrink-only ratchet, because 22
 * entries legitimately have none. It covers what A cannot: a source string
 * whose WORDING changed leaves its old entry behind, and A is satisfied by the
 * new one. Four of the register's entries are exactly that.
 *
 * Both keyed by `(msgctxt, msgid)` through {@see PoCatalogue::key()}, the same
 * key #1266 uses, so the two guards cannot disagree about what an entry is.
 *
 * THE REGISTER IS TWO LISTS, ON PURPOSE
 *
 * {@see self::NO_CALL_SITE_POSSIBLE} is structural: the plugin header is a
 * COMMENT that WordPress parses before PHP runs, so no `__()` can ever emit
 * `Alex Meusburger` or the plugin description. Those will never leave, and
 * filing them as debt would say they should.
 *
 * {@see self::ORPHANED} is debt, and every line carries what replaced it.
 * A wrong reason is the failure mode a register has -- so each is written to be
 * checkable against the tree rather than taken on trust.
 *
 * THE TRAP THAT MAKES THIS GUARD DANGEROUS, and it already fired once
 *
 * A mis-tuned extractor freezes a wrong baseline, and a baseline is believed.
 * The first scan written for #1282 matched only `T_STRING`, missing every call
 * written `\__( … )` -- 30 strings, the whole ALTCHA block among them. Direction
 * A was unaffected (all 30 were translated), but direction B reported **52**
 * orphans instead of 22. Written then, this register would have declared 30
 * live strings dead. {@see self::test_a_fully_qualified_call_is_seen()} is the
 * canary for that shape, and {@see self::test_the_scan_reaches_the_tree()} is
 * the #1071 / #1094 rule: an empty result must never read as clean.
 *
 * WHAT IT DOES NOT SEE
 *
 * A msgid assembled at runtime, a call with no textdomain (there is one --
 * `__( 'Appointment_Receipt' )` -- and it resolves against `default`, so it is
 * deliberately not claimed), and whether a translation is correct or on the
 * right screen. #1260's rule holds: a scan sees presence, never truth.
 */
class TranslationSourceCoverageTest extends TestCase {

	/**
	 * Entries no call site can ever emit, because they are not calls.
	 *
	 * The plugin header of `ffcertificate.php` is a docblock WordPress parses
	 * itself; these are its translatable fields.
	 *
	 * @var array<string, string>
	 */
	private const NO_CALL_SITE_POSSIBLE = array(
		'Alex Meusburger'                        => 'Plugin header: Author.',
		'https://github.com/rpgmem'              => 'Plugin header: Author URI.',
		'https://github.com/rpgmem/ffcertificate' => 'Plugin header: Plugin URI and Update URI.',
		'Allows creation of dynamic forms, saves submissions, generates a PDF certificate, and enables CSV export.' => 'Plugin header: Description.',
	);

	/**
	 * Entries whose source site is gone. A ratchet: it may only shrink.
	 *
	 * Not deleted from the `.po`. Loco marks an obsolete entry `#~` rather than
	 * dropping it, and whether a string might return is a judgement separate
	 * from noticing that it is orphaned.
	 *
	 * @var array<string, string>
	 */
	private const ORPHANED = array(
		// Superseded wording -- the class direction B exists for.
		'Auto (follow plugin Dark Mode)'          => 'Superseded by "Auto (follow Dark Mode above)" when #1148 moved the setting next to Dark Mode.',
		'Applies to the Certificate HTML editor on the form edit screen. "Auto" mirrors the admin Dark Mode setting (General tab); fresh installs default to Dark.' => 'Superseded by the "…the Dark Mode setting above" wording, same move.',
		'Store generated QR Codes in database to avoid regenerating them on each request.' => 'Superseded by "Store generated certificate QR Codes…", which says which QR Code it means.',
		'sent automatically when the deadline is within N days (default 7).' => 'Superseded by the longer paragraph #1283 added to the reregistration documentation.',
		'Report-only scan for submissions wrongly linked to WordPress users: links to deleted users, one user bound to multiple CPF/RF identities, unlinked submissions whose CPF matches a linked one, and a single CPF shared across multiple users. Nothing is changed — review and fix each case manually.' => 'Superseded by the wording that also names the three cross-store checks #1313 added to the tool.',
		'Report-only scan for submissions wrongly linked to WordPress users. Nothing is changed — review each finding and fix it manually. Detection uses the stored CPF/RF hashes, so no decryption is involved.' => 'Superseded by the Migrations-tab card wording that says the scan now covers appointments, candidacies and the identity index (#1313).',
		'No link problems found. Submissions and users look consistent.' => 'Superseded by the clean-result wording that states what was checked, now that the audit is wider than submissions (#1313).',

		// The queue capability stopped covering the two verbs #1397 gave gates
		// of their own, so its description stopped saying it does.
		'Work the identity queue: correct a stored CPF/RF, split an account holding two people, merge two accounts holding one. Does not reveal a stored identifier — that stays with the PII capability.' => 'Superseded by the wording that names only what `ffc_manage_identities` still covers and says the other two are separate capabilities.',

		// The stepper's panel header says the tier once, so the column that
		// repeated it per row went with it (#1397).
		'Accounts to resolve'                     => 'Superseded by the per-tier panel headings ("One is mistyped", "Needs a decision"), which name what the old shared heading grouped.',
		'What is known'                           => 'Column header dropped: the panel header carries the tier label and its note once, where that column repeated both on every row.',

		// Seeded field labels whose text moved.
		'Emergency Contact'                       => 'Seeded label, now "Emergency Contact Name".',
		'Emergency Phone'                         => 'Seeded label, now "Emergency Contact Phone".',
		'Institutional Email'                     => 'Seeded label, now "Institutional Email (@sme)".',
		'Apt/Suite'                               => 'Seeded address label, no longer emitted.',
		'Select Division / Location'              => 'Seeded division/sector placeholder, no longer emitted.',

		// Surfaces that no longer exist.
		'All tables created successfully.'        => 'Table-creation report screen, removed.',
		'Table %s created successfully.'          => 'Table-creation report screen, removed.',
		'Failed to create table %s.'              => 'Table-creation report screen, removed.',
		'Some tables could not be created. Check details.' => 'Table-creation report screen, removed.',
		'Editor Preferences'                      => 'Code-editor section of the Advanced tab, moved to General by #1148.',
		'Appearance options for the code-based editors used inside the plugin admin.' => 'Same section, same move.',
		'Are you sure you want to cancel this appointment?' => 'Confirmation text with no remaining emitter.',
		'No submission found.'                    => 'Message with no remaining emitter.',
		'No submission found for this user.'      => 'Message with no remaining emitter.',
	);

	/**
	 * Absolute path of a catalogue.
	 *
	 * @param string $name File name under `languages/`.
	 * @return string
	 */
	private function path( string $name ): string {
		return dirname( __DIR__, 2 ) . '/languages/' . $name;
	}

	/**
	 * A msgid rendered so a failure message is readable.
	 *
	 * @param string $key Entry key, possibly carrying a `\x04` context.
	 * @return string
	 */
	private function label( string $key ): string {
		$shown = str_replace( array( "\x04", "\n" ), array( ' [ctx] ', '\n' ), $key );

		return mb_strlen( $shown ) > 90 ? mb_substr( $shown, 0, 90 ) . '…' : $shown;
	}

	/**
	 * Direction A: nothing on screen may be missing from either catalogue.
	 *
	 * The `.pot` matters as much as the `.po`: it is what a NEW locale starts
	 * from, so a string absent there is untranslatable for every future
	 * language rather than merely untranslated in this one.
	 */
	public function test_every_source_string_has_a_catalogue_entry(): void {
		$calls = I18nCalls::all();

		foreach ( array( 'ffcertificate-pt_BR.po', 'ffcertificate.pot' ) as $file ) {
			$entries = PoCatalogue::by_key( $this->path( $file ) );
			$missing = array();

			foreach ( $calls as $key => $call ) {
				if ( ! isset( $entries[ $key ] ) ) {
					$missing[] = $this->label( $key ) . '  (' . $call['refs'][0] . ')';
				}
			}

			$this->assertSame(
				array(),
				$missing,
				"These strings are on screen and absent from {$file}, so they render in English:\n  - "
					. implode( "\n  - ", $missing )
			);
		}
	}

	/**
	 * An entry that exists but is empty renders the English source.
	 *
	 * This passes every agreement check in #1266 -- `msgfmt` simply omits it
	 * from both compiled catalogues, and all three then agree that it is not
	 * there. Only a comparison against the source can see it.
	 */
	public function test_every_source_string_is_actually_translated(): void {
		$entries      = PoCatalogue::by_key( $this->path( 'ffcertificate-pt_BR.po' ) );
		$untranslated = array();

		foreach ( I18nCalls::all() as $key => $call ) {
			if ( isset( $entries[ $key ] ) && ! PoCatalogue::is_translated( $entries[ $key ] ) ) {
				$untranslated[] = $this->label( $key ) . '  (' . $call['refs'][0] . ')';
			}
		}

		$this->assertSame(
			array(),
			$untranslated,
			"These have a .po entry with an empty or fuzzy msgstr, so pt_BR still renders the English source:\n  - "
				. implode( "\n  - ", $untranslated )
		);
	}

	/**
	 * Direction B: an entry with no call site is either structural or registered.
	 *
	 * A ratchet both ways -- a new orphan fails (say what replaced it, or
	 * restore the call), and a registered one that regained a call site fails
	 * too, so a win gets locked in instead of sitting in the register as a lie.
	 *
	 * Both catalogues are read, not only the `.po`. They carry the same 22
	 * orphans today, so the union costs nothing -- but nothing else compares
	 * the two key sets with each other (#1266 covers `.po` ↔ `.mo` ↔
	 * `.l10n.php`, and the `.pot` is in none of those pairs), so a `.pot`-only
	 * orphan would otherwise be seen by no guard at all.
	 */
	public function test_every_catalogue_entry_has_a_source_site(): void {
		$calls    = I18nCalls::all();
		$expected = array_merge( self::NO_CALL_SITE_POSSIBLE, self::ORPHANED );

		$unregistered = array();
		$orphaned     = array();
		foreach ( array( 'ffcertificate-pt_BR.po', 'ffcertificate.pot' ) as $file ) {
			foreach ( PoCatalogue::by_key( $this->path( $file ) ) as $key => $entry ) {
				if ( isset( $calls[ $key ] ) ) {
					continue;
				}
				$orphaned[ $key ] = true;
				if ( ! isset( $expected[ $key ] ) ) {
					$unregistered[ $key ] = $this->label( $key ) . "  (in {$file})";
				}
			}
		}
		$unregistered = array_values( $unregistered );

		$this->assertSame(
			array(),
			$unregistered,
			"These catalogue entries have no call site and are not in the register. If a source string was reworded, "
				. "add the old text to ORPHANED naming what replaced it; if the call was lost, restore it:\n  - "
				. implode( "\n  - ", $unregistered )
		);

		$stale = array();
		foreach ( array_keys( $expected ) as $key ) {
			if ( ! isset( $orphaned[ $key ] ) ) {
				$stale[] = $this->label( $key );
			}
		}

		$this->assertSame(
			array(),
			$stale,
			"These are registered as having no call site, and now have one (or left the .po). "
				. "Drop them from the register to lock the win in:\n  - " . implode( "\n  - ", $stale )
		);
	}

	/**
	 * No catalogue may declare one entry twice.
	 *
	 * `msgfmt` rejects a duplicate definition outright, so a `.po`/`.pot`
	 * carrying one is not a file every gettext tool will accept.
	 * `ffcertificate.pot` had exactly one (the appointment-confirmation body,
	 * one block per source site instead of one block with two references) and
	 * nothing reported it -- keying the entries, as every reader here does,
	 * makes a duplicate vanish rather than fail.
	 */
	public function test_no_catalogue_declares_the_same_entry_twice(): void {
		foreach ( array( 'ffcertificate-pt_BR.po', 'ffcertificate.pot' ) as $file ) {
			$seen       = array();
			$duplicates = array();
			foreach ( PoCatalogue::read( $this->path( $file ) ) as $entry ) {
				if ( isset( $seen[ $entry['key'] ] ) ) {
					$duplicates[] = $this->label( $entry['key'] );
				}
				$seen[ $entry['key'] ] = true;
			}

			$this->assertSame(
				array(),
				$duplicates,
				"{$file} declares these twice; merge the blocks and keep both `#:` references:\n  - "
					. implode( "\n  - ", $duplicates )
			);
		}
	}

	/**
	 * The canary for the #1282 miss.
	 *
	 * `\__( 'I am not a robot', 'ffcertificate' )` in `AltchaCaptcha` is
	 * tokenized as `T_NAME_FULLY_QUALIFIED`, and a scan that knows only
	 * `T_STRING` cannot see it or the 29 other calls written that way. Pinned to
	 * a real call rather than a fixture, so it fails if that shape stops being
	 * covered AND keeps describing the tree.
	 */
	public function test_a_fully_qualified_call_is_seen(): void {
		$calls = I18nCalls::all();

		$this->assertArrayHasKey(
			'I am not a robot',
			$calls,
			'A call written `\\__( … )` is no longer seen. It tokenizes as T_NAME_FULLY_QUALIFIED, '
				. 'not T_STRING -- the #1282 miss, which reported 52 orphans instead of 22.'
		);

		$fully_qualified = 0;
		foreach ( I18nCalls::files() as $relative ) {
			$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/' . $relative );
			$fully_qualified += preg_match_all( '/\\\\(?:__|_e|_x|esc_html__|esc_attr__|esc_html_e|esc_attr_e)\(/', $source );
		}

		$this->assertGreaterThan(
			20,
			$fully_qualified,
			'Far fewer fully-qualified i18n calls than the 30 measured in #1282 -- if they were genuinely '
				. 'rewritten, lower this floor; if the regex stopped matching, the canary above is the only '
				. 'thing left guarding the shape.'
		);
	}

	/**
	 * The scan must fail when it collapses, never read as clean.
	 *
	 * The #1071 / #1094 rule. Every assertion above is satisfied by an empty
	 * scan: no calls means nothing missing, and no entries means no orphans.
	 */
	public function test_the_scan_reaches_the_tree(): void {
		$files = I18nCalls::files();
		$calls = I18nCalls::all();

		$this->assertGreaterThan( 400, count( $files ), 'The file walk no longer reaches the plugin source.' );
		$this->assertGreaterThan( 4000, count( $calls ), 'The extractor collapsed -- an empty scan must never read as clean.' );

		foreach ( array( 'ffcertificate-pt_BR.po', 'ffcertificate.pot' ) as $file ) {
			$this->assertGreaterThan(
				4000,
				count( PoCatalogue::read( $this->path( $file ) ) ),
				"The reader found almost nothing in {$file}."
			);
		}

		// A context-carrying entry, a plural and a multi-line msgid: three
		// shapes whose loss would look like clean output rather than a defect.
		$this->assertArrayHasKey( PoCatalogue::key( 'pdf filename prefix - certificate', 'certificate' ), $calls );
		$this->assertArrayHasKey( '%d hour', $calls );
		$this->assertSame( '%d hours', $calls['%d hour']['msgid_plural'] );
	}
}
