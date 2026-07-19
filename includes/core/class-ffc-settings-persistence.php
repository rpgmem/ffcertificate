<?php
/**
 * Settings Persistence
 *
 * The single settings-write chokepoint (rpgmem/ffcertificate#711). Every
 * settings save routes its cross-cutting concerns — CSRF nonce verification,
 * capability authorization and per-field sanitisation — through
 * {@see self::save()}, so authorization is enforced in one auditable place
 * instead of being re-implemented in each of the plugin's separate save paths
 * (the classic-POST `SettingsSaveHandler`, the `SettingsActionHandler`
 * maintenance actions and the `SettingsAjaxEndpoint` autosave). Mirrors the
 * `Core\EmailService` transport chokepoint.
 *
 * The engine is **capability-driven, not role-driven**: the caller declares the
 * capability slug(s) that gate a write and the engine resolves them via
 * {@see Capabilities::current_user_can_admin_or()} (`manage_options` OR the
 * cap). Roles never enter the runtime gate — they compose capabilities upstream
 * (`RoleRegistrar` / `CapabilityManager` / `CapabilityMigrator`). Adding a
 * capability is pure data to this engine.
 *
 * Persistence itself stays the caller's concern via the `store` entry (a
 * callback, or an option key), so each existing writer keeps its exact storage
 * semantics — merge into the `ffc_settings` array, or `update_option` on a
 * dedicated key — while the gate and sanitisation are centralised here.
 *
 * @package FreeFormCertificate\Core
 * @since 6.15.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Capability-gated settings persistence chokepoint.
 *
 * @phpstan-type FieldSpec array{sanitize: callable, cap?: string}
 * @phpstan-type SaveSpec array{
 *   cap: string,
 *   nonce: array{action: string, field: string},
 *   input: array<string, mixed>,
 *   fields: array<string, FieldSpec>,
 *   store?: (callable(array<string, mixed>): void)|string
 * }
 */
final class SettingsPersistence {

	/**
	 * Persist a set of settings fields behind one nonce + capability gate.
	 *
	 * Flow: verify the CSRF nonce, then the coarse capability; sanitise every
	 * submitted field with its (required) callback — a field carrying a stricter
	 * per-field cap the user lacks is skipped, not saved, so the rest of the
	 * write still proceeds; then hand the sanitised map to the caller's store.
	 *
	 * @param array<string, mixed> $spec Save specification.
	 * @phpstan-param SaveSpec $spec
	 * @return bool True when the write was authorised and persisted; false when
	 *              the nonce or the coarse capability check failed.
	 * @throws \InvalidArgumentException When a declared field has no sanitize callback.
	 */
	public static function save( array $spec ): bool {
		if ( ! self::verify( $spec ) ) {
			return false;
		}

		$default_cap = isset( $spec['cap'] ) ? (string) $spec['cap'] : '';
		$input       = ( isset( $spec['input'] ) && is_array( $spec['input'] ) ) ? $spec['input'] : array();
		$fields      = ( isset( $spec['fields'] ) && is_array( $spec['fields'] ) ) ? $spec['fields'] : array();
		$sanitized   = array();

		foreach ( $fields as $key => $field ) {
			if ( ! is_array( $field ) || ! isset( $field['sanitize'] ) || ! is_callable( $field['sanitize'] ) ) {
				throw new \InvalidArgumentException(
					sprintf( 'SettingsPersistence: field "%s" is missing a sanitize callback.', (string) $key )
				);
			}

			// Per-field capability override (e.g. the SMTP / danger-zone
			// sub-caps). A field the user is not allowed to edit is skipped
			// entirely; the rest of the write is unaffected.
			$field_cap = isset( $field['cap'] ) ? (string) $field['cap'] : $default_cap;
			if ( $field_cap !== $default_cap && ! Capabilities::current_user_can_admin_or( $field_cap ) ) {
				continue;
			}

			if ( ! array_key_exists( $key, $input ) ) {
				continue;
			}

			$sanitized[ $key ] = call_user_func( $field['sanitize'], $input[ $key ] );
		}

		self::persist( $spec, $sanitized );

		return true;
	}

	/**
	 * Verify the CSRF nonce and the coarse capability for a write.
	 *
	 * @param array<string, mixed> $spec Save specification.
	 * @return bool
	 */
	private static function verify( array $spec ): bool {
		$nonce  = ( isset( $spec['nonce'] ) && is_array( $spec['nonce'] ) ) ? $spec['nonce'] : array();
		$input  = ( isset( $spec['input'] ) && is_array( $spec['input'] ) ) ? $spec['input'] : array();
		$field  = isset( $nonce['field'] ) ? (string) $nonce['field'] : '';
		$action = isset( $nonce['action'] ) ? (string) $nonce['action'] : '';
		$token  = ( '' !== $field && isset( $input[ $field ] ) ) ? (string) $input[ $field ] : '';

		if ( '' === $token || '' === $action || ! wp_verify_nonce( $token, $action ) ) {
			return false;
		}

		$cap = isset( $spec['cap'] ) ? (string) $spec['cap'] : '';
		return '' !== $cap && Capabilities::current_user_can_admin_or( $cap );
	}

	/**
	 * Hand the sanitised values to the caller's store — a callback, or an
	 * option key persisted with `update_option`.
	 *
	 * @param array<string, mixed> $spec      Save specification.
	 * @param array<string, mixed> $sanitized Sanitised field values.
	 * @return void
	 */
	private static function persist( array $spec, array $sanitized ): void {
		$store = $spec['store'] ?? null;

		if ( is_callable( $store ) ) {
			call_user_func( $store, $sanitized );
			return;
		}

		if ( is_string( $store ) && '' !== $store ) {
			update_option( $store, $sanitized );
		}
	}
}
