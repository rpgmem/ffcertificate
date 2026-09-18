<?php
/**
 * The three translation catalogues must say the same thing.
 *
 * @package FreeFormCertificate\Tests
 */

declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use FreeFormCertificate\Tests\Support\PoCatalogue;
use PHPUnit\Framework\TestCase;

/**
 * Catalogue-agreement guard (#1266).
 *
 * `languages/` ships one translation in three formats and nothing checked that
 * they agree. They had already disagreed **in production, for two releases**:
 * the `.po` and `.l10n.php` carried #1209's fix (`Union` → `Sindicato`,
 * `Acknowledgment` → `Termo de Ciência`) while the shipped `.mo` still said
 * `Estado` and `Agradecimentos`, and six further entries the `.po` carried were
 * absent from it entirely (#1265).
 *
 * WordPress reads `.l10n.php` only from **6.5**, and this plugin's declared
 * floor is **6.4**, where it falls back to the `.mo`. So on the floor "Union"
 * still collided with the address state -- the exact defect #1209 existed to
 * fix, never delivered there. The `.po` and `.l10n.php` were regenerated; the
 * `.mo` was not; nothing reported it.
 *
 * WHAT IS COMPARED
 *
 * Both directions, all three files: every **translated** `.po` entry must
 * appear with the same value in each compiled catalogue, and neither compiled
 * catalogue may carry an entry the `.po` does not. An entry is keyed by
 * `(msgctxt, msgid)`, because one `msgid` under two contexts is two entries.
 *
 * PARSE THE FILE WITH ITS OWN LANGUAGE WHERE YOU CAN
 *
 * `.l10n.php` is read by **including it** -- it is a side-effect-free
 * `return array( … )`. That is not a shortcut: it removes two of the traps
 * below outright, because PHP is the authority on how PHP escapes a string.
 * (Contrast `.github/scripts/ffc-uninstall-manifest.php`, which parses
 * `uninstall.php` as TEXT precisely because including that one would run the
 * uninstaller. The rule is not "never include" -- it is "include when the file
 * does nothing".)
 *
 * The `.po` reader's own unescaping is checked by the comparison rather than
 * asserted: were `stripcslashes()` wrong for some sequence, that entry would
 * stop matching the `.mo`, which is the compiled ground truth.
 *
 * SIX TRAPS, EVERY ONE MEASURED
 *
 * The issue lists five; the sixth was found while writing this and is the one
 * that would have reported 20 false divergences on the first run.
 *
 * 1. **PHP writes a numeric-string key as an INT.** `.l10n.php` carries
 *    `302=>'302'` and `6=>'6'` with no quotes on the key, so a regex requiring
 *    `'…'=>` misses both. Including the file makes them `int` keys, which is
 *    why every key is cast back to `string` here.
 * 2. **The context separator is `\x04`**, not a visible character.
 * 3. **Quote parity** -- the trap `CssSelectors` and `CssClassEmitters` already
 *    record. Moot for `.l10n.php` now that PHP parses it; still live for the
 *    `.po`, whose reader consumes whole literals line by line.
 * 4. **`msgfmt` omits untranslated entries.** A `.po` entry with an empty
 *    `msgstr` is legitimately absent from the `.mo`, so those are skipped --
 *    see {@see self::translated()}. Today every one of the entries is
 *    translated and none is fuzzy, but that is a property of this file, not of
 *    the format.
 * 5. **20 entries carry a plural.** Their value is an indexed set, so comparing
 *    a single `msgstr` would silently pass a plural whose forms disagree.
 * 6. **The two compiled formats key a plural DIFFERENTLY.** The `.mo` keys it
 *    as `msgid \0 msgid_plural`; `.l10n.php` keys it by `msgid` alone. Keying
 *    naively reports all 20 as divergent. The canonical key here is
 *    `(msgctxt, msgid)` for all three, so `msgid_plural` itself is only
 *    comparable between the `.po` and the `.mo` -- `.l10n.php` does not carry
 *    it, and no guard can invent it.
 *
 * WHAT IT DOES NOT CHECK, DELIBERATELY
 *
 * That the `.pot` covers every `__()` in the code -- that is source coverage,
 * not catalogue agreement, and needs `makepot` rather than a comparison. Nor
 * translation quality, nor grammar: #1260's lesson holds, a scan sees presence
 * and never truth.
 */
