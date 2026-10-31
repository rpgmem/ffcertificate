<?php
/**
 * ViewAs
 *
 * The one gate for "view the dashboard as another user" (#2246).
 *
 * @package FreeFormCertificate\Core
 * @since   6.43.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Who may view the dashboard as whom.
 *
 * The page and the REST routes the dashboard calls used to ask different
 * questions: the page asked for `ffc_view_as_user`, the routes for
 * `manage_options`, and neither asked whether the viewer may edit the
 * account being viewed. Both now ask this class, so a link that opens the
 * page can never reach data the routes refuse, nor the other way round.
 */
final class ViewAs {

	/**
	 * The capability that delegates view-as without full administration.
	 */
	public const CAPABILITY = 'ffc_view_as_user';

	/**
	 * Whether the current user may view the dashboard as a given account.
	 *
	 * Three conditions, all required: the view-as capability (or
	 * `manage_options`), `edit_user` on the target — so view-as never opens
	 * an account its viewer could not edit in wp-admin — and an account that
	 * exists.
	 *
	 * @param int $target_user_id Account to view as.
	 * @return bool
	 */
	public static function can_view( int $target_user_id ): bool {
		if ( $target_user_id <= 0 ) {
			return false;
		}
		if ( ! Capabilities::current_user_can_admin_or( self::CAPABILITY ) ) {
			return false;
		}
		if ( ! current_user_can( 'edit_user', $target_user_id ) ) {
			return false;
		}
		return false !== get_userdata( $target_user_id );
	}
}
