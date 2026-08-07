<?php
/**
 * CertTemplateWriter
 *
 * Write side of the certificate-template pool (issue #865). Complements the
 * read-only {@see CertTemplateReader} and the one-shot {@see CertTemplateSeeder}:
 * this class owns the runtime mutations the management UI and the editor's
 * "Save as model" flow need — creating a user template, toggling a template's
 * visibility in the editor "Load" list, and rewriting a template's HTML body.
 *
 * The pool is a `wp_posts` CPT (not a custom table), so writes go through the
 * WordPress post API rather than the StaticRepository pattern. Every mutation
 * is fenced to the `ffc_cert_template` post type; authorization (nonce +
 * capability) is the caller's responsibility (the AJAX / admin-action handler),
 * exactly as WordPress core keeps `wp_insert_post()` auth-agnostic.
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
 * Write-side operations for the certificate-template pool.
 */
class CertTemplateWriter {

	/**
	 * Create a new user template (never a default) in the pool.
	 *
	 * @param string $title    Template title (already sanitized by the caller).
	 * @param string $html     Template HTML body.
	 * @param bool   $visible  Whether it appears in the editor "Load" list. Default true.
	 * @param string $bg_image Background-image URL (already sanitized by the caller). Default ''.
	 * @return int The new post id, or 0 on failure.
	 */
	public static function create( string $title, string $html, bool $visible = true, string $bg_image = '' ): int {
		$id = wp_insert_post(
			array(
				'post_type'   => CertTemplateCpt::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => $title,
			),
			true
		);
		// wp_insert_post( …, true ) returns WP_Error on failure, a positive id on success.
		if ( ! is_int( $id ) ) {
			return 0;
		}

		update_post_meta( $id, CertTemplateCpt::META_HTML, $html );
		update_post_meta( $id, CertTemplateCpt::META_VISIBLE, $visible ? '1' : '0' );
		update_post_meta( $id, CertTemplateCpt::META_BG_IMAGE, $bg_image );
		// User templates are never plugin-shipped defaults.
		update_post_meta( $id, CertTemplateCpt::META_IS_DEFAULT, '0' );

		return $id;
	}

	/**
	 * Toggle whether a template appears in the editor "Load" list.
	 *
	 * @param int  $id      Template post id.
	 * @param bool $visible Target visibility.
	 * @return bool True when applied, false when the id is not a pool template.
	 */
	public static function set_visibility( int $id, bool $visible ): bool {
		if ( ! self::is_pool_post( $id ) ) {
			return false;
		}
		update_post_meta( $id, CertTemplateCpt::META_VISIBLE, $visible ? '1' : '0' );
		return true;
	}

	/**
	 * Replace a template's stored HTML body.
	 *
	 * @param int    $id   Template post id.
	 * @param string $html New HTML body.
	 * @return bool True when applied, false when the id is not a pool template.
	 */
	public static function update_html( int $id, string $html ): bool {
		if ( ! self::is_pool_post( $id ) ) {
			return false;
		}
		update_post_meta( $id, CertTemplateCpt::META_HTML, $html );
		return true;
	}

	/**
	 * Set a template's background-image URL.
	 *
	 * @param int    $id       Template post id.
	 * @param string $bg_image Background-image URL (already sanitized by the caller).
	 * @return bool True when applied, false when the id is not a pool template.
	 */
	public static function set_bg_image( int $id, string $bg_image ): bool {
		if ( ! self::is_pool_post( $id ) ) {
			return false;
		}
		update_post_meta( $id, CertTemplateCpt::META_BG_IMAGE, $bg_image );
		return true;
	}

	/**
	 * Whether the given id is a post of the certificate-template pool.
	 *
	 * @param int $id Candidate post id.
	 * @return bool
	 */
	private static function is_pool_post( int $id ): bool {
		if ( $id <= 0 ) {
			return false;
		}
		$post = get_post( $id );
		return $post instanceof \WP_Post && CertTemplateCpt::POST_TYPE === $post->post_type;
	}
}
