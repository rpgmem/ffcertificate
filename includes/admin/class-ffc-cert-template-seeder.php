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
 * @since   6.18.0
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
	 * Version 2 corrected the default templates' background/signature image refs
	 * from the update-fragile `html/` path to `assets/` (#871). Installs seeded
	 * under v1 kept the stale `html/` ref, which the image-rewrite migration then
	 * sideloaded into the Media Library; the bump refreshes those bodies to the
	 * shipped `assets/` source on upgrade.
	 *
	 * Version 3 lifts each default's full-page background out of the body (a baked
	 * `<img>` at z-index 0) into the dedicated `META_BG_IMAGE` field, so a default
	 * uses the same background mechanism as any user template — the field shows the
	 * image, and Load carries it into the form's `_ffc_form_bg`. The bump refreshes
	 * existing installs' bodies (background `<img>` removed) and populates the field.
	 *
	 * Version 4 seeds the two **appointment-receipt** defaults (#945) — Regular and
	 * Custom — tagged `META_KIND = appointment_receipt`, so the self-scheduling
	 * comprovante can be chosen from the pool per mode. Non-destructive: the bump's
	 * restore() adds the two missing receipt defaults without touching user
	 * templates (and re-tags any existing default with its kind).
	 */
	private const SEED_VERSION = 4;

	/**
	 * Directory (relative to the plugin root) holding the default background
	 * images referenced by {@see self::definitions()}.
	 */
	private const BG_DIR = 'assets/img/certificate-defaults/';

	/**
	 * Default seed-HTML directory (certificate defaults). A definition may
	 * override it via its `dir` key (e.g. the appointment-receipt defaults live
	 * under `templates/documents/`).
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

			$html = self::read_seed_file( $def['file'], $def['dir'] ?? self::SEED_DIR );
			if ( '' === $html ) {
				continue;
			}

			self::insert_default( $slug, $def['title'], $html, self::bg_url( $def['bg'] ), $def['kind'] );
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
			$html = self::read_seed_file( $def['file'], $def['dir'] ?? self::SEED_DIR );
			if ( '' === $html ) {
				continue;
			}

			$kind = $def['kind'];

			if ( isset( $existing_map[ $slug ] ) ) {
				// Refresh the shipped body + background field only — leave
				// visibility as the admin set it (#865 decision #13). Also (re)tag
				// the kind so defaults seeded before #945 report the right surface.
				update_post_meta( $existing_map[ $slug ], CertTemplateCpt::META_HTML, $html );
				update_post_meta( $existing_map[ $slug ], CertTemplateCpt::META_BG_IMAGE, self::bg_url( $def['bg'] ) );
				update_post_meta( $existing_map[ $slug ], CertTemplateCpt::META_KIND, $kind );
				continue;
			}

			self::insert_default( $slug, $def['title'], $html, self::bg_url( $def['bg'] ), $kind );
		}
	}

	/**
	 * Insert one shipped default template with its canonical meta.
	 *
	 * @param string $slug  Stable default slug.
	 * @param string $title Template title.
	 * @param string $html  Seed HTML body.
	 * @param string $bg    Background-image URL for the dedicated field. Default ''.
	 * @param string $kind  Template kind (#945). Default `certificate`.
	 * @return void
	 */
	private static function insert_default( string $slug, string $title, string $html, string $bg = '', string $kind = CertTemplateCpt::KIND_CERTIFICATE ): void {
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
		update_post_meta( $id, CertTemplateCpt::META_BG_IMAGE, $bg );
		update_post_meta( $id, CertTemplateCpt::META_KIND, $kind );
	}

	/**
	 * The shipped default templates: stable slug → title + seed filename + the
	 * background-image basename (under {@see self::BG_DIR}) lifted out of the body
	 * into `META_BG_IMAGE` since seed v3. Each entry also carries its `kind`
	 * (#945) and, optionally, a `dir` overriding {@see self::SEED_DIR}.
	 *
	 * @return array<string, array{title:string, file:string, bg:string, kind:string, dir?:string}>
	 */
	private static function definitions(): array {
		return array(
			'default_certificate_1'               => array(
				'title' => __( 'Certificate model 1', 'ffcertificate' ),
				'file'  => 'default_certificate_1.html',
				'bg'    => 'default_background_certificate_1.png',
				'kind'  => CertTemplateCpt::KIND_CERTIFICATE,
			),
			'default_certificate_2'               => array(
				'title' => __( 'Certificate model 2', 'ffcertificate' ),
				'file'  => 'default_certificate_2.html',
				'bg'    => 'default_background_certificate_2.png',
				'kind'  => CertTemplateCpt::KIND_CERTIFICATE,
			),
			'default_certificate_3'               => array(
				'title' => __( 'Certificate model 3', 'ffcertificate' ),
				'file'  => 'default_certificate_3.html',
				'bg'    => 'default_background_certificate_3.png',
				'kind'  => CertTemplateCpt::KIND_CERTIFICATE,
			),
			// Appointment-receipt defaults (#945) — HTML under templates/documents/,
			// no background image, tagged with the appointment_receipt kind.
			'default_appointment_receipt_regular' => array(
				'title' => __( 'Appointment receipt — Regular', 'ffcertificate' ),
				'file'  => 'default_appointment_receipt_1.html',
				'bg'    => '',
				'kind'  => CertTemplateCpt::KIND_APPOINTMENT_RECEIPT,
				'dir'   => 'templates/documents/',
			),
			'default_appointment_receipt_custom'  => array(
				'title' => __( 'Appointment receipt — Custom', 'ffcertificate' ),
				'file'  => 'default_appointment_receipt_custom.html',
				'bg'    => '',
				'kind'  => CertTemplateCpt::KIND_APPOINTMENT_RECEIPT,
				'dir'   => 'templates/documents/',
			),
		);
	}

	/**
	 * Absolute URL of a shipped default background image, or '' when the default
	 * has no background.
	 *
	 * @param string $bg Background basename under {@see self::BG_DIR}.
	 * @return string
	 */
	private static function bg_url( string $bg ): string {
		return '' === $bg ? '' : FFC_PLUGIN_URL . self::BG_DIR . $bg;
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
	 * @param string $dir  Directory (relative to plugin root) holding the file.
	 *                     Default {@see self::SEED_DIR} (certificate defaults).
	 * @return string File contents, or an empty string when unreadable.
	 */
	private static function read_seed_file( string $file, string $dir = self::SEED_DIR ): string {
		$path = FFC_PLUGIN_DIR . $dir . $file;
		if ( ! is_readable( $path ) ) {
			return '';
		}
		$html = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a bundled plugin asset, not a remote/user file.
		return is_string( $html ) ? $html : '';
	}
}
