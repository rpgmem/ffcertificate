<?php
/**
 * The plugin's icon set.
 *
 * @package FreeFormCertificate\Core
 * @since   6.35.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One registry for every icon the plugin draws (#1613).
 *
 * Drawn here rather than vendored: an icon bundle in `libs/js/` is the
 * fifth-bundle trigger recorded in #1208, and an icon font is one more
 * download for a set this small. Every icon is a 24x24 stroke drawing in
 * `currentColor`, so it takes the text colour of wherever it sits and follows
 * the dark theme with no colour of its own.
 *
 * Two ways to use one:
 *
 * - `svg()` prints it inline, for markup that is ours to change;
 * - the `.ffc-icon-<class>` classes draw it as a CSS mask, which is how the
 *   ~200 existing call sites (PHP, templates and JS-built markup) get it
 *   without being touched. That stylesheet is generated from this class by
 *   `stylesheet()` and committed into `assets/css/ffc-common.css` between
 *   markers; `IconStylesheetTest` fails when the two disagree.
 *
 * Names say what the icon MEANS, never which screen uses it (#1525). This
 * class was `Generators\QrIcons` until the rest of the plugin needed it.
 */
final class Icons {

	/**
	 * Inner markup of each icon, in a 24x24 box.
	 *
	 * @var array<string, string>
	 */
	private const PATHS = array(
		// QR content types. These keys are stored values (QrDesign::FRAME_ICONS
		// and the generator's type list), so they do not rename.
		'url'           => '<path d="M9 16H7a4 4 0 0 1 0-8h2M15 8h2a4 4 0 0 1 0 8h-2M8 12h8"/>',
		'text'          => '<path d="M4 6h16M4 10h16M4 14h16M4 18h10"/>',
		'wifi'          => '<path d="M2.5 9a14 14 0 0 1 19 0M5.5 12.5a9.5 9.5 0 0 1 13 0M8.8 16a5 5 0 0 1 6.4 0"/><circle cx="12" cy="19.5" r="1.2" fill="currentColor" stroke="none"/>',
		'email'         => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3.5 7l8.5 6 8.5-6"/>',
		'phone'         => '<path d="M5 4h3.5l1.8 4.6-2.3 1.5a11 11 0 0 0 5.9 5.9l1.5-2.3L20 15.5V19a2 2 0 0 1-2 2A15 15 0 0 1 3 6a2 2 0 0 1 2-2z"/>',
		'sms'           => '<path d="M4 5h16v11H9.5L5 20v-4H4z"/><path d="M8 9.5h8M8 12.5h5"/>',
		'whatsapp'      => '<path d="M4 20l1.3-4A8.5 8.5 0 1 1 8.4 19z"/><path d="M9.3 8.4l1.2-.3.9 2-.8.9a5 5 0 0 0 2.4 2.4l.9-.8 2 .9-.3 1.2c-3 .2-6.5-3.3-6.3-6.3z"/>',
		'vcard'         => '<rect x="2.5" y="5" width="19" height="14" rx="2"/><circle cx="8.5" cy="10.5" r="2"/><path d="M5.5 16a3 3 0 0 1 6 0M14.5 10h4.5M14.5 14h3.5"/>',
		'social'        => '<circle cx="18" cy="5.5" r="2.5"/><circle cx="6" cy="12" r="2.5"/><circle cx="18" cy="18.5" r="2.5"/><path d="M8.2 10.8l7.6-4.1M8.2 13.2l7.6 4.1"/>',
		'event'         => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/>',

		// QR design sections.
		'pattern'       => '<path d="M4 4h4v4H4zM10 4h4v4h-4zM16 10h4v4h-4zM4 16h4v4H4zM10 10h4v4h-4zM16 16h4v4h-4z"/>',
		'eyes'          => '<rect x="3" y="3" width="8" height="8" rx="1"/><rect x="13" y="3" width="8" height="8" rx="1"/><rect x="3" y="13" width="8" height="8" rx="1"/><path d="M6 6h2v2H6zM16 6h2v2h-2zM6 16h2v2H6z" fill="currentColor"/>',
		'colors'        => '<path d="M12 3s6 6.5 6 11a6 6 0 0 1-12 0c0-4.5 6-11 6-11z"/>',
		'logo'          => '<rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="9" cy="10" r="2"/><path d="M21 16l-5-5-9 9"/>',
		'frame'         => '<rect x="4" y="3" width="16" height="18" rx="2"/><rect x="7" y="6" width="10" height="9"/><path d="M9 18h6"/>',
		'advanced'      => '<path d="M4 6h9M17 6h3M4 12h3M11 12h9M4 18h11M19 18h1"/><circle cx="15" cy="6" r="2"/><circle cx="9" cy="12" r="2"/><circle cx="17" cy="18" r="2"/>',
		'none'          => '<circle cx="12" cy="12" r="8"/><path d="M6.5 17.5l11-11"/>',
		'scan'          => '<path d="M4 8V5a1 1 0 0 1 1-1h3M16 4h3a1 1 0 0 1 1 1v3M20 16v3a1 1 0 0 1-1 1h-3M8 20H5a1 1 0 0 1-1-1v-3M7 12h10"/>',
		'globe'         => '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/>',

		// Actions.
		'download'      => '<path d="M12 4v11M7 10l5 5 5-5M5 20h14"/>',
		'print'         => '<path d="M7 9V3h10v6"/><rect x="3" y="9" width="18" height="8" rx="2"/><path d="M7 14h10v7H7z"/>',
		'edit'          => '<path d="M4 20h4L19 9l-4-4L4 16z"/><path d="M13.5 6.5l4 4"/>',
		'trash'         => '<path d="M4 7h16M10 11v6M14 11v6M9 7V4h6v3M6 7l1 13h10l1-13"/>',
		'restore'       => '<path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5"/>',
		'copy'          => '<rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15V5a2 2 0 0 1 2-2h10"/>',
		'filter'        => '<path d="M3 5h18l-7 8v6l-4-2v-4z"/>',
		'search'        => '<circle cx="11" cy="11" r="6.5"/><path d="M20 20l-4.4-4.4"/>',
		'sync'          => '<path d="M20 11a8 8 0 0 0-14.3-4.9L4 8M4 4v4h4M4 13a8 8 0 0 0 14.3 4.9L20 16M20 20v-4h-4"/>',
		'skip'          => '<path d="M5 5l9 7-9 7zM18 5v14"/>',
		'logout'        => '<path d="M10 4H6a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h4M15 8l4 4-4 4M19 12H9"/>',
		'eye'           => '<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>',
		'link'          => '<path d="M10 14a4.5 4.5 0 0 0 6.4 0l3-3a4.5 4.5 0 0 0-6.4-6.4l-1 1"/><path d="M14 10a4.5 4.5 0 0 0-6.4 0l-3 3a4.5 4.5 0 0 0 6.4 6.4l1-1"/>',

		// Status.
		'info'          => '<circle cx="12" cy="12" r="9"/><path d="M12 11v6"/><circle cx="12" cy="7.5" r="1.1" fill="currentColor" stroke="none"/>',
		'warning'       => '<path d="M12 3.5L2.5 20h19z"/><path d="M12 10v4.5"/><circle cx="12" cy="17.3" r="1.1" fill="currentColor" stroke="none"/>',
		'success'       => '<circle cx="12" cy="12" r="9"/><path d="M8 12.5l2.8 2.8L16.5 9"/>',
		'error'         => '<circle cx="12" cy="12" r="9"/><path d="M9 9l6 6M15 9l-6 6"/>',
		'check'         => '<path d="M5 12.5l4.5 4.5L19 7"/>',
		'x'             => '<path d="M6 6l12 12M18 6L6 18"/>',
		'help'          => '<circle cx="12" cy="12" r="9"/><path d="M9.5 9.5a2.5 2.5 0 1 1 3.5 2.3c-.6.3-1 .9-1 1.6v.6"/><circle cx="12" cy="17" r="1.1" fill="currentColor" stroke="none"/>',
		'bulb'          => '<path d="M9 18h6M10 21h4M12 3a6 6 0 0 0-3.5 10.9c.6.5 1 1.2 1 2V16h5v-.1c0-.8.4-1.5 1-2A6 6 0 0 0 12 3z"/>',

		// Things.
		'settings'      => '<circle cx="12" cy="12" r="3"/><circle cx="12" cy="12" r="7"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3M4.9 4.9L7 7M17 17l2.1 2.1M4.9 19.1L7 17M17 7l2.1-2.1"/>',
		'clipboard'     => '<rect x="5" y="4" width="14" height="17" rx="2"/><path d="M9 4V3h6v1M9 10h6M9 14h6M9 18h4"/>',
		'file'          => '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5M9 13h6M9 17h6"/>',
		'book'          => '<path d="M4 5a2 2 0 0 1 2-2h14v16H6a2 2 0 0 0-2 2z"/><path d="M4 19V5M8 7h8"/>',
		'chart'         => '<path d="M4 20h16"/><path d="M7 20v-6M12 20V8M17 20V10"/>',
		'bug'           => '<rect x="7" y="8" width="10" height="12" rx="5"/><path d="M12 8v12M9 5l1.5 2M15 5l-1.5 2M3 12h4M17 12h4M4 7l3 2.5M20 7l-3 2.5M4 18l3-2M20 18l-3-2"/>',
		'package'       => '<path d="M3 7.5L12 3l9 4.5v9L12 21l-9-4.5z"/><path d="M3 7.5l9 4.5 9-4.5M12 12v9"/>',
		'palette'       => '<path d="M12 3a9 9 0 1 0 0 18c1.1 0 1.6-.8 1.6-1.6 0-1.2-1-1.6-1-2.6 0-.9.7-1.6 1.6-1.6H17a4 4 0 0 0 4-4c0-4.6-4-8.2-9-8.2z"/><circle cx="7.5" cy="11" r="1"/><circle cx="10" cy="7" r="1"/><circle cx="15" cy="7" r="1"/>',
		'lock'          => '<rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/>',
		'unlock'        => '<rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V8a4 4 0 0 1 7.7-1.5"/>',
		'megaphone'     => '<path d="M3 10v4h3l7 4V6l-7 4z"/><path d="M6 14l1.5 5h2.5l-1.3-4.3M17 9a4 4 0 0 1 0 6M19.5 6.5a7.5 7.5 0 0 1 0 11"/>',
		'shield'        => '<path d="M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6z"/>',
		'robot'         => '<rect x="4" y="8" width="16" height="12" rx="3"/><path d="M12 4.5V8M9 13v1M15 13v1M9 17h6"/><circle cx="12" cy="3.5" r="1"/>',
		'inbox'         => '<path d="M3 13l2.5-8h13L21 13v6H3z"/><path d="M3 13h5l1.5 2.5h5L16 13h5"/>',
		'user'          => '<circle cx="12" cy="8" r="4"/><path d="M4 21c1.5-4 4.5-6 8-6s6.5 2 8 6"/>',
		'users'         => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c.8-3.5 3.4-5.5 6.5-5.5s5.7 2 6.5 5.5"/><path d="M16 4.6a3.5 3.5 0 0 1 0 6.8M18 14.8c2 .7 3.2 2.5 3.5 5.2"/>',
		'id-card'       => '<rect x="2.5" y="5" width="19" height="14" rx="2"/><path d="M6.5 15V9M10 9h2.5a3 3 0 0 1 0 6H10z"/>',
		'map-pin'       => '<path d="M12 21s-7-6.2-7-11.5A7 7 0 0 1 19 9.5C19 14.8 12 21 12 21z"/><circle cx="12" cy="9.5" r="2.5"/>',
		'clock'         => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
		'grid'          => '<rect x="4" y="4" width="7" height="7" rx="1.5"/><rect x="13" y="4" width="7" height="7" rx="1.5"/><rect x="4" y="13" width="7" height="7" rx="1.5"/><rect x="13" y="13" width="7" height="7" rx="1.5"/>',
		'send'          => '<path d="M21 3L3 10.5l7 2.5 2.5 7z"/><path d="M21 3L10 13"/>',
		'layout'        => '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M3 9h18M9 9v11"/>',
		'qr'            => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><path d="M14 14h3v3h-3zM20.5 14v.01M14 20.5h.01M17.5 20.5H21v-3.5"/>',
		'user-check'    => '<circle cx="10" cy="8" r="4"/><path d="M3 21c1.4-3.8 4-5.8 7-5.8 1.6 0 3 .5 4.2 1.5M15.5 18.5l2 2 4-4.5"/>',
		'history'       => '<path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5M12 7.5V12l3 2"/>',
		'gauge'         => '<path d="M4.2 17a9 9 0 1 1 15.6 0"/><path d="M12.9 12.8L16 8.5"/><circle cx="12" cy="14" r="1.6"/>',
		'network'       => '<rect x="9" y="3" width="6" height="5" rx="1"/><rect x="3" y="16" width="6" height="5" rx="1"/><rect x="15" y="16" width="6" height="5" rx="1"/><path d="M12 8v4M6 16v-2h12v2"/>',
		'key'           => '<circle cx="7.5" cy="15.5" r="4"/><path d="M10.5 12.5L20 3M16 7l3 3M13.5 9.5l2 2"/>',
		'zap'           => '<path d="M13 2L4 14h7l-1 8 9-12h-7z"/>',
		'database'      => '<path d="M4 6c0-1.7 3.6-3 8-3s8 1.3 8 3-3.6 3-8 3-8-1.3-8-3z"/><path d="M4 6v12c0 1.7 3.6 3 8 3s8-1.3 8-3V6M4 12c0 1.7 3.6 3 8 3s8-1.3 8-3"/>',
		'award'         => '<circle cx="12" cy="9" r="6"/><path d="M8.5 13.9L7 21l5-2.5 5 2.5-1.5-7.1"/>',
		'tag'           => '<path d="M3 12V4a1 1 0 0 1 1-1h8l9 9-9 9z"/><circle cx="7.5" cy="7.5" r="1.5"/>',
		'list'          => '<path d="M9 6h11M9 12h11M9 18h11"/><circle cx="4.5" cy="6" r="1" fill="currentColor" stroke="none"/><circle cx="4.5" cy="12" r="1" fill="currentColor" stroke="none"/><circle cx="4.5" cy="18" r="1" fill="currentColor" stroke="none"/>',
		'code'          => '<path d="M8 7l-5 5 5 5M16 7l5 5-5 5M14 4l-4 16"/>',
		'wrench'        => '<path d="M14.5 6.5a4 4 0 0 0 5 5L21 10a6 6 0 0 1-7.9 5.4L6 22.5 1.5 18l7.1-7.1A6 6 0 0 1 14 3l-1.5 1.5z"/>',
		'cloud'         => '<path d="M7 18a4.5 4.5 0 0 1-.5-9A6 6 0 0 1 18 8.5a4.75 4.75 0 0 1-.5 9.5z"/>',
		'api'           => '<path d="M10 19H6a4.25 4.25 0 0 1-.6-8.46 6.75 6.75 0 0 1 13.1-1.3A3.6 3.6 0 0 1 21 11.7"/><path d="M15.6 14.4L15.8 13.0L18.2 13.0L18.4 14.4L18.7 14.6L20.1 14.1L21.3 16.1L20.1 17.0L20.1 17.4L21.3 18.3L20.1 20.3L18.7 19.8L18.4 20.0L18.2 21.4L15.8 21.4L15.6 20.0L15.3 19.8L13.9 20.3L12.7 18.3L13.9 17.4L13.9 17.0L12.7 16.1L13.9 14.1L15.3 14.6z"/><circle cx="17" cy="17.2" r="1.3"/>',
		'server'        => '<rect x="3" y="4" width="18" height="7" rx="1.5"/><rect x="3" y="13" width="18" height="7" rx="1.5"/><path d="M7 7.5h.01M7 16.5h.01M11 7.5h6M11 16.5h6"/>',
		'monitor'       => '<rect x="2.5" y="4" width="15" height="10.5" rx="1.5"/><path d="M7 18.5h6M10 14.5v4"/><rect x="16" y="9" width="5.5" height="11" rx="1.2"/>',
		'plus'          => '<path d="M12 5v14M5 12h14"/>',
		'minus'         => '<path d="M5 12h14"/>',
		'grip'          => '<circle cx="9" cy="6" r="1.2" fill="currentColor" stroke="none"/><circle cx="15" cy="6" r="1.2" fill="currentColor" stroke="none"/><circle cx="9" cy="12" r="1.2" fill="currentColor" stroke="none"/><circle cx="15" cy="12" r="1.2" fill="currentColor" stroke="none"/><circle cx="9" cy="18" r="1.2" fill="currentColor" stroke="none"/><circle cx="15" cy="18" r="1.2" fill="currentColor" stroke="none"/>',
		'upload'        => '<path d="M12 16V5M7 10l5-5 5 5M5 20h14"/>',
		'chevron-left'  => '<path d="M15 6l-6 6 6 6"/>',
		'chevron-right' => '<path d="M9 6l6 6-6 6"/>',
		'chevron-up'    => '<path d="M6 15l6-6 6 6"/>',
		'chevron-down'  => '<path d="M6 9l6 6 6-6"/>',
		'building'      => '<path d="M4 21V5a1 1 0 0 1 1-1h9a1 1 0 0 1 1 1v16M15 9h4a1 1 0 0 1 1 1v11M3 21h18"/><path d="M8 8h3M8 12h3M8 16h3"/>',
		'external'      => '<path d="M14 4h6v6M20 4l-9 9"/><path d="M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5"/>',
		'cookie'        => '<path d="M12 3a9 9 0 1 0 9 9 3 3 0 0 1-3.5-3A3 3 0 0 1 14 5.5 3 3 0 0 1 12 3z"/><circle cx="8.5" cy="10" r="1" fill="currentColor" stroke="none"/><circle cx="10" cy="15.5" r="1" fill="currentColor" stroke="none"/><circle cx="15" cy="15" r="1" fill="currentColor" stroke="none"/>',
	);

