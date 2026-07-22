<?php
/**
 * CptEditorCompat — deprecation shim for the #739 CPT decoupling.
 *
 * The `ffc_form` and `ffc_self_scheduling` CPTs were decoupled from
 * WordPress's native post capabilities in 6.16.0: form/calendar management is
 * now gated by `ffc_manage_forms` / `ffc_manage_calendars` instead of
 * `edit_others_posts`. To avoid abruptly breaking installs that delegated form
 * editing to plain WordPress Editors, this shim keeps honouring
 * `edit_others_posts` for those two caps for TWO releases, surfacing an admin
 * notice so operators can migrate to the explicit role/cap grant.
 *
 * Remove this class and its `init()` call in {@see self::REMOVE_IN}.
 *
 * @package FreeFormCertificate\Admin
 */

declare(strict_types=1);

namespace FreeFormCertificate\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Temporary backward-compat shim (#739). Scheduled for removal.
 */
final class CptEditorCompat {

	/**
	 * Version this shim is removed in (two releases after 6.16.0).
	 */
	private const REMOVE_IN = '6.18.0';

	/**
	 * The FFC caps that fall back to `edit_others_posts` during the window.
	 *
	 * @var list<string>
	 */
	private const SHIMMED_CAPS = array( 'ffc_manage_forms', 'ffc_manage_calendars' );

	/**
	 * Wire the compat filter + the deprecation notice.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_filter( 'user_has_cap', array( self::class, 'grant_to_editors' ), 10, 3 );
		add_action( 'admin_notices', array( self::class, 'render_notice' ) );
	}

	/**
	 * Grant the shimmed caps to holders of `edit_others_posts` (WP Editors).
	 *
	 * @param array<string, bool> $allcaps All caps the user currently has.
	 * @param array<int, string>  $caps    Primitive caps required for this check.
	 * @param array<int, mixed>   $args    [ meta cap, user id, object id, … ].
	 * @return array<string, bool>
	 */
	public static function grant_to_editors( array $allcaps, array $caps, array $args ): array {
		if ( empty( $allcaps['edit_others_posts'] ) ) {
			return $allcaps;
		}
		foreach ( self::SHIMMED_CAPS as $cap ) {
			if ( in_array( $cap, $caps, true ) && empty( $allcaps[ $cap ] ) ) {
				$allcaps[ $cap ] = true;
			}
		}
		return $allcaps;
	}

	/**
	 * Warn admins, on the forms/calendars screens, that the shim is temporary.
	 *
	 * @return void
	 */
	public static function render_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$ptype  = $screen instanceof \WP_Screen ? (string) $screen->post_type : '';
		if ( 'ffc_form' !== $ptype && 'ffc_self_scheduling' !== $ptype ) {
			return;
		}
		echo '<div class="notice notice-warning"><p>';
		printf(
			/* translators: %s: version the compatibility shim is removed in */
			esc_html__( 'Backward compatibility: WordPress Editors can still manage FFC forms and calendars, but this stops in version %s. Grant the FFC Administrator role (or the form/calendar management capability) to the users who need it.', 'ffcertificate' ),
			esc_html( self::REMOVE_IN )
		);
		echo '</p></div>';
	}
}
