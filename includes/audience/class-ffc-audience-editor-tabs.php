<?php
/**
 * Audience Editor Tabs
 *
 * The vertical tabs of the audience calendar and audience editors (#1761),
 * drawn with the shared vertical-tab component (`.ffc-vtabs`, driven by
 * `ffc-vertical-tabs.js`) through the streaming primitives of
 * {@see \FreeFormCertificate\Admin\AdminUI}, so every editor shares one
 * markup, one stylesheet and one script. What stays here is what only these
 * two screens need: which tab opens, the layout class of their box, and the
 * screens that load the script.
 *
 * @package FreeFormCertificate\Audience
 */

declare(strict_types=1);

namespace FreeFormCertificate\Audience;

use FreeFormCertificate\Admin\AdminUI;
use FreeFormCertificate\Core\RequestInput;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Prints the tab strip and the panels around content the editors already
 * render.
 *
 * Every panel stays in the DOM and only hides once the script marks the
 * container ready, so a form spread over several panels still posts every
 * field, and without the script the panels stack. A panel is reached by its
 * id (`ffc-tabpanel-<key>`), never by position, which is what lets one
 * `<form>` wrap a run of panels while the next panel holds a form of its own.
 *
 * A tab's `icon` is an `ffc-icon-*` class, the form every vertical tab takes.
 *
 * @phpstan-type EditorTab array{key: string, label: string, icon: string}
 */
final class AudienceEditorTabs {

	/**
	 * Handle of the tab script, shared with the other editors.
	 */
	public const SCRIPT_HANDLE = AdminUI::VERTICAL_TABS_HANDLE;

	/**
	 * The tab to open: `?tab=`, when it names one of the tabs, else the
	 * fallback. The fallback is how a handler that lands back on the screen
	 * (a POST, or a redirect carrying `message=`) opens the tab it acted on.
	 *
	 * @param array<int, string> $keys     Keys of the tabs the screen draws.
	 * @param string             $fallback Key to open otherwise.
	 * @return string
	 */
	public static function active( array $keys, string $fallback ): string {
		$requested = RequestInput::get_get_string( 'tab' );
		if ( in_array( $requested, $keys, true ) ) {
			return $requested;
		}
		return in_array( $fallback, $keys, true ) ? $fallback : (string) ( $keys[0] ?? '' );
	}

	/**
	 * Open the container: the tab strip, then the panels column.
	 *
	 * @param array<int, array{key: string, label: string, icon: string}> $tabs   Tabs, in display order.
	 * @param string                                                      $active Key of the open tab.
	 * @phpstan-param list<EditorTab> $tabs
	 * @return void
	 */
	public static function open( array $tabs, string $active ): void {
		AdminUI::vertical_tabs_open( $tabs, $active, extra_class: 'ffc-aud-editor__tabs' );
	}

	/**
	 * Open one panel, with its title.
	 *
	 * @param array{key: string, label: string, icon: string} $tab    The tab.
	 * @param string                                          $active Key of the open tab.
	 * @phpstan-param EditorTab $tab
	 * @return void
	 */
	public static function panel_open( array $tab, string $active ): void {
		AdminUI::vertical_tab_panel_open( $tab, $active );
	}

	/**
	 * Close a panel.
	 *
	 * @return void
	 */
	public static function panel_close(): void {
		AdminUI::vertical_tab_panel_close();
	}

	/**
	 * Close the panels column and the container.
	 *
	 * @return void
	 */
	public static function close(): void {
		AdminUI::vertical_tabs_close();
	}

	/**
	 * Enqueue the tab script when the screen is one of the editors: the
	 * calendar form, and the audience form with its `action=members` URL.
	 *
	 * @param string $page   `?page=` of the screen.
	 * @param string $action `?action=` of the screen.
	 * @return bool Whether the script was enqueued.
	 */
	public static function maybe_enqueue( string $page, string $action ): bool {
		$editors = array(
			'ffc-scheduling-calendars' => array( 'new', 'edit' ),
			'ffc-scheduling-audiences' => array( 'new', 'edit', 'members' ),
		);
		if ( ! isset( $editors[ $page ] ) || ! in_array( $action, $editors[ $page ], true ) ) {
			return false;
		}
		AdminUI::enqueue_vertical_tabs();
		return true;
	}
}
