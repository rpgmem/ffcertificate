<?php
/**
 * Recruitment Admin Assets Manager.
 *
 * Enqueues the dedicated CSS / JS bundle for the recruitment admin
 * page. Gated by screen ID so unrelated wp-admin pages don't pay the
 * cost. Mirrors the pattern used by
 * {@see \FreeFormCertificate\Admin\AdminAssetsManager}.
 *
 * Sprint A1 ships the skeleton (CSS for status badges + the
 * attached-adjutancy pills already inline; JS bundle is a stub that
 * will eat the per-render inline `<script>` blocks in subsequent
 * sprints). The manager is hookable from now on, so future sprints
 * just append to the existing files.
 *
 * @package FreeFormCertificate\Recruitment
 * @since   6.1.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Recruitment;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Asset enqueue manager scoped to the recruitment admin screens.
 */
final class RecruitmentAdminAssetsManager {

	/**
	 * Handle prefix for the recruitment admin assets.
	 */
	public const HANDLE_CSS = 'ffc-recruitment-admin';
	public const HANDLE_JS  = 'ffc-recruitment-admin';

	/**
	 * Hook into `admin_enqueue_scripts`. Default priority — no ordering
	 * constraint vs other plugins.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'admin_enqueue_scripts', array( self::class, 'maybe_enqueue' ), 10 );
	}

	/**
	 * Enqueue assets only when we're on a recruitment admin screen.
	 *
	 * The hook suffix WP passes is `toplevel_page_ffc-recruitment` for
	 * the main page (since the top-level menu's slug is the same as the
	 * page slug); future sub-pages will be `ffc-recruitment_page_…`.
	 * We match both prefixes.
	 *
	 * @param string $hook_suffix Current admin screen hook suffix.
	 * @return void
	 */
	public static function maybe_enqueue( string $hook_suffix ): void {
		if ( ! self::is_recruitment_screen( $hook_suffix ) ) {
			return;
		}

		$css_path = FFC_PLUGIN_DIR . 'assets/css/ffc-recruitment-admin.css';
		$js_path  = FFC_PLUGIN_DIR . 'assets/js/ffc-recruitment-admin.js';

		// Bust cache via filemtime so admins editing local copies see the
		// fresh asset without bumping FFC_VERSION on every change.
		$css_ver = file_exists( $css_path ) ? (string) filemtime( $css_path ) : FFC_VERSION;
		$js_ver  = file_exists( $js_path ) ? (string) filemtime( $js_path ) : FFC_VERSION;

		wp_enqueue_style(
			self::HANDLE_CSS,
			FFC_PLUGIN_URL . 'assets/css/ffc-recruitment-admin.css',
			array(),
			$css_ver
		);

		wp_enqueue_script(
			self::HANDLE_JS,
			FFC_PLUGIN_URL . 'assets/js/ffc-recruitment-admin.js',
			array(),
			$js_ver,
			true
		);

		// Localize the REST root + nonce so the JS can post against the
		// recruitment endpoints without an inline `<script>` block.
		wp_localize_script(
			self::HANDLE_JS,
			'ffcRecruitmentAdmin',
			array(
				'restRoot' => esc_url_raw( rest_url( 'ffcertificate/v1/recruitment/' ) ),
				'nonce'    => wp_create_nonce( 'wp_rest' ),
			)
		);
	}

	/**
	 * Is the current admin screen one of the recruitment pages?
	 *
	 * @param string $hook_suffix Current screen hook suffix.
	 * @return bool
	 */
	private static function is_recruitment_screen( string $hook_suffix ): bool {
		// Top-level page hook is `toplevel_page_<slug>`; sub-pages would
		// be `<parent>_page_<slug>`. Both contain the page slug.
		return false !== strpos( $hook_suffix, RecruitmentAdminPage::PAGE_SLUG );
	}
}
