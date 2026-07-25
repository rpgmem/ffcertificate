<?php
/**
 * Locale-aware label ordering.
 *
 * Single source of truth for "alphabetical by the *translated* label"
 * ordering used across the admin UI — Settings tabs, per-module sub-tab
 * bars, field-type `<select>`s and the capability catalog. Two layers:
 *
 * - {@see LabelSorter::compare_labels()} — a stable, locale-aware string
 *   comparator. Uses `Collator` (ext-intl) for the active WordPress locale
 *   so pt-BR accents collate correctly ("Geolocalização" sorts by `g`, not
 *   after `z`); falls back to an accent-folded case-insensitive compare when
 *   ext-intl is unavailable.
 * - {@see LabelSorter::sort()} — an *anchored* alphabetical sort: a fixed
 *   head (pinned first, in the given order) and tail (pinned last) bracket a
 *   middle that is alphabetized by translated label. Array keys are
 *   preserved so callers keyed by id/slug keep their mapping.
 *
 * Introduced to standardize the one-off pattern that already lived in
 * `CapabilityCatalog::groups()` (pinned self-service section, then
 * `strcasecmp` on the label); that call site now delegates its label tie-break
 * to {@see LabelSorter::compare_labels()} so the collation rule lives here.
 *
 * @package FreeFormCertificate\Core
 * @since   6.16.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stateless helper for alphabetical-by-translated-label ordering.
 */
final class LabelSorter {

	/**
	 * Per-locale `Collator` cache. Value is a `Collator` when creation
	 * succeeded, or `false` when it was attempted and failed (so we don't
	 * retry on every comparison).
	 *
	 * @var array<string, \Collator|false>
	 */
	private static $collators = array();

	/**
	 * Latin accent → ASCII map for the ext-intl-less fallback. Covers the
	 * pt-BR set plus the common Western-European accents; anything outside
	 * it simply collates by its raw bytes, which is acceptable for a
	 * degraded fallback on hosts without ext-intl.
	 *
	 * @var array<string, string>
	 */
	private const ACCENT_MAP = array(
		'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a',
		'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
		'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
		'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
		'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
		'ç' => 'c', 'ñ' => 'n', 'ý' => 'y', 'ÿ' => 'y',
		'Á' => 'A', 'À' => 'A', 'Â' => 'A', 'Ã' => 'A', 'Ä' => 'A', 'Å' => 'A',
		'É' => 'E', 'È' => 'E', 'Ê' => 'E', 'Ë' => 'E',
		'Í' => 'I', 'Ì' => 'I', 'Î' => 'I', 'Ï' => 'I',
		'Ó' => 'O', 'Ò' => 'O', 'Ô' => 'O', 'Õ' => 'O', 'Ö' => 'O',
		'Ú' => 'U', 'Ù' => 'U', 'Û' => 'U', 'Ü' => 'U',
		'Ç' => 'C', 'Ñ' => 'N', 'Ý' => 'Y',
	);

	/**
	 * Compare two display labels for alphabetical ordering under the active
	 * locale. Returns a negative / zero / positive int, suitable as a
	 * `usort`/`uasort` comparator.
	 *
	 * @param string $a First label.
	 * @param string $b Second label.
	 * @return int Negative if `$a` sorts before `$b`, 0 if equal, positive otherwise.
	 */
	public static function compare_labels( string $a, string $b ): int {
		$collator = self::collator();
		if ( null !== $collator ) {
			$result = $collator->compare( $a, $b );
			if ( false !== $result ) {
				return $result;
			}
		}

		return self::compare_ascii( $a, $b );
	}

	/**
	 * Locale-independent, accent-folded, case-insensitive comparison — the
	 * fallback used when ext-intl is unavailable, and directly useful when a
	 * caller wants deterministic ordering that does not depend on the host's
	 * ICU data.
	 *
	 * @param string $a First label.
	 * @param string $b Second label.
	 * @return int Negative / zero / positive, like `strcmp`.
	 */
	public static function compare_ascii( string $a, string $b ): int {
		return strcmp( self::fold( $a ), self::fold( $b ) );
	}

