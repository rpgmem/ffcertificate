<?php
/**
 * RecordAutosave
 *
 * The third autosave endpoint (#1757): a toggle on a record's edit screen --
 * a calendar, an audience, a campaign, a notice, a reason, a rule -- saves
 * the moment it flips, the way options (`SettingsAjaxEndpoint`) and form
 * meta (`FormMetaAjaxEndpoint`) already do.
 *
 * Those records live in the plugin's own tables, each behind its own writer
 * and its own domain rules, so this class owns only the transport: nonce,
 * capability, id, allowlist and the value's type. What a field means is the
 * module's: each entity registers here at bootstrap with the callbacks that
 * load and save it, so the domain validation that runs on Save runs here
 * too. Core references no feature class -- the `SourceRegistry` shape of
 * the CSV export (#772).
 *
 * A new record (id 0) has nothing to save against, so its screen renders no
 * autosave attributes and keeps the Save button only.
 *
 * @package FreeFormCertificate\Core
 * @since   6.40.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registry and AJAX handler for per-record autosave.
 *
 * @phpstan-type RecordField array{type: string, options?: list<string>, map?: array<int, string>}
 * @phpstan-type RecordEntity array{cap: string, fields: array<string, RecordField>, exists: callable(int): bool, save: callable(int, string, mixed): (true|\WP_Error)}
 */
class RecordAutosave {

	/**
	 * AJAX action, and the nonce action that guards it.
	 *
	 * @var string
	 */
	public const AJAX_ACTION = 'ffc_update_record';

	/**
	 * Registered entities.
	 *
	 * @var array<string, RecordEntity>
	 */
	private static array $entities = array();

	/**
	 * Hook the handler.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( self::class, 'handle' ) );
	}

	/**
	 * Register (or replace) an entity.
	 *
	 * Field types:
	 * - `bool`: a checkbox, saved as `1` / `0`;
	 * - `enum`: one of `options`;
	 * - `map`: a checkbox whose two states mean two stored values,
	 *   `map[1]` and `map[0]` (a status `active` / `inactive`);
	 * - `list`: a checkbox group, saved as the subset of `options` ticked.
	 *
	 * @param string               $entity Entity key, as the markup names it.
	 * @param array<string, mixed> $def    Capability, fields and callbacks.
	 * @phpstan-param RecordEntity $def
	 * @return void
	 */
	public static function register( string $entity, array $def ): void {
		self::$entities[ $entity ] = $def;
	}

	/**
	 * Registered entity keys.
	 *
	 * @return list<string>
	 */
	public static function entities(): array {
		return array_keys( self::$entities );
	}

	/**
	 * Drop every registration. For tests.
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$entities = array();
	}

	/**
	 * The `data` attributes a control needs to autosave one field of one
	 * record, for `AdminUI::render_toggle()` and its siblings. Empty for a
	 * new record, which keeps the Save button only.
	 *
	 * @param string $entity Entity key.
	 * @param int    $id     Record id.
	 * @param string $field  Field key.
	 * @return array<string, string>
	 */
	public static function attrs( string $entity, int $id, string $field ): array {
		if ( $id <= 0 ) {
			return array();
		}
		return array(
			'ffc-autosave-record-key' => $field,
			'ffc-autosave-record'     => $entity . ':' . $id,
		);
	}

	/**
	 * Enqueue the autosave widget and the endpoint's settings on a record
	 * screen.
	 *
	 * @return void
	 */
	public static function enqueue(): void {
		$s = AssetHelper::asset_suffix();
		wp_enqueue_script( 'ffc-core', FFC_PLUGIN_URL . "assets/js/ffc-core{$s}.js", array( 'jquery' ), FFC_VERSION, true );
		wp_enqueue_script( 'ffc-admin-js', FFC_PLUGIN_URL . "assets/js/ffc-admin{$s}.js", array( 'jquery', 'ffc-core' ), FFC_VERSION, true );
		wp_enqueue_script( 'ffc-admin-autosave', FFC_PLUGIN_URL . "assets/js/ffc-admin-autosave{$s}.js", array( 'jquery', 'ffc-core', 'ffc-admin-js' ), FFC_VERSION, true );
		wp_localize_script(
			'ffc-admin-autosave',
			'ffcRecordAutosave',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'action'  => self::AJAX_ACTION,
				'nonce'   => wp_create_nonce( self::AJAX_ACTION ),
				'strings' => array(
					'saving'  => __( 'Saving…', 'ffcertificate' ),
					'saved'   => __( 'Saved', 'ffcertificate' ),
					'error'   => __( 'Save failed', 'ffcertificate' ),
					'invalid' => __( 'Enter a valid value', 'ffcertificate' ),
				),
			)
		);
	}

	/**
	 * AJAX handler.
	 *
	 * @return void
	 */
	public static function handle(): void {
		check_ajax_referer( self::AJAX_ACTION, 'nonce' );

		$entity_key = sanitize_key( RequestInput::get_post_string( 'entity' ) );
		$entity     = self::$entities[ $entity_key ] ?? null;
		if ( null === $entity ) {
			wp_send_json_error( array( 'message' => __( 'Unknown record type.', 'ffcertificate' ) ), 400 );
		}

		if ( ! Capabilities::current_user_can_admin_or( $entity['cap'] ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to change this record.', 'ffcertificate' ) ), 403 );
		}

		$id = RequestInput::get_post_int( 'id' );
		if ( $id <= 0 || ! ( $entity['exists'] )( $id ) ) {
			wp_send_json_error( array( 'message' => __( 'That record no longer exists.', 'ffcertificate' ) ), 404 );
		}

		$key   = sanitize_key( RequestInput::get_post_string( 'key' ) );
		$field = $entity['fields'][ $key ] ?? null;
		if ( null === $field ) {
			wp_send_json_error( array( 'message' => __( 'This field cannot be saved here.', 'ffcertificate' ) ), 403 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce checked above; the value is typed by sanitize() right below.
		$raw   = isset( $_POST['value'] ) ? wp_unslash( $_POST['value'] ) : '';
		$value = self::sanitize( $raw, $field );
		if ( null === $value ) {
			wp_send_json_error( array( 'message' => __( 'Enter a valid value', 'ffcertificate' ) ), 400 );
		}

		$result = ( $entity['save'] )( $id, $key, $value );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		wp_send_json_success(
			array(
				'key'   => $key,
				'value' => $value,
			)
		);
	}

	/**
	 * Type a posted value by its field definition.
	 *
	 * @param mixed                $raw   Posted value.
	 * @param array<string, mixed> $field Field definition.
	 * @phpstan-param RecordField $field
	 * @return mixed The typed value, or null when it is not one the field accepts.
	 */
	public static function sanitize( $raw, array $field ) {
		$options = $field['options'] ?? array();

		switch ( $field['type'] ) {
			case 'bool':
				return RequestInput::is_truthy( $raw ) ? 1 : 0;

			case 'map':
				$map = $field['map'] ?? array();
				return $map[ RequestInput::is_truthy( $raw ) ? 1 : 0 ] ?? null;

			case 'enum':
				if ( is_array( $raw ) ) {
					return null;
				}
				$raw = sanitize_text_field( (string) $raw );
				return in_array( $raw, $options, true ) ? $raw : null;

			case 'list':
				// An empty selection arrives as '' -- jQuery drops empty arrays.
				if ( '' === $raw ) {
					$raw = array();
				}
				if ( ! is_array( $raw ) ) {
					return null;
				}
				$list = array_map( static fn( $v ): string => sanitize_text_field( (string) $v ), $raw );
				return array_values( array_intersect( $options, $list ) );
		}

		return null;
	}
}
