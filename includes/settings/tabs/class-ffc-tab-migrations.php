<?php
/**
 * Data Migrations Tab
 *
 * @package FreeFormCertificate\Settings\Tabs
 * @since 2.10.0
 * @version 3.3.0 - Added strict types and type hints
 * @version 3.2.0 - Migrated to namespace
 */

declare(strict_types=1);

namespace FreeFormCertificate\Settings\Tabs;

use FreeFormCertificate\Settings\SettingsTab;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tab Migrations settings tab.
 */
class TabMigrations extends SettingsTab {

	/**
	 * Init.
	 */
	protected function init(): void {
		$this->tab_id    = 'migrations';
		$this->tab_group = 'system';
		$this->tab_title = __( 'Data Migrations', 'ffcertificate' );
		$this->tab_icon  = 'ffc-icon-database';
		$this->tab_order = 80;

		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
	}

	/**
	 * Enqueue autosave infra so the URL-cleanup criteria toggles on this
	 * tab persist incrementally (no more "lost selection after preview").
	 *
	 * @param string $hook Hook name.
	 */
	public function enqueue_scripts( string $hook ): void {
		if ( ! $this->should_enqueue_on( $hook ) ) {
			return;
		}
		$this->enqueue_autosave_infra();
	}

	/**
	 * Whether a migration card renders open (#2115).
	 *
	 * A finished card closes, so the cards with work left are what the
	 * screen leads with. One whose status could not be read stays open: a
	 * closed card would hide the error along with the progress.
	 *
	 * @param bool   $is_complete  Whether the strategy reports the work done.
	 * @param string $status_error The status check's error, or ''.
	 * @return bool
	 */
	public static function card_opens( bool $is_complete, string $status_error ): bool {
		return ! $is_complete || '' !== $status_error;
	}

	/**
	 * The release that deletes a deprecated migration, or '' for one that
	 * stays (#2115).
	 *
	 * @param array<string, mixed> $migration A registry entry.
	 * @return string
	 */
	public static function removal_of( array $migration ): string {
		return isset( $migration['removal'] ) && is_string( $migration['removal'] ) ? $migration['removal'] : '';
	}

	/**
	 * Render.
	 */
	public function render(): void {
		// Include view file.
		$view_file = $this->view_path( 'ffc-tab-migrations.php' );

		if ( file_exists( $view_file ) ) {
			include $view_file;
		} else {
			\FreeFormCertificate\Core\AdminNotice::render(
				'error',
				esc_html__( 'Migrations view file not found.', 'ffcertificate' )
			);
		}
	}
}
