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
 * option; the option is bumped when new shipped defaults are added — but only
 * once the run has actually left a default in the pool, so a seed that read
 * nothing retries instead of recording itself as done (see
 * {@see self::pool_has_defaults()}).
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
	 *
	 * Version 5 seeds the **record** default (#951 phase 2) — tagged
	 * `META_KIND = record`, so the reregistration record can be chosen from the
	 * pool. Same non-destructive bump: adds the one missing record default.
	 *
	 * Version 6 replaces the three certificate defaults with a revised set and adds
	 * three more (#1956). The bump refreshes the body and background of the existing
	 * slugs and adds the new ones; no user template is touched.
	 *
	 * Version 7 moves the footer of three defaults up so the QR code and the
	 * validation link fit inside the A4 page; the bump refreshes those bodies.
	 *
	 * Version 8 sizes model 5's QR code to its 170 px box and moves model 6's
	 * signatures and footer up, so its QR code stays inside the frame (#2076).
	 *
	 * Version 9 refreshes the record default: the layout of #2227 never reached
	 * an install that picked the pool's default, because no bump carried it, and
	 * each e-mail now takes a full row (#2235).
	 *
	 * Version 10 adds {{schedule}} to the six certificate defaults: the default
	 * Required data asks for it in every layout, so a form on a shipped model
	 * could not be saved until an administrator added the tag (#2524).
	 *
	 * Version 11 puts an installable font in front of every stack of the nine
	 * defaults (#2574): Cinzel, Great Vibes, EB Garamond, Montserrat, Arimo and
	 * Cousine, each followed by the system font the default named before, so a
	 * site that installs none renders as it did. The name of models 1, 3 and 5
	 * takes `ffc-fit` (#2570), so a long one shrinks instead of wrapping.
	 *
	 * Version 12 adds visual versions of the six certificate models (#2586):
	 * each is a layout under `templates/certificate-defaults/visual/`, seeded
	 * as a visual template whose HTML is rendered from it, so duplicating one
	 * starts a visual template a user edits on the page.
	 *
	 * Version 13 gives the last line of visual models 1, 2, 3, 4 and 6 room
	 * for its QR code (#2603): the box was 12 px tall and clipped the code,
	 * the verification URL and the authentication code off the certificate.
	 *
	 * Version 14 turns the example text of the twelve certificate models into
	 * tokens (#2624): the place and the workload are template fields whose
	 * defaults are the old examples, the completion date is the form's event
	 * date and the issue date the submission's, and the two signature columns
	 * print signers 1 and 2 (#2620). The bump refreshes the bodies and stores
	 * the fields. A form that loaded one of these bodies keeps its copy with
	 * the defaults filled in, which is what the archive under `previous/`
	 * must hold once this version is released.
	 */
	private const SEED_VERSION = 14;

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
	 * The flag is written only when the pool actually ends up holding a shipped
	 * default — see {@see self::pool_has_defaults()} for why an unconditional
	 * write is a trap.
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

		if ( ! self::pool_has_defaults() ) {
			\FreeFormCertificate\Core\Debug::log_admin(
				'CertTemplateSeeder: seed run produced no default template; leaving the seed flag unwritten so the next admin request retries.',
				array(
					'seed_version' => self::SEED_VERSION,
					'applied'      => $applied,
					'seed_dir'     => FFC_PLUGIN_DIR . self::SEED_DIR,
				)
			);
			return;
		}

		update_option( self::SEED_FLAG, self::SEED_VERSION );
	}

	/**
	 * Whether the pool holds at least one shipped default after a seed run.
	 *
	 * This is the guard that keeps the seeder honest, and it exists because the
	 * whole path fails silently: {@see \FreeFormCertificate\Core\Utils::read_file_contents()}
	 * returns '' for a file it cannot read, {@see self::seed()} then `continue`s
	 * past that definition without a word, and an unconditional
	 * `update_option( SEED_FLAG, … )` would record the run as applied. On the
	 * next request `maybe_seed()` returns early, so a first seed that read
	 * nothing — a deploy that landed mid-rsync, a permissions problem, a
	 * packaging slip that dropped the seed files — leaves the pool permanently
	 * empty and never retries.
	 *
	 * That was not a cosmetic failure: an empty pool was exactly the condition
	 * under which
	 * {@see \FreeFormCertificate\Admin\AdminAssetsManager::discover_layout_templates()}
	 * fell back to the legacy `html/` glob, so that fallback's stated exit
	 * condition — "removed once the pool seeds on every install" — could not be
	 * met while this hole existed (#865 phase-4). The retry closed the hole in
	 * 6.22.0 and the glob went in 6.23.0 (#1087); this paragraph is why the
	 * retry is here, in the past tense because the fallback it names is gone.
	 *
	 * Deliberately narrow: it asks whether the pool is *empty*, not whether every
	 * definition seeded. A partial seed still populates the picker and keeps the
	 * fallback dormant, so recording it as applied is correct — and blocking the
	 * flag on completeness would re-run restore()'s meta writes on every single
	 * admin request for as long as one file stayed unreadable. Visibility is not
	 * consulted either: hiding a default is the admin's choice, not a seed
	 * failure.
	 *
	 * @return bool
	 */
	private static function pool_has_defaults(): bool {
		return array() !== self::existing_default_map();
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

			$source = self::read_source( $def );
			if ( null === $source ) {
				continue;
			}

			$id = self::insert_default( $slug, $def['title'], $source['html'], self::bg_url( $def['bg'] ), $def['kind'] );
			self::store_layout( $id, $source['layout'] );
			self::store_fields( $id, $def );
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
			$source = self::read_source( $def );
			if ( null === $source ) {
				continue;
			}

			$html = $source['html'];
			$kind = $def['kind'];

			if ( isset( $existing_map[ $slug ] ) ) {
				// Refresh the shipped body + background field only — leave
				// visibility as the admin set it (#865 decision #13). Also (re)tag
				// the kind so defaults seeded before #945 report the right surface.
				update_post_meta( $existing_map[ $slug ], CertTemplateCpt::META_HTML, $html );
				update_post_meta( $existing_map[ $slug ], CertTemplateCpt::META_BG_IMAGE, self::bg_url( $def['bg'] ) );
				update_post_meta( $existing_map[ $slug ], CertTemplateCpt::META_KIND, $kind );
				self::store_layout( $existing_map[ $slug ], $source['layout'] );
				self::store_fields( $existing_map[ $slug ], $def );
				continue;
			}

			$id = self::insert_default( $slug, $def['title'], $html, self::bg_url( $def['bg'] ), $kind );
			self::store_layout( $id, $source['layout'] );
			self::store_fields( $id, $def );
		}
	}

	/**
	 * A definition's body: the HTML file, or, for a visual default (#2586),
	 * the layout file and the HTML rendered from it. Null when the file is
	 * missing or the layout does not validate.
	 *
	 * @param array{title:string, file:string, bg:string, kind:string, dir?:string, layout?:bool} $def Definition.
	 * @return array{html: string, layout: string}|null `layout` is '' for an HTML default.
	 */
	private static function read_source( array $def ): ?array {
		$raw = self::read_seed_file( $def['file'], $def['dir'] ?? self::SEED_DIR );
		if ( '' === $raw ) {
			return null;
		}
		if ( empty( $def['layout'] ) ) {
			return array(
				'html'   => $raw,
				'layout' => '',
			);
		}
		$layout = \FreeFormCertificate\Core\VisualLayout::validate( $raw );
		if ( is_wp_error( $layout ) ) {
			\FreeFormCertificate\Core\Debug::log_admin( 'CertTemplateSeeder: visual default ' . $def['file'] . ' does not validate: ' . $layout->get_error_message() );
			return null;
		}
		return array(
			'html'   => \FreeFormCertificate\Core\VisualLayout::render( $layout ),
			'layout' => \FreeFormCertificate\Core\VisualLayout::to_json( $layout ),
		);
	}

	/**
	 * Mark a default as visual and store its layout; nothing for an HTML one.
	 *
	 * @param int    $id     Template post id (0 when the insert failed).
	 * @param string $layout Layout JSON, or '' for an HTML default.
	 * @return void
	 */
	private static function store_layout( int $id, string $layout ): void {
		if ( $id <= 0 || '' === $layout ) {
			return;
		}
		update_post_meta( $id, \FreeFormCertificate\Core\VisualLayout::META_EDITOR, \FreeFormCertificate\Core\VisualLayout::EDITOR_VISUAL );
		\FreeFormCertificate\Core\VisualLayout::store_json( $id, $layout );
	}

	/**
	 * Store the template fields a default declares (#2624). Only the
	 * certificate models declare any, and a default never lost a field it
	 * once declared, so there is nothing to remove.
	 *
	 * @param int                  $id  Template post id (0 when the insert failed).
	 * @param array<string, mixed> $def Definition.
	 * @return void
	 */
	private static function store_fields( int $id, array $def ): void {
		$fields = is_array( $def['fields'] ?? null ) ? $def['fields'] : array();
		if ( $id <= 0 || array() === $fields ) {
			return;
		}
		update_post_meta( $id, \FreeFormCertificate\Core\CertificateTemplateLink::META_FIELDS, $fields );
	}

	/**
	 * The template fields the certificate models declare (#2624): the place
	 * and the workload, whose defaults are the examples the bodies printed
	 * before they became tokens.
	 *
	 * @return array<int, array{key: string, label: string, type: string, required: bool, default: string}>
	 */
	private static function certificate_model_fields(): array {
		return array(
			array(
				'key'      => 'place',
				'label'    => __( 'Place', 'ffcertificate' ),
				'type'     => 'text',
				'required' => false,
				'default'  => 'San Diego',
			),
			array(
				'key'      => 'workload',
				'label'    => __( 'Workload', 'ffcertificate' ),
				'type'     => 'text',
				'required' => false,
				'default'  => '4 hours',
			),
		);
	}

	/**
	 * Insert one shipped default template with its canonical meta.
	 *
	 * @param string $slug  Stable default slug.
	 * @param string $title Template title.
	 * @param string $html  Seed HTML body.
	 * @param string $bg    Background-image URL for the dedicated field. Default ''.
	 * @param string $kind  Template kind (#945). Default `certificate`.
	 * @return int The new post id, or 0 when the insert failed.
	 */
	private static function insert_default( string $slug, string $title, string $html, string $bg = '', string $kind = CertTemplateCpt::KIND_CERTIFICATE ): int {
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
			return 0;
		}

		update_post_meta( $id, CertTemplateCpt::META_IS_DEFAULT, '1' );
		update_post_meta( $id, CertTemplateCpt::META_DEFAULT_SLUG, $slug );
		update_post_meta( $id, CertTemplateCpt::META_VISIBLE, '1' );
		update_post_meta( $id, CertTemplateCpt::META_HTML, $html );
		update_post_meta( $id, CertTemplateCpt::META_BG_IMAGE, $bg );
		update_post_meta( $id, CertTemplateCpt::META_KIND, $kind );
		return $id;
	}

	/**
	 * The shipped default templates: stable slug → title + seed filename + the
	 * background-image basename (under {@see self::BG_DIR}) lifted out of the body
	 * into `META_BG_IMAGE` since seed v3. Each entry also carries its `kind`
	 * (#945) and, optionally, a `dir` overriding {@see self::SEED_DIR}.
	 *
	 * A visual default (#2586) sets `layout` and points `file` at its layout
	 * JSON; its HTML is rendered from the layout.
	 *
	 * @return array<string, array{title:string, file:string, bg:string, kind:string, dir?:string, layout?:bool, fields?:array<int, array<string, mixed>>}>
	 */
	private static function definitions(): array {
		$visual = array();
		for ( $n = 1; $n <= 6; $n++ ) {
			$visual[ 'default_visual_certificate_' . $n ] = array(
				/* translators: %d: model number, 1 to 6 */
				'title'  => sprintf( __( 'Visual certificate model %d', 'ffcertificate' ), $n ),
				'file'   => 'visual_certificate_' . $n . '.json',
				// The visual models draw what the HTML models draw, background included.
				'bg'     => $n <= 2 ? 'default_background_certificate_' . $n . '.png' : '',
				'kind'   => CertTemplateCpt::KIND_CERTIFICATE,
				'dir'    => 'templates/certificate-defaults/visual/',
				'layout' => true,
				'fields' => self::certificate_model_fields(),
			);
		}

		$html = array(
			'default_certificate_1'               => array(
				'title'  => __( 'Certificate model 1', 'ffcertificate' ),
				'file'   => 'default_certificate_1.html',
				'bg'     => 'default_background_certificate_1.png',
				'kind'   => CertTemplateCpt::KIND_CERTIFICATE,
				'fields' => self::certificate_model_fields(),
			),
			'default_certificate_2'               => array(
				'title'  => __( 'Certificate model 2', 'ffcertificate' ),
				'file'   => 'default_certificate_2.html',
				'bg'     => 'default_background_certificate_2.png',
				'kind'   => CertTemplateCpt::KIND_CERTIFICATE,
				'fields' => self::certificate_model_fields(),
			),
			'default_certificate_3'               => array(
				'title'  => __( 'Certificate model 3', 'ffcertificate' ),
				'file'   => 'default_certificate_3.html',
				'bg'     => '',
				'kind'   => CertTemplateCpt::KIND_CERTIFICATE,
				'fields' => self::certificate_model_fields(),
			),
			'default_certificate_4'               => array(
				'title'  => __( 'Certificate model 4', 'ffcertificate' ),
				'file'   => 'default_certificate_4.html',
				'bg'     => '',
				'kind'   => CertTemplateCpt::KIND_CERTIFICATE,
				'fields' => self::certificate_model_fields(),
			),
			'default_certificate_5'               => array(
				'title'  => __( 'Certificate model 5', 'ffcertificate' ),
				'file'   => 'default_certificate_5.html',
				'bg'     => '',
				'kind'   => CertTemplateCpt::KIND_CERTIFICATE,
				'fields' => self::certificate_model_fields(),
			),
			'default_certificate_6'               => array(
				'title'  => __( 'Certificate model 6', 'ffcertificate' ),
				'file'   => 'default_certificate_6.html',
				'bg'     => '',
				'kind'   => CertTemplateCpt::KIND_CERTIFICATE,
				'fields' => self::certificate_model_fields(),
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
			// Reregistration record default (#951 phase 2) — HTML under
			// templates/documents/, no background image, tagged `record`.
			//
			// The KEY stays `default_ficha` (#1264). It is written to post meta
			// as `META_DEFAULT_SLUG` and read back to build the "already
			// seeded?" map, so renaming it makes every existing install miss its
			// own default and insert a SECOND one. Same stored-value exception
			// as `CertTemplateCpt::KIND_RECORD = 'ficha'`.
			'default_ficha'                       => array(
				'title' => __( 'Record', 'ffcertificate' ),
				'file'  => 'default_ficha_template.html',
				'bg'    => '',
				'kind'  => CertTemplateCpt::KIND_RECORD,
				'dir'   => 'templates/documents/',
			),
		);

		return $html + $visual;
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
				'meta_key'         => CertTemplateCpt::META_DEFAULT_SLUG,
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
		return \FreeFormCertificate\Core\Utils::read_file_contents( FFC_PLUGIN_DIR . $dir . $file );
	}
}
