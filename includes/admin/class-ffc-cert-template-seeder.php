<?php
/**
 * CertTemplateSeeder
 *
 * Seeds the plugin's default certificate templates into the pool
 * (issue #865). The canonical source is code — the HTML files under
 * `templates/certificate-defaults/` — so a versioned "Restore defaults" can
 * re-seed at any time. Seeding is **non-destructive**: a default is (re)created
 * only when its slug is missing, so user edits to their own templates (and any
 * hidden/edited defaults) are never clobbered.
 *
 * Runs once per seed version, guarded by the `ffc_cert_templates_seeded_version`
 * option; the option is bumped when new shipped defaults are added.
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
 * One-shot (versioned) seeder for the default certificate templates.
 */
class CertTemplateSeeder {

	/**
	 * Option flag storing the highest seed version already applied.
	 */
	private const SEED_FLAG = 'ffc_cert_templates_seeded_version';

	/**
	 * Current seed version — bump when adding a new shipped default.
	 */
	private const SEED_VERSION = 1;

	/**
	 * Directory (relative to the plugin root) holding the seed HTML files.
	 */
	private const SEED_DIR = 'templates/certificate-defaults/';

	/**
	 * Run the seeder once per seed version.
	 *
	 * @return void
	 */
	public static function maybe_seed(): void {
		if ( (int) get_option( self::SEED_FLAG, 0 ) >= self::SEED_VERSION ) {
			return;
		}
		self::seed();
		update_option( self::SEED_FLAG, self::SEED_VERSION );
	}

	/**
	 * Create any missing default templates (non-destructive). Also the target
	 * of a future "Restore defaults" action.
	 *
	 * @return void
	 */
	public static function seed(): void {
		$existing = self::existing_default_slugs();

		foreach ( self::definitions() as $slug => $def ) {
			if ( in_array( $slug, $existing, true ) ) {
				continue; // Non-destructive: never overwrite an existing default.
			}

			$html = self::read_seed_file( $def['file'] );
			if ( '' === $html ) {
				continue;
			}

			$id = wp_insert_post(
				array(
					'post_type'   => CertTemplateCpt::POST_TYPE,
					'post_status' => 'publish',
					'post_title'  => $def['title'],
				),
				true
			);
			if ( ! is_int( $id ) || $id <= 0 ) {
				continue;
			}

			update_post_meta( $id, CertTemplateCpt::META_IS_DEFAULT, '1' );
			update_post_meta( $id, CertTemplateCpt::META_DEFAULT_SLUG, $slug );
			update_post_meta( $id, CertTemplateCpt::META_VISIBLE, '1' );
			update_post_meta( $id, CertTemplateCpt::META_HTML, $html );
		}
	}

	/**
	 * The shipped default templates: stable slug → title + seed filename.
	 *
	 * @return array<string, array{title:string, file:string}>
	 */
	private static function definitions(): array {
		return array(
			'default_certificate_1' => array(
				'title' => __( 'Certificate model 1', 'ffcertificate' ),
				'file'  => 'default_certificate_1.html',
			),
			'default_certificate_2' => array(
				'title' => __( 'Certificate model 2', 'ffcertificate' ),
				'file'  => 'default_certificate_2.html',
			),
			'default_certificate_3' => array(
				'title' => __( 'Certificate model 3', 'ffcertificate' ),
				'file'  => 'default_certificate_3.html',
			),
		);
	}

	/**
	 * Slugs of the default templates already present in the pool.
	 *
	 * @return array<int, string>
	 */
	private static function existing_default_slugs(): array {
		$ids = get_posts(
			array(
				'post_type'        => CertTemplateCpt::POST_TYPE,
				'post_status'      => 'any',
				'numberposts'      => -1,
				'fields'           => 'ids',
				'meta_key'         => CertTemplateCpt::META_DEFAULT_SLUG, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- One-shot seed guard over a tiny admin-only pool.
				'suppress_filters' => false,
			)
		);

		$slugs = array();
		foreach ( (array) $ids as $id ) {
			$slug = (string) get_post_meta( (int) $id, CertTemplateCpt::META_DEFAULT_SLUG, true );
			if ( '' !== $slug ) {
				$slugs[] = $slug;
			}
		}
		return $slugs;
	}

	/**
	 * Read a seed HTML file from the plugin's seed directory.
	 *
	 * @param string $file Basename of the seed file.
	 * @return string File contents, or an empty string when unreadable.
	 */
	private static function read_seed_file( string $file ): string {
		$path = FFC_PLUGIN_DIR . self::SEED_DIR . $file;
		if ( ! is_readable( $path ) ) {
			return '';
		}
		$html = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a bundled plugin asset, not a remote/user file.
		return is_string( $html ) ? $html : '';
	}
}
