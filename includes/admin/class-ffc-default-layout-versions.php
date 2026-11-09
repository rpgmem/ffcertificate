<?php
/**
 * DefaultLayoutVersions
 *
 * Tells a form whose layout is an earlier body of a default model that a newer
 * one exists (#2577).
 *
 * @package FreeFormCertificate\Admin
 * @since   6.45.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Earlier bodies of the default certificate models, and the notice that offers
 * the current one.
 *
 * Loading a default copies its HTML into the form, so the seeder's refresh of
 * the pool never reaches the copy. Rewriting the copy is not an option either:
 * certificates are regenerated from the current layout (#2241), so a silent
 * rewrite would change certificates already issued. The form is told instead,
 * and the administrator decides.
 *
 * A layout matches only when it equals an archived body after the same
 * `wp_kses()` the form save applies, with whitespace collapsed, so an edited
 * copy is never offered an overwrite. The archive holds the bodies as released
 * under `templates/certificate-defaults/previous/`, named `<slug>-<version>.html`;
 * it starts at 6.42.0, where the current six models were drawn (#1956), because
 * offering a model of the set before them as an "update" would swap the design.
 */
class DefaultLayoutVersions {

	/**
	 * Directory, relative to the plugin root, holding the archived bodies.
	 */
	public const PREVIOUS_DIR = 'templates/certificate-defaults/previous/';

	/**
	 * Fingerprints of the archived bodies, per request.
	 *
	 * @var array<string, string>|null Fingerprint => default slug.
	 */
	private static ?array $fingerprints = null;

	/**
	 * Forget the memoised fingerprints (tests).
	 */
	public static function reset(): void {
		self::$fingerprints = null;
	}

	/**
	 * The slug of the default whose earlier body this layout is, or '' when it
	 * is none.
	 *
	 * @param string $layout The form's layout HTML.
	 * @return string
	 */
	public static function earlier_default_of( string $layout ): string {
		if ( '' === trim( $layout ) ) {
			return '';
		}
		$fingerprints = self::fingerprints();
		$own          = self::fingerprint( $layout );
		return $fingerprints[ $own ] ?? '';
	}

	/**
	 * Print the notice with its Update layout button, when the layout is an
	 * earlier body of a default the pool still holds.
	 *
	 * @param string $layout The form's layout HTML.
	 */
	public static function render_notice( string $layout ): void {
		$slug = self::earlier_default_of( $layout );
		if ( '' === $slug ) {
			return;
		}
		$id = self::pool_id( $slug );
		if ( $id <= 0 ) {
			return;
		}
		$title = (string) get_the_title( $id );

		$button = \FreeFormCertificate\Core\Button::html(
			array(
				'label'    => __( 'Update layout', 'ffcertificate' ),
				'category' => 'caution',
				'icon'     => 'template-load',
				'id'       => 'ffc_update_default_layout_btn',
				'attrs'    => array(
					'data-template-id'   => (string) $id,
					'data-template-name' => $title,
				),
			)
		);

		\FreeFormCertificate\Core\AdminNotice::callout(
			'info',
			'<p>' . esc_html(
				sprintf(
					/* translators: %s: name of a default certificate model */
					__( 'This layout is an earlier version of %s. The current version of that model may draw differently, for instance with other fonts. Updating replaces the HTML, the background and the page colour in the editor; nothing changes until you save the form.', 'ffcertificate' ),
					$title
				)
			) . '</p><p>' . $button . '</p>',
			array( 'classes' => array( 'ffc-layout-update-notice', 'inline' ) )
		);
	}

	/**
	 * Fingerprint of a layout: the save's `wp_kses()`, whitespace collapsed.
	 *
	 * @param string $html Layout HTML.
	 * @return string
	 */
	private static function fingerprint( string $html ): string {
		$clean = wp_kses( $html, \FreeFormCertificate\Core\HtmlPolicy::get_allowed_html_tags() );
		return md5( trim( (string) preg_replace( '/\s+/', ' ', $clean ) ) );
	}

	/**
	 * Fingerprint => slug for every archived body.
	 *
	 * @return array<string, string>
	 */
	private static function fingerprints(): array {
		if ( null !== self::$fingerprints ) {
			return self::$fingerprints;
		}
		self::$fingerprints = array();
		foreach ( self::archived() as $file => $slug ) {
			$html = \FreeFormCertificate\Core\Utils::read_file_contents( $file );
			if ( '' !== $html ) {
				self::$fingerprints[ self::fingerprint( $html ) ] = $slug;
			}
		}
		return self::$fingerprints;
	}

	/**
	 * Archived body files and the default each belongs to.
	 *
	 * @return array<string, string> Absolute path => default slug.
	 */
	public static function archived(): array {
		$files = glob( FFC_PLUGIN_DIR . self::PREVIOUS_DIR . '*.html' );
		$out   = array();
		foreach ( is_array( $files ) ? $files : array() as $file ) {
			if ( 1 === preg_match( '/^(default_[a-z0-9_]+)-\d+(?:\.\d+)+\.html$/', basename( $file ), $m ) ) {
				$out[ $file ] = $m[1];
			}
		}
		return $out;
	}

	/**
	 * Pool post id of a shipped default, or 0 when the pool no longer holds it.
	 *
	 * @param string $slug Default slug.
	 * @return int
	 */
	private static function pool_id( string $slug ): int {
		$ids = get_posts(
			array(
				'post_type'        => CertTemplateCpt::POST_TYPE,
				'post_status'      => 'any',
				'numberposts'      => 1,
				'fields'           => 'ids',
				'meta_key'         => CertTemplateCpt::META_DEFAULT_SLUG, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- one lookup on the form editor screen.
				'meta_value'       => $slug, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- one lookup on the form editor screen.
				'suppress_filters' => false,
			)
		);
		return isset( $ids[0] ) ? (int) $ids[0] : 0;
	}
}
