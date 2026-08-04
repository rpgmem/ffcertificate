<?php
/**
 * CertTemplateReader
 *
 * Read-side of the certificate-template pool (issue #865). Supplies the form
 * editor's "Load" flow with the visible templates (grouped defaults-first) and
 * resolves a template's HTML by id. Write-side (create / seed) lives in
 * {@see CertTemplateSeeder} and the management UI.
 *
 * @package FreeFormCertificate\Admin
 * @since   6.20.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read-side queries for the certificate-template pool.
 */
class CertTemplateReader {

	/**
	 * List the templates that should appear in the form editor's "Load" control:
	 * only the visible ones, plugin defaults first then user templates.
	 *
	 * @return array<int, array{id:int, label:string, is_default:bool}>
	 */
	public static function list_for_editor(): array {
		$posts = get_posts(
			array(
				'post_type'        => CertTemplateCpt::POST_TYPE,
				'post_status'      => 'publish',
				'numberposts'      => -1,
				'orderby'          => 'title',
				'order'            => 'ASC',
				'meta_key'         => CertTemplateCpt::META_VISIBLE, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Small, admin-only pool; the visible flag is the intended filter.
				'meta_value'       => '1', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- See above.
				'suppress_filters' => false,
			)
		);

		$defaults = array();
		$mine     = array();

		foreach ( (array) $posts as $post ) {
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}
			$is_default = '1' === (string) get_post_meta( $post->ID, CertTemplateCpt::META_IS_DEFAULT, true );
			$entry      = array(
				'id'         => (int) $post->ID,
				'label'      => (string) $post->post_title,
				'is_default' => $is_default,
			);
			if ( $is_default ) {
				$defaults[] = $entry;
			} else {
				$mine[] = $entry;
			}
		}

		return array_merge( $defaults, $mine );
	}

	/**
	 * Resolve a template's stored HTML body by post id.
	 *
	 * @param int $id Template post id.
	 * @return string The template HTML, or an empty string when the id is not a
	 *                template of this pool.
	 */
	public static function get_html( int $id ): string {
		if ( $id <= 0 ) {
			return '';
		}
		$post = get_post( $id );
		if ( ! $post instanceof \WP_Post || CertTemplateCpt::POST_TYPE !== $post->post_type ) {
			return '';
		}
		return (string) get_post_meta( $id, CertTemplateCpt::META_HTML, true );
	}

	/**
	 * Whether the given id is a plugin-shipped default template.
	 *
	 * @param int $id Template post id.
	 * @return bool
	 */
	public static function is_default( int $id ): bool {
		if ( $id <= 0 ) {
			return false;
		}
		return '1' === (string) get_post_meta( $id, CertTemplateCpt::META_IS_DEFAULT, true );
	}
}