	/**
	 * Anchored alphabetical sort.
	 *
	 * Items whose identifier appears in `$head` are emitted first in the
	 * exact order given; items whose identifier appears in `$tail` are
	 * emitted last in the exact order given; everything else is sorted
	 * alphabetically by translated label in between. Original array keys are
	 * preserved.
	 *
	 * An item's identifier is `$key_of( $item, $key )` when a resolver is
	 * supplied, otherwise the item's array key — so a map already keyed by
	 * slug/id needs no resolver.
	 *
	 * @template T
	 * @param array<array-key, T>                   $items    Items to order (assoc or list).
	 * @param callable(T): string                   $label_of `fn( $item ): string` — the translated label.
	 * @param list<string>                          $head     Identifiers pinned first, in order.
	 * @param list<string>                          $tail     Identifiers pinned last, in order.
	 * @param (callable(T, array-key): string)|null $key_of   `fn( $item, $key ): string` identifier resolver.
	 * @return array<array-key, T> Reordered items, keys preserved.
	 */
	public static function sort( array $items, callable $label_of, array $head = array(), array $tail = array(), ?callable $key_of = null ): array {
		$head_set = array_flip( $head );
		$tail_set = array_flip( $tail );

		$head_bucket = array();
		$tail_bucket = array();
		$middle      = array();

		foreach ( $items as $key => $item ) {
			$id = null !== $key_of ? (string) $key_of( $item, $key ) : (string) $key;
			if ( isset( $head_set[ $id ] ) ) {
				$head_bucket[ $id ] = array( $key, $item );
			} elseif ( isset( $tail_set[ $id ] ) ) {
				$tail_bucket[ $id ] = array( $key, $item );
			} else {
				$middle[ $key ] = $item;
			}
		}

		// Stable on PHP 8.0+ (the project's floor): equal labels keep their
		// original relative order.
		uasort(
			$middle,
			static function ( $a, $b ) use ( $label_of ): int {
				return self::compare_labels( (string) $label_of( $a ), (string) $label_of( $b ) );
			}
		);

		$out = array();
		foreach ( $head as $id ) {
			if ( isset( $head_bucket[ $id ] ) ) {
				list( $key, $item ) = $head_bucket[ $id ];
				$out[ $key ]        = $item;
			}
		}
		foreach ( $middle as $key => $item ) {
			$out[ $key ] = $item;
		}
		foreach ( $tail as $id ) {
			if ( isset( $tail_bucket[ $id ] ) ) {
				list( $key, $item ) = $tail_bucket[ $id ];
				$out[ $key ]        = $item;
			}
		}

		return $out;
	}

	/**
	 * Resolve (and memoize) the `Collator` for the active WordPress locale.
	 *
	 * @return \Collator|null The collator, or null when ext-intl is
	 *                        unavailable or the locale is unsupported.
	 */
	private static function collator(): ?\Collator {
		if ( ! class_exists( '\Collator' ) ) {
			return null;
		}

		$locale = self::locale();
		if ( ! array_key_exists( $locale, self::$collators ) ) {
			$collator = \Collator::create( $locale );
			if ( $collator instanceof \Collator ) {
				// SECONDARY: order by base letter, treat accents as an
				// adjacent (secondary) difference, ignore case — so "cache"
				// and "Cache" tie and "Água" sorts by its base "a", matching
				// the accent-folded, case-insensitive ASCII fallback.
				$collator->setStrength( \Collator::SECONDARY );
			}
			self::$collators[ $locale ] = $collator instanceof \Collator ? $collator : false;
		}

		$cached = self::$collators[ $locale ];
		return $cached instanceof \Collator ? $cached : null;
	}

	/**
	 * Active WordPress locale, e.g. `pt_BR`. Falls back to `en_US` outside a
	 * WordPress runtime (defensive — mirrors the `function_exists` guards
	 * elsewhere in Core).
	 *
	 * @return string
	 */
	private static function locale(): string {
		if ( function_exists( 'get_locale' ) ) {
			$locale = get_locale();
			if ( is_string( $locale ) && '' !== $locale ) {
				return $locale;
			}
		}

		return 'en_US';
	}

	/**
	 * Accent-fold + lowercase a string for the ext-intl-less fallback so a
	 * case/diacritic-insensitive byte compare approximates locale collation.
	 *
	 * @param string $value Raw label.
	 * @return string Folded, lowercased form.
	 */
	private static function fold( string $value ): string {
		$value = strtr( $value, self::ACCENT_MAP );
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $value, 'UTF-8' ) : strtolower( $value );
	}
}
