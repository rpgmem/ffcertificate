<?php
/**
 * Says which pages activation created (#2343).
 *
 * Activation creates the certificate verification page at /valid and the
 * member dashboard at /dashboard, publishes them, and used to say nothing:
 * an administrator found two pages in the site's menu with no idea where
 * they came from. The notice names each page with a link to edit it, and
 * reappears only when activation creates another one.
 *
 * @package FreeFormCertificate\Admin
 * @since   6.44.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Admin;

use FreeFormCertificate\Core\ManagedPages;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Names the pages the plugin created.
 */
class CreatedPagesNotice extends AbstractDismissibleNotice {

	const OPTION_DISMISSED = 'ffc_created_pages_notice_dismissed';
	const AJAX_ACTION      = 'ffc_dismiss_created_pages';

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
	 * Stable class for styling / test hooks.
	 */
	protected static function extra_class(): string {
		return 'ffc-created-pages-notice';
	}

	/**
	 * The ids of the pages named, so a page created later shows again.
	 */
	protected static function dismiss_signature(): string {
		return implode( ',', ManagedPages::created() );
	}

	/**
	 * Only while a page activation created still exists.
	 */
	protected static function should_show(): bool {
		return array() !== ManagedPages::created();
	}

	/**
	 * The inner notice HTML (already escaped).
	 */
	protected static function notice_message(): string {
		$links = array();
		foreach ( ManagedPages::created() as $page_id ) {
			$title = get_the_title( $page_id );
			$url   = get_edit_post_link( $page_id, 'raw' );
			$path  = (string) wp_parse_url( (string) get_permalink( $page_id ), PHP_URL_PATH );
			$label = esc_html( '' !== $title ? $title : '#' . $page_id ) . ( '' !== $path ? ' <code>' . esc_html( $path ) . '</code>' : '' );

			$links[] = is_string( $url ) && '' !== $url ? '<a href="' . esc_url( $url ) . '">' . $label . '</a>' : $label;
		}

		return '<p><strong>'
			. esc_html__( 'Free Form Certificate created these pages', 'ffcertificate' )
			. '</strong> — '
			. implode( ', ', $links )
			. '. '
			. esc_html__( 'Each one holds the shortcode that draws it, so it can be renamed, moved or added to a menu. Deleting the plugin with its data removes a page only while its content is still that shortcode alone.', 'ffcertificate' )
			. '</p>';
	}
}