	/**
	 * The `.ffc-icon-<class>` utility classes and the icon each one draws.
	 *
	 * The class names are a published API: they are emitted from PHP, from
	 * templates and from JS-built markup, and settings tabs return them from
	 * `get_icon()`. Several predate this registry and were named for the emoji
	 * they used to print (`checkmark`, `cross`, `scroll`), so the map carries
	 * the meaning rather than renaming every call site.
	 *
	 * @var array<string, string>
	 */
	private const CLASSES = array(
		'api'           => 'api',
		'award'         => 'award',
		'building'      => 'building',
		'bulb'          => 'bulb',
		'calendar'      => 'event',
		'chart'         => 'chart',
		'checkmark'     => 'check',
		'chevron-down'  => 'chevron-down',
		'chevron-left'  => 'chevron-left',
		'chevron-right' => 'chevron-right',
		'chevron-up'    => 'chevron-up',
		'clipboard'     => 'clipboard',
		'clock'         => 'clock',
		'cloud'         => 'cloud',
		'code'          => 'code',
		'copy'          => 'copy',
		'cross'         => 'x',
		'database'      => 'database',
		'debug'         => 'bug',
		'delete'        => 'trash',
		'doc'           => 'book',
		'download'      => 'download',
		'edit'          => 'edit',
		'email'         => 'email',
		'error'         => 'error',
		'external'      => 'external',
		'eye'           => 'eye',
		'file'          => 'file',
		'filter'        => 'filter',
		'gauge'         => 'gauge',
		'globe'         => 'globe',
		'grid'          => 'grid',
		'grip'          => 'grip',
		'help'          => 'help',
		'history'       => 'history',
		'id'            => 'id-card',
		'image'         => 'logo',
		'inbox'         => 'inbox',
		'info'          => 'info',
		'key'           => 'key',
		'layout'        => 'layout',
		'link'          => 'link',
		'list'          => 'list',
		'lock'          => 'lock',
		'logout'        => 'logout',
		'map-pin'       => 'map-pin',
		'megaphone'     => 'megaphone',
		'minus'         => 'minus',
		'monitor'       => 'monitor',
		'network'       => 'network',
		'package'       => 'package',
		'palette'       => 'palette',
		'phone'         => 'phone',
		'plus'          => 'plus',
		'print'         => 'print',
		'qr'            => 'qr',
		'restore'       => 'restore',
		'robot'         => 'robot',
		'scroll'        => 'file',
		'search'        => 'search',
		'send'          => 'send',
		'server'        => 'server',
		'settings'      => 'settings',
		'share'         => 'social',
		'shield'        => 'shield',
		'skip'          => 'skip',
		'sliders'       => 'advanced',
		'success'       => 'success',
		'sync'          => 'sync',
		'tag'           => 'tag',
		'unlock'        => 'unlock',
		'upload'        => 'upload',
		'user'          => 'user',
		'user-check'    => 'user-check',
		'users'         => 'users',
		'warning'       => 'warning',
		'wrench'        => 'wrench',
		'zap'           => 'zap',
	);

