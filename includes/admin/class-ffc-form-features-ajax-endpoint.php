<?php
/**
 * Form Features AJAX endpoint — inline toggles on the forms list table.
 *
 * Lets the admin flip three per-form feature flags directly from the
 * Forms list (post type `ffc_form`) without opening the editor:
 *
 *   - csv_public_enabled → flat meta `_ffc_csv_public_enabled` ('1'/'').
 *   - quiz_enabled       → nested under array meta `_ffc_form_config`.
 *   - device_enabled     → nested under array meta `_ffc_device_limit`.
 *
 * Security:
 *   - nonce verified against the action name (FFC.request supplies it).
 *   - capability gated PER POST on `edit_post` — a user that can edit
 *     form A but not form B can flip A's flags and gets a 403 on B.
 *   - the `feature` key lives in a hardcoded allowlist; no arbitrary
 *     post-meta writes.
 *
 * @package FreeFormCertificate\Admin
 * @since 6.5.6
 */

declare(strict_types=1);

namespace FreeFormCertificate\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AJAX endpoint for the per-form feature toggles on the Forms list.
 */
class FormFeaturesAjaxEndpoint {

	public const AJAX_ACTION = 'ffc_update_form_feature';

	/**
	 * Register hooks.
	 */
	public static function init(): void {
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( self::class, 'handle' ) );
	}

	/**
	 * Per-feature schema. Each entry describes where to write the
	 * boolean inside the form's post-meta.
	 *
	 *   - 'meta'  — meta key.
	 *   - 'path'  — when set, the meta value is an array and 'path' is
	 *               the leaf key inside it; the toggle stores '1'/''
	 *               at that key. When absent, 'meta' is a flat scalar.
	 *
	 * Add a new toggleable feature with a single-line append.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function feature_map(): array {
		return array(
			'csv_public_enabled' => array(
				'meta' => '_ffc_csv_public_enabled',
			),
			'quiz_enabled'       => array(
				'meta' => '_ffc_form_config',
				'path' => 'quiz_enabled',
			),
			'device_enabled'     => array(
				'meta' => '_ffc_device_limit',
				'path' => 'enabled',
			),
		);
	}

	/**
	 * Handle the AJAX request.
	 */
	public static function handle(): void {
		check_ajax_referer( self::AJAX_ACTION, 'nonce' );

		$form_id = isset( $_POST['form_id'] ) ? absint( $_POST['form_id'] ) : 0;
		if ( $form_id <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'Missing form id.', 'ffcertificate' ) ), 400 );
		}

		// Per-post capability gate — `edit_post` against the specific
		// form. A user with `manage_options` but no `edit_post` for
		// this form is denied; conversely, an author of this form
		// (with the right capability) can flip its flags.
		if ( ! current_user_can( 'edit_post', $form_id ) ) {
			wp_send_json_error(
				array( 'message' => __( 'You do not have permission to edit this form.', 'ffcertificate' ) ),
				403
			);
		}

		// Confirm the post actually is an ffc_form — avoids someone
		// flipping these flags on an unrelated post type.
		if ( 'ffc_form' !== get_post_type( $form_id ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Target is not a form.', 'ffcertificate' ) ),
				400
			);
		}

		$feature = isset( $_POST['feature'] ) ? sanitize_key( wp_unslash( $_POST['feature'] ) ) : '';
		$map     = self::feature_map();
		if ( ! isset( $map[ $feature ] ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Unknown feature.', 'ffcertificate' ) ),
				400
			);
		}

		$raw      = wp_unslash( $_POST['value'] ?? '' );
		$is_truthy = in_array( strtolower( (string) $raw ), array( '1', 'true', 'on', 'yes' ), true );

		$entry = $map[ $feature ];
		$meta  = $entry['meta'];

		if ( isset( $entry['path'] ) ) {
			// Nested write — preserve siblings inside the array meta.
			$current = get_post_meta( $form_id, $meta, true );
			if ( ! is_array( $current ) ) {
				$current = array();
			}
			$current[ $entry['path'] ] = $is_truthy ? '1' : '';
			update_post_meta( $form_id, $meta, $current );
		} else {
			update_post_meta( $form_id, $meta, $is_truthy ? '1' : '' );
		}

		wp_send_json_success(
			array(
				'feature' => $feature,
				'value'   => $is_truthy,
				'form_id' => $form_id,
			)
		);
	}
}
