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
	 * Current seed version — bump when adding a new shipped default OR when a
	 * shipped default's body changes and existing installs must pick it up.
	 *
	 * v2: the default templates' background/signature image refs were corrected
	 * from the update-fragile `html/` path to `assets/` (#871). Installs seeded
	 * under v1 kept the stale `html/` ref, which the image-rewrite migration then
	 * sideloaded into the Media Library; bumping to v2 refreshes those bodies to
	 * the shipped `assets/` source on upgrade.
	 */
	private const SEED_VERSION = 2;

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
		$applied = (int) get_option( self::SEED_FLAG, 0 );
		if ( $applied >= self::SEED_VERSION ) {
			return;
		}

		if ( $applied > 0 ) {
			// A version bump on an already-seeded install: refresh existing
			// default bodies to the current shipped source (and add any newly
			// shipped default) so corrections like the #871 assets/ image-path
			// fix reach installs seeded under an older version. restore() is
			// non-destructive — user templates are never touched and each
			// default's admin-chosen visibility is preserved.
			self::restore();
		} else {
			// First-ever seed: create the shipped defaults.
			self::seed();
		}

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

			self::insert_default( $slug, $def['title'], $html );
		}
	}

	/**
	 * "Restore defaults" (#865 decision #13) — non-destructive re-seed:
	 * re-adds any missing shipped default and refreshes the HTML body of the
	 * defaults that already exist to the current shipped source. User templates
	 * (no default slug) are never touched, and an existing default's visibility
	 * choice is preserved (only its body is refreshed).
	 *
	 * @return void
	 */
	public static function restore(): void {
		$existing_map = self::existing_default_map();

		foreach ( self::definitions() as $slug => $def ) {
			$html = self::read_seed_file( $def['file'] );
			if ( '' === $html ) {
				continue;
			}

			if ( isset( $existing_map[ $slug ] ) ) {
				// Refresh the shipped body only — leave visibility as the admin set it.
				update_post_meta( $existing_map[ $slug ], CertTemplateCpt::META_HTML, $html );
				continue;
			}

			self::insert_default( $slug, $def['title'], $html );
		}
	}

	/**
	 * Insert one shipped default template with its canonical meta.
	 *
	 * @param string $slug  Stable default slug.
	 * @param string $title Template title.
	 * @param string $html  Seed HTML body.
	 * @return void
	 */
	private static function insert_default( string $slug, string $title, string $html ): void {
		$id = wp_insert_post(
			array(
				'post_type'   => CertTemplateCpt::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => $title,
			),
			true
		);
		// wp_insert_post( …, true ) returns WP_Error on failure, a positive
		// post id on success — is_int() rejects the error case.
		if ( ! is_int( $id ) ) {
			return;
		}

		update_post_meta( $id, CertTemplateCpt::META_IS_DEFAULT, '1' );
		update_post_meta( $id, CertTemplateCpt::META_DEFAULT_SLUG, $slug );
		update_post_meta( $id, CertTemplateCpt::META_VISIBLE, '1' );
		update_post_meta( $id, CertTemplateCpt::META_HTML, $html );
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
		return array_keys( self::existing_default_map() );
	}

	/**
	 * Map of shipped-default slug → post id for the defaults present in the pool.
	 *
	 * @return array<string, int>
	 */
	private static function existing_default_map(): array {
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

		$map = array();
		foreach ( (array) $ids as $id ) {
			$slug = (string) get_post_meta( (int) $id, CertTemplateCpt::META_DEFAULT_SLUG, true );
			if ( '' !== $slug ) {
				$map[ $slug ] = (int) $id;
			}
		}
		return $map;
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
