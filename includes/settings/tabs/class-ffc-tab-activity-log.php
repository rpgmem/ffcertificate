<?php
/**
 * Activity Log Settings Tab
 *
 * Embeds the Activity Log admin surface as a Settings tab (#802 Phase B),
 * replacing its former standalone submenu. The heavy lifting — the query,
 * filters, table, stats and batched CSV export — stays in
 * {@see \FreeFormCertificate\Admin\AdminActivityLogPage}; this tab is a thin
 * adapter that delegates render + enqueue and gates on the audit-log caps.
 *
 * The tab is gated by its OWN caps, not the settings caps: `ffc_view_activity_log`
 * to view (so an audit-only operator such as `ffc_readonly` still reaches it
 * even without `ffc_view_settings`, via the computed page-entry meta-cap) and
 * `ffc_export_activity_log` as the "manage" cap (so the Settings read-only
 * fieldset lock never disables the log's export/filter controls).
 *
 * @package FreeFormCertificate\Settings\Tabs
 * @since 6.16.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Settings\Tabs;

use FreeFormCertificate\Settings\SettingsTab;
use FreeFormCertificate\Admin\AdminActivityLogPage;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Activity Log settings tab.
 */
class TabActivityLog extends SettingsTab {

	/**
	 * The Activity Log admin surface this tab renders.
	 *
	 * @var AdminActivityLogPage
	 */
	private AdminActivityLogPage $page;

	/**
	 * Init.
	 */
	protected function init(): void {
		$this->tab_id    = 'activity_log';
		$this->tab_title = __( 'Activity Log', 'ffcertificate' );
		$this->tab_icon  = 'ffc-icon-clipboard';
		$this->tab_order = 10;

		$this->page = new AdminActivityLogPage();
		add_action( 'admin_enqueue_scripts', array( $this->page, 'enqueue_scripts' ) );
	}

	/**
	 * View cap: the dedicated audit-log read cap, independent of the settings
	 * view cap so an audit-only operator still sees this tab.
	 */
	public function get_view_cap(): string {
		return 'ffc_view_activity_log';
	}

	/**
	 * Manage cap: the audit-log export cap. Used only to decide the Settings
	 * read-only fieldset lock — keeping it off `ffc_manage_settings` means the
	 * log's export/filter controls are never disabled for a legitimate viewer.
	 */
	public function get_manage_cap(): string {
		return 'ffc_export_activity_log';
	}

	/**
	 * Render — delegate to the Activity Log admin surface.
	 */
	public function render(): void {
		$this->page->render_page();
	}
}