	/**
	 * Tones an icon may take inside a tab's content, and the tokens behind each:
	 * the colour of a bare icon, then the ground and colour of its badge.
	 *
	 * An icon is monochrome by default: in the tab menu and on buttons it takes
	 * the text colour, because there it only labels. A tone is for content,
	 * where the colour says what something IS — a limit, a destructive action,
	 * a piece of help — so a tone is chosen for its meaning, never to decorate.
	 * A badge without a tone modifier is the neutral one.
	 *
	 * The bare warning icon reads `--ffc-warning-text`, not `--ffc-warning`:
	 * the signal colour measures 3.04:1 on a light card, at the floor for a
	 * graphic, and the darker shade clears it with room. `DarkModeCssTest`
	 * measures every pair here in both themes.
	 *
	 * @var array<string, array{0: string, 1: string, 2: string}>
	 */
	private const TONES = array(
		'primary' => array( '--ffc-primary', '--ffc-primary-light', '--ffc-primary' ),
		'info'    => array( '--ffc-info', '--ffc-info-bg', '--ffc-info-text' ),
		'success' => array( '--ffc-success', '--ffc-success-bg', '--ffc-success-text' ),
		'warning' => array( '--ffc-warning-text', '--ffc-warning-bg', '--ffc-warning-text' ),
		'danger'  => array( '--ffc-danger', '--ffc-danger-bg', '--ffc-danger-text' ),
		'neutral' => array( '--ffc-text-secondary', '--ffc-bg-alt', '--ffc-text-secondary' ),
	);

