<?php
/**
 * CPT
 * Manages the Custom Post Type for forms, including registration and duplication logic.
 *
 * V2.9.2: OPTIMIZED to use FFC_Utils functions
 *
 * @package FreeFormCertificate\Admin
 * @version 3.3.0 - Added strict types and type hints
 * @version 3.2.0 - Migrated to namespace (Phase 2)
 */

declare(strict_types=1);

namespace FreeFormCertificate\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * C P T.
 */
class CPT {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'register_form_cpt' ) );
		add_filter( 'post_row_actions', array( $this, 'add_duplicate_link' ), 10, 2 );
		add_action( 'admin_action_ffc_duplicate_form', array( $this, 'handle_form_duplication' ) );
		add_filter( 'views_edit-ffc_form', array( $this, 'translate_views' ) );
	}

	/**
	 * Registers the 'ffc_form' Custom Post Type
	 */
	public function register_form_cpt(): void {
		$labels = array(
			'name'               => _x( 'Forms', 'Post Type General Name', 'ffcertificate' ),
			'singular_name'      => _x( 'Form', 'Post Type Singular Name', 'ffcertificate' ),
			'menu_name'          => __( 'Certificate', 'ffcertificate' ),
			'name_admin_bar'     => __( 'FFC Form', 'ffcertificate' ),
			'add_new'            => __( 'Add New Form', 'ffcertificate' ),
			'add_new_item'       => __( 'Add New Form', 'ffcertificate' ),
			'new_item'           => __( 'New Form', 'ffcertificate' ),
			'edit_item'          => __( 'Edit Form', 'ffcertificate' ),
			'view_item'          => __( 'View Form', 'ffcertificate' ),
			'all_items'          => __( 'All Forms', 'ffcertificate' ),
			'search_items'       => __( 'Search Forms', 'ffcertificate' ),
			'not_found'          => __( 'No forms found.', 'ffcertificate' ),
			'not_found_in_trash' => __( 'No forms found in Trash.', 'ffcertificate' ),
		);

		$args = array(
			'labels'          => $labels,
			'public'          => false,
			'show_ui'         => true,
			'show_in_menu'    => true,
			'query_var'       => true,
			'capability_type' => 'post',
			'has_archive'     => false,
			'hierarchical'    => false,
			'menu_icon'       => 'dashicons-feedback',
			'supports'        => array( 'title' ),
			'rewrite'         => array( 'slug' => 'ffc-form' ),
		);

		register_post_type( 'ffc_form', $args );
	}

	/**
	 * Adds a "Duplicate" link to the post row actions
	 *
	 * @param array<string, string> $actions Row action links.
	 * @param object                $post Post object.
	 * @phpstan-param \WP_Post $post
	 * @return array<string, string>
	 */
	public function add_duplicate_link( array $actions, object $post ): array {
		if ( 'ffc_form' !== $post->post_type ) {
			return $actions;
		}

		if ( ! \FreeFormCertificate\Core\Utils::current_user_can_manage() ) {
			return $actions;
		}

		$url = wp_nonce_url(
			admin_url( 'admin.php?action=ffc_duplicate_form&post=' . $post->ID ),
			'ffc_duplicate_form_nonce'
		);

		$actions['duplicate'] = '<a href="' . esc_url( $url ) . '" title="' . esc_attr__( 'Duplicate this form', 'ffcertificate' ) . '">' . esc_html__( 'Duplicate', 'ffcertificate' ) . '</a>';

		return $actions;
	}

	/**
	 * Handles the duplication process when the action is triggered
	 */
	public function handle_form_duplication(): void {
		if ( ! \FreeFormCertificate\Core\Utils::current_user_can_manage() ) {
			\FreeFormCertificate\Core\Debug::log_admin(
				'Unauthorized form duplication attempt',
				array(
					'user_id' => get_current_user_id(),
					'ip'      => \FreeFormCertificate\Core\Utils::get_user_ip(),
				)
			);
			wp_die( esc_html__( 'You do not have permission to duplicate this post.', 'ffcertificate' ) );
		}

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce verified immediately below via check_admin_referer.
		$post_id = ( isset( $_GET['post'] ) ? absint( wp_unslash( $_GET['post'] ) ) : 0 );

		check_admin_referer( 'ffc_duplicate_form_nonce' );

		$post = get_post( $post_id );

		if ( ! $post || 'ffc_form' !== $post->post_type ) {
			\FreeFormCertificate\Core\Debug::log_admin(
				'Invalid form duplication request',
				array(
					'post_id' => $post_id,
					'user_id' => get_current_user_id(),
				)
			);
			wp_die( esc_html__( 'Invalid post.', 'ffcertificate' ) );
		}

		$original_title = $post->post_title;
		/* translators: %s: original post title */
		$new_title = sprintf( __( '%s (Copy)', 'ffcertificate' ), $original_title );

		// Create new post.
		$new_post_args = array(
			'post_title'  => $new_title,
			'post_status' => 'draft',
			'post_type'   => $post->post_type,
			'post_author' => get_current_user_id(),
		);

		$new_post_id = wp_insert_post( $new_post_args, true );

		if ( is_wp_error( $new_post_id ) ) {
			\FreeFormCertificate\Core\Debug::log_admin(
				'Form duplication failed',
				array(
					'error'            => $new_post_id->get_error_message(),
					'original_post_id' => $post_id,
				)
			);
			wp_die( esc_html( $new_post_id->get_error_message() ) );
		}

		// Copy metadata. Three buckets:
		// - Core form data (fields, config, bg, geofence) — always copy.
		// - Public CSV Download config — copy enabled flag + sub-settings,
		// but NEVER the hash (security: a shared hash would let one URL
		// unlock both forms), the counter (each duplicate starts fresh),
		// or the audit log (history belongs to the original).
		// - Device Fingerprint per-form override — copy in full.
		$config_metas = array(
			// Core.
			'_ffc_form_fields',
			'_ffc_form_config',
			'_ffc_form_bg',
			'_ffc_geofence_config',
			// Public CSV Download (enabled flag + sub-feature toggles + sub-settings).
			// 6.6.10 — the four `*_enabled` sub-feature toggles below were
			// missing from the pre-6.6.10 list, so a duplicated form lost
			// the admin's choice on download / preview / start-early /
			// extend-end. Empty meta reads as the FormEditorSaveHandler
			// default ('1' for the first three, '0' for extend-end), so
			// the silent loss flipped the duplicate's behaviour either
			// direction depending on which toggle the admin had touched.
			'_ffc_csv_public_enabled',
			'_ffc_csv_public_download_enabled',
			'_ffc_csv_public_preview_enabled',
			'_ffc_csv_public_start_early_enabled',
			'_ffc_csv_public_extend_end_enabled',
			'_ffc_csv_public_limit',
			'_ffc_csv_public_cpf_mode',
			'_ffc_csv_public_cpf_whitelist',
			// Device Fingerprint per-form override.
			'_ffc_device_limit_enabled',
			'_ffc_device_limit_max',
			'_ffc_device_match_threshold',
			'_ffc_device_limit_message',
		);

		$metadata_copied = array();

		foreach ( $config_metas as $meta_key ) {
			$value = get_post_meta( $post_id, $meta_key, true );
			if ( '' === $value || array() === $value || null === $value ) {
				continue;
			}
			update_post_meta( $new_post_id, $meta_key, $value );
			$metadata_copied[] = $meta_key;
		}

		// Hash, counter, and audit log are intentionally NOT copied. The
		// next save with _ffc_csv_public_enabled === '1' regenerates the
		// hash automatically (see FormEditorSaveHandler::save_form_data).

		\FreeFormCertificate\Core\Debug::log_admin(
			'Form duplicated successfully',
			array(
				'original_post_id' => $post_id,
				'new_post_id'      => $new_post_id,
				'original_title'   => \FreeFormCertificate\Core\Utils::truncate( $original_title, 50 ),
				'new_title'        => \FreeFormCertificate\Core\Utils::truncate( $new_title, 50 ),
				'metadata_copied'  => implode( ', ', $metadata_copied ),
				'user_id'          => get_current_user_id(),
			)
		);

		// Redirect to forms list.
		wp_safe_redirect( admin_url( 'edit.php?post_type=ffc_form' ) );
		exit;
	}

	/**
	 * Translate view links that WordPress core may not translate
	 *
	 * @param array<string, string> $views View links.
	 * @return array<string, string>
	 */
	public function translate_views( array $views ): array {
		$map = array(
			'all'     => __( 'All', 'ffcertificate' ),
			'publish' => __( 'Published', 'ffcertificate' ),
			'draft'   => __( 'Draft', 'ffcertificate' ),
			'pending' => __( 'Pending', 'ffcertificate' ),
			'trash'   => __( 'Trash', 'ffcertificate' ),
		);

		foreach ( $views as $key => &$html ) {
			if ( isset( $map[ $key ] ) ) {
				// Replace the English label while keeping the HTML structure (<a>, <span class="count">).
				$html = preg_replace(
					'/(<a[^>]*>)\s*[^<]+(<span)/',
					'$1' . esc_html( $map[ $key ] ) . ' $2',
					$html
				) ?? $html;
			}
		}
		unset( $html );

		return $views;
	}
}
