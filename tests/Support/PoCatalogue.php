<?php
/**
 * One reader for the gettext source catalogues.
 *
 * @package FreeFormCertificate\Tests
 */

declare(strict_types=1);

namespace FreeFormCertificate\Tests\Support;

/**
 * Reads a `.po` or `.pot` into entries (#1284).
 *
 * Shared for the reason `.github/scripts/ffc-create-statements.php` is shared
 * and `CssSelectors` is shared: two guards measuring the same files must not
 * disagree about what an entry IS. `TranslationCatalogueAgreementTest` (#1266)
 * compares the catalogues with each other and `TranslationSourceCoverageTest`
 * (#1284) compares them with the source; a second, independently written parser
 * is exactly the drift those precedents exist to prevent.
 *
 * WHAT A SINGLE-LINE REGEX GETS WRONG, and why this reader is a state machine:
 *
 * - **A literal continues over following lines.** `msgid ""` followed by
 *   quoted lines is one string, not an empty one. A line-wise scan of this
 *   repository's `.po` reports 2 untranslated entries where there are none,
 *   because both are multi-line and their `msgstr ""` merely opens the
 *   continuation (#1266).
 * - **A plural's value is an indexed set**, `msgstr[0]` … `msgstr[n]`.
 * - **`#,` carries the flags**, and `fuzzy` there means the entry is a
 *   suggestion rather than a translation -- `msgfmt` omits it from the `.mo`.
 * - **The context separator is `\x04`**, not a visible character, so the key
 *   has to be built rather than read.
 *
 * Entries come back as an ordered LIST, not keyed. That is deliberate: a
 * duplicate `msgid` is a real defect -- `msgfmt` rejects the file outright --
 * and keying the result would silently swallow it. `ffcertificate.pot` carries
 * one today. Use {@see self::by_key()} when the duplicate is not the question.
 */
final class PoCatalogue {

	/**
	 * The canonical entry key, shared with `TranslationCatalogueAgreementTest`.
	 *
	 * One `msgid` under two contexts is two entries, so the context belongs in
	 * the key. `\x04` is gettext's own separator.
	 *
	 * @param string|null $msgctxt Context, or null when the entry carries none.
	 * @param string      $msgid   The source string.
	 * @return string
	 */
	public static function key( ?string $msgctxt, string $msgid ): string {
		return null === $msgctxt ? $msgid : $msgctxt . "\x04" . $msgid;
	}

	/**
	 * Every entry of a `.po` / `.pot`, in file order, header excluded.
	 *
	 * @param string $path Absolute path.
	 * @return array<int, array{key: string, msgctxt: string|null, msgid: string, msgid_plural: string|null, forms: array<int, string>, fuzzy: bool}>
	 */
	public static function read( string $path ): array {
		$entries = array();
		$cur     = array();
		$field   = null;

		$flush = static function () use ( &$cur, &$entries, &$field ): void {
			if ( isset( $cur['msgid'] ) && '' !== $cur['msgid'] ) {
				$forms = array();
				if ( isset( $cur['msgid_plural'] ) ) {
					for ( $i = 0; isset( $cur[ 'msgstr[' . $i . ']' ] ); $i++ ) {
						$forms[] = $cur[ 'msgstr[' . $i . ']' ];
					}
				} else {
					$forms[] = $cur['msgstr'] ?? '';
				}

				$entries[] = array(
					'key'          => self::key( $cur['msgctxt'] ?? null, $cur['msgid'] ),
					'msgctxt'      => $cur['msgctxt'] ?? null,
					'msgid'        => $cur['msgid'],
					'msgid_plural' => $cur['msgid_plural'] ?? null,
					'forms'        => $forms,
					'fuzzy'        => (bool) ( $cur['fuzzy'] ?? false ),
				);
			}
			$cur   = array();
			$field = null;
		};

		foreach ( explode( "\n", (string) file_get_contents( $path ) ) as $line ) {
			$trimmed = trim( $line );

			if ( '' === $trimmed ) {
				$flush();
				continue;
			}
			if ( str_starts_with( $trimmed, '#' ) ) {
				if ( str_starts_with( $trimmed, '#,' ) && str_contains( $trimmed, 'fuzzy' ) ) {
					$cur['fuzzy'] = true;
				}
				continue;
			}
			if ( preg_match( '/^(msgctxt|msgid|msgid_plural|msgstr(?:\[\d+\])?)\s+"(.*)"$/s', $trimmed, $m ) ) {
				$field         = $m[1];
				$cur[ $field ] = ( $cur[ $field ] ?? '' ) . stripcslashes( $m[2] );
				continue;
			}
			// A continuation line: the literal keeps going on its own.
			if ( null !== $field && preg_match( '/^"(.*)"$/s', $trimmed, $m ) ) {
				$cur[ $field ] .= stripcslashes( $m[1] );
			}
		}
		$flush();

		return $entries;
	}

	/**
	 * The same entries keyed by `(msgctxt, msgid)`.
	 *
	 * A duplicate collapses here; {@see self::read()} is what sees it.
	 *
	 * @param string $path Absolute path.
	 * @return array<string, array{key: string, msgctxt: string|null, msgid: string, msgid_plural: string|null, forms: array<int, string>, fuzzy: bool}>
	 */
	public static function by_key( string $path ): array {
		$out = array();
		foreach ( self::read( $path ) as $entry ) {
			$out[ $entry['key'] ] = $entry;
		}

		return $out;
	}

	/**
	 * Whether an entry carries a usable translation.
	 *
	 * A `fuzzy` entry is a suggestion, and `msgfmt` leaves both it and an empty
	 * `msgstr` out of the compiled catalogue.
	 *
	 * @param array{forms: array<int, string>, fuzzy: bool} $entry Entry.
	 * @return bool
	 */
	public static function is_translated( array $entry ): bool {
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
}