	/** Marker that opens the generated block inside `ffc-common.css`. */
	public const CSS_BEGIN = '/* ffc-icons:begin — generated by FreeFormCertificate\Core\Icons::stylesheet(); do not edit by hand. */';

	/** Marker that closes the generated block inside `ffc-common.css`. */
	public const CSS_END = '/* ffc-icons:end */';

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
	 * The `.ffc-icon-*` utility classes, mapped to the icon each one draws.
	 *
	 * @return array<string, string>
	 */
	public static function classes(): array {
		return self::CLASSES;
	}

	/**
	 * The tones, each with its bare colour token and its badge's ground and
	 * colour tokens.
	 *
	 * @return array<string, array{0: string, 1: string, 2: string}>
	 */
	public static function tones(): array {
		return self::TONES;
	}

	/**
	 * The `.ffc-icon-*` classes that style an icon rather than draw one: the
	 * badge and its tone variants, the bare tones, and the busy spin.
	 *
	 * @return array<int, string>
	 */
	public static function modifiers(): array {
		$modifiers = array( 'badge', 'spin' );
		foreach ( array_keys( self::TONES ) as $tone ) {
			$modifiers[] = 'tone-' . $tone;
			if ( 'neutral' !== $tone ) {
				$modifiers[] = 'badge-' . $tone;
			}
		}
		return $modifiers;
	}

