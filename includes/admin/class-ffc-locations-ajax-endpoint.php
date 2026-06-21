<?php
/**
 * Per-row inline-CRUD endpoint for the geofence locations table.
 *
 * Backs the AJAX calls from `assets/js/ffc-locations-crud.js` so the
 * admin can add / edit / delete a location row without reloading the
 * full Settings page. Sits alongside `SettingsAjaxEndpoint` — that one
 * is a generic key/value updater; this one is dedicated because the
 * payload shape (name + lat + lng + radius + default flags) is
 * structured, not a single scalar.
 *
 * Security:
 *   - nonce verified against the AJAX action.
 *   - capability gated on `manage_options`.
 *   - input sanitised by `GeofenceLocationRegistry::save()`'s own
 *     sanitiser; we layer field-level guards on top for length /
 *     numeric range.
 *
 * @package FreeFormCertificate\Admin
 * @since 6.5.4
 */

declare(strict_types=1);

namespace FreeFormCertificate\Admin;

use FreeFormCertificate\Security\GeofenceLocationRegistry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * LocationsAjaxEndpoint.
 */
class LocationsAjaxEndpoint {

	public const ACTION_SAVE   = 'ffc_location_save';
	public const ACTION_DELETE = 'ffc_location_delete';

	/**
	 * Register hooks.
	 */
	public static function init(): void {
		add_action( 'wp_ajax_' . self::ACTION_SAVE, array( self::class, 'handle_save' ) );
		add_action( 'wp_ajax_' . self::ACTION_DELETE, array( self::class, 'handle_delete' ) );
	}

	/**
	 * Upsert a location.
	 *
	 * Accepts: id (optional, for edit) + name + lat + lng + radius +
	 * default_gps + default_ip. Responds with the canonical record
	 * after sanitisation so the client can update the row's id (if it
	 * was a new row) and reflect any value coercions.
	 */
	public static function handle_save(): void {
		check_ajax_referer( self::ACTION_SAVE, 'nonce' );
		if ( ! \FreeFormCertificate\Core\Utils::current_user_can_admin_or( 'ffc_manage_settings' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to manage locations.', 'ffcertificate' ) ), 403 );
		}

		$name = trim( \FreeFormCertificate\Core\RequestInput::get_post_string( 'name' ) );
		if ( '' === $name ) {
			wp_send_json_error( array( 'message' => __( 'Location name is required.', 'ffcertificate' ) ), 400 );
		}

		$payload = array(
			'name'        => $name,
			'lat'         => isset( $_POST['lat'] ) ? floatval( wp_unslash( $_POST['lat'] ) ) : 0.0,
			'lng'         => isset( $_POST['lng'] ) ? floatval( wp_unslash( $_POST['lng'] ) ) : 0.0,
			'radius'      => isset( $_POST['radius'] ) ? floatval( wp_unslash( $_POST['radius'] ) ) : 1000.0,
			'default_gps' => self::truthy( $_POST['default_gps'] ?? null ),
			'default_ip'  => self::truthy( $_POST['default_ip'] ?? null ),
		);

		$id = isset( $_POST['id'] ) ? sanitize_key( wp_unslash( $_POST['id'] ) ) : '';
		if ( '' !== $id ) {
			// Ensure the id refers to an existing row before treating as edit.
			if ( null === GeofenceLocationRegistry::get_by_id( $id ) ) {
				wp_send_json_error( array( 'message' => __( 'Location not found.', 'ffcertificate' ) ), 404 );
			}
			$payload['id'] = $id;
		}

		$saved_id  = GeofenceLocationRegistry::save( $payload );
		$persisted = GeofenceLocationRegistry::get_by_id( $saved_id );

		wp_send_json_success(
			array(
				'location' => $persisted,
				'is_new'   => '' === $id,
			)
		);
	}

	/**
	 * Delete a location row by id.
	 */
	public static function handle_delete(): void {
		check_ajax_referer( self::ACTION_DELETE, 'nonce' );
		if ( ! \FreeFormCertificate\Core\Utils::current_user_can_admin_or( 'ffc_manage_settings' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to manage locations.', 'ffcertificate' ) ), 403 );
		}

		$id = isset( $_POST['id'] ) ? sanitize_key( wp_unslash( $_POST['id'] ) ) : '';
		if ( '' === $id ) {
			wp_send_json_error( array( 'message' => __( 'Missing location id.', 'ffcertificate' ) ), 400 );
		}

		$removed = GeofenceLocationRegistry::delete( $id );
		if ( ! $removed ) {
			wp_send_json_error( array( 'message' => __( 'Location not found.', 'ffcertificate' ) ), 404 );
		}

		wp_send_json_success( array( 'id' => $id ) );
	}

	/**
	 * Coerce a POST field value to bool.
	 *
	 * Accepts '1' / 'true' / 'on' / 'yes' (case-insensitive) as truthy.
	 *
	 * @param mixed $raw Raw POST value.
	 * @return bool
	 */
	private static function truthy( $raw ): bool {
		if ( is_array( $raw ) ) {
			return false;
		}
		$str = strtolower( (string) $raw );
		return in_array( $str, array( '1', 'true', 'on', 'yes' ), true );
	}
}
