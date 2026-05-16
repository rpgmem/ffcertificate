<?php
/**
 * Per-form meta auto-save endpoint.
 *
 * Sibling of {@see \FreeFormCertificate\Admin\SettingsAjaxEndpoint}
 * for the form-editor master toggles. The settings endpoint writes
 * into WP options; this one writes into per-post `post_meta`, scoped
 * to a specific `ffc_form` and a hardcoded allowlist of toggle keys.
 *
 * Why a dedicated endpoint:
 *   - Scope: every write is gated on `edit_post` for the *exact*
 *     `post_id` posted, not a global cap. The settings endpoint can
 *     legitimately use `manage_options`; per-form metas can be
 *     editable by lower-privileged users who own a form.
 *   - Allowlist payload: the meta layout for form toggles is mixed —
 *     some are flat top-level metas (`_ffc_csv_public_enabled`),
 *     others are paths into an array meta
 *     (`_ffc_form_config[quiz_enabled]` or
 *     `_ffc_form_config[restrictions][password]`).
 *   - Side effects: the master toggle `_ffc_csv_public_enabled` is
 *     intentionally NOT on the allowlist. Enabling it for the first
 *     time generates a hash + upgrades cpf_mode; that flow stays
 *     gated behind the full save handler. Auto-save covers the
 *     simple toggles where flipping the flag is the entire write.
 *
 * @package FreeFormCertificate\Admin
 * @since   6.5.15
 */

declare(strict_types=1);

namespace FreeFormCertificate\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AJAX endpoint for per-form-toggle auto-save.
 */
class FormMetaAjaxEndpoint {

	/**
	 * AJAX action name (used by both the wp_ajax_ hook and the
	 * nonce that the frontend localises into `ffcFormMetaAutosave`).
	 */
	public const AJAX_ACTION = 'ffc_update_form_meta';