	/**
	 * The class that draws a tab's icon.
	 *
	 * The plugin's own tabs name a registry class (`ffc-icon-settings`). A tab
	 * added through a filter (`ffc_scheduling_settings_tabs`, the recruitment
	 * and form-editor tab lists) may still name a dashicon by its bare slug,
	 * as every tab did before #1613; that keeps drawing as a dashicon rather
	 * than as nothing.
	 *
	 * @param string $icon A `.ffc-icon-*` class, or a dashicon slug.
	 * @return string
	 */
	public static function tab_class( string $icon ): string {
		if ( str_starts_with( $icon, 'ffc-icon-' ) && isset( self::CLASSES[ substr( $icon, strlen( 'ffc-icon-' ) ) ] ) ) {
			return $icon;
		}
		return 'dashicons dashicons-' . $icon;
	}

	/**
	 * The inner drawing of an icon, for embedding in a larger SVG (a QR
	 * frame's call to action); '' for an unknown name.
	 *
	 * @param string $name Icon name.
	 * @return string
	 */
	public static function paths( string $name ): string {
		return self::PATHS[ $name ] ?? '';
	}

	/**
	 * An icon as an inline SVG element, hidden from assistive technology:
	 * every place it is used carries a visible label, or gives its control an
	 * accessible name of its own.
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
			'<svg class="ffc-svg-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="%1$d" height="%1$d" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">%2$s</svg>',
			max( 1, $size ),
			self::PATHS[ $name ]
		);
	}

	/**
	 * The CSS that draws every `.ffc-icon-*` class, between its markers.
	 *
	 * Each icon is a mask over `currentColor`, so it is painted in the text
	 * colour of its element; it sits in `::before` with no content, which
	 * keeps it out of the accessibility tree the way the emoji it replaced
	 * were not. Sizes are in `em` so the icon scales with the text beside it.
	 *
	 * @return string
	 */
	public static function stylesheet(): string {
		$selectors = array();
		$rules     = array();
		foreach ( self::CLASSES as $class => $icon ) {
			$selector    = '.ffc-icon-' . $class . '::before';
			$selectors[] = $selector;
			$url         = 'url("data:image/svg+xml,' . self::data_uri_body( $icon ) . '")';
			$rules[]     = $selector . " {\n    -webkit-mask-image: " . $url . ";\n    mask-image: " . $url . ";\n}";
		}

		$base = implode( ",\n", $selectors ) . " {\n"
			. "    content: \"\";\n"
			. "    display: inline-block;\n"
			. "    flex-shrink: 0;\n"
			. "    width: 1.15em;\n"
			. "    height: 1.15em;\n"
			. "    margin-right: 0.4em;\n"
			. "    vertical-align: -0.2em;\n"
			. "    background-color: var(--ffc-icon-tone, currentColor);\n"
			. "    -webkit-mask-repeat: no-repeat;\n"
			. "    mask-repeat: no-repeat;\n"
			. "    -webkit-mask-position: center;\n"
			. "    mask-position: center;\n"
			. "    -webkit-mask-size: contain;\n"
			. "    mask-size: contain;\n"
			. '}';

		// Forced-colors mode replaces every background colour with the system
		// ground, which erased the mask fill and left each icon blank. Opting
		// the pseudo-element out of the adjustment and painting it in the system
		// text colour keeps it visible; a tone is dropped there on purpose, since
		// the mode owns every colour. The badge loses its ground the same way,
		// so it and each of its variants take a contour.
		$badges = array( '.ffc-icon-badge' );
		foreach ( array_keys( self::TONES ) as $tone ) {
			if ( 'neutral' !== $tone ) {
				$badges[] = '.ffc-icon-badge.ffc-icon-badge-' . $tone;
			}
		}
		$forced = "@media (forced-colors: active) {\n"
			. '    ' . implode( ",\n    ", $selectors ) . " {\n"
			. "        forced-color-adjust: none;\n"
			. "        background-color: CanvasText;\n"
			. "    }\n\n"
			. '    ' . implode( ",\n    ", $badges ) . " {\n"
			. "        border: 1px solid CanvasText;\n"
			. "    }\n"
			. '}';

		return self::CSS_BEGIN . "\n" . $base . "\n\n" . implode( "\n\n", $rules ) . "\n\n" . self::tone_rules() . "\n\n" . $forced . "\n" . self::CSS_END;
	}

