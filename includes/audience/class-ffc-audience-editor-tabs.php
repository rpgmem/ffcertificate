<?php
/**
 * Audience Editor Tabs
 *
 * The vertical tabs of the audience calendar and audience editors (#1761),
 * drawn with the certificate form editor's component (`.ffc-form-tabs`,
 * driven by `ffc-form-editor-tabs.js`), so the three editors share one
 * markup, one stylesheet and one script.
 *
 * @package FreeFormCertificate\Audience
 */

declare(strict_types=1);

namespace FreeFormCertificate\Audience;

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
 * @phpstan-type EditorTab array{key: string, label: string, icon: string}
 */
final class AudienceEditorTabs {

	/**
	 * Handle of the tab script, shared with the certificate form editor.
	 */
	public const SCRIPT_HANDLE = 'ffc-form-editor-tabs';

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
		echo '<div class="ffc-aud-editor__tabs ffc-form-tabs" data-ffc-form-tabs>';
		echo '<ul class="ffc-form-tabs__nav" role="tablist" aria-orientation="vertical">';
		foreach ( $tabs as $tab ) {
			$is_active = $tab['key'] === $active;
			printf(
				'<li class="ffc-form-tabs__nav-item" role="presentation"><a href="#ffc-tab-%1$s" id="ffc-tabnav-%1$s" class="ffc-form-tabs__tab%2$s" role="tab" aria-controls="ffc-tabpanel-%1$s" aria-selected="%3$s" tabindex="%4$s"><span class="ffc-form-tabs__icon ffc-icon-%5$s" aria-hidden="true"></span><span class="ffc-form-tabs__label">%6$s</span></a></li>',
				esc_attr( $tab['key'] ),
				$is_active ? ' is-active' : '',
				$is_active ? 'true' : 'false',
				$is_active ? '0' : '-1',
				esc_attr( $tab['icon'] ),
				esc_html( $tab['label'] )
			);
		}
		echo '</ul>';
		echo '<div class="ffc-form-tabs__panels">';
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
		printf(
			'<section id="ffc-tabpanel-%1$s" class="ffc-form-tabs__panel%2$s" role="tabpanel" aria-labelledby="ffc-tabnav-%1$s" tabindex="0">',
			esc_attr( $tab['key'] ),
			$tab['key'] === $active ? ' is-active' : ''
		);
		printf(
			'<h2 class="ffc-form-tabs__panel-title ffc-icon-%1$s">%2$s</h2>',
			esc_attr( $tab['icon'] ),
			esc_html( $tab['label'] )
		);
	}

	/**
	 * Close a panel.
	 *
	 * @return void
	 */
	public static function panel_close(): void {
		echo '</section>';
	}

	/**
	 * Close the panels column and the container.
	 *
	 * @return void
	 */
	public static function close(): void {
		echo '</div></div>';
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
		$s = \FreeFormCertificate\Core\AssetHelper::asset_suffix();
		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			FFC_PLUGIN_URL . "assets/js/ffc-form-editor-tabs{$s}.js",
			array( 'jquery' ),
			FFC_VERSION,
			true
		);
		return true;
	}
}
