<?php
/**
 * Turns a `readme.txt` and a release body into the "View details" tabs.
 *
 * @package FreeFormCertificate\Integrations
 * @since   6.39.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Integrations;

/**
 * Builds the `sections` WordPress shows as tabs in the "View details" modal.
 *
 * WordPress.org renders a plugin's readme into these tabs on its own servers;
 * a plugin that updates from GitHub gets an empty modal unless it does the
 * same. This class does the minimum the two sources need: the readme's
 * `== Section ==` / `= Heading =` structure, and the Markdown a release body
 * is written in (`###` headings, `-` lists, `**bold**`, `` `code` ``, links).
 * Anything else stays plain text.
 *
 * It returns HTML built from escaped text and a fixed set of tags; the caller
 * still passes it through `wp_kses_post()`, and WordPress filters the modal
 * again with its own allow-list, so a readme cannot inject markup.
 *
 * Pure PHP, no WordPress calls, so it is testable on its own. The same class
 * serves the other three plugins' updaters.
 *
 * @since 6.39.0
 */
final class ReadmeSections {

	/**
	 * Readme sections with a tab of their own, in WordPress's key names.
	 *
	 * The changelog is not here on purpose: the release body is the changelog
	 * of the version being offered, while the readme's section is a pointer.
	 */
	private const TABS = array(
		'description'                => 'description',
		'installation'               => 'installation',
		'frequently asked questions' => 'faq',
	);

	/**
	 * Readme sections that never become a tab.
	 */
	private const SKIPPED = array( 'changelog', 'upgrade notice', 'screenshots' );

	/**
	 * Every tab built from a readme, in WordPress.org's order.
	 *
	 * Sections without a tab of their own (Privacy, Usage, Settings…) are
	 * gathered under "Other notes", each with its heading, rather than dropped.
	 *
	 * @param string             $readme      Full `readme.txt` text.
	 * @param string             $repo        `owner/repo`, to link `#123` to its issue.
	 * @param array<int, string> $screenshots Image URLs, the first one for caption 1.
	 * @return array<string, string> WordPress section key => HTML.
	 */
	public static function fromReadme( string $readme, string $repo, array $screenshots ): array {
		$tabs  = array();
		$notes = '';

		foreach ( self::split( $readme ) as $title => $body ) {
			$key = strtolower( $title );
			if ( isset( self::TABS[ $key ] ) ) {
				$tabs[ self::TABS[ $key ] ] = self::toHtml( $body, $repo );
			} elseif ( 'screenshots' === $key ) {
				$tabs['screenshots'] = self::screenshots( $body, $screenshots );
			} elseif ( ! in_array( $key, self::SKIPPED, true ) ) {
				$notes .= '<h3>' . self::escape( $title ) . '</h3>' . self::toHtml( $body, $repo );
			}
		}

		if ( '' !== $notes ) {
			$tabs['other_notes'] = $notes;
		}

		return array_filter( $tabs, static fn ( string $html ): bool => '' !== $html );
	}

	/**
	 * Split a readme into its `== Section ==` bodies, keyed by heading.
	 *
	 * Exactly two signs on each side: the plugin's own `=== Name ===` title
	 * line is a header, not a section.
	 *
	 * @param string $readme Full `readme.txt` text.
	 * @return array<string, string>
	 */
	public static function split( string $readme ): array {
		$parts = preg_split( '/^==(?!=)\s*(.+?)\s*(?<!=)==\s*$/m', $readme, -1, PREG_SPLIT_DELIM_CAPTURE );
		if ( ! is_array( $parts ) ) {
			return array();
		}

		$out   = array();
		$count = count( $parts );
		for ( $i = 1; $i + 1 < $count; $i += 2 ) {
			$out[ trim( $parts[ $i ] ) ] = trim( $parts[ $i + 1 ] );
		}
		return $out;
	}

	/**
	 * The Screenshots tab: each numbered caption beside its image.
	 *
	 * A caption with no image is shown alone, and prose before the list is
	 * dropped, since it describes the repository rather than the screens.
	 *
	 * @param string             $body        The section's text.
	 * @param array<int, string> $screenshots Image URLs, the first one for caption 1.
	 */
	public static function screenshots( string $body, array $screenshots ): string {
		if ( 0 === preg_match_all( '/^\s*(\d+)\.\s+(.+)$/m', $body, $matches, PREG_SET_ORDER ) ) {
			return '';
		}

		$html = '<ol>';
		foreach ( $matches as $match ) {
			$caption = self::escape( trim( $match[2] ) );
			$url     = $screenshots[ (int) $match[1] - 1 ] ?? '';
			$html   .= '<li>';
			if ( '' !== $url ) {
				$src   = self::escape( $url );
				$html .= '<a href="' . $src . '"><img src="' . $src . '" alt="' . $caption . '" /></a>';
			}
			$html .= '<p>' . $caption . '</p></li>';
		}
		return $html . '</ol>';
	}

