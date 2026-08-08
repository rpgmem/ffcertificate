<?php
/**
 * HtmlRefsNotice
 *
 * Phase 0 admin notice (#865): warns admins when any stored certificate content
 * still references the legacy `html/` drop-folder (the plugin-scoped marker
 * `ffcertificate/html/`) — images a plugin update or `rsync --delete` deploy
 * will wipe. Points at the Settings → Migrations "Rewrite html/ Image
 * References" card that side-loads those images into the Media Library.
 *
 * Detection is one cached `LIKE` existence probe over the three post-meta keys
 * that can carry a certificate reference (`_ffc_form_config`, `_ffc_form_bg`,
 * `_ffc_template_html`), memoized in a transient so it is not re-run on every
 * admin page load. The shared hook / dismiss / capability plumbing lives in
 * {@see AbstractDismissibleNotice} (#849).
 *
 * @package FreeFormCertificate\Admin
 * @since   6.20.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Admin;

use FreeFormCertificate\Core\LegacyHtmlRefs;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders + persists the dismissible "legacy html/ references" warning.
 */
class HtmlRefsNotice extends AbstractDismissibleNotice {

	const OPTION_DISMISSED = 'ffc_html_refs_notice_dismissed';
	const AJAX_ACTION      = 'ffc_dismiss_html_refs_notice';
	const TRANSIENT        = 'ffc_html_refs_present';

	/**
	 * Option key the dismissed signature is stored under.
	 */
	protected static function option_key(): string {
		return self::OPTION_DISMISSED;
	}

	/**
	 * Nonce + `wp_ajax_{action}` hook suffix.
	 */
	protected static function action(): string {
		return self::AJAX_ACTION;
	}

	/**
	 * A "your data will break on update" warning renders warning-level.
	 */
	protected static function notice_type(): string {
		return 'warning';
	}

	/**
	 * Stable class for styling / test hooks.
	 */
	protected static function extra_class(): string {
		return 'ffc-html-refs-notice';
	}

	/**
	 * Show only when stored certificate content still references `html/`.
	 * Memoized in a transient so the probe runs at most twice a day.
	 */
	protected static function should_show(): bool {
		$cached = get_transient( self::TRANSIENT );
		if ( false !== $cached ) {
			return '1' === $cached;
		}

		$present = self::has_html_refs();
		set_transient( self::TRANSIENT, $present ? '1' : '0', 12 * HOUR_IN_SECONDS );
		return $present;
	}

	/**
	 * One-shot nudge: once the migration clears the references, {@see should_show}
	 * turns false and the notice disappears on its own; a dismissal hides it in
	 * the meantime.
	 */
	protected static function dismiss_signature(): string {
		return '1';
	}

	/**
	 * The inner notice HTML (two paragraphs, already escaped).
	 */
	protected static function notice_message(): string {
		$migrations_url = admin_url( 'admin.php?page=ffc-settings&tab=migrations' );

		$title = '<p><strong>'
			. esc_html__( 'Free Form Certificate — certificate images point at the plugin folder', 'ffcertificate' )
			. '</strong></p>';

		$body = '<p>' . wp_kses(
			sprintf(
				/* translators: 1: opening <code> tag, 2: closing </code> tag, 3: link to the Migrations settings tab. */
				__( 'Some certificate templates reference images inside the plugin\'s %1$shtml/%2$s folder, which a plugin update or deploy will delete. Run %3$s to move them into the Media Library.', 'ffcertificate' ),
				'<code>',
				'</code>',
				'<a href="' . esc_url( $migrations_url ) . '">' . esc_html__( 'Settings → Migrations → Rewrite html/ Image References', 'ffcertificate' ) . '</a>'
			),
			array(
				'a'    => array( 'href' => array() ),
				'code' => array(),
			)
		) . '</p>';

		return $title . $body;
	}

	/**
	 * Whether any of the three certificate meta keys stores the legacy marker.
	 * `_ffc_form_config` is serialized, but the marker is a literal substring of
	 * the serialized blob, so a single `LIKE` catches config, bg and pool bodies.
	 *
	 * @return bool
	 */
	private static function has_html_refs(): bool {
		global $wpdb;

		$like = '%' . $wpdb->esc_like( LegacyHtmlRefs::MARKER ) . '%';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Memoized in a transient (see should_show); a single LIMIT 1 existence probe.
		$found = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT meta_id FROM {$wpdb->postmeta}
				 WHERE meta_key IN ( '_ffc_form_config', '_ffc_form_bg', '_ffc_template_html' )
				 AND meta_value LIKE %s
				 LIMIT 1",
				$like
			)
		);

		return null !== $found;
	}
}