class TranslationCatalogueAgreementTest extends TestCase {

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
	 * Reads the `.po` into `key => array{forms, plural_id, fuzzy}`.
	 *
	 * The parsing lives in {@see PoCatalogue}, shared with
	 * `TranslationSourceCoverageTest` (#1284) for the reason
	 * `.github/scripts/ffc-create-statements.php` is shared: two guards reading
	 * the same file must not disagree about what an entry is. What stays here is
	 * only the shape this comparison wants.
	 *
	 * @return array<string, array{forms: array<int, string>, plural_id: string|null, fuzzy: bool}>
	 */
	private function po(): array {
		$entries = array();
		foreach ( PoCatalogue::read( $this->path( 'ffcertificate-pt_BR.po' ) ) as $entry ) {
			$entries[ $entry['key'] ] = array(
				'forms'     => $entry['forms'],
				'plural_id' => $entry['msgid_plural'],
				'fuzzy'     => $entry['fuzzy'],
			);
		}

		return $entries;
	}

	/**
	 * Reads the `.mo` into `key => array{forms, plural_id}`.
	 *
	 * The two tables are all WordPress itself reads, so the hash table is
	 * ignored here too.
	 *
	 * @return array<string, array{forms: array<int, string>, plural_id: string|null}>
	 */
	private function mo(): array {
		$blob = (string) file_get_contents( $this->path( 'ffcertificate-pt_BR.mo' ) );

		$magic = unpack( 'V', substr( $blob, 0, 4 ) );
		$this->assertIsArray( $magic );
		$this->assertSame( 0x950412de, $magic[1], 'Unexpected .mo magic -- the file is not little-endian gettext.' );

		$head = unpack( 'Vrev/Vcount/Voffset_orig/Voffset_trans', substr( $blob, 4, 16 ) );
		$this->assertIsArray( $head );

		$entries = array();
		for ( $i = 0; $i < $head['count']; $i++ ) {
			$ko = unpack( 'Vlen/Voff', substr( $blob, (int) $head['offset_orig'] + $i * 8, 8 ) );
			$vo = unpack( 'Vlen/Voff', substr( $blob, (int) $head['offset_trans'] + $i * 8, 8 ) );
			$this->assertIsArray( $ko );
			$this->assertIsArray( $vo );

			$key   = substr( $blob, (int) $ko['off'], (int) $ko['len'] );
			$value = substr( $blob, (int) $vo['off'], (int) $vo['len'] );

			if ( '' === $key ) {
				continue; // The header entry.
			}

			// Trap 6: a plural's key is `msgid \0 msgid_plural`.
			$plural_id = null;
			if ( str_contains( $key, "\0" ) ) {
				[ $key, $plural_id ] = explode( "\0", $key, 2 );
			}

			$entries[ $key ] = array(
				'forms'     => explode( "\0", $value ),
				'plural_id' => $plural_id,
			);
		}

		return $entries;
	}

	/**
	 * Reads `.l10n.php` by including it.
	 *
	 * @return array<string, array<int, string>> key => plural forms.
	 */
	private function l10n(): array {
		/** @var array{messages?: array<array-key, string>} $data */
		$data = include $this->path( 'ffcertificate-pt_BR.l10n.php' );

		$this->assertIsArray( $data );
		$this->assertArrayHasKey( 'messages', $data, 'The .l10n.php has no `messages` array -- the format changed.' );

		$entries = array();
		foreach ( $data['messages'] as $key => $value ) {
			// Trap 1: PHP turned `'302'` into int 302 on the way in.
			$entries[ (string) $key ] = explode( "\0", (string) $value );
		}

		return $entries;
	}

	/**
	 * The `.po` entries a compiled catalogue is expected to carry.
	 *
	 * Trap 4: `msgfmt` drops an untranslated entry, so its absence downstream is
	 * correct rather than drift. A fuzzy entry is dropped for the same reason.
	 *
	 * @param array<string, array{forms: array<int, string>, plural_id: string|null, fuzzy: bool}> $po Parsed `.po`.
	 * @return array<string, array{forms: array<int, string>, plural_id: string|null, fuzzy: bool}>
	 */
	private function translated( array $po ): array {
		return array_filter(
			$po,
			static function ( array $entry ): bool {
				if ( $entry['fuzzy'] ) {
					return false;
				}
				foreach ( $entry['forms'] as $form ) {
					if ( '' !== $form ) {
						return true;
					}
				}

				return false;
			}
		);
	}

	/**
	 * `json_encode` for failure messages only.
	 *
	 * Not `wp_json_encode()`: it is undefined in this suite, and stubbing it
	 * would teach Patchwork a function every later test then inherits -- the
	 * order dependence `CLAUDE.md` records.
	 *
	 * @param array<int, string> $value Plural forms.
	 * @return string
	 */
	private function forms( array $value ): string {
		return (string) json_encode( $value, JSON_UNESCAPED_UNICODE );
	}

	/**
	 * Renders a key for a failure message, with the separator made visible.
	 *
	 * @param string $key Catalogue key.
	 * @return string
	 */
	private function label( string $key ): string {
		$key = str_replace( "\x04", ' ⟨ctx⟩ ', $key );
		$key = str_replace( "\n", '\n', $key );

		return mb_strlen( $key ) > 90 ? mb_substr( $key, 0, 90 ) . '…' : $key;
	}

	/**
	 * Every translated `.po` entry reaches both compiled catalogues, unchanged.
	 *
	 * This is the direction that failed in production: the `.po` carried
	 * #1209's fix and the `.mo` did not.
	 *
	 * @return void
	 */
	public function test_every_translated_po_entry_is_in_both_compiled_catalogues(): void {
		$po    = $this->translated( $this->po() );
		$mo    = $this->mo();
		$l10n  = $this->l10n();
		$drift = array();

		foreach ( $po as $key => $entry ) {
			if ( ! isset( $mo[ $key ] ) ) {
				$drift[] = 'missing from .mo: ' . $this->label( $key );
			} elseif ( $mo[ $key ]['forms'] !== $entry['forms'] ) {
				$drift[] = sprintf(
					'.mo disagrees on "%s": po=%s mo=%s',
					$this->label( $key ),
					$this->forms( $entry['forms'] ),
					$this->forms( $mo[ $key ]['forms'] )
				);
			}

			if ( ! isset( $l10n[ $key ] ) ) {
				$drift[] = 'missing from .l10n.php: ' . $this->label( $key );
			} elseif ( $l10n[ $key ] !== $entry['forms'] ) {
				$drift[] = sprintf(
					'.l10n.php disagrees on "%s": po=%s l10n=%s',
					$this->label( $key ),
					$this->forms( $entry['forms'] ),
					$this->forms( $l10n[ $key ] )
				);
			}
		}

		$this->assertSame(
			array(),
			$drift,
			"A compiled catalogue disagrees with the `.po`. Recompile both from it:\n"
				. "  msgfmt -o languages/ffcertificate-pt_BR.mo languages/ffcertificate-pt_BR.po\n"
				. "and regenerate the `.l10n.php`. WordPress reads `.l10n.php` from 6.5 and the `.mo`\n"
				. "on this plugin's 6.4 floor, so a divergence means the string depends on the WP version.\n"
				. implode( "\n", $drift )
		);
	}

	/**
	 * Neither compiled catalogue carries an entry the `.po` does not.
	 *
	 * The other direction, and the one that catches a stale compiled file
	 * holding a string the source has since dropped.
	 *
	 * @return void
	 */
	public function test_neither_compiled_catalogue_carries_an_entry_the_po_does_not(): void {
		$po      = $this->po();
		$orphans = array();

		foreach ( array_keys( $this->mo() ) as $key ) {
			if ( ! isset( $po[ $key ] ) ) {
				$orphans[] = '.mo carries an entry the .po does not: ' . $this->label( $key );
			}
		}
		foreach ( array_keys( $this->l10n() ) as $key ) {
			if ( ! isset( $po[ $key ] ) ) {
				$orphans[] = '.l10n.php carries an entry the .po does not: ' . $this->label( $key );
			}
		}

		$this->assertSame(
			array(),
			$orphans,
			"A compiled catalogue holds a string the `.po` no longer has -- it was compiled from an\n"
				. "older source. Recompile both from the `.po`:\n" . implode( "\n", $orphans )
		);
	}

	/**
	 * Plural entries agree form by form, and on the plural msgid.
	 *
	 * Trap 5: comparing one `msgstr` passes a plural whose second form has
	 * drifted. Trap 6: `.l10n.php` does not carry `msgid_plural` at all, so that
	 * half is `.po` ↔ `.mo` only -- stated rather than silently skipped.
	 *
	 * @return void
	 */
	public function test_plural_entries_agree_form_by_form(): void {
		$po     = $this->translated( $this->po() );
		$mo     = $this->mo();
		$l10n   = $this->l10n();
		$drift  = array();
		$checked = 0;

		foreach ( $po as $key => $entry ) {
			if ( null === $entry['plural_id'] ) {
				continue;
			}
			++$checked;

			$forms = count( $entry['forms'] );
			if ( $forms < 2 ) {
				$drift[] = sprintf( '"%s" declares a plural but carries %d form(s) in the .po.', $this->label( $key ), $forms );
			}
			if ( isset( $mo[ $key ] ) && $mo[ $key ]['plural_id'] !== $entry['plural_id'] ) {
				$drift[] = sprintf( '"%s": the .mo keys a different msgid_plural.', $this->label( $key ) );
			}
			if ( isset( $mo[ $key ] ) && count( $mo[ $key ]['forms'] ) !== $forms ) {
				$drift[] = sprintf( '"%s": the .mo carries %d forms, the .po %d.', $this->label( $key ), count( $mo[ $key ]['forms'] ), $forms );
			}
			if ( isset( $l10n[ $key ] ) && count( $l10n[ $key ] ) !== $forms ) {
				$drift[] = sprintf( '"%s": the .l10n.php carries %d forms, the .po %d.', $this->label( $key ), count( $l10n[ $key ] ), $forms );
			}
		}

		$this->assertGreaterThan(
			0,
			$checked,
			'No plural entry was examined -- the plural scan collapsed rather than finding nothing to say.'
		);

		$this->assertSame(
			array(),
			$drift,
			"A plural entry disagrees across the catalogues. The forms are an indexed set, so a single\n"
				. "`msgstr` comparison would have passed this:\n" . implode( "\n", $drift )
		);
	}

	/**
	 * The scan reaches all three catalogues.
	 *
	 * An empty result must never read as clean -- the #1071 / #1094 rule, and
	 * the one a two-way comparison needs most: parse nothing on both sides and
	 * every assertion above passes.
	 *
	 * @return void
	 */
	public function test_the_scan_reaches_all_three_catalogues(): void {
		$po   = $this->po();
		$mo   = $this->mo();
		$l10n = $this->l10n();

		foreach ( array( '.po' => $po, '.mo' => $mo, '.l10n.php' => $l10n ) as $name => $parsed ) {
			$this->assertGreaterThan( 4000, count( $parsed ), "The {$name} parser returned almost nothing -- it is broken, not clean." );
		}

		// A context entry, a plural and an entry PHP turned into an int key:
		// the three shapes whose parsing traps are recorded in the class
		// docblock. Each one present proves that trap is handled.
		$this->assertArrayHasKey(
			"pdf filename prefix - certificate\x04certificate",
			$po,
			'The .po parser lost the msgctxt entries (trap 2).'
		);
		$this->assertArrayHasKey(
			"pdf filename prefix - certificate\x04certificate",
			$l10n,
			'The .l10n.php parser lost the msgctxt entries (trap 2).'
		);
		$this->assertArrayHasKey( '302', $l10n, "The .l10n.php parser lost PHP's unquoted integer keys (trap 1)." );

		$plurals = array_filter( $po, static fn ( array $e ): bool => null !== $e['plural_id'] );
		$this->assertGreaterThan( 0, count( $plurals ), 'The .po parser lost every plural (trap 5).' );

		$multiline = array_filter( array_keys( $po ), static fn ( string $k ): bool => str_contains( $k, "\n" ) );
		$this->assertGreaterThan(
			0,
			count( $multiline ),
			'The .po parser lost the entry whose msgid spans lines -- the continuation handling is broken (trap 3).'
		);
	}
}
