<?php
/**
 * Abstract Base Class for Settings Tabs
 *
 * Provides common functionality for all settings tabs
 *
 * @package FFC
 * @since 2.10.0
 * @version 3.2.0 - Migrated to namespace
 */

declare(strict_types=1);

namespace FreeFormCertificate\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Ensure WordPress is loaded.
if ( ! function_exists( 'wp_kses_post' ) ) {
	require_once ABSPATH . 'wp-includes/formatting.php';
}

/**
 * Settings Tab.
 */
abstract class SettingsTab {

	/**
	 * Tab unique identifier
	 *
	 * @var string
	 */
	protected $tab_id;

	/**
	 * Tab display title
	 *
	 * @var string
	 */
	protected $tab_title;

	/**
	 * Tab icon (emoji or HTML)
	 *
	 * @var string
	 */
	protected $tab_icon;

	/**
	 * Tab order/priority.
	 *
	 * Legacy hint: since 6.16.0 the Settings screen orders tabs
	 * alphabetically by their translated title (with General pinned first and
	 * Advanced/Migrations/Documentation pinned last) via
	 * {@see \FreeFormCertificate\Core\LabelSorter}, so this value no longer
	 * drives display order. Retained because existing per-tab tests assert it
	 * and third-party tabs may still read it.
	 *
	 * @var int
	 */
	protected $tab_order = 10;