	/**
	 * Render readme or Markdown text as HTML.
	 *
	 * Blocks: `#`–`######` and `= Heading =` become headings, `-`/`*`/`+` and
	 * `1.` lines become lists (an indented line continues the item above),
	 * and everything else becomes paragraphs split on blank lines.
	 *
	 * @param string $text Source text.
	 * @param string $repo `owner/repo`, to link `#123` to its issue; empty for none.
	 */
	public static function toHtml( string $text, string $repo = '' ): string {
		$html = '';
		foreach ( self::blocks( $text ) as $block ) {
			if ( 'h' === $block['type'] ) {
				$html .= '<h4>' . self::inline( $block['lines'][0], $repo ) . '</h4>';
			} elseif ( 'p' === $block['type'] ) {
				$html .= '<p>' . self::inline( implode( ' ', $block['lines'] ), $repo ) . '</p>';
			} else {
				$html .= '<' . $block['type'] . '>';
				foreach ( $block['lines'] as $item ) {
					$html .= '<li>' . self::inline( $item, $repo ) . '</li>';
				}
				$html .= '</' . $block['type'] . '>';
			}
		}
		return $html;
	}

	/**
	 * Group source lines into blocks: a heading, a paragraph, or a list.
	 *
	 * @param string $text Source text.
	 * @return array<int, array{type: string, lines: array<int, string>}>
	 */
	private static function blocks( string $text ): array {
		$blocks = array();
		$open   = false; // Whether the last block may still take lines.

		foreach ( preg_split( '/\R/', $text ) ?: array() as $line ) { // phpcs:ignore Universal.Operators.DisallowShortTernary.Found -- preg_split() returns false only on a malformed pattern, which this literal is not.
			if ( '' === trim( $line ) ) {
				$open = false;
				continue;
			}

			if ( 1 === preg_match( '/^\s*#{1,6}\s+(.+?)\s*#*\s*$/', $line, $m ) || 1 === preg_match( '/^\s*=\s*(.+?)\s*=\s*$/', $line, $m ) ) {
				$blocks[] = array(
					'type'  => 'h',
					'lines' => array( $m[1] ),
				);
				$open     = false;
				continue;
			}

			$type = '';
			if ( 1 === preg_match( '/^\s*[-*+]\s+(.*)$/', $line, $m ) ) {
				$type = 'ul';
			} elseif ( 1 === preg_match( '/^\s*\d+[.)]\s+(.*)$/', $line, $m ) ) {
				$type = 'ol';
			}

			$last = count( $blocks ) - 1;
			if ( '' !== $type ) {
				if ( $open && $blocks[ $last ]['type'] === $type ) {
					$blocks[ $last ]['lines'][] = $m[1];
				} else {
					$blocks[] = array(
						'type'  => $type,
						'lines' => array( $m[1] ),
					);
				}
				$open = true;
				continue;
			}

			$in_list = $open && in_array( $blocks[ $last ]['type'], array( 'ul', 'ol' ), true );
			if ( $in_list && 1 === preg_match( '/^\s{2,}\S/', $line ) ) {
				$item                               = count( $blocks[ $last ]['lines'] ) - 1;
				$blocks[ $last ]['lines'][ $item ] .= ' ' . trim( $line );
				continue;
			}

			if ( $open && 'p' === $blocks[ $last ]['type'] ) {
				$blocks[ $last ]['lines'][] = trim( $line );
			} else {
				$blocks[] = array(
					'type'  => 'p',
					'lines' => array( trim( $line ) ),
				);
			}
			$open = true;
		}

		return $blocks;
	}

	/**
	 * Inline Markdown on one escaped line: code, links, bold and issue refs.
	 *
	 * Code spans are set aside first, so nothing inside them is formatted.
	 *
	 * @param string $text Raw text.
	 * @param string $repo `owner/repo`, to link `#123`; empty for none.
	 */
	private static function inline( string $text, string $repo ): string {
		$code = array();
		$text = (string) preg_replace_callback(
			'/`([^`]+)`/',
			static function ( array $m ) use ( &$code ): string {
				$code[] = '<code>' . self::escape( $m[1] ) . '</code>';
				return "\x00" . ( count( $code ) - 1 ) . "\x00";
			},
			$text
		);

		$text = self::escape( $text );
		$text = (string) preg_replace( '/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/', '<a href="$2">$1</a>', $text );
		$text = (string) preg_replace( '/\*\*(.+?)\*\*/', '<strong>$1</strong>', $text );
		if ( '' !== $repo ) {
			$text = (string) preg_replace( '/(^|[\s(])#(\d+)\b/', '$1<a href="https://github.com/' . self::escape( $repo ) . '/issues/$2">#$2</a>', $text );
		}

		return (string) preg_replace_callback( "/\x00(\d+)\x00/", static fn ( array $m ): string => $code[ (int) $m[1] ], $text );
	}

	/**
	 * Escape text for HTML, quotes included.
	 *
	 * @param string $text Raw text.
	 */
	private static function escape( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES | ENT_HTML401, 'UTF-8' );
	}
}