	/**
	 * Register hooks.
	 */
	public static function init(): void {
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( self::class, 'handle' ) );
	}

	/**
	 * Allowlist of writable toggle keys.
	 *
	 * Each entry maps a stable JS-side key to:
	 *   - 'meta'   — the post_meta key that stores the value (or the
	 *                parent array meta when 'path' is non-empty).
	 *   - 'path'   — optional ordered list of nested keys when the
	 *                meta is an array (e.g. _ffc_form_config →
	 *                restrictions → password).
	 *
	 * Adding a new auto-saveable toggle is a single-line append here.
	 *
	 * @return array<string, array{meta: string, path: array<int,string>}>
	 */
	public static function allowlist(): array {
		return array(
			// Public Operator Access — 3 operator-feature sub-toggles.
			// The master `_ffc_csv_public_enabled` is excluded: enabling
			// it for the first time generates a hash and bumps cpf_mode
			// from 'none' to 'audit'; those side effects stay in the
			// full save handler so the page reload always picks them up.
			'csv_public_download_enabled'      => array(
				'meta' => '_ffc_csv_public_download_enabled',
				'path' => array(),
			),
			'csv_public_start_early_enabled'   => array(
				'meta' => '_ffc_csv_public_start_early_enabled',
				'path' => array(),
			),
			'csv_public_extend_end_enabled'    => array(
				'meta' => '_ffc_csv_public_extend_end_enabled',
				'path' => array(),
			),

			// Device Fingerprint Limit.
			'device_limit_enabled'             => array(
				'meta' => '_ffc_device_limit_enabled',
				'path' => array(),
			),

			// Form config — Email + Quiz + 4 Restriction toggles.
			'send_user_email'                  => array(
				'meta' => '_ffc_form_config',
				'path' => array( 'send_user_email' ),
			),
			'quiz_enabled'                     => array(
				'meta' => '_ffc_form_config',
				'path' => array( 'quiz_enabled' ),
			),
			'restriction_password'             => array(
				'meta' => '_ffc_form_config',
				'path' => array( 'restrictions', 'password' ),
			),
			'restriction_allowlist'            => array(
				'meta' => '_ffc_form_config',
				'path' => array( 'restrictions', 'allowlist' ),
			),
			'restriction_denylist'             => array(
				'meta' => '_ffc_form_config',
				'path' => array( 'restrictions', 'denylist' ),
			),
			'restriction_ticket'               => array(
				'meta' => '_ffc_form_config',
				'path' => array( 'restrictions', 'ticket' ),
			),

			// Geofence — 2 master + 1 nested.
			'geofence_datetime_enabled'        => array(
				'meta' => '_ffc_geofence_config',
				'path' => array( 'datetime_enabled' ),
			),
			'geofence_geo_enabled'             => array(
				'meta' => '_ffc_geofence_config',
				'path' => array( 'geo_enabled' ),
			),
			'geofence_geo_ip_areas_permissive' => array(
				'meta' => '_ffc_geofence_config',
				'path' => array( 'geo_ip_areas_permissive' ),
			),
		);
	}

	/**
	 * Handle the AJAX request.
	 *
	 * Responds with `wp_send_json_success` on success and
	 * `wp_send_json_error` on any guard failure. Failure status codes
	 * match the convention used by SettingsAjaxEndpoint (400/403).
	 */
	public static function handle(): void {
		check_ajax_referer( self::AJAX_ACTION, 'nonce' );

		$post_id = isset( $_POST['post_id'] ) ? absint( wp_unslash( $_POST['post_id'] ) ) : 0;
		if ( $post_id <= 0 || 'ffc_form' !== get_post_type( $post_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid form.', 'ffcertificate' ) ), 400 );
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to edit this form.', 'ffcertificate' ) ), 403 );
		}

		$key = isset( $_POST['key'] ) ? sanitize_key( wp_unslash( $_POST['key'] ) ) : '';
		if ( '' === $key ) {
			wp_send_json_error( array( 'message' => __( 'Missing toggle key.', 'ffcertificate' ) ), 400 );
		}

		$allowlist = self::allowlist();
		if ( ! isset( $allowlist[ $key ] ) ) {
			wp_send_json_error( array( 'message' => __( 'This toggle is not exposed for incremental updates.', 'ffcertificate' ) ), 403 );
		}

		// Normalise to '1' / '0' string — the rest of the plugin reads
		// these toggles via `'1' === (string) $meta`, so we persist the
		// canonical string form. `sanitize_value` returns a native bool;
		// cast it to the canonical string here.
		$raw_value = isset( $_POST['value'] ) ? wp_unslash( $_POST['value'] ) : '';
		$value     = SettingsAjaxEndpoint::sanitize_value( $raw_value, 'bool' ) ? '1' : '0';

		$entry = $allowlist[ $key ];
		$path  = $entry['path'];

		if ( empty( $path ) ) {
			update_post_meta( $post_id, $entry['meta'], $value );
		} else {
			$meta_value = get_post_meta( $post_id, $entry['meta'], true );
			if ( ! is_array( $meta_value ) ) {
				$meta_value = array();
			}
			self::set_nested( $meta_value, $path, $value );
			update_post_meta( $post_id, $entry['meta'], $meta_value );
		}

		wp_send_json_success(
			array(
				'key'   => $key,
				'value' => $value,
			)
		);
	}

	/**
	 * Write $value into $arr at the location described by $path,
	 * creating intermediate associative arrays as needed.
	 *
	 * @param array<string,mixed> $arr   Reference to the parent array.
	 * @param array<int,string>   $path  Ordered list of keys to walk.
	 * @param mixed               $value Value to set at the leaf.
	 */
	private static function set_nested( array &$arr, array $path, $value ): void {
		$cursor = &$arr;
		$last   = array_pop( $path );
		foreach ( $path as $segment ) {
			if ( ! isset( $cursor[ $segment ] ) || ! is_array( $cursor[ $segment ] ) ) {
				$cursor[ $segment ] = array();
			}
			$cursor = &$cursor[ $segment ];
		}
		$cursor[ $last ] = $value;
	}
}