	/**
	 * The tone and badge rules.
	 *
	 * A tone sets `--ffc-icon-tone`, which both the mask and the inline SVG
	 * read, so it colours the icon and never the text beside it. A badge
	 * resets that property to its own colour, so a tone set on an ancestor
	 * cannot paint the icon off the badge's ground.
	 *
	 * @return string
	 */
	private static function tone_rules(): string {
		$rules = array(
			".ffc-svg-icon {\n    color: var(--ffc-icon-tone, currentColor);\n}",
		);
		foreach ( self::TONES as $tone => $tokens ) {
			$rules[] = '.ffc-icon-tone-' . $tone . " {\n    --ffc-icon-tone: var(" . $tokens[0] . ");\n}";
		}

		$neutral = self::TONES['neutral'];
		$rules[] = ".ffc-icon-badge {\n"
			. "    --ffc-icon-tone: currentColor;\n"
			. "    display: inline-flex;\n"
			. "    align-items: center;\n"
			. "    justify-content: center;\n"
			. "    flex-shrink: 0;\n"
			. "    width: 2.25em;\n"
			. "    height: 2.25em;\n"
			. "    border-radius: var(--ffc-radius-lg);\n"
			. '    background: var(' . $neutral[1] . ");\n"
			. '    color: var(' . $neutral[2] . ");\n"
			. '}';
		$rules[] = ".ffc-icon-badge::before {\n    margin-right: 0;\n}";
		foreach ( self::TONES as $tone => $tokens ) {
			if ( 'neutral' === $tone ) {
				continue;
			}
			$rules[] = '.ffc-icon-badge.ffc-icon-badge-' . $tone . " {\n"
				. '    background: var(' . $tokens[1] . ");\n"
				. '    color: var(' . $tokens[2] . ");\n"
				. '}';
		}

		// A busy state. Turned off for whoever asked for reduced motion, where
		// the icon alone still says "working".
		$rules[] = ".ffc-icon-spin::before {\n    animation: ffc-icon-spin 1s linear infinite;\n}";
		$rules[] = "@keyframes ffc-icon-spin {\n    to {\n        transform: rotate(360deg);\n    }\n}";
		$rules[] = "@media (prefers-reduced-motion: reduce) {\n    .ffc-icon-spin::before {\n        animation: none;\n    }\n}";

		return implode( "\n\n", $rules );
	}

	/**
	 * A standalone SVG for a data URI, percent-encoded where CSS needs it.
	 *
	 * `currentColor` inside a standalone image resolves to black, which is all
	 * a mask needs: only the alpha channel is read.
	 *
	 * @param string $name Icon name.
	 * @return string
	 */
	private static function data_uri_body( string $name ): string {
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">'
			. self::paths( $name )
			. '</svg>';

		return strtr(
			str_replace( '"', "'", $svg ),
			array(
				'<' => '%3C',
				'>' => '%3E',
				'#' => '%23',
			)
		);
	}
}
