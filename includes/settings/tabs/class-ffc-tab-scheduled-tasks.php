<?php
/**
 * Scheduled Tasks Tab
 *
 * @package FreeFormCertificate\Settings\Tabs
 * @since 6.33.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Settings\Tabs;

use FreeFormCertificate\Core\ScheduledTasks;
use FreeFormCertificate\Settings\SettingsTab;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read-only view of the plugin's WP-Cron tasks and the server line that keeps
 * them running (#1538).
 *
 * Nothing here writes: the table reads the register and the heartbeats
 * `ScheduledTasks` keeps, and the crontab line is generated for the operator
 * to paste on the server. So the tab carries no form, and the page-wide
 * settings lock never has anything to disable.
 */
class TabScheduledTasks extends SettingsTab {

	/**
	 * Script handle of the crontab-line generator.
	 */
	private const SCRIPT_HANDLE = 'ffc-scheduled-tasks';

	/**
	 * Init.
	 */
	protected function init(): void {
		$this->tab_id    = 'scheduled_tasks';
		$this->tab_group = 'system';
		$this->tab_title = __( 'Scheduled Tasks', 'ffcertificate' );
		$this->tab_icon  = 'ffc-icon-calendar';
		$this->tab_order = 85;

		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
	}

	/**
	 * Enqueue the crontab-line generator, with every line pre-built in PHP so
	 * the script only swaps a value and never assembles a shell command.
	 *
	 * @param string $hook Hook name.
	 * @return void
	 */
	public function enqueue_scripts( string $hook ): void {
		if ( ! $this->should_enqueue_on( $hook ) ) {
			return;
		}

		$s = \FreeFormCertificate\Core\AssetHelper::asset_suffix();
		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			FFC_PLUGIN_URL . "assets/js/ffc-scheduled-tasks{$s}.js",
			array( 'jquery' ),
			FFC_VERSION,
			true
		);
		wp_localize_script(
			self::SCRIPT_HANDLE,
			'ffcScheduledTasks',
			array( 'lines' => self::crontab_lines() )
		);
	}

	/**
	 * Every crontab line for this site.
	 *
	 * @return array<string, array<int, string>>
	 */
	public static function crontab_lines(): array {
		return ScheduledTasks::crontab_lines( ABSPATH, site_url() );
	}

	/**
	 * Human label of a task state.
	 *
	 * @param string $state One of the states `ScheduledTasks::state()` returns.
	 * @return string
	 */
	public static function state_label( string $state ): string {
		switch ( $state ) {
			case 'ok':
				return __( 'Running', 'ffcertificate' );
			case 'late':
				return __( 'Late — has not run when expected', 'ffcertificate' );
			case 'never_run':
				return __( 'Scheduled — no run recorded yet', 'ffcertificate' );
			case 'not_scheduled':
				return __( 'Not scheduled', 'ffcertificate' );
		}
		return $state;
	}

	/**
	 * Render.
	 */
	public function render(): void {
		$view_file = FFC_PLUGIN_DIR . 'includes/settings/views/ffc-tab-scheduled-tasks.php';

		if ( file_exists( $view_file ) ) {
			include $view_file;
		} else {
			wp_admin_notice(
				esc_html__( 'Scheduled tasks view file not found.', 'ffcertificate' ),
				array( 'type' => 'error' )
			);
		}
	}
}
