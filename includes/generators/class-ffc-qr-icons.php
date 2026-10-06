<?php
/**
 * Inline SVG icons for the QR screens.
 *
 * @package FreeFormCertificate\Generators
 * @since   6.34.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Generators;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The plugin's own icon set for the QR generator and design (#1570).
 *
 * Drawn here rather than vendored: an icon bundle in `libs/js/` is exactly
 * the fifth-bundle trigger recorded in #1208, and dashicons has no Wi-Fi or
 * contact-card glyph. Every icon is a 24x24 stroke drawing in
 * `currentColor`, so it takes the text colour of wherever it sits and follows
 * the dark theme with no colour of its own.
 */
final class QrIcons {

	/**
	 * Inner markup of each icon, in a 24x24 box.
	 *
	 * @var array<string, string>
	 */
	private const PATHS = array(
		// Content types.
		'url'      => '<path d="M9 16H7a4 4 0 0 1 0-8h2M15 8h2a4 4 0 0 1 0 8h-2M8 12h8"/>',
		'text'     => '<path d="M4 6h16M4 10h16M4 14h16M4 18h10"/>',
		'wifi'     => '<path d="M2.5 9a14 14 0 0 1 19 0M5.5 12.5a9.5 9.5 0 0 1 13 0M8.8 16a5 5 0 0 1 6.4 0"/><circle cx="12" cy="19.5" r="1.2" fill="currentColor" stroke="none"/>',
		'email'    => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3.5 7l8.5 6 8.5-6"/>',
		'phone'    => '<path d="M5 4h3.5l1.8 4.6-2.3 1.5a11 11 0 0 0 5.9 5.9l1.5-2.3L20 15.5V19a2 2 0 0 1-2 2A15 15 0 0 1 3 6a2 2 0 0 1 2-2z"/>',
		'sms'      => '<path d="M4 5h16v11H9.5L5 20v-4H4z"/><path d="M8 9.5h8M8 12.5h5"/>',
		'whatsapp' => '<path d="M4 20l1.3-4A8.5 8.5 0 1 1 8.4 19z"/><path d="M9.3 8.4l1.2-.3.9 2-.8.9a5 5 0 0 0 2.4 2.4l.9-.8 2 .9-.3 1.2c-3 .2-6.5-3.3-6.3-6.3z"/>',
		'vcard'    => '<rect x="2.5" y="5" width="19" height="14" rx="2"/><circle cx="8.5" cy="10.5" r="2"/><path d="M5.5 16a3 3 0 0 1 6 0M14.5 10h4.5M14.5 14h3.5"/>',
		'social'   => '<circle cx="18" cy="5.5" r="2.5"/><circle cx="6" cy="12" r="2.5"/><circle cx="18" cy="18.5" r="2.5"/><path d="M8.2 10.8l7.6-4.1M8.2 13.2l7.6 4.1"/>',
		'event'    => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/>',

		// Design sections.
		'pattern'  => '<path d="M4 4h4v4H4zM10 4h4v4h-4zM16 10h4v4h-4zM4 16h4v4H4zM10 10h4v4h-4zM16 16h4v4h-4z"/>',
		'eyes'     => '<rect x="3" y="3" width="8" height="8" rx="1"/><rect x="13" y="3" width="8" height="8" rx="1"/><rect x="3" y="13" width="8" height="8" rx="1"/><path d="M6 6h2v2H6zM16 6h2v2h-2zM6 16h2v2H6z" fill="currentColor"/>',
		'colors'   => '<path d="M12 3s6 6.5 6 11a6 6 0 0 1-12 0c0-4.5 6-11 6-11z"/>',
		'logo'     => '<rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="9" cy="10" r="2"/><path d="M21 16l-5-5-9 9"/>',
		'frame'    => '<rect x="4" y="3" width="16" height="18" rx="2"/><rect x="7" y="6" width="10" height="9"/><path d="M9 18h6"/>',
		'advanced' => '<path d="M4 6h9M17 6h3M4 12h3M11 12h9M4 18h11M19 18h1"/><circle cx="15" cy="6" r="2"/><circle cx="9" cy="12" r="2"/><circle cx="17" cy="18" r="2"/>',

		// Actions.
		'download' => '<path d="M12 4v11M7 10l5 5 5-5M5 20h14"/>',
		'print'    => '<path d="M7 9V3h10v6"/><rect x="3" y="9" width="18" height="8" rx="2"/><path d="M7 14h10v7H7z"/>',
		'none'     => '<circle cx="12" cy="12" r="8"/><path d="M6.5 17.5l11-11"/>',
	);

	/**
	 * Whether an icon exists.
	 *
	 * @param string $name Icon name.
	 * @return bool
	 */
	public static function has( string $name ): bool {
		return isset( self::PATHS[ $name ] );
	}

	/**
	 * The names of every icon.
	 *
	 * @return array<int, string>
	 */
	public static function names(): array {
		return array_keys( self::PATHS );
	}

	/**
	 * An icon as an inline SVG element, hidden from assistive technology:
	 * every place it is used carries a visible label beside it.
	 *
	 * Built only from the constant drawings above and an integer, so the
	 * markup needs no escaping; an unknown name returns ''.
	 *
	 * @param string $name Icon name.
	 * @param int    $size Width and height, in px.
	 * @return string
	 */
	public static function svg( string $name, int $size = 20 ): string {
		if ( ! self::has( $name ) ) {
			return '';
		}

		return sprintf(
			'<svg class="ffc-qr-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="%1$d" height="%1$d" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">%2$s</svg>',
			max( 1, $size ),
			self::PATHS[ $name ]
		);
	}
}
