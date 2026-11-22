<?php
/**
 * CertificateTemplateLink
 *
 * A form that references a certificate template instead of holding a copy of
 * it (#2581).
 *
 * @package FreeFormCertificate\Core
 * @since   6.45.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Where a form's certificate layout comes from.
 *
 * Data model:
 * - the form's `_ffc_layout_template_id` meta names the template it
 *   references; absent or 0 means the form prints its own copy,
 *   `_ffc_form_config['pdf_layout']`, as before;
 * - the template declares its fields in `_ffc_template_fields`
 *   ({@see TemplateFields});
 * - the form keeps the values it fills in `_ffc_template_values`, a map of
 *   key to value.
 *
 * A referencing form keeps its own copy untouched, so switching back, or a
 * template that goes to the bin, prints that copy rather than nothing.
 *
 * The template's post type and meta keys are repeated here rather than read
 * from `Admin\CertTemplateCpt`, because Core does not depend on Admin;
 * `CertificateTemplateLinkTest` holds the two spellings together.
 */
final class CertificateTemplateLink {

	/** Form meta: the referenced template's id. */
	public const META_TEMPLATE_ID = '_ffc_layout_template_id';

	/** Form meta: the values the form fills for the template's fields. */
	public const META_VALUES = '_ffc_template_values';

	/** Template meta: the fields the template declares. */
	public const META_FIELDS = '_ffc_template_fields';

	/** The template post type (`CertTemplateCpt::POST_TYPE`). */
	public const TEMPLATE_POST_TYPE = 'ffc_cert_template';

	/** Template meta keys (`CertTemplateCpt::META_*`). */
	public const TEMPLATE_META_HTML     = '_ffc_template_html';
	public const TEMPLATE_META_BG_IMAGE = '_ffc_template_bg_image';
	public const TEMPLATE_META_BG_COLOR = '_ffc_template_bg_color';
	public const TEMPLATE_META_KIND     = '_ffc_template_kind';

	/**
	 * The certificate template a form references, or 0 when it prints its own
	 * copy or the template it names is gone, binned or not a certificate.
	 *
	 * @param int $form_id Form post id.
	 */
	public static function template_id( int $form_id ): int {
		$id = self::stored_template_id( $form_id );
		return self::is_usable_template( $id ) ? $id : 0;
	}

	/**
	 * The template id a form stores, usable or not. The editor reads it to say
	 * that a referenced template went away.
	 *
	 * @param int $form_id Form post id.
	 */
	public static function stored_template_id( int $form_id ): int {
		$raw = $form_id > 0 ? get_post_meta( $form_id, self::META_TEMPLATE_ID, true ) : '';
		return is_numeric( $raw ) ? max( 0, (int) $raw ) : 0;
	}

	/**
	 * Whether an id names a certificate template a form can reference.
	 *
	 * @param int $id Template post id.
	 */
	public static function is_usable_template( int $id ): bool {
		if ( $id <= 0 ) {
			return false;
		}
		$post = get_post( $id );
		if ( ! $post instanceof \WP_Post || self::TEMPLATE_POST_TYPE !== $post->post_type ) {
			return false;
		}
		if ( in_array( $post->post_status, array( 'trash', 'auto-draft' ), true ) ) {
			return false;
		}
		$kind = (string) get_post_meta( $id, self::TEMPLATE_META_KIND, true );
		return '' === $kind || 'certificate' === $kind;
	}

	/**
	 * The fields a template declares.
	 *
	 * @param int $template_id Template post id.
	 * @return array<int, array{key: string, label: string, type: string, required: bool, default: string}>
	 */
	public static function fields( int $template_id ): array {
		return $template_id > 0 ? TemplateFields::sanitize( get_post_meta( $template_id, self::META_FIELDS, true ) ) : array();
	}

	/**
	 * The values a form fills for its template's fields.
	 *
	 * @param int $form_id Form post id.
	 * @return array<string, string>
	 */
	public static function values( int $form_id ): array {
		$raw    = $form_id > 0 ? get_post_meta( $form_id, self::META_VALUES, true ) : array();
		$values = array();
		foreach ( is_array( $raw ) ? $raw : array() as $key => $value ) {
			if ( is_string( $key ) && is_scalar( $value ) ) {
				$values[ $key ] = (string) $value;
			}
		}
		return $values;
	}

	/**
	 * A template's layout with a form's values in its `{{model:…}}` tokens.
	 *
	 * @param int                   $template_id Template post id.
	 * @param array<string, string> $values      Values; empty ones print the default.
	 */
	public static function layout( int $template_id, array $values ): string {
		$html = (string) get_post_meta( $template_id, self::TEMPLATE_META_HTML, true );
		return TemplateFields::replace_tokens( $html, self::fields( $template_id ), $values );
	}

	/**
	 * A template's background image URL.
	 *
	 * @param int $template_id Template post id.
	 */
	public static function bg_image( int $template_id ): string {
		return esc_url_raw( (string) get_post_meta( $template_id, self::TEMPLATE_META_BG_IMAGE, true ) );
	}

	/**
	 * A template's page colour.
	 *
	 * @param int $template_id Template post id.
	 */
	public static function bg_color( int $template_id ): string {
		return FormBackground::normalize_color( (string) get_post_meta( $template_id, self::TEMPLATE_META_BG_COLOR, true ) );
	}

	/**
	 * The form config the certificate prints from: the stored one, with the
	 * layout and the background of the referenced template when there is one.
	 *
	 * @param int $form_id Form post id.
	 * @return array<string, mixed>
	 */
	public static function form_config( int $form_id ): array {
		$config = get_post_meta( $form_id, '_ffc_form_config', true );
		$config = is_array( $config ) ? $config : array();

		$template_id = self::template_id( $form_id );
		if ( 0 === $template_id ) {
			return $config;
		}
		$config['pdf_layout'] = self::layout( $template_id, self::values( $form_id ) );
		$config['bg_image']   = self::bg_image( $template_id );
		$config['bg_color']   = self::bg_color( $template_id );
		// A visual template carries its own page orientation (#2584).
		$visual = VisualLayout::is_visual( $template_id ) ? VisualLayout::stored( $template_id ) : null;
		if ( null !== $visual ) {
			$config['orientation'] = $visual['page']['orientation'];
		}
		return $config;
	}

	/**
	 * Forms that reference a template, binned ones excluded.
	 *
	 * @param int $template_id Template post id.
	 * @return array<int, int>
	 */
	public static function forms_using( int $template_id ): array {
		if ( $template_id <= 0 ) {
			return array();
		}
		$ids = get_posts(
			array(
				'post_type'        => 'ffc_form',
				'post_status'      => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'numberposts'      => -1,
				'fields'           => 'ids',
				'meta_key'         => self::META_TEMPLATE_ID, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- run only on the template and form edit screens.
				'meta_value'       => (string) $template_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- the template and form edit screens only.
				'suppress_filters' => false,
			)
		);
		return array_values( array_map( 'intval', array_filter( $ids, 'is_numeric' ) ) );
	}
}