	/**
	 * The Settings-nav group this tab belongs to.
	 *
	 * The nav renders tabs under domain subheadings (Content / Security &
	 * Access / Tools / System …); this key selects the group, and the group
	 * order + labels live centrally in {@see \FreeFormCertificate\Admin\Settings}.
	 * Tabs stay alphabetical WITHIN their group. Defaults to `tools` so a tab
	 * that doesn't set a group (e.g. a third-party tab) lands in the neutral
	 * "Tools" bucket rather than breaking the grouped nav.
	 *
	 * @var string
	 */
	protected $tab_group = 'tools';

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->init();
	}

	/**
	 * Initialize tab properties
	 * Override this in child classes
	 *
	 * @return void
	 */
	protected function init() {
		// Override in child class.
	}

	/**
	 * Render tab content
	 * Must be implemented by child classes
	 *
	 * @return void
	 */
	abstract public function render();

	/**
	 * Get tab ID
	 *
	 * @return string
	 */
	public function get_id() {
		return $this->tab_id;
	}

	/**
	 * Get tab title
	 *
	 * @return string
	 */
	public function get_title() {
		return $this->tab_title;
	}

	/**
	 * Get tab icon
	 *
	 * @return string
	 */
	public function get_icon() {
		return $this->tab_icon;
	}

	/**
	 * Get tab order
	 *
	 * @return int
	 */
	public function get_order() {
		return $this->tab_order;
	}

	/**
	 * Get the Settings-nav group key for this tab.
	 *
	 * @return string One of the group keys defined in
	 *                {@see \FreeFormCertificate\Admin\Settings::nav_group_labels()}.
	 */
	public function get_group(): string {
		return (string) $this->tab_group;
	}

	/**
	 * Capability required to VIEW this tab.
	 *
	 * Defaults to the page-wide settings view cap so every existing config tab
	 * keeps its current gating. Tabs backed by their own capability (e.g. the
	 * Activity Log) override this to be visible to their own audience even when
	 * the user lacks `ffc_view_settings`. Consumed by the Settings render loop
	 * (per-tab visibility) and by the computed `ffc_view_settings_page` menu cap.
	 *
	 * @return string
	 */
	public function get_view_cap(): string {
		return 'ffc_view_settings';
	}

	/**
	 * Capability required to EDIT (save) on this tab.
	 *
	 * Defaults to the page-wide settings manage cap. The Settings page wraps a
	 * tab's body in a disabled <fieldset> when the current user lacks THIS cap,
	 * so a read-only surface (e.g. the Activity Log) overrides it to its own
	 * tier and never has its legitimate actions disabled by the settings lock.
	 *
	 * @return string
	 */
	public function get_manage_cap(): string {
		return 'ffc_manage_settings';
	}

	/**
	 * Absolute path of one of the shipped Settings views.
	 *
	 * The single place a tab's `render()` resolves its view, so a test can
	 * point the real `render()` at a view of its own (or at none) by
	 * overriding this method alone, instead of copying the body of
	 * `render()` and asserting against the copy (#1811).
	 *
	 * @since 6.40.0
	 * @param string $file The view's file name, e.g. `ffc-tab-smtp.php`.
	 * @return string
	 */
	protected function view_path( string $file ): string {
		return FFC_PLUGIN_DIR . 'includes/settings/views/' . $file;
	}

	/**
	 * Render admin notice
	 *
	 * @param string $message Notice message.
	 * @param string $type Notice type (success, error, warning, info).
	 * @return void
	 */
	protected function render_notice( $message, $type = 'success' ) {
		// paragraph_wrap (default true) supplies the <p>; the message is
		// wp_kses_post-sanitized as before, so rich-HTML callers still work.
		\FreeFormCertificate\Core\AdminNotice::render(
			$type,
			wp_kses_post( $message )
		);
	}

	/**
	 * Render settings section header
	 *
	 * @param string $title Section title.
	 * @param string $description Section description (optional).
	 * @param string $icon        A `.ffc-icon-*` class for the heading (optional).
	 * @return void
	 */
	protected function render_section_header( $title, $description = '', $icon = '' ) {
		?>
		<div class="ffc-section-header">
			<h2<?php echo '' !== $icon ? ' class="' . esc_attr( $icon ) . '"' : ''; ?>><?php echo esc_html( $title ); ?></h2>
			<?php if ( ! empty( $description ) ) : ?>
				<p class="description"><?php echo wp_kses_post( $description ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render settings table row
	 *
	 * @param string $label Field label.
	 * @param string $content Field HTML content.
	 * @param string $description Field description (optional).
	 * @return void
	 */
	protected function render_field_row( $label, $content, $description = '' ) {
		?>
		<tr>
			<th scope="row">
				<label><?php echo esc_html( $label ); ?></label>
			</th>
			<td>
				<?php echo wp_kses_post( $content ); ?>
				<?php if ( ! empty( $description ) ) : ?>
					<p class="description"><?php echo wp_kses_post( $description ); ?></p>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * Check if current tab is active
	 *
	 * @return bool
	 */
	protected function is_active() {
		return null !== self::$resolved_active_tab && self::$resolved_active_tab === $this->tab_id;
	}

	/**
	 * The tab the Settings page shows for this request, as it resolved it.
	 *
	 * The page shows its first visible tab when `?tab=` is absent or not one
	 * the user may see. A tab that read the query string alone believed it was
	 * inactive on the bare menu link (`page=ffc-settings`), so its assets were
	 * not enqueued until the administrator switched tabs and back.
	 *
	 * @var string|null
	 */
	private static $resolved_active_tab = null;

	/**
	 * Record the tab the Settings page resolved for this request.
	 *
	 * @param string|null $tab_id Resolved tab id, or null before the page has resolved one.
	 */
	public static function set_resolved_active_tab( ?string $tab_id ): void {
		self::$resolved_active_tab = $tab_id;
	}

	/**
	 * The `admin_enqueue_scripts` hook suffix of the FFC Settings page — the
	 * top-level menu whose slug is `ffc-settings`. All tab assets gate on this.
	 *
	 * @var string
	 */
	public const SETTINGS_PAGE_HOOK = 'toplevel_page_ffc-settings';

	/**
	 * Whether this tab should enqueue its assets on the current request: we are
	 * on the FFC Settings page hook AND this tab is the active one. Centralizes
	 * the `toplevel_page_ffc-settings` + {@see self::is_active()} gate every
	 * tab's `enqueue_scripts()` otherwise repeats (and normalizes the split
	 * between tabs that inlined the `$_GET['tab']` read and those that already
	 * called `is_active()`).
	 *
	 * @since 6.20.0
	 * @param string $hook Current admin page hook suffix.
	 * @return bool
	 */
	protected function should_enqueue_on( string $hook ): bool {
		return self::SETTINGS_PAGE_HOOK === $hook && $this->is_active();
	}

	/**
	 * Enqueue the FFC auto-save infrastructure (ffc-core + the
	 * autosave widget) on this tab. Idempotent — safe to call from
	 * multiple tabs that each enqueue on `admin_enqueue_scripts`.
	 *
	 * Use when the tab renders one or more `data-ffc-autosave-key`
	 * inputs (toggles, etc.) backed by `SettingsAjaxEndpoint`.
	 */
	protected function enqueue_autosave_infra(): void {
		\FreeFormCertificate\Core\AssetRegistry::enqueue_scripts( 'ffc-core', 'ffc-admin-autosave' );
		// Section-collapse helper — wires `data-ffc-section-master` /
		// `data-ffc-section` so the standard "hide subsections when the
		// master toggle is off" UX works on every tab without bespoke JS.
		\FreeFormCertificate\Core\AssetRegistry::enqueue_scripts( 'ffc-section-collapse' );
		// Endpoint-specific nonce — the global ffc_ajax.nonce is created
		// for `ffc_admin_pdf_nonce` and won't verify against
		// `SettingsAjaxEndpoint::AJAX_ACTION` (`ffc_update_setting`).
		// The autosave script reads this object and passes the nonce in
		// the `data` payload; `FFC.request` preserves it when no
		// `options.nonce` override is provided.
		wp_localize_script(
			'ffc-admin-autosave',
			'ffcAdminAutosave',
			array(
				'nonce'   => wp_create_nonce( \FreeFormCertificate\Admin\SettingsAjaxEndpoint::AJAX_ACTION ),
				'strings' => \FreeFormCertificate\Admin\AdminUI::autosave_strings(),
			)
		);
	}

	/**
	 * Get option value from ffc_settings
	 *
	 * @param string $key Option key.
	 * @param string $default Default value.
	 * @return string
	 */
	public function get_option( string $key, string $default = '' ): string {
		return (string) SettingsReader::get( $key, $default );
	}
}