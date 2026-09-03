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
 * @since   6.18.0
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
	 * List the visible templates of one kind for a "Load" / selection control:
	 * plugin defaults first, then user templates.
	 *
	 * @param string $kind Which surface's templates to list (#945). Defaults to
	 *                     `certificate`; an absent `_ffc_template_kind` counts as
	 *                     `certificate` so pre-#945 templates still appear.
	 * @return array<int, array{id:int, label:string, is_default:bool}>
	 */
	public static function list_for_editor( string $kind = CertTemplateCpt::KIND_CERTIFICATE ): array {
		// Visible flag is always required; the kind clause treats a missing meta as
		// `certificate` (retrocompat) and matches the exact value for other kinds.
		$meta_query = array(
			array(
				'key'   => CertTemplateCpt::META_VISIBLE,
				'value' => '1',
			),
		);
		if ( CertTemplateCpt::KIND_CERTIFICATE === $kind ) {
			$meta_query[] = array(
				'relation' => 'OR',
				array(
					'key'   => CertTemplateCpt::META_KIND,
					'value' => CertTemplateCpt::KIND_CERTIFICATE,
				),
				array(
					'key'     => CertTemplateCpt::META_KIND,
					'compare' => 'NOT EXISTS',
				),
			);
		} else {
			$meta_query[] = array(
				'key'   => CertTemplateCpt::META_KIND,
				'value' => $kind,
			);
		}

		$posts = get_posts(
			array(
				'post_type'        => CertTemplateCpt::POST_TYPE,
				'post_status'      => 'publish',
				'numberposts'      => -1,
				'orderby'          => 'title',
				'order'            => 'ASC',
				'meta_query'       => $meta_query,
				'suppress_filters' => false,
			)
		);

		$defaults = array();
		$mine     = array();

		foreach ( $posts as $post ) {
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
	 * Resolve a template's stored background-image URL by post id.
	 *
	 * @param int $id Template post id.
	 * @return string The background-image URL, or an empty string when the id is
	 *                not a template of this pool or none is set.
	 */
	public static function get_bg_image( int $id ): string {
		if ( $id <= 0 ) {
			return '';
		}
		$post = get_post( $id );
		if ( ! $post instanceof \WP_Post || CertTemplateCpt::POST_TYPE !== $post->post_type ) {
			return '';
		}
		return (string) get_post_meta( $id, CertTemplateCpt::META_BG_IMAGE, true );
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

	/**
	 * The template's kind (#945). An absent meta counts as `certificate` so
	 * pre-#945 templates report the original kind.
	 *
	 * @param int $id Template post id.
	 * @return string One of the `CertTemplateCpt::KIND_*` values.
	 */
	public static function get_kind( int $id ): string {
		if ( $id <= 0 ) {
			return CertTemplateCpt::KIND_CERTIFICATE;
		}
		$kind = (string) get_post_meta( $id, CertTemplateCpt::META_KIND, true );
		return '' !== $kind ? $kind : CertTemplateCpt::KIND_CERTIFICATE;
	}
}
