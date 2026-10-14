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
 *   - Side effects: a toggle whose first enable does more than flip a
 *     flag names an `on_enable` callback, run after the write when the
 *     stored value goes from anything else to '1'. The master toggle
 *     `_ffc_csv_public_enabled` is the one case: its first enable
 *     generates the access hash and upgrades the CPF mode, the same
 *     work the full save handler does (#1755).
 *
 * @package FreeFormCertificate\Admin
 * @since   6.6.0
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
	 *   - 'type'    — optional; 'enum' with an 'options' list for a radio
	 *                 group (#1745), or 'map' with a 'map' of '1' / '0' to
	 *                 the two stored values, for a toggle that stands for a
	 *                 two-value setting (#1755). Absent means a '1' / '0'
	 *                 toggle.
	 *   - 'on_enable' — optional static method (`Class::method`) taking the
	 *                 post id, run when a toggle is switched on from any
	 *                 other stored state; what it returns joins the response.
	 *
	 * @return array<string, array{meta: string, path: array<int,string>, type?: string, options?: array<int, string>, map?: array<int, string>, on_enable?: string}>
	 */
	public static function allowlist(): array {
		return array(
			// Public Operator Access. Enabling the master for the first
			// time generates the hash and bumps cpf_mode from 'none' to
			// 'audit', so it carries the same work the save handler does.
			'csv_public_enabled'                  => array(
				'meta'      => '_ffc_csv_public_enabled',
				'path'      => array(),
				'on_enable' => FormPublicCsvActions::class . '::on_enable',
			),
			'csv_public_cpf_mode'                 => array(
				'meta'    => '_ffc_csv_public_cpf_mode',
				'path'    => array(),
				'type'    => 'enum',
				'options' => FormPublicCsvActions::CPF_MODES,
			),
			'csv_public_preview_enabled'          => array(
				'meta' => '_ffc_csv_public_preview_enabled',
				'path' => array(),
			),
			'csv_public_download_enabled'         => array(
				'meta' => '_ffc_csv_public_download_enabled',
				'path' => array(),
			),
			'csv_public_start_early_enabled'      => array(
				'meta' => '_ffc_csv_public_start_early_enabled',
				'path' => array(),
			),
			'csv_public_extend_end_enabled'       => array(
				'meta' => '_ffc_csv_public_extend_end_enabled',
				'path' => array(),
			),

			// Device Fingerprint Limit.
			'device_limit_enabled'                => array(
				'meta' => '_ffc_device_limit_enabled',
				'path' => array(),
			),

			// Form config — Email + Quiz + 4 Restriction toggles.
			'send_user_email'                     => array(
				'meta' => '_ffc_form_config',
				'path' => array( 'send_user_email' ),
			),
			// Admin-notification opt-in (#649). Wired with a
			// `data-ffc-autosave-form-key` attribute in the email metabox but
			// originally omitted here, so flipping it returned 403 "key not
			// allowlisted" and never persisted on autosave (it did survive a
			// full form save via the save handler) — same class of bug as
			// `geofence_schedule_exception_enabled` below.
			'send_admin_email'                    => array(
				'meta' => '_ffc_form_config',
				'path' => array( 'send_admin_email' ),
			),
			'quiz_enabled'                        => array(
				'meta' => '_ffc_form_config',
				'path' => array( 'quiz_enabled' ),
			),
			'quiz_show_score'                     => array(
				'meta' => '_ffc_form_config',
				'path' => array( 'quiz_show_score' ),
			),
			'quiz_show_correct'                   => array(
				'meta' => '_ffc_form_config',
				'path' => array( 'quiz_show_correct' ),
			),
			// Off / Moderate / Strict (#1745) -- a radio group, not a toggle.
			'quiz_reset_on_leave'                 => array(
				'meta'    => '_ffc_form_config',
				'path'    => array( 'quiz_reset_on_leave' ),
				'type'    => 'enum',
				'options' => \FreeFormCertificate\Core\QuizLeaveLevel::LEVELS,
			),
			'restriction_password'                => array(
				'meta' => '_ffc_form_config',
				'path' => array( 'restrictions', 'password' ),
			),
			'restriction_allowlist'               => array(
				'meta' => '_ffc_form_config',
				'path' => array( 'restrictions', 'allowlist' ),
			),
			'restriction_denylist'                => array(
				'meta' => '_ffc_form_config',
				'path' => array( 'restrictions', 'denylist' ),
			),
			'restriction_ticket'                  => array(
				'meta' => '_ffc_form_config',
				'path' => array( 'restrictions', 'ticket' ),
			),

			// Geofence — 3 master + 1 nested.
			'geofence_datetime_enabled'           => array(
				'meta' => '_ffc_geofence_config',
				'path' => array( 'datetime_enabled' ),
			),
			'geofence_geo_enabled'                => array(
				'meta' => '_ffc_geofence_config',
				'path' => array( 'geo_enabled' ),
			),
			'geofence_geo_gps_enabled'            => array(
				'meta' => '_ffc_geofence_config',
				'path' => array( 'geo_gps_enabled' ),
			),
			'geofence_geo_ip_enabled'             => array(
				'meta' => '_ffc_geofence_config',
				'path' => array( 'geo_ip_enabled' ),
			),
			// On = the operator modal opens on the current time.
			'geofence_schedule_default_mode'      => array(
				'meta' => '_ffc_geofence_config',
				'path' => array( 'schedule_default_mode' ),
				'type' => 'map',
				'map'  => array(
					1 => 'now',
					0 => 'manual',
				),
			),
			'geofence_geo_ip_areas_permissive'    => array(
				'meta' => '_ffc_geofence_config',
				'path' => array( 'geo_ip_areas_permissive' ),
			),
			// The Schedule Exception master toggle was wired in #366 with a
			// `data-ffc-autosave-form-key` attribute but never added to this
			// allowlist, so flipping it returned 403 "key not allowlisted"
			// from the AJAX endpoint and never persisted on autosave. The
			// state did survive a full form save (the geofence save handler
			// picks it up from the POST namespace), so the bug only showed
			// up if the operator changed the toggle and walked away.
			'geofence_schedule_exception_enabled' => array(
				'meta' => '_ffc_geofence_config',
				'path' => array( 'schedule_exception_enabled' ),
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

		$post_id = \FreeFormCertificate\Core\RequestInput::get_post_int( 'post_id', 0 );
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

		// A toggle normalises to the '1' / '0' string -- the rest of the
		// plugin reads these via `'1' === (string) $meta` -- and
		// `sanitize_value` returns a native bool, cast below.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitised below by SettingsAjaxEndpoint::sanitize_value() against the allowlisted type.
		$raw_value = isset( $_POST['value'] ) ? wp_unslash( $_POST['value'] ) : '';
		$entry     = $allowlist[ $key ];

		// A toggle is a bool stored as '1' / '0'; a radio group (#1745)
		// carries a closed list and stores the chosen member as is.
		$type = $entry['type'] ?? 'bool';
		if ( 'enum' === $type ) {
			$value = SettingsAjaxEndpoint::sanitize_value( $raw_value, 'enum', $entry );
			if ( null === $value ) {
				wp_send_json_error( array( 'message' => __( 'That value is not one of the choices.', 'ffcertificate' ) ), 400 );
			}
		} elseif ( 'map' === $type ) {
			$map   = $entry['map'] ?? array();
			$value = (string) ( $map[ SettingsAjaxEndpoint::sanitize_value( $raw_value, 'bool' ) ? 1 : 0 ] ?? '' );
		} else {
			$value = SettingsAjaxEndpoint::sanitize_value( $raw_value, 'bool' ) ? '1' : '0';
		}

		$path     = $entry['path'];
		$previous = '';

		if ( empty( $path ) ) {
			$stored   = get_post_meta( $post_id, $entry['meta'], true );
			$previous = is_scalar( $stored ) ? (string) $stored : '';
			update_post_meta( $post_id, $entry['meta'], $value );
		} else {
			$meta_value = get_post_meta( $post_id, $entry['meta'], true );
			if ( ! is_array( $meta_value ) ) {
				$meta_value = array();
			}
			self::set_nested( $meta_value, $path, $value );
			update_post_meta( $post_id, $entry['meta'], $meta_value );
		}

		$response  = array(
			'key'   => $key,
			'value' => $value,
		);
		$on_enable = $entry['on_enable'] ?? '';
		if ( '1' === $value && '1' !== $previous && is_callable( $on_enable ) ) {
			$response = array_merge( $response, (array) call_user_func( $on_enable, $post_id ) );
		}

		wp_send_json_success( $response );
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
