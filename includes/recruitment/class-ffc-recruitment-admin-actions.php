<?php
/**
 * RecruitmentAdminActions
 *
 * State-transition controller for the recruitment admin page: the
 * `?action=…` GET-link operations (delete notice / adjutancy / reason /
 * candidate) triggered from the list-table row actions. Each branch
 * validates its own nonce and redirects back to the canonical tab.
 *
 * Split out of {@see RecruitmentAdminPage} (frontend-audit Item 3) so the
 * state mutations live apart from the page rendering and can be unit-tested
 * without the WP_List_Table admin runtime the render_* methods need.
 *
 * @package FreeFormCertificate\Recruitment
 */

declare(strict_types=1);

namespace FreeFormCertificate\Recruitment;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Recruitment admin row-action dispatcher.
 */
final class RecruitmentAdminActions {

	/**
	 * Dispatch a `?action=…` GET-link operation triggered from a row
	 * action in the list tables. Each branch validates its own nonce
	 * via `check_admin_referer` and short-circuits with `wp_safe_redirect`
	 * back to the canonical tab so the URL stays clean.
	 *
	 * Edit-screen actions (`edit-notice`, `edit-candidate`, etc.) are
	 * handled earlier in render_page() — they're full-screen renders,
	 * not redirects.
	 *
	 * @param string $action Sanitized action key from the request.
	 * @return void
	 */
	public static function dispatch( string $action ): void {
		// Every dispatch branch is a destructive delete. GAP E splits record
		// deletion (notices, adjutancies, candidates) out of the umbrella
		// manage cap into the dedicated `ffc_delete_recruitment` cap — a manager
		// without it can configure the module but cannot delete records, and a
		// read-only viewer (who never sees the row-action links) still can't
		// trigger one via a crafted URL. Reasons are a config catalog with their
		// own manage tier, so reason deletion is gated by the strict
		// `ffc_manage_recruitment_reasons` cap (GAP I) — the umbrella no longer
		// grants it — not the records-delete cap.
		$required_cap = ( 'delete-reason' === $action )
			? 'ffc_manage_recruitment_reasons'
			: 'ffc_delete_recruitment';
		if ( ! \FreeFormCertificate\Core\Capabilities::current_user_can_admin_or( $required_cap ) ) {
			return;
		}

		switch ( $action ) {
			case 'delete-notice':
				$id = isset( $_GET['notice_id'] ) ? absint( wp_unslash( (string) $_GET['notice_id'] ) ) : 0;
				if ( $id > 0 ) {
					check_admin_referer( 'ffc_recruitment_delete_notice_' . $id );
					RecruitmentNoticeWriter::delete( $id );
				}
				wp_safe_redirect(
					add_query_arg(
						array(
							'page' => RecruitmentAdminPage::PAGE_SLUG,
							'tab'  => 'notices',
						),
						admin_url( 'admin.php' )
					)
				);
				exit;

			case 'delete-adjutancy':
				$id = isset( $_GET['adjutancy_id'] ) ? absint( wp_unslash( (string) $_GET['adjutancy_id'] ) ) : 0;
				if ( $id > 0 ) {
					check_admin_referer( 'ffc_recruitment_delete_adjutancy_' . $id );
					// DeleteService gates on §14: rejects when notice_adjutancy
					// or classification rows reference this adjutancy. The
					// envelope's success flag is opaque from the redirect path
					// (UI lands in sprint B's edit screen with proper feedback).
					RecruitmentDeleteService::delete_adjutancy( $id );
				}
				wp_safe_redirect(
					add_query_arg(
						array(
							'page' => RecruitmentAdminPage::PAGE_SLUG,
							'tab'  => 'adjutancies',
						),
						admin_url( 'admin.php' )
					)
				);
				exit;

			case 'delete-reason':
				$id = isset( $_GET['reason_id'] ) ? absint( wp_unslash( (string) $_GET['reason_id'] ) ) : 0;
				if ( $id > 0 ) {
					check_admin_referer( 'ffc_recruitment_delete_reason_' . $id );
					// Reasons are referentially gated like adjutancies: a
					// reason that's still linked to any classification's
					// preview_reason_id can't be removed without orphaning
					// the audit trail. Silently no-op on a blocked delete;
					// the list table's deletion gate already explains the
					// rule via the row-action confirm copy.
					if ( 0 === RecruitmentReasonRepository::count_references( $id ) ) {
						RecruitmentReasonRepository::delete( $id );
					}
				}
				wp_safe_redirect(
					add_query_arg(
						array(
							'page' => RecruitmentAdminPage::PAGE_SLUG,
							'tab'  => 'reasons',
						),
						admin_url( 'admin.php' )
					)
				);
				exit;

			case 'delete-candidate':
				$id = isset( $_GET['candidate_id'] ) ? absint( wp_unslash( (string) $_GET['candidate_id'] ) ) : 0;
				if ( $id > 0 ) {
					check_admin_referer( 'ffc_recruitment_delete_candidate_' . $id );
					// DeleteService gates on §7-bis: zero classifications.
					// The reason-collection UI lives on sprint C's edit screen.
					RecruitmentDeleteService::delete_candidate( $id );
				}
				wp_safe_redirect(
					add_query_arg(
						array(
							'page' => RecruitmentAdminPage::PAGE_SLUG,
							'tab'  => 'candidates',
						),
						admin_url( 'admin.php' )
					)
				);
				exit;
		}
	}
}
